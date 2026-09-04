export function createEvolutionTransport(config) {
  const { baseUrl, instance, apiKey } = config.evolution;
  if (!baseUrl || !instance || !apiKey) throw new Error("Evolution transport requires base URL, instance, and API key.");
  let readyCache = { value: false, expires: 0 };

  async function request(path, init = {}) {
    const response = await fetch(`${baseUrl}${path}`, {
      ...init,
      headers: { "content-type": "application/json", apikey: apiKey, ...(init.headers || {}) },
      signal: AbortSignal.timeout(config.sendTimeoutMs),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(`Evolution request failed with ${response.status}.`);
    return body;
  }

  return {
    name: "evolution",
    async ready() {
      if (readyCache.expires > Date.now()) return readyCache.value;
      try {
        const body = await request(`/instance/connectionState/${encodeURIComponent(instance)}`, { method: "GET" });
        readyCache = { value: body?.instance?.state === "open", expires: Date.now() + 10000 };
      } catch {
        readyCache = { value: false, expires: Date.now() + 3000 };
      }
      return readyCache.value;
    },
    async sendText(phone, text) {
      const body = await request(`/message/sendText/${encodeURIComponent(instance)}`, {
        method: "POST",
        body: JSON.stringify({ number: phone, text, delay: 0, linkPreview: false }),
      });
      return { id: body?.key?.id || "accepted" };
    },
    setup() {
      return { state: "managed-by-evolution", qr: "" };
    },
    close: async () => {},
  };
}
