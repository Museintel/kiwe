const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname,'../..');
const result = spawnSync(process.env.KIWE_PHP || 'php',['tools/release/test-portable-recovery-ownership.php'],{cwd:root,encoding:'utf8',windowsHide:true,maxBuffer:4*1024*1024});
let proof;
try { proof = JSON.parse(result.stdout); } catch {}
if (proof) {
 proof.sourceVersion = fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/dsa.php'),'utf8').match(/define\(\s*'DSA_VERSION',\s*'([^']+)'/)?.[1];
 proof.sourceOnly = true;
 proof.exitCode = result.status;
 proof.sourceHashes = Object.fromEntries([
  'wp-content/mu-plugins/dsa/includes/Backup/Portable_Recovery_Migration.php',
  'wp-content/mu-plugins/dsa/includes/Backup/Portable_Recovery_Ownership.php',
  'wp-content/mu-plugins/dsa/includes/Backup/Portable_Recovery_Database.php',
  'wp-content/mu-plugins/dsa/includes/Backup/Portable_Recovery_Stage.php',
  'wp-content/mu-plugins/dsa/recovery/portable-recovery.php',
  'tools/release/test-portable-recovery-ownership.php',
 ].map(file=>[file,crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex')]));
 const file = path.join(root,'docs/generated',`kiwe-recovery-ownership-local-${proof.sourceVersion.replace('8.0.0-rc.','rc')}.json`);
 const bytes = `${JSON.stringify(proof,null,2)}\n`;
 if (fs.existsSync(file) && fs.readFileSync(file,'utf8') !== bytes) throw Error('Retain the original ownership evidence; use a fresh candidate or named attempt.');
 fs.writeFileSync(file,bytes);
}
if (result.status !== 0 || proof?.passed !== true || proof?.cleanup !== true || Object.values(proof.checks).some(v=>v!==true)) throw Error(result.stderr || result.stdout || 'Ownership proof incomplete');
console.log(`PASS native owner quarantine (${Object.keys(proof.checks).length} identity/policy/denial checks; no local SQL execution).`);
