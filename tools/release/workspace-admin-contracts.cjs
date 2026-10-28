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
check(service.includes('Site_Owner_Service::is_owner( (int) $user->ID )'), 'Owner detection retains explicit native administration');
check(service.includes("wp_enqueue_style( 'kiwe-workspace-admin'") && !service.includes('wp_enqueue_script('), 'shell adds no admin JavaScript');
check(service.includes("wp_count_posts( 'post', 'readable' )") && !service.includes('get_terms('), 'dashboard uses bounded native counts without a category inventory');
check(service.includes("'shop_manager' === $role") && service.includes('post-new.php?post_type=product'), 'shop manager receives a product-only action lane');
const seoImport = service.match(/^use\s+(DSA\\SEO\\SEO_Refinement_Service);/m)?.[1];
const seoPath = seoImport && 'wp-content/mu-plugins/dsa/includes/' + seoImport.slice(4).replaceAll('\\', '/') + '.php';
check(!service.includes('SEO_Refinement_Service::') || !!(seoPath && fs.existsSync(seoPath) && read(seoPath).includes('class SEO_Refinement_Service')), 'Shop Manager SEO task resolves its real PHP class');
check(service.includes('Guest workspace') && service.includes('kiwe-guests'), 'Guest receives the protected contribution lane');
const homeRendering=service.slice(service.indexOf('public function render_dashboard()'));
check(!homeRendering.includes('PhoneKey_Bridge') && !service.includes('get_option('), 'client Home does not read technical channel configuration');
check(!service.includes("'Site healthy'") && !service.includes("'Protected'") && !service.includes("'No action required'"), 'dashboard makes no unevidenced security or health claim');
check(service.includes('Category_Workspace_Service::url()') && service.includes('Team_Workspace_Service::url()'), 'client task cards link the native category and team workspaces');
check(css.includes('@media (max-width: 782px)') && css.includes('@media (max-width: 520px)'), 'workspace has tablet and phone layouts');
check(!css.includes('backdrop-filter') && !css.includes('@keyframes'), 'workspace avoids glass and motion tax');

console.log(passed + ' workspace admin contracts passed.');
