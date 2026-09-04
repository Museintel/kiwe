const fs = require('node:fs');

let passed = 0;
function read(path) { return fs.readFileSync(path, 'utf8'); }
function check(condition, label) {
	if (!condition) throw new Error('FAIL ' + label);
	passed += 1;
	console.log('PASS ' + label);
}

const service = read('wp-content/mu-plugins/dsa/includes/Access/Workspace_Admin_Service.php');
const access = read('wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php');
const css = read('wp-content/mu-plugins/dsa/assets/css/workspace-admin.css');

check(access.includes('( new Workspace_Admin_Service() )->register();'), 'workspace reuses the role boundary');
check(access.includes("if ( 'index.php' === $script ) return;"), 'authorized staff can open the native dashboard route');
check(access.includes("[ 'index.php', 'edit.php', 'upload.php'"), 'Home is first in the client menu');
check(service.includes('Site_Owner_Service::is_owner()'), 'Super Admin is excluded from the client shell');
check(service.includes("wp_enqueue_style( 'kiwe-workspace-admin'") && !service.includes('wp_enqueue_script('), 'shell adds no admin JavaScript');
check(service.includes("wp_count_posts( 'post' )") && service.includes("get_terms( [ 'taxonomy'=>'category'"), 'dashboard reads canonical WordPress content');
check(service.includes("'shop_manager' === $role") && service.includes('post-new.php?post_type=product'), 'shop manager receives a product-only action lane');
check(service.includes('Guest workspace') && service.includes('kiwe-guests'), 'Guest receives the protected contribution lane');
check(service.includes("get_option( 'dsa_email_last_test'") && service.includes('PhoneKey_Bridge::provider_ready()'), 'status pills require real channel evidence');
check(css.includes('@media (max-width: 782px)') && css.includes('@media (max-width: 520px)'), 'workspace has tablet and phone layouts');
check(!css.includes('backdrop-filter') && !css.includes('@keyframes'), 'workspace avoids glass and motion tax');

console.log(passed + ' workspace admin contracts passed.');
