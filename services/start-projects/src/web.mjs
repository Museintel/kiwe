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
  const cards = projects.map((project) => `<li>
<a href="/projects/${project.id}">${escape(project.domain || 'Choose a domain')}</a>
<p>${escape(project.planId)} · ${project.hostingIncluded ? 'Kiwe hosting included' : 'Your existing hosting'}</p>
</li>`).join('');
  return page('Your projects', cards ? `<ul class="projects">${cards}</ul>` : '<p>Your purchased projects will appear here.</p>', true);
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

export function projectPage(project, { error, managed = false } = {}) {
  const alert = error ? `<p class="error" role="alert">${escape(error)}</p>` : '';
  const status = project.site
    ? `<p class="status">Website verified at <a href="${escape(project.site.url)}">${escape(project.domain)}</a>. Next, connect Key.</p>`
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
  return page('Choose your domain', `<p><a href="/projects">All projects</a></p>
${alert}${status}
<form method="post" action="/projects/${project.id}/domain">
<label for="domain">Domain</label>
<input id="domain" name="domain" inputmode="url" spellcheck="false" placeholder="example.com" maxlength="253" value="${escape(project.domain)}" required>
${selection}
<input type="hidden" name="revision" value="${project.revision}">
<button class="button">Save domain</button>
</form>
${connect}${hostingStep(project, managed)}`, true);
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
input, select {
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
.status, .error { padding: .75rem 1rem; border-radius: .4rem; }
.status { background: #e7f3ec; }
.error { color: #711825; background: #fce8eb; }
`;
