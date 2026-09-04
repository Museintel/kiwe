import assert from "node:assert/strict";
import { readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, test } from "node:test";
import { randomUUID } from "node:crypto";
import { RcHistoryStore } from "../src/history.mjs";

const paths = [];
afterEach(async () => { while (paths.length) await rm(paths.pop(), { force: true }); });

test("encrypts inbound RC text and permanently redacts outbound OTP content", async () => {
  const path = join(tmpdir(), `key-history-${randomUUID()}.json`);
  paths.push(path);
  const store = await new RcHistoryStore({ enabled: true, captureInboundText: true, path, maxEvents: 100, retentionDays: 14, key: Buffer.alloc(32, 7).toString("base64url") }).load();
  await store.record({ tenant: "client", direction: "inbound", phone: "+919876543210", status: "received", content: "Please resend my code" });
  await store.record({ tenant: "client", direction: "outbound", phone: "+919876543210", status: "accepted", content: "OTP 123456", summary: "Verification message accepted" });
  const raw = await readFile(path, "utf8");
  assert.equal(raw.includes("Please resend my code"), false);
  assert.equal(raw.includes("123456"), false);
  const entries = store.list();
  assert.equal(entries.find((entry) => entry.direction === "inbound").content, "Please resend my code");
  assert.equal(entries.find((entry) => entry.direction === "outbound").content, "");
});

test("stores consented outbound RC notification text only as ciphertext", async () => {
  const path = join(tmpdir(), `key-history-${randomUUID()}.json`);
  paths.push(path);
  const store = await new RcHistoryStore({ enabled: true, captureInboundText: false, captureOutboundText: true, path, maxEvents: 100, retentionDays: 14, key: Buffer.alloc(32, 9).toString("base64url") }).load();
  await store.record({ tenant: "client", direction: "outbound", phone: "+919876543210", status: "accepted", content: "Order 42 is ready", allowContent: true, summary: "order_status" });
  assert.equal((await readFile(path, "utf8")).includes("Order 42 is ready"), false);
  assert.equal(store.list()[0].content, "Order 42 is ready");
});
