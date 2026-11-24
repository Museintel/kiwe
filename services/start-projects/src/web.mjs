import { documentPath, wooPresentation } from './document-targets.mjs';
import { clientPlan } from './client-plan.mjs';

const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[character]);

function page(title, body, signedIn = false) {
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escape(title)} · Kiwe Start</title>
<link rel="stylesheet" href="/projects/style.css">
</head>
<body>
<header><a href="/projects">Kiwe Start</a>
${signedIn ? '<form method="post" action="/projects/sign-out"><button class="link">Sign out</button></form>' : ''}
</header>
<main>
<h1>${escape(title)}</h1>
${body}
</main>
</body>
</html>`;
}

export function signInPage(checkoutOrigin, message, browserLogin, pilot, launchBuyers = false) {
  const details = message || (pilot ? 'Sign in with the Key account invited to the Kiwe Launch agency pilot.'
    : browserLogin ? 'Use your Kiwe Launch account to open your site projects.' : 'Open your project using your Kiwe Launch enrollment link.');
  const form = browserLogin ? `<form method="post" action="/projects/sign-in">
${pilot ? '' : `<details><summary>I have a client invitation</summary>
<label for="invite-code">Invitation code</label>
<input id="invite-code" name="inviteCode" maxlength="43" autocomplete="off" spellcheck="false">
</details>`}
<button class="button">${pilot ? 'Continue with Key' : 'Continue to sign in'}</button>
</form>` : '';
  const buyer = launchBuyers ? `<p><a href="${escape(checkoutOrigin + '/checkout/#sign-in')}">Sign in with your Kiwe Launch account</a></p>` : '';
  return page('Log in to Kiwe Start',
    `<p${message ? ' class="error" role="alert"' : ''}>${escape(details)}</p>${form}${buyer}<p><a href="${escape(checkoutOrigin)}">Go to Kiwe Launch</a></p>`);
}

export function pilotMcpPage(token) {
  const credential = token ? `<p>Copy this token into your MCP client’s authorization settings. It expires in 20 minutes or when you sign out.</p>
<label for="pilot-token">Access token</label>
<input type="text" id="pilot-token" value="${escape(token)}" autocomplete="off" spellcheck="false" readonly>` : '';
  return page('MCP access', `<p><a href="/projects">All projects</a></p><p>Connect to <code>/projects/mcp</code> with a temporary bearer token.</p>
${credential}<form method="post" action="/projects/mcp-access">
<button class="button">${token ? 'Replace token' : 'Create token'}</button>
</form>`, true);
}

export function projectsPage(projects, agencySites, error, pilot = false) {
  const section = (items, details = '') => items.length ? `<section>
<h2>${escape(items[0].workspace.name)}</h2>${details}
<ul class="projects">${items.map((project) => `<li>
<a href="/projects/${project.id}">${escape(project.domain || 'Choose a domain')}</a>
<p>${project.access === 'client' ? 'Client workspace' : `${escape(project.planId)} · ${project.hostingIncluded ? 'Kiwe hosting included' : 'Your existing hosting'}`}</p>
</li>`).join('')}</ul></section>` : '';
  const clients = projects.filter(({ accountType }) => accountType === 'client');
  const agency = projects.filter(({ accountType }) => accountType === 'agency');
  const mcp = pilot ? '<p><a href="/projects/mcp-access">MCP access</a></p>' : '';
  const agencySettings = '<p><a href="/projects/agency">Agency workspace settings</a></p>';
  const allowance = agencySites ? `<p>${agencySites.used} of ${agencySites.limit} site slots reserved</p>` : '';
  const addSite = agencySites && agencySites.used < agencySites.limit ? `<form method="post" action="/projects/agency/sites">
<label for="new-domain">Domain of an existing WordPress site</label>
<input id="new-domain" name="domain" inputmode="url" placeholder="example.com" maxlength="253" required>
<button class="button">Add site</button>
</form>` : '';
  const alert = error ? `<p class="error" role="alert">${escape(error)}</p>` : '';
  return page('Your projects', alert + mcp + (projects.length
    ? section(clients) + section(agency, agencySettings + allowance + addSite)
    : '<p>Your purchased projects will appear here.</p>'), true);
}

export function agencyPage({ workspace, pending }, error) {
  const alert = error ? `<p class="error" role="alert">${escape(error)}</p>` : '';
  const current = workspace.website ? `<p class="status">Verified agency website <a href="${escape(workspace.website)}">${escape(workspace.website)}</a></p>` : '';
  const proof = pending ? `<section class="card">
<h2>Verify ${escape(pending.website)}</h2>
<p>Publish a plain text file at this address on your agency website.</p>
<p><code>${escape(pending.proofUrl)}</code></p>
<p>Use this exact text as the entire file.</p>
<p><code>${escape(pending.proofText)}</code></p>
<p>The proof expires after 24 hours.</p>
<form method="post" action="/projects/agency/verify">
<input type="hidden" name="attemptId" value="${escape(pending.attemptId)}">
<button class="button">Verify website</button>
</form></section>` : '';
  return page('Agency workspace', `<p><a href="/projects">All projects</a></p>
${alert}${current}
<form method="post" action="/projects/agency">
<label for="agency-name">Agency name</label>
<input id="agency-name" name="name" maxlength="80" value="${escape(pending?.name || (workspace.website ? workspace.name : ''))}" required>
<label for="agency-domain">Agency website domain</label>
<input id="agency-domain" name="domain" inputmode="url" placeholder="agency.com" maxlength="253" value="${escape((pending?.website || workspace.website || '').slice('https://'.length))}" required>
<button class="button">Set agency website</button>
</form>${proof}`, true);
}

function hostingStep(project, available) {
  if (!available || project.hosting !== 'included') return '';
  const hosted = project.managedSite;
  if (hosted?.status === 'ready') {
    return `<p class="status">WordPress is ready on your <a href="${escape(hosted.url)}">temporary address</a>. Install Kiwe and its MCP App there before connecting it here.</p>`;
  }
  if (hosted?.status === 'needs_attention') {
    return '<p class="error" role="alert">Hosting setup needs attention. Your project is saved.</p>';
  }
  return `${hosted ? '<p class="status">Hosting setup is in progress.</p>' : ''}
<form method="post" action="/projects/${project.id}/hosting">
<input type="hidden" name="revision" value="${project.revision}">
<button class="button">${hosted ? 'Check hosting status' : 'Set up Kiwe hosting'}</button>
</form>`;
}

function keyStep(project, available) {
  if (!available || !project.site) return '';
  const messages = {
    awaiting_approval: 'Approve the website in Key to choose its account.',
    sender_ready: project.key?.siteAuthenticated
      ? 'WhatsApp is connected to this WordPress site.'
      : 'The Key sender is ready. Open Key for the one-time WordPress setup code.',
    sender_offline: 'Open Key to reconnect the WhatsApp sender.',
    inactive: 'This Key website or account is inactive.',
  };
  const status = project.key ? `<p class="status">${escape(messages[project.key.state])}</p>` : '';
  const pairing = project.key?.pairingPending ? '<p>The client number is waiting for a WhatsApp scan.</p>' : '';
  const label = project.key ? 'Check Key connection' : 'Connect Key';
  const manage = project.key?.manageUrl && project.key.state !== 'awaiting_approval'
    ? `<p><a href="${escape(project.key.manageUrl)}">Open Key</a></p>` : '';
  return `${status}${pairing}${manage}<form method="post" action="/projects/${project.id}/key">
<input type="hidden" name="revision" value="${project.revision}">
<button class="button">${label}</button>
</form>`;
}

function designContextButton(site) {
  return `<a class="button" href="${escape(site.designContextUrl)}">Open Design Context in WordPress</a>`;
}

export function clientProjectPage(project) {
  return page(project.domain, `<p><a href="/projects">All projects</a></p>
<p>Your website is linked to this Kiwe account.</p>
<p>${designContextButton(project.site)}</p>
<p><a href="${escape(project.site.url)}">Visit your website</a></p>`, true);
}

function clientAccessPanel(project, state, invitation) {
  if (!state) return '';
  const notice = invitation ? `<p class="status">Share the <a href="/login/">Start login page</a> and this one-time code privately. The client signs in with the email you selected. The code expires in seven days.</p>
<p><code>${escape(invitation.inviteCode)}</code></p>` : '';
  const invite = state !== 'active' ? `<form method="post" action="/projects/${project.id}/invite-client">
<label for="client-email">Client email</label>
<input type="email" name="email" id="client-email" maxlength="254" required>
<input type="hidden" name="revision" value="${project.revision}">
<button class="button">${state === 'invited' ? 'Replace invitation' : 'Create invitation'}</button></form>` : '';
  const revoke = state !== 'none' ? `<form method="post" action="/projects/${project.id}/revoke-client">
<button class="link">${state === 'active' ? 'Revoke client access' : 'Cancel invitation'}</button></form>` : '';
  return `<section class="card"><h2>Client access</h2>
<p>${state === 'active' ? 'Your client can open this website in their own Start dashboard.' : 'Invite your client to sign in with their own Kiwe account.'}</p>
${notice}${invite}${revoke}</section>`;
}

export function projectPage(project, { error, managed = false, keyEnabled = false, clientAccess = null, invitation = null } = {}) {
  const alert = error ? `<p class="error" role="alert">${escape(error)}</p>` : '';
  const purchased = project.planId === clientPlan.id;
  const status = project.site
    ? `<p class="status">Website verified at <a href="${escape(project.site.url)}">${escape(new URL(project.site.url).hostname)}</a>.${project.next === 'fill_design_context' ? ' Next, fill in Design Context in WordPress.' : ''}</p>`
    : project.domain && !project.managedSite
      ? `<p class="status">Domain saved. Next, ${project.hosting === 'included' ? 'set up Kiwe hosting' : 'connect your WordPress site'}.</p>`
      : '';
  const connect = project.next === 'connect_site' || project.next === 'activate_kiwe'
    ? `<form method="post" action="/projects/${project.id}/connect">
<input type="hidden" name="revision" value="${project.revision}">
<button class="button">Connect WordPress site</button>
</form>`
    : '';
  const selection = project.hostingIncluded
    ? `<label for="hosting">Hosting</label>
<select id="hosting" name="hosting" required>
${project.hosting ? '' : '<option value="" disabled selected>Choose hosting</option>'}
<option value="included"${project.hosting === 'included' ? ' selected' : ''}>Kiwe hosting included in my plan</option>
<option value="existing"${project.hosting === 'existing' ? ' selected' : ''}>I have an existing WordPress site</option>
</select>`
    : '<input type="hidden" name="hosting" value="existing"><p>You’ll connect an existing WordPress site.</p>';
  const domainForm = purchased ? '' : `<form method="post" action="/projects/${project.id}/domain">
<label for="domain">Domain</label>
<input id="domain" name="domain" inputmode="url" spellcheck="false" placeholder="example.com" maxlength="253" value="${escape(project.domain)}" required>
${selection}
<input type="hidden" name="revision" value="${project.revision}">
<button class="button">Save domain</button>
</form>`;
  return page(purchased ? project.domain : 'Choose your domain', `<p><a href="/projects">All projects</a>${project.workspace.type === 'agency' ? ` · <a href="/projects/agency">${escape(project.workspace.name)}</a>` : ' · Kiwe Launch'}</p>
${alert}${status}${domainForm}
${project.site ? '' : hostingStep(project, managed)}${connect}${keyStep(project, keyEnabled)}
${clientAccessPanel(project, clientAccess, invitation)}
${project.site ? `<p>${designContextButton(project.site)} <a class="button" href="/projects/${project.id}/pages">Pages &amp; Templates</a></p>` : ''}
${project.key?.siteAuthenticated && project.key.state !== 'inactive' ? `<p><a class="button" href="/projects/${project.id}/homepage">Homepage draft</a></p>` : ''}`, true);
}

export function websitePage(project, state, message) {
  const base = '/projects/' + project.id;
  const alert = message ? `<p class="error" role="alert">${escape(message)}</p>` : '';
  const updated = state.inventory
    ? `<p class="muted">WordPress inventory checked ${escape(state.inventory.checkedAt.slice(0, 10))}.</p>`
    : '<p>Refresh the website inventory to see its current WordPress pages and Store routes.</p>';
  const rows = state.items.map(item => {
    const convert = item.convert ? `<form class="website-convert" method="post" action="${escape(item.convert.href)}">
<input type="hidden" name="draftId" value="${escape(item.convert.draftId)}"><input type="hidden" name="revision" value="${escape(item.convert.revision)}">
<button class="button">Convert to Bricks</button></form>` : '';
    const links = item.links.map(link => `<a href="${escape(link.href)}">${escape(link.label)}</a>`).join('<br>');
    return `<tr><th scope="row">${escape(item.name)}<span class="website-mobile-type">${escape(item.type)}</span></th>
<td class="website-type">${escape(item.type)}</td><td>${escape(item.status)}${item.designStatus ? `<br><strong>${escape(item.designStatus)}</strong>` : ''}</td>
<td>${convert}${links}</td></tr>`;
  }).join('');
  return page('Pages & Templates', `<p><a href="${base}">Project</a> · ${escape(project.domain)}</p>
${alert}${updated}
<form method="post" action="${base}/pages/refresh"><input type="hidden" name="revision" value="${project.revision}">
<button class="button">${state.inventory ? 'Refresh website inventory' : 'Read website inventory'}</button></form>
<div class="website-rows"><table><caption>${escape(project.domain)} pages and templates</caption>
<thead><tr><th>Page or template</th><th class="website-type">Type</th><th>Status</th><th>Action</th></tr></thead><tbody>${rows}</tbody></table></div>`, true);
}

function conversionStep(location, revision, draft, options) {
  if (!options.compiler || draft.status !== 'approved') return '';
  const conversion = options.conversion;
  const hidden = revision + `<input type="hidden" name="draftId" value="${draft.id}">`;
  if (conversion?.recoveryRequired) {
    return `<p class="status">Check the saved imports in WordPress Framework.</p>
<p><a href="${escape(conversion.frameworkUrl)}">Open WordPress Framework</a></p>`;
  }
  if (!conversion || (!conversion.reviewUrl && !conversion.sessionActive)) {
    return `<form method="post" action="${location}/convert">${hidden}<button class="button">Convert to Bricks</button></form>`;
  }
  if (conversion.status === 'draft_ready') {
    return `<p class="status">Private Bricks draft is ready for review.</p><p><a class="button" href="${escape(conversion.builderUrl)}">Open Bricks draft</a></p>`;
  }
  const nativeLink = conversion.approvalUrl || conversion.reviewUrl;
  const action = nativeLink ? `<p><a class="button" href="${escape(nativeLink)}">${conversion.approvalUrl ? 'Approve draft import in WordPress' : 'Open native import review'}</a></p>` : '';
  const check = conversion.sessionActive ? `<form method="post" action="${location}/continue-import">${hidden}<button class="button">Check Bricks import</button></form>` : '';
  return action + check;
}

function publicationStep(location, revision, draft, options, target, origin) {
  if (options.conversion?.status !== 'draft_ready') return '';
  const published = options.publication;
  const action = published?.status === 'published' || published?.action === 'restore' ? 'restore' : 'publish';
  const hidden = revision + '<input type="hidden" name="draftId" value="' + escape(draft.id)
    + '"><input type="hidden" name="action" value="' + action + '">';
  const form = (route, label) => '<form method="post" action="' + location + '/' + route + '">'
    + hidden + '<button class="button">' + label + '</button></form>';
  const review = published?.sessionActive && published.approvalUrl
    ? '<p><a class="button" href="' + escape(published.approvalUrl) + '">Approve in WordPress</a></p>'
      + form('finish-publication', published.action === 'restore' ? 'Check restoration' : 'Check publication')
    : form('publication', action === 'restore' ? (target ? 'Restore previous design' : 'Restore previous homepage')
      : (target ? 'Publish design' : 'Publish homepage'));
  const destination = published?.url || (published?.pageId ? origin + '/wp-admin/post.php?action=edit&post=' + published.pageId : '');
  const state = published?.status === 'published'
    ? '<p class="status">' + (target ? 'Published. ' : 'Published on the homepage. ')
      + '<a href="' + escape(destination) + '">' + (target ? (published.url ? 'View page' : 'Open in Bricks') : 'View homepage') + '</a>'
      + (published.blogUrl ? ' · <a href="' + escape(published.blogUrl) + '">View Blog</a>' : '') + '</p>'
    : published?.status === 'restored' ? '<p class="status">' + (target ? 'The previous design' : 'The previous homepage') + ' was restored.</p>' : '';
  const cleanup = published?.importCleanupPending ? '<p>Remove the completed import in WordPress Framework to free an import slot.</p>' : '';
  return '<section id="publication"><h2>' + (target ? 'Publication' : 'Homepage publication') + '</h2>' + state + cleanup + review + '</section>';
}

export function homepagePage(project, view, options = {}) {
  const target = view.target;
  const location = documentPath(project.id, target?.id);
  const label = target?.name ?? 'Homepage';
  const title = label + ' draft';
  const generate = target ? 'Generate design' : 'Generate homepage';
  const draft = view.draft;
  const error = options.error ? `<p class="error" role="alert">${escape(options.error)}</p>` : '';
  const revision = `<input type="hidden" name="revision" value="${project.revision}">`;
  const request = `<form class="card" method="post" action="${location}/request">
<label for="homepage-direction">Design direction</label>
<textarea id="homepage-direction" name="feedback" rows="3" maxlength="1200" placeholder="Describe a layout, mood or reference."></textarea>
${revision}<button class="button">${draft ? 'Refresh Design Context and generate' : generate}</button></form>`;
  const back = target ? '<a href="/projects/' + project.id + '/pages">Pages &amp; Templates</a>' : '<a href="/projects/' + project.id + '">Project</a>';
  const nativeNote = target && wooPresentation.has(target.id)
    ? '<p>Preview the design that appears above the live WooCommerce controls on this route.</p>' : '';
  const designContext = `<p>${designContextButton(project.site)}</p>`;
  if (!draft && target && !view.homeApproved) {
    return page(title, '<p>' + back + '</p>' + error
      + '<p><a href="' + documentPath(project.id) + '">Approve the homepage</a> to set the design direction for this website.</p>', true);
  }
  if (!draft) {
    return page(title, `<p>${back} · ${escape(project.domain)}</p>
${error}${nativeNote}${designContext}${request}`, true);
  }
  const ready = draft.status !== 'waiting_for_design';
  const status = draft.status === 'approved' ? 'Approved for Seam conversion.'
    : ready ? 'Ready for your review.' : options.generator ? 'The design request is saved.' : 'Waiting for your connected AI to prepare the design.';
  const preview = ready ? `<iframe class="homepage-preview" title="${escape(label)} preview" sandbox="allow-scripts"
src="${location}/preview?draft=${draft.id}"></iframe><p><a href="${location}/preview?draft=${draft.id}">Open full-width preview</a></p>` : '';
  const approve = draft.status === 'review' ? `<form method="post" action="${location}/approve">
<input type="hidden" name="draftId" value="${draft.id}"><input type="hidden" name="sha256" value="${escape(draft.sourceSha256)}">
<button class="button">${target ? 'Approve design' : 'Approve this homepage'}</button></form>` : '';
  const download = draft.sourceUrl ? `<p><a class="button" href="${location}/source?draft=${draft.id}&amp;format=seam">Download for Seam</a>
<a href="${location}/source?draft=${draft.id}">Download the raw preview files</a></p>` : '';
  const retry = !ready && options.generator ? `<form method="post" action="${location}/generate"><button class="button">Generate draft</button></form>` : '';
  const changes = `<form class="card" method="post" action="${location}/revise">
<label for="homepage-feedback">What would you change?</label>
<textarea id="homepage-feedback" name="feedback" rows="3" maxlength="1200"></textarea>
${revision}<input type="hidden" name="draftId" value="${draft.id}">
<button class="button">Request another revision</button>${target ? '<button class="button" name="regenerate" value="1">Regenerate design</button>' : ''}</form>`;
  return page(title, `<p>${back} · ${escape(project.domain)}</p>
${error}${nativeNote}<p class="status">Revision ${draft.version} · ${status}</p>${preview}${approve}${conversionStep(location, revision, draft, options)}${publicationStep(location, revision, draft, options, target, project.site.url)}${download}${retry}
${changes}<details><summary>Refresh saved business facts</summary>${designContext}${request}</details>`, true);
}

export function siteAuthorizationPage(project, authorizationUrl, purpose = 'approve the connection to Kiwe Start', designReview) {
  const back = designReview
    ? `<a href="${escape(designReview)}">Back to design review</a>`
    : `<a href="/projects/${project.id}">Back to project</a>`;
  return page('Authorize this website',
    `<p>Sign in to WordPress at <strong>${escape(new URL(authorizationUrl).hostname)}</strong> and ${escape(purpose)}.</p>
<a class="button" href="${escape(authorizationUrl)}">Open WordPress authorization</a>
<p>${back}</p>`, true);
}

export function siteReturnPage(message, approved) {
  return page(approved ? 'Website connected' : 'Website authorization needed',
    `<p>${escape(message)}</p><a class="button" href="/projects">Return to Kiwe Start</a>`);
}

export const stylesheet = `:root {
  font-family: system-ui, sans-serif;
  color: #14241d;
  background: #f7f9f6;
  line-height: 1.55;
}
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; }
header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem max(1.25rem, calc((100vw - 42rem) / 2));
  border-bottom: 1px solid #d8e4dc;
}
header a { font-weight: 700; text-decoration: none; }
main { max-width: 44.5rem; margin: 2rem auto; padding: 0 1.25rem 3rem; }
h1 { line-height: 1.18; margin-bottom: 1.25rem; }
a, .link { color: #1b6548; }
.link { appearance: none; background: none; border: 0; font: inherit; text-decoration: underline; }
label { display: block; margin-top: 1rem; font-weight: 650; }
input, select, textarea {
  display: block;
  width: 100%;
  min-height: 2.75rem;
  margin: .3rem 0 .8rem;
  border: 1px solid #83978b;
  background: white;
  border-radius: .4rem;
  padding: .55rem .7rem;
  color: inherit;
  font: inherit;
}
.button {
  display: inline-block;
  border: 0;
  border-radius: .4rem;
  background: #17523b;
  color: white;
  padding: .6rem 1rem;
  margin-top: .6rem;
  font: inherit;
  font-weight: 650;
  text-decoration: none;
}
button { cursor: pointer; }
:focus-visible { outline: 3px solid #05754c; outline-offset: 3px; }
.projects { list-style: none; padding: 0; }
.projects li { padding: 1rem 0; border-bottom: 1px solid #d8e4dc; }
.projects li p { margin: .3rem 0 0; }
.card { border: 1px solid #d8e4dc; border-radius: .6rem; margin-top: 1.5rem; padding: 1rem; }
.card code, blockquote { overflow-wrap: anywhere; }
main:has(.homepage-preview) { max-width: 92rem; }
.website-rows { overflow-x: auto; margin-top: 1.25rem; }
.website-rows table { width: 100%; border-collapse: collapse; text-align: left; }
.website-rows caption { text-align: left; padding-bottom: .5rem; }
.website-rows th, .website-rows td { padding: .65rem .55rem; border-bottom: 1px solid #ceddd4; vertical-align: top; }
.website-rows th, .website-rows a { overflow-wrap: anywhere; }
.website-convert .button { margin: 0 0 .5rem; padding-inline: .7rem; }
.website-mobile-type { display: none; }
@media (max-width: 35rem) {
  .website-rows table { table-layout: fixed; }
  .website-rows th, .website-rows td { padding-inline: .4rem; overflow-wrap: anywhere; }
  .website-rows .website-type { display: none; }
  .website-mobile-type { display: block; font-size: .85rem; font-weight: normal; }
}
.homepage-preview { display: block; width: 100%; height: min(72vh, 53rem); min-height: 26rem; border: 1px solid #83978b; border-radius: .6rem; background: white; }
details { margin-top: 1rem; }
blockquote { margin: .5rem 0 1rem; border-left: 3px solid #aec7b8; padding: .5rem .9rem; white-space: pre-wrap; }
.status, .error { padding: .75rem 1rem; border-radius: .4rem; }
.status { background: #e7f3ec; }
.error { color: #711825; background: #fce8eb; }
`;
