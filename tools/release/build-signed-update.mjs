#!/usr/bin/env node
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const muRoot = path.join(repo, 'wp-content', 'mu-plugins');
const secretDir = path.join(repo, 'tools', 'release', '.secrets');
const privatePath = path.join(secretDir, 'kiwe-update-ed25519-private.pem');
const publicPath = path.join(repo, 'tools', 'release', 'kiwe-update-public-key.json');
const outputRoot = path.join(repo, 'public', 'app.kiwelaunch.com', 'updates', 'kiwe', 'v1');
const initKey = process.argv.includes('--init-key');
const channelArg = process.argv.find((value) => value.startsWith('--channel='));
const channel = channelArg ? channelArg.slice('--channel='.length) : 'candidate';

if (!['stable', 'candidate'].includes(channel)) throw new Error('Channel must be stable or candidate.');

async function ensureKey() {
	const environmentKey = String(process.env.KIWE_UPDATE_ED25519_PRIVATE_PEM || '').trim();
	if (environmentKey) {
		const privatePem = `${environmentKey}\n`;
		const privateDer = crypto.createPrivateKey(privatePem);
		if (privateDer.asymmetricKeyType !== 'ed25519') {
			throw new Error('KIWE_UPDATE_ED25519_PRIVATE_PEM must contain an Ed25519 private key.');
		}
		const publicDer = crypto.createPublicKey(privateDer).export({ type: 'spki', format: 'der' });
		const raw = publicDer.subarray(publicDer.length - 32);
		const record = {
			schema: 'kiwe.update-public-key.v1',
			keyId: 'kiwe-release-2026-01',
			algorithm: 'ed25519',
			publicKey: raw.toString('base64'),
		};
		await fs.writeFile(publicPath, `${JSON.stringify(record, null, 2)}\n`);
		return { privatePem, record };
	}

	try {
		await fs.access(privatePath);
	} catch {
		if (!initKey) throw new Error('Release signing key is missing. Set KIWE_UPDATE_ED25519_PRIVATE_PEM or run once with --init-key on a trusted workstation.');
		await fs.mkdir(secretDir, { recursive: true });
		const { privateKey } = crypto.generateKeyPairSync('ed25519');
		await fs.writeFile(privatePath, privateKey.export({ type: 'pkcs8', format: 'pem' }), { mode: 0o600 });
	}

	const privatePem = await fs.readFile(privatePath, 'utf8');
	const publicDer = crypto.createPublicKey(privatePem).export({ type: 'spki', format: 'der' });
	const raw = publicDer.subarray(publicDer.length - 32);
	const record = {
		schema: 'kiwe.update-public-key.v1',
		keyId: 'kiwe-release-2026-01',
		algorithm: 'ed25519',
		publicKey: raw.toString('base64'),
	};
	await fs.writeFile(publicPath, `${JSON.stringify(record, null, 2)}\n`);
	return { privatePem, record };
}

function versionFrom(source, constant) {
	const match = source.match(new RegExp(`define\\(\\s*'${constant}'\\s*,\\s*'([^']+)'\\s*\\)`));
	if (!match || !/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(match[1])) throw new Error(`Unable to read ${constant}.`);
	return match[1];
}

async function copyPackage(stage) {
	for (const name of ['dsa', 'dsa.php', 'kiwe-incident-guard.php']) {
		await fs.cp(path.join(muRoot, name), path.join(stage, name), { recursive: true, force: true });
	}
}

function zipStage(stage, destination) {
	if (process.platform === 'win32') {
		const result = spawnSync('tar.exe', ['-a', '-c', '-f', destination, 'dsa', 'dsa.php', 'kiwe-incident-guard.php'], { cwd: stage, encoding: 'utf8' });
		if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'tar zip creation failed');
		return;
	}
	{
		const result = spawnSync('zip', ['-q', '-r', destination, 'dsa', 'dsa.php', 'kiwe-incident-guard.php'], { cwd: stage, encoding: 'utf8' });
		if (result.status !== 0) throw new Error(result.stderr || 'zip failed');
	}
}

function signaturePayload(release) {
	return [
		release.schema,
		release.version,
		release.channel,
		release.publishedAt,
		release.packageUrl,
		release.sha256,
		String(release.bytes),
		release.requiresPhp,
		release.requiresWp,
		release.packageManifestSha256,
		release.keyId,
	].join('\n') + '\n';
}

const { privatePem, record: publicKey } = await ensureKey();
const loader = await fs.readFile(path.join(muRoot, 'dsa.php'), 'utf8');
const nested = await fs.readFile(path.join(muRoot, 'dsa', 'dsa.php'), 'utf8');
const version = versionFrom(loader, 'KIWE_MU_LOADER_VERSION');
if (version !== versionFrom(nested, 'DSA_VERSION')) throw new Error('Loader and nested versions do not match.');

const packageManifestPath = path.join(muRoot, 'dsa', 'package-manifest.json');
const packageManifest = JSON.parse(await fs.readFile(packageManifestPath, 'utf8'));
if (packageManifest.version !== version) throw new Error('Build package-manifest.json before signing the release.');

const releaseDir = path.join(outputRoot, 'releases', version);
const stage = path.join(repo, '.tmp', `kiwe-signed-stage-${version}`);
const archive = path.join(releaseDir, 'kiwe-mu-plugin.zip');
await fs.rm(stage, { recursive: true, force: true });
await fs.mkdir(stage, { recursive: true });
await fs.mkdir(releaseDir, { recursive: true });
await copyPackage(stage);
zipStage(stage, archive);
await fs.rm(stage, { recursive: true, force: true });

const archiveBody = await fs.readFile(archive);
const manifestBody = await fs.readFile(packageManifestPath);
const release = {
	schema: 'kiwe.mu-release.v1',
	version,
	channel,
	publishedAt: new Date().toISOString(),
	packageUrl: `https://app.kiwelaunch.com/updates/kiwe/v1/releases/${version}/kiwe-mu-plugin.zip`,
	sha256: crypto.createHash('sha256').update(archiveBody).digest('hex'),
	bytes: archiveBody.length,
	requiresPhp: '8.2',
	requiresWp: '7.0',
	packageManifestSha256: crypto.createHash('sha256').update(manifestBody).digest('hex'),
	keyId: publicKey.keyId,
};
release.signature = crypto.sign(null, Buffer.from(signaturePayload(release)), privatePem).toString('base64');

await fs.writeFile(path.join(releaseDir, 'manifest.json'), `${JSON.stringify(release, null, 2)}\n`);
await fs.writeFile(path.join(outputRoot, `${channel}.json`), `${JSON.stringify(release, null, 2)}\n`);
console.log(`Built signed ${channel} release ${version} (${release.bytes} bytes).`);
console.log(`Public key: ${publicKey.publicKey}`);
