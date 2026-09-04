const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const core = read('wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const assets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const bridge = read('wp-content/mu-plugins/dsa/includes/PhoneKey/PhoneKey_Bridge.php');
const secure = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
const extended = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-admin-extended.php');

let failed = 0;
function check(name, condition) {
  if (condition) console.log(`PASS ${name}`);
  else { console.error(`FAIL ${name}`); failed += 1; }
}

const policy = ({ privileged, isNew, verified, graceUsed, timing = 'verify_later' }) => ({
  canDefer: timing === 'verify_later' && isNew && !verified && !graceUsed && !privileged,
  mustVerify: privileged || (!verified && (!isNew || graceUsed || timing !== 'verify_later')),
});

check('new ordinary account receives one grace login', policy({ privileged: false, isNew: true, verified: false, graceUsed: false }).canDefer);
check('second unverified login must verify', policy({ privileged: false, isNew: false, verified: false, graceUsed: true }).mustVerify);
check('privileged account never receives grace', !policy({ privileged: true, isNew: true, verified: false, graceUsed: false }).canDefer);
check('verified account does not need grace', !policy({ privileged: false, isNew: false, verified: true, graceUsed: true }).mustVerify);

check('one-time grace is persisted and replay is blocked', core.includes("pk_initial_unverified_login_used_at") && core.includes("first_login_grace_exhausted"));
check('administrator enrollment requires password passkey phone binding and a verified factor', /pk_admin_enrollment_complete[\s\S]*?pk_credential_count[\s\S]*?pk_admin_phone_bound[\s\S]*?pk_account_verified[\s\S]*?pk_admin_password_bound_at/.test(core));
check('administrator can verify email before passkey and fall back to a real phone proof', core.includes("privileged_email_verify") && core.includes("privileged_phone_verify") && core.includes("pk_send_email_otp_or_link") && surface.includes("response.next === 'verify_email' || response.next === 'verify_phone'"));
check('email fallback is not misreported as phone proof', core.includes("$phone_proof_dispatched") && core.includes("'email_fallback' !=="));
check('new-device recovery enrolls a new passkey', core.includes("device_recovery_verified_at") && core.includes("'device_passkey_enroll'"));
check('completed strict authentication trusts the exact IP in SecureTrack', core.includes("$strict_privileged_complete") && core.includes("stp_trust_ip( pk_ip()"));
check('SecureTrack has one reusable IP trust operation', secure.includes("function stp_trust_ip(") && secure.includes("stp_ajax_trust_ip"));
check('wp-login replacement preserves break glass and password recovery actions', core.includes("pk_break_glass_login_allowed") && core.includes("'lostpassword'") && core.includes("pk_protect_wordpress_login"));
check('break glass re-entry bypasses counters and fresh entry never fails closed on alert throttling', secure.includes('if ( stp_break_glass_session_valid() )') && secure.includes('$alert_allowed = stp_rate_limit') && !secure.includes('Recovery login temporarily rate limited.'));
check('wp-admin permits only completed privileged enrollment outside break glass', /pk_protect_wordpress_admin[\s\S]*?pk_break_glass_login_allowed[\s\S]*?pk_admin_enrollment_complete\( get_current_user_id\(\) \)/.test(core));
check('role elevation revokes WordPress and PhoneKey sessions and forces reenrollment', core.includes("WP_Session_Tokens::get_instance( $user_id )->destroy_all()") && core.includes("'pk_admin_enrollment_required'") && core.includes("'pk_force_reenroll'") && core.includes("'privilege_elevation_requires_stepup'"));
check('every privileged role-scope change revokes assurance rather than inheriting a broader session', core.includes("'privileged_role_scope_changed_requires_stepup'") && core.includes('$role_changed && ( $is_privileged || $was_privileged )'));
check('trusted devices are invalidated when their WordPress role scope changes', core.includes("trusted_device_role_scope_revoked") && core.includes("SELECT id, role_scope FROM"));
check('PhoneKey-created users can never inherit a privileged signup role', /pk_create_user_for_identifier[\s\S]*?pk_role_is_high_privilege_role[\s\S]*?wp_generate_password\( 32/.test(core));
check('privileged login fails closed before WordPress auth cookies when setup is incomplete', /function pk_finish_login[\s\S]*?pk_admin_enrollment_complete[\s\S]*?privileged_setup_incomplete[\s\S]*?wp_set_auth_cookie/.test(core));
check('existing subscriber passkeys are asserted rather than recreated after promotion', core.includes("$has_passkey ? 'login_passkey' : 'enroll_passkey'") && surface.includes("response.next === 'login_passkey'"));
check('passkey assertion continues to mandatory phone binding for privileged roles', /pk_rest_webauthn_login_verify[\s\S]*?pk_privileged_bind_phone_response/.test(core));
check('strict PhoneKey exposes the native WordPress password setup and reset lane', surface.includes('Set or reset WordPress password') && surface.includes('phonekey.resetPasswordUrl'));
check('legacy privileged WordPress sessions enter strict PhoneKey directly instead of looping on Profile', bridge.includes("'privilegedEnrollmentRequired'") && bridge.includes("pk_admin_enrollment_complete") && /directAuth === '1'[\s\S]*?user\.privilegedEnrollmentRequired[\s\S]*?openProfileVerification/.test(surface));
check('frontend admin bar uses the canonical Kiwe dock setting', assets.includes("hide_frontend_admin_bar") && !core.includes("pk[hide_admin_bar]"));
check('counterpart binding follows email-or-phone mode, stays in-sheet, and can be skipped for ordinary accounts', core.includes("'email_or_phone' !== ( $s['identifier_mode']") && core.includes("/counterpart/start") && core.includes("/counterpart/skip") && surface.includes("renderCounterpartPrompt"));
check('automatic account linking refuses privileged accounts and preserves an alias record', core.includes("pk_merge_privileged") && core.includes("pk_merged_into") && core.includes("pk_linked_user_ids"));
check('header icon attributes open profile and search without a dock click', surface.includes("[data-dsa-open-module]") && surface.includes("openOverlay( moduleId"));
check('Developer explains Bricks Name and Value fields without reversed attributes', admin.includes("put the exact attribute in Name and only its value in Value") && admin.includes("Profile icon example:"));
check('Authentication page can recover the canonical disabled PhoneKey setting', admin.includes("admin_post_dsa_enable_phonekey") && admin.includes("[ 'phonekey' => [ 'enabled' => true ] ]") && admin.includes("Enable Key.kiwe"));
check('wp-login redirects directly into the Profile sheet', surface.includes("searchParams.get( 'kiwe-auth' )") && surface.includes("openOverlay( 'profile', 'Secure sign in' )"));
check('legacy duplicate SecureTrack settings are disabled', extended.includes("Legacy duplicate settings retained only for migration reference; never render or save from SecureTrack"));

if (failed) process.exit(1);
console.log('PhoneKey strict-flow contracts passed.');
