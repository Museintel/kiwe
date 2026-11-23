import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { publicVersion, readRelease, rollout, validateTargets } from './rollout-core-fleet.mjs';
import { captureEncryptedSql } from './core-fleet-sql-backup.mjs';

const exec = promisify(execFile);
const toolRoot = fileURLToPath(new URL('.', import.meta.url));
const [target, mode, configFile, releaseDir, channelDir] = process.argv.slice(2);
const { laptop, sites } = JSON.parse(await readFile(configFile, 'utf8'));
validateTargets(sites, target);
assert.ok(mode === 'apply' || (['rehearse', 'rehearse-sql'].includes(mode) && target === 'staging' && sites.length === 1));
assert.ok(sites.every(site => site.wordpressRoot === '/home/' + site.hostingerUsername + '/domains/' + site.domain + '/public_html'));
assert.match(laptop.user, /^u[0-9]+$/);
assert.match(laptop.host, /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/);
assert.ok(Number.isInteger(laptop.port) && laptop.port >= 1 && laptop.port < 65536);
assert.ok(sites.every(site => site.hostingerUsername === laptop.user));

const phrases = JSON.parse(process.env.KIWE_LOCAL_FLEET_BACKUP_PHRASES || '{}');
delete process.env.KIWE_LOCAL_FLEET_BACKUP_PHRASES;
assert.ok(sites.every(site => typeof phrases[site.domain] === 'string' && /^[A-Za-z0-9+/=]{43,}$/.test(phrases[site.domain])));
assert.equal(new Set(sites.map(site => phrases[site.domain])).size, sites.length);

const release = await readRelease(releaseDir);
const nonce = randomUUID();
const targetHost = laptop.user + '@' + laptop.host;
const php = '/opt/alt/php82/usr/bin/php -d extension=sodium';
const options = [
	'-i', laptop.identity,
	'-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
	'-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + laptop.knownHosts,
	'-o', 'GlobalKnownHostsFile=none', '-o', 'HostKeyAlgorithms=ssh-ed25519',
	'-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4',
];
const remoteWork = new Map();
const transfer = ['core-fleet-site.php', 'core-fleet-recovery.php', 'manifest.json', 'kiwe-mu-plugin.zip'];
const record = { run: nonce, target, mode, version: release.version, sha256: release.sha256, events: [] };
const event = (type, fields = {}) => {
	const entry = { type, ...fields };
	record.events.push(entry);
	console.log(JSON.stringify(entry));
};
const wp = site => php + ' /usr/local/bin/wp --path=' + site.wordpressRoot + ' --quiet' + (site.wpUser ? ' --user=' + site.wpUser : '');
const commands = command => ['-p', String(laptop.port), ...options, targetHost, command];

async function copyPrivate(directory, files, timeout = 120000) {
	await exec('scp.exe', ['-P', String(laptop.port), ...options, ...files, targetHost + ':' + directory + '/'],
		{ timeout, maxBuffer: 1048576, windowsHide: true });
}

async function remote(site, command, input, timeout = 90000) {
	try {
		const child = exec('ssh.exe', commands(command),
			{ timeout, maxBuffer: 1048576, windowsHide: true });
		child.child.stdin.on('error', () => {});
		child.child.stdin.end(input);
		return (await child).stdout.trim();
	} catch (error) {
		let diagnostic;
		try { diagnostic = JSON.parse(String(error.stdout))?.error; } catch {}
		const fallback = String(error.stderr || '').split(/\r?\n/).find(line => /^(Error:|PHP Fatal error:|PHP Warning:)/.test(line));
		throw new Error(site.domain + ': ' + (diagnostic || fallback || 'SSH command failed or timed out').slice(0, 400));
	}
}

async function prepare(site) {
	const query = 'echo json_encode([realpath(ABSPATH), wp_parse_url(home_url(), PHP_URL_HOST), extension_loaded("sodium"), extension_loaded("zip")]);';
	const location = JSON.parse(await remote(site, wp(site) + " eval '" + query + "'"));
	assert.deepEqual(location, [site.wordpressRoot, site.domain, true, true], 'WordPress location or release prerequisites differ on ' + site.domain);
	const root = await remote(site, wp(site) + " eval 'echo \\DSA\\Runtime\\Private_Storage::directory(\"releases\",true);'");
	assert.match(root, /^\/[a-zA-Z0-9_.\/-]+\/kiwe-private-releases\/site-[a-f0-9]{16}$/);
	assert.ok(root.startsWith('/home/' + laptop.user + '/domains/' + site.domain + '/') && !root.includes('..') && !root.startsWith(site.wordpressRoot + '/'));
	const destination = root + '/core-fleet-' + nonce;
	await remote(site, 'umask 077 && mkdir -m 700 -- ' + destination);
	remoteWork.set(site, destination);
	const localFiles = transfer.map((name, index) => path.join(index < 2 ? toolRoot : releaseDir, name));
	await copyPrivate(destination, localFiles);
	event('prepared', { domain: site.domain });
}

async function siteTask(site, action, input) {
	const directory = remoteWork.get(site);
	const choice = site.firstScroll === undefined ? 'keep' : site.firstScroll ? 'on' : 'off';
	if (action !== 'inspect') event('phase', { domain: site.domain, action });
	if (action === 'restore-files') {
		return JSON.parse(await remote(site, php + ' ' + directory + '/core-fleet-recovery.php ' + directory + ' ' + site.wordpressRoot));
	}
	const command = wp(site) + ' eval-file ' + directory + '/core-fleet-site.php ' + [action, directory, site.domain, choice].join(' ');
	const response = await remote(site, command, input,
		action === 'backup' ? 1200000 : 90000);
	return JSON.parse(response);
}

async function sqlBackup(site) {
	const directory = remoteWork.get(site);
	const { age, ageKeygen, vaultIdentity, storage } = laptop;
	const { stdout } = await exec(ageKeygen, ['-y', vaultIdentity], { windowsHide: true, timeout: 8000 });
	const recipient = stdout.trim();
	assert.equal(recipient, laptop.vaultRecipient, 'The recovery identity differs from the enrolled laptop');
	const { database, tables } = await siteTask(site, 'prepare-sql-backup');
	assert.match(database, /^[A-Za-z0-9_]{1,64}$/);
	assert.ok(Array.isArray(tables) && tables.length && tables.every(table => /^[A-Za-z0-9_]{1,64}$/.test(table)));
	const localRoot = path.join(storage, nonce, site.domain);
	await mkdir(localRoot, { recursive: true });
	const filename = 'database.sql.gz.age';
	const file = path.join(localRoot, filename);
	event('native-sql-started', { domain: site.domain, tables: tables.length });
	const config = directory + '/sql-client.cnf';
	const dump = 'set -o pipefail\n' + "trap 'rm -f -- " + config + "' EXIT\n" +
		'/usr/bin/mariadb-dump --defaults-file=' + config + ' --single-transaction --quick --skip-lock-tables --no-tablespaces --hex-blob -- ' +
		database + ' ' + tables.join(' ') + ' | /usr/bin/gzip -1 -c\n';
	const receipt = await captureEncryptedSql({
		command: 'ssh.exe', args: commands('/bin/bash -s'), input: dump, age, identity: vaultIdentity, recipient, file, tables,
	});
	await writeFile(path.join(localRoot, 'receipt.json'), JSON.stringify({ domain: site.domain, ...receipt }, null, 2) + '\n', { flag: 'wx' });
	await copyPrivate(directory, [file], 900000);
	await remote(site, 'chmod 600 -- ' + directory + '/' + filename);
	const result = await siteTask(site, 'backup-sql', JSON.stringify(receipt));
	event('native-sql-verified', { domain: site.domain, bytes: receipt.bytes, tables: tables.length });
	return result;
}

async function task(site, action) {
	if (action === 'backup') {
		if (mode === 'rehearse-sql') return sqlBackup(site);
		try { return await siteTask(site, action, phrases[site.domain]); }
		catch (error) {
			if (!error.message.endsWith('A native table exceeds the supported row limit.')) throw error;
			return sqlBackup(site);
		}
	}
	return siteTask(site, action);
}

async function purge(site) {
	await task(site, 'purge-local');
	const id = randomUUID();
	const reply = path.join(channelDir, id + '.json');
	event('cache', { id, domain: site.domain });
	const deadline = Date.now() + 120000;
	while (Date.now() < deadline) {
		try {
			const acknowledgement = JSON.parse(await readFile(reply, 'utf8'));
			assert.strictEqual(acknowledgement.ok, true, 'The Hostinger cache operation failed for ' + site.domain);
			return;
		} catch (error) {
			if (error.code !== 'ENOENT') throw error;
			await delay(350);
		}
	}
	throw new Error('Hostinger cache acknowledgement timed out for ' + site.domain);
}

async function canonicalVersion(domain, version) {
	for (let index = 0; index < 2; index++) {
		const response = await fetch('https://' + domain + '/', { signal: AbortSignal.timeout(15000) });
		assert.ok(response.ok && new URL(response.url).hostname === domain, 'Invalid canonical response for ' + domain);
		assert.equal(publicVersion(await response.text()), version, 'Unexpected canonical Kiwe version for ' + domain);
	}
}

await mkdir(channelDir, { recursive: true });
try {
	for (const site of sites) await prepare(site);
	const baseline = new Map();
	for (const site of sites) {
		const before = await task(site, 'inspect');
		baseline.set(site, before);
		event('preflight', { domain: site.domain, current: before.current, target: before.target, appCount: Object.keys(before.apps).length });
	}
	const result = await rollout(sites, release.version, task, purge);
	record.sites = result;
	for (const site of result) event('verified', { domain: site.domain, core: site.version, firstScroll: site.public.firstScroll });
	if (mode !== 'apply') {
		const [site] = sites;
		const before = baseline.get(site);
		try { await task(site, 'rollback'); }
		catch {
			await task(site, 'restore-files');
			await task(site, 'rollback');
		}
		await purge(site);
		const after = await task(site, 'inspect');
		assert.equal(after.current, before.current);
		assert.equal(after.settingsChange, before.settingsChange);
		assert.deepEqual(after.apps, before.apps);
		await canonicalVersion(site.domain, before.current);
		record.hostedRollback = { domain: site.domain, restored: before.current, dockRestored: true, appsPreserved: true };
		event('restored', record.hostedRollback);
	}
	record.ok = true;
} catch (error) {
	record.ok = false;
	record.error = error.message;
	event('failed', { message: error.message });
	process.exitCode = 1;
} finally {
	for (const [site, directory] of remoteWork) {
		try { await remote(site, 'rm -f -- ' + [...transfer, 'sql-client.cnf'].map(name => directory + '/' + name).join(' ')); }
		catch { event('cleanup-needed', { domain: site.domain, run: nonce }); }
	}
	await writeFile(path.join(channelDir, 'proof.json'), JSON.stringify(record, null, 2) + '\n', { flag: 'wx' });
}
