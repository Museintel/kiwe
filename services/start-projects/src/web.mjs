import { randomUUID } from 'node:crypto';

import { documentPath, wooPresentation } from './document-targets.mjs';

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

export function signInPage(checkoutOrigin, message = 'Sign in through Kiwe Launch to open your projects.') {
  return page('Open your Kiwe project',
    `<p>${escape(message)}</p><a class="button" href="${escape(checkoutOrigin)}">Go to Kiwe Launch</a>`);
}

export function projectsPage(projects) {
  const section = (items, details = '') => items.length ? `<section>
<h2>${escape(items[0].workspace.name)}</h2>${details}
<ul class="projects">${items.map((project) => `<li>
<a href="/projects/${project.id}">${escape(project.domain || 'Choose a domain')}</a>
<p>${escape(project.planId)} · ${project.hostingIncluded ? 'Kiwe hosting included' : 'Your existing hosting'}</p>
</li>`).join('')}</ul></section>` : '';
  const clients = projects.filter(({ accountType }) => accountType === 'client');
  const agency = projects.filter(({ accountType }) => accountType === 'agency');
  return page('Your projects', projects.length
    ? section(clients) + section(agency, '<p><a href="/projects/agency">Agency workspace settings</a></p>')
    : '<p>Your purchased projects will appear here.</p>', true);
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
    return `<p class="status">WordPress is ready on your <a href="${escape(hosted.url)}">temporary address</a>. Next, activate Kiwe.</p>`;
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

export function projectPage(project, { error, managed = false, keyEnabled = false } = {}) {
  const alert = error ? `<p class="error" role="alert">${escape(error)}</p>` : '';
  const status = project.site
    ? `<p class="status">Website verified at <a href="${escape(project.site.url)}">${escape(project.domain)}</a>.${project.next === 'fill_design_context' ? ' Next, fill in Design Context.' : ''}</p>`
    : project.domain && !project.managedSite
      ? `<p class="status">Domain saved. Next, ${project.hosting === 'included' ? 'set up Kiwe hosting' : 'connect your WordPress site'}.</p>`
      : '';
  const connect = project.next === 'connect_site'
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
  return page('Choose your domain', `<p><a href="/projects">All projects</a>${project.workspace.type === 'agency' ? ` · <a href="/projects/agency">${escape(project.workspace.name)}</a>` : ' · Kiwe Launch'}</p>
${alert}${status}
<form method="post" action="/projects/${project.id}/domain">
<label for="domain">Domain</label>
<input id="domain" name="domain" inputmode="url" spellcheck="false" placeholder="example.com" maxlength="253" value="${escape(project.domain)}" required>
${selection}
<input type="hidden" name="revision" value="${project.revision}">
<button class="button">Save domain</button>
</form>
${connect}${hostingStep(project, managed)}${keyStep(project, keyEnabled)}
${project.site ? `<p><a class="button" href="/projects/${project.id}/pages">Pages &amp; Templates</a></p>` : ''}
${project.key?.siteAuthenticated && project.key.state !== 'inactive' ? `<p><a class="button" href="/projects/${project.id}/context">Open Design Context</a> <a class="button" href="/projects/${project.id}/homepage">Homepage draft</a></p>` : ''}`, true);
}

const contextNames = { identity: 'Business identity', story: 'Story and audience', contact: 'Public contact' };

function contextLink(project) {
  return '/projects/' + project.id + '/context';
}

function fieldControl(field, id) {
  const label = `<label for="${id}">${escape(field.label)}</label>`;
  if (field.type === 'select') {
    return label + `<select id="${id}" name="value">${field.choices.map(choice => (
      `<option value="${escape(choice.value)}"${choice.value === field.value ? ' selected' : ''}>${escape(choice.label)}</option>`
    )).join('')}</select>`;
  }
  if (field.type === 'textarea') {
    return label + `<textarea id="${id}" name="value" rows="4" maxlength="${field.maxLength}">${escape(field.value)}</textarea>`;
  }
  return label + `<input id="${id}" name="value" type="${field.type}" maxlength="${field.maxLength}" value="${escape(field.value)}">`;
}

export function contextPage(project, area, view, error) {
  const location = contextLink(project);
  const status = error ? `<p class="error" role="alert">${escape(error)}</p>` : '';
  const menu = `<nav aria-label="Design Context sections"><ul class="context-nav">${Object.entries(contextNames).map(([part, label]) => (
    `<li><a href="${location}?area=${part}"${area === part ? ' aria-current="page"' : ''}>${label}</a></li>`
  )).join('')}</ul></nav>`;
  const authorization = project.key?.siteAuthenticated && project.key.state !== 'inactive'
    ? `<form method="post" action="${location}/authorize">
<input type="hidden" name="revision" value="${project.revision}">
<button class="button">Authorize in WordPress</button></form>` : '';
  const section = view?.section;
  const details = section ? `<h2>${contextNames[area]}</h2>
${section.fields.map((field, index) => `<form class="context-field" method="post" action="${location}?area=${area}">
${fieldControl(field, 'context-field-' + index)}
<input type="hidden" name="revision" value="${project.revision}">
<input type="hidden" name="area" value="${area}">
<input type="hidden" name="field" value="${escape(field.path)}">
<input type="hidden" name="expected" value="${escape(section.expected)}">
<input type="hidden" name="reviewId" value="${randomUUID()}">
<button class="button">Save ${escape(field.label)}</button>
</form>`).join('')}
<form class="context-end" method="post" action="${location}/close">
<button class="link">Close WordPress access</button></form>` : authorization;
  const native = project.site ? `<p><a href="${escape(project.site.url)}/wp-admin/admin.php?page=kiwe-design-context">Open the full Design Context journey in WordPress</a></p>` : '';
  return page('Design Context', `<p><a href="/projects/${project.id}">Project</a> · ${escape(project.domain)}</p>
${status}<p>Enter the public business facts for this website. Changes save in WordPress.</p>
${menu}${details}${native}`, true);
}

export function contextReviewPage(project, review, reviewId) {
  const location = contextLink(project);
  return page('Review Design Context change', `<p><a href="${location}?area=${review.area}">Back to Design Context</a></p>
<p>Website <strong>${escape(project.domain)}</strong></p>
<h2>${escape(review.label)}</h2>
<p>Current value</p><blockquote>${escape(review.before || 'Empty')}</blockquote>
<p>New value</p><blockquote>${escape(review.value || 'Clear the current value')}</blockquote>
<form method="post" action="${location}/review/${reviewId}">
<button class="button">Confirm and save to WordPress</button></form>`, true);
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
  if (!draft && target && !view.homeApproved) {
    return page(title, '<p>' + back + '</p>' + error
      + '<p><a href="' + documentPath(project.id) + '">Approve the homepage</a> to set the design direction for this website.</p>', true);
  }
  if (!draft) {
    return page(title, `<p>${back} · ${escape(project.domain)}</p>
${error}${nativeNote}${target ? '' : '<p>Build the first homepage from this site’s saved Design Context.</p>'}${request}`, true);
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
${changes}<details><summary>Refresh saved business facts</summary>${request}</details>`, true);
}

export function siteAuthorizationPage(project, authorizationUrl) {
  return page('Authorize this website',
    `<p>Sign in to WordPress at <strong>${escape(project.domain)}</strong> and approve the connection to Kiwe Start.</p>
<a class="button" href="${escape(authorizationUrl)}">Open WordPress authorization</a>
<p><a href="/projects/${project.id}">Back to project</a></p>`, true);
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
.context-nav { display: flex; gap: .7rem 1rem; flex-wrap: wrap; list-style: none; padding: 0; }
.context-nav [aria-current] { font-weight: 700; }
.context-field { padding-bottom: 1rem; border-bottom: 1px solid #d8e4dc; }
.context-end { margin-top: 1rem; }
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
