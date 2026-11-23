import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { createBricksCompiler } from '../src/bricks-compiler.mjs';

test('a prepared Bricks upload reuses the exact local ZIP and detects changed bytes', async (t) => {
  const workRoot = await mkdtemp(path.join(tmpdir(), 'kiwe-seam-retry-'));
  t.after(() => rm(workRoot, { recursive: true, force: true }));
  const id = 'a'.repeat(32);
  const output = path.join(workRoot, id, 'seam');
  const file = path.join(output, 'seam-framework.zip');
  const archive = Buffer.from('the first compiled package');
  let compiles = 0;
  const compiler = createBricksCompiler({
    seamRoot: workRoot, workRoot, async execute(_command, args) {
      compiles++;
      assert.equal(args[4], output);
      await writeFile(file, archive);
      await writeFile(path.join(output, 'manifest.json'), JSON.stringify({
        templates: [{ path: 'index.html', templateType: 'content', frameworkFile: 'templates/home.json' }],
        framework: { dependencies: { archive: 'seam-framework.zip' } },
      }));
    },
  });
  const files = Object.fromEntries([
    'index.html', 'styles.css', 'seam/site-graph.json', 'seam/seam-map.json', 'authoring/start-homepage-receipt.json',
  ].map(name => [name, 'approved ' + name]));
  const { bytes } = await compiler.compile({ id, files });
  const archiveSha256 = createHash('sha256').update(bytes).digest('hex');
  assert.deepEqual((await compiler.compile({ id, files, archiveSha256 })).bytes, archive);
  assert.equal(compiles, 1);
  await writeFile(file, 'different bytes');
  await assert.rejects(compiler.compile({ id, files, archiveSha256 }), /ZIP changed/);
});
