const path = require('node:path');
const fs = require('node:fs');
const { build } = require('esbuild');
const { writeWorkspaceArtifacts } = require('./workspace-css.cjs');
const kinds = ['ai','analytics','app','apps','backups','commerce','continuity','dock','feedback','forms','guest','identity','media','mcp','navigation','notification','posts','seo-object','system','taxonomy','team','templates','trash'];
// One source owner; only Core Forms needs the union-receipt adapter. Project before
// parsing so unrelated immutable App bundles also retain their exact identifiers.
function activitySource(source, kind) {
  const marker = /\/\* KIWE_MULTI_SOURCE_ACTIVITY_START \*\/[\s\S]*?\/\* KIWE_MULTI_SOURCE_ACTIVITY_END \*\//g;
  if (Array.from(source.matchAll(marker)).length !== 1) throw Error('One explicit Forms activity extension is required.');
  return kind === 'forms' ? source : source.replace(marker, '');
}
async function buildWorkspace(kind) {
  if (!kinds.includes(kind)) throw Error('Unknown native workspace');
  const result = await build({ absWorkingDir: __dirname, entryPoints: [kind === 'seo-object' ? 'seo-core-workspace.jsx' : kind + '-app-entry.jsx'], bundle: true, format: 'iife', platform: 'browser', target: ['es2020'], minify: true, write: false, outfile: kind + '-app.js', metafile: true, plugins: [{ name:'native-activity-owner', setup(b) { b.onLoad({ filter:/workspace-activity\.mjs$/ }, args => { if (args.path !== path.join(__dirname,'workspace-activity.mjs')) throw Error('Unknown activity source.'); return { contents:activitySource(fs.readFileSync(args.path,'utf8'),kind),loader:'js' }; }); } }], alias: { react: path.join(__dirname, 'wp-element-shim.mjs'), 'react-dom/client': path.join(__dirname, 'wp-element-shim.mjs') } });
  if (Object.keys(result.metafile.inputs).some(file => /workspace-model|node_modules[\\/]react/.test(file))) throw Error('Native workspace must use WordPress React and no sample records.');
  return writeWorkspaceArtifacts(result);
}
async function main(selected = kinds) {
  const output = [];
  for (const kind of selected) output.push({ kind, assets: await buildWorkspace(kind) });
  console.log(JSON.stringify({ passed: true, mode: process.argv.includes('--check') ? 'verify' : 'build', react: 'WordPress wp-element', sampleRecords: false, output }));
}
if (require.main === module) main().catch(error => { console.error(error.message); process.exitCode = 1; });
module.exports = { buildWorkspace, main, kinds, activitySource };
