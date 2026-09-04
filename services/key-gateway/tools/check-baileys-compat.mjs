import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const root = new URL("../", import.meta.url);
const manifest = JSON.parse(await readFile(new URL("package.json", root), "utf8"));
const lock = JSON.parse(await readFile(new URL("package-lock.json", root), "utf8"));
const transport = await readFile(new URL("src/transports/baileys.mjs", root), "utf8");
const pinned = manifest.dependencies?.["@whiskeysockets/baileys"];
const locked = lock.packages?.["node_modules/@whiskeysockets/baileys"]?.version;
const declared = transport.match(/const BAILEYS_VERSION = "([^"]+)"/)?.[1];

assert.match(pinned || "", /^7\.0\.0-rc\d+$/, "Key.kiwe must pin an audited Baileys 7 release candidate exactly.");
assert.equal(locked, pinned, "package-lock.json drifted from the audited Baileys version.");
assert.equal(declared, pinned, "The setup diagnostic version drifted from package.json.");
assert.match(transport, /fetchLatestWaWebVersion/, "Key.kiwe must negotiate the current WhatsApp Web protocol version.");

if (process.env.KIWE_KEY_SKIP_UPSTREAM_VERSION_CHECK !== "1") {
  const response = await fetch("https://registry.npmjs.org/@whiskeysockets%2fbaileys", {
    headers: { accept: "application/vnd.npm.install-v1+json" },
    signal: AbortSignal.timeout(10000),
  });
  assert.equal(response.ok, true, `Unable to read the Baileys npm registry metadata (${response.status}).`);
  const metadata = await response.json();
  const upstream = metadata["dist-tags"]?.latest;
  assert.equal(upstream, pinned, `Baileys latest moved to ${upstream}; review compatibility before updating Key.kiwe.`);
}

console.log(`PASS Key.kiwe Baileys compatibility gate (${pinned})`);
