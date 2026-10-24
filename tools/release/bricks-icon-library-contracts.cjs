const path = require('node:path'), { spawnSync } = require('node:child_process');
const result = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'test-bricks-icon-library.php')], { encoding: 'utf8' });
if (result.status !== 0) throw new Error(result.stdout + '\n' + result.stderr);
const proof = JSON.parse(result.stdout.trim());
if (proof.passed !== true || !Array.isArray(proof.checks) || proof.checks.some(check => check.passed !== true)) throw new Error('Bricks icon boundaries failed');
console.log(JSON.stringify({ passed: true, checks: proof.checks.length, native: proof.native }));
