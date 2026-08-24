import QRCode from "qrcode";
import { ExpiringSet, SlidingLimiter, normalizePhone, opaque, verifySignature } from "./security.mjs";

const json = (response, status, payload, headers = {}) => {
  const body = JSON.stringify(payload);
  response.writeHead(status, { "content-type": "application/json; charset=utf-8", "cache-control": "no-store", ...headers });
  response.end(body);
};

const text = (response, status, body, type = "text/plain; charset=utf-8", allowSameOriginForm = false) => {
  response.writeHead(status, {
    "content-type": type,
    "cache-control": "no-store",
    "content-security-policy": `default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action ${allowSameOriginForm ? "'self'" : "'none'"}`,
    "x-content-type-options": "nosniff",
    "x-frame-options": "DENY",
    "permissions-policy": "camera=(), microphone=(), geolocation=(), payment=(), usb=()",
    "referrer-policy": "no-referrer",
  });
  response.end(body);
};

const escapeHtml = (value) => String(value ?? "")
  .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
  .replace(/"/g, "&quot;").replace(/'/g, "&#039;");

async function body(request, maximum = 8192) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > maximum) throw Object.assign(new Error("Request is too large."), { status: 413 });
    chunks.push(chunk);
  }
  return Buffer.concat(chunks).toString("utf8");
}

function message(site, code) {
  return `${site}: Your PhoneKey verification code is ${code}. It expires in 10 minutes. Never share this code.`;
}

export function createApp(config, transport, clock = () => Date.now(), history = null, operator = {}) {
  const nonces = new ExpiringSet(10000);
  const idempotency = new ExpiringSet(10000);
  const tenantLimiter = new SlidingLimiter(120, 60 * 60 * 1000);
  const targetLimiter = new SlidingLimiter(6, 60 * 60 * 1000);
  const messageTenantLimiter = new SlidingLimiter(500, 60 * 60 * 1000);
  const messageTargetLimiter = new SlidingLimiter(60, 24 * 60 * 60 * 1000);
  const operatorSelfTestLimiter = new SlidingLimiter(3, 60 * 60 * 1000);
  const operatorResetLimiter = new SlidingLimiter(3, 60 * 60 * 1000);
  const record = (event) => history?.record(event).catch(() => {});

  function authenticate(request, raw) {
    const keyId = String(request.headers["x-phonekey-key-id"] || "");
    const timestamp = String(request.headers["x-phonekey-timestamp"] || "");
    const nonce = String(request.headers["x-phonekey-nonce"] || "");
    const supplied = String(request.headers["x-phonekey-signature"] || "");
    const tenant = config.tenants[keyId];
    const now = clock();
    if (!tenant || !/^\d{10,13}$/.test(timestamp) || !/^[a-zA-Z0-9_-]{16,96}$/.test(nonce)) throw Object.assign(new Error("unauthorized"), { status: 401 });
    const timestampMs = timestamp.length === 10 ? Number(timestamp) * 1000 : Number(timestamp);
    if (Math.abs(now - timestampMs) > config.requestWindowSeconds * 1000) throw Object.assign(new Error("expired_request"), { status: 401 });
    if (nonces.has(`${keyId}:${nonce}`, now)) throw Object.assign(new Error("replayed_request"), { status: 409 });
    if (!verifySignature(tenant.secret, timestamp, nonce, raw, supplied)) throw Object.assign(new Error("unauthorized"), { status: 401 });
    nonces.add(`${keyId}:${nonce}`, config.requestWindowSeconds * 2000, now);
    return { keyId, tenant, now };
  }

  return async function app(request, response) {
    const url = new URL(request.url || "/", "http://phonekey.local");
    if (request.method === "GET" && url.pathname === "/health") {
      const available = await transport.ready().catch(() => false);
      const setup = transport.setup();
      return json(response, available ? 200 : 503, {
        ok: available,
        service: "kiwe-phonekey",
        transport: transport.name,
        state: setup.state || (available ? "ready" : "unavailable"),
        ...(setup.libraryVersion ? { libraryVersion: setup.libraryVersion } : {}),
        ...(setup.protocolVersion ? { protocolVersion: setup.protocolVersion, protocolSource: setup.protocolSource } : {}),
      });
    }
    if (request.method === "GET" && url.pathname === "/setup") {
      if (!config.setupToken || url.searchParams.get("token") !== config.setupToken) return text(response, 404, "Not found.");
      const setup = transport.setup();
      const image = setup.qr ? await QRCode.toDataURL(setup.qr, { margin: 2, width: 320 }) : "";
      const rssMb = Math.round(process.memoryUsage().rss / 1024 / 1024);
      const historyLink = config.rcObservability.enabled ? `<p><a href="/rc?token=${encodeURIComponent(config.setupToken)}">Open RC delivery history</a></p>` : "";
      const protocol = setup.protocolVersion ? `<span>Protocol: ${escapeHtml(setup.protocolVersion)} (${escapeHtml(setup.protocolSource)})</span>` : "";
      const library = setup.libraryVersion ? `<span>Baileys: ${escapeHtml(setup.libraryVersion)}</span>` : "";
      const lastDisconnect = setup.lastDisconnect?.at
        ? `<p class="diagnostic">Last disconnect: <code>${escapeHtml(setup.lastDisconnect.reason)}</code> · ${new Date(setup.lastDisconnect.at).toISOString()}</p>`
        : "";
      const compatibilityNotice = setup.registered === false
        ? `<aside><strong>New-device compatibility notice</strong><br>WhatsApp may currently reject unofficial linked-device sessions after a valid scan. If the phone says it could not link, stop retrying. PhoneKey will keep email fallback active while WhatsApp is unavailable.</aside>`
        : "";
      const recoverable = ["session-replaced", "logged-out", "reconnect-failed"].includes(setup.state);
      const resetAction = recoverable && typeof operator.resetSession === "function"
        ? `<aside><strong>Linked-device recovery required</strong><br>The stored WhatsApp device session is no longer usable. Reset only the linked-device credentials, then scan one fresh QR.<form method="post" action="/setup/reset-session?token=${encodeURIComponent(config.setupToken)}"><input type="hidden" name="confirm" value="reset-linked-device"><button type="submit">Reset linked device</button></form></aside>`
        : "";
      const setupBody = image
        ? `<p>Open WhatsApp → Linked devices → Link a device, then scan this fresh QR once.</p><img alt="WhatsApp pairing QR" src="${image}">`
        : `<p>${setup.state === "open" ? "The WhatsApp sender is connected." : (setup.state === "pairing-timeout" ? "The pairing window ended without a completed WhatsApp handshake. PhoneKey will prepare a fresh session after a guarded cooldown." : "Reload shortly while PhoneKey prepares the pairing session.")}</p>`;
      const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'"><meta http-equiv="refresh" content="15"><title>PhoneKey WhatsApp setup</title><style>body{font:16px system-ui;max-width:620px;margin:8vh auto;padding:24px;background:#f5f7f4;color:#10251d}main{background:#fff;border:1px solid #ccd8d1;border-radius:18px;padding:28px}img{display:block;max-width:100%;margin:20px auto}code{padding:4px 7px;background:#edf3ef;border-radius:5px}.metrics{display:flex;gap:10px;flex-wrap:wrap;color:#52645d;font-size:13px}.diagnostic{font-size:13px;color:#52645d}aside{margin:18px 0;padding:14px 16px;border:1px solid #d5a72e;border-radius:12px;background:#fff8df;color:#5b4300;line-height:1.45}form{margin-top:14px}button{appearance:none;border:0;border-radius:9px;background:#10251d;color:#fff;font:inherit;font-weight:700;padding:10px 14px;cursor:pointer}a{color:#075b3a}</style></head><body><main><h1>PhoneKey WhatsApp</h1><p>State: <code>${escapeHtml(setup.state)}</code></p><p class="metrics"><span>Transport: ${escapeHtml(transport.name)}</span>${library}${protocol}<span>Memory: ${rssMb} MB / ${config.memoryLimitMb} MB</span><span>Uptime: ${Math.floor(process.uptime() / 60)} min</span></p>${compatibilityNotice}${resetAction}${lastDisconnect}${setupBody}${historyLink}</main></body></html>`;
      return text(response, 200, html, "text/html; charset=utf-8", true);
    }
    if (request.method === "POST" && url.pathname === "/setup/reset-session") {
      if (!config.setupToken || url.searchParams.get("token") !== config.setupToken || typeof operator.resetSession !== "function") return text(response, 404, "Not found.");
      if (!operatorResetLimiter.allow("linked-device", clock())) return json(response, 429, { ok: false, error: "rate_limited" }, { "retry-after": "3600" });
      const raw = await body(request, 256);
      if (new URLSearchParams(raw).get("confirm") !== "reset-linked-device") return json(response, 400, { ok: false, error: "confirmation_required" });
      const setup = transport.setup();
      if (!["session-replaced", "logged-out", "reconnect-failed"].includes(setup.state)) return json(response, 409, { ok: false, error: "reset_not_required", state: setup.state });
      await operator.resetSession();
      return text(response, 202, "<!doctype html><html><head><meta name=\"viewport\" content=\"width=device-width\"><meta http-equiv=\"refresh\" content=\"4;url=/setup?token=" + encodeURIComponent(config.setupToken) + "\"><title>PhoneKey reset</title></head><body><main><h1>Linked device reset</h1><p>PhoneKey is restarting. A fresh pairing QR will appear shortly.</p></main></body></html>", "text/html; charset=utf-8");
    }
    if (request.method === "GET" && url.pathname === "/rc") {
      if (!config.rcObservability.enabled || !config.setupToken || url.searchParams.get("token") !== config.setupToken) return text(response, 404, "Not found.");
      const entries = history?.list({ tenant: url.searchParams.get("tenant") || "", limit: url.searchParams.get("limit") || 200 }) || [];
      const rows = entries.map((entry) => `<tr><td>${new Date(entry.at).toISOString()}</td><td>${escapeHtml(entry.tenant)}</td><td>${escapeHtml(entry.direction)}</td><td>${escapeHtml(entry.status)}</td><td>••••${escapeHtml(entry.targetLast4)}</td><td>${escapeHtml(entry.content || entry.summary)}</td><td>${escapeHtml(entry.error)}</td></tr>`).join("");
      const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'"><meta http-equiv="refresh" content="20"><title>PhoneKey RC history</title><style>body{font:14px system-ui;margin:24px;background:#f4f7f5;color:#10251d}main{background:#fff;border:1px solid #ccd8d1;border-radius:16px;padding:22px;overflow:auto}table{border-collapse:collapse;width:100%;min-width:920px}th,td{text-align:left;padding:9px;border-bottom:1px solid #e0e8e3;vertical-align:top}th{position:sticky;top:0;background:#edf4ef}.note{color:#52645d}</style></head><body><main><h1>PhoneKey RC delivery history</h1><p class="note">Capped at ${config.rcObservability.maxEvents} events for ${config.rcObservability.retentionDays} days. OTP codes are never stored. Inbound text capture: ${config.rcObservability.captureInboundText ? "enabled" : "disabled"}. Consented outbound notification capture: ${config.rcObservability.captureOutboundText ? "enabled" : "disabled"}.</p><table><thead><tr><th>Time</th><th>Tenant</th><th>Direction</th><th>Status</th><th>Target</th><th>Summary</th><th>Error</th></tr></thead><tbody>${rows || '<tr><td colspan="7">No events yet.</td></tr>'}</tbody></table></main></body></html>`;
      return text(response, 200, html, "text/html; charset=utf-8");
    }
    if (request.method === "POST" && url.pathname === "/setup/self-test") {
      if (!config.setupToken || url.searchParams.get("token") !== config.setupToken) return text(response, 404, "Not found.");
      if (!operatorSelfTestLimiter.allow("connected-account", clock())) return json(response, 429, { ok: false, error: "rate_limited" }, { "retry-after": "3600" });
      if (!(await transport.ready().catch(() => false)) || typeof transport.selfTest !== "function") return json(response, 503, { ok: false, error: "whatsapp_unavailable", fallback: "email" });
      try {
        const sent = await transport.selfTest();
        await record({ tenant: config.rcObservability.tenant, phone: sent.target, receipt: sent.id, direction: "outbound", channel: "whatsapp", status: "accepted", summary: "Connected-account RC self-test" });
        return json(response, 202, { ok: true, provider: "whatsapp", receipt: opaque(sent.id || "self-test") });
      } catch {
        await record({ tenant: config.rcObservability.tenant, direction: "outbound", channel: "whatsapp", status: "failed", summary: "Connected-account RC self-test", error: "provider_send_failure" });
        return json(response, 503, { ok: false, error: "delivery_unavailable", fallback: "email" });
      }
    }
    if (request.method !== "POST" || !["/v1/otp", "/v1/message", "/v1/event"].includes(url.pathname)) return json(response, 404, { ok: false, error: "not_found" });
    if (!String(request.headers["content-type"] || "").toLowerCase().startsWith("application/json")) return json(response, 415, { ok: false, error: "json_required" });

    try {
      const raw = await body(request);
      const { keyId, tenant, now } = authenticate(request, raw);
      const payload = JSON.parse(raw);
      const phone = normalizePhone(payload.phone);
      const origin = String(payload.origin || "").replace(/\/$/, "");
      const requestId = String(payload.requestId || "");
      if (tenant.sites.length && !tenant.sites.map((item) => item.replace(/\/$/, "")).includes(origin)) return json(response, 403, { ok: false, error: "site_not_allowed" });
      if (url.pathname === "/v1/event") {
        const event = String(payload.event || "");
        if (!phone || !/^[a-zA-Z0-9._:-]{16,128}$/.test(requestId) || !["email_fallback_accepted", "email_fallback_failed"].includes(event)) return json(response, 422, { ok: false, error: "invalid_payload" });
        await record({ tenant: keyId, phone, requestId, direction: "outbound", channel: "email", status: event, summary: event === "email_fallback_accepted" ? "Email fallback accepted by WordPress mail transport" : "Email fallback failed at WordPress mail transport" });
        return json(response, 202, { ok: true });
      }
      if (url.pathname === "/v1/message") {
        const messageBody = String(payload.message || "").trim();
        const purpose = String(payload.purpose || "");
        const allowedPurposes = new Set(["notification_campaign", "abandoned_cart_reminder", "abandoned_cart_automation", "order_status", "admin_new_order", "admin_new_comment", "admin_visitor_summary", "admin_live_visitor"]);
        if (!phone || !messageBody || messageBody.length > 1600 || !allowedPurposes.has(purpose) || !/^[a-zA-Z0-9._:-]{16,128}$/.test(requestId)) return json(response, 422, { ok: false, error: "invalid_payload" });
        if (idempotency.has(`${keyId}:${requestId}`, now)) return json(response, 200, { ok: true, duplicate: true, provider: "whatsapp" });
        const messageTarget = opaque(`${keyId}:message:${phone}`);
        if (!messageTenantLimiter.allow(keyId, now) || !messageTargetLimiter.allow(messageTarget, now)) return json(response, 429, { ok: false, error: "rate_limited" }, { "retry-after": "3600" });
        if (!(await transport.ready())) {
          await record({ tenant: keyId, phone, requestId, direction: "outbound", status: "fallback_required", summary: `${purpose}: WhatsApp unavailable; consent-aware fallback may be used` });
          return json(response, 503, { ok: false, error: "whatsapp_unavailable", fallback: "email" });
        }
        try {
          const sent = await transport.sendText(phone, messageBody);
          idempotency.add(`${keyId}:${requestId}`, 24 * 60 * 60 * 1000, now);
          await record({ tenant: keyId, phone, requestId, receipt: sent.id, direction: "outbound", status: "accepted", summary: purpose, content: messageBody, allowContent: true });
          return json(response, 202, { ok: true, provider: "whatsapp", receipt: opaque(sent.id || requestId) });
        } catch (error) {
          await record({ tenant: keyId, phone, requestId, direction: "outbound", status: "failed", summary: purpose, error: "provider_send_failure" });
          throw error;
        }
      }
      const code = String(payload.code || "");
      const site = String(payload.site || tenant.label).trim().slice(0, 80);
      if (!phone || !/^\d{6}$/.test(code) || !/^[a-zA-Z0-9._:-]{16,128}$/.test(requestId)) return json(response, 422, { ok: false, error: "invalid_payload" });
      await record({ tenant: keyId, phone, requestId, direction: "outbound", status: "requested", summary: "PhoneKey verification requested" });
      if (idempotency.has(`${keyId}:${requestId}`, now)) return json(response, 200, { ok: true, duplicate: true, provider: "whatsapp" });
      const target = opaque(`${keyId}:${phone}`);
      if (!tenantLimiter.allow(keyId, now) || !targetLimiter.allow(target, now)) return json(response, 429, { ok: false, error: "rate_limited" }, { "retry-after": "3600" });
      if (!(await transport.ready())) {
        await record({ tenant: keyId, phone, requestId, direction: "outbound", status: "fallback_required", summary: "WhatsApp unavailable; email fallback required" });
        return json(response, 503, { ok: false, error: "whatsapp_unavailable", fallback: "email" });
      }
      const sent = await transport.sendText(phone, message(site || tenant.label, code));
      idempotency.add(`${keyId}:${requestId}`, 15 * 60 * 1000, now);
      await record({ tenant: keyId, phone, requestId, receipt: sent.id, direction: "outbound", status: "accepted", summary: "WhatsApp accepted the PhoneKey verification message" });
      return json(response, 202, { ok: true, provider: "whatsapp", receipt: opaque(sent.id || requestId) });
    } catch (error) {
      const status = Number(error.status) || 503;
      const name = status === 413 ? "request_too_large" : (error.message === "replayed_request" ? "replayed_request" : (error.message === "expired_request" ? "expired_request" : (status === 401 ? "unauthorized" : "delivery_unavailable")));
      return json(response, status, { ok: false, error: name, ...(status >= 500 ? { fallback: "email" } : {}) });
    }
  };
}
