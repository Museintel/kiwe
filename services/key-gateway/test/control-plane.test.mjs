import assert from "node:assert/strict";
import { readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { randomUUID } from "node:crypto";
import { afterEach, test } from "node:test";
import { ControlPlaneStore } from "../src/control-plane.mjs";

const paths = [];
afterEach(async () => { while (paths.length) await rm(paths.pop(), { force: true }); });

function config(path) {
  return {
    setupToken: "t".repeat(40),
    controlPlane: { path, bootstrapOwnerEmail: "owner@example.com", encryptionKey: "", sessionHours: 24, registrationEnabled: true },
    tenants: { existing: { secret: "s".repeat(40), sites: ["https://existing.example"], label: "Existing" } },
  };
}

test("migrates the imported master and sites to the account-primary model without exposing secrets", async () => {
  const path = join(tmpdir(), `key-control-${randomUUID()}.json`);
  paths.push(path);
  const store = await new ControlPlaneStore(config(path), () => 1720000000000).load();
  const owner = store.bootstrapUser();
  assert.equal(owner.role, "master");
  assert.equal(store.sitesFor(owner.id)[0].connectionMode, "account_primary");
  await store.linkBootstrapPrimaryPhone("919876543210");
  assert.equal(store.userByPhone("+91 98765 43210").id, owner.id);
  assert.equal((await readFile(path, "utf8")).includes("919876543210"), false);
  assert.equal((await readFile(path, "utf8")).includes("s".repeat(40)), false);
  await store.close();
});

test("rebrands only the exact historical PhoneKey test-site label", async () => {
  const path = join(tmpdir(), `key-control-${randomUUID()}.json`);
  paths.push(path);
  const old = { version: 2, users: [], sessions: [], registrations: [], reconnections: [], loginChallenges: [], sites: [
    { id: "old-test", ownerId: "", keyId: "old-test", label: "PhoneKey RC test site", origin: "https://test.example", secret: "", active: true, connectionMode: "account_primary", pendingDedicated: false },
    { id: "client", ownerId: "", keyId: "client", label: "Client PhoneKey Archive", origin: "https://client.example", secret: "", active: true, connectionMode: "account_primary", pendingDedicated: false },
  ] };
  await import("node:fs/promises").then(({ writeFile }) => writeFile(path, JSON.stringify(old)));
  const store = await new ControlPlaneStore(config(path), () => 1720000000000).load();
  assert.equal(store.siteByKeyId("old-test").label, "Key.kiwe RC test site");
  assert.equal(store.siteByKeyId("client").label, "Client PhoneKey Archive");
  await store.close();
});

test("registers with one WhatsApp pairing and signs in with a one-time code", async () => {
  const path = join(tmpdir(), `key-control-${randomUUID()}.json`);
  paths.push(path);
  const store = await new ControlPlaneStore(config(path), () => 1720000000000).load();
  const pending = await store.beginRegistration("919111222333");
  assert.equal(store.registration(pending.token).connectionId, pending.registration.connectionId);
  await assert.rejects(() => store.completeRegistration(pending.token, "919999888777"), /does not match/);
  const user = await store.completeRegistration(pending.token, "919111222333");
  assert.equal(user.role, "owner");
  assert.equal(user.primaryConnectionMode, "dedicated");
  const challenge = await store.beginLogin("919111222333");
  assert.equal((await store.verifyLogin(challenge.token, "000000")), null);
  const authenticated = await store.verifyLogin(challenge.token, challenge.code);
  assert.equal(authenticated.id, user.id);
  const session = await store.createSession(user.id);
  assert.equal(store.session(session.token).user.phone, "919111222333");
  await store.close();
});

test("repairs an offline account only after the registered WhatsApp number scans", async () => {
  const path = join(tmpdir(), `key-control-${randomUUID()}.json`);
  paths.push(path);
  const store = await new ControlPlaneStore(config(path), () => 1720000000000).load();
  await store.linkBootstrapPrimaryPhone("919876543210");
  const reconnect = await store.beginReconnect("919876543210");
  assert.ok(reconnect.token);
  assert.equal(store.reconnection(reconnect.token).user.role, "master");
  await assert.rejects(() => store.completeReconnect(reconnect.token, "919111222333"), /does not match/);
  const repaired = await store.completeReconnect(reconnect.token, "919876543210");
  assert.equal(repaired.primaryConnectionMode, "dedicated");
  assert.notEqual(repaired.primaryConnectionId, "legacy-primary");
  assert.equal(store.reconnection(reconnect.token), null);
  await store.close();
});

test("new websites inherit the account number and can independently override or return", async () => {
  const path = join(tmpdir(), `key-control-${randomUUID()}.json`);
  paths.push(path);
  const store = await new ControlPlaneStore(config(path), () => 1720000000000).load();
  const pending = await store.beginRegistration("919111222333");
  const user = await store.completeRegistration(pending.token, "919111222333");
  const created = await store.createSite(user.id, "News", "https://news.example/path");
  assert.equal(created.site.connectionMode, "account_primary");
  await store.prepareDedicated(user.id, created.site.id);
  await store.finalizeDedicated(created.site.id, "919222333444");
  assert.equal(store.siteByKeyId(created.site.keyId).connectionMode, "dedicated");
  assert.equal(store.sitesFor(user.id).find((site) => site.id === created.site.id).activePhone, "919222333444");
  await store.inheritPrimary(user.id, created.site.id);
  assert.equal(store.siteByKeyId(created.site.keyId).connectionMode, "account_primary");
  await store.close();
});

test("only the master can inspect and suspend other account workspaces", async () => {
  const path = join(tmpdir(), `key-control-${randomUUID()}.json`);
  paths.push(path);
  const store = await new ControlPlaneStore(config(path), () => 1720000000000).load();
  await store.linkBootstrapPrimaryPhone("919876543210");
  const master = store.bootstrapUser();
  const pending = await store.beginRegistration("919111222333");
  const user = await store.completeRegistration(pending.token, "919111222333");
  await store.createSite(user.id, "Client", "https://client.example");
  assert.equal(store.networkSummary(master.id).find((entry) => entry.id === user.id).sites.length, 1);
  assert.throws(() => store.networkSummary(user.id), /Master access/);
  await store.setUserStatus(master.id, user.id, "suspended");
  assert.equal(store.userById(user.id).status, "suspended");
  await store.close();
});
