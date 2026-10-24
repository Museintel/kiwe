const {spawnSync} = require('node:child_process');
for (const test of ['test-portable-store-recovery.php', 'test-store-recovery-gate.php']) {
  const result = spawnSync(process.env.KIWE_PHP || 'php', ['tools/release/' + test], {encoding:'utf8', windowsHide:true, maxBuffer:4*1024*1024});
  if (result.status !== 0 || result.stderr.trim()) throw Error(result.stderr || result.stdout || 'Store recovery test did not complete');
  const proof = JSON.parse(result.stdout.trim());
  const checks = Array.isArray(proof.checks) ? proof.checks.length : Object.keys(proof.checks || {}).length;
  if (proof.passed !== true || proof.cleanup !== true || !checks || (!Array.isArray(proof.checks) && Object.values(proof.checks).some(v => v !== true))) throw Error('Incomplete Store recovery proof');
  console.log(`PASS ${test}: ${checks} local checks; native database and Woo execution require separate evidence.`);
}
