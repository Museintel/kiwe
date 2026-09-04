import fs from 'node:fs/promises';
import path from 'node:path';

const [sourceRoot, remoteRoot = 'wp-content/mu-plugins', ...includeArguments] = process.argv.slice(2);
const baseUrl = String(process.env.KIWE_TUS_URL || '').replace(/\/+$/, '');
const authKey = String(process.env.KIWE_TUS_AUTH || '');
const restAuthKey = String(process.env.KIWE_TUS_REST_AUTH || '');

if (!sourceRoot || !baseUrl || !authKey || !restAuthKey) {
	throw new Error('Usage: set KIWE_TUS_URL, KIWE_TUS_AUTH, and KIWE_TUS_REST_AUTH, then pass the local MU-plugin directory.');
}

const include = includeArguments.length
	? includeArguments.flatMap((argument) => argument.split(',')).map((value) => value.trim()).filter(Boolean)
	: ['dsa', 'dsa.php', 'kiwe-incident-guard.php'];
const files = [];

async function walk(localPath, relativePath) {
	const stat = await fs.stat(localPath);
	if (stat.isDirectory()) {
		const entries = await fs.readdir(localPath, { withFileTypes: true });
		entries.sort((a, b) => a.name.localeCompare(b.name));
		for (const entry of entries) {
			await walk(path.join(localPath, entry.name), path.posix.join(relativePath, entry.name));
		}
		return;
	}
	if (stat.isFile()) files.push({ localPath, relativePath, size: stat.size });
}

for (const name of include) {
	await walk(path.resolve(sourceRoot, name), name);
}

const encodePath = (value) => value.split('/').map(encodeURIComponent).join('/');
const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function upload(file) {
	const remotePath = path.posix.join(remoteRoot, file.relativePath);
	const target = `${baseUrl}/${encodePath(remotePath)}?override=true`;
	const commonHeaders = {
		'X-Auth': authKey,
		'X-Auth-Rest': restAuthKey,
		'Tus-Resumable': '1.0.0',
	};

	for (let attempt = 1; attempt <= 4; attempt += 1) {
		try {
			const create = await fetch(target, {
				method: 'POST',
				headers: { ...commonHeaders, 'Upload-Length': String(file.size), 'Upload-Offset': '0' },
			});
			if (create.status !== 201) throw new Error(`create ${create.status}`);

			if (file.size > 0) {
				const body = await fs.readFile(file.localPath);
				const patch = await fetch(target, {
					method: 'PATCH',
					headers: { ...commonHeaders, 'Content-Type': 'application/offset+octet-stream', 'Upload-Offset': '0' },
					body,
					duplex: 'half',
				});
				if (patch.status !== 204 || Number(patch.headers.get('upload-offset')) !== file.size) {
					throw new Error(`patch ${patch.status} offset ${patch.headers.get('upload-offset') || 'missing'}`);
				}
			}
			return;
		} catch (error) {
			if (attempt === 4) throw new Error(`${remotePath}: ${error.message}`);
			await wait(250 * (2 ** attempt));
		}
	}
}

let completed = 0;

async function uploadBatch(batch, concurrency = 6) {
	let cursor = 0;
	const workers = Array.from({ length: Math.min(concurrency, batch.length) }, async () => {
		while (cursor < batch.length) {
			const index = cursor;
			cursor += 1;
			await upload(batch[index]);
			completed += 1;
			if (completed % 25 === 0 || completed === files.length) {
				console.log(`Uploaded ${completed}/${files.length}`);
			}
		}
	});
	await Promise.all(workers);
}

// The nested package must finish before its MU loaders are replaced. Keeping
// the two root entry points last prevents a mixed loader/package version from
// being observable even when this utility is used instead of Kiwe's atomic
// in-dashboard updater.
const loaderNames = new Set(['dsa.php', 'kiwe-incident-guard.php']);
const packageFiles = files.filter((file) => !loaderNames.has(file.relativePath));
const loaderFiles = files.filter((file) => loaderNames.has(file.relativePath));

await uploadBatch(packageFiles);
await uploadBatch(loaderFiles, 1);
console.log(`MU-plugin deployment complete: ${files.length} files.`);
