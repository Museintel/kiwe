import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

function integer(value, fallback, minimum, maximum) {
  const parsed = Number.parseInt(String(value ?? ""), 10);
  return Number.isFinite(parsed) ? Math.max(minimum, Math.min(maximum, parsed)) : fallback;
}

function readJson(path, required = false) {
  if (!path) return {};
  if (!existsSync(resolve(path)) && !required) return {};
  try {
    return JSON.parse(readFileSync(resolve(path), "utf8"));
  } catch (error) {
    throw new Error(`PhoneKey runtime configuration could not be read: ${error.message}`);
  }
}

export function loadConfig(environment = process.env) {
  const file = readJson(environment.PHONEKEY_CONFIG_FILE || "runtime-config.json", Boolean(environment.PHONEKEY_CONFIG_FILE));
  const tenants = environment.PHONEKEY_TENANTS_JSON ? JSON.parse(environment.PHONEKEY_TENANTS_JSON) : (file.tenants || {});
  const transport = String(environment.PHONEKEY_TRANSPORT || file.transport || "baileys").toLowerCase();
  if (!['baileys', 'evolution'].includes(transport)) throw new Error("PHONEKEY_TRANSPORT must be baileys or evolution.");
  if (!Object.keys(tenants).length) throw new Error("At least one PhoneKey tenant is required.");
  for (const [keyId, tenant] of Object.entries(tenants)) {
    if (!/^[a-z0-9][a-z0-9._-]{2,63}$/i.test(keyId)) throw new Error(`Invalid PhoneKey tenant key id: ${keyId}`);
    if (String(tenant.secret || "").length < 32) throw new Error(`PhoneKey tenant ${keyId} needs a secret of at least 32 characters.`);
    tenant.sites = Array.isArray(tenant.sites) ? tenant.sites.map(String) : [];
    tenant.label = String(tenant.label || keyId).slice(0, 80);
  }
  const setupToken = String(environment.PHONEKEY_SETUP_TOKEN || file.setupToken || "");
  if (transport === "baileys" && setupToken.length < 32) throw new Error("PhoneKey setup token must contain at least 32 characters.");
  return {
    port: integer(environment.PORT || file.port, 3000, 1, 65535),
    host: String(environment.HOST || file.host || "0.0.0.0"),
    transport,
    stateDirectory: resolve(environment.PHONEKEY_STATE_DIR || file.stateDirectory || "../.phonekey-state"),
    setupToken,
    memoryLimitMb: integer(environment.PHONEKEY_MEMORY_LIMIT_MB || file.memoryLimitMb, 256, 128, 2048),
    requestWindowSeconds: integer(environment.PHONEKEY_REQUEST_WINDOW_SECONDS || file.requestWindowSeconds, 90, 30, 300),
    sendTimeoutMs: integer(environment.PHONEKEY_SEND_TIMEOUT_MS || file.sendTimeoutMs, 7000, 2000, 15000),
    tenants,
    evolution: {
      baseUrl: String(environment.EVOLUTION_BASE_URL || file.evolution?.baseUrl || "").replace(/\/$/, ""),
      instance: String(environment.EVOLUTION_INSTANCE || file.evolution?.instance || "phonekey"),
      apiKey: String(environment.EVOLUTION_API_KEY || file.evolution?.apiKey || ""),
    },
  };
}
