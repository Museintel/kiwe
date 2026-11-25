import assert from 'node:assert/strict';
import { createHash, createPublicKey, verify } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const fields = ['schema', 'version', 'channel', 'publishedAt', 'packageUrl', 'sha256', 'bytes',
  'requiresPhp', 'requiresWp', 'packageManifestSha256', 'keyId'];
const keyId = 'kiwe-release-2026-01';
const trusted = 'v6qwuL3+Zqx76G5A1MCqkvnzOM62fyXudc8OCgXqKzg=';

export async function readCoreRelease(directory) {
  const descriptor = JSON.parse(await readFile(path.join(directory, 'manifest.json'), 'utf8'));
  assert.equal(descriptor.schema, 'kiwe.mu-release.v1');
  assert.equal(descriptor.keyId, keyId);
  assert.match(descriptor.version, /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/);
  assert.ok(['stable', 'candidate'].includes(descriptor.channel));
  assert.match(descriptor.requiresPhp, /^\d+\.\d+(?:\.\d+)?$/);
  assert.match(descriptor.requiresWp, /^\d+\.\d+(?:\.\d+)?$/);
  assert.match(descriptor.packageManifestSha256, /^[a-f0-9]{64}$/);
  assert.equal(descriptor.packageUrl,
    'https://app.kiwelaunch.com/updates/kiwe/v1/releases/' + descriptor.version + '/kiwe-mu-plugin.zip');
  const key = createPublicKey({
    key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), Buffer.from(trusted, 'base64')]),
    format: 'der',
    type: 'spki',
  });
  const message = Buffer.from(fields.map(field => descriptor[field]).join('\n') + '\n');
  assert.ok(verify(null, message, key, Buffer.from(descriptor.signature, 'base64')), 'Kiwe release signature failed');
  assert.ok(Number.isSafeInteger(descriptor.bytes) && descriptor.bytes > 0 && descriptor.bytes <= 67108864);
  const archive = await readFile(path.join(directory, 'kiwe-mu-plugin.zip'));
  assert.equal(archive.length, descriptor.bytes);
  assert.equal(createHash('sha256').update(archive).digest('hex'), descriptor.sha256);
  return descriptor;
}
