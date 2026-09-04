#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const service = read('wp-content/mu-plugins/dsa/includes/Runtime/Update_Service.php');
const loader = read('wp-content/mu-plugins/dsa.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const panels = read('wp-content/mu-plugins/dsa/assets/js/modules/surface-panels.js');
const schema = read('wp-content/mu-plugins/dsa/includes/Theme/Screen_Copy_Schema.php');
const menuRenderers = panels.slice(panels.indexOf('function renderLegacyMenu'), panels.indexOf('const menuAdapters'));
const failures = [];
const check = (label, pass) => pass ? console.log(`PASS ${label}`) : failures.push(label);

check('menu title is not hardcoded marketing copy', !panels.includes("title: 'Move around faster.'"));
check('admin menu title owns first navigation section', panels.includes('menuGroupsWithSectionTitle') && panels.includes('normalized[ 0 ].label = title'));
check('menu hero does not duplicate the navigation title', !menuRenderers.includes("<h2>' + escapeHtml( copy.title )"));
check('menu setting describes navigation-section ownership', schema.includes("Navigation section title"));
check('update feed is fixed to trusted HTTPS origin', service.includes("https://app.kiwelaunch.com/updates/kiwe/v1/") && service.includes('is_trusted_release_url') && !service.includes("https://start.kiwelaunch.com/updates/kiwe/v1/"));
check('release metadata uses Ed25519 verification', service.includes('sodium_crypto_sign_verify_detached') && service.includes('PUBLIC_KEY_B64'));
check('archive and inner manifest are verified before swapping', service.includes("hash_file( 'sha256', $download )") && service.includes('verify_package_root') && service.includes('packageManifestSha256'));
check('update uses staged swap, lock, and transaction', service.includes('acquire_lock') && service.includes('swap_package') && service.includes('awaiting_boot'));
check('root loader recovers interrupted or failed update', loader.includes('kiwe_mu_rollback_update') && loader.includes("'installing'") && loader.includes("'awaiting_boot'"));
check('Developer exposes manual and automatic controls', admin.includes('Signed Kiwe updates') && admin.includes('dsa_check_updates') && admin.includes('dsa_install_update') && admin.includes('kiwe_updates[automatic]'));

if (failures.length) {
	console.error(`Kiwe update contracts failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('Kiwe update contracts verified.');
