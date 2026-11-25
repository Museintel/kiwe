import { execFile } from 'node:child_process';
import { realpath } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { promisify } from 'node:util';
import { siteSetups, siteSetupMode } from './site-setup.mjs';

const execute = promisify(execFile);
const plugins = Object.freeze({ 'woo-razorpay': '4.8.9', shiprocket: '2.0.9' });
const storePlugins = Object.freeze({ woocommerce: '11.3.1', ...plugins });
const domainName = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/;

export function createManagedWordPressCommands({ domainsDirectory, wp = 'wp', run = execute, resolvePath = realpath }) {
  if (!path.isAbsolute(domainsDirectory)) throw new Error('Use the absolute managed domains directory.');
  const root = path.resolve(domainsDirectory);

  return async (domain) => {
    if (!domainName.test(domain) || domain.length > 253) throw new Error('An exact managed site domain is required.');
    const expected = path.join(root, domain, 'public_html');
    const site = await resolvePath(expected);
    if (site !== expected) throw new Error('The managed WordPress root must match the reserved site.');

    const command = async (args, environment, loadTheme = false, loadPlugins = false) => (await run(wp, [
      '--path=' + site, ...(loadPlugins ? [] : ['--skip-plugins']), ...(loadTheme ? [] : ['--skip-themes']), ...args,
    ], { timeout: 20000, maxBuffer: 256 * 1024, env: environment ? { ...process.env, ...environment } : undefined })).stdout.trim();
    if ((await command(['option', 'get', 'home'])).replace(/\/$/, '') !== 'https://' + domain) {
      throw new Error('The WordPress site does not match the managed domain.');
    }
    return command;
  };
}

export function createManagedCommerceInstaller(options) {
  const resolveSite = createManagedWordPressCommands(options);
  return async (domain, mode = null) => {
    if (mode !== null) siteSetupMode.parse(mode);
    const command = await resolveSite(domain);
    const wpCommand = (...args) => command(args);
    const installed = async () => JSON.parse(await wpCommand('plugin', 'list', '--fields=name,version,status', '--format=json'));
    const original = await installed();
    if (mode === 'services') {
      if (original.some(({ name }) => Object.hasOwn(storePlugins, name) || name === 'kiwe-store')) {
        throw new Error('The services and portfolio setup needs a fresh WordPress site without Store plugins.');
      }
      return [];
    }
    const targets = Object.entries(mode && siteSetups[mode].store ? storePlugins : plugins);
    for (const [name, version] of targets) {
      const entry = original.find(item => item.name === name);
      if (mode && entry && entry.version !== version) {
        throw new Error('The existing WordPress commerce version needs an installation review.');
      }
      if (!entry) {
        await wpCommand('plugin', 'install', name, '--version=' + version);
      }
      if (mode || !entry) {
        await wpCommand('plugin', 'verify-checksums', name, '--strict');
      }
    }
    if (mode && !original.some(entry => entry.name === 'woocommerce' && entry.status === 'active')) {
      await wpCommand('plugin', 'activate', 'woocommerce');
    }
    const current = await installed();
    return targets.map(([name, version]) => {
      const found = current.find((entry) => entry.name === name);
      const previous = original.find((entry) => entry.name === name);
      const status = mode && name === 'woocommerce' ? 'active' : previous?.status ?? 'inactive';
      if (!found || found.version !== (previous?.version ?? version) || found.status !== status) {
        throw new Error('The managed commerce plugin could not be verified.');
      }
      return { name, version: found.version, status: found.status };
    });
  };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const [domainsDirectory, domain] = process.argv.slice(2);
    const install = createManagedCommerceInstaller({ domainsDirectory });
    process.stdout.write(JSON.stringify(await install(domain)) + '\n');
  } catch {
    process.stderr.write('The Kiwe commerce package could not be verified for this site.\n');
    process.exitCode = 1;
  }
}
