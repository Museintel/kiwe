#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const capsule = read('wp-content/mu-plugins/dsa/includes/AI/Task_Capsule_Service.php');
const openapi = read('wp-content/mu-plugins/dsa/includes/AI/External_Client_OpenAPI_Service.php');
const adapters = read('wp-content/mu-plugins/dsa/includes/AI/External_Client_Adapter_Service.php');
const controller = read('wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php');
const graph = read('wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const start = read('KIWE-START.md');
const dynamic = read('kiwe-ai-toolkit/contexts/dynamic-lite.md');

const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

check('task capsules are hash-only and revocable', capsule.includes("wp_hash_password( $plain )") && capsule.includes('public function revoke(') && !capsule.includes("'token'       => $plain"));
check('task capsules expire and enforce request budgets', capsule.includes("'expiresUnix'") && capsule.includes("'maxUses'") && capsule.includes('capsule_exhausted'));
check('task capsules are public-only and mutation-forbidden', capsule.includes("'publicOnly'  => true") && capsule.includes("'mutation'    => 'forbidden'") && !capsule.match(/SCOPES\s*=\s*\[[\s\S]*controlled_mutation/));
check('task capsules enforce resource field and row budgets', capsule.includes('authorize_data_args') && capsule.includes('capsule_resource_denied') && capsule.includes("'maxRows'"));
check('task capsule graph redacts private structural evidence', controller.includes("'publicOnly'  => 'task_capsule'") && graph.includes("$public_only ? 'publish' : [ 'publish', 'draft', 'private' ]") && graph.includes("'privateSettingsRedacted' => true") && graph.includes("$query['public'] = true"));
check('OpenAPI exposes both bearer and custom-header authentication', openapi.includes("'KiweBearer'") && openapi.includes("'KiweHeader'") && openapi.includes("'openapi' => '3.1.0'"));
check('OpenAPI marks authority boundaries', openapi.includes('read-convert-validate') && openapi.includes('separately-authorized-staging') && openapi.includes('high-authority-permanent-key-only'));
check('OpenAPI and client manifest are public secret-free discovery routes', controller.includes("'/ai/openapi.json'") && controller.includes("'/ai/client-manifest'") && openapi.includes("'security'    => []"));
check('ordinary external tools receive a reduced task-only OpenAPI contract', controller.includes("'/ai/openapi.task.json'") && openapi.includes("'x-kiwe-task-only'") && adapters.includes('/openapi.task.json'));
check('AI routes enforce origin and credential rate limits', controller.includes("'kiwe-ai-auth:'") && controller.includes("'kiwe-ai-client:'") && controller.includes("'Retry-After'"));
check('AI controller distinguishes permanent keys from task capsules', controller.includes("credential_kind") && controller.includes("'task_capsule'") && controller.includes('authenticate_request( $request, $scope )'));
check('SiteGraph admin explains namespace versus webpage', admin.includes('Base API namespace (not a webpage)') && admin.includes('Do not paste the capsule secret into an ordinary AI chat.'));
check('SiteGraph admin creates and revokes downloadable client connections', admin.includes('dsa_create_sitegraph_client_package') && admin.includes('dsa_revoke_sitegraph_task_capsule') && admin.includes('kiwe.external-client-connection.v1'));
check('permanent all-scope keys are not selected by default', admin.includes('All Kiwe AI connector access') && !admin.includes('name="scopes[]" value="all" checked'));
check('command documentation covers task capsules and vendor-neutral clients', start.includes('SiteGraph task capsule') && dynamic.includes('kiwe.external-client-manifest.v1'));

const php = spawnSync('php', ['tools/release/test-sitegraph-task-capsule.php'], { cwd: root, encoding: 'utf8' });
check('SiteGraph task capsule PHP runtime contract passes', php.status === 0 && /PASS SiteGraph task capsule, task OpenAPI and adapter/.test(String(php.stdout)));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
if (php.status !== 0) process.stderr.write(String(php.stderr || php.stdout || 'PHP runtime contract failed.') + '\n');
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} SiteGraph external-client contracts passed.`);
if (failed.length) process.exit(1);
