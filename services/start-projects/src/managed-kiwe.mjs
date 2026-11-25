import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { readCoreRelease } from './core-release.mjs';

const helper = fileURLToPath(new URL('./managed-kiwe.php', import.meta.url));

export function createManagedKiweInstaller({ directory, resolveSite }) {
  if (!path.isAbsolute(directory)) throw new Error('Use the private absolute Kiwe release directory.');
  return async (domain) => {
    const release = await readCoreRelease(directory);
    const command = await resolveSite(domain);
    const environment = {
      KIWE_MANAGED_SITE_ORIGIN: 'https://' + domain,
      KIWE_MANAGED_RELEASE_DIR: directory,
      KIWE_MANAGED_CORE_HASH: release.sha256,
    };
    if (await command(['eval-file', helper, 'core'], environment) !== 'core ready') {
      throw new Error('The signed Core did not install on the managed site.');
    }
    if (await command(['eval-file', helper, 'mcp'], environment) !== 'mcp ready') {
      throw new Error('The signed Kiwe MCP App did not install on the managed site.');
    }
    const boot = await command(['eval-file', helper, 'boot'], environment, false, true);
    if (boot !== 'kiwe ready') throw new Error('The Kiwe stack did not boot on the managed site.');
  };
}
