const fs = require('node:fs'), path = require('node:path'), {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..'), php = process.env.KIWE_PHP || 'php';
const probe = spawnSync(php, ['-r','echo json_encode([PHP_BINARY, extension_loaded("zip")]);'], {cwd:root,encoding:'utf8',windowsHide:true});
if (probe.error || probe.status !== 0) throw probe.error || Error('PHP probe failed');
const [binary,zip] = JSON.parse(probe.stdout), flags = [];
if (!zip) { const extension = path.join(path.dirname(binary),'ext',process.platform === 'win32' ? 'php_zip.dll' : 'zip.so'); if (!fs.existsSync(extension)) throw Error('Native ZIP is required'); flags.push('-d', `extension=${extension}`); }
for (const script of ['test-compiler-package-reader.php','test-framework-style-merge.php','test-compiler-import-boundaries.php','test-template-owner-trash.php']) {
  const result = spawnSync(php,[...flags,'tools/release/' + script],{cwd:root,encoding:'utf8',windowsHide:true,maxBuffer:4*1024*1024});
  if (result.stdout) process.stdout.write(result.stdout); if (result.stderr) process.stderr.write(result.stderr);
  if (result.error || result.status !== 0 || /Warning:|Fatal error:|Deprecated:/.test(result.stdout + result.stderr)) throw result.error || Error(script + ' failed');
}
