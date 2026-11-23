import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdir, readFile, realpath, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { promisify } from 'node:util';

const exec = promisify(execFile);
const exact = bytes => createHash('sha256').update(bytes).digest('hex');
const archiveLimit = 128 * 1024 * 1024;

function inside(root, file) {
  const relative = path.relative(root, file);
  return relative !== '' && relative !== '..' && !relative.startsWith('..' + path.sep) && !path.isAbsolute(relative);
}

/** Only the trusted local Seam CLI compiles the approved, typed source. */
export function createBricksCompiler({ seamRoot, workRoot, execute = exec }) {
  return {
    async compile({ id, files, archiveSha256 = null }) {
      if (!/^[a-f0-9]{32}$/.test(id)) throw new Error('Invalid Bricks import workspace.');
      await mkdir(workRoot, { recursive: true, mode: 0o700 });
      const root = await realpath(workRoot);
      const run = path.join(root, id);
      await mkdir(run, { recursive: true, mode: 0o700 });
      if (await realpath(run) !== run) throw new Error('The Bricks import workspace could not be verified.');
      const source = path.join(run, 'source');
      const output = path.join(run, 'seam');
      await mkdir(source, { recursive: true, mode: 0o700 });
      if (await realpath(source) !== source) throw new Error('The Bricks source workspace could not be verified.');
      const expected = ['index.html','styles.css','seam/site-graph.json','seam/seam-map.json','authoring/start-homepage-receipt.json'];
      if (Object.keys(files).length !== expected.length || expected.some(name => typeof files[name] !== 'string')) {
        throw new Error('Convert the exact approved homepage package.');
      }
      for (const name of expected) {
        const filename = path.join(source, name);
        const directory = path.dirname(filename);
        await mkdir(directory, { recursive: true, mode: 0o700 });
        if (await realpath(directory) !== directory) throw new Error('The Bricks source directory could not be verified.');
        try {
          const prior = await readFile(filename);
          if (await realpath(filename) !== filename || exact(prior) !== exact(files[name])) {
            throw new Error('The saved homepage package changed.');
          }
        } catch (error) {
          if (error.code !== 'ENOENT') throw error;
          await writeFile(filename, files[name], { flag: 'wx', mode: 0o600 });
        }
      }
      await mkdir(output, { recursive: true, mode: 0o700 });
      if (await realpath(output) !== output) throw new Error('The Seam output directory could not be verified.');
      if (!archiveSha256) {
        const compiler = path.join(seamRoot, 'tools', 'compile-folder.mts');
        await execute(process.execPath, ['--import','tsx',compiler,source,output,'Draft','--framework','--review-package'], {
          cwd: seamRoot, timeout: 180000, maxBuffer: 262144, windowsHide: true,
        });
      }
      const manifest = JSON.parse(await readFile(path.join(output, 'manifest.json'), 'utf8'));
      if (!Array.isArray(manifest.templates) || manifest.templates.length !== 1 || manifest.templates[0].path !== 'index.html'
          || manifest.templates[0].templateType !== 'content' || manifest.sourceRuntimeDependencies?.length
          || manifest.framework?.dependencies?.archive !== 'seam-framework.zip') {
        throw new Error('Seam must return one native homepage draft package.');
      }
      const archive = await realpath(path.join(output, 'seam-framework.zip'));
      if (!inside(output, archive)) throw new Error('The Seam ZIP could not be verified.');
      const { size } = await stat(archive);
      if (size < 1 || size > archiveLimit) throw new Error('The Seam ZIP is outside the native upload limit.');
      const bytes = await readFile(archive);
      if (archiveSha256 && exact(bytes) !== archiveSha256) throw new Error('The staged Seam ZIP changed.');
      return { bytes, frameworkFile: manifest.templates[0].frameworkFile };
    },
  };
}
