import assert from "node:assert/strict";
import { createServer } from "node:http";
import { afterEach, test } from "node:test";
import { createApp } from "../src/app.mjs";
import { signature } from "../src/security.mjs";

const servers = [];
afterEach(async () => { while (servers.length) await new Promise((resolve) => servers.pop().close(resolve)); });

async function fixture(ready = true) {
  const sent = [];
  const events = [];
  const config = {
    setupToken: "s".repeat(32), requestWindowSeconds: 90, sendTimeoutMs: 1000,
    tenants: { client: { secret: "x".repeat(40), sites: ["https://example.com"], label: "Example" } },
  };
  const transport = { name: "test", ready: async () => ready, sendText: async (phone, text) => { sent.push({ phone, text }); return { id: "message-1" }; }, setup: () => ({ state: ready ? "open" : "closed", qr: "" }), close: async () => {} };
  const history = { record: async (event) => { events.push(event); }, list: () => events };
  const server = createServer(createApp(config, transport, () => 1720000000000, history));
  servers.push(server);
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  return { config, sent, events, url: `http://127.0.0.1:${server.address().port}` };
}

function signed(config, payload, overrides = {}) {
  const raw = JSON.stringify(payload);
  const timestamp = "1720000000000";
  const nonce = overrides.nonce || "nonce_1234567890123456";
  return {
    method: "POST",
    headers: {
      "content-type": "application/json", "x-phonekey-key-id": "client", "x-phonekey-timestamp": timestamp,
      "x-phonekey-nonce": nonce, "x-phonekey-signature": signature(config.tenants.client.secret, timestamp, nonce, raw),
    },
    body: raw,
  };
}

const payload = { phone: "+919876543210", code: "135790", site: "Example", origin: "https://example.com", requestId: "request_1234567890123456" };

test("accepts a signed bounded OTP without exposing it in the response", async () => {
  const app = await fixture();
  const response = await fetch(`${app.url}/v1/otp`, signed(app.config, payload));
  const result = await response.json();
  assert.equal(response.status, 202);
  assert.equal(result.ok, true);
  assert.equal(JSON.stringify(result).includes(payload.code), false);
  assert.deepEqual(app.sent.map((item) => item.phone), ["919876543210"]);
});

test("rejects invalid signatures, replay, and unapproved site origins", async () => {
  const app = await fixture();
  const invalid = signed(app.config, payload);
  invalid.headers["x-phonekey-signature"] = "0".repeat(64);
  assert.equal((await fetch(`${app.url}/v1/otp`, invalid)).status, 401);
  const valid = signed(app.config, payload);
  assert.equal((await fetch(`${app.url}/v1/otp`, valid)).status, 202);
  assert.equal((await fetch(`${app.url}/v1/otp`, valid)).status, 409);
  const wrongSite = signed(app.config, { ...payload, origin: "https://attacker.example", requestId: "request_abcdefghijklmnop" }, { nonce: "nonce_abcdefghijklmnop" });
  assert.equal((await fetch(`${app.url}/v1/otp`, wrongSite)).status, 403);
});

test("returns an explicit email fallback signal while WhatsApp is unavailable", async () => {
  const app = await fixture(false);
  const response = await fetch(`${app.url}/v1/otp`, signed(app.config, payload));
  assert.equal(response.status, 503);
  assert.equal((await response.json()).fallback, "email");
  assert.equal(app.sent.length, 0);
  assert.equal(app.events.some((event) => event.status === "fallback_required"), true);
});

test("accepts signed email fallback outcomes without OTP content", async () => {
  const app = await fixture(false);
  const outcome = { phone: payload.phone, origin: payload.origin, requestId: payload.requestId, event: "email_fallback_accepted" };
  const response = await fetch(`${app.url}/v1/event`, signed(app.config, outcome));
  assert.equal(response.status, 202);
  assert.equal(app.events.at(-1).status, "email_fallback_accepted");
  assert.equal(JSON.stringify(app.events).includes(payload.code), false);
});

test("accepts bounded consented notifications through the same tenant", async () => {
  const app = await fixture();
  const notification = { phone: payload.phone, origin: payload.origin, requestId: "notification_1234567890123456", purpose: "order_status", message: "Example: Order #42 is ready." };
  const response = await fetch(`${app.url}/v1/message`, signed(app.config, notification));
  assert.equal(response.status, 202);
  assert.equal(app.sent.at(-1).text, notification.message);
  assert.equal(app.events.at(-1).summary, "order_status");
  assert.equal(app.events.at(-1).allowContent, true);
});
