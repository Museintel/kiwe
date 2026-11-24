import assert from 'node:assert/strict';
import { createHash, randomUUID } from 'node:crypto';
import { exportJWK, generateKeyPair, SignJWT } from 'jose';
import { createBrowserLogin } from '../src/browser-login.mjs';
import { issuer, startOrigin } from './fixture.mjs';

export async function browserIdentity() {
  const { privateKey, publicKey } = await generateKeyPair('RS256');
  const jwk = await exportJWK(publicKey);
  const clientId = 'start-browser';
  const clientSecret = 'synthetic-client-credential';
  const grants = new Map();
  const replies = [];
  const provider = createBrowserLogin({
    issuer, startOrigin, jwks: issuer + '/jwks', authorizationEndpoint: issuer + '/authorize',
    tokenEndpoint: issuer + '/token', clientId, clientSecret,
    async http(input, init) {
      const request = new Request(input, init);
      if (request.url === issuer + '/jwks') return Response.json({ keys: [jwk] });
      assert.equal(request.url, issuer + '/token');
      const basic = Buffer.from(request.headers.get('authorization').slice('Basic '.length), 'base64').toString();
      assert.deepEqual(basic.split(':').map(decodeURIComponent), [clientId, clientSecret]);
      const form = new URLSearchParams(await request.text());
      const grant = grants.get(form.get('code'));
      assert.ok(grant);
      assert.equal(form.get('redirect_uri'), grant.redirect);
      assert.equal(createHash('sha256').update(form.get('code_verifier')).digest('base64url'), grant.challenge);
      grants.delete(form.get('code'));
      replies.push(request.url);
      return Response.json({ id_token: grant.idToken, access_token: 'synthetic-access-token', token_type: 'Bearer' });
    },
  });

  return {
    provider,
    replies,
    async callback(start, { accountId = 'buyer-one', email = 'member@example.test', verified = true, nonce, audience = clientId } = {}) {
      const authorize = new URL(start.headers.get('location'));
      const values = authorize.searchParams;
      assert.equal(authorize.origin, issuer);
      assert.equal(values.get('response_type'), 'code');
      assert.equal(values.get('code_challenge_method'), 'S256');
      assert.match(values.get('scope'), /\bopenid\b/);
      const code = randomUUID();
      const redirect = values.get('redirect_uri');
      const idToken = await new SignJWT({ nonce: nonce ?? values.get('nonce'), email, email_verified: verified })
        .setProtectedHeader({ alg: 'RS256' }).setIssuer(issuer).setAudience(audience).setSubject(accountId)
        .setIssuedAt().setExpirationTime('2m').sign(privateKey);
      grants.set(code, { challenge: values.get('code_challenge'), redirect, idToken });
      const callbackUrl = new URL(redirect);
      callbackUrl.search = new URLSearchParams({ code, state: values.get('state'), iss: issuer });
      const browserCookie = start.headers.getSetCookie()[0];
      assert.match(browserCookie, /^__Host-kiwe-start-login=/);
      assert.match(browserCookie, /HttpOnly; SameSite=Lax; Secure/);
      return { path: callbackUrl.pathname + callbackUrl.search, binding: browserCookie.split(';')[0] };
    },
  };
}
