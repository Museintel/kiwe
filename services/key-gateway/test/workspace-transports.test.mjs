import assert from "node:assert/strict";
import { test } from "node:test";
import { WorkspaceTransportManager } from "../src/workspace-transports.mjs";

test("keeps every dedicated website in an isolated persistent transport state directory", async () => {
  const closed = [];
  const owner = { id: "owner-1", status: "active", primaryConnectionMode: "legacy_shared", primaryConnectionId: "legacy-primary" };
  const sites = {
    first: { id: "site-1", ownerId: owner.id, keyId: "first", active: true, connectionMode: "account_primary", pendingDedicated: false },
    second: { id: "site-2", ownerId: owner.id, keyId: "second", active: true, connectionMode: "dedicated", pendingDedicated: false },
  };
  const store = {
    siteByKeyId: (key) => sites[key] || null,
    ownerForSite: () => owner,
    userById: () => owner,
  };
  const primary = { ready: async () => true, close: async () => { closed.push("primary"); } };
  const manager = new WorkspaceTransportManager({
    config: { stateDirectory: "C:/kiwe-key-state", transport: "baileys" }, store, primaryTransport: primary, history: null,
    transportFactory: async (config, _onEvent, site) => ({ siteId: site.id, stateDirectory: config.stateDirectory, ready: async () => true, close: async () => { closed.push(site.id); } }),
  });
  assert.equal(await manager.forTenant("first"), primary);
  const second = await manager.forTenant("second");
  assert.equal(second.siteId, sites.second.id);
  assert.match(second.stateDirectory.replace(/\\/g, "/"), /connections\/site-2$/);
  assert.notEqual(second, primary);
  await manager.close();
  assert.deepEqual(new Set(closed), new Set(["primary", sites.second.id]));
});

test("keeps account-primary delivery live until a site's independent pairing is actually connected", async () => {
  const owner = { id: "owner-1", status: "active", primaryConnectionMode: "legacy_shared", primaryConnectionId: "legacy-primary" };
  const site = { id: "site-1", ownerId: owner.id, keyId: "first", active: true, connectionMode: "account_primary", pendingDedicated: true };
  let dedicatedReady = false;
  let finalized = 0;
  const store = {
    siteByKeyId: () => site,
    ownerForSite: () => owner,
    userById: () => owner,
    finalizeDedicated: async () => { site.connectionMode = "dedicated"; site.pendingDedicated = false; finalized += 1; },
  };
  const primary = { ready: async () => true, close: async () => {} };
  const manager = new WorkspaceTransportManager({
    config: { stateDirectory: "C:/kiwe-key-state", transport: "baileys" }, store, primaryTransport: primary, history: null,
    transportFactory: async () => ({ ready: async () => dedicatedReady, close: async () => {} }),
  });
  assert.equal(await manager.forTenant(site.keyId), primary);
  assert.equal(finalized, 0);
  dedicatedReady = true;
  assert.notEqual(await manager.forTenant(site.keyId), primary);
  assert.equal(finalized, 1);
  assert.equal(site.connectionMode, "dedicated");
  await manager.close();
});
