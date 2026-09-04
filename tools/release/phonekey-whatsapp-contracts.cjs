const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const core = read('wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
const app = read('services/key-gateway/src/app.mjs');
const compose = read('services/key-gateway/deploy/docker-compose.vps.yml');
const history = read('services/key-gateway/src/history.mjs');
const email = read('wp-content/mu-plugins/dsa/includes/Communications/Email_Service.php');
const channels = read('wp-content/mu-plugins/dsa/includes/Communications/Channel_Service.php');
const preferences = read('wp-content/mu-plugins/dsa/includes/Notifications/Notification_Preference_Service.php');
const carts = read('wp-content/mu-plugins/dsa/includes/Commerce/Abandoned_Cart_Service.php');
const orders = read('wp-content/mu-plugins/dsa/includes/Notifications/Order_Notification_Service.php');
const owners = read('wp-content/mu-plugins/dsa/includes/Notifications/Admin_Event_Notification_Service.php');
const bridge = read('wp-content/mu-plugins/dsa/includes/PhoneKey/PhoneKey_Bridge.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const gatewayPackage = JSON.parse(read('services/key-gateway/package.json'));
const baileys = read('services/key-gateway/src/transports/baileys.mjs');
const checks = [
  ['Key.kiwe signs exact request bodies', core.includes("X-Kiwe-Key-Signature") && core.includes("$timestamp . '.' . $nonce . '.' . $body")],
  ['PhoneKey validates provider status', core.includes('wp_remote_retrieve_response_code') && core.includes('$status >= 200 && $status < 300')],
  ['Signed Key.kiwe mode resolves the canonical hosted gateway safely', core.includes('function pk_default_whatsapp_gateway_url()') && core.includes("'phonekey_gateway' === ( $settings['whatsapp_mode']") && core.includes('https://key.kiwelaunch.com/v1/otp')],
  ['PhoneKey has explicit same-code email fallback', core.includes('pk_send_phone_fallback_email') && core.includes('WhatsApp was unavailable; the code was sent by email.')],
  ['PhoneKey uses Woo billing email as a fallback address', core.includes("get_user_meta( $user_id, 'billing_email', true )")],
  ['Phone-only signup is not silently replaced by email bootstrap', !core.includes("'email_bootstrap_required'") && core.includes("pk_create_user_for_identifier( $identifier, $type )")],
  ['Dual identifier mode is saved and rendered as two fields', core.includes("'email_and_phone'") && surface.includes("identifierMode === 'email_and_phone'") && surface.includes('id="dsa-pk-email"') && surface.includes('id="dsa-pk-phone"')],
  ['Dual identifier flow verifies email before phone', core.includes("$flow_meta['pending_phone'] = pk_encrypt( $pending_phone )") && core.includes("'next' => 'verify_phone'") && surface.includes("response.next === 'verify_phone'")],
  ['provider secret uses Kiwe Secret Store', core.includes('Secret_Store::encrypt') && core.includes('Secret_Store::decrypt')],
  ['gateway enforces HMAC freshness and replay defense', app.includes('verifySignature') && app.includes('expired_request') && app.includes('replayed_request')],
  ['gateway never returns OTP content', !app.includes('code: code') && !app.includes('phone: phone')],
  ['gateway returns deterministic fallback signal', app.includes('fallback: "email"')],
  ['RC history is bounded and encrypts captured content', history.includes('createCipheriv("aes-256-gcm"') && history.includes('slice(-this.maximum)')],
  ['RC history encrypts only explicitly allowed outbound notification content', history.includes('this.captureOutboundText && event.allowContent === true') && history.includes('createCipheriv("aes-256-gcm"')],
  ['WordPress reports fallback outcomes without OTP content', core.includes('pk_report_gateway_event') && app.includes('email_fallback_accepted')],
  ['Kiwe Email exposes a tested fallback readiness signal', email.includes("'fallback_ready'") && email.includes("$last_test['success']")],
  ['Explicit Kiwe SMTP owns final PHPMailer configuration without duplicate hooks', email.includes("private bool $registered = false") && email.includes("if ( $this->registered )") && email.includes("PHP_INT_MAX") && email.includes("$this->register();")],
  ['PhoneKey exposes a signed bounded notification endpoint', core.includes('pk_send_whatsapp_message') && app.includes('url.pathname === "/v1/message"') && app.includes('allowedPurposes')],
  ['Channel service prefers PhoneKey and limits email fallback by consent', channels.includes('notification_ready') && channels.includes("'fallback_email_allowed'") && bridge.includes('pk_send_whatsapp_message')],
  ['Notification preferences include saved-cart consent', preferences.includes("'cart_reminder'") && preferences.includes('user_accepts')],
  ['Abandoned-cart automation can prefer WhatsApp without duplicate sends', carts.includes("'automatic_whatsapp_enabled'") && carts.includes("$channel = 'whatsapp'")],
  ['WooCommerce status changes use opted-in notification channels', orders.includes('woocommerce_order_status_changed') && orders.includes("'order_status'")],
  ['Owner events use selected external channels', owners.includes('deliver_channels') && owners.includes("'admin_new_order'")],
  ['Gateway pins the audited Baileys RC', gatewayPackage.dependencies['@whiskeysockets/baileys'] === '7.0.0-rc14' && baileys.includes('const BAILEYS_VERSION = "7.0.0-rc14"')],
  ['Gateway negotiates WhatsApp protocol and reports bounded diagnostics', baileys.includes('fetchLatestWaWebVersion') && baileys.includes('lastDisconnect') && app.includes('New-device compatibility notice')],
  ['VPS profile pins Evolution and bounded data services', compose.includes('evoapicloud/evolution-api:v2.3.7') && compose.includes('postgres:15-alpine') && compose.includes('redis:7-alpine')],
];
let failed = false;
for (const [label, ok] of checks) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
  if (!ok) failed = true;
}
if (failed) process.exit(1);
