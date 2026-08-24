import { createServer } from "node:http";
import { copyFile, mkdir, rename } from "node:fs/promises";
import { dirname, isAbsolute, join, relative, resolve } from "node:path";
import { createApp } from "./app.mjs";
import { loadConfig } from "./config.mjs";
import { createBaileysTransport } from "./transports/baileys.mjs";
import { createEvolutionTransport } from "./transports/evolution.mjs";
import { createHistoryStore } from "./history.mjs";

const config = loadConfig();
const history = await createHistoryStore(config);
const transportEvent = (event) => history.record({ tenant: config.rcObservability.tenant, ...event });
const transport = config.transport === "evolution" ? createEvolutionTransport(config) : await createBaileysTransport(config, transportEvent);
const resetSession = async () => {
  if (config.transport !== "baileys") throw new Error("Linked-device reset is available only for the Baileys transport.");
  const stateDirectory = resolve(config.stateDirectory);
  if (dirname(stateDirectory) === stateDirectory || stateDirectory.length < 8) throw new Error("Unsafe PhoneKey state directory.");
  const backupDirectory = `${stateDirectory}.session-reset-${Date.now()}`;
  const historyRelative = relative(stateDirectory, resolve(config.rcObservability.path));
  const historyInsideState = historyRelative && !historyRelative.startsWith("..") && !isAbsolute(historyRelative);
  await transport.close();
  await history.close();
  try {
    await rename(stateDirectory, backupDirectory);
  } catch (error) {
    if (error?.code !== "ENOENT") throw error;
  }
  await mkdir(stateDirectory, { recursive: true, mode: 0o700 });
  if (historyInsideState) {
    try {
      const target = join(stateDirectory, historyRelative);
      await mkdir(dirname(target), { recursive: true, mode: 0o700 });
      await copyFile(join(backupDirectory, historyRelative), target);
    } catch (error) {
      if (error?.code !== "ENOENT") throw error;
    }
  }
  setTimeout(() => process.exit(0), 250).unref();
};
const server = createServer(createApp(config, transport, () => Date.now(), history, { resetSession }));
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
  server.close();
  await transport.close();
  await history.close();
  process.exit(0);
}
process.once("SIGINT", shutdown);
process.once("SIGTERM", shutdown);
