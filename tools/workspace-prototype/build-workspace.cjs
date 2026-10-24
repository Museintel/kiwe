const path = require('node:path');
const { build } = require('esbuild');
const { writeWorkspaceArtifacts } = require('./workspace-css.cjs');
const kinds = ['ai','analytics','app','apps','backups','commerce','continuity','dock','forms','guest','identity','media','navigation','notification','posts','seo-object','taxonomy','team','templates','trash'];
async function buildWorkspace(kind) {
  if (!kinds.includes(kind)) throw Error('Unknown native workspace');
  const result = await build({ absWorkingDir: __dirname, entryPoints: [kind === 'seo-object' ? 'seo-core-workspace.jsx' : kind + '-app-entry.jsx'], bundle: true, format: 'iife', platform: 'browser', target: ['es2020'], minify: true, write: false, outfile: kind + '-app.js', metafile: true, alias: { react: path.join(__dirname, 'wp-element-shim.mjs'), 'react-dom/client': path.join(__dirname, 'wp-element-shim.mjs') } });
  if (Object.keys(result.metafile.inputs).some(file => /workspace-model|node_modules[\\/]react/.test(file))) throw Error('Native workspace must use WordPress React and no sample records.');
  return writeWorkspaceArtifacts(result);
}
async function main(selected = kinds) {
  const output = [];
  for (const kind of selected) output.push({ kind, assets: await buildWorkspace(kind) });
  console.log(JSON.stringify({ passed: true, mode: process.argv.includes('--check') ? 'verify' : 'build', react: 'WordPress wp-element', sampleRecords: false, output }));
}
if (require.main === module) main().catch(error => { console.error(error.message); process.exitCode = 1; });
module.exports = { buildWorkspace, main, kinds };
