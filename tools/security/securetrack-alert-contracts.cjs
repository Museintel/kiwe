const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const core = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
const events = read('wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Event_Service.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-admin-core.php');
const policy = read('wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Settings_Policy.php');

let passed = 0;
const checks = [];
function check(name, condition) {
  checks.push({ name, condition: Boolean(condition) });
  if (condition) passed += 1;
}

const risk = core.slice(core.indexOf('function stp_risk('), core.indexOf('// Site Brain and AI queue'));
const alert = core.slice(core.indexOf('function stp_alert('), core.indexOf('/** Count recent failed logins'));

check('contained protections are capped below red and remain visible as yellow',
  risk.includes("stp_is_containment_event( $type") &&
  risk.includes("$ctx['containment_failed']") &&
  risk.includes("$ctx['bypass_evidence']") &&
  risk.includes("$cfg['red_threshold'] - 1") &&
  risk.includes('Request contained; no administrator action required') &&
  risk.indexOf('$is_containment') < risk.indexOf('$flag  =')
);

check('contained events cannot create generic high-risk alerts',
  events.includes("$is_containment = function_exists( 'stp_is_containment_event' )") &&
  events.includes("elseif ( ! $is_containment && $risk['flag'] === 'red'")
);

check('the duplicate every-red email path is removed',
  !events.includes('Red-Flag Event: {$type}')
);

check('incident disabling is authoritative for every SecureTrack delivery path',
	alert.includes("empty( $config['alert_on_red'] )") &&
	alert.indexOf("empty( $config['alert_on_red'] )") < alert.indexOf("do_action(")
);

check('incident delivery has subject coalescing and an hourly gateway cap',
	alert.includes("'stp_notice_subject_'") &&
	alert.includes("$config['alert_repeat_window_mins']") &&
	alert.includes('$repeat_window * MINUTE_IN_SECONDS') &&
	alert.includes("'stp_notice_hour_'") &&
	alert.includes("$config['alert_hourly_limit']") &&
	alert.includes('if ( $hour_count >= $hourly_limit )') &&
	alert.includes('HOUR_IN_SECONDS')
);

check('settings expose safe notification budgets and explain preserved evidence',
	admin.includes('Generate SecureTrack incident notifications') &&
	admin.includes('name="alert_repeat_window_mins"') &&
	admin.includes('name="alert_hourly_limit"') &&
	admin.includes('Choose recipients and Email, WhatsApp or SMS under WordPress') &&
	admin.includes('complete event ledger always remains in SecureTrack')
);

check('yellow delivery is an explicit normalized opt-in that reuses gateway budgets',
  admin.includes('name="alert_delivery_policy"') &&
  admin.includes('High/critical incidents only (recommended)') &&
  admin.includes('Include yellow informational events') &&
  policy.includes("[ 'actionable', 'yellow_and_actionable' ]") &&
  events.includes("$risk['flag'] === 'yellow'") &&
  events.includes("=== 'yellow_and_actionable'") &&
	events.includes('stp_alert(')
);

check('SecureTrack publishes into the shared Kiwe notification ingress without direct mail',
	alert.includes("'kiwe_notification_event'") &&
	alert.includes("'securetrack_incident'") &&
	!alert.includes('wp_mail(')
);

for (const item of checks) {
  console.log(`${item.condition ? 'PASS' : 'FAIL'} ${item.name}`);
}
console.log(`\n${passed}/${checks.length} SecureTrack alert escalation contracts passed.`);
if (passed !== checks.length) process.exit(1);
