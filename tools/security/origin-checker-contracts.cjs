const { spawnSync } = require('node:child_process');
const path = require('node:path');

const result = spawnSync('php', ['tools/security/test-origin-checker.php'], {
  cwd: path.resolve(__dirname, '../..'),
  encoding: 'utf8',
});
process.stdout.write(result.stdout || '');
process.stderr.write(result.stderr || '');
if (result.error) throw result.error;
process.exit(result.status === 0 ? 0 : 1);
