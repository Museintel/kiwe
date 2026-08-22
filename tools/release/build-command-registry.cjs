#!/usr/bin/env node

const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const repoRoot = path.resolve(__dirname, '..', '..');
const publicRoot = path.join(repoRoot, 'public', 'start.kiwelaunch.com');
const baseUrl = 'https://start.kiwelaunch.com';
const checkOnly = process.argv.includes('--check');
const textExtensions = new Set(['.cjs', '.css', '.html', '.js', '.json', '.md', '.txt', '.xml']);

function canonicalText(value) {
  return String(value).replace(/\r\n?/g, '\n');
}

function canonicalFile(file) {
  const body = fs.readFileSync(file);
  if (!textExtensions.has(path.extname(file).toLowerCase())) return body;
  return Buffer.from(canonicalText(body.toString('utf8')), 'utf8');
}

function readJson(file) {
  return JSON.parse(canonicalFile(file).toString('utf8'));
}

function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

function write(root, relative, value) {
  const target = path.join(root, relative);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, value);
}

function copy(root, source, relative) {
  write(root, relative, canonicalFile(source));
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function routeFor(command) {
  return command
    .trim()
    .split(/\s+/)
    .map((token) => token.replace(/^\/+/, '').toLowerCase())
    .filter(Boolean)
    .join('/');
}

function renderPage({ title, eyebrow, summary, body, machineUrl, canonicalUrl, contractVersion }) {
  const jsonLd = JSON.stringify({
    '@context': 'https://schema.org',
    '@type': 'TechArticle',
    name: title,
    description: summary,
    version: contractVersion,
    url: canonicalUrl,
  }).replaceAll('<', '\\u003c');

  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="${escapeHtml(summary)}">
  <link rel="canonical" href="${escapeHtml(canonicalUrl)}">
  <title>${escapeHtml(title)} · SeamFlow</title>
  <script type="application/ld+json">${jsonLd}</script>
  <style>
    :root{color-scheme:light dark;font-family:Inter,ui-sans-serif,system-ui,sans-serif;--bg:#f4f2ea;--ink:#102d29;--card:#fffdf7;--line:#a7b9b4;--accent:#0b8277;--code:#e4eee9}
    @media(prefers-color-scheme:dark){:root{--bg:#091713;--ink:#eaf6f1;--card:#10231e;--line:#36564e;--accent:#65dccd;--code:#172f28}}
    *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);line-height:1.55}main{width:min(920px,calc(100% - 32px));margin:48px auto}.card{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:clamp(24px,5vw,56px);box-shadow:0 18px 60px #0001}.eyebrow{color:var(--accent);font-weight:800;letter-spacing:.08em;text-transform:uppercase}h1{font-size:clamp(2rem,6vw,4.5rem);line-height:1;margin:.35em 0}h2{margin-top:2em}a{color:var(--accent)}code,pre{background:var(--code);border-radius:10px}code{padding:.15em .4em}pre{overflow:auto;padding:18px;white-space:pre-wrap}ul{padding-left:1.25rem}.meta{display:flex;gap:12px;flex-wrap:wrap;margin:1.5rem 0}.meta span{border:1px solid var(--line);border-radius:999px;padding:.35rem .7rem}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:2rem}.actions a{border:1px solid var(--line);border-radius:999px;padding:.65rem 1rem;text-decoration:none;font-weight:700}.actions a:first-child{background:var(--ink);color:var(--card)}
  </style>
</head>
<body><main><article class="card">
  <p class="eyebrow">${escapeHtml(eyebrow)}</p>
  <h1>${escapeHtml(title)}</h1>
  <p>${escapeHtml(summary)}</p>
  <div class="meta"><span>Contract ${escapeHtml(contractVersion)}</span><span>Deterministic registry</span><span>Git-derived</span></div>
  ${body}
  <nav class="actions" aria-label="Related resources"><a href="${escapeHtml(machineUrl)}">Machine contract</a><a href="${baseUrl}/">All commands</a><a href="${baseUrl}/v/${escapeHtml(contractVersion)}/">Pinned version</a></nav>
</article></main></body></html>`;
}

function markdownFor(command, route, spec, version, sourceHash) {
  return `# ${command}\n\nSeamFlow contract: ${version}\n\nCanonical URL: ${baseUrl}/${route}/\nMachine contract: ${baseUrl}/${route}/contract.json\nSource hash: sha256:${sourceHash}\n\n## Phase\n\n${spec.phase || 'command'}\n\n## Requirements\n\n${(spec.requires || []).map((item) => `- ${item}`).join('\n') || '- None'}\n\n## Required behavior\n\n${(spec.must || []).map((item) => `- ${item}`).join('\n') || '- Follow the canonical SeamFlow Start and command manifest.'}\n\n## Outputs\n\n${(spec.output || []).map((item) => `- ${item}`).join('\n') || '- Follow the command contract.'}\n\n## Forbidden\n\n${(spec.forbidden || []).map((item) => `- ${item}`).join('\n') || '- Do not invent missing authority, artifacts, or PASS evidence.'}\n\n## Final response\n\n${spec.finalResponse || 'Use the canonical SeamFlow response shape.'}\n`;
}

function walkFiles(root) {
  if (!fs.existsSync(root)) return [];
  return fs.readdirSync(root, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(root, entry.name);
    return entry.isDirectory() ? walkFiles(full) : [full];
  });
}

function compareTrees(expectedRoot, actualRoot) {
  const relative = (root, file) => path.relative(root, file).replaceAll('\\', '/');
  const expected = walkFiles(expectedRoot).map((file) => relative(expectedRoot, file)).sort();
  const actual = walkFiles(actualRoot).map((file) => relative(actualRoot, file)).sort();
  const errors = [];
  for (const file of expected.filter((item) => !actual.includes(item))) errors.push(`missing: ${file}`);
  for (const file of actual.filter((item) => !expected.includes(item))) errors.push(`unexpected: ${file}`);
  for (const file of expected.filter((item) => actual.includes(item))) {
    const left = fs.readFileSync(path.join(expectedRoot, file));
    const right = fs.readFileSync(path.join(actualRoot, file));
    if (!left.equals(right)) errors.push(`drifted: ${file}`);
  }
  return errors;
}

function build(targetRoot) {
  const startPath = path.join(repoRoot, 'KIWE-START.md');
  const toolkitRoot = path.join(repoRoot, 'kiwe-ai-toolkit');
  const entryPath = path.join(toolkitRoot, 'entry.json');
  const manifestPath = path.join(toolkitRoot, 'command-manifest.json');
  const start = canonicalFile(startPath).toString('utf8');
  const entry = readJson(entryPath);
  const manifest = readJson(manifestPath);
  const commands = manifest.commands || {};
  const version = String(entry.contractVersion || manifest.contractVersion || 'unknown');
  const sourceHash = sha256([start, canonicalFile(entryPath), canonicalFile(manifestPath)].join('\n'));
  const generatedAt = entry.updated || manifest.updated || 'unknown';

  const routes = Object.entries(commands).map(([command, spec]) => ({ command, spec, route: routeFor(command) }));
  const duplicateRoutes = routes.filter((item, index) => routes.findIndex((candidate) => candidate.route === item.route) !== index);
  if (duplicateRoutes.length) throw new Error(`Duplicate registry routes: ${duplicateRoutes.map((item) => item.route).join(', ')}`);

  const discovery = {
    schema: 'kiwe.command-registry.v1',
    product: 'SeamFlow',
    contractVersion: version,
    updated: generatedAt,
    canonicalBase: baseUrl,
    immutableBase: `${baseUrl}/v/${version}`,
    start: `${baseUrl}/start.md`,
    entry: `${baseUrl}/entry.json`,
    commandManifest: `${baseUrl}/command-manifest.json`,
    sourceRepository: 'https://github.com/Museintel/kiwe',
    sourceHash: `sha256:${sourceHash}`,
    commands: Object.fromEntries(routes.map(({ command, route }) => [command, `${baseUrl}/${route}/contract.json`])),
  };

  const listItems = routes.map(({ command, route, spec }) => `<li><a href="/${escapeHtml(route)}/"><code>${escapeHtml(command)}</code></a> — ${escapeHtml(spec.phase || 'command')}</li>`).join('\n');
  const rootBody = `<h2>Use one memorable URL</h2><pre>${baseUrl}/ideate</pre><p>Give a browser AI the relevant route once. In the same conversation, continue with short composable SeamFlow commands.</p><h2>Canonical commands</h2><ul>${listItems}</ul><h2>Machine discovery</h2><ul><li><a href="/.well-known/kiwe.json"><code>/.well-known/kiwe.json</code></a></li><li><a href="/entry.json"><code>/entry.json</code></a></li><li><a href="/command-manifest.json"><code>/command-manifest.json</code></a></li><li><a href="/start.md"><code>/start.md</code></a></li></ul>`;
  write(targetRoot, 'index.html', renderPage({ title: 'SeamFlow command registry', eyebrow: 'Kiwe launch', summary: 'A stable, public and machine-readable command front door generated from the canonical Kiwe contracts.', body: rootBody, machineUrl: `${baseUrl}/.well-known/kiwe.json`, canonicalUrl: `${baseUrl}/`, contractVersion: version }));
  write(targetRoot, '.well-known/kiwe.json', `${JSON.stringify(discovery, null, 2)}\n`);
  write(targetRoot, 'registry.json', `${JSON.stringify(discovery, null, 2)}\n`);
  write(targetRoot, 'start.md', start);
  copy(targetRoot, entryPath, 'entry.json');
  copy(targetRoot, manifestPath, 'command-manifest.json');

  for (const { command, spec, route } of routes) {
    const machine = { schema: 'kiwe.command-route.v1', contractVersion: version, command, route: `/${route}`, sourceHash: `sha256:${sourceHash}`, ...spec };
    const requirementList = (spec.requires || []).map((item) => `<li>${escapeHtml(item)}</li>`).join('') || '<li>Follow the canonical Start contract.</li>';
    const outputList = (spec.output || []).map((item) => `<li>${escapeHtml(item)}</li>`).join('') || '<li>Follow the canonical command contract.</li>';
    const body = `<h2>Command</h2><pre>${escapeHtml(command)}</pre><h2>Requires</h2><ul>${requirementList}</ul><h2>Outputs</h2><ul>${outputList}</ul><h2>Execution rule</h2><p>${escapeHtml(spec.finalResponse || 'Use the canonical SeamFlow response shape.')}</p>`;
    write(targetRoot, `${route}/contract.json`, `${JSON.stringify(machine, null, 2)}\n`);
    write(targetRoot, `${route}/index.md`, markdownFor(command, route, spec, version, sourceHash));
    write(targetRoot, `${route}/index.html`, renderPage({ title: command, eyebrow: spec.phase || 'command', summary: spec.finalResponse || `Canonical SeamFlow route for ${command}.`, body, machineUrl: `${baseUrl}/${route}/contract.json`, canonicalUrl: `${baseUrl}/${route}/`, contractVersion: version }));
  }

  for (const directory of ['contexts', 'contracts', 'schemas', 'tools']) {
    const sourceDir = path.join(toolkitRoot, directory);
    for (const source of walkFiles(sourceDir)) {
      const relative = path.relative(sourceDir, source);
      copy(targetRoot, source, path.join(directory === 'tools' ? 'validators' : directory, relative));
    }
  }

  const versionRoot = path.join(targetRoot, 'v', version);
  for (const file of walkFiles(targetRoot)) {
    if (file.startsWith(path.join(targetRoot, 'v') + path.sep)) continue;
    const relative = path.relative(targetRoot, file);
    copy(versionRoot, file, relative);
  }

  const urls = [`${baseUrl}/`, ...routes.map(({ route }) => `${baseUrl}/${route}/`)];
  write(targetRoot, 'sitemap.xml', `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.map((url) => `  <url><loc>${escapeHtml(url)}</loc></url>`).join('\n')}\n</urlset>\n`);
  write(targetRoot, 'robots.txt', `User-agent: *\nAllow: /\nSitemap: ${baseUrl}/sitemap.xml\n`);
  write(targetRoot, '.htaccess', `Options -Indexes\nDirectoryIndex index.html\n\n<IfModule mod_mime.c>\n  AddType application/json .json\n  AddType text/markdown .md\n  AddType text/javascript .cjs\n</IfModule>\n\n<IfModule mod_headers.c>\n  Header always set X-Content-Type-Options "nosniff"\n  Header always set Referrer-Policy "no-referrer"\n  Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"\n  Header always set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"\n  <FilesMatch "\\.(json|md|cjs)$">\n    Header always set Access-Control-Allow-Origin "*"\n  </FilesMatch>\n</IfModule>\n`);
  write(targetRoot, '404.html', renderPage({ title: 'Command route not found', eyebrow: 'SeamFlow', summary: 'This route is not part of the current canonical command registry.', body: '<p>Use the command index or machine discovery document to select a valid route.</p>', machineUrl: `${baseUrl}/.well-known/kiwe.json`, canonicalUrl: `${baseUrl}/404.html`, contractVersion: version }));
  write(targetRoot, 'BUILD-METADATA.json', `${JSON.stringify({ schema: 'kiwe.command-registry-build.v1', contractVersion: version, sourceHash: `sha256:${sourceHash}`, generatedFrom: ['KIWE-START.md', 'kiwe-ai-toolkit/entry.json', 'kiwe-ai-toolkit/command-manifest.json'], generatedAt }, null, 2)}\n`);
  write(targetRoot, 'README.md', '# Generated SEAM command registry\n\nDo not edit files in this directory manually. Run `node tools/release/build-command-registry.cjs` from the Kiwe repository root. CI uses `--check` and fails when the hosted registry drifts from canonical Kiwe contracts.\n');
}

if (checkOnly) {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'kiwe-command-registry-'));
  try {
    build(tempRoot);
    const errors = compareTrees(tempRoot, publicRoot);
    if (errors.length) {
      console.error('SEAM command registry drift detected:');
      for (const error of errors) console.error(`- ${error}`);
      process.exitCode = 1;
    } else {
      console.log('SEAM command registry: PASS (generated output matches canonical Kiwe contracts)');
    }
  } finally {
    fs.rmSync(tempRoot, { recursive: true, force: true });
  }
} else {
  const resolved = path.resolve(publicRoot);
  if (resolved !== path.resolve(repoRoot, 'public', 'start.kiwelaunch.com')) throw new Error('Unsafe registry output path');
  fs.rmSync(resolved, { recursive: true, force: true });
  build(resolved);
  console.log(`Built SEAM command registry at ${resolved}`);
}
