import makeWASocket, {
  Browsers,
  DisconnectReason,
  fetchLatestBaileysVersion,
  fetchLatestWaWebVersion,
  useMultiFileAuthState,
} from "@whiskeysockets/baileys";
import pino from "pino";

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const BAILEYS_VERSION = "7.0.0-rc14";
const REPLACED_RECONNECT_DELAY_MS = 120000;

function disconnectLabel(status) {
  const labels = new Map([
    [DisconnectReason.badSession, "bad-session"],
    [DisconnectReason.connectionClosed, "connection-closed"],
    [DisconnectReason.connectionLost, "connection-lost"],
    [DisconnectReason.connectionReplaced, "connection-replaced"],
    [DisconnectReason.loggedOut, "logged-out"],
    [DisconnectReason.restartRequired, "restart-required"],
    [DisconnectReason.timedOut, "timed-out"],
  ]);
  return labels.get(status) || (status ? `status-${status}` : "unknown");
}

export function reconnectDelayFor({ status, attempts, registered }) {
  if (status === DisconnectReason.connectionReplaced) return REPLACED_RECONNECT_DELAY_MS;
  if (status === DisconnectReason.restartRequired) return 1000;
  if (!registered && status === DisconnectReason.timedOut) return 60000;
  return Math.min(30000, 2000 * (2 ** Math.min(Math.max(0, attempts - 1), 4)));
}

async function resolveProtocolVersion() {
  try {
    const result = await fetchLatestWaWebVersion({ timeout: 8000 });
    return { version: result.version, source: "wa-web", current: result.isLatest !== false };
  } catch {
    try {
      const result = await fetchLatestBaileysVersion({ timeout: 8000 });
      return { version: result.version, source: "baileys-fallback", current: result.isLatest !== false };
    } catch {
      return { version: undefined, source: "library-default", current: false };
    }
  }
}

function messageText(message = {}) {
  return message.conversation
    || message.extendedTextMessage?.text
    || message.imageMessage?.caption
    || message.videoMessage?.caption
    || "";
}

const jidPhone = (jid) => String(jid || "").split("@")[0].split(":")[0].replace(/\D/g, "");

export async function createBaileysTransport(config, onEvent = async () => {}) {
  const logger = pino({ level: process.env.KIWE_KEY_LOG_LEVEL || "warn" });
  let socket;
  let state = "starting";
  let qr = "";
  let closing = false;
  let reconnectTimer;
  let reconnectAttempts = 0;
  let connectionGeneration = 0;
  let qrRefreshes = 0;
  let qrIssuedAt = 0;
  let connectedAt = 0;
  let lastDisconnect = { code: 0, reason: "", at: 0 };
  const auth = await useMultiFileAuthState(config.stateDirectory);
  const protocol = await resolveProtocolVersion();

  function scheduleReconnect(delayMs, generation) {
    if (closing || reconnectTimer) return;
    if (!new Set(["pairing-timeout", "session-replaced"]).has(state)) state = "reconnecting";
    reconnectTimer = setTimeout(() => {
      reconnectTimer = undefined;
      if (closing || generation !== connectionGeneration) return;
      connect().catch(() => {
        state = "reconnect-failed";
        scheduleReconnect(Math.min(120000, Math.max(5000, delayMs * 2)), connectionGeneration);
      });
    }, delayMs);
    reconnectTimer.unref?.();
  }

  async function connect() {
    const generation = ++connectionGeneration;
    const options = {
      auth: auth.state,
      logger,
      browser: Browsers.ubuntu("Key.kiwe"),
      markOnlineOnConnect: false,
      syncFullHistory: false,
      shouldSyncHistoryMessage: () => false,
      emitOwnEvents: false,
      generateHighQualityLinkPreview: false,
      connectTimeoutMs: 20000,
      keepAliveIntervalMs: 30000,
      retryRequestDelayMs: 500,
      maxMsgRetryCount: 2,
      getMessage: async () => undefined,
    };
    if (protocol.version) options.version = protocol.version;
    const activeSocket = makeWASocket(options);
    socket = activeSocket;
    activeSocket.ev.on("creds.update", auth.saveCreds);
    activeSocket.ev.on("connection.update", async (update) => {
      if (closing || generation !== connectionGeneration) return;
      if (update.qr) {
        if (update.qr !== qr) qrRefreshes += 1;
        qr = update.qr;
        qrIssuedAt = Date.now();
        state = "pairing";
      }
      if (update.connection === "open") {
        if (reconnectTimer) {
          clearTimeout(reconnectTimer);
          reconnectTimer = undefined;
        }
        qr = "";
        state = "open";
        connectedAt = Date.now();
        reconnectAttempts = 0;
        lastDisconnect = { code: 0, reason: "", at: 0 };
      }
      if (update.connection === "close") {
        qr = "";
        const status = Number(update.lastDisconnect?.error?.output?.statusCode || update.lastDisconnect?.error?.data?.statusCode || 0);
        lastDisconnect = { code: status, reason: disconnectLabel(status), at: Date.now() };
        if (status === DisconnectReason.loggedOut) {
          state = "logged-out";
          return;
        }
        reconnectAttempts += 1;
        const pairingTimedOut = !auth.state.creds.registered && status === DisconnectReason.timedOut;
        state = pairingTimedOut
          ? "pairing-timeout"
          : (status === DisconnectReason.connectionReplaced ? "session-replaced" : "closed");
        const delay = reconnectDelayFor({ status, attempts: reconnectAttempts, registered: auth.state.creds.registered });
        scheduleReconnect(delay, generation);
      }
    });
    activeSocket.ev.on("messages.upsert", async ({ messages = [], type }) => {
      if (closing || generation !== connectionGeneration) return;
      if (type !== "notify") return;
      for (const item of messages) {
        if (item.key?.fromMe || !item.message) continue;
        await onEvent({
          direction: "inbound",
          channel: "whatsapp",
          status: "received",
          phone: jidPhone(item.key?.remoteJid),
          receipt: item.key?.id || "",
          summary: "Inbound WhatsApp message received during RC",
          content: messageText(item.message),
        }).catch(() => {});
      }
    });
    activeSocket.ev.on("messages.update", async (updates = []) => {
      if (closing || generation !== connectionGeneration) return;
      for (const item of updates) {
        if (!item.key?.fromMe) continue;
        const status = Number(item.update?.status);
        if (status !== 0 && status < 3) continue;
        await onEvent({
          direction: "outbound",
          channel: "whatsapp",
          status: status === 0 ? "failed" : "delivered",
          phone: jidPhone(item.key?.remoteJid),
          receipt: item.key?.id || "",
          summary: status === 0 ? "WhatsApp reported a delivery failure" : "WhatsApp reported delivery",
          error: status === 0 ? "provider_delivery_failure" : "",
        }).catch(() => {});
      }
    });
  }

  await connect();
  return {
    name: "baileys",
    ready: async () => state === "open",
    async sendText(phone, text) {
      if (state !== "open" || !socket) throw new Error("WhatsApp session is not connected.");
      const result = await Promise.race([
        socket.sendMessage(`${phone}@s.whatsapp.net`, { text }),
        wait(config.sendTimeoutMs).then(() => { throw new Error("WhatsApp send timed out."); }),
      ]);
      return { id: result?.key?.id || "accepted" };
    },
    async selfTest() {
      if (state !== "open" || !socket) throw new Error("WhatsApp session is not connected.");
      const target = jidPhone(socket.user?.phoneNumber || auth.state.creds.me?.phoneNumber || socket.user?.id || auth.state.creds.me?.id);
      if (!target) throw new Error("The connected WhatsApp account has no self-test target.");
      const result = await Promise.race([
        socket.sendMessage(`${target}@s.whatsapp.net`, { text: "Key.kiwe self-test passed. The connected WhatsApp delivery path is operational." }),
        wait(config.sendTimeoutMs).then(() => { throw new Error("WhatsApp self-test timed out."); }),
      ]);
      return { id: result?.key?.id || "accepted", target };
    },
    setup: () => ({
      state,
      qr,
      connectedPhone: jidPhone(socket?.user?.phoneNumber || auth.state.creds.me?.phoneNumber || socket?.user?.id || auth.state.creds.me?.id),
      libraryVersion: BAILEYS_VERSION,
      protocolVersion: protocol.version?.join(".") || "library-default",
      protocolSource: protocol.source,
      protocolCurrent: protocol.current,
      registered: Boolean(auth.state.creds.registered),
      qrRefreshes,
      qrIssuedAt,
      connectedAt,
      lastDisconnect,
    }),
    async close() {
      closing = true;
      connectionGeneration += 1;
      if (reconnectTimer) clearTimeout(reconnectTimer);
      try { socket?.ws?.close(); } catch {}
    },
  };
}
