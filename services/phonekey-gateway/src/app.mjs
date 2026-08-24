import QRCode from "qrcode";
import { ExpiringSet, SlidingLimiter, normalizePhone, opaque, verifySignature } from "./security.mjs";

const json = (response, status, payload, headers = {}) => {
  const body = JSON.stringify(payload);
  response.writeHead(status, { "content-type": "application/json; charset=utf-8", "cache-control": "no-store", ...headers });
  response.end(body);
};

const text = (response, status, body, type = "text/plain; charset=utf-8") => {
  response.writeHead(status, {
    "content-type": type,
    "cache-control": "no-store",
    "content-security-policy": "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
    "x-content-type-options": "nosniff",
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

export function createApp(config, transport, clock = () => Date.now(), history = null) {
  const nonces = new ExpiringSet(10000);
  const idempotency = new ExpiringSet(10000);
  const tenantLimiter = new SlidingLimiter(120, 60 * 60 * 1000);
  const targetLimiter = new SlidingLimiter(6, 60 * 60 * 1000);
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
      return json(response, available ? 200 : 503, { ok: available, service: "kiwe-phonekey", transport: transport.name, state: available ? "ready" : "unavailable" });
    }
    if (request.method === "GET" && url.pathname === "/setup") {
      if (!config.setupToken || url.searchParams.get("token") !== config.setupToken) return text(response, 404, "Not found.");
      const setup = transport.setup();
      const image = setup.qr ? await QRCode.toDataURL(setup.qr, { margin: 2, width: 320 }) : "";
      const rssMb = Math.round(process.memoryUsage().rss / 1024 / 1024);
      const historyLink = config.rcObservability.enabled ? `<p><a href="/rc?token=${encodeURIComponent(config.setupToken)}">Open RC delivery history</a></p>` : "";
      const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width"><meta http-equiv="refresh" content="15"><title>PhoneKey WhatsApp setup</title><style>body{font:16px system-ui;max-width:560px;margin:8vh auto;padding:24px;background:#f5f7f4;color:#10251d}main{background:#fff;border:1px solid #ccd8d1;border-radius:18px;padding:28px}img{display:block;max-width:100%;margin:20px auto}code{padding:4px 7px;background:#edf3ef;border-radius:5px}.metrics{display:flex;gap:10px;flex-wrap:wrap;color:#52645d;font-size:13px}a{color:#075b3a}</style></head><body><main><h1>PhoneKey WhatsApp</h1><p>State: <code>${escapeHtml(setup.state)}</code></p><p class="metrics"><span>Transport: ${escapeHtml(transport.name)}</span><span>Memory: ${rssMb} MB / ${config.memoryLimitMb} MB</span><span>Uptime: ${Math.floor(process.uptime() / 60)} min</span></p>${image ? `<p>Open WhatsApp → Linked devices → Link a device, then scan this QR.</p><img alt="WhatsApp pairing QR" src="${image}">` : `<p>${setup.state === "open" ? "The WhatsApp sender is connected." : "Reload shortly while PhoneKey prepares the pairing session."}</p>`}${historyLink}</main></body></html>`;
      return text(response, 200, html, "text/html; charset=utf-8");
    }
    if (request.method === "GET" && url.pathname === "/rc") {
      if (!config.rcObservability.enabled || !config.setupToken || url.searchParams.get("token") !== config.setupToken) return text(response, 404, "Not found.");
      const entries = history?.list({ tenant: url.searchParams.get("tenant") || "", limit: url.searchParams.get("limit") || 200 }) || [];
      const rows = entries.map((entry) => `<tr><td>${new Date(entry.at).toISOString()}</td><td>${escapeHtml(entry.tenant)}</td><td>${escapeHtml(entry.direction)}</td><td>${escapeHtml(entry.status)}</td><td>••••${escapeHtml(entry.targetLast4)}</td><td>${escapeHtml(entry.content || entry.summary)}</td><td>${escapeHtml(entry.error)}</td></tr>`).join("");
      const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width"><meta http-equiv="refresh" content="20"><title>PhoneKey RC history</title><style>body{font:14px system-ui;margin:24px;background:#f4f7f5;color:#10251d}main{background:#fff;border:1px solid #ccd8d1;border-radius:16px;padding:22px;overflow:auto}table{border-collapse:collapse;width:100%;min-width:920px}th,td{text-align:left;padding:9px;border-bottom:1px solid #e0e8e3;vertical-align:top}th{position:sticky;top:0;background:#edf4ef}.note{color:#52645d}</style></head><body><main><h1>PhoneKey RC delivery history</h1><p class="note">Capped at ${config.rcObservability.maxEvents} events for ${config.rcObservability.retentionDays} days. OTP codes are never stored. Inbound text capture: ${config.rcObservability.captureInboundText ? "enabled" : "disabled"}.</p><table><thead><tr><th>Time</th><th>Tenant</th><th>Direction</th><th>Status</th><th>Target</th><th>Summary</th><th>Error</th></tr></thead><tbody>${rows || '<tr><td colspan="7">No events yet.</td></tr>'}</tbody></table></main></body></html>`;
      return text(response, 200, html, "text/html; charset=utf-8");
    }
    if (request.method !== "POST" || !["/v1/otp", "/v1/event"].includes(url.pathname)) return json(response, 404, { ok: false, error: "not_found" });
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
