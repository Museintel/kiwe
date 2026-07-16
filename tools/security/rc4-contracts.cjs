const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const store = read('wp-content/mu-plugins/dsa/includes/Security/Secret_Store.php');
const phonekey = read('wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
const secure = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
const push = read('wp-content/mu-plugins/dsa/includes/Notifications/Push_Service.php');
const pwa = read('wp-content/mu-plugins/dsa/includes/PWA/PWA_Service.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');

const checks = [
  ['versioned authenticated secret format', store.includes('dsa-secret:v2:sodium:') && store.includes('dsa-secret:v2:gcm:')],
  ['secret writes fail closed', /public static function encrypt[\s\S]*return '';\s*\}/.test(store)],
  ['no shared plaintext-equivalent writer', !store.includes("'b64:'") && !store.includes("'legacy:'")],
  ['decrypt reports key mismatch', store.includes("'status' => 'key_mismatch'")],
  ['previous recovery keys supported', store.includes('DSA_SECRET_STORE_PREVIOUS_KEYS') && store.includes('dsa_secret_store_previous_keys')],
  ['diagnostics expose ID not key material', store.includes("'keyId'") && !/diagnostics\(\)[\s\S]{0,1200}'key'\s*=>\s*self::key\(/.test(store)],
  ['PhoneKey new writes use shared store', phonekey.includes('Secret_Store::encrypt')],
  ['PhoneKey legacy b64 is read-only', phonekey.includes("pk_starts_with( $stored, 'b64:' )") && !phonekey.includes("return 'b64:'")],
  ['SecureTrack new writes use shared store', secure.includes('Secret_Store::encrypt') && !secure.includes("return 'legacy:'")],
  ['Push encrypted inventory complete', ['endpoint', 'p256dh', 'auth_secret'].every((field) => push.includes(`'${field}' => $encrypted`))],
  ['VAPID private key encrypted', push.includes("'private' => $encrypted")],
  ['VAPID rotation marks re-enrollment', push.includes("status='reenroll_required'") && push.includes('vapid_key_rotated')],
  ['Push schema records key ID', push.includes('key_id varchar(32)') && push.includes("'key_id' => (string) $vapid['key_id']")],
  ['Browser replaces mismatched subscription', surface.includes('pushKeyMatches') && surface.includes('subscription.unsubscribe()') && surface.includes('dsa_vapid_key_id')],
  ['Public PWA config exposes only public key identity', pwa.includes("'vapidKeyId'") && /public function public_config\(\): array \{[\s\S]*?'keyId'[\s\S]*?\n\t\}/.test(push) && !/public function public_config\(\): array \{[\s\S]*?'private'/.test(push.split('public function maybe_install')[0])],
  ['Admin output reports recovery counts without secrets', admin.includes('Devices awaiting cryptographic re-enrollment') && admin.includes('Active key ID')],
];

let failed = 0;
for (const [name, ok] of checks) {
  process.stdout.write(`${ok ? 'PASS' : 'FAIL'} ${name}\n`);
  if (!ok) failed++;
}
if (failed) {
  process.stderr.write(`\n${failed} RC4 contract check(s) failed.\n`);
  process.exit(1);
}
process.stdout.write(`\nRC4 crypto/recovery contracts passed (${checks.length}/${checks.length}).\n`);
