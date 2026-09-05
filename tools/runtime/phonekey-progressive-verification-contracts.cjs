#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const core = read('wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
const bridge = read('wp-content/mu-plugins/dsa/includes/PhoneKey/PhoneKey_Bridge.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const profile = read('wp-content/mu-plugins/dsa/assets/js/modules/profile-panel.js');
const failures = [];

function check(label, condition) {
	if (condition) {
		console.log(`PASS ${label}`);
		return;
	}
	failures.push(label);
	console.error(`FAIL ${label}`);
}

const state = ({ email = false, phone = false, passkey = false }) =>
	email && phone && passkey ? 'verified' : (email || phone ? 'partial' : 'unverified');

check('one verified contact is a stable partial state', state({ email: true }) === 'partial');
check('email phone and passkey are all required for full verification', state({ email: true, phone: true, passkey: true }) === 'verified');
check('server exposes distinct identity and security completion contracts', core.includes('function pk_security_completion') && core.includes("'identityVerified'") && core.includes("'passkeyEnrolled'"));
check('existing accounts without a passkey must prove a factor before enrollment', core.includes("elseif ( $verified && ! $is_new && $creds < 1 ) $mode = 'verify_required';"));
check('verified ordinary users may defer remaining setup only after current proof or an authenticated session', core.includes('$verified_in_flow || $authenticated_user') && core.includes("'security_setup_deferred'"));
check('privileged users still cannot defer setup', /pk_rest_continue_later[\s\S]*?pk_is_privileged[\s\S]*?must complete secure setup/.test(core));
check('verified factor flows offer the missing counterpart before passkey enrollment', core.includes('pk_next_security_setup_response') && core.includes("'counterpartRequired'") && core.includes("'identity_verified_this_flow'"));
check('profile completion intent survives passkey proof and reopens a previously deferred counterpart', /pk_rest_webauthn_login_options[\s\S]*?'completion_intent'[\s\S]*?pk_rest_webauthn_login_verify[\s\S]*?pk_next_security_setup_response/.test(core));
check('public profile distinguishes partial from full verification', bridge.includes("'verificationState'") && profile.includes('Partially verified &middot; finish setup'));
check('authenticated profile data exposes the canonical PhoneKey phone factor', bridge.includes("function_exists( 'pk_user_phone' )") && bridge.includes("'phone'           => $phone"));
check('inline profile verification is authenticated and bound to the current WordPress user',
	core.includes("'/account/factor/start'")
	&& core.includes('function pk_rest_account_factor_start')
	&& /pk_rest_account_factor_start[\s\S]*?get_current_user_id\(\)/.test(core)
	&& /pk_rest_account_factor_start[\s\S]*?Save and confirm this email as your WordPress account email/.test(core)
);
check('inline profile verification reuses one OTP issuer and never creates or merges users',
	core.includes('function pk_start_factor_otp')
	&& /pk_rest_account_factor_start[\s\S]*?pk_start_factor_otp/.test(core)
	&& /pk_rest_counterpart_start[\s\S]*?pk_start_factor_otp/.test(core)
	&& !/function pk_rest_account_factor_start[\s\S]*?pk_create_user_for_identifier/.test(core)
	&& !/function pk_rest_account_factor_start[\s\S]*?pk_merge_counterpart_accounts/.test(core)
);
check('privileged profile factor actions remain behind strict WordPress password setup',
	/function pk_rest_account_factor_start[\s\S]*?pk_is_privileged[\s\S]*?privileged_setup_required/.test(core)
	&& /startProfileFactorVerification[\s\S]*?privilegedEnrollmentRequired[\s\S]*?openProfileVerification/.test(surface)
);
check('profile factor buttons transition into the server-selected OTP and completion journey',
	profile.includes('data-dsa-profile-factor-verify')
	&& surface.includes("phoneKeyPost( 'account/factor/start'")
	&& surface.includes("reason: 'profile_factor_verification'")
	&& /startProfileFactorVerification[\s\S]*?renderVerify\( response \)/.test(surface)
	&& core.includes("'completion_intent' => 1")
);
check('PhoneKey close remains enabled during network work and closes immediately', surface.includes("button:not([data-dsa-pk-close]), input") && surface.includes("closeOverlay( false, { immediate: true } )"));
check('OTP verification route is server-authoritative', core.includes("'/verify-code'") && core.includes('function pk_flow_verification_type') && core.includes('function pk_rest_verify_code') && core.includes("return pk_rest_verify_phone( $r )") && core.includes("return pk_rest_verify_email( $r )"));
check('browser no longer chooses an email or phone verification endpoint', surface.includes("phoneKeyPost( 'verify-code'") && !/function verifyCode[\s\S]*?phoneKeyPost\( isPhone \? 'verify-phone' : 'verify-email'/.test(surface));
check('counterpart UI preserves the server-requested factor',
	surface.includes("phonekeyState.verificationTarget = type")
	&& surface.includes("const verificationResponse = { identifierType: type, emailDelivery: 'otp'")
	&& surface.includes('renderVerify( verificationResponse );')
);
check('OTP issuance responses publish a resend cooldown', (core.match(/'resendAfter'/g) || []).length >= 7);
check('resend cooldown is visible and survives busy-state release', surface.includes("'Resend code in ' + remaining + 's'") && surface.includes('control.disabled = busy || coolingDown') && surface.includes('syncOtpResendCountdown'));
check('initial OTP delivery transitions to the code screen before a slow provider returns',
	core.includes("$defer_otp_delivery = (bool) $r->get_param( 'deferOtpDelivery' )")
	&& core.includes("'deliveryPending' => $delivery_pending")
	&& surface.includes("deferOtpDelivery: true")
	&& surface.includes('function beginDeferredOtpDelivery')
	&& surface.includes("phoneKeyPost( 'resend-otp', { token: requestToken } )")
	&& surface.includes('Preparing and sending your code securely')
);
check('OTP controls stay unavailable until the delivery provider accepts the code',
	surface.includes("deliveryPending ? ' disabled' : ''")
	&& surface.includes("deliveryPending ? 'Sending code&hellip;'")
	&& surface.includes('phonekeyState.deliveryPending = false;')
);
check('resend channel is also selected from flow metadata', /function pk_rest_resend_otp[\s\S]*?pk_flow_verification_type\( \$meta \)/.test(core));
check('server enforces the cooldown before consuming the broader resend allowance', /function pk_rest_resend_otp[\s\S]*?pk_otp_resend_after[\s\S]*?Please wait before requesting another code[\s\S]*?pk_rate_limit\( 'otp_resend\|'/.test(core));
check('new-device OTP copy names a masked server-selected destination',
	surface.includes('function maskedVerificationDestination')
	&& surface.includes("'your email at '")
	&& surface.includes("phoneHint || 'your phone number'")
	&& surface.includes("sent to ' + escapeHtml( verificationDestination )")
);
check('standalone app accepts a logged-in partially verified identity', surface.includes('currentUser.identityVerified === false'));
check('deferred setup no longer waits through a long success delay', surface.includes('}, 150 );') && !surface.includes('}, 650 );'));

if (failures.length) {
	console.error(`\nPhoneKey progressive verification failed (${failures.length}).`);
	process.exit(1);
}

console.log('\nPhoneKey progressive verification contracts passed.');
