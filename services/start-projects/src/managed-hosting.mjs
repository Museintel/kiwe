import { randomBytes } from 'node:crypto';
import { parse, siteLinkInput, StartError } from './projects.mjs';
import { requestSite } from './site-network.mjs';

const api = 'https://developers.hostinger.com/api/hosting/v1';
const freeDomain = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.hostingersite\.com$/;

function providerUnavailable() {
  return new StartError(502, 'hosting_unavailable', 'The hosting provider is temporarily unavailable. Check this project again to resume.');
}

export function createHostingerProvider({ token, orderId, username, adminEmail, http = fetch, probe = requestSite }) {
  async function call(endpoint, init = {}) {
    const response = await http(api + endpoint, {
      ...init, redirect: 'error', signal: AbortSignal.timeout(10000),
      headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json', Accept: 'application/json' },
    });
    if (response.status === 409 && init.method === 'POST') return null;
    if (!response.ok) throw providerUnavailable();
    const payload = await response.json();
    return payload.data ?? payload;
  }

  function result(entry, domain) {
    if (entry?.domain !== domain || entry.username !== username || entry.type !== 'wordpress'
        || !['running', 'completed', 'failed'].includes(entry.status)) throw providerUnavailable();
    return entry.status;
  }

  return {
    async reserve() {
      const reserved = await call('/domains/free-subdomains', { method: 'POST' });
      if (!freeDomain.test(reserved?.domain ?? '')) throw providerUnavailable();
      return reserved.domain;
    },

    async status(domain) {
      const records = await call('/onboardings?' + new URLSearchParams({ order_id: orderId, domain }));
      if (!Array.isArray(records)) throw providerUnavailable();
      const entry = records.find((record) => record.domain === domain);
      return entry ? result(entry, domain) : null;
    },

    async start(domain) {
      const accepted = await call('/orders/' + orderId + '/onboardings', {
        method: 'POST',
        body: JSON.stringify({
          domain, type: 'wordpress',
          wordpress: {
            language: 'en_US',
            admin: {
              user: 'kiwe_owner_' + randomBytes(5).toString('hex'),
              email: adminEmail,
              password: 'Ki9!' + randomBytes(22).toString('base64url'),
            },
          },
        }),
      });
      return accepted ? result(accepted, domain) : 'running';
    },

    async wordpressReady(domain) {
      if (!freeDomain.test(domain)) throw providerUnavailable();
      const origin = 'https://' + domain;
      for (const path of ['/wp-json/?_fields=home,namespaces', '/?rest_route=%2F&_fields=home,namespaces']) {
        try {
          const { body } = await probe(origin, origin + path);
          const value = JSON.parse(body);
          if (new URL(value.home).origin === origin && Array.isArray(value.namespaces)
              && value.namespaces.includes('wp/v2')) return true;
        } catch {
          // Site setup can finish before HTTPS and the WordPress routes respond.
        }
      }
      return false;
    },
  };
}

export function createManagedHosting(projects, provider) {
  const inFlight = new Map();

  async function advance(accountId, details, job) {
    let { domain, status } = job;
    if (!domain) {
      const reserved = await provider.reserve();
      ({ domain, status } = projects.reserveManagedSite(accountId, details, reserved));
    }
    const current = await provider.status(domain);
    if (current === 'failed') return projects.saveManagedStatus(accountId, details, 'needs_attention');
    if (current === 'completed') {
      const ready = await provider.wordpressReady(domain);
      return projects.saveManagedStatus(accountId, details, ready ? 'ready' : 'provisioning');
    }
    if (current === 'running') return projects.saveManagedStatus(accountId, details, 'provisioning');
    if (projects.managedReservationExpired(accountId, details)) {
      return projects.saveManagedStatus(accountId, details, 'needs_attention');
    }
    if (status === 'provisioning') return projects.getProject(accountId, details);
    if (!projects.claimManagedStart(accountId, details)) return projects.getProject(accountId, details);

    const accepted = await provider.start(domain);
    return projects.saveManagedStatus(accountId, details, accepted === 'failed' ? 'needs_attention' : 'provisioning');
  }

  return {
    available: Boolean(provider),
    async step(accountId, input) {
      if (!provider) throw new StartError(503, 'hosting_unconfigured', 'Kiwe hosting is not available for this setup yet.');
      const details = parse(siteLinkInput, input);
      const job = projects.prepareManagedSite(accountId, details);
      if (job.status === 'ready' || job.status === 'needs_attention') return projects.getProject(accountId, details);
      if (inFlight.has(details.projectId)) return inFlight.get(details.projectId);
      const pending = advance(accountId, details, job).catch((error) => {
        if (error instanceof StartError) throw error;
        throw providerUnavailable();
      });
      inFlight.set(details.projectId, pending);
      try {
        return await pending;
      } finally {
        inFlight.delete(details.projectId);
      }
    },
  };
}
