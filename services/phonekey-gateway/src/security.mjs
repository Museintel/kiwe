import { createHash, createHmac, timingSafeEqual } from "node:crypto";

export function signature(secret, timestamp, nonce, rawBody) {
  return createHmac("sha256", secret).update(`${timestamp}.${nonce}.${rawBody}`, "utf8").digest("hex");
}

export function verifySignature(secret, timestamp, nonce, rawBody, supplied) {
  if (!/^[a-f0-9]{64}$/i.test(String(supplied || ""))) return false;
  const expected = Buffer.from(signature(secret, timestamp, nonce, rawBody), "hex");
  const received = Buffer.from(String(supplied), "hex");
  return expected.length === received.length && timingSafeEqual(expected, received);
}

export function opaque(value) {
  return createHash("sha256").update(String(value), "utf8").digest("hex").slice(0, 16);
}

export function normalizePhone(value) {
  const digits = String(value || "").replace(/\D/g, "");
  return /^\d{8,15}$/.test(digits) ? digits : "";
}

export class ExpiringSet {
  constructor(limit = 5000) {
    this.limit = limit;
    this.values = new Map();
  }
  has(key, now = Date.now()) {
    this.prune(now);
    const expires = this.values.get(key);
    return Boolean(expires && expires > now);
  }
  add(key, ttlMs, now = Date.now()) {
    this.prune(now);
    this.values.set(key, now + ttlMs);
    if (this.values.size > this.limit) this.values.delete(this.values.keys().next().value);
  }
  prune(now = Date.now()) {
    for (const [key, expires] of this.values) if (expires <= now) this.values.delete(key);
  }
}

export class SlidingLimiter {
  constructor(limit, windowMs, capacity = 5000) {
    this.limit = limit;
    this.windowMs = windowMs;
    this.capacity = capacity;
    this.values = new Map();
  }
  allow(key, now = Date.now()) {
    const floor = now - this.windowMs;
    const recent = (this.values.get(key) || []).filter((item) => item > floor);
    if (recent.length >= this.limit) return false;
    recent.push(now);
    this.values.set(key, recent);
    if (this.values.size > this.capacity) this.values.delete(this.values.keys().next().value);
    return true;
  }
}
