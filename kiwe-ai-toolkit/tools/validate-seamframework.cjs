#!/usr/bin/env node
const { spawnSync } = require('node:child_process');
const path = require('node:path');

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  console.log('Usage: node tools/validate-seamframework.cjs <website/bricks-paste.html-or-handoff-dir>');
  console.log('');
  console.log('Runs the official Seam Framework website/page audit lane. PASS requires this executable proof, not copied or reconstructed validator logic.');
  process.exit(0);
}

const target = process.argv[2] || '.';
const auditTool = path.join(__dirname, 'audit-output.cjs');
const result = spawnSync(process.execPath, [auditTool, target], {
  cwd: path.resolve(__dirname, '..'),
  stdio: 'inherit'
});

if (result.error) {
  console.error(JSON.stringify({
    ok: false,
    error: 'KIWE_VALIDATOR_UNAVAILABLE',
    message: result.error.message
  }, null, 2));
  process.exit(1);
}

process.exit(typeof result.status === 'number' ? result.status : 1);
