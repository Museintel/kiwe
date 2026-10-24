const {spawnSync} = require('node:child_process');
const {resolve} = require('node:path');
const result = spawnSync('php', ['tools/release/test-category-workspace.php'], {cwd: resolve(__dirname, '../..'), encoding: 'utf8'});
process.stdout.write(result.stdout || '');
process.stderr.write(result.stderr || '');
if (result.error) throw result.error;
if (result.status !== 0 || /Warning:|Fatal error:|Deprecated:/.test((result.stdout || '') + (result.stderr || ''))) process.exit(1);
