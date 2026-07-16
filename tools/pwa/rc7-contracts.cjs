const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const push = read('wp-content/mu-plugins/dsa/includes/Notifications/Push_Service.php');
const controller = read('wp-content/mu-plugins/dsa/includes/Rest/Push_Controller.php');
const pwa = read('wp-content/mu-plugins/dsa/includes/PWA/PWA_Service.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');

const checks = [
  ['push schema carries renewal capability hash', push.includes("private const DB_VERSION = '4'") && push.includes('renewal_hash char(64)')],
  ['renewal capability is random and stored one-way', push.includes('wp_generate_password( 48') && push.includes("hash_hmac( 'sha256', $token")],
  ['renewal binds token to old endpoint', push.includes("WHERE endpoint_hash=%s LIMIT 1") && push.includes("hash_equals( (string) $row['renewal_hash']")],
  ['renewal preserves original owner', push.includes("$data['visitor_hash'] = (string) $row['visitor_hash']") && push.includes("$data['user_id'] = absint( $row['user_id'] )")],
  ['renewal token rotates after use', push.includes("$data['renewal_hash'] = $this->renewal_hash( $next_token )")],
  ['push controller preserves renewal authorization status', controller.includes("$result['status']")],
  ['page persists renewal token and issuing endpoint', surface.includes('dsa_push_renewal_token') && surface.includes('dsa_push_renewal_endpoint')],
  ['worker stores scoped renewal metadata', pwa.includes('KIWE_PUSH_RENEWAL') && pwa.includes('kiwe-push-meta-v1')],
  ['worker renewal sends old endpoint and rotating token', pwa.includes('oldEndpoint: renewal.endpoint') && pwa.includes('renewalToken: renewal.token')],
  ['unsubscribe clears renewal capability', surface.includes('KIWE_PUSH_RENEWAL_CLEAR') && pwa.includes("event.data.type === 'KIWE_PUSH_RENEWAL_CLEAR'")],
  ['worker no longer caches arbitrary page images', !pwa.includes("request.destination === 'image'")],
  ['editorial refresh honors Save-Data', pwa.includes('saveDataRequested(request)') && pwa.includes("headers.get('Save-Data') === 'on'")],
  ['editorial refresh has deterministic freshness window', pwa.includes('editorialFreshSeconds') && pwa.includes('X-Kiwe-Cached-At')],
  ['editorial contract remains private-policy aware', pwa.includes("policy !== 'public-editorial-v1'") && pwa.includes('/(?:private|no-store)/i')],
  ['network-only routes exclude transactional and REST paths', ['wp-admin','wp-json','wp-login','cart','checkout','my-account','order-pay','order-received','wc-api'].every((part) => pwa.includes(part))],
  ['app assets use pathname and version matching', pwa.includes('candidate.pathname !== expected.pathname') && pwa.includes("candidate.searchParams.get('ver') === version")],
  ['editorial caches retain bounded entry counts', pwa.includes('trimCache(KIWE_PWA.editorialCache, 30)') && pwa.includes('trimCache(KIWE_PWA.mediaCache, 40)')],
  ['notification clicks cannot navigate cross-origin', pwa.includes('new URL(target).origin !== self.location.origin')],
  ['push campaign batches have an execution lock', push.includes('acquire_job_lock') && push.includes('release_job_lock') && push.includes('finally')],
  ['stale push jobs are cleaned', push.includes("time() - 2 * DAY_IN_SECONDS") && push.includes("delete_option( 'dsa_push_job_'")],
  ['vendor expiry is immediate for 404 and 410', push.includes("in_array( $code, [ 404, 410 ], true )")],
  ['OpenSSL P-256 and cron diagnostics remain exposed', push.includes("'p256'") && push.includes("'cronScheduled'") && push.includes("'wpCronDisabled'")],
];

// Parse the generated worker body independently of PHP interpolation.
const start = pwa.indexOf('const KIWE_PWA = ');
const end = pwa.indexOf('\n\t\t<?php', start);
let workerParses = false;
if (start >= 0 && end > start) {
  const worker = pwa.slice(start, end).replace(/^const KIWE_PWA = .*;$/m, 'const KIWE_PWA = {};');
  try { new Function(worker); workerParses = true; } catch (error) { process.stderr.write(`Worker parse error: ${error.message}\n`); }
}
checks.push(['generated service-worker body parses', workerParses]);

let failed = 0;
for (const [name, ok] of checks) {
  process.stdout.write(`${ok ? 'PASS' : 'FAIL'} ${name}\n`);
  if (!ok) failed++;
}
if (failed) {
  process.stderr.write(`\n${failed} RC7 PWA/Push contract check(s) failed.\n`);
  process.exit(1);
}
process.stdout.write(`\nRC7 PWA/Push/worker contracts passed (${checks.length}/${checks.length}).\n`);
