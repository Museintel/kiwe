import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRemoteJWKSet } from 'jose';
import { createStartServer } from './app.mjs';
import { createMcpAuthorization } from './auth.mjs';
import { createBrowserLogin } from './browser-login.mjs';
import { createKeyPilotLogin } from './key-pilot-login.mjs';
import { openProjects, StartError } from './projects.mjs';
import { createHostingerProvider } from './managed-hosting.mjs';
import { createManagedCommerceInstaller, createManagedWordPressCommands } from './managed-commerce.mjs';
import { createManagedBricksInstaller } from './managed-bricks.mjs';
import { createManagedKiweInstaller } from './managed-kiwe.mjs';
import { createKeyProvider } from './key-connection.mjs';
import { createHomepageProvider } from './homepage.mjs';
import { createBricksCompiler } from './bricks-compiler.mjs';
import { createPublicGuide } from './public-guide.mjs';

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

function managedProvider() {
  const token = process.env.KIWE_START_HOSTINGER_TOKEN;
  if (!token) return undefined;
  const orderId = Number(required('KIWE_START_HOSTINGER_ORDER'));
  const username = required('KIWE_START_HOSTINGER_ACCOUNT');
  const adminEmail = required('KIWE_START_HOSTINGER_ADMIN_EMAIL');
  if (!Number.isSafeInteger(orderId) || orderId < 1 || !/^u[0-9]+$/.test(username)
      || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(adminEmail)) {
    throw new Error('Set a valid managed hosting order, account and operator email.');
  }
  const locations = { domainsDirectory: process.env.KIWE_START_HOSTINGER_DOMAINS_DIRECTORY || '/home/' + username + '/domains' };
  const installCommerce = createManagedCommerceInstaller(locations);
  const resolveSite = createManagedWordPressCommands(locations);
  const bricksArchive = process.env.KIWE_START_BRICKS_ARCHIVE;
  const installBricks = bricksArchive && createManagedBricksInstaller({
    resolveSite, archive: bricksArchive, sha256: required('KIWE_START_BRICKS_SHA256'), version: required('KIWE_START_BRICKS_VERSION'),
  });
  const kiweRelease = process.env.KIWE_START_KIWE_RELEASE_DIRECTORY;
  const installKiwe = kiweRelease && createManagedKiweInstaller({ directory: kiweRelease, resolveSite });
  const prepareWordPress = async (domain, retail, mode) => {
    if (retail && (!installBricks || !installKiwe || !managedKeyToken)) {
      throw new StartError(503, 'managed_stack_unavailable', 'The managed site package needs its Bricks, signed Kiwe and Key configuration.');
    }
    await installCommerce(domain, mode);
    if (retail) {
      await installKiwe(domain, mode);
      return installBricks(domain);
    }
    return true;
  };
  const setup = fileURLToPath(new URL('./managed-key-setup.php', import.meta.url));
  const connectKey = async (domain, code, { enableAuth = true } = {}) => {
    const command = await resolveSite(domain);
    const response = await command(['eval-file', setup], {
      KIWE_MANAGED_KEY_CODE: code ?? '', KIWE_MANAGED_SITE_ORIGIN: 'https://' + domain, KIWE_MANAGED_ENABLE_AUTH: enableAuth ? 'yes' : 'no',
    });
    if (response !== 'verified') throw new Error('The site could not confirm its Key connection.');
  };
  return createHostingerProvider({ token, orderId, username, adminEmail, prepareWordPress, connectKey });
}

const startOrigin = configuredUrl('KIWE_START_ORIGIN', true);
const checkoutOrigin = configuredUrl('KIWE_START_CHECKOUT_ORIGIN', true);
const pilotAccount = process.env.KIWE_START_KEY_PILOT_ACCOUNT;
const purchaseToken = pilotAccount ? process.env.KIWE_START_PURCHASE_TOKEN : required('KIWE_START_PURCHASE_TOKEN');
if (purchaseToken && !/^[A-Za-z0-9_-]{43,128}$/.test(purchaseToken)) throw new Error('Use a random purchase credential.');
const keyToken = process.env.KIWE_START_KEY_TOKEN;
if (keyToken && (keyToken === purchaseToken || !/^[A-Za-z0-9_-]{43,128}$/.test(keyToken))) {
  throw new Error('Use a separate random service credential for Key.');
}
const keyOrigin = keyToken ? configuredUrl('KIWE_START_KEY_ORIGIN', true) : undefined;
const managedKeyToken = process.env.KIWE_START_KEY_MANAGED_TOKEN;
if (managedKeyToken && (!keyToken || [keyToken, purchaseToken].includes(managedKeyToken) || !/^[A-Za-z0-9_-]{43,128}$/.test(managedKeyToken))) {
  throw new Error('Configure a separate credential for managed Key setup.');
}
const key = keyToken ? createKeyProvider({ token: keyToken, origin: keyOrigin, managedToken: managedKeyToken }) : undefined;
const loginSettings = ['KIWE_START_LOGIN_CLIENT_ID', 'KIWE_START_LOGIN_CLIENT_SECRET', 'KIWE_START_LOGIN_AUTHORIZATION_URL', 'KIWE_START_LOGIN_TOKEN_URL'];
let browserLogin;
let issuer;
let authorizeMcp;
if (pilotAccount) {
  if (!key || !startOrigin.startsWith('https://')
      || [...loginSettings, 'KIWE_START_OAUTH_ISSUER', 'KIWE_START_OAUTH_JWKS'].some(name => process.env[name])) {
    throw new Error('Configure the closed Key pilot with its own HTTPS identity settings.');
  }
  browserLogin = createKeyPilotLogin({ origin: keyOrigin, token: keyToken, accountId: pilotAccount });
} else {
  issuer = configuredUrl('KIWE_START_OAUTH_ISSUER');
  const jwks = configuredUrl('KIWE_START_OAUTH_JWKS');
  authorizeMcp = createMcpAuthorization({
    issuer, resource: startOrigin + '/projects/mcp', keys: createRemoteJWKSet(new URL(jwks)),
  });
  if (loginSettings.some(name => process.env[name])) {
    const clientSecret = required('KIWE_START_LOGIN_CLIENT_SECRET');
    if (clientSecret === purchaseToken || clientSecret === keyToken) throw new Error('Use separate checkout and browser login credentials.');
    browserLogin = createBrowserLogin({
      startOrigin, issuer, jwks, clientSecret, clientId: required('KIWE_START_LOGIN_CLIENT_ID'),
      authorizationEndpoint: configuredUrl('KIWE_START_LOGIN_AUTHORIZATION_URL'),
      tokenEndpoint: configuredUrl('KIWE_START_LOGIN_TOKEN_URL'),
    });
  }
}
const generatorToken = process.env.KIWE_START_HOMEPAGE_GENERATOR_TOKEN;
const generatorUrl = process.env.KIWE_START_HOMEPAGE_GENERATOR_URL;
let homepageProvider;
if (generatorToken || generatorUrl) {
  const endpoint = configuredUrl('KIWE_START_HOMEPAGE_GENERATOR_URL');
  if (!endpoint.startsWith('https://') || !generatorToken || generatorToken.length < 32
      || generatorToken === purchaseToken || generatorToken === keyToken) throw new Error('Configure a separate HTTPS homepage generator credential.');
  homepageProvider = createHomepageProvider({ endpoint, token: generatorToken });
}
const seamRoot = process.env.KIWE_START_SEAM_ROOT;
const seamWork = process.env.KIWE_START_SEAM_WORK;
let bricksCompiler;
if (seamRoot || seamWork) {
  if (!seamRoot || !seamWork || !path.isAbsolute(seamRoot) || !path.isAbsolute(seamWork)) {
    throw new Error('Configure the approved Seam checkout and a private absolute workspace.');
  }
  bricksCompiler = createBricksCompiler({ seamRoot, workRoot: seamWork });
}
const database = required('KIWE_START_DATABASE');
if (!path.isAbsolute(database)) throw new Error('Use an absolute private database path.');
const guideRoot = process.env.KIWE_START_GUIDE_ROOT;
if (guideRoot && !path.isAbsolute(guideRoot)) throw new Error('Use an absolute public guide directory.');
const publicGuide = guideRoot ? await createPublicGuide(guideRoot) : undefined;
const port = Number(process.env.PORT || 3000);
if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('Use a valid PORT.');

process.umask(0o077);
mkdirSync(path.dirname(database), { recursive: true, mode: 0o700 });
const projects = openProjects(database, { launchWebsite: checkoutOrigin });
const server = createStartServer({
  projects, startOrigin, checkoutOrigin, issuer, purchaseToken, pilotPurchaseToken: pilotAccount ? purchaseToken : undefined,
  authorizeMcp, browserLogin, publicGuide, hostingProvider: managedProvider(), keyProvider: key, homepageProvider, bricksCompiler,
});
server.listen(port, process.env.KIWE_START_BIND || '127.0.0.1');
for (const name of ['SIGINT', 'SIGTERM']) {
  process.once(name, () => server.close(() => projects.close()));
}
