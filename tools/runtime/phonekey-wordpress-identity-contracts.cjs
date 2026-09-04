const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const core = read('wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const accountController = read('wp-content/mu-plugins/dsa/includes/Rest/Account_Controller.php');

let failed = 0;
function check(name, condition) {
	if (condition) console.log(`PASS ${name}`);
	else { console.error(`FAIL ${name}`); failed += 1; }
}

const resolvePendingEmail = ({ pendingOwner, privileged }) => {
	if (!pendingOwner) return 'normal_lookup';
	return privileged ? 'strict_existing_owner' : 'stop_without_registration';
};

check('ordinary pending WordPress email never creates a second account', resolvePendingEmail({ pendingOwner: 21, privileged: false }) === 'stop_without_registration');
check('privileged pending WordPress email resolves to the original strict account', resolvePendingEmail({ pendingOwner: 7, privileged: true }) === 'strict_existing_owner');
check('unreserved email continues through canonical lookup', resolvePendingEmail({ pendingOwner: 0, privileged: false }) === 'normal_lookup');
check('pending core email is resolved from _new_email and compared after normalization', core.includes('function pk_pending_wordpress_email_owner') && core.includes("get_user_meta( $candidate_id, '_new_email'") && core.includes("$pending['newemail']"));
check('pending privileged email resolution happens before PhoneKey account creation', /pk_pending_wordpress_email_owner[\s\S]*?pk_is_privileged\( \$pending_wordpress_owner \)[\s\S]*?pk_create_user_for_identifier/.test(core));
check('ordinary pending email returns a conflict before registration', core.includes("'wordpress_email_change_pending'") && core.indexOf("'wordpress_email_change_pending'") < core.indexOf('pk_create_user_for_identifier( $identifier, $type )', core.indexOf('function pk_rest_identify')));
check('PhoneKey-created accounts carry durable provenance', core.includes("'pk_created_by_phonekey'"));
check('WordPress profile email confirmation synchronizes PhoneKey factors and revokes remembered trust', core.includes("add_action( 'profile_update', 'pk_sync_wordpress_profile_email_change'") && core.includes("'wordpress_account_email_changed'") && core.includes("pk_t( 'trusted_devices' )"));
check('WordPress password reset revokes remembered trust and requires privileged reenrollment', core.includes("add_action( 'after_password_reset', 'pk_sync_wordpress_password_reset'") && core.includes("'pk_force_reenroll'") && core.includes("'wordpress_password_reset'"));
check('wp-login preserves an exact same-origin return in a signed HttpOnly cookie', core.includes('function pk_remember_login_return') && core.includes("hash_hmac( 'sha256'") && core.includes('wp_validate_redirect') && core.includes("'httponly' => true"));
check('successful PhoneKey login consumes the WordPress return path before default routing', /function pk_finish_login[\s\S]*?pk_consume_login_return\(\)[\s\S]*?pk_redirect_url\(\)/.test(core));
check('stale REST no-route failures refresh runtime once instead of trapping the user', surface.includes("response.status === 404 && code === 'rest_no_route'") && surface.includes('phoneKeyPost( path, payload, true )'));
check('profile password reset only reports success after WordPress accepts the email', accountController.includes('$mail_accepted = wp_mail') && accountController.includes('delete_transient( $rate_key );') && accountController.includes("'ok'      => false"));

if (failed) process.exit(1);
console.log('PhoneKey WordPress identity-continuity contracts passed.');
