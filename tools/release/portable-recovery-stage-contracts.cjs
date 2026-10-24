const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const probe = spawnSync(process.env.KIWE_PHP || 'php', ['-r', 'echo json_encode([PHP_BINARY,extension_loaded("openssl")]);'], {encoding:'utf8',windowsHide:true});
if(probe.status!==0)throw Error(probe.stderr||'PHP unavailable');
const [binary,ssl]=JSON.parse(probe.stdout),phpArgs=[];
if(!ssl){const ext=path.join(path.dirname(binary),'ext',process.platform==='win32'?'php_openssl.dll':'openssl.so');if(!require('node:fs').existsSync(ext))throw Error('OpenSSL unavailable');phpArgs.push('-d','extension='+ext);}
const result = spawnSync(process.env.KIWE_PHP || 'php', [...phpArgs, 'tools/release/test-portable-recovery-stage.php'], {
	cwd: root, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024,
});
let proof;
try { proof = JSON.parse(result.stdout); } catch {}
if (proof) {
	const entry = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/dsa.php'), 'utf8');
	proof.sourceVersion = entry.match(/define\(\s*'DSA_VERSION',\s*'([^']+)'/)?.[1];
	proof.sourceOnly = true;
	proof.exitCode = result.status;
	proof.sourceHashes = Object.fromEntries([
		'wp-content/mu-plugins/dsa/includes/Backup/Portable_Recovery_Stage.php',
		'wp-content/mu-plugins/dsa/includes/Backup/Portable_Archive_Container.php',
		'wp-content/mu-plugins/dsa/includes/Backup/Portable_Database_Capture.php',
		'wp-content/mu-plugins/dsa/recovery/portable-recovery.php',
		'tools/release/test-portable-recovery-stage.php',
	].map(file => [file, crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex')]));
	const suffix = process.env.KIWE_RECOVERY_STAGE_RECEIPT_SUFFIX || '';
	if (suffix && !/^[a-z0-9][a-z0-9-]{0,47}$/.test(suffix)) throw Error('Use a bounded named recovery receipt attempt.');
	const file = path.join(root, 'docs/generated', `kiwe-recovery-stage-local-${proof.sourceVersion.replace('8.0.0-rc.', 'rc')}${suffix ? '-'+suffix : ''}.json`);
	const bytes = `${JSON.stringify(proof,null,2)}\n`;
	if (fs.existsSync(file) && fs.readFileSync(file,'utf8') !== bytes) {
		console.error('Retain the original staging receipt; use a fresh candidate or named attempt for changed evidence.');
		process.exit(1);
	}
	fs.writeFileSync(file, bytes);
}
if (result.status !== 0 || proof?.passed !== true || proof?.cleanup !== true) {
	console.error(result.stderr || result.stdout || result.error?.message || 'Private staging proof did not complete.');
	process.exit(1);
}
console.log(`PASS private destination staging (${Object.keys(proof.checks).length} behavior checks; synthetic SQL model, real local files and CLI).`);
