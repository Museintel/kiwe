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
const manifest = read('wp-content/mu-plugins/dsa/package-manifest.json');
const roadmap = read('docs/KIWE-UI-OVERHAUL-ROADMAP.md');
const changelog = read('CHANGELOG.md');

check('enhancement settings lane is retired', !settings.includes("'enhancements'        =>") && settings.includes("unset( $settings['enhancements'] )") && settings.includes('SAFETY_MIGRATION_VERSION = 4'));
check('admin no longer exposes htmx/Alpine gates', !admin.includes('Controlled web-app enhancements') && !admin.includes('sanitize_enhancement_settings') && !admin.includes('wp_ajax_dsa_developer_package_proof'));
check('admin package proof remains server-rendered without htmx attributes', admin.includes('render_package_proof_fragment') && admin.includes('Package_Manifest::verify()') && !admin.includes('hx-get=') && !admin.includes('hx-trigger=') && !admin.includes('dsa-refresh-package-proof'));
check('admin no longer loads htmx or Alpine', !admin.includes('assets/vendor/htmx/htmx.min.js') && !admin.includes('assets/vendor/alpine/alpine.min.js') && !admin.includes("wp_script_add_data( 'dsa-alpine'"));
check('frontend no longer loads htmx or Alpine', !assets.includes('dsa-htmx') && !assets.includes('dsa-alpine') && !assets.includes('assets/vendor/htmx/htmx.min.js') && !assets.includes('assets/vendor/alpine/alpine.min.js'));
check('public boot payload no longer advertises enhancement metadata', !assets.includes("'enhancements' =>") && !assets.includes('enhancement_data') && !assets.includes('server-fragment-pilots') && !assets.includes('isolated-local-widget-pilots'));
check('vendored htmx and Alpine files are absent', !exists('wp-content/mu-plugins/dsa/assets/vendor/htmx/htmx.min.js') && !exists('wp-content/mu-plugins/dsa/assets/vendor/alpine/alpine.min.js') && !exists('wp-content/mu-plugins/dsa/assets/vendor/README.md'));
check('package manifest excludes htmx and Alpine vendor assets', !manifest.includes('"assets/vendor/htmx/htmx.min.js"') && !manifest.includes('"assets/vendor/alpine/alpine.min.js"') && !manifest.includes('"assets/vendor/README.md"'));
check('roadmap records retired hybrid decision', roadmap.includes('htmx/Alpine track is retired') && roadmap.includes('DSA core remains the default authority'));
check('changelog records current retirement', changelog.includes('Retired the htmx/Alpine enhancement pilot'));

const failed = checks.filter(([, passed]) => !passed);
for (const [name, passed] of checks) process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`);
process.stdout.write(`${checks.length - failed.length}/${checks.length} htmx/Alpine retirement contracts passed.\n`);
if (failed.length) process.exit(1);
