import { createServer } from "node:http";
import { copyFile, cp, mkdir, rename, stat } from "node:fs/promises";
import { dirname, isAbsolute, join, relative, resolve } from "node:path";
import { createApp } from "./app.mjs";
import { loadConfig } from "./config.mjs";
import { createBaileysTransport } from "./transports/baileys.mjs";
import { createEvolutionTransport } from "./transports/evolution.mjs";
import { createHistoryStore } from "./history.mjs";
import { createControlPlane } from "./control-plane.mjs";
import { createControlPlaneWeb } from "./control-plane-web.mjs";
import { WorkspaceTransportManager } from "./workspace-transports.mjs";

const config = loadConfig();

async function migrateLegacyState() {
  if (!config.legacyStateDirectory) return;
  const source = resolve(config.legacyStateDirectory);
  const target = resolve(config.stateDirectory);
  if (source === target) return;
  try { await stat(join(target, "control-plane.json")); return; } catch {}
  try {
    await mkdir(dirname(target), { recursive: true, mode: 0o700 });
    await cp(source, target, { recursive: true, force: false, errorOnExist: false });
  } catch (error) {
    if (error?.code !== "ENOENT") throw error;
  }
}

await migrateLegacyState();
const history = await createHistoryStore(config);
const controlPlane = await createControlPlane(config);
controlPlane?.hydrateActivity(history);
const transportEvent = (event) => history.record({ tenant: config.rcObservability.tenant, ...event });
const transport = config.transport === "evolution" ? createEvolutionTransport(config) : await createBaileysTransport(config, transportEvent);
const bootstrapPhoneLink = setInterval(async () => {
  const connectedPhone = transport.setup?.().connectedPhone || "";
  if (!connectedPhone) return;
  await controlPlane?.linkBootstrapPrimaryPhone(connectedPhone);
  clearInterval(bootstrapPhoneLink);
}, 2000);
bootstrapPhoneLink.unref?.();
const transportManager = controlPlane ? new WorkspaceTransportManager({ config, store: controlPlane, primaryTransport: transport, history }) : null;
const resetPrimarySession = async () => {
  if (config.transport !== "baileys") throw new Error("Linked-device reset is available only for the Baileys transport.");
  const stateDirectory = resolve(config.stateDirectory);
  if (dirname(stateDirectory) === stateDirectory || stateDirectory.length < 8) throw new Error("Unsafe Key.kiwe state directory.");
  const backupDirectory = `${stateDirectory}.session-reset-${Date.now()}`;
  const historyRelative = relative(stateDirectory, resolve(config.rcObservability.path));
  const historyInsideState = historyRelative && !historyRelative.startsWith("..") && !isAbsolute(historyRelative);
  if (transportManager) await transportManager.close();
  else await transport.close();
  await history.close();
  await controlPlane?.close();
  try {
    await rename(stateDirectory, backupDirectory);
  } catch (error) {
    if (error?.code !== "ENOENT") throw error;
  }
  await mkdir(stateDirectory, { recursive: true, mode: 0o700 });
  const preserve = async (sourcePath) => {
    const sourceRelative = relative(stateDirectory, resolve(sourcePath));
    const insideState = sourceRelative && !sourceRelative.startsWith("..") && !isAbsolute(sourceRelative);
    if (!insideState) return;
    try {
      const target = join(stateDirectory, sourceRelative);
      await mkdir(dirname(target), { recursive: true, mode: 0o700 });
      await copyFile(join(backupDirectory, sourceRelative), target);
    } catch (error) {
      if (error?.code !== "ENOENT") throw error;
    }
  };
  if (historyInsideState) await preserve(config.rcObservability.path);
  if (controlPlane) await preserve(config.controlPlane.path);
  try {
    await cp(join(backupDirectory, "connections"), join(stateDirectory, "connections"), { recursive: true, force: false, errorOnExist: false });
  } catch (error) {
    if (error?.code !== "ENOENT") throw error;
  }
  try {
    await cp(join(backupDirectory, "accounts"), join(stateDirectory, "accounts"), { recursive: true, force: false, errorOnExist: false });
  } catch (error) {
    if (error?.code !== "ENOENT") throw error;
  }
  setTimeout(() => process.exit(0), 250).unref();
};
const resetSession = resetPrimarySession;
const web = controlPlane ? createControlPlaneWeb({ config, store: controlPlane, connections: transportManager }) : null;
const server = createServer(createApp(config, transport, () => Date.now(), history, {
  resetSession, controlPlane, web, transportForTenant: (keyId) => transportManager?.forTenant(keyId),
}));
server.requestTimeout = 15000;
server.headersTimeout = 10000;
server.keepAliveTimeout = 5000;
server.listen(config.port, config.host);

const memoryGuard = setInterval(() => {
  const rssMb = process.memoryUsage().rss / 1024 / 1024;
  if (rssMb > config.memoryLimitMb) process.kill(process.pid, "SIGTERM");
}, 30000);
memoryGuard.unref();

async function shutdown() {
  clearInterval(memoryGuard);
  clearInterval(bootstrapPhoneLink);
  server.close();
  if (transportManager) await transportManager.close();
  else await transport.close();
  await history.close();
  await controlPlane?.close();
  process.exit(0);
}
process.once("SIGINT", shutdown);
process.once("SIGTERM", shutdown);
