#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const failures = [];

function check(label, condition) {
	if (condition) {
		console.log(`PASS ${label}`);
		return;
	}
	failures.push(label);
	console.error(`FAIL ${label}`);
}

const bootstrap = read('wp-content/mu-plugins/dsa/dsa.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');

const earlyPhoneKey = bootstrap.indexOf('( new \\DSA\\PhoneKey\\PhoneKey_Core_Loader() )->register();');
const packageAudit = bootstrap.indexOf('$dsa_package_proof = \\DSA\\Runtime\\Package_Manifest::verify();');

check('PhoneKey core is loaded before the full package audit', earlyPhoneKey >= 0 && packageAudit >= 0 && earlyPhoneKey < packageAudit);
check('early PhoneKey boot reuses the canonical core loader', bootstrap.includes("is_readable( DSA_DIR . 'includes/PhoneKey/phonekey-core.php' )") && bootstrap.includes("$kiwe_boot_settings['phonekey']['enabled']"));
check('REST nonce refresh uses the universal runtime hydration route', surface.includes("const endpoint = hydrationConfig.endpoint || ( data.restUrl ? data.restUrl + '/runtime/hydrate' : '' );"));
check('REST nonce refresh is not coupled to commerce', !surface.slice(surface.indexOf('function refreshRestNonce'), surface.indexOf('function restJson')).includes("'/cart/nonce'"));
check('PhoneKey endpoints normalize exactly one path slash', surface.includes("replace( /\\/+$/, '' ) + '/' + String( path || '' ).replace( /^\\/+/, '' )"));
check('ordinary 401/403 auth results are not rewritten as route failures', !surface.slice(surface.indexOf('function phoneKeyPost'), surface.indexOf('function identifyPhoneKey')).includes('response.status === 401 || response.status === 403'));
check('a missing PhoneKey route retries only after runtime hydration', surface.includes("response.status === 404 && code === 'rest_no_route'") && surface.includes('return phoneKeyPost( path, payload, true );'));

if (failures.length) {
	console.error(`\nPhoneKey route resilience failed (${failures.length}).`);
	process.exit(1);
}

console.log('\nPhoneKey route resilience verified.');
