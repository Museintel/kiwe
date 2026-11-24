import * as oidc from 'openid-client';
import { z } from 'zod';
import { accountInput, StartError, ticketInput } from './projects.mjs';

export const browserLoginPath = '/login/';
export const browserLoginReturn = '/projects/login/return';
export const browserLoginInput = z.strictObject({ inviteCode: ticketInput.shape.ticket.or(z.literal('')).optional() });
export const browserLoginLifetime = 10 * 60;

export function createBrowserLogin({ startOrigin, issuer, jwks, authorizationEndpoint, tokenEndpoint, clientId, clientSecret, http }) {
  const config = new oidc.Configuration({
    issuer, jwks_uri: jwks, authorization_endpoint: authorizationEndpoint, token_endpoint: tokenEndpoint,
  }, clientId, {}, oidc.ClientSecretBasic(clientSecret));
  config.timeout = 10;
  if (http) config[oidc.customFetch] = http;
  oidc.enableNonRepudiationChecks(config);

  return {
    authorizationOrigin: new URL(authorizationEndpoint).origin,
    async begin(inviteCode) {
      const binding = oidc.randomState();
      const state = oidc.randomState();
      const verifier = oidc.randomPKCECodeVerifier();
      const nonce = oidc.randomNonce();
      const authorization = oidc.buildAuthorizationUrl(config, {
        redirect_uri: startOrigin + browserLoginReturn, scope: 'openid email', response_mode: 'query',
        state, nonce, code_challenge: await oidc.calculatePKCECodeChallenge(verifier), code_challenge_method: 'S256',
      });
      return { authorization, binding, state, pending: { verifier, nonce, inviteCode } };
    },

    async complete(search, state, pending) {
      const callback = new URL(browserLoginReturn, startOrigin);
      callback.search = search;
      try {
        const tokens = await oidc.authorizationCodeGrant(config, callback, {
          pkceCodeVerifier: pending.verifier, expectedState: state, expectedNonce: pending.nonce, idTokenExpected: true,
        });
        const claims = tokens.claims();
        const account = accountInput.safeParse(claims?.sub);
        if (!account.success) throw new Error('Invalid account subject');
        return { accountId: account.data, verifiedEmail: claims.email_verified === true ? claims.email : undefined };
      } catch {
        throw new StartError(401, 'login_unverified', 'Sign-in could not be verified. Start a new sign-in below.');
      }
    },
  };
}
