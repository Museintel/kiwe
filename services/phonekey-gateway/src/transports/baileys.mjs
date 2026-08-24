import makeWASocket, { DisconnectReason, useMultiFileAuthState } from "@whiskeysockets/baileys";
import pino from "pino";

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function messageText(message = {}) {
  return message.conversation
    || message.extendedTextMessage?.text
    || message.imageMessage?.caption
    || message.videoMessage?.caption
    || "";
}

const jidPhone = (jid) => String(jid || "").split("@")[0].replace(/\D/g, "");

export async function createBaileysTransport(config, onEvent = async () => {}) {
  const logger = pino({ level: process.env.PHONEKEY_LOG_LEVEL || "warn" });
  let socket;
  let state = "starting";
  let qr = "";
  let closing = false;
  let reconnecting = false;
  const auth = await useMultiFileAuthState(config.stateDirectory);

  async function connect() {
    socket = makeWASocket({
      auth: auth.state,
      logger,
      browser: ["Kiwe PhoneKey", "Chrome", "1.0.0"],
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
    });
    socket.ev.on("creds.update", auth.saveCreds);
    socket.ev.on("connection.update", async (update) => {
      if (update.qr) {
        qr = update.qr;
        state = "pairing";
      }
      if (update.connection === "open") {
        qr = "";
        state = "open";
        reconnecting = false;
      }
      if (update.connection === "close") {
        state = "closed";
        const status = update.lastDisconnect?.error?.output?.statusCode;
        if (status === DisconnectReason.loggedOut) {
          state = "logged-out";
          return;
        }
        if (!closing && !reconnecting) {
          reconnecting = true;
          await wait(2000);
          await connect().catch(() => { reconnecting = false; });
        }
      }
    });
    socket.ev.on("messages.upsert", async ({ messages = [], type }) => {
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
    socket.ev.on("messages.update", async (updates = []) => {
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
    setup: () => ({ state, qr }),
    async close() {
      closing = true;
      try { socket?.ws?.close(); } catch {}
    },
  };
}
