import { randomBytes } from 'node:crypto';
import { parse, siteLinkInput, StartError } from './projects.mjs';
import { requestSite } from './site-network.mjs';

const api = 'https://developers.hostinger.com/api/hosting/v1';
const freeDomain = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.hostingersite\.com$/;
const registeredDomain = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.in$/;

function providerUnavailable() {
  return new StartError(502, 'hosting_unavailable', 'The hosting provider is temporarily unavailable. Check this project again to resume.');
}

export function createHostingerProvider({ token, orderId, username, adminEmail, prepareWordPress, connectKey, http = fetch, probe = requestSite }) {
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
    prepareWordPress, connectKey,
    async reserve() {
      const reserved = await call('/domains/free-subdomains', { method: 'POST' });
      if (!freeDomain.test(reserved?.domain ?? '')) throw providerUnavailable();
      return reserved.domain;
    },

    async primaryAvailable(domain) {
      if (!registeredDomain.test(domain)) throw providerUnavailable();
      const records = await call('/websites?' + new URLSearchParams({ domain, per_page: '100' }));
      if (!Array.isArray(records) || records.length >= 100) throw providerUnavailable();
      return !records.some((record) => record.domain === domain) && !(await this.status(domain));
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
      if (!(freeDomain.test(domain) || registeredDomain.test(domain))) throw providerUnavailable();
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

export function createManagedHosting(projects, provider, keyConnections) {
  const inFlight = new Map();

  async function advance(accountId, details, job) {
    let { domain, status, holdUntil } = job;
    if (!domain) {
      let reserved = job.registeredDomain;
      if (reserved) {
        if (!provider.primaryAvailable || !(await provider.primaryAvailable(reserved))) {
          throw new StartError(409, 'managed_domain_in_use', 'This domain needs a hosting review before Kiwe can set up WordPress.');
        }
      } else {
        reserved = await provider.reserve();
      }
      ({ domain, status, holdUntil } = projects.reserveManagedSite(accountId, details, reserved));
    }
    const primary = domain === job.registeredDomain;
    const current = await provider.status(domain);
    if (primary && current && !holdUntil) return projects.saveManagedStatus(accountId, details, 'needs_attention');
    if (current === 'failed') return projects.saveManagedStatus(accountId, details, 'needs_attention');
    if (current === 'completed') {
      let ready = await provider.wordpressReady(domain);
      if (ready && provider.prepareWordPress) ready = await provider.prepareWordPress(domain, /^launch:[a-f0-9]{32}$/.test(accountId)) !== false;
      if (ready && provider.connectKey && keyConnections?.managed) {
        ready = await keyConnections.provision(accountId, details, provider.connectKey);
      }
      return projects.saveManagedStatus(accountId, details, ready ? 'ready' : 'provisioning');
    }
    if (current === 'running') return projects.saveManagedStatus(accountId, details, 'provisioning');
    if (projects.managedReservationExpired(accountId, details)) {
      return projects.saveManagedStatus(accountId, details, 'needs_attention');
    }
    if (status === 'provisioning') return projects.getProject(accountId, details);
    if (!projects.claimManagedStart(accountId, details)) return projects.getProject(accountId, details);
    if (primary && !(await provider.primaryAvailable(domain))) {
      return projects.saveManagedStatus(accountId, details, 'needs_attention');
    }

    const accepted = await provider.start(domain);
    return projects.saveManagedStatus(accountId, details, accepted === 'failed' ? 'needs_attention' : 'provisioning');
  }

  return {
    available: Boolean(provider),
    canVerifyDomainKey: Boolean(provider?.connectKey && keyConnections?.managed),
    verifyDomainKey(accountId, input) {
      return keyConnections.verifyDomainMove(accountId, input, provider?.connectKey);
    },
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
