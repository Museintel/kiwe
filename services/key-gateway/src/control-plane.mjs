import { createCipheriv, createDecipheriv, createHash, randomBytes, randomInt, randomUUID, timingSafeEqual } from "node:crypto";
import { mkdir, readFile, rename, writeFile } from "node:fs/promises";
import { dirname } from "node:path";

const clean = (value, maximum = 160) => String(value || "").replace(/[\u0000-\u001f\u007f]/g, " ").trim().slice(0, maximum);
const digest = (value) => createHash("sha256").update(String(value || ""), "utf8").digest("hex");

function normalizedPhone(value) {
  const digits = String(value || "").replace(/\D/g, "");
  return digits.length >= 8 && digits.length <= 15 ? digits : "";
}

function normalizedOrigin(value) {
  try {
    const url = new URL(String(value || "").trim());
    if (url.protocol !== "https:" || url.username || url.password || url.search || url.hash) return "";
    return `${url.protocol}//${url.host}`.replace(/\/$/, "");
  } catch { return ""; }
}

function slug(value) {
  return String(value || "site").toLowerCase().replace(/^www\./, "").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "").slice(0, 42) || "site";
}

function sameDigest(left, right) {
  const a = Buffer.from(String(left || ""));
  const b = Buffer.from(String(right || ""));
  return a.length === b.length && timingSafeEqual(a, b);
}

export class ControlPlaneStore {
  constructor(config, clock = () => Date.now()) {
    this.config = config;
    this.clock = clock;
    this.path = config.controlPlane.path;
    // Stable by design: changing this derivation would make the existing encrypted tenant data unreadable.
    this.key = createHash("sha256").update(`${config.setupToken}:phonekey-control-plane:${config.controlPlane.encryptionKey || "v1"}`, "utf8").digest();
    this.data = { version: 2, users: [], sites: [], sessions: [], registrations: [], reconnections: [], loginChallenges: [] };
    this.pending = Promise.resolve();
    this.persistTimer = undefined;
  }

  async load() {
    try {
      const parsed = JSON.parse(await readFile(this.path, "utf8"));
      if (parsed?.version === 1 || parsed?.version === 2) this.data = parsed;
    } catch {}
    this.migrate();
    this.prune();
    this.seedBootstrapOwner();
    this.seedConfiguredSites();
    this.syncTenants();
    await this.persist();
    return this;
  }

  migrate() {
    this.data.version = 2;
    this.data.users ||= [];
    this.data.sites ||= [];
    this.data.sessions ||= [];
    this.data.registrations ||= [];
    this.data.reconnections ||= [];
    this.data.loginChallenges ||= [];
    const bootstrapEmail = String(this.config.controlPlane.bootstrapOwnerEmail || "").trim().toLowerCase();
    for (const user of this.data.users) {
      user.role = user.email === bootstrapEmail ? "master" : (user.role || "owner");
      user.primaryPhone ||= "";
      user.primaryPhoneHash ||= "";
      user.primaryConnectionId ||= user.role === "master" ? "legacy-primary" : randomUUID();
      user.primaryConnectionMode ||= user.role === "master" ? "legacy_shared" : "dedicated";
      user.pendingPrimaryConnectionId ||= "";
      user.pendingPrimaryPhone ||= "";
      user.pendingPrimaryPhoneHash ||= "";
    }
    for (const site of this.data.sites) {
      if (typeof site.active !== "boolean") site.active = true;
      if (!site.connectionMode || site.connectionMode === "legacy_shared") site.connectionMode = "account_primary";
      if (typeof site.pendingDedicated !== "boolean") site.pendingDedicated = false;
      site.connectionPhone ||= "";
      if (site.label === "PhoneKey RC test site") site.label = "Key.kiwe RC test site";
    }
  }

  seedBootstrapOwner() {
    const email = String(this.config.controlPlane.bootstrapOwnerEmail || "").trim().toLowerCase();
    if (!email || this.data.users.some((user) => user.email === email)) return;
    this.data.users.push({ id: randomUUID(), email, role: "master", status: "pending_activation", createdAt: this.clock(), activatedAt: 0, primaryPhone: "", primaryPhoneHash: "", primaryConnectionId: "legacy-primary", primaryConnectionMode: "legacy_shared", pendingPrimaryConnectionId: "", pendingPrimaryPhone: "", pendingPrimaryPhoneHash: "" });
  }

  bootstrapUser() {
    const email = String(this.config.controlPlane.bootstrapOwnerEmail || "").trim().toLowerCase();
    return this.data.users.find((user) => user.email === email) || null;
  }

  seedConfiguredSites() {
    const owner = this.bootstrapUser();
    for (const [keyId, tenant] of Object.entries(this.config.tenants)) {
      if (this.data.sites.some((site) => site.keyId === keyId)) continue;
      this.data.sites.push({ id: randomUUID(), ownerId: owner?.id || "", keyId, label: clean(tenant.label || keyId, 80), origin: normalizedOrigin(tenant.sites?.[0]), secret: this.seal(String(tenant.secret)), connectionPhone: "", createdAt: this.clock(), lastAuthenticatedAt: 0, lastTransactionAt: 0, transactionCount: 0, lastStatus: "configured", active: true, connectionMode: "account_primary", pendingDedicated: false });
    }
  }

  syncTenants() {
    for (const site of this.data.sites) {
      const secret = this.open(site.secret);
      if (secret) this.config.tenants[site.keyId] = { secret, sites: [site.origin], label: site.label };
    }
  }

  seal(value) {
    const iv = randomBytes(12);
    const cipher = createCipheriv("aes-256-gcm", this.key, iv);
    const encrypted = Buffer.concat([cipher.update(String(value), "utf8"), cipher.final()]);
    return [iv, cipher.getAuthTag(), encrypted].map((part) => part.toString("base64url")).join(".");
  }

  open(value) {
    try {
      const [iv, tag, encrypted] = String(value || "").split(".").map((part) => Buffer.from(part, "base64url"));
      const decipher = createDecipheriv("aes-256-gcm", this.key, iv);
      decipher.setAuthTag(tag);
      return Buffer.concat([decipher.update(encrypted), decipher.final()]).toString("utf8");
    } catch { return ""; }
  }

  userPhone(user) { return this.open(user?.primaryPhone || ""); }
  pendingPrimaryPhone(user) { return this.open(user?.pendingPrimaryPhone || ""); }
  sitePhone(site) { return this.open(site?.connectionPhone || ""); }

  publicSite(site) {
    if (!site) return null;
    return { ...site, secret: undefined, connectionPhone: undefined, activePhone: this.sitePhone(site) };
  }

  publicUser(user) {
    if (!user) return null;
    return { id: user.id, role: user.role, status: user.status, createdAt: user.createdAt, activatedAt: user.activatedAt, phone: this.userPhone(user), primaryConnectionId: user.primaryConnectionId, primaryConnectionMode: user.primaryConnectionMode, pendingPrimaryConnectionId: user.pendingPrimaryConnectionId, pendingPrimaryPhone: this.pendingPrimaryPhone(user) };
  }

  userById(id) { return this.data.users.find((entry) => entry.id === id) || null; }

  userByPhone(value) {
    const phone = normalizedPhone(value);
    if (!phone) return null;
    const hash = digest(phone);
    return this.data.users.find((entry) => entry.primaryPhoneHash === hash) || null;
  }

  prune() {
    const now = this.clock();
    this.data.sessions = this.data.sessions.filter((entry) => entry.expiresAt > now);
    this.data.registrations = this.data.registrations.filter((entry) => entry.expiresAt > now);
    this.data.reconnections = this.data.reconnections.filter((entry) => entry.expiresAt > now);
    this.data.loginChallenges = this.data.loginChallenges.filter((entry) => entry.expiresAt > now && entry.attempts < 6);
  }

  schedulePersist() {
    if (this.persistTimer) return;
    this.persistTimer = setTimeout(() => { this.persistTimer = undefined; this.persist().catch(() => {}); }, 1500);
    this.persistTimer.unref?.();
  }

  async persist() {
    await mkdir(dirname(this.path), { recursive: true, mode: 0o700 });
    const temporary = `${this.path}.tmp`;
    const snapshot = `${JSON.stringify(this.data)}\n`;
    this.pending = this.pending.then(async () => { await writeFile(temporary, snapshot, { encoding: "utf8", mode: 0o600 }); await rename(temporary, this.path); });
    await this.pending;
  }

  async linkBootstrapPrimaryPhone(value) {
    const user = this.bootstrapUser();
    const phone = normalizedPhone(value);
    if (!user || !phone || user.primaryPhoneHash) return this.publicUser(user);
    user.primaryPhone = this.seal(phone);
    user.primaryPhoneHash = digest(phone);
    user.status = "active";
    user.activatedAt ||= this.clock();
    for (const site of this.data.sites) if (!site.ownerId) site.ownerId = user.id;
    await this.persist();
    return this.publicUser(user);
  }

  async beginRegistration(value) {
    if (!this.config.controlPlane.registrationEnabled) throw new Error("Registration is not available.");
    const phone = normalizedPhone(value);
    if (!phone) throw new Error("Enter a valid WhatsApp number including country code.");
    if (this.userByPhone(phone)) throw new Error("This WhatsApp number already owns a Key.kiwe account.");
    this.prune();
    this.data.registrations = this.data.registrations.filter((entry) => entry.phoneHash !== digest(phone));
    const token = randomBytes(32).toString("base64url");
    const registration = { id: randomUUID(), tokenHash: digest(token), phone: this.seal(phone), phoneHash: digest(phone), connectionId: randomUUID(), createdAt: this.clock(), expiresAt: this.clock() + 15 * 60 * 1000 };
    this.data.registrations.push(registration);
    await this.persist();
    return { token, registration: { ...registration, phone, tokenHash: undefined } };
  }

  registration(token) {
    this.prune();
    const entry = this.data.registrations.find((item) => item.tokenHash === digest(token));
    return entry ? { ...entry, phone: this.open(entry.phone), tokenHash: undefined } : null;
  }

  async completeRegistration(token, connectedValue) {
    const registration = this.registration(token);
    const connected = normalizedPhone(connectedValue);
    if (!registration || !connected) throw new Error("The pairing session expired. Start again.");
    if (!sameDigest(registration.phoneHash, digest(connected))) throw new Error("The scanned WhatsApp number does not match the number entered.");
    if (this.userByPhone(connected)) throw new Error("This WhatsApp number already owns an account.");
    const user = { id: randomUUID(), role: "owner", status: "active", createdAt: this.clock(), activatedAt: this.clock(), primaryPhone: this.seal(connected), primaryPhoneHash: digest(connected), primaryConnectionId: registration.connectionId, primaryConnectionMode: "dedicated", pendingPrimaryConnectionId: "", pendingPrimaryPhone: "", pendingPrimaryPhoneHash: "" };
    this.data.users.push(user);
    this.data.registrations = this.data.registrations.filter((entry) => entry.id !== registration.id);
    await this.persist();
    return this.publicUser(user);
  }

  async beginReconnect(value) {
    const phone = normalizedPhone(value);
    const user = this.userByPhone(phone);
    if (!user || user.status !== "active") return null;
    this.prune();
    const token = randomBytes(32).toString("base64url");
    user.pendingPrimaryConnectionId = randomUUID();
    user.pendingPrimaryPhone = this.seal(phone);
    user.pendingPrimaryPhoneHash = digest(phone);
    this.data.reconnections = this.data.reconnections.filter((entry) => entry.userId !== user.id);
    this.data.reconnections.push({ tokenHash: digest(token), userId: user.id, connectionId: user.pendingPrimaryConnectionId, phoneHash: digest(phone), createdAt: this.clock(), expiresAt: this.clock() + 15 * 60 * 1000 });
    this.data.loginChallenges = this.data.loginChallenges.filter((entry) => entry.userId !== user.id);
    await this.persist();
    return { token, reconnection: { user: this.publicUser(user), connectionId: user.pendingPrimaryConnectionId } };
  }

  reconnection(token) {
    this.prune();
    const entry = this.data.reconnections.find((item) => item.tokenHash === digest(token));
    const user = entry ? this.userById(entry.userId) : null;
    return entry && user?.status === "active" ? { ...entry, tokenHash: undefined, user: this.publicUser(user) } : null;
  }

  async completeReconnect(token, connectedValue) {
    const reconnect = this.reconnection(token);
    const connected = normalizedPhone(connectedValue);
    if (!reconnect || !connected) throw new Error("The reconnection session expired. Start again.");
    if (!sameDigest(reconnect.phoneHash, digest(connected))) throw new Error("The scanned WhatsApp number does not match this Key.kiwe account.");
    const user = this.userById(reconnect.userId);
    if (!user?.pendingPrimaryConnectionId || user.pendingPrimaryConnectionId !== reconnect.connectionId) throw new Error("The reconnection session is no longer active.");
    user.primaryPhone = this.seal(connected);
    user.primaryPhoneHash = digest(connected);
    user.primaryConnectionId = user.pendingPrimaryConnectionId;
    user.primaryConnectionMode = "dedicated";
    user.pendingPrimaryConnectionId = "";
    user.pendingPrimaryPhone = "";
    user.pendingPrimaryPhoneHash = "";
    this.data.reconnections = this.data.reconnections.filter((entry) => entry.userId !== user.id);
    await this.persist();
    return this.publicUser(user);
  }

  async beginLogin(value) {
    const phone = normalizedPhone(value);
    const user = this.userByPhone(phone);
    if (!user || user.status !== "active") return null;
    const now = this.clock();
    const recent = this.data.loginChallenges.find((entry) => entry.userId === user.id && entry.expiresAt > now && now - entry.sentAt < 45_000);
    if (recent) throw Object.assign(new Error("Wait before requesting another code."), { status: 429, retryAfter: Math.ceil((45_000 - (now - recent.sentAt)) / 1000) });
    const token = randomBytes(32).toString("base64url");
    const code = String(randomInt(0, 1_000_000)).padStart(6, "0");
    this.data.loginChallenges = this.data.loginChallenges.filter((entry) => entry.userId !== user.id);
    this.data.loginChallenges.push({ tokenHash: digest(token), userId: user.id, codeHash: digest(`${token}:${code}`), sentAt: now, expiresAt: now + 5 * 60 * 1000, attempts: 0 });
    await this.persist();
    return { token, code, user: this.publicUser(user) };
  }

  async verifyLogin(token, code) {
    this.prune();
    const challenge = this.data.loginChallenges.find((entry) => entry.tokenHash === digest(token));
    if (!challenge) return null;
    challenge.attempts += 1;
    const valid = /^\d{6}$/.test(String(code || "")) && sameDigest(challenge.codeHash, digest(`${token}:${code}`));
    if (!valid) { await this.persist(); return null; }
    const user = this.userById(challenge.userId);
    this.data.loginChallenges = this.data.loginChallenges.filter((entry) => entry !== challenge);
    await this.persist();
    return user?.status === "active" ? this.publicUser(user) : null;
  }

  async createSession(userId) {
    this.prune();
    const token = randomBytes(32).toString("base64url");
    const session = { tokenHash: digest(token), userId, csrf: randomBytes(24).toString("base64url"), createdAt: this.clock(), expiresAt: this.clock() + this.config.controlPlane.sessionHours * 60 * 60 * 1000 };
    this.data.sessions.push(session);
    await this.persist();
    return { token, csrf: session.csrf, expiresAt: session.expiresAt };
  }

  session(token) {
    this.prune();
    const session = this.data.sessions.find((entry) => entry.tokenHash === digest(token));
    if (!session) return null;
    const user = this.userById(session.userId);
    return user?.status === "active" ? { session, user: this.publicUser(user) } : null;
  }

  async logout(token) { this.data.sessions = this.data.sessions.filter((entry) => entry.tokenHash !== digest(token)); await this.persist(); }
  sitesFor(userId) { return this.data.sites.filter((site) => site.ownerId === userId).map((site) => this.publicSite(site)); }
  siteByKeyId(keyId) { return this.data.sites.find((site) => site.keyId === keyId) || null; }
  siteForOwner(userId, siteId) { return this.data.sites.find((site) => site.id === siteId && site.ownerId === userId) || null; }
  ownerForTenant(keyId) { return this.data.sites.find((site) => site.keyId === keyId)?.ownerId || ""; }
  ownerForSite(site) { return this.userById(site?.ownerId); }
  firstTenantForOwner(userId) { return this.data.sites.find((site) => site.ownerId === userId)?.keyId || ""; }

  async createSite(userId, labelValue, originValue) {
    const origin = normalizedOrigin(originValue);
    if (!origin) throw new Error("Enter the HTTPS origin of the website.");
    if (this.data.sites.some((site) => site.origin === origin)) throw new Error("This website is already connected.");
    const label = clean(labelValue, 80) || new URL(origin).hostname;
    const keyId = `${slug(new URL(origin).hostname)}-${randomBytes(3).toString("hex")}`;
    const secret = randomBytes(36).toString("base64url");
    const site = { id: randomUUID(), ownerId: userId, keyId, label, origin, secret: this.seal(secret), connectionPhone: "", createdAt: this.clock(), lastAuthenticatedAt: 0, lastTransactionAt: 0, transactionCount: 0, lastStatus: "pending_first_request", active: true, connectionMode: "account_primary", pendingDedicated: false };
    this.data.sites.push(site);
    this.config.tenants[keyId] = { secret, sites: [origin], label };
    await this.persist();
    return { site: this.publicSite(site), secret };
  }

  async setSiteActive(userId, siteId, active) {
    const site = this.siteForOwner(userId, siteId);
    if (!site) throw new Error("Website not found.");
    site.active = Boolean(active);
    site.lastStatus = site.active ? (site.lastAuthenticatedAt ? "active" : "awaiting_connection") : "inactive";
    await this.persist();
    return this.publicSite(site);
  }

  async prepareDedicated(userId, siteId) {
    const site = this.siteForOwner(userId, siteId);
    if (!site) throw new Error("Website not found.");
    site.pendingDedicated = true;
    site.active = true;
    site.lastStatus = "pairing_dedicated_number";
    await this.persist();
    return this.publicSite(site);
  }

  async finalizeDedicated(siteId, connectedValue = "") {
    const site = this.data.sites.find((entry) => entry.id === siteId);
    if (!site) return null;
    const connected = normalizedPhone(connectedValue);
    site.connectionMode = "dedicated";
    site.pendingDedicated = false;
    site.active = true;
    site.lastStatus = "connected";
    if (connected) site.connectionPhone = this.seal(connected);
    await this.persist();
    return this.publicSite(site);
  }

  async inheritPrimary(userId, siteId) {
    const site = this.siteForOwner(userId, siteId);
    if (!site) throw new Error("Website not found.");
    site.connectionMode = "account_primary";
    site.pendingDedicated = false;
    site.connectionPhone = "";
    site.lastStatus = "connected_to_primary";
    await this.persist();
    return this.publicSite(site);
  }

  async preparePrimaryReplacement(userId, value) {
    const user = this.userById(userId);
    const phone = normalizedPhone(value);
    if (!user || !phone) throw new Error("Enter a valid WhatsApp number including country code.");
    const existing = this.userByPhone(phone);
    if (existing && existing.id !== userId) throw new Error("That number already owns another account.");
    user.pendingPrimaryConnectionId = randomUUID();
    user.pendingPrimaryPhone = this.seal(phone);
    user.pendingPrimaryPhoneHash = digest(phone);
    await this.persist();
    return this.publicUser(user);
  }

  async finalizePrimaryReplacement(userId, connectedValue) {
    const user = this.userById(userId);
    const connected = normalizedPhone(connectedValue);
    if (!user?.pendingPrimaryConnectionId || !connected || !sameDigest(user.pendingPrimaryPhoneHash, digest(connected))) return null;
    user.primaryPhone = this.seal(connected);
    user.primaryPhoneHash = digest(connected);
    user.primaryConnectionId = user.pendingPrimaryConnectionId;
    user.primaryConnectionMode = "dedicated";
    user.pendingPrimaryConnectionId = "";
    user.pendingPrimaryPhone = "";
    user.pendingPrimaryPhoneHash = "";
    await this.persist();
    return this.publicUser(user);
  }

  networkSummary(masterId) {
    const master = this.userById(masterId);
    if (master?.role !== "master") throw Object.assign(new Error("Master access required."), { status: 403 });
    return this.data.users.map((user) => ({ ...this.publicUser(user), sites: this.sitesFor(user.id), lastTransactionAt: Math.max(0, ...this.data.sites.filter((site) => site.ownerId === user.id).map((site) => Number(site.lastTransactionAt || 0))) }));
  }

  async setUserStatus(masterId, userId, status) {
    const master = this.userById(masterId);
    const user = this.userById(userId);
    if (master?.role !== "master" || !user || user.id === master.id) throw Object.assign(new Error("This account action is not allowed."), { status: 403 });
    user.status = status === "active" ? "active" : "suspended";
    if (user.status !== "active") this.data.sessions = this.data.sessions.filter((entry) => entry.userId !== user.id);
    await this.persist();
    return this.publicUser(user);
  }

  recordTransaction(keyId, status = "authenticated", at = this.clock()) {
    const site = this.data.sites.find((entry) => entry.keyId === keyId);
    if (!site) return;
    site.lastAuthenticatedAt = at;
    site.lastTransactionAt = at;
    site.transactionCount = Number(site.transactionCount || 0) + 1;
    site.lastStatus = clean(status, 48) || "authenticated";
    this.schedulePersist();
  }

  hydrateActivity(history) {
    for (const site of this.data.sites) {
      const latest = history?.list({ tenant: site.keyId, limit: 1 })?.[0];
      if (!latest || Number(latest.at) <= Number(site.lastTransactionAt || 0)) continue;
      site.lastTransactionAt = Number(latest.at);
      site.lastAuthenticatedAt = Number(latest.at);
      site.lastStatus = latest.status || "active";
    }
    this.schedulePersist();
  }

  async close() {
    if (this.persistTimer) clearTimeout(this.persistTimer);
    this.persistTimer = undefined;
    await this.persist();
  }
}

export async function createControlPlane(config, clock) {
  if (!config.controlPlane.enabled) return null;
  return new ControlPlaneStore(config, clock).load();
}
