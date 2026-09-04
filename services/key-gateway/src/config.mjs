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
    throw new Error(`Key.kiwe runtime configuration could not be read: ${error.message}`);
  }
}

function readTenantEnvironment(environment) {
  const encoded = String(environment.KIWE_KEY_TENANTS_BASE64URL || "").trim();
  const json = encoded
    ? Buffer.from(encoded, "base64url").toString("utf8")
    : String(environment.KIWE_KEY_TENANTS_JSON || "");
  if (!json) return null;
  try {
    return JSON.parse(json);
  } catch {
    throw new Error("Key.kiwe tenant environment configuration is invalid JSON.");
  }
}

export function loadConfig(environment = process.env) {
  const configFile = environment.KIWE_KEY_CONFIG_FILE || "runtime-config.json";
  const instanceFile = environment.KIWE_KEY_INSTANCE_CONFIG_FILE || "instance-config.json";
  const file = readJson(configFile, Boolean(environment.KIWE_KEY_CONFIG_FILE));
  const instance = readJson(instanceFile, Boolean(environment.KIWE_KEY_INSTANCE_CONFIG_FILE));
  const tenants = readTenantEnvironment(environment) || file.tenants || {};
  const transport = String(environment.KIWE_KEY_TRANSPORT || file.transport || "baileys").toLowerCase();
  const controlPlaneEnabled = String(environment.KIWE_KEY_CONTROL_PLANE_ENABLED ?? instance.controlPlane?.enabled ?? file.controlPlane?.enabled ?? "true") === "true";
  if (!['baileys', 'evolution'].includes(transport)) throw new Error("KIWE_KEY_TRANSPORT must be baileys or evolution.");
  if (!Object.keys(tenants).length && !controlPlaneEnabled) throw new Error("At least one Key.kiwe tenant is required when the control plane is disabled.");
  for (const [keyId, tenant] of Object.entries(tenants)) {
    if (!/^[a-z0-9][a-z0-9._-]{2,63}$/i.test(keyId)) throw new Error(`Invalid Key.kiwe tenant key id: ${keyId}`);
    if (String(tenant.secret || "").length < 32) throw new Error(`Key.kiwe tenant ${keyId} needs a secret of at least 32 characters.`);
    tenant.sites = Array.isArray(tenant.sites) ? tenant.sites.map(String) : [];
    tenant.label = String(tenant.label || keyId).slice(0, 80);
  }
  const setupToken = String(environment.KIWE_KEY_SETUP_TOKEN || file.setupToken || "");
  if (transport === "baileys" && setupToken.length < 32) throw new Error("Key.kiwe setup token must contain at least 32 characters.");
  const rcHistoryKey = String(environment.KIWE_KEY_RC_HISTORY_KEY || file.rcObservability?.key || "");
  const rcHistoryEnabled = String(environment.KIWE_KEY_RC_HISTORY ?? file.rcObservability?.enabled ?? "false") === "true";
  if (rcHistoryEnabled && Buffer.from(rcHistoryKey, "base64url").length !== 32) {
    throw new Error("RC history requires a 32-byte base64url encryption key.");
  }
  return {
    port: integer(environment.PORT || file.port, 3000, 1, 65535),
    host: String(environment.HOST || file.host || "0.0.0.0"),
    transport,
    publicBaseUrl: String(environment.KIWE_KEY_PUBLIC_URL || file.publicBaseUrl || "https://key.kiwelaunch.com").replace(/\/$/, ""),
    stateDirectory: resolve(environment.KIWE_KEY_STATE_DIR || file.stateDirectory || "../.kiwe-key-state"),
    legacyStateDirectory: String(environment.KIWE_KEY_LEGACY_STATE_DIR || file.legacyStateDirectory || "").trim(),
    setupToken,
    memoryLimitMb: integer(environment.KIWE_KEY_MEMORY_LIMIT_MB || file.memoryLimitMb, 256, 128, 2048),
    requestWindowSeconds: integer(environment.KIWE_KEY_REQUEST_WINDOW_SECONDS || file.requestWindowSeconds, 90, 30, 300),
    sendTimeoutMs: integer(environment.KIWE_KEY_SEND_TIMEOUT_MS || file.sendTimeoutMs, 7000, 2000, 15000),
    rcObservability: {
      enabled: rcHistoryEnabled,
      captureInboundText: String(environment.KIWE_KEY_RC_CAPTURE_INBOUND ?? file.rcObservability?.captureInboundText ?? "false") === "true",
      captureOutboundText: String(environment.KIWE_KEY_RC_CAPTURE_OUTBOUND ?? file.rcObservability?.captureOutboundText ?? "false") === "true",
      retentionDays: integer(environment.KIWE_KEY_RC_RETENTION_DAYS || file.rcObservability?.retentionDays, 14, 1, 30),
      maxEvents: integer(environment.KIWE_KEY_RC_MAX_EVENTS || file.rcObservability?.maxEvents, 3000, 100, 10000),
      path: resolve(environment.KIWE_KEY_RC_HISTORY_PATH || file.rcObservability?.path || "../.kiwe-key-state/rc-history.json"),
      key: rcHistoryKey,
      tenant: String(environment.KIWE_KEY_RC_TENANT || file.rcObservability?.tenant || Object.keys(tenants)[0] || ""),
    },
    controlPlane: {
      enabled: controlPlaneEnabled,
      path: resolve(environment.KIWE_KEY_CONTROL_PLANE_PATH || instance.controlPlane?.path || file.controlPlane?.path || `${environment.KIWE_KEY_STATE_DIR || file.stateDirectory || "../.kiwe-key-state"}/control-plane.json`),
      bootstrapOwnerEmail: String(environment.KIWE_KEY_BOOTSTRAP_OWNER_EMAIL || instance.controlPlane?.bootstrapOwnerEmail || file.controlPlane?.bootstrapOwnerEmail || "").trim().toLowerCase(),
      encryptionKey: String(environment.KIWE_KEY_CONTROL_PLANE_KEY || instance.controlPlane?.encryptionKey || file.controlPlane?.encryptionKey || ""),
      sessionHours: integer(environment.KIWE_KEY_SESSION_HOURS || instance.controlPlane?.sessionHours || file.controlPlane?.sessionHours, 24, 1, 720),
      registrationEnabled: String(environment.KIWE_KEY_REGISTRATION_ENABLED ?? instance.controlPlane?.registrationEnabled ?? file.controlPlane?.registrationEnabled ?? "true") === "true",
    },
    tenants,
    evolution: {
      baseUrl: String(environment.EVOLUTION_BASE_URL || file.evolution?.baseUrl || "").replace(/\/$/, ""),
      instance: String(environment.EVOLUTION_INSTANCE || file.evolution?.instance || "kiwe-key"),
      apiKey: String(environment.EVOLUTION_API_KEY || file.evolution?.apiKey || ""),
    },
  };
}
