const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const exists = (relative) => fs.existsSync(path.join(root, relative));
const checks = [];
const check = (name, condition) => checks.push([name, Boolean(condition)]);

const settings = read('wp-content/mu-plugins/dsa/includes/Settings.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const assets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
const vendorReadme = read('wp-content/mu-plugins/dsa/assets/vendor/README.md');
const manifest = read('wp-content/mu-plugins/dsa/package-manifest.json');
const roadmap = read('docs/KIWE-UI-OVERHAUL-ROADMAP.md');

check('enhancement gates default off', settings.includes("'enhancements'") && settings.includes("'enabled' => false") && settings.includes("'htmx'    => false") && settings.includes("'alpine'  => false"));
check('admin sanitizes enhancement gates', admin.includes('sanitize_enhancement_settings') && admin.includes("$next['htmx']    = $next['enabled']") && admin.includes("$next['alpine']  = $next['enabled']"));
check('admin htmx pilot is nonce and capability guarded', admin.includes("wp_ajax_dsa_developer_package_proof") && admin.includes("current_user_can( 'manage_options' )") && admin.includes("check_ajax_referer( 'dsa_developer_package_proof'"));
check('admin htmx pilot is read-only package proof', admin.includes('render_package_proof_fragment') && admin.includes('Package_Manifest::verify()') && !admin.includes('hx-post='));
check('admin loads gated local htmx and alpine assets only on Developer page', admin.includes("'kiwe_page_kiwe-developer' === $hook") && admin.includes("assets/vendor/htmx/htmx.min.js") && admin.includes("assets/vendor/alpine/alpine.min.js") && admin.includes("wp_script_add_data( 'dsa-alpine', 'defer', true )"));
check('frontend loads only local packaged assets', assets.includes("assets/vendor/htmx/htmx.min.js") && assets.includes("assets/vendor/alpine/alpine.min.js") && !assets.includes('https://unpkg.com') && !assets.includes('https://cdn.jsdelivr.net'));
check('public runtime metadata is scoped', assets.includes("'scope'   => 'server-fragment-pilots'") && assets.includes("'scope'   => 'isolated-local-widget-pilots'"));
check('vendored files are present', exists('wp-content/mu-plugins/dsa/assets/vendor/htmx/htmx.min.js') && exists('wp-content/mu-plugins/dsa/assets/vendor/alpine/alpine.min.js'));
check('package manifest inventories vendor assets', manifest.includes('"assets/vendor/htmx/htmx.min.js"') && manifest.includes('"assets/vendor/alpine/alpine.min.js"'));
check('vendor note records forbidden ownership', vendorReadme.includes('must not control PhoneKey/auth') && vendorReadme.includes('checkout/payment authority') && vendorReadme.includes('core Surface lifecycle'));
check('roadmap records controlled htmx/alpine track', roadmap.includes('H1 - Controlled htmx/Alpine Integration Track') && roadmap.includes('server-owned fragment pilots') && roadmap.includes('isolated local-widget pilots'));

const failed = checks.filter(([, passed]) => !passed);
for (const [name, passed] of checks) process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`);
process.stdout.write(`${checks.length - failed.length}/${checks.length} htmx/Alpine contracts passed.\n`);
if (failed.length) process.exit(1);
