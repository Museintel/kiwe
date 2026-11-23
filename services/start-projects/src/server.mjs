import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { createRemoteJWKSet } from 'jose';
import { createStartServer } from './app.mjs';
import { createMcpAuthorization } from './auth.mjs';
import { openProjects } from './projects.mjs';

function required(name) {
  const value = process.env[name];
  if (!value) throw new Error('Set ' + name + ' before starting Kiwe projects.');
  return value;
}

function configuredUrl(name, originOnly = false) {
  const value = required(name);
  const url = new URL(value);
  const loopback = url.hostname === '127.0.0.1' || url.hostname === 'localhost' || url.hostname === '[::1]';
  if ((url.protocol !== 'https:' && !(url.protocol === 'http:' && loopback))
      || url.username || url.password || url.hash || url.search || (originOnly && url.pathname !== '/')) {
    throw new Error('Invalid URL in ' + name + '.');
  }
  return originOnly ? url.origin : value;
}

const startOrigin = configuredUrl('KIWE_START_ORIGIN', true);
const checkoutOrigin = configuredUrl('KIWE_START_CHECKOUT_ORIGIN', true);
const issuer = configuredUrl('KIWE_START_OAUTH_ISSUER');
const jwks = configuredUrl('KIWE_START_OAUTH_JWKS');
const purchaseToken = required('KIWE_START_PURCHASE_TOKEN');
if (!/^[A-Za-z0-9_-]{43,128}$/.test(purchaseToken)) throw new Error('Use a random purchase credential.');
const database = required('KIWE_START_DATABASE');
if (!path.isAbsolute(database)) throw new Error('Use an absolute private database path.');
const port = Number(process.env.PORT || 3000);
if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('Use a valid PORT.');

process.umask(0o077);
mkdirSync(path.dirname(database), { recursive: true, mode: 0o700 });
const projects = openProjects(database);
const authorizeMcp = createMcpAuthorization({
  issuer, resource: startOrigin + '/projects/mcp', keys: createRemoteJWKSet(new URL(jwks)),
});
const server = createStartServer({ projects, startOrigin, checkoutOrigin, issuer, purchaseToken, authorizeMcp });
server.listen(port, process.env.KIWE_START_BIND || '127.0.0.1');
for (const name of ['SIGINT', 'SIGTERM']) {
  process.once(name, () => server.close(() => projects.close()));
}
