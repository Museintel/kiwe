const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const assets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
const renderer = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Surface_Renderer.php');
const bridge = read('wp-content/mu-plugins/dsa/includes/PhoneKey/PhoneKey_Bridge.php');
const commerce = read('wp-content/mu-plugins/dsa/includes/Commerce/Commerce_Context_Service.php');
const hydration = read('wp-content/mu-plugins/dsa/includes/Rest/Runtime_Hydration_Controller.php');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const bricks = read('wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php');

const checks = [
  ['runtime hydration route is registered', hydration.includes("'/runtime/hydrate'") && plugin.includes('Runtime_Hydration_Controller')],
  ['hydration is same-site only', hydration.includes('Origin_Checker::is_same_site_request()')],
  ['hydration response is private and no-store', hydration.includes("private, no-store, no-cache") && hydration.includes("'Vary', 'Cookie'")],
  ['hydration is excluded from indexing', hydration.includes("'X-Robots-Tag', 'noindex, nofollow'")],
  ['static shell advertises cookie-authenticated private hydration endpoint', assets.includes("admin_url( 'admin-ajax.php?action=dsa_runtime_hydrate' )") && hydration.includes("'wp_ajax_dsa_runtime_hydrate'") && hydration.includes("'wp_ajax_nopriv_dsa_runtime_hydrate'")],
  ['static shell carries no REST nonce', /'nonce'\s*=>\s*''/.test(assets) && !assets.includes("wp_create_nonce( 'wp_rest' )")],
  ['PhoneKey has a neutral boot contract', bridge.includes('public function boot_data(): array') && /public function boot_data\(\): array[\s\S]*?'nonce'\s*=>\s*''/.test(bridge)],
  ['static shell uses neutral PhoneKey data', assets.includes('$this->phonekey->boot_data()') && !assets.includes('$this->phonekey->public_data()')],
  ['static shell uses public commerce data', assets.includes('$this->commerce->public_context()') && !assets.includes('$this->commerce->context( $trust_summary, $protected_flow )')],
  ['public commerce boot has neutral cart and no complements', commerce.includes('public function public_context(): array') && commerce.includes('$this->neutral_cart_context( $available )') && commerce.includes("'complements'        => []")],
  ['static protected flow boot is neutral', assets.includes('$protected_flow = $this->neutral_protected_flow( $settings );') && /private function neutral_protected_flow[\s\S]*?'active'\s*=>\s*false/.test(assets)],
  ['renderer has no identity-dependent branch', !renderer.includes('is_user_logged_in') && !renderer.includes('current_user_can')],
  ['asset shell has no identity-dependent branch', !assets.includes('is_user_logged_in') && !assets.includes('current_user_can')],
  ['static profile and cart badges are neutral', renderer.includes("'profile' => 0") && renderer.includes("'cart'    => 0")],
  ['static Links editor is disabled', assets.includes("'canEdit'         => false")],
  ['boot seed is deterministic', assets.includes("wp_json_encode( DSA_VERSION ),\n\t\t\t'0'")],
  ['client hydration bypasses browser cache', surface.includes("cache: 'no-store'") && surface.includes("credentials: 'same-origin'")],
  ['private runtime state merges only after hydration', surface.includes('Object.assign( phonekey, payload.phonekey )') && surface.includes('Object.assign( commerce, payload.commerce )')],
  ['dock visibility and geometry hydrate together', surface.includes('payload.dock.phonekey_visible === false') && surface.includes("--dsa-dock-item-count")],
  ['admin Links capability hydrates privately', hydration.includes("'links' => $this->links_admin_data") && surface.includes('Object.assign( linksHub, payload.links )')],
  ['Bricks boot config carries no nonce', (bricks.match(/'nonce'\s*=>\s*''/g) || []).length >= 2],
  ['Bricks acquires nonce before first mutation', (bricks.match(/if\(!config\.nonce && !retried\)\{ return refreshNonce\(\)/g) || []).length >= 2],
];

let failed = 0;
for (const [name, ok] of checks) {
  process.stdout.write(`${ok ? 'PASS' : 'FAIL'} ${name}\n`);
  if (!ok) failed++;
}
if (failed) {
  process.stderr.write(`\n${failed} RC5 cache contract check(s) failed.\n`);
  process.exit(1);
}
process.stdout.write(`\nRC5 cache-safe boot contracts passed (${checks.length}/${checks.length}).\n`);
