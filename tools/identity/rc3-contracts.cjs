const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const phonekey = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php'), 'utf8');
const account = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/includes/Rest/Account_Controller.php'), 'utf8');
const surface = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/js/surface.js'), 'utf8');

const checks = [
  ['profile email remains pending', !/\$update\[['"]user_email['"]\]/.test(account)],
  ['new email ownership is verified', phonekey.includes("'account_email_change'") && phonekey.includes("'otp_hash'")],
  ['pending email is encrypted', phonekey.includes("'requested_email' => pk_encrypt( $email )")],
  ['email token is consumed atomically', phonekey.includes('WHERE id=%d AND used=0') && phonekey.includes('1 !== $consumed')],
  ['stale email request is rejected', phonekey.includes("'previous_hash'") && phonekey.includes("pk_email_change_stale")],
  ['admin email requires password step-up', account.includes('pk_is_privileged') && account.includes('wp_check_password')],
  ['anchor rotation revokes remembered trust', phonekey.includes("'account_email_changed'") && phonekey.includes("pk_t( 'trusted_devices' )")],
  ['anchor rotation preserves credentials', !/account_email_changed[\s\S]{0,800}DELETE FROM[^;]+credentials/.test(phonekey)],
  ['new device requires OTP then enrollment', phonekey.includes("$mode = 'new_device_verify'") && phonekey.includes("'next' => 'enroll_passkey'")],
  ['multiple passkeys remain supported', phonekey.includes('pk_credentials_for_user( $user_id )') && phonekey.includes("'excludeCredentials' => $exclude")],
  ['WebAuthn binds challenge', phonekey.includes("throw new Exception( 'Challenge mismatch' )")],
  ['WebAuthn binds origin', phonekey.includes("throw new Exception( 'Origin mismatch' )")],
  ['WebAuthn binds RP ID', phonekey.includes("throw new Exception( 'RP hash mismatch' )")],
  ['WebAuthn rejects counter replay', phonekey.includes("throw new Exception( 'Authenticator counter replay' )")],
  ['privilege elevation and revocation clear assurance', phonekey.includes('privilege_elevation_requires_stepup') && phonekey.includes('privilege_revocation_cleared_assurance')],
  ['PhoneKey account mutations carry shared proof', phonekey.includes("'X-Kiwe-Mutation':'1'")],
  ['profile UI completes pending email', surface.includes('/account/email-change/verify') && surface.includes('confirmProfileEmail')],
];

let failed = 0;
for (const [name, ok] of checks) {
  process.stdout.write(`${ok ? 'PASS' : 'FAIL'} ${name}\n`);
  if (!ok) failed++;
}
if (failed) {
  process.stderr.write(`\n${failed} RC3 contract check(s) failed.\n`);
  process.exit(1);
}
process.stdout.write(`\nRC3 identity/recovery contracts passed (${checks.length}/${checks.length}).\n`);
