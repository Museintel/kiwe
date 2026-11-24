import { z } from 'zod';
import { keyState, parse, siteLinkInput, StartError } from './projects.mjs';

const receipt = keyState.extend({ origin: z.url(), approvalUrl: z.url() });

function unavailable() {
  return new StartError(502, 'key_unavailable', 'Key could not confirm the website. Open this project again to resume.');
}

export function createKeyProvider({ origin, token, http = fetch }) {
  return {
    origin,
    async prepare(request) {
      try {
        const response = await http(origin + '/integrations/start/requests', {
          method: 'POST', redirect: 'error', signal: AbortSignal.timeout(10000),
          headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
          body: JSON.stringify(request),
        });
        if (response.status === 409) {
          throw new StartError(409, 'key_request_conflict', 'This website already has a different Key request.');
        }
        if (!response.ok) throw unavailable();
        const value = receipt.parse(await response.json());
        const approval = new URL(value.approvalUrl);
        if (value.origin !== request.origin || approval.origin !== origin || approval.username || approval.password
            || approval.hash || approval.search || !/^\/start\/connect\/[a-f0-9-]{36}$/.test(approval.pathname)) {
          throw unavailable();
        }
        return value;
      } catch (error) {
        if (error instanceof StartError) throw error;
        throw unavailable();
      }
    },
  };
}

export function createKeyConnections(projects, provider) {
  const active = new Map();
  return {
    available: Boolean(provider),
    async resume(accountId, input) {
      if (!provider) throw new StartError(503, 'key_unconfigured', 'Key setup will be available once the connection is configured.');
      const details = parse(siteLinkInput, input);
      const request = projects.prepareKeyRequest(accountId, details);
      if (active.has(details.projectId)) return active.get(details.projectId);
      const pending = (async () => {
        const result = await provider.prepare(request);
        const project = projects.saveKeyStatus(accountId, details, result);
        return { project, approvalUrl: result.approvalUrl };
      })();
      active.set(details.projectId, pending);
      try { return await pending; } finally { active.delete(details.projectId); }
    },
  };
}
