const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const guard = read('wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Runtime_Guard.php');
const core = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
const adminCore = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-admin-core.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');

let passed = 0;
const checks = [];
function check(name, condition) {
  checks.push({ name, condition: Boolean(condition) });
  if (condition) passed += 1;
}

const limiter = guard.slice(
  guard.indexOf('public static function endpoint_rate_limit_guard'),
  guard.indexOf('private static function is_frontend_pageview')
);
check('pre-auth hard limiter is restricted to XML-RPC',
  limiter.includes("apply_filters( 'stp_pre_auth_hard_limited_endpoint_types', [ 'xmlrpc' ] )") &&
  limiter.indexOf("if ( ! in_array( $type, $hard_limited_types, true ) )") < limiter.indexOf('stp_rate_limit(')
);
check('interactive login and admin surfaces bypass the early generic limiter',
  limiter.indexOf('$type = self::endpoint_type()') >= 0 &&
  limiter.indexOf("if ( ! in_array( $type, $hard_limited_types, true ) )") < limiter.indexOf('if ( is_user_logged_in() )')
);
check('settings expose only the XML-RPC early hard limit',
  adminCore.includes('<th scope="row">XML-RPC Rate Limit</th>') &&
  !adminCore.includes('name="rl_login_per_min"') &&
  !adminCore.includes('name="rl_rest_per_min"') &&
  !adminCore.includes('name="rl_admin_per_min"') &&
  !adminCore.includes('name="rl_frontend_per_min"')
);
check('trusted IPs bypass the generic endpoint limiter',
  limiter.includes('stp_ip_status_is_trusted( $ip )') &&
  limiter.indexOf('stp_ip_status_is_trusted( $ip )') < limiter.indexOf('stp_rate_limit(')
);
check('trust action resolves/upserts an IP and verifies persistence',
  core.includes('function stp_ajax_trust_ip()') &&
  core.includes('$row = stp_upsert_ip( $ip, false );') &&
  core.includes("'status' => 'trusted'") &&
  core.includes('false === $updated')
);
const adminCheck = core.slice(core.indexOf('function stp_check()'), core.indexOf('function stp_endpoint_type()'));
check('administrator mutations are never trapped by the live-feed limiter',
  adminCheck.includes("if ( 'stp_live_feed' !== $action ) return;") &&
  adminCheck.indexOf("if ( 'stp_live_feed' !== $action ) return;") < adminCheck.indexOf('stp_rate_limit(')
);
check('trust UI handles transport failures and reloads persisted state',
  adminCore.includes('}).fail(function(xhr){') &&
  adminCore.includes('SecureTrack could not trust this IP:') &&
  adminCore.includes('window.setTimeout(function(){ location.reload(); },250);')
);
check('idle logout has one Kiwe-owned UI authority',
  adminCore.includes('Idle auto-logout is managed by Kiwe Secure above.') &&
  !adminCore.includes('name="idle_timeout_mins"') &&
  !adminCore.includes('name="idle_timeout_roles[]"')
);
check('Kiwe controls render only when SecureTrack Settings is active',
  admin.includes("if ( ! $loaded || 'settings' === $active )") &&
  admin.indexOf('nav-tab-wrapper') < admin.indexOf("if ( ! $loaded || 'settings' === $active )")
);

for (const item of checks) {
  console.log(`${item.condition ? 'PASS' : 'FAIL'} ${item.name}`);
}
console.log(`\n${passed}/${checks.length} SecureTrack lockout contracts passed.`);
if (passed !== checks.length) process.exit(1);
