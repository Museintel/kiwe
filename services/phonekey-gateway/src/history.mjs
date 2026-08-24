import { createCipheriv, createDecipheriv, createHmac, randomBytes, randomUUID } from "node:crypto";
import { mkdir, readFile, rename, writeFile } from "node:fs/promises";
import { dirname } from "node:path";

const allowedStatuses = new Set([
  "requested", "accepted", "delivered", "failed", "fallback_required",
  "email_fallback_accepted", "email_fallback_failed", "received",
]);

const clean = (value, maximum = 160) => String(value || "").replace(/[\u0000-\u001f\u007f]/g, " ").trim().slice(0, maximum);

export class RcHistoryStore {
  constructor(config, clock = () => Date.now()) {
    this.enabled = Boolean(config.enabled);
    this.captureInboundText = Boolean(config.captureInboundText);
    this.path = config.path;
    this.maximum = config.maxEvents;
    this.retentionMs = config.retentionDays * 24 * 60 * 60 * 1000;
    this.key = Buffer.from(config.key, "base64url");
    this.clock = clock;
    this.entries = [];
    this.pending = Promise.resolve();
  }

  async load() {
    if (!this.enabled) return this;
    try {
      const parsed = JSON.parse(await readFile(this.path, "utf8"));
      this.entries = Array.isArray(parsed) ? parsed : [];
    } catch {}
    this.prune();
    return this;
  }

  hash(value) {
    return createHmac("sha256", this.key).update(String(value || "")).digest("hex").slice(0, 24);
  }

  seal(value) {
    if (!value) return "";
    const iv = randomBytes(12);
    const cipher = createCipheriv("aes-256-gcm", this.key, iv);
    const encrypted = Buffer.concat([cipher.update(value, "utf8"), cipher.final()]);
    return [iv, cipher.getAuthTag(), encrypted].map((item) => item.toString("base64url")).join(".");
  }

  open(value) {
    try {
      const [iv, tag, encrypted] = String(value || "").split(".").map((item) => Buffer.from(item, "base64url"));
      const decipher = createDecipheriv("aes-256-gcm", this.key, iv);
      decipher.setAuthTag(tag);
      return Buffer.concat([decipher.update(encrypted), decipher.final()]).toString("utf8");
    } catch {
      return "[unavailable]";
    }
  }

  prune() {
    const threshold = this.clock() - this.retentionMs;
    this.entries = this.entries.filter((entry) => Number(entry.at) >= threshold).slice(-this.maximum);
  }

  async record(event = {}) {
    if (!this.enabled) return;
    const phone = String(event.phone || "").replace(/\D/g, "");
    const direction = event.direction === "inbound" ? "inbound" : "outbound";
    const contentAllowed = direction === "inbound" && this.captureInboundText;
    const entry = {
      id: randomUUID(),
      at: this.clock(),
      tenant: clean(event.tenant, 64),
      direction,
      channel: clean(event.channel || "whatsapp", 24),
      status: allowedStatuses.has(event.status) ? event.status : "failed",
      targetHash: phone ? this.hash(phone) : "",
      targetLast4: phone ? phone.slice(-4) : "",
      requestHash: event.requestId ? this.hash(event.requestId) : "",
      receiptHash: event.receipt ? this.hash(event.receipt) : "",
      summary: clean(event.summary, 240),
      error: clean(event.error, 160),
      content: contentAllowed ? this.seal(clean(event.content, 1000)) : "",
    };
    this.entries.push(entry);
    this.prune();
    this.pending = this.pending.then(() => this.persist()).catch(() => {});
    await this.pending;
  }

  async persist() {
    await mkdir(dirname(this.path), { recursive: true, mode: 0o700 });
    const temporary = `${this.path}.tmp`;
    await writeFile(temporary, `${JSON.stringify(this.entries)}\n`, { encoding: "utf8", mode: 0o600 });
    await rename(temporary, this.path);
  }

  list({ tenant = "", limit = 200 } = {}) {
    this.prune();
    return this.entries
      .filter((entry) => !tenant || entry.tenant === tenant)
      .slice(-Math.max(1, Math.min(500, Number(limit) || 200)))
      .reverse()
      .map((entry) => ({ ...entry, content: entry.content ? this.open(entry.content) : "" }));
  }

  async close() {
    await this.pending;
  }
}

export async function createHistoryStore(config, clock) {
  return new RcHistoryStore(config.rcObservability, clock).load();
}
