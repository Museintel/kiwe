import assert from "node:assert/strict";
import { randomBytes } from "node:crypto";
import { loadConfig } from "../src/config.mjs";
import { signature } from "../src/security.mjs";

const baseUrl = String(process.env.PHONEKEY_BASE_URL || "").replace(/\/$/, "");
assert.match(baseUrl, /^https:\/\//, "PHONEKEY_BASE_URL must be an HTTPS URL.");

const config = loadConfig();
const [keyId, tenant] = Object.entries(config.tenants)[0] || [];
const origin = tenant?.sites?.[0];
assert.ok(keyId && tenant?.secret && origin, "The selected runtime tenant needs a secret and at least one allowed site.");

const results = [];
const mark = (name, detail = "") => results.push({ name, detail });
const nonce = () => randomBytes(18).toString("base64url");

function signed(raw, options = {}) {
  const timestamp = String(options.timestamp ?? Date.now());
  const requestNonce = options.nonce || nonce();
  return {
    "content-type": "application/json",
    "x-phonekey-key-id": keyId,
    "x-phonekey-timestamp": timestamp,
    "x-phonekey-nonce": requestNonce,
    "x-phonekey-signature": options.invalidSignature ? "0".repeat(64) : signature(tenant.secret, timestamp, requestNonce, raw),
  };
}

async function request(path, options = {}) {
  return fetch(`${baseUrl}${path}`, { redirect: "error", signal: AbortSignal.timeout(15000), ...options });
}

const health = await request("/health");
assert.equal(health.status, 200);
assert.match(health.headers.get("cache-control") || "", /no-store/);
const healthBody = await health.json();
assert.equal(healthBody.ok, true);
assert.equal(healthBody.state, "open");
assert.equal(healthBody.libraryVersion, "7.0.0-rc14");
assert.deepEqual(Object.keys(healthBody).sort(), ["libraryVersion", "ok", "protocolSource", "protocolVersion", "service", "state", "transport"]);
mark("public health is open and bounded", `${healthBody.libraryVersion} / ${healthBody.protocolVersion}`);

const setupMissing = await request("/setup");
assert.equal(setupMissing.status, 404);
const setupWrong = await request(`/setup?token=${"x".repeat(32)}`);
assert.equal(setupWrong.status, 404);
const setup = await request(`/setup?token=${encodeURIComponent(config.setupToken)}`);
assert.equal(setup.status, 200);
const framingDenied = /frame-ancestors 'none'/.test(setup.headers.get("content-security-policy") || "")
  || /^DENY$/i.test(setup.headers.get("x-frame-options") || "");
assert.equal(framingDenied, true, "The hosting edge must preserve at least one anti-framing control.");
assert.match(setup.headers.get("permissions-policy") || "", /camera=\(\)/);
assert.match(setup.headers.get("referrer-policy") || "", /no-referrer/);
const setupBody = await setup.text();
assert.match(setupBody, /http-equiv="Content-Security-Policy"/);
assert.match(setupBody, /State: <code>open<\/code>/);
assert.match(setupBody, /Baileys: 7\.0\.0-rc14/);
assert.doesNotMatch(setupBody, /pairing QR/);
mark("setup authorization and connected UI");

for (const path of ["/runtime-config.json", "/.phonekey-state/creds.json", "/src/server.mjs", "/package.json"]) {
  const response = await request(path);
  assert.equal(response.status, 404, `${path} must not be public`);
}
mark("runtime secrets, state, source and manifest are not public");

const wrongType = await request("/v1/otp", { method: "POST", headers: { "content-type": "text/plain" }, body: "{}" });
assert.equal(wrongType.status, 415);

const sample = JSON.stringify({ phone: "+919999999999", code: "123456", site: "Smoke", origin, requestId: `smoke_${nonce()}` });
const invalidSignature = await request("/v1/otp", { method: "POST", headers: signed(sample, { invalidSignature: true }), body: sample });
assert.equal(invalidSignature.status, 401);

const expired = await request("/v1/otp", { method: "POST", headers: signed(sample, { timestamp: Date.now() - 10 * 60 * 1000 }), body: sample });
assert.equal(expired.status, 401);
assert.equal((await expired.json()).error, "expired_request");

const wrongOriginBody = JSON.stringify({ phone: "+919999999999", code: "123456", site: "Smoke", origin: "https://not-allowed.invalid", requestId: `smoke_${nonce()}` });
const wrongOrigin = await request("/v1/otp", { method: "POST", headers: signed(wrongOriginBody), body: wrongOriginBody });
assert.equal(wrongOrigin.status, 403);

const invalidPayloadBody = JSON.stringify({ phone: "invalid", code: "000", site: "Smoke", origin, requestId: `smoke_${nonce()}` });
const replayNonce = nonce();
const invalidPayloadHeaders = signed(invalidPayloadBody, { nonce: replayNonce });
const invalidPayload = await request("/v1/otp", { method: "POST", headers: invalidPayloadHeaders, body: invalidPayloadBody });
assert.equal(invalidPayload.status, 422);
const replay = await request("/v1/otp", { method: "POST", headers: invalidPayloadHeaders, body: invalidPayloadBody });
assert.equal(replay.status, 409);
assert.equal((await replay.json()).error, "replayed_request");

const oversized = await request("/v1/otp", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ padding: "x".repeat(9000) }) });
assert.equal(oversized.status, 413);
mark("content type, signature, freshness, origin, payload, replay and size defenses");

const timings = await Promise.all(Array.from({ length: 20 }, async () => {
  const start = performance.now();
  const response = await request("/health");
  assert.equal(response.status, 200);
  await response.arrayBuffer();
  return performance.now() - start;
}));
timings.sort((a, b) => a - b);
const p95 = timings[Math.ceil(timings.length * 0.95) - 1];
assert.ok(p95 < 5000, `Health p95 was unexpectedly slow: ${Math.round(p95)} ms`);
mark("bounded concurrent health probe", `20/20 passed; p95 ${Math.round(p95)} ms`);

for (const result of results) console.log(`PASS ${result.name}${result.detail ? ` — ${result.detail}` : ""}`);
console.log(`Live PhoneKey smoke passed (${results.length}/${results.length}). No WhatsApp message was sent.`);
