import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import path from 'node:path';

export function createManagedBricksInstaller({ resolveSite, archive, sha256, version }) {
  if (!path.isAbsolute(archive) || path.extname(archive).toLowerCase() !== '.zip'
      || !/^[a-f0-9]{64}$/.test(sha256) || !/^\d+\.\d+\.\d+$/.test(version)) {
    throw new Error('Configure the private Bricks archive, hash and stable release version.');
  }
  const approvedHash = async () => {
    const hash = createHash('sha256');
    for await (const chunk of createReadStream(archive)) hash.update(chunk);
    return hash.digest('hex') === sha256;
  };
  return async (domain) => {
    const command = await resolveSite(domain);
    const installed = async () => JSON.parse(await command(['theme', 'list', '--fields=name,version,status', '--format=json']));
    let bricks = (await installed()).find(theme => theme.name === 'bricks');
    if (!bricks) {
      if (!await approvedHash()) throw new Error('The private Bricks archive failed its hash check.');
      await command(['theme', 'install', archive]);
      bricks = (await installed()).find(theme => theme.name === 'bricks');
    }
    if (bricks?.version !== version) throw new Error('Bricks does not match the approved stable release.');
    if (!['active', 'parent'].includes(bricks.status)) await command(['theme', 'activate', 'bricks']);
    const status = await command(['eval',
      'echo class_exists("\\Bricks\\License") && \\Bricks\\License::license_is_valid() && \\Bricks\\License::get_license_status() !== "error_remote" ? "licensed" : "pending";',
    ], undefined, true);
    return status === 'licensed';
  };
}
