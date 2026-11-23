import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { stat } from 'node:fs/promises';
import { createInterface } from 'node:readline';
import { Transform } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { createGunzip } from 'node:zlib';

function checkedProcess(command, args, label, timeout) {
  const child = spawn(command, args, { timeout, windowsHide: true, stdio: ['pipe', 'pipe', 'ignore'] });
  const completed = new Promise((resolve, reject) => {
    child.once('error', () => reject(new Error(label + ' could not start')));
    child.once('close', code => code === 0 ? resolve() : reject(new Error(label + ' failed')));
  });
  return { child, completed };
}

export async function inspectSqlDump(compressed, tables) {
  assert.ok(tables.length > 0 && new Set(tables).size === tables.length &&
    tables.every(table => /^[A-Za-z0-9_]{1,64}$/.test(table)), 'Invalid database inventory');
  const hash = createHash('sha256');
  const touched = new Set();
  const decode = createGunzip();
  const pass = new Transform({
    transform(bytes, encoding, done) {
      hash.update(bytes);
      done(null, bytes);
    },
  });
  const lines = createInterface({ input: decode, crlfDelay: Infinity });
  let completed = false;
  const streamed = pipeline(compressed, pass, decode);
  const inspected = (async () => {
    for await (const line of lines) {
      const table = /^CREATE TABLE `([A-Za-z0-9_]+)` \($/.exec(line)?.[1];
      if (table) {
        assert.ok(!touched.has(table), 'Duplicate table in the SQL backup');
        touched.add(table);
      }
      if (line.startsWith('-- Dump completed on ')) completed = true;
    }
  })();
  try {
    await Promise.all([streamed, inspected]);
  } finally {
    lines.close();
    decode.destroy();
  }
  assert.ok(completed, 'The native SQL dump did not finish');
  assert.deepEqual([...touched].sort(), [...tables].sort(), 'The native SQL dump differs from the database inventory');
  return hash.digest('hex');
}

export async function captureEncryptedSql({ command, args, input, age, identity, recipient, file, tables }) {
  assert.match(recipient, /^age1[a-z0-9]+$/);
  const timeout = 1200000;
  const dump = checkedProcess(command, args, 'Native SQL dump', timeout);
  const encrypt = checkedProcess(age, ['--encrypt', '--recipient', recipient, '--output', file], 'Database encryption', timeout);
  dump.child.stdin.on('error', () => {});
  dump.child.stdin.end(input);
  try {
    await Promise.all([dump.completed, encrypt.completed, pipeline(dump.child.stdout, encrypt.child.stdin)]);
  } finally {
    if (dump.child.exitCode === null) dump.child.kill();
    if (encrypt.child.exitCode === null) encrypt.child.kill();
  }
  const decrypt = checkedProcess(age, ['--decrypt', '--identity', identity, file], 'Encrypted database verification', timeout);
  decrypt.child.stdin.end();
  let sqlGzipSha256;
  try {
    [sqlGzipSha256] = await Promise.all([inspectSqlDump(decrypt.child.stdout, tables), decrypt.completed]);
  } finally {
    if (decrypt.child.exitCode === null) decrypt.child.kill();
  }
  const hash = createHash('sha256');
  for await (const bytes of createReadStream(file)) hash.update(bytes);
  return { tables, sqlGzipSha256, sha256: hash.digest('hex'), bytes: (await stat(file)).size };
}
