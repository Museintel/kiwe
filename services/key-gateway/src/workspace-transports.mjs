import { mkdir, rename } from "node:fs/promises";
import { join, relative, resolve } from "node:path";
import { createBaileysTransport } from "./transports/baileys.mjs";
import { createEvolutionTransport } from "./transports/evolution.mjs";

export class WorkspaceTransportManager {
  constructor({ config, store, primaryTransport, history, clock = () => Date.now(), idleMinutes = 30, transportFactory = null }) {
    this.config = config;
    this.store = store;
    this.history = history;
    this.clock = clock;
    this.idleMs = idleMinutes * 60 * 1000;
    this.transportFactory = transportFactory;
    this.primaryTransport = primaryTransport;
    this.entries = new Map();
    this.ownerEntries = new Map();
    this.sweeper = setInterval(() => this.sweep().catch(() => {}), Math.min(this.idleMs, 5 * 60 * 1000));
    this.sweeper.unref?.();
  }

  stateDirectory(siteId) {
    return join(resolve(this.config.stateDirectory), "connections", String(siteId));
  }

  ownerStateDirectory(connectionId) {
    return join(resolve(this.config.stateDirectory), "accounts", String(connectionId));
  }

  scopedConfig(site) {
    const scoped = { ...this.config, stateDirectory: this.stateDirectory(site.id) };
    if (this.config.transport === "evolution") scoped.evolution = { ...this.config.evolution, instance: `${this.config.evolution.instance}-${String(site.id).slice(0, 12)}` };
    return scoped;
  }

  ownerScopedConfig(connectionId) {
    const scoped = { ...this.config, stateDirectory: this.ownerStateDirectory(connectionId) };
    if (this.config.transport === "evolution") scoped.evolution = { ...this.config.evolution, instance: `${this.config.evolution.instance}-account-${String(connectionId).slice(0, 12)}` };
    return scoped;
  }

  async createOwner(connectionId, ownerId = "registration") {
    const onEvent = (event) => this.history?.record({ tenant: `account:${ownerId}`, ...event });
    const scoped = this.ownerScopedConfig(connectionId);
    if (this.transportFactory) return this.transportFactory(scoped, onEvent, { id: connectionId, ownerId, kind: "account" });
    return scoped.transport === "evolution" ? createEvolutionTransport(scoped) : createBaileysTransport(scoped, onEvent);
  }

  async ownerConnection(connectionId, ownerId = "registration") {
    if (!connectionId) return null;
    const current = this.ownerEntries.get(connectionId);
    if (current) {
      current.lastUsedAt = this.clock();
      return current.transport;
    }
    const transport = await this.createOwner(connectionId, ownerId);
    this.ownerEntries.set(connectionId, { transport, lastUsedAt: this.clock() });
    return transport;
  }

  async ownerTransport(userValue) {
    const user = this.store.userById(userValue?.id) || userValue;
    if (!user || user.status !== "active") return null;
    if (user.primaryConnectionMode === "legacy_shared") return this.primaryTransport;
    return this.ownerConnection(user.primaryConnectionId, user.id);
  }

  async registrationTransport(registration) {
    return this.ownerConnection(registration.connectionId, `registration:${registration.id}`);
  }

  async reconnectTransport(reconnection) {
    return this.ownerConnection(reconnection.connectionId, `reconnect:${reconnection.user.id}`);
  }

  async pendingPrimaryTransport(userValue) {
    const user = this.store.userById(userValue?.id) || userValue;
    return user?.pendingPrimaryConnectionId ? this.ownerConnection(user.pendingPrimaryConnectionId, user.id) : null;
  }

  async sendLoginCode(userValue, code) {
    const user = this.store.userById(userValue?.id) || userValue;
    const transport = await this.ownerTransport(user);
    const phone = this.store.userPhone(user);
    if (!transport || !phone || !(await transport.ready())) throw Object.assign(new Error("Your primary WhatsApp connection is offline. Reconnect it before signing in."), { status: 503 });
    return transport.sendText(phone, `Your Key.kiwe sign-in code is ${code}. It expires in 5 minutes. Never share this code.`);
  }

  async create(site) {
    const onEvent = (event) => this.history?.record({ tenant: site.keyId, ...event });
    const scoped = this.scopedConfig(site);
    if (this.transportFactory) return this.transportFactory(scoped, onEvent, site);
    return scoped.transport === "evolution" ? createEvolutionTransport(scoped) : createBaileysTransport(scoped, onEvent);
  }

  async dedicated(site) {
    const current = this.entries.get(site.id);
    if (current) {
      current.lastUsedAt = this.clock();
      return current.transport;
    }
    const transport = await this.create(site);
    this.entries.set(site.id, { transport, lastUsedAt: this.clock() });
    return transport;
  }

  async forTenant(keyId) {
    const site = this.store.siteByKeyId(keyId);
    if (!site || !site.active) return null;
    const owner = this.store.ownerForSite(site);
    if (!owner || owner.status !== "active") return null;
    if (site.connectionMode === "account_primary" && !site.pendingDedicated) return this.ownerTransport(owner);
    const transport = await this.dedicated(site);
    if (site.pendingDedicated && await transport.ready().catch(() => false)) {
      await this.store.finalizeDedicated(site.id, transport.setup?.().connectedPhone || "");
      return transport;
    }
    return site.connectionMode === "account_primary" ? this.ownerTransport(owner) : transport;
  }

  async forDisplay(siteValue) {
    const site = this.store.siteByKeyId(siteValue.keyId) || siteValue;
    if (!site.active) return null;
    const owner = this.store.ownerForSite(site);
    if (!owner || owner.status !== "active") return null;
    if (site.connectionMode === "account_primary" && !site.pendingDedicated) return this.ownerTransport(owner);
    const transport = await this.dedicated(site);
    if (site.pendingDedicated && await transport.ready().catch(() => false)) await this.store.finalizeDedicated(site.id, transport.setup?.().connectedPhone || "");
    return transport;
  }

  async beginDedicated(userId, siteId) {
    const site = await this.store.prepareDedicated(userId, siteId);
    return this.dedicated(site);
  }

  async resetDirectory(site) {
    const entry = this.entries.get(site.id);
    await entry?.transport?.close();
    this.entries.delete(site.id);
    const stateDirectory = resolve(this.stateDirectory(site.id));
    const connectionsRoot = resolve(join(this.config.stateDirectory, "connections"));
    const rel = relative(connectionsRoot, stateDirectory);
    if (!rel || rel.startsWith("..")) throw new Error("Unsafe Key.kiwe site connection directory.");
    try {
      await rename(stateDirectory, `${stateDirectory}.session-reset-${this.clock()}`);
    } catch (error) {
      if (error?.code !== "ENOENT") throw error;
    }
    await mkdir(stateDirectory, { recursive: true, mode: 0o700 });
  }

  async resetSite(userId, siteId) {
    let site = this.store.siteForOwner(userId, siteId);
    if (!site) throw new Error("Website not found.");
    if (site.connectionMode === "account_primary") site = await this.store.prepareDedicated(userId, siteId);
    await this.resetDirectory(site);
    return this.dedicated(site);
  }

  async inheritPrimary(userId, siteId) {
    const site = this.store.siteForOwner(userId, siteId);
    if (!site) throw new Error("Website not found.");
    const entry = this.entries.get(site.id);
    await entry?.transport?.close();
    this.entries.delete(site.id);
    return this.store.inheritPrimary(userId, siteId);
  }

  async resetPendingPrimary(userId) {
    const user = this.store.userById(userId);
    if (!user?.pendingPrimaryConnectionId) return null;
    const connectionId = user.pendingPrimaryConnectionId;
    const entry = this.ownerEntries.get(connectionId);
    await entry?.transport?.close();
    this.ownerEntries.delete(connectionId);
    const stateDirectory = resolve(this.ownerStateDirectory(connectionId));
    const accountsRoot = resolve(join(this.config.stateDirectory, "accounts"));
    const rel = relative(accountsRoot, stateDirectory);
    if (!rel || rel.startsWith("..")) throw new Error("Unsafe Key account connection directory.");
    try { await rename(stateDirectory, `${stateDirectory}.session-reset-${this.clock()}`); } catch (error) { if (error?.code !== "ENOENT") throw error; }
    await mkdir(stateDirectory, { recursive: true, mode: 0o700 });
    return this.pendingPrimaryTransport(user);
  }

  async sweep() {
    const threshold = this.clock() - this.idleMs;
    for (const [siteId, entry] of this.entries) {
      if (entry.lastUsedAt >= threshold) continue;
      await entry.transport.close();
      this.entries.delete(siteId);
    }
    for (const [connectionId, entry] of this.ownerEntries) {
      if (entry.lastUsedAt >= threshold) continue;
      await entry.transport.close();
      this.ownerEntries.delete(connectionId);
    }
  }

  async close() {
    clearInterval(this.sweeper);
    await Promise.allSettled([this.primaryTransport, ...this.entries.values(), ...this.ownerEntries.values()].map((entry) => (entry.transport || entry).close()));
    this.entries.clear();
    this.ownerEntries.clear();
  }
}
