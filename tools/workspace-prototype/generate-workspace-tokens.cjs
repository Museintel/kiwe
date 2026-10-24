const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const { transformSync } = require('esbuild');
const root = path.resolve(__dirname, '../..');
const owners = ['wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php', 'wp-content/mu-plugins/dsa/includes/Access/Workspace_Design_Service.php', 'tools/workspace-prototype/workspace-token-source.php'];
function sourceHash() { return crypto.createHash('sha256').update(owners.map(file => fs.readFileSync(path.join(root, file), 'utf8').replace(/\r\n/g, '\n')).join('\n')).digest('hex'); }
function generate() {
  const result = spawnSync('php', [path.join(__dirname, 'workspace-token-source.php')], { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0 || !result.stdout.startsWith(':root{') || /Warning:|Fatal error:|Deprecated:/.test(result.stdout + result.stderr)) throw Error('Canonical workspace token projection failed: ' + result.stderr);
  return '/* Generated private workspace tokens; source-sha256:' + sourceHash() + ' */\n' + transformSync(result.stdout, { loader: 'css', minify: true }).code;
}
function verifySource(text) { if (!text.includes('source-sha256:' + sourceHash())) throw Error('Regenerate workspace tokens from canonical owners.'); return text; }
if (require.main === module) {
  const expected = generate(), file = path.join(root, 'wp-content/mu-plugins/dsa/assets/css/workspace-tokens.css');
  if (process.argv.includes('--check')) { if (fs.readFileSync(file, 'utf8') !== expected) throw Error('Generated workspace tokens differ from canonical owners.'); }
  else fs.writeFileSync(file, expected);
  console.log(JSON.stringify({ passed: true, mode: process.argv.includes('--check') ? 'verify' : 'build', bytes: Buffer.byteLength(expected) }));
}
module.exports = { generate, verifySource, sourceHash };
