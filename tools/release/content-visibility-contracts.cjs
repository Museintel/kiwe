const path = require('node:path');
const {spawnSync} = require('node:child_process');
const result = spawnSync(process.env.KIWE_PHP || process.env.PHP_BINARY || 'php', [path.join(__dirname, 'test-content-visibility.php')], {
	cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', windowsHide: true
});
if (result.status !== 0 || /Warning:|Fatal error:|Deprecated:/.test(result.stdout + result.stderr)) throw new Error(result.stdout + result.stderr);
const proof = JSON.parse(result.stdout);
if (!proof.passed || proof.checks < 40) throw new Error('Incomplete native content visibility regression');
console.log(JSON.stringify(proof));
