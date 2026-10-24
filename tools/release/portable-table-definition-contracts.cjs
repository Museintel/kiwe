const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const result = spawnSync(process.env.KIWE_PHP || 'php', ['tools/release/test-portable-table-definition.php'], {
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
		'wp-content/mu-plugins/dsa/includes/Backup/Portable_Table_Definition.php',
		'wp-content/mu-plugins/dsa/includes/Backup/Portable_Recovery_Database.php',
		'wp-content/mu-plugins/dsa/recovery/portable-recovery.php',
		'tools/release/test-portable-table-definition.php',
	].map(file => [file, crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex')]));
	const file = path.join(root, 'docs/generated', `kiwe-table-definition-local-${proof.sourceVersion.replace('8.0.0-rc.', 'rc')}.json`);
	const bytes = `${JSON.stringify(proof,null,2)}\n`;
	if (fs.existsSync(file) && fs.readFileSync(file,'utf8') !== bytes) {
		console.error('Retain the original definition receipt; use a fresh candidate or named attempt for changed evidence.');
		process.exit(1);
	}
	fs.writeFileSync(file, bytes);
}
if (result.status !== 0 || proof?.passed !== true || proof?.cleanup !== true) {
	console.error(result.stderr || result.stdout || result.error?.message || 'Native definition proof did not complete.');
	process.exit(1);
}
console.log(`PASS strict native table definition (${Object.keys(proof.checks).length} parser and denial checks; no SQL execution in this local fixture).`);
