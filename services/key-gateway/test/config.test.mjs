import assert from "node:assert/strict";
import { test } from "node:test";
import { loadConfig } from "../src/config.mjs";

test("loads host-safe base64url tenants without leaking malformed configuration", () => {
  const tenants = { client: { secret: "s".repeat(32), sites: ["https://example.test"], label: "Example" } };
  const environment = {
    KIWE_KEY_TRANSPORT: "evolution",
    KIWE_KEY_TENANTS_BASE64URL: Buffer.from(JSON.stringify(tenants)).toString("base64url"),
  };

  assert.deepEqual(loadConfig(environment).tenants, tenants);
  assert.throws(
    () => loadConfig({ ...environment, KIWE_KEY_TENANTS_BASE64URL: Buffer.from('{"secret":"do-not-log"').toString("base64url") }),
    (error) => error.message === "Key.kiwe tenant environment configuration is invalid JSON."
      && !error.message.includes("do-not-log"),
  );
});
