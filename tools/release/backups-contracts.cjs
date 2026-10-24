const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const php = process.env.KIWE_PHP || 'php';
const probe = spawnSync(php, ['-r', 'echo json_encode([PHP_BINARY, extension_loaded("openssl")]);'], {cwd:root, encoding:'utf8', windowsHide:true});
if (probe.error || probe.status !== 0) throw probe.error || Error(probe.stderr || 'PHP probe failed.');
const [binary, ready] = JSON.parse(probe.stdout);
const args = [];
if (!ready) {
 const extension = path.join(path.dirname(binary), 'ext', process.platform === 'win32' ? 'php_openssl.dll' : 'openssl.so');
 if (!fs.existsSync(extension)) throw Error('PHP OpenSSL extension is unavailable for the backup restore fixture.');
 args.push('-d', `extension=${extension}`);
}
args.push('tools/release/test-configuration-snapshot.php');
const result = spawnSync(php, args, {cwd:root, encoding:'utf8', windowsHide:true});
if (result.stdout) process.stdout.write(result.stdout);
if (result.stderr) process.stderr.write(result.stderr);
if (result.error || result.status !== 0 || /Warning:|Fatal error:/.test(result.stdout || '')) throw result.error || Error('Configuration backup behavior failed.');
const receipt = JSON.parse(result.stdout.trim());
if (receipt.passed !== true || receipt.cleanup !== true || Object.keys(receipt.checks || {}).length < 16 || Object.values(receipt.checks).some(value => value !== true)) throw Error('Configuration backup receipt is incomplete.');
