import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash, createPublicKey, randomUUID, verify } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const run = promisify(execFile);
const codeDirectory = fileURLToPath(new URL('.', import.meta.url));
const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const signedFields = ['schema', 'version', 'channel', 'publishedAt', 'packageUrl', 'sha256', 'bytes',
  'requiresPhp', 'requiresWp', 'packageManifestSha256', 'keyId'];

export async function readRelease(directory) {
  const descriptor = JSON.parse(await readFile(path.join(directory, 'manifest.json'), 'utf8'));
  const trust = JSON.parse(await readFile(path.join(codeDirectory, 'kiwe-update-public-key.json'), 'utf8'));
  assert.equal(descriptor.schema, 'kiwe.mu-release.v1');
  assert.equal(descriptor.keyId, trust.keyId);
  assert.match(descriptor.version, /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/);
  assert.ok(['stable', 'candidate'].includes(descriptor.channel));
  assert.equal(descriptor.packageUrl,
    'https://app.kiwelaunch.com/updates/kiwe/v1/releases/' + descriptor.version + '/kiwe-mu-plugin.zip');
  const key = createPublicKey({
    key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), Buffer.from(trust.publicKey, 'base64')]),
    format: 'der',
    type: 'spki',
  });
  const message = Buffer.from(signedFields.map(field => descriptor[field]).join('\n') + '\n');
  assert.ok(verify(null, message, key, Buffer.from(descriptor.signature, 'base64')), 'Kiwe release signature failed');
  const archive = await readFile(path.join(directory, 'kiwe-mu-plugin.zip'));
  assert.equal(archive.length, descriptor.bytes);
  assert.equal(digest(archive), descriptor.sha256);
  return descriptor;
}

export function publicVersion(html) {
  return html.match(/window\.DSA\.boot\s*=\s*\{version:\s*"(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)"/)?.[1] ??
    html.match(/<!-- DSA Surface (\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?) -->/)?.[1] ?? null;
}

async function verifyPublic(site, expected, firstScroll) {
  let last;
  let consecutive = 0;
  for (let attempt = 0; attempt < 8; attempt++) {
    try {
      // Canonical requests must traverse the same cache as an ordinary visitor.
      const response = await fetch('https://' + site.domain + '/', { signal: AbortSignal.timeout(10000) });
      const body = await response.text();
      last = { status: response.status, version: publicVersion(body), firstScroll: body.includes('id="dsa-dock-reveal"'),
        cache: response.headers.get('x-hcdn-cache-status') };
      if (new URL(response.url).hostname === site.domain && response.ok && last.version === expected &&
          (firstScroll === undefined || firstScroll === last.firstScroll) &&
          !/There has been a critical error|Checking your browser/i.test(body)) {
        consecutive++;
        if (consecutive === 2) return last;
      } else {
        consecutive = 0;
      }
    } catch {
      consecutive = 0;
    }
    await delay(1500);
  }
  throw Error(site.domain + ' canonical homepage did not serve ' + expected + ' (' + JSON.stringify(last) + ')');
}

export async function rollout(sites, target, siteTask, purge, probe = verifyPublic) {
  const before = [];
  const attempted = [];
  const results = [];

  // Require every site to be ready before switching the first package.
  for (const site of sites) {
    const snapshot = await siteTask(site, 'inspect');
    assert.equal(snapshot.domain, site.domain);
    assert.ok(snapshot.current && snapshot.target === target && snapshot.ready, site.domain + ' failed preflight');
    before.push({ site, snapshot });
  }
  for (const { site, snapshot } of before) {
    try {
      await probe(site, snapshot.current);
    } catch {
      await purge(site);
      await probe(site, snapshot.current);
    }
  }
  for (const item of before) {
    if (item.snapshot.current !== target || item.snapshot.settingsChange) {
      const backup = await siteTask(item.site, 'backup');
      assert.equal(backup.current, item.snapshot.current);
      assert.equal(backup.ready, true, item.site.domain + ' has no confirmed recovery checkpoint');
    }
  }

  try {
    for (const { site, snapshot } of before) {
      if (snapshot.current !== target || snapshot.settingsChange) {
        attempted.push({ site, snapshot });
        if (snapshot.current !== target) await siteTask(site, 'apply');
        if (site.firstScroll !== undefined) await siteTask(site, 'settings');
      }
      // Purge even a site that already runs the release. Its edge may still serve an old page.
      await purge(site);
      const state = await siteTask(site, 'inspect');
      assert.equal(state.current, target, site.domain + ' did not boot the signed release');
      assert.equal(state.settingsChange, false, site.domain + ' did not confirm its requested Dock setting');
      assert.deepEqual(state.apps, snapshot.apps, site.domain + ' active App versions drifted during the rollout');
      results.push({ domain: site.domain, version: state.current, public: await probe(site, target, site.firstScroll) });
    }
    for (const site of sites) await probe(site, target, site.firstScroll);
    return results;
  } catch (error) {
    const failures = [];
    for (const { site, snapshot } of attempted.reverse()) {
      try {
        await siteTask(site, 'rollback');
        await purge(site);
        const state = await siteTask(site, 'inspect');
        assert.equal(state.current, snapshot.current);
        assert.equal(state.settingsChange, snapshot.settingsChange);
        assert.deepEqual(state.apps, snapshot.apps);
        await probe(site, snapshot.current);
      } catch (rollbackError) {
        failures.push(site.domain + ': ' + rollbackError.message);
      }
    }
    const recovery = failures.length ? ' Recovery needs attention on ' + failures.join(', ') :
      ' The previous releases were verified on the sites that changed.';
    throw Error(error.message + recovery, { cause: error });
  }
}

function validSite(site) {
  return typeof site?.domain === 'string' && /^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(site.domain) &&
    typeof site.sshAlias === 'string' && /^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(site.sshAlias) &&
    typeof site.wordpressRoot === 'string' && /^\/[a-zA-Z0-9_.\/-]+$/.test(site.wordpressRoot) &&
    !site.wordpressRoot.includes('..') &&
    typeof site.hostingerUsername === 'string' && /^u[0-9]+$/.test(site.hostingerUsername) &&
    (site.firstScroll === undefined || typeof site.firstScroll === 'boolean') &&
    (site.wpUser === undefined || typeof site.wpUser === 'string' && /^[a-zA-Z0-9][a-zA-Z0-9_.@-]*$/.test(site.wpUser)) &&
    (site.firstScroll === undefined || site.wpUser !== undefined);
}

async function main() {
  const [releaseDir, configFile] = process.argv.slice(2);
  if (!releaseDir || !configFile) {
    throw Error('Usage: node rollout-core-fleet.mjs SIGNED_RELEASE_DIRECTORY PRIVATE_FLEET_JSON');
  }
  const release = await readRelease(releaseDir);
  const sites = JSON.parse(await readFile(configFile, 'utf8'));
  assert.ok(Array.isArray(sites) && sites.length && sites.every(validSite), 'Invalid private fleet configuration');
  assert.equal(new Set(sites.map(site => site.domain)).size, sites.length, 'Duplicate fleet domain');
  const passphrases = JSON.parse(process.env.KIWE_FLEET_BACKUP_PASSPHRASES || '{}');
  const hostingerToken = process.env.HOSTINGER_API_TOKEN;
  assert.ok(hostingerToken, 'Missing Hostinger cache API authorization');
  for (const { domain } of sites) {
    assert.ok(typeof passphrases[domain] === 'string' && passphrases[domain].length >= 16 &&
      passphrases[domain].length <= 256 && !/[\r\n]/.test(passphrases[domain]), 'Missing private backup phrase for ' + domain);
  }

  const sshFlags = ['-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10'];
  const sshDir = process.env.KIWE_FLEET_SSH_DIR;
  if (sshDir) {
    sshFlags.push('-F', path.join(sshDir, 'config'), '-i', path.join(sshDir, 'identity'), '-o', 'IdentitiesOnly=yes',
      '-o', 'UserKnownHostsFile=' + path.join(sshDir, 'known_hosts'));
  }
  const nonce = randomUUID();
  const directories = new Map();
  const wp = site => 'wp --path=' + site.wordpressRoot + ' --quiet' + (site.wpUser ? ' --user=' + site.wpUser : '');
  async function ssh(site, command, input) {
    try {
      const process = run('ssh', [...sshFlags, site.sshAlias, command], { timeout: 600000, maxBuffer: 1048576 });
      process.child.stdin.on('error', () => {});
      process.child.stdin.end(input);
      return await process;
    } catch (error) {
      const reported = String(error.stdout ?? '').trim();
      let diagnostic;
      try { diagnostic = JSON.parse(reported)?.error; } catch {}
      throw Error(site.domain + ' remote operation failed' + (diagnostic ? ': ' + diagnostic : ''));
    }
  }
  try {
    for (const site of sites) {
      const { stdout } = await ssh(site, wp(site) +
        " eval 'echo \\DSA\\Runtime\\Private_Storage::directory( \"releases\", true );'");
      const privateRoot = stdout.trim();
      assert.match(privateRoot, /^\/[a-zA-Z0-9_.\/-]+\/kiwe-private-releases\/site-[a-f0-9]{16}$/);
      assert.ok(!privateRoot.includes('..') && !privateRoot.startsWith(site.wordpressRoot.replace(/\/$/, '') + '/'));
      const work = privateRoot + '/core-fleet-' + nonce;
      await ssh(site, 'umask 077 && mkdir -m 700 -- ' + work);
      directories.set(site, work);
      await run('scp', [...sshFlags, path.join(codeDirectory, 'core-fleet-site.php'),
        path.join(releaseDir, 'manifest.json'), path.join(releaseDir, 'kiwe-mu-plugin.zip'),
        site.sshAlias + ':' + work + '/'], { timeout: 120000 });
    }
    const siteTask = async (site, action) => {
      const firstScroll = site.firstScroll === undefined ? 'keep' : (site.firstScroll ? 'on' : 'off');
      const work = directories.get(site);
      const { stdout } = await ssh(site, wp(site) + ' eval-file ' + work + '/core-fleet-site.php ' +
        [action, work, site.domain, firstScroll].join(' '), action === 'backup' ? passphrases[site.domain] : undefined);
      return JSON.parse(stdout);
    };
    const purge = async site => {
      await siteTask(site, 'purge-local');
      const endpoint = 'https://developers.hostinger.com/api/hosting/v1/accounts/' + site.hostingerUsername +
        '/websites/' + site.domain + '/cache/clear';
      const response = await fetch(endpoint, {
        method: 'DELETE', headers: { Authorization: 'Bearer ' + hostingerToken },
        signal: AbortSignal.timeout(30000), redirect: 'error',
      });
      if (!response.ok) throw Error(site.domain + ' Hostinger cache purge returned HTTP ' + response.status);
    };
    const proven = await rollout(sites, release.version, siteTask, purge);
    console.log(JSON.stringify({ run: nonce, release: release.version, sha256: release.sha256, sites: proven }, null, 2));
  } catch (error) {
    throw Error('Fleet run ' + nonce + ': ' + error.message, { cause: error });
  } finally {
    for (const [site, work] of directories) {
      const uploaded = ['core-fleet-site.php', 'manifest.json', 'kiwe-mu-plugin.zip'];
      await ssh(site, 'rm -f -- ' + uploaded.map(file => work + '/' + file).join(' '))
        .catch(() => console.error(site.domain + ' still has private transfer files for fleet run ' + nonce));
    }
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch(error => { console.error(error.message); process.exitCode = 1; });
}
