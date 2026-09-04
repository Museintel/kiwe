import { randomBytes } from "node:crypto";
import { writeFileSync } from "node:fs";

const args = Object.fromEntries(process.argv.slice(2).map((value, index, all) => value.startsWith("--") ? [value.slice(2), all[index + 1]] : null).filter(Boolean));
const keyId = String(args["key-id"] || "");
const site = String(args.site || "").replace(/\/$/, "");
const label = String(args.label || keyId).slice(0, 80);
if (!/^[a-z0-9][a-z0-9._-]{2,63}$/i.test(keyId) || !/^https:\/\/[a-z0-9.-]+(?::\d+)?$/i.test(site)) {
  throw new Error("Usage: node tools/create-runtime-config.mjs --key-id <id> --site https://example.com --label <name>");
}
const secret = () => randomBytes(32).toString("base64url");
const config = {
  port: 3000,
  transport: "baileys",
  stateDirectory: "../../.kiwe-key-state",
  setupToken: secret(),
  memoryLimitMb: 160,
  rcObservability: {
    enabled: true,
    captureInboundText: true,
    captureOutboundText: true,
    retentionDays: 14,
    maxEvents: 3000,
    path: "../../.kiwe-key-state/rc-history.json",
    key: secret(),
    tenant: keyId,
  },
  tenants: { [keyId]: { secret: secret(), sites: [site], label } },
  evolution: { baseUrl: "http://evolution:8080", instance: "kiwe-key", apiKey: "" },
};
writeFileSync("runtime-config.json", `${JSON.stringify(config, null, 2)}\n`, { encoding: "utf8", mode: 0o600, flag: args.rotate ? "w" : "wx" });
console.log(`${args.rotate ? "Rotated" : "Created"} ignored runtime-config.json with fresh setup, history, and tenant secrets.`);
