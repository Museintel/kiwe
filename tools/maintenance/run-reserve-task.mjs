import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const phpFiles = ['WordPress_Role_Access_Service', 'Workspace_Admin_Service', 'Workspace_Design_Service', 'Workspace_Notice_UI', 'Account_Setup_Notice_Service', 'Category_Workspace_Service', 'Team_Workspace_Service', 'Page_Workspace_Service', 'Guest_Contribution_Service', 'Context_Workspace_Service', 'Navigation_Workspace_Service', 'Task_Workspace_UI','AI_Workspace_Service'].map(name=>`Access/${name}`).concat(['Onboarding/Context_Selection_Service','Onboarding/Design_Context_Profile_Service','Site_Graph/Data_Query_Service','Site_Graph/Design_Context_Service','Notifications/Admin_Event_Notification_Service','Notifications/Notification_Preference_Service','Communications/Channel_Service','AI/AI_Allowance_Service','AI/AI_Usage_Service','AI/AI_Feature_Policy','AI/AI_Broker_Service','AI/AI_Provider_Service','Admin/Admin','SEO/SEO_Refinement_Service','SEO/SEO_Job_Store','SEO/SEO_Discovery_Service','SEO/SEO_Score_Source','SEO/SEO_Score_Service','Onboarding/Onboarding_Service','Settings','Runtime/Package_Manifest']);
const recipes = {
  shell: [[process.execPath,['tools/release/workspace-shell-contracts.cjs']], ['php',['-l','wp-content/mu-plugins/dsa/includes/Access/Workspace_Shell_Service.php']], ['php',['-l','wp-content/mu-plugins/dsa/includes/Onboarding/Onboarding_Service.php']], [process.execPath,['tools/release/verify-package.cjs']]],
  retention: [[process.execPath, ['tools/runtime/securetrack-retention-behavior.cjs']], ['php', ['-l', 'tools/release/test-wordpress-securetrack-retention.php']], [process.execPath, ['tools/release/verify-package.cjs']]],
  telemetry: [[process.execPath, ['tools/runtime/securetrack-telemetry-behavior.cjs']], [process.execPath, ['tools/runtime/securetrack-worker-budget.cjs']], ['php', ['-l', 'tools/release/test-wordpress-securetrack-telemetry.php']], ['php', ['-l', 'wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Event_Service.php']], ['php', ['-l', 'wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php']], ['php', ['-l', 'wp-content/mu-plugins/dsa/includes/Secure/securetrack-admin-core.php']], [process.execPath, ['tools/release/verify-package.cjs']]],
  pwa: [[process.execPath, ['tools/pwa/worker-behavior.cjs']], [process.execPath, ['tools/pwa/rc7-contracts.cjs']], ['php', ['-l', 'tools/release/test-wordpress-pwa.php']], [process.execPath, ['tools/release/verify-package.cjs']]],
  schema: [[process.execPath, ['tools/release/securetrack-schema-contracts.cjs']]],
  workspace: ['client-workspace-contracts.cjs', 'workspace-admin-contracts.cjs', 'workspace-app-contracts.cjs', 'category-workspace-contracts.cjs', 'team-workspace-contracts.cjs'].map(name => [process.execPath, [`tools/release/${name}`]]),
  content: ['client-workspace-contracts.cjs', 'product-notification-contracts.cjs'].map(name => [process.execPath, [`tools/release/${name}`]]),
  context: [[process.execPath, ['tools/release/context-navigation-contracts.cjs']]],
  features: [[process.execPath, ['tools/release/ai-feature-contracts.cjs']], [process.execPath, ['tools/release/ai-broker-contracts.cjs']], ['php', ['-l','wp-content/mu-plugins/dsa/includes/AI/AI_Broker_Service.php']], [process.execPath, ['tools/release/verify-package.cjs']]],
  allowance: [[process.execPath, ['tools/release/ai-allowance-contracts.cjs']]],
  tokens: [['php', ['-l','wp-content/mu-plugins/dsa/includes/AI/AI_Allowance_Service.php']], [process.execPath, ['tools/release/ai-allowance-contracts.cjs']], [process.execPath, ['tools/release/ai-feature-contracts.cjs']], [process.execPath, ['tools/release/ai-broker-contracts.cjs']], [process.execPath, ['tools/release/verify-package.cjs']]],
  seo: [['php',['-l','wp-content/mu-plugins/dsa/includes/SEO/SEO_Refinement_Service.php']],['php',['-l','wp-content/mu-plugins/dsa/includes/SEO/SEO_Job_Store.php']], ...['onboarding-contracts.cjs','seo-job-contracts.cjs','owner-handoff-refinement-contracts.cjs','ai-feature-contracts.cjs','ai-broker-contracts.cjs','verify-package.cjs'].map(name=>[process.execPath,[`tools/release/${name}`]])],
  score: [ ...['SEO/SEO_Score_Service','SEO/SEO_Score_Source','SEO/SEO_Refinement_Service','Onboarding/Design_Context_Profile_Service','Onboarding/Onboarding_Service'].map(name=>['php',['-l',`wp-content/mu-plugins/dsa/includes/${name}.php`]]), ...['seo-score-contracts.cjs','seo-job-contracts.cjs','onboarding-contracts.cjs','design-context-native-contracts.cjs','verify-package.cjs'].map(name=>[process.execPath,[`tools/release/${name}`]]) ],
  syntax: phpFiles.map(name => ['php', ['-l', `wp-content/mu-plugins/dsa/includes/${name}.php`]]),
  docs: [[process.execPath, ['tools/maintenance/check-maintained-doc-links.mjs']]],
  baseline: [[process.execPath, ['tools/release/verify-green-baseline.cjs']]],
};
const [task, option, requestedOutput, ...extra] = process.argv.slice(2);
if (task === '--list') {
  console.log(Object.keys(recipes).join('\n'));
  process.exit(0);
}
if (!Object.hasOwn(recipes, task || '') || option !== '--out' || !requestedOutput || extra.length) {
  console.error('Usage: node tools/maintenance/run-reserve-task.mjs <shell|retention|telemetry|pwa|schema|workspace|content|context|allowance|features|tokens|seo|score|syntax|docs|baseline> --out <absolute directory outside the ecosystem repositories>');
  process.exit(2);
}
if (!path.isAbsolute(requestedOutput)) throw new Error('Evidence directory must be absolute.');
const ecosystemRoot = fs.realpathSync(path.dirname(root));
const inside = (parent, target) => {const rel = path.relative(parent, target); return rel === '' || (!rel.startsWith(`..${path.sep}`) && rel !== '..' && !path.isAbsolute(rel));};
if (inside(ecosystemRoot, path.resolve(requestedOutput))) throw new Error('Evidence must remain outside the ecosystem source workspace.');
let ancestor = path.resolve(requestedOutput);
while (!fs.existsSync(ancestor)) ancestor = path.dirname(ancestor);
if (inside(ecosystemRoot, fs.realpathSync(ancestor))) throw new Error('Evidence cannot follow a link into the ecosystem source workspace.');
fs.mkdirSync(requestedOutput, {recursive: true});
const out = fs.realpathSync(requestedOutput);
if (inside(ecosystemRoot, out)) throw new Error('Evidence must remain outside the ecosystem source workspace.');
function command(executable, args, timeout = 300000) {
  const started = Date.now();
  const r = spawnSync(executable, args, {cwd: root, encoding: 'utf8', timeout, windowsHide: true, maxBuffer: 16 * 1024 * 1024, shell: false});
  return {executable: path.basename(executable), args, exit: r.status, signal: r.signal, error: r.error?.code || null, milliseconds: Date.now() - started, output: `${r.stdout || ''}${r.stderr || ''}`};
}
function git(args) {
  const result = command('git', args, 30000);
  if (result.exit !== 0) throw new Error(`Unable to record Git state: ${args[0]}`);
  return result.output;
}
function snapshot() {
  const paths = [...new Set(git(['ls-files', '--cached', '--others', '--exclude-standard', '-z']).split('\0').filter(Boolean))].sort();
  const files = paths.map(file => {
    const absolute = path.join(root, file);
    if (!fs.existsSync(absolute)) return [file, 'missing'];
    const stat = fs.lstatSync(absolute);
    if (!stat.isFile()) throw new Error(`Unsupported non-file source entry: ${file}`);
    return [file, crypto.createHash('sha256').update(fs.readFileSync(absolute)).digest('hex')];
  });
  return {revision: git(['rev-parse', 'HEAD']).trim(), branch: git(['branch', '--show-current']).trim(), dirty: git(['status', '--porcelain=v1', '-z']).split('\0').filter(Boolean), sourceHash: crypto.createHash('sha256').update(JSON.stringify(files)).digest('hex'), fileCount: files.length};
}
const before = snapshot();
if (before.branch !== 'main') throw new Error('This recipe expects the canonical main checkout.');
const startedAt = new Date().toISOString();
const runs = recipes[task].map(([executable, args]) => {
  const result = command(executable, args);
  const lines = result.output.split(/\r?\n/);
  // Only known synthetic gate summaries enter the receipt; raw output remains out of shared evidence.
  const checks = lines.filter(line => /^(?:PASS |FAIL |\d+ .* (?:passed|failed)\.?$|No syntax errors detected|All .* passed)/.test(line)).map(line => line.slice(0, 500));
  const diagnostics = /(?:Warning:|Fatal error:|Deprecated:)/.test(result.output);
  const outputHash = crypto.createHash('sha256').update(result.output).digest('hex');
  delete result.output;
  console.log(`${result.exit === 0 && !diagnostics ? 'PASS' : 'FAIL'} ${args.join(' ')}`);
  return {...result, diagnostics, outputHash, checks};
});
const after = snapshot();
const unchanged = before.sourceHash === after.sourceHash && before.revision === after.revision && before.branch === after.branch;
const passed = runs.every(run => run.exit === 0 && !run.diagnostics);
const php = command('php', ['-r', 'echo PHP_VERSION;'], 30000);
const receipt = {schema: 'kiwe-reserve-evidence/v1', task, startedAt, endedAt: new Date().toISOString(), state: !unchanged ? 'inconclusive' : passed ? 'passed' : 'failed', sourceUnchanged: unchanged, before, after, runtime: {node: process.version, php: php.exit === 0 ? php.output.trim() : 'unavailable', platform: process.platform}, runs, limitation: 'Named local checks only; not real WordPress, browser, delivery or deployment certification.'};
const destination = path.join(out, `${task}-${Date.now()}-${crypto.randomUUID()}.json`);
fs.writeFileSync(destination, JSON.stringify(receipt, null, 2) + '\n', {flag: 'wx'});
console.log(`Receipt: ${destination}`);
console.log(`Result: ${receipt.state}`);
process.exitCode = receipt.state === 'passed' ? 0 : 1;
