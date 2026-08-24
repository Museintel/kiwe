import { createServer } from "node:http";
import { createApp } from "./app.mjs";
import { loadConfig } from "./config.mjs";
import { createBaileysTransport } from "./transports/baileys.mjs";
import { createEvolutionTransport } from "./transports/evolution.mjs";

const config = loadConfig();
const transport = config.transport === "evolution" ? createEvolutionTransport(config) : await createBaileysTransport(config);
const server = createServer(createApp(config, transport));
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
  process.exit(0);
}
process.once("SIGINT", shutdown);
process.once("SIGTERM", shutdown);
