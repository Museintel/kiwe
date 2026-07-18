const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const keys = read('wp-content/mu-plugins/dsa/includes/AI/Access_Key_Service.php');
const rest = read('wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php');
const manifest = read('wp-content/mu-plugins/dsa/package-manifest.json');
const docs = read('KIWE-AI.md') + '\n' + read('kiwe-ai-toolkit/contexts/dynamic-lite.md');

const checks = [];
function check(label, pass, detail = '') {
	checks.push({ label, pass: Boolean(pass), detail });
}

check('Kiwe admin has a dedicated AI submenu', admin.includes("'kiwe-ai'") && admin.includes('render_ai_page'));
check('Framework page remains framework-focused', !admin.includes('Kiwe > Framework > AI connector and Site Graph') && admin.includes('Push Kiwe Framework to Bricks'));
check('AI admin creates and revokes hashed API keys', admin.includes('dsa_create_ai_access_key') && admin.includes('dsa_revoke_ai_access_key') && keys.includes('wp_hash_password'));
check('API key secrets are shown once via transient only', admin.includes('dsa_ai_key_once_') && keys.includes("'hash'") && !keys.includes("'plain'"));
check('REST API-key controller is registered', plugin.includes('new AI_Access_Controller') && rest.includes("'/ai/site-graph'"));
check('REST AI routes cover trusted apply chain', rest.includes("'/ai/stage-apply-plan'") && rest.includes("'/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/post-apply-verification'"));
check('REST AI routes require API-key guard', rest.includes('authenticate_request') && rest.includes("'permission_callback' => '__return_true'"));
check('Status endpoint accepts any active key', rest.includes("[ 'GET', '/ai/status', 'status', 'status' ]") && keys.includes("'status' !== $required_scope"));
check('Package manifest includes API key files', manifest.includes('includes/AI/Access_Key_Service.php') && manifest.includes('includes/Rest/AI_Access_Controller.php'));
check('Docs point external AI clients to Kiwe > AI and /ai API', docs.includes('Kiwe > AI > API access keys') && docs.includes('/wp-json/dsa/v1/ai/site-graph'));
check('Docs do not send AI connector workflow back to Kiwe > Framework', !docs.includes('Kiwe > Framework > AI connector'));

const failures = checks.filter((item) => !item.pass);
for (const item of checks) {
	console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.label}${item.detail ? ` :: ${item.detail}` : ''}`);
}

if (failures.length) {
	console.error(`\n${failures.length}/${checks.length} AI API connector contracts failed.`);
	process.exit(1);
}

console.log(`\n${checks.length}/${checks.length} AI API connector contracts passed.`);
