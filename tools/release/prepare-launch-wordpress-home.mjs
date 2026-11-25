import { copyFile, mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const requested = process.argv[2];
if (!requested) throw new Error('Usage: node tools/release/prepare-launch-wordpress-home.mjs OUTPUT_DIRECTORY');

const launch = fileURLToPath(new URL('../../public/kiwelaunch.com/', import.meta.url));
const output = resolve(requested);
const publicRoot = resolve(launch);
if (output === publicRoot || output.startsWith(publicRoot + sep)) {
  throw new Error('Choose an output directory outside the published site source.');
}

const assets = join(launch, 'assets', 'launch');
const input = join(output, 'kiwe-launch-home-r7');
const compilerAssets = join(input, 'assets', 'launch');
const nativeAssets = join(output, 'site-assets');
const [html, styles] = await Promise.all([
  readFile(join(launch, 'index.html'), 'utf8'),
  readFile(join(assets, 'site.css'), 'utf8'),
]);
const authoredScript = /^[ \t]*<script src="\/assets\/launch\/site\.js" defer><\/script>\r?\n/m;
const fontFace = /^@font-face\s*\{[^{}]+\}\s*/.exec(styles);
if (!authoredScript.test(html) || !fontFace || !fontFace[0].includes('url("manrope.woff2")')) {
  throw new Error('Review the current Launch behavior and font stylesheet before preparing the Bricks draft.');
}

await Promise.all([mkdir(compilerAssets, { recursive: true }), mkdir(nativeAssets, { recursive: true })]);
const visualAssets = (await readdir(assets, { withFileTypes: true }))
  .filter(entry => entry.isFile() && /\.(?:webp|png|svg)$/i.test(entry.name));
await Promise.all([
  writeFile(join(input, 'index.html'), html.replace(authoredScript, '')),
  writeFile(join(compilerAssets, 'site.css'), styles.slice(fontFace[0].length)),
  writeFile(join(nativeAssets, 'launch-font.css'), fontFace[0].trimEnd() + '\n'),
  ...['site.js', 'manrope.woff2', 'manrope-OFL.txt'].map(name => copyFile(join(assets, name), join(nativeAssets, name))),
  ...visualAssets.map(({ name }) => copyFile(join(assets, name), join(compilerAssets, name))),
]);
console.log(JSON.stringify({ input, nativeAssets, visualAssets: visualAssets.length }));
