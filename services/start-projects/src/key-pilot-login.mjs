import { createHash, randomBytes } from 'node:crypto';
import { StartError } from './projects.mjs';

const opaque = /^[A-Za-z0-9_-]{43}$/;
export const pilotAccountPattern = /^key:[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/;
const endpoint = '/integrations/start/sign-ins';
const approvalPath = /^\/start\/sign-in\/[a-f0-9-]{36}$/;
const secret = () => randomBytes(32).toString('base64url');
const challengeFor = verifier => createHash('sha256').update(verifier).digest('base64url');

export function createKeyPilotLogin({ origin, token, accountId, http = fetch }) {
  if (!pilotAccountPattern.test(accountId) || new URL(origin).protocol !== 'https:') {
    throw new Error('Configure the verified Key account and HTTPS origin for the pilot.');
  }
  const unavailable = () => new StartError(401, 'pilot_sign_in_unverified', 'Open a new Kiwe Start sign-in and approve it from the invited Key account.');
  async function exchange(path, body) {
    let reply;
    try {
      reply = await http(origin + endpoint + path, {
        method: 'POST', headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
        body: JSON.stringify(body), redirect: 'error', signal: AbortSignal.timeout(8000),
      });
      if (reply.ok) return await reply.json();
    } catch {
      throw new StartError(502, 'pilot_sign_in_unavailable', 'Key could not open the pilot sign-in. Try again in a moment.');
    }
    throw unavailable();
  }
  return {
    authorizationOrigin: origin,
    pilotAccount: accountId,
    async begin(inviteCode) {
      if (inviteCode) throw new StartError(400, 'pilot_owner_only', 'Use the Key account invited to the agency pilot.');
      const verifier = secret();
      const state = secret();
      const receipt = await exchange('', { state, challenge: challengeFor(verifier) });
      try {
        const authorization = new URL(receipt.approvalUrl);
        if (authorization.origin !== origin || !approvalPath.test(authorization.pathname)
            || authorization.username || authorization.password || authorization.hash || authorization.search) throw unavailable();
        return { authorization, state, binding: secret(), pending: { verifier } };
      } catch {
        throw unavailable();
      }
    },
    async complete(search, state, pending) {
      const params = new URLSearchParams(search);
      const code = params.get('code');
      if (params.size !== 3 || params.get('iss') !== origin || params.get('state') !== state || !opaque.test(code ?? '')) {
        throw unavailable();
      }
      const account = await exchange('/redeem', { code, state, verifier: pending.verifier });
      if (account.accountId !== accountId) throw unavailable();
      return { accountId };
    },
  };
}
