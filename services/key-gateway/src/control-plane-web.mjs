import QRCode from "qrcode";
import { SlidingLimiter } from "./security.mjs";

const escapeHtml = (value) => String(value ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");

async function requestBody(request, maximum = 16384) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > maximum) throw Object.assign(new Error("Request is too large."), { status: 413 });
    chunks.push(chunk);
  }
  return Buffer.concat(chunks).toString("utf8");
}

function cookies(request) {
  return Object.fromEntries(String(request.headers.cookie || "").split(";").map((entry) => entry.trim()).filter(Boolean).map((entry) => {
    const separator = entry.indexOf("=");
    return separator < 0 ? [entry, ""] : [entry.slice(0, separator), decodeURIComponent(entry.slice(separator + 1))];
  }));
}

function send(response, status, body, headers = {}) {
  response.writeHead(status, {
    "content-type": "text/html; charset=utf-8", "cache-control": "no-store, private",
    "content-security-policy": "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
    "x-content-type-options": "nosniff", "x-frame-options": "DENY",
    "permissions-policy": "camera=(), microphone=(), geolocation=(), payment=(), usb=()", "referrer-policy": "no-referrer", ...headers,
  });
  response.end(body);
}

function redirect(response, location, headers = {}) {
  response.writeHead(303, { location, "cache-control": "no-store", ...headers });
  response.end();
}

function date(value) {
  if (!value) return "No activity yet";
  return new Intl.DateTimeFormat("en", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Kolkata" }).format(new Date(value));
}

function maskPhone(value) {
  const digits = String(value || "").replace(/\D/g, "");
  return digits ? `+${digits.slice(0, Math.max(2, digits.length - 6))}••••${digits.slice(-4)}` : "Not paired";
}

const styles = `:root{color-scheme:light;--ink:#10221c;--muted:#64736d;--line:#dce6e1;--paper:#fff;--wash:#f3f7f5;--green:#087a50;--mint:#dff7eb;--amber:#8a5a00;--amberbg:#fff4d6;--red:#a22d3f;--redbg:#ffedf0;--violet:#7047eb}*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--wash);color:var(--ink);font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}a{color:var(--green)}main{width:min(1160px,calc(100% - 32px));margin:28px auto 70px}.shell{background:var(--paper);border:1px solid var(--line);border-radius:24px;padding:clamp(20px,4vw,44px);box-shadow:0 24px 80px rgba(23,58,44,.07)}header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:28px}.brand{font-size:14px;font-weight:900;letter-spacing:.08em}.brand em{font-style:normal;color:var(--violet)}.muted,.meta{color:var(--muted)}h1{font-size:clamp(34px,6vw,68px);line-height:.98;letter-spacing:-.045em;margin:.18em 0}h2{font-size:22px;margin:0 0 14px}h3{margin:0 0 6px}.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}.card{grid-column:span 6;border:1px solid var(--line);border-radius:18px;padding:20px;background:#fff}.wide{grid-column:1/-1}.metric{grid-column:span 4}.metric strong{font-size:26px;display:block}.status{display:inline-flex;align-items:center;gap:7px;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:850;background:var(--amberbg);color:var(--amber)}.status.ok{background:var(--mint);color:var(--green)}.status.bad{background:var(--redbg);color:var(--red)}.dot{width:8px;height:8px;border-radius:50%;background:currentColor}form{margin:0}.stack{display:grid;gap:12px}label{display:grid;gap:6px;font-weight:750}input{width:100%;border:1px solid #cbd8d2;border-radius:12px;padding:13px 14px;font:inherit;color:var(--ink);background:#fff}button,.button{appearance:none;border:0;border-radius:12px;padding:12px 16px;font:inherit;font-weight:850;background:var(--ink);color:#fff;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.primary{background:var(--violet)}.secondary{background:#edf4f0;color:var(--ink)}.danger{background:var(--red)}.actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.site{padding:20px 0;border-top:1px solid var(--line)}.site:first-of-type{border-top:0}.site-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.site code,.secret{overflow-wrap:anywhere}.secret{display:block;padding:12px;background:#12261f;color:#dff7eb;border-radius:10px}.notice{border:1px solid #9ed8bd;background:var(--mint);border-radius:14px;padding:15px;margin:0 0 18px}.warning{border-color:#e9cd7d;background:var(--amberbg)}.auth{width:min(560px,calc(100% - 32px));margin:7vh auto}.auth .shell{padding:clamp(24px,6vw,50px)}.auth nav{margin-top:18px}.qr{max-width:270px;width:100%;display:block;margin:14px auto}.split{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{padding:12px 13px;border:1px solid var(--line);border-radius:12px;background:var(--wash)}.field b{display:block;font-size:12px;color:var(--muted);margin-bottom:4px}.logout{background:none;color:var(--muted);padding:5px}.connection{margin-top:14px;padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--wash)}details{margin-top:14px}summary{cursor:pointer;font-weight:800}.hero{padding:clamp(48px,9vw,110px) 0 70px;display:grid;grid-template-columns:1.2fr .8fr;align-items:end;gap:30px}.eyebrow{font-weight:850;color:var(--violet);letter-spacing:.08em;text-transform:uppercase}.hero p{font-size:clamp(18px,2vw,23px);max-width:650px}.hero-card{background:linear-gradient(145deg,#10221c,#224b3c);color:#fff;border-radius:28px;padding:30px;box-shadow:0 30px 90px rgba(16,34,28,.2)}.hero-card strong{font-size:clamp(34px,6vw,72px);display:block;line-height:1}.features{padding:38px 0 80px}.feature{grid-column:span 4}.feature span{font-size:30px}.cta{background:linear-gradient(135deg,#7047eb,#925cff);color:#fff;border-radius:28px;padding:clamp(28px,6vw,62px);display:flex;justify-content:space-between;gap:20px;align-items:center}.cta .button{background:#fff;color:#3e267e}.navlinks{display:flex;gap:16px;align-items:center}.table{width:100%;border-collapse:collapse}.table th,.table td{text-align:left;padding:12px 8px;border-bottom:1px solid var(--line);vertical-align:top}.table th{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}.site-list{display:grid;gap:6px}.site-list span{display:block}.refresh{position:relative}.refresh:after{content:'Waiting for secure pairing…';display:block;color:var(--muted);font-size:13px;margin-top:10px}@media(max-width:780px){main{width:min(100% - 20px,1160px);margin-top:10px}.card,.metric,.feature{grid-column:1/-1}.split,.hero{grid-template-columns:1fr}.site-head,header,.cta{align-items:flex-start}.hero{padding-top:40px}.navlinks{flex-wrap:wrap}}`;

function page(title, content, navigation = "", refresh = 0) {
  const refreshMeta = refresh ? `<meta http-equiv="refresh" content="${Number(refresh)}">` : "";
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">${refreshMeta}<title>${escapeHtml(title)} · Key.kiwe</title><style>${styles}</style></head><body><main>${navigation}${content}</main></body></html>`;
}

function authPage(title, content, refresh = 0) {
  return page(title, `<div class="auth"><section class="shell"><div class="brand">Key<em>.kiwe</em></div><h1>${escapeHtml(title)}</h1>${content}</section></div>`, "", refresh);
}

function marketingPage(account) {
  const navigation = `<header><a class="brand" href="/">Key<em>.kiwe</em></a><nav class="navlinks"><a href="#features">Features</a>${account ? '<a class="button secondary" href="/app">Open dashboard</a>' : '<a href="/login">Sign in</a><a class="button primary" href="/register">Try it — free</a>'}</nav></header>`;
  const content = `<section class="hero"><div><div class="eyebrow">Your websites. Your WhatsApp identity.</div><h1>One secure key for every website you run.</h1><p>Register with your WhatsApp number, scan once, and connect websites in minutes. Every new site inherits your primary number by default; client numbers stay optional and independent.</p><div class="actions"><a class="button primary" href="/register">Try it — it’s free</a><a class="button secondary" href="/login">I already have Key.kiwe</a></div></div><aside class="hero-card"><span>Setup</span><strong>1 number.<br>1 scan.</strong><p>No passwords. No app installation. No shared client credentials.</p></aside></section><section id="features" class="features"><div class="eyebrow">Built for real client work</div><h2>Low friction without collapsing security boundaries.</h2><div class="grid"><article class="card feature"><span>⚡</span><h3>WhatsApp-first access</h3><p>Registration pairs the number you own. Future sign-ins use a short code delivered by that same connection.</p></article><article class="card feature"><span>◎</span><h3>Primary by default</h3><p>New websites work through the account’s primary number immediately, with isolated signed credentials per website.</p></article><article class="card feature"><span>↗</span><h3>Client override anytime</h3><p>Pair, replace, deactivate, reactivate, or return one website to the primary number without disturbing the rest.</p></article><article class="card feature"><span>◉</span><h3>Live connection health</h3><p>See the active number, delivery state, last transaction, and signed-request count for every connected website.</p></article><article class="card feature"><span>◆</span><h3>Tenant isolation</h3><p>Each website receives its own HMAC identity and only its approved origin can submit signed delivery requests.</p></article><article class="card feature"><span>✓</span><h3>Free to start</h3><p>Create the account with only a WhatsApp number. Add a website when you are ready—no card required.</p></article></div></section><section class="cta"><div><div class="eyebrow" style="color:#ded3ff">Your first secure connection</div><h2 style="margin:.2em 0">WhatsApp number → scan → you’re in.</h2></div><a class="button" href="/register">Create my Key.kiwe account</a></section>`;
  return page("WhatsApp-first website access", content, navigation);
}

export function createControlPlaneWeb({ config, store, connections, clock = () => Date.now() }) {
  const loginLimiter = new SlidingLimiter(8, 15 * 60 * 1000);
  const registrationLimiter = new SlidingLimiter(5, 60 * 60 * 1000);
  const reconnectLimiter = new SlidingLimiter(5, 60 * 60 * 1000);
  const sessionCookie = "key_session";
  const pairingCookie = "key_pairing";
  const reconnectCookie = "key_reconnect";
  const challengeCookie = "key_login";
  const baseUrl = config.publicBaseUrl;

  const clientKey = (request) => String(request.headers["cf-connecting-ip"] || request.headers["x-forwarded-for"] || request.socket?.remoteAddress || "unknown").split(",")[0].trim();
  const auth = (request) => store.session(cookies(request)[sessionCookie] || "");
  const validCsrf = (account, form) => Boolean(account?.session?.csrf && form.get("csrf") === account.session.csrf);
  const secureCookie = (name, value, maximum) => `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=${maximum}; HttpOnly; Secure; SameSite=Lax`;
  const clearCookie = (name) => `${name}=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Lax`;

  async function primaryCard(account) {
    const raw = store.userById(account.user.id);
    let currentTransport = await connections.ownerTransport(raw);
    const current = currentTransport?.setup?.() || { state: "offline", connectedPhone: "" };
    let pendingHtml = "";
    let refresh = false;
    if (raw.pendingPrimaryConnectionId) {
      const pendingTransport = await connections.pendingPrimaryTransport(raw);
      const pending = pendingTransport?.setup?.() || { state: "starting", connectedPhone: "", qr: "" };
      if (await pendingTransport?.ready?.().catch(() => false)) {
        const finalized = await store.finalizePrimaryReplacement(raw.id, pending.connectedPhone);
        if (finalized) {
          currentTransport = pendingTransport;
          pendingHtml = '<aside class="notice"><strong>Primary number changed.</strong> Every website using your primary connection switched automatically.</aside>';
        } else {
          pendingHtml = '<aside class="notice warning"><strong>Wrong number scanned.</strong> Scan using the WhatsApp number entered for this replacement.</aside>';
        }
      } else {
        const qr = pending.qr ? await QRCode.toDataURL(pending.qr, { margin: 2, width: 300 }) : "";
        pendingHtml = `<aside class="notice warning refresh"><strong>Pair the replacement primary number</strong><p>Open WhatsApp → Linked devices → Link a device on ${escapeHtml(maskPhone(raw.pendingPrimaryPhone ? store.pendingPrimaryPhone(raw) : ""))}.</p>${qr ? `<img class="qr" alt="WhatsApp pairing QR" src="${qr}">` : '<p>Preparing a fresh QR…</p>'}</aside>`;
        refresh = true;
      }
    }
    const updated = store.userById(account.user.id);
    const setup = currentTransport?.setup?.() || current;
    const ready = Boolean(currentTransport && await currentTransport.ready().catch(() => false));
    return { refresh, html: `<section class="card wide"><div class="site-head"><div><h2>Your primary WhatsApp connection</h2><p class="muted">Every website uses this number unless you give that site its own connection.</p></div><span class="status ${ready ? "ok" : "bad"}"><span class="dot"></span>${ready ? "Connected" : escapeHtml(setup.state || "Offline")}</span></div><div class="split"><div class="field"><b>Primary number</b><strong>${escapeHtml(maskPhone(store.userPhone(updated)))}</strong></div><div class="field"><b>Inherited by</b><strong>${store.sitesFor(updated.id).filter((site) => site.connectionMode === "account_primary").length} website(s)</strong></div></div>${pendingHtml}<details><summary>Change the primary number</summary><p class="meta">The current number remains live until the replacement scan succeeds.</p><form class="stack" method="post" action="/account/replace"><input type="hidden" name="csrf" value="${escapeHtml(account.session.csrf)}"><label>New WhatsApp number<input name="phone" inputmode="tel" autocomplete="tel" placeholder="919876543210" required></label><button type="submit">Prepare replacement QR</button></form></details></section>` };
  }

  async function siteCard(siteValue, account) {
    let site = store.siteByKeyId(siteValue.keyId) || siteValue;
    const transport = site.active ? await connections.forDisplay(site) : null;
    site = store.siteByKeyId(site.keyId) || site;
    const setup = transport?.setup?.() || { state: "inactive", connectedPhone: "", qr: "" };
    const ready = Boolean(transport && await transport.ready().catch(() => false));
    const qr = site.pendingDedicated && setup.qr ? await QRCode.toDataURL(setup.qr, { margin: 2, width: 300 }) : "";
    const inherited = site.connectionMode === "account_primary" && !site.pendingDedicated;
    const stateLabel = !site.active ? "Inactive" : (ready ? "Connected" : (setup.state || "Awaiting pairing"));
    const stateClass = ready ? "ok" : (!site.active || ["logged-out", "reconnect-failed", "session-replaced"].includes(setup.state) ? "bad" : "");
    const pairing = site.pendingDedicated ? `<aside class="notice warning"><strong>Client-number pairing in progress</strong><br>The primary number continues serving this website until the scan succeeds.</aside>${qr ? `<p>On the client number, open WhatsApp → Linked devices → Link a device.</p><img class="qr" alt="WhatsApp pairing QR for ${escapeHtml(site.label)}" src="${qr}">` : '<p class="muted">Preparing this website’s QR…</p>'}` : "";
    const overrideAction = inherited ? `<form method="post" action="/sites/${encodeURIComponent(site.id)}/independent"><input type="hidden" name="csrf" value="${escapeHtml(account.session.csrf)}"><button type="submit">Use a client number</button></form>` : `<form method="post" action="/sites/${encodeURIComponent(site.id)}/inherit"><input type="hidden" name="csrf" value="${escapeHtml(account.session.csrf)}"><button class="secondary" type="submit">Return to primary number</button></form>`;
    const resetAction = !inherited ? `<details><summary>Replace this website’s client number</summary><p class="meta">Only ${escapeHtml(site.label)} is affected.</p><form class="stack" method="post" action="/sites/${encodeURIComponent(site.id)}/reset"><input type="hidden" name="csrf" value="${escapeHtml(account.session.csrf)}"><label>Type CHANGE to continue<input name="confirm" autocomplete="off" required></label><button class="danger" type="submit">Disconnect this client number and show a new QR</button></form></details>` : "";
    return `<article class="site"><div class="site-head"><div><h3>${escapeHtml(site.label)}</h3><a href="${escapeHtml(site.origin)}" rel="noreferrer">${escapeHtml(site.origin)}</a></div><span class="status ${stateClass}"><span class="dot"></span>${escapeHtml(stateLabel)}</span></div><p class="meta">Last transaction: ${escapeHtml(date(site.lastTransactionAt))} · ${Number(site.transactionCount || 0).toLocaleString("en-IN")} signed requests</p><div class="split"><div class="field"><b>Active number</b><strong>${escapeHtml(maskPhone(setup.connectedPhone))}</strong></div><div class="field"><b>Connection</b><strong>${inherited ? "Account primary" : "Client-specific"}</strong></div><div class="field"><b>Key gateway URL</b><code>${escapeHtml(baseUrl)}/v1/otp</code></div><div class="field"><b>Tenant key ID</b><code>${escapeHtml(site.keyId)}</code></div></div><div class="connection">${pairing}${site.active ? "" : '<p class="muted">Signed delivery is paused. Configuration and history are preserved.</p>'}<div class="actions">${overrideAction}<a class="button secondary" href="/app">Refresh status</a><form method="post" action="/sites/${encodeURIComponent(site.id)}/toggle"><input type="hidden" name="csrf" value="${escapeHtml(account.session.csrf)}"><input type="hidden" name="active" value="${site.active ? "0" : "1"}"><button class="secondary" type="submit">${site.active ? "Deactivate website" : "Activate website"}</button></form></div>${resetAction}</div></article>`;
  }

  async function dashboard(account, message = "", credentials = null) {
    const primary = await primaryCard(account);
    const sites = store.sitesFor(account.user.id);
    const cards = await Promise.all(sites.map((site) => siteCard(site, account)));
    const current = store.sitesFor(account.user.id);
    const navigation = `<header><a class="brand" href="/">Key<em>.kiwe</em></a><nav class="navlinks">${account.user.role === "master" ? '<a href="/network">Network oversight</a>' : ""}<span class="muted">${escapeHtml(maskPhone(account.user.phone))}</span><form method="post" action="/logout"><input type="hidden" name="csrf" value="${escapeHtml(account.session.csrf)}"><button class="logout" type="submit">Sign out</button></form></nav></header>`;
    const credentialsNotice = credentials ? `<aside class="notice"><strong>${escapeHtml(credentials.site.label)} is ready.</strong><p>Copy this signing secret now. It is encrypted at rest and will not be shown again.</p><div class="split"><div class="field"><b>Key gateway URL</b><code>${escapeHtml(baseUrl)}/v1/otp</code></div><div class="field"><b>Tenant key ID</b><code>${escapeHtml(credentials.site.keyId)}</code></div></div><p><b>Signing secret</b><code class="secret">${escapeHtml(credentials.secret)}</code></p><p class="meta">Enter these in WordPress under Kiwe → Key → WhatsApp provider. This website already uses your primary number.</p></aside>` : "";
    const content = `<section class="shell">${message ? `<aside class="notice ${message.startsWith("Error:") ? "warning" : ""}">${escapeHtml(message)}</aside>` : ""}${credentialsNotice}<h1>Your Key.kiwe sites</h1><p class="muted">Primary by default. Client-specific only when you choose it.</p><div class="grid"><section class="card metric"><span class="meta">Websites</span><strong>${current.length}</strong></section><section class="card metric"><span class="meta">Active</span><strong>${current.filter((site) => site.active).length}</strong></section><section class="card metric"><span class="meta">Client-number overrides</span><strong>${current.filter((site) => site.connectionMode === "dedicated").length}</strong></section>${primary.html}<section class="card wide"><div class="actions" style="justify-content:space-between"><h2>Connected websites</h2><a class="button secondary" href="/app">Refresh all</a></div>${cards.join("") || '<p class="muted">No websites are connected yet.</p>'}</section><section class="card wide"><h2>Add a website</h2><form class="stack" method="post" action="/sites"><input type="hidden" name="csrf" value="${escapeHtml(account.session.csrf)}"><div class="split"><label>Website name<input name="label" maxlength="80" placeholder="Client website" required></label><label>Website URL<input name="origin" type="url" placeholder="https://example.com" required></label></div><button type="submit">Create website connection</button></form><p class="meta">The new website inherits your primary WhatsApp number and receives isolated signing credentials.</p></section></div></section>`;
    return page("Dashboard", content, navigation, primary.refresh ? 4 : 0);
  }

  async function network(account, message = "") {
    const users = store.networkSummary(account.user.id);
    const siteCount = users.reduce((sum, user) => sum + user.sites.length, 0);
    const rows = users.map((user) => `<tr><td><strong>${escapeHtml(user.phone ? `+${user.phone}` : "Not paired")}</strong><br><span class="meta">Joined ${escapeHtml(date(user.createdAt))}</span></td><td><span class="status ${user.status === "active" ? "ok" : "bad"}"><span class="dot"></span>${escapeHtml(user.status)}</span><br><span class="meta">${escapeHtml(user.role)}</span></td><td><div class="site-list">${user.sites.map((site) => { const inherited = site.connectionMode === "account_primary"; const activeNumber = inherited ? user.phone : site.activePhone; const route = inherited ? "account primary" : "client-specific"; return `<span><strong>${escapeHtml(site.label)}</strong> · ${escapeHtml(site.origin)} · ${site.active ? "active" : "inactive"}<br><span class="meta">Serving number: <strong>${escapeHtml(activeNumber ? `+${activeNumber}` : "Awaiting pairing")}</strong> · ${escapeHtml(route)}</span></span>`; }).join("") || '<span class="meta">No websites</span>'}</div></td><td>${escapeHtml(date(user.lastTransactionAt))}</td><td>${user.role === "master" ? "—" : `<form method="post" action="/network/users/${encodeURIComponent(user.id)}/toggle"><input type="hidden" name="csrf" value="${escapeHtml(account.session.csrf)}"><input type="hidden" name="status" value="${user.status === "active" ? "suspended" : "active"}"><button class="${user.status === "active" ? "danger" : "secondary"}" type="submit">${user.status === "active" ? "Suspend" : "Restore"}</button></form>`}</td></tr>`).join("");
    const nav = `<header><a class="brand" href="/">Key<em>.kiwe</em></a><nav class="navlinks"><a href="/app">My clients</a><form method="post" action="/logout"><input type="hidden" name="csrf" value="${escapeHtml(account.session.csrf)}"><button class="logout" type="submit">Sign out</button></form></nav></header>`;
    const content = `<section class="shell">${message ? `<aside class="notice">${escapeHtml(message)}</aside>` : ""}<div class="eyebrow">Master account</div><h1>Key network oversight</h1><p class="muted">Operational visibility for abuse response and connection support.</p><div class="grid"><section class="card metric"><span class="meta">Accounts</span><strong>${users.length}</strong></section><section class="card metric"><span class="meta">Active accounts</span><strong>${users.filter((user) => user.status === "active").length}</strong></section><section class="card metric"><span class="meta">Connected websites</span><strong>${siteCount}</strong></section><section class="card wide" style="overflow:auto"><table class="table"><thead><tr><th>Account number</th><th>Status</th><th>Websites and contact surface</th><th>Last transaction</th><th>Action</th></tr></thead><tbody>${rows}</tbody></table></section></div></section>`;
    return page("Network oversight", content, nav);
  }

  async function pairingPage(request) {
    const token = cookies(request)[pairingCookie] || "";
    const registration = store.registration(token);
    if (!registration) return { status: 410, html: authPage("Pairing expired", '<p>Start registration again.</p><a class="button primary" href="/register">Restart</a>') };
    const transport = await connections.registrationTransport(registration);
    const setup = transport.setup();
    if (await transport.ready().catch(() => false)) {
      const user = await store.completeRegistration(token, setup.connectedPhone);
      const session = await store.createSession(user.id);
      return { redirect: "/app", headers: { "set-cookie": [secureCookie(sessionCookie, session.token, config.controlPlane.sessionHours * 3600), clearCookie(pairingCookie)] } };
    }
    const qr = setup.qr ? await QRCode.toDataURL(setup.qr, { margin: 2, width: 320 }) : "";
    const html = authPage("Scan once. You’re in.", `<p>On ${escapeHtml(maskPhone(registration.phone))}, open WhatsApp → Linked devices → Link a device.</p>${qr ? `<img class="qr" alt="Key.kiwe WhatsApp pairing QR" src="${qr}">` : '<p class="notice warning">Preparing a secure QR…</p>'}<p class="meta">This page continues automatically after WhatsApp confirms the scan.</p>`, 4);
    return { status: 200, html };
  }

  async function reconnectPage(request) {
    const token = cookies(request)[reconnectCookie] || "";
    const reconnection = store.reconnection(token);
    if (!reconnection) return { status: 410, html: authPage("Reconnection expired", '<p>Start the secure reconnection again.</p><a class="button primary" href="/reconnect">Restart</a>') };
    const transport = await connections.reconnectTransport(reconnection);
    const setup = transport.setup();
    if (await transport.ready().catch(() => false)) {
      const user = await store.completeReconnect(token, setup.connectedPhone);
      const session = await store.createSession(user.id);
      return { redirect: "/app", headers: { "set-cookie": [secureCookie(sessionCookie, session.token, config.controlPlane.sessionHours * 3600), clearCookie(reconnectCookie), clearCookie(challengeCookie)] } };
    }
    const qr = setup.qr ? await QRCode.toDataURL(setup.qr, { margin: 2, width: 320 }) : "";
    const html = authPage("Reconnect your Key", `<p>On ${escapeHtml(maskPhone(reconnection.user.phone))}, open WhatsApp → Linked devices → Link a device.</p>${qr ? `<img class="qr" alt="Key.kiwe WhatsApp reconnection QR" src="${qr}">` : '<p class="notice warning">Preparing a fresh secure QR…</p>'}<p class="meta">Scanning proves control of the registered number, replaces only its offline connection, and signs you in automatically.</p>`, 4);
    return { status: 200, html };
  }

  async function handle(request, response) {
    const url = new URL(request.url || "/", baseUrl);
    const siteAction = url.pathname.match(/^\/sites\/([^/]+)\/(toggle|independent|inherit|reset)$/);
    const userAction = url.pathname.match(/^\/network\/users\/([^/]+)\/toggle$/);
    const managed = new Set(["/", "/app", "/login", "/login/verify", "/reconnect", "/reconnect/pair", "/register", "/register/pair", "/activate", "/logout", "/sites", "/account/replace", "/network"]);
    if (!managed.has(url.pathname) && !siteAction && !userAction) return false;
    const account = auth(request);
    try {
      if (request.method === "GET" && url.pathname === "/") return send(response, 200, marketingPage(account));
      if (request.method === "GET" && url.pathname === "/app") return account ? send(response, 200, await dashboard(account)) : redirect(response, "/login");
      if (request.method === "GET" && url.pathname === "/login") return account ? redirect(response, "/app") : send(response, 200, authPage("Welcome back", '<p class="muted">Enter the WhatsApp number that owns your Key.kiwe account.</p><form class="stack" method="post" action="/login"><label>WhatsApp number<input name="phone" inputmode="tel" autocomplete="tel" placeholder="919876543210" required></label><button class="primary" type="submit">Send my sign-in code</button></form><nav><a href="/reconnect">Primary WhatsApp offline? Reconnect it</a><br><a href="/register">Create a free account</a></nav>'));
      if (request.method === "POST" && url.pathname === "/login") {
        if (!loginLimiter.allow(clientKey(request), clock())) return send(response, 429, authPage("Try again later", "<p>Too many sign-in attempts were made from this connection.</p>"), { "retry-after": "900" });
        const form = new URLSearchParams(await requestBody(request));
        const challenge = await store.beginLogin(form.get("phone"));
        if (!challenge) return send(response, 401, authPage("Could not sign in", '<p class="notice warning">That number is not an active Key.kiwe account.</p><a class="button secondary" href="/login">Try again</a>'));
        try {
          await connections.sendLoginCode(challenge.user, challenge.code);
        } catch (error) {
          if (Number(error?.status) !== 503) throw error;
          const reconnect = await store.beginReconnect(challenge.user.phone);
          if (!reconnect) throw error;
          await connections.reconnectTransport(reconnect.reconnection);
          return redirect(response, "/reconnect/pair", { "set-cookie": [secureCookie(reconnectCookie, reconnect.token, 900), clearCookie(challengeCookie)] });
        }
        return redirect(response, "/login/verify", { "set-cookie": secureCookie(challengeCookie, challenge.token, 300) });
      }
      if (request.method === "GET" && url.pathname === "/login/verify") return send(response, 200, authPage("Enter your code", '<p class="muted">The six-digit code was sent by your own Key.kiwe WhatsApp connection to the registered number.</p><form class="stack" method="post" action="/login/verify"><label>Six-digit code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="primary" type="submit">Open my account</button></form><nav><a href="/login">Start again</a></nav>'));
      if (request.method === "POST" && url.pathname === "/login/verify") {
        const form = new URLSearchParams(await requestBody(request));
        const user = await store.verifyLogin(cookies(request)[challengeCookie] || "", form.get("code"));
        if (!user) return send(response, 401, authPage("Code not accepted", '<p class="notice warning">The code is wrong, expired, or has already been used.</p><a class="button secondary" href="/login">Request a new code</a>'));
        const session = await store.createSession(user.id);
        return redirect(response, "/app", { "set-cookie": [secureCookie(sessionCookie, session.token, config.controlPlane.sessionHours * 3600), clearCookie(challengeCookie)] });
      }
      if (request.method === "GET" && url.pathname === "/reconnect") return account ? redirect(response, "/app") : send(response, 200, authPage("Reconnect your Key", '<p class="muted">Use this only when your primary WhatsApp connection is offline. Scanning from the registered number proves ownership and restores access.</p><form class="stack" method="post" action="/reconnect"><label>Registered WhatsApp number<input name="phone" inputmode="tel" autocomplete="tel" placeholder="919876543210" required></label><button class="primary" type="submit">Show my reconnection QR</button></form><nav><a href="/login">Back to sign in</a></nav>'));
      if (request.method === "POST" && url.pathname === "/reconnect") {
        if (!reconnectLimiter.allow(clientKey(request), clock())) return send(response, 429, authPage("Try again later", "<p>Too many reconnection attempts were made from this connection.</p>"), { "retry-after": "3600" });
        const form = new URLSearchParams(await requestBody(request));
        const reconnect = await store.beginReconnect(form.get("phone"));
        if (!reconnect) return send(response, 401, authPage("Could not reconnect", '<p class="notice warning">That number is not an active Key.kiwe account.</p><a class="button secondary" href="/reconnect">Try again</a>'));
        await connections.reconnectTransport(reconnect.reconnection);
        return redirect(response, "/reconnect/pair", { "set-cookie": secureCookie(reconnectCookie, reconnect.token, 900) });
      }
      if (request.method === "GET" && url.pathname === "/reconnect/pair") {
        const result = await reconnectPage(request);
        return result.redirect ? redirect(response, result.redirect, result.headers) : send(response, result.status, result.html);
      }
      if (request.method === "GET" && url.pathname === "/register") return account ? redirect(response, "/app") : send(response, 200, authPage("Create your account", '<p class="muted">Your WhatsApp number becomes the primary secure connection for every website you add.</p><form class="stack" method="post" action="/register"><label>WhatsApp number<input name="phone" inputmode="tel" autocomplete="tel" placeholder="919876543210" required></label><button class="primary" type="submit">Continue to secure scan</button></form><nav><a href="/login">Already registered?</a></nav>'));
      if (request.method === "POST" && url.pathname === "/register") {
        if (!registrationLimiter.allow(clientKey(request), clock())) return send(response, 429, authPage("Try again later", "<p>Too many accounts were requested from this connection.</p>"), { "retry-after": "3600" });
        const form = new URLSearchParams(await requestBody(request));
        const created = await store.beginRegistration(form.get("phone"));
        return redirect(response, "/register/pair", { "set-cookie": secureCookie(pairingCookie, created.token, 900) });
      }
      if (request.method === "GET" && url.pathname === "/register/pair") {
        const result = await pairingPage(request);
        return result.redirect ? redirect(response, result.redirect, result.headers) : send(response, result.status, result.html);
      }
      if (url.pathname === "/activate") return redirect(response, account ? "/app" : "/login");
      if (request.method === "POST" && url.pathname === "/logout") {
        const form = new URLSearchParams(await requestBody(request));
        if (!validCsrf(account, form)) return send(response, 403, authPage("Session expired", '<p><a href="/login">Sign in again</a></p>'));
        await store.logout(cookies(request)[sessionCookie] || "");
        return redirect(response, "/", { "set-cookie": clearCookie(sessionCookie) });
      }
      if (!account) return redirect(response, "/login");
      if (request.method === "GET" && url.pathname === "/network") return account.user.role === "master" ? send(response, 200, await network(account)) : send(response, 403, await dashboard(account, "Error: Master access is required."));
      if (request.method === "POST" && userAction) {
        const form = new URLSearchParams(await requestBody(request));
        if (!validCsrf(account, form)) return send(response, 403, await network(account, "Your session changed. Refresh and try again."));
        await store.setUserStatus(account.user.id, decodeURIComponent(userAction[1]), form.get("status"));
        return send(response, 200, await network(account, "Account status updated."));
      }
      if (request.method === "POST" && url.pathname === "/account/replace") {
        const form = new URLSearchParams(await requestBody(request));
        if (!validCsrf(account, form)) return send(response, 403, await dashboard(account, "Error: Your session changed."));
        await store.preparePrimaryReplacement(account.user.id, form.get("phone"));
        await connections.pendingPrimaryTransport(store.userById(account.user.id));
        return send(response, 202, await dashboard(account, "Replacement pairing started. The current primary number stays live until the scan succeeds."));
      }
      if (request.method === "POST" && url.pathname === "/sites") {
        const form = new URLSearchParams(await requestBody(request));
        if (!validCsrf(account, form)) return send(response, 403, await dashboard(account, "Error: Your session changed."));
        const credentials = await store.createSite(account.user.id, form.get("label"), form.get("origin"));
        return send(response, 201, await dashboard(account, "Website connected through your primary number.", credentials));
      }
      if (request.method === "POST" && siteAction) {
        const siteId = decodeURIComponent(siteAction[1]);
        const form = new URLSearchParams(await requestBody(request));
        if (!validCsrf(account, form) || !store.siteForOwner(account.user.id, siteId)) return send(response, 403, await dashboard(account, "Error: This website action was not authorized."));
        if (siteAction[2] === "toggle") { await store.setSiteActive(account.user.id, siteId, form.get("active") === "1"); return send(response, 200, await dashboard(account, form.get("active") === "1" ? "Website activated." : "Website deactivated; its history and settings were preserved.")); }
        if (siteAction[2] === "independent") { await connections.beginDedicated(account.user.id, siteId); return send(response, 200, await dashboard(account, "Client-number pairing started. The primary number remains live until the scan succeeds.")); }
        if (siteAction[2] === "inherit") { await connections.inheritPrimary(account.user.id, siteId); return send(response, 200, await dashboard(account, "This website now uses your primary number.")); }
        if (siteAction[2] === "reset") {
          if (form.get("confirm") !== "CHANGE") return send(response, 400, await dashboard(account, "Error: Type CHANGE exactly before replacing this website’s number."));
          await connections.resetSite(account.user.id, siteId);
          return send(response, 202, await dashboard(account, "This website has a fresh client-number pairing session."));
        }
      }
      return send(response, 405, authPage("Method not allowed", '<p><a href="/">Return to Key.kiwe</a></p>'), { allow: "GET, POST" });
    } catch (error) {
      const status = Number(error?.status) || 400;
      if (account) return send(response, status, account.user.role === "master" && url.pathname.startsWith("/network") ? await network(account, `Error: ${error.message || "The request could not be completed."}`) : await dashboard(account, `Error: ${error.message || "The request could not be completed."}`));
      return send(response, status, authPage("Could not continue", `<aside class="notice warning">${escapeHtml(error.message || "The request could not be completed.")}</aside><p><a href="/">Return to Key.kiwe</a></p>`), error?.retryAfter ? { "retry-after": String(error.retryAfter) } : {});
    }
  }

  return { handle };
}
