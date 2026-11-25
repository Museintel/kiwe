import { z } from 'zod';
import { keyState, parse, siteLinkInput, StartError } from './projects.mjs';

const receipt = keyState.extend({ origin: z.url(), approvalUrl: z.url() });
const setupCode = z.string().regex(/^[A-Za-z0-9_-]{43}$/).nullable();
const managedReceipt = receipt.extend({ setupCode });
const moveFields = z.strictObject({ moveId: z.uuid(), origin: z.url(), expiresAt: z.number().int().positive() });
const moveReceipt = z.discriminatedUnion('state', [
  moveFields.extend({ state: z.literal('awaiting_wordpress'), setupCode: setupCode.unwrap() }),
  moveFields.extend({ state: z.literal('verified'), setupCode: z.null() }),
]);
export const keyPairingInput = siteLinkInput.extend({
  phone: z.string().trim().regex(/^\+?[1-9][0-9]{9,14}$/),
  useForMessages: z.literal(true),
});

function unavailable() {
  return new StartError(502, 'key_unavailable', 'Key could not confirm the website. Open this project again to resume.');
}

export function createKeyProvider({ origin, token, managedToken, http = fetch }) {
  async function post(route, request, credential) {
    try {
      const response = await http(origin + '/integrations/start/' + route, {
        method: 'POST', redirect: 'error', signal: AbortSignal.timeout(10000),
        headers: { Authorization: 'Bearer ' + credential, 'Content-Type': 'application/json' },
        body: JSON.stringify(request),
      });
      if (response.status === 409) {
        throw new StartError(409, 'key_request_conflict', 'Key could not accept this setup. Check the site’s current connection.');
      }
      if (!response.ok) throw unavailable();
      const value = await response.json();
      if (value.origin !== request.origin) throw unavailable();
      return value;
    } catch (error) {
      if (error instanceof StartError) throw error;
      throw unavailable();
    }
  }

  function verifyLink(value, pattern) {
    let link;
    try { link = new URL(value); } catch { throw unavailable(); }
    if (link.origin !== origin || link.username || link.password || link.hash || link.search || !pattern.test(link.pathname)) {
      throw unavailable();
    }
    return link.href;
  }

  function verifyReceipt(schema, data) {
    const value = schema.safeParse(data);
    if (!value.success) throw unavailable();
    verifyLink(value.data.approvalUrl, /^\/start\/connect\/[a-f0-9-]{36}$/);
    return value.data;
  }

  return {
    origin, managed: Boolean(managedToken),
    async prepare(request) {
      return verifyReceipt(receipt, await post('requests', request, token));
    },
    async prepareManaged(request) {
      return verifyReceipt(managedReceipt, await post('managed-sites', request, managedToken));
    },
    async prepareManagedMove(request) {
      const value = await post('managed-sites/move', request, managedToken);
      const parsed = moveReceipt.safeParse(value);
      if (!parsed.success || parsed.data.moveId !== request.moveId) throw unavailable();
      return parsed.data;
    },
    async pairManaged(request, phone) {
      const value = await post('managed-sites/pair', { ...request, phone }, managedToken);
      return verifyLink(value.pairingUrl, /^\/start\/pair\/[A-Za-z0-9_-]{43}$/);
    },
  };
}

export function createKeyConnections(projects, provider) {
  const active = new Map();
  return {
    available: Boolean(provider),
    managed: Boolean(provider?.managed),
    async provision(accountId, details, connectSite) {
      const request = projects.managedKeyRequest(accountId, details);
      if (!request) return true;
      const status = await provider.prepareManaged(request);
      if (status.state !== 'sender_ready' || (!status.siteAuthenticated && !status.setupCode)) return false;
      await connectSite(new URL(request.origin).hostname, status.setupCode);
      return (await provider.prepareManaged(request)).siteAuthenticated;
    },
    async verifyDomainMove(accountId, input, connectSite) {
      if (!provider?.managed || !connectSite) throw unavailable();
      const details = parse(siteLinkInput, input);
      const request = projects.managedKeyMoveRequest(accountId, details);
      if (active.has(request.moveId)) return active.get(request.moveId);
      const pending = (async () => {
        let status = await provider.prepareManagedMove(request);
        if (status.state === 'awaiting_wordpress') {
          try {
            await connectSite(new URL(request.origin).hostname, status.setupCode, { enableAuth: false });
          } catch {
            throw unavailable();
          }
          status = await provider.prepareManagedMove(request);
        }
        return projects.saveDomainMoveKey(accountId, details, status);
      })();
      active.set(request.moveId, pending);
      try { return await pending; } finally { active.delete(request.moveId); }
    },
    async pair(accountId, input) {
      if (!provider?.managed) throw unavailable();
      const { phone, projectId, revision } = parse(keyPairingInput, input);
      const request = projects.managedKeyRequest(accountId, { projectId, revision }, true);
      if (!request) throw new StartError(409, 'managed_key_required', 'Pair a client number from its connected managed site.');
      const international = /^\d{10}$/.test(phone) ? '+91' + phone : phone;
      return { pairingUrl: await provider.pairManaged(request, international) };
    },
    async resume(accountId, input) {
      if (!provider) throw new StartError(503, 'key_unconfigured', 'Key setup will be available once the connection is configured.');
      const details = parse(siteLinkInput, input);
      const managed = provider.managed && projects.managedKeyRequest(accountId, details, true);
      const request = managed || projects.prepareKeyRequest(accountId, details);
      if (active.has(details.projectId)) return active.get(details.projectId);
      const pending = (async () => {
        const result = managed ? await provider.prepareManaged(request) : await provider.prepare(request);
        const project = projects.saveKeyStatus(accountId, details, result);
        return { project, approvalUrl: managed ? null : result.approvalUrl };
      })();
      active.set(details.projectId, pending);
      try { return await pending; } finally { active.delete(details.projectId); }
    },
  };
}
