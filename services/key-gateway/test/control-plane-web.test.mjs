import assert from "node:assert/strict";
import { createServer } from "node:http";
import { rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { randomUUID } from "node:crypto";
import { afterEach, test } from "node:test";
import { ControlPlaneStore } from "../src/control-plane.mjs";
import { createControlPlaneWeb } from "../src/control-plane-web.mjs";

const servers = [];
const paths = [];
afterEach(async () => {
  while (servers.length) await new Promise((resolve) => servers.pop().close(resolve));
  while (paths.length) await rm(paths.pop(), { force: true });
});

async function fixture({ offlineLogin = false } = {}) {
  const path = join(tmpdir(), `key-web-${randomUUID()}.json`);
  paths.push(path);
  const config = {
    setupToken: "t".repeat(40), publicBaseUrl: "https://key.kiwelaunch.com",
    controlPlane: { path, bootstrapOwnerEmail: "owner@example.com", encryptionKey: "", sessionHours: 24, registrationEnabled: true },
    tenants: { current: { secret: "s".repeat(40), sites: ["https://current.example"], label: "Current" } },
  };
  const store = await new ControlPlaneStore(config, () => 1720000000000).load();
  await store.linkBootstrapPrimaryPhone("919876543210");
  const transport = { ready: async () => true, setup: () => ({ state: "open", connectedPhone: "919876543210", qr: "" }) };
  let sentCode = "";
  const connections = {
    ownerTransport: async () => transport,
    registrationTransport: async (registration) => ({ ...transport, setup: () => ({ state: "open", connectedPhone: registration.phone, qr: "" }) }),
    reconnectTransport: async (reconnection) => ({ ...transport, setup: () => ({ state: "open", connectedPhone: reconnection.user.phone, qr: "" }) }),
    pendingPrimaryTransport: async () => transport,
    sendLoginCode: async (_user, code) => {
      if (offlineLogin) throw Object.assign(new Error("offline"), { status: 503 });
      sentCode = code;
    },
    forDisplay: async () => transport,
    beginDedicated: async (_userId, siteId) => store.prepareDedicated(store.bootstrapUser().id, siteId),
    inheritPrimary: async (userId, siteId) => store.inheritPrimary(userId, siteId),
    resetSite: async () => transport,
  };
  const web = createControlPlaneWeb({ config, store, connections, clock: () => 1720000000000 });
  const server = createServer(async (request, response) => { await web.handle(request, response); if (!response.writableEnded) response.end("unhandled"); });
  servers.push(server);
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  return { config, store, connections, get sentCode() { return sentCode; }, url: `http://127.0.0.1:${server.address().port}` };
}

test("serves the public Key.kiwe product site and keeps the dashboard protected", async () => {
  const app = await fixture();
  const landing = await fetch(`${app.url}/`);
  const html = await landing.text();
  assert.equal(landing.status, 200);
  assert.match(html, /One secure key for every website/);
  assert.match(html, /Try it — it’s free/);
  assert.equal((await fetch(`${app.url}/app`, { redirect: "manual" })).headers.get("location"), "/login");
});

test("registers with only a number and one completed pairing scan", async () => {
  const app = await fixture();
  const start = await fetch(`${app.url}/register`, { method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" }, body: "phone=919111222333", redirect: "manual" });
  assert.equal(start.status, 303);
  assert.equal(start.headers.get("location"), "/register/pair");
  const pairingCookie = start.headers.get("set-cookie").split(";")[0];
  const complete = await fetch(`${app.url}/register/pair`, { headers: { cookie: pairingCookie }, redirect: "manual" });
  assert.equal(complete.status, 303);
  assert.equal(complete.headers.get("location"), "/app");
  assert.equal(app.store.userByPhone("919111222333").role, "owner");
});

test("signs in by WhatsApp number and one-time code without a password", async () => {
  const app = await fixture();
  const start = await fetch(`${app.url}/login`, { method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" }, body: "phone=919876543210", redirect: "manual" });
  assert.equal(start.status, 303);
  const challengeCookie = start.headers.get("set-cookie").split(";")[0];
  const verify = await fetch(`${app.url}/login/verify`, { method: "POST", headers: { cookie: challengeCookie, "content-type": "application/x-www-form-urlencoded" }, body: `code=${app.sentCode}`, redirect: "manual" });
  assert.equal(verify.status, 303);
  assert.equal(verify.headers.get("location"), "/app");
  assert.match(verify.headers.get("set-cookie"), /key_session=/);
});

test("turns an offline sign-in into a same-number QR reconnection and authenticated session", async () => {
  const app = await fixture({ offlineLogin: true });
  const start = await fetch(`${app.url}/login`, { method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" }, body: "phone=919876543210", redirect: "manual" });
  assert.equal(start.status, 303);
  assert.equal(start.headers.get("location"), "/reconnect/pair");
  const reconnectCookie = start.headers.get("set-cookie").split(";")[0];
  const complete = await fetch(`${app.url}/reconnect/pair`, { headers: { cookie: reconnectCookie }, redirect: "manual" });
  assert.equal(complete.status, 303);
  assert.equal(complete.headers.get("location"), "/app");
  assert.match(complete.headers.get("set-cookie"), /key_session=/);
  assert.equal(app.store.bootstrapUser().primaryConnectionMode, "dedicated");
});

test("new sites inherit the primary number and expose client override controls", async () => {
  const app = await fixture();
  const owner = app.store.bootstrapUser();
  const session = await app.store.createSession(owner.id);
  const cookie = `key_session=${session.token}`;
  const created = await fetch(`${app.url}/sites`, { method: "POST", headers: { cookie, "content-type": "application/x-www-form-urlencoded" }, body: `csrf=${encodeURIComponent(session.csrf)}&label=News&origin=https%3A%2F%2Fnews.example` });
  const html = await created.text();
  assert.equal(created.status, 201);
  assert.match(html, /Account primary/);
  assert.match(html, /Use a client number/);
  assert.match(html, /key\.kiwelaunch\.com\/v1\/otp/);
});

test("master oversight lists tenant websites and can suspend another account", async () => {
  const app = await fixture();
  const pending = await app.store.beginRegistration("919111222333");
  const user = await app.store.completeRegistration(pending.token, "919111222333");
  await app.store.createSite(user.id, "Client", "https://client.example");
  const master = app.store.bootstrapUser();
  const session = await app.store.createSession(master.id);
  const cookie = `key_session=${session.token}`;
  const overview = await fetch(`${app.url}/network`, { headers: { cookie } });
  const overviewHtml = await overview.text();
  assert.match(overviewHtml, /client\.example/);
  assert.match(overviewHtml, /Serving number: <strong>\+919111222333<\/strong> · account primary/);
  const suspended = await fetch(`${app.url}/network/users/${user.id}/toggle`, { method: "POST", headers: { cookie, "content-type": "application/x-www-form-urlencoded" }, body: `csrf=${encodeURIComponent(session.csrf)}&status=suspended` });
  assert.equal(suspended.status, 200);
  assert.equal(app.store.userById(user.id).status, "suspended");
});
