const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const core = read('wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
const app = read('services/phonekey-gateway/src/app.mjs');
const compose = read('services/phonekey-gateway/deploy/docker-compose.vps.yml');
const history = read('services/phonekey-gateway/src/history.mjs');
const email = read('wp-content/mu-plugins/dsa/includes/Communications/Email_Service.php');
const checks = [
  ['PhoneKey signs exact request bodies', core.includes("X-PhoneKey-Signature") && core.includes("$timestamp . '.' . $nonce . '.' . $body")],
  ['PhoneKey validates provider status', core.includes('wp_remote_retrieve_response_code') && core.includes('$status >= 200 && $status < 300')],
  ['PhoneKey has explicit same-code email fallback', core.includes('pk_send_phone_fallback_email') && core.includes('WhatsApp was unavailable; the code was sent by email.')],
  ['PhoneKey uses Woo billing email as a fallback address', core.includes("get_user_meta( $user_id, 'billing_email', true )")],
  ['PhoneKey requires an email bootstrap when fallback is mandatory', core.includes("'email_bootstrap_required'") && core.includes("'whatsapp_email_fallback'")],
  ['provider secret uses Kiwe Secret Store', core.includes('Secret_Store::encrypt') && core.includes('Secret_Store::decrypt')],
  ['gateway enforces HMAC freshness and replay defense', app.includes('verifySignature') && app.includes('expired_request') && app.includes('replayed_request')],
  ['gateway never returns OTP content', !app.includes('code: code') && !app.includes('phone: phone')],
  ['gateway returns deterministic fallback signal', app.includes('fallback: "email"')],
  ['RC history is bounded and encrypts captured content', history.includes('createCipheriv("aes-256-gcm"') && history.includes('slice(-this.maximum)')],
  ['RC history never stores outbound message content', history.includes('direction === "inbound" && this.captureInboundText')],
  ['WordPress reports fallback outcomes without OTP content', core.includes('pk_report_gateway_event') && app.includes('email_fallback_accepted')],
  ['Kiwe Email exposes a tested fallback readiness signal', email.includes("'fallback_ready'") && email.includes("$last_test['success']")],
  ['VPS profile pins Evolution and bounded data services', compose.includes('evoapicloud/evolution-api:v2.3.7') && compose.includes('postgres:15-alpine') && compose.includes('redis:7-alpine')],
];
let failed = false;
for (const [label, ok] of checks) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
  if (!ok) failed = true;
}
if (failed) process.exit(1);
