#!/usr/bin/env node
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const repoRoot = path.resolve(__dirname, '..', '..');
const publicRoot = path.join(repoRoot, 'public', 'start.kiwelaunch.com');
const toolkitRoot = path.join(repoRoot, 'kiwe-ai-toolkit');
const baseUrl = 'https://start.kiwelaunch.com';
const sourceRepository = 'https://github.com/Museintel/kiwe';
const sourceBranch = 'codex/phonekey-whatsapp-rc1';
const sourceStart = `${sourceRepository}/blob/${sourceBranch}/KIWE-START.md`;
const rawSourceStart = `https://raw.githubusercontent.com/Museintel/kiwe/${sourceBranch}/KIWE-START.md`;
const indexNowKey = 'c8db19ce3f2e469aa5622c25743c28f3';
const checkOnly = process.argv.includes('--check');
const textExtensions = new Set(['.cjs', '.css', '.html', '.js', '.json', '.md', '.txt', '.xml']);

const canonical = (value) => String(value).replace(/\r\n?/g, '\n');
const bytes = (file) => {
  const value = fs.readFileSync(file);
  return textExtensions.has(path.extname(file).toLowerCase()) ? Buffer.from(canonical(value.toString('utf8'))) : value;
};
const json = (file) => JSON.parse(bytes(file).toString('utf8'));
const hash = (value) => crypto.createHash('sha256').update(value).digest('hex');
const write = (root, relative, value) => {
  const target = path.join(root, relative);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, value);
};
const copy = (root, source, relative) => write(root, relative, bytes(source));
const walk = (root) => fs.existsSync(root) ? fs.readdirSync(root, { withFileTypes: true }).flatMap((entry) => entry.isDirectory() ? walk(path.join(root, entry.name)) : [path.join(root, entry.name)]) : [];
const routeFor = (command) => command.trim().split(/\s+/).map((token) => token.replace(/^\/+/, '').toLowerCase()).filter(Boolean).join('/');
const escape = (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');

function page(title, summary, body, version, releaseId, machineUrl, canonicalUrl = baseUrl) {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="${escape(summary)}"><meta name="robots" content="index,follow,max-snippet:-1"><link rel="canonical" href="${escape(canonicalUrl)}"><link rel="alternate" type="text/markdown" href="${baseUrl}/start.md" title="SEAM start contract"><link rel="alternate" type="text/plain" href="${baseUrl}/llms.txt" title="SEAM AI index"><meta property="og:type" content="website"><meta property="og:site_name" content="SEAM by Kiwe"><meta property="og:title" content="${escape(title)} · SEAM"><meta property="og:description" content="${escape(summary)}"><meta property="og:url" content="${escape(canonicalUrl)}"><title>${escape(title)} · SEAM</title><style>:root{color-scheme:light dark;font-family:Inter,system-ui,sans-serif;--bg:#f4f2ea;--ink:#102d29;--card:#fffdf7;--line:#a7b9b4;--accent:#0b8277}@media(prefers-color-scheme:dark){:root{--bg:#091713;--ink:#eaf6f1;--card:#10231e;--line:#36564e;--accent:#65dccd}}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);line-height:1.55}main{width:min(920px,calc(100% - 32px));margin:48px auto}.card{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:clamp(24px,5vw,56px)}h1{font-size:clamp(2rem,6vw,4.5rem);line-height:1}a{color:var(--accent)}code,pre{overflow:auto}li{margin:.55rem 0}.meta{display:flex;gap:8px;flex-wrap:wrap}.meta span{border:1px solid var(--line);border-radius:99px;padding:.3rem .65rem}</style></head><body><main><article class="card"><p>Kiwe launch</p><h1>${escape(title)}</h1><p>${escape(summary)}</p><div class="meta"><span>Contract ${escape(version)}</span><span>${escape(releaseId)}</span></div>${body}<p><a href="${escape(machineUrl)}">Machine contract</a> · <a href="${baseUrl}/start.md">Markdown start</a> · <a href="${escape(rawSourceStart)}">GitHub mirror</a> · <a href="${baseUrl}/">All commands</a></p></article></main></body></html>`;
}

function compare(expectedRoot, actualRoot) {
  const relative = (root, file) => path.relative(root, file).replaceAll('\\', '/');
  const expected = walk(expectedRoot).map((file) => relative(expectedRoot, file)).sort();
  const expectedVersionRoots = new Set(expected.filter((file) => file.startsWith('v/')).map((file) => file.split('/').slice(0, 2).join('/')));
  const actual = walk(actualRoot).map((file) => relative(actualRoot, file)).filter((file) => !file.startsWith('v/') || expectedVersionRoots.has(file.split('/').slice(0, 2).join('/'))).sort();
  const errors = [];
  for (const file of expected.filter((item) => !actual.includes(item))) errors.push(`missing: ${file}`);
  for (const file of actual.filter((item) => !expected.includes(item))) errors.push(`unexpected: ${file}`);
  for (const file of expected.filter((item) => actual.includes(item))) if (!fs.readFileSync(path.join(expectedRoot, file)).equals(fs.readFileSync(path.join(actualRoot, file)))) errors.push(`drifted: ${file}`);
  return errors;
}

function build(targetRoot) {
  const startPath = path.join(repoRoot, 'KIWE-START.md');
  const entryPath = path.join(toolkitRoot, 'entry.json');
  const manifestPath = path.join(toolkitRoot, 'command-manifest.json');
  const entry = json(entryPath);
  const manifest = json(manifestPath);
  const commands = Array.isArray(manifest.commands) ? manifest.commands : [];
  const published = [startPath, entryPath, manifestPath, ...['contexts', 'contracts', 'schemas'].flatMap((dir) => walk(path.join(toolkitRoot, dir)))].sort();
  const sourceHash = hash(published.map((file) => `${path.relative(repoRoot, file).replaceAll('\\', '/')}\n${bytes(file).toString('utf8')}`).join('\n---\n'));
  const version = String(entry.contractVersion);
  const releaseId = `${version}-${sourceHash.slice(0, 12)}`;
  const routes = commands.map((spec) => ({ command: spec.command, spec, route: routeFor(spec.command) }));
  if (new Set(routes.map((item) => item.route)).size !== routes.length) throw new Error('Duplicate SEAM command routes.');

  const discovery = {
    schema: 'kiwe.command-registry.v2', product: 'SEAM', contractVersion: version, releaseId, updated: entry.updated,
    canonicalBase: baseUrl, immutableBase: `${baseUrl}/v/${releaseId}`, start: `${baseUrl}/start.md`, entry: `${baseUrl}/entry.json`,
    commandManifest: `${baseUrl}/command-manifest.json`, llms: `${baseUrl}/llms.txt`, llmsFull: `${baseUrl}/llms-full.txt`,
    sourceRepository, sourceStart, rawSourceStart, sourceHash: `sha256:${sourceHash}`,
    fallback: { whenCanonicalUnavailable: 'Read rawSourceStart, verify the advertised contract version, then follow the same strict command boundary. Never substitute a similarly named domain.' },
    commands: Object.fromEntries(routes.map(({ command, route }) => [command, `${baseUrl}/${route}/contract.json`])),
    immutableCommands: Object.fromEntries(routes.map(({ command, route }) => [command, `${baseUrl}/v/${releaseId}/${route}/contract.json`])),
    cachePolicy: { canonical: 'no-store', immutable: 'public, max-age=31536000, immutable' }
  };

  const list = routes.map(({ command, route, spec }) => `<li><a href="/${escape(route)}/"><code>${escape(command)}</code></a> — ${escape(spec.boundary)}</li>`).join('');
  write(targetRoot, 'index.html', page('SEAM command registry', 'The strict public command boundary generated from the canonical Kiwe repository.', `<h2>Six commands</h2><ul>${list}</ul><h2>AI-readable bootstrap</h2><p>Read <a href="/start.md">start.md</a> first. If a browser AI cannot yet open this newly indexed host, use the byte-equivalent <a href="${escape(rawSourceStart)}">GitHub mirror</a>. Never substitute a similarly named product or domain.</p>`, version, releaseId, `${baseUrl}/.well-known/kiwe.json`, `${baseUrl}/`));
  write(targetRoot, '.well-known/kiwe.json', `${JSON.stringify(discovery, null, 2)}\n`);
  write(targetRoot, 'registry.json', `${JSON.stringify(discovery, null, 2)}\n`);
  copy(targetRoot, startPath, 'start.md');
  copy(targetRoot, entryPath, 'entry.json');
  copy(targetRoot, manifestPath, 'command-manifest.json');
  const llms = `# SEAM by Kiwe\n\n> Canonical command boundary for attachment-aware website ideation, auditing, fixing and WordPress binding preparation.\n\n- [Start contract](${baseUrl}/start.md)\n- [Command manifest](${baseUrl}/command-manifest.json)\n- [Machine registry](${baseUrl}/.well-known/kiwe.json)\n- [GitHub source mirror](${rawSourceStart})\n\nNever substitute kiwilaunch.com or another similarly named domain.\n`;
  const llmsFull = `${llms}\n---\n\n${bytes(startPath).toString('utf8')}\n\n--- COMMAND MANIFEST ---\n\n${bytes(manifestPath).toString('utf8')}\n\n${routes.map(({ command, spec }) => `--- ${command} ---\n\n${bytes(path.join(toolkitRoot, spec.context)).toString('utf8')}`).join('\n\n')}\n`;
  write(targetRoot, 'llms.txt', llms);
  write(targetRoot, 'llms-full.txt', llmsFull);

  for (const { command, route, spec } of routes) {
    const sources = ['KIWE-START.md', `kiwe-ai-toolkit/${spec.context}`];
    const resources = sources.map((source) => {
      const sourcePath = source === 'KIWE-START.md' ? startPath : path.join(repoRoot, source);
      const publicPath = source === 'KIWE-START.md' ? 'start.md' : source.slice('kiwe-ai-toolkit/'.length);
      return { source, url: `${baseUrl}/${publicPath}`, pinnedUrl: `${baseUrl}/v/${releaseId}/${publicPath}`, sha256: hash(bytes(sourcePath)) };
    });
    const contract = { schema: 'kiwe.command-route.v2', contractVersion: version, releaseId, route: `/${route}`, resources, sourceHash: `sha256:${sourceHash}`, ...spec };
    write(targetRoot, `${route}/contract.json`, `${JSON.stringify(contract, null, 2)}\n`);
    write(targetRoot, `${route}/index.md`, `# ${command}\n\nSEAM contract: ${version}\n\n${spec.boundary}\n\n## Requires\n${spec.requires.map((item) => `- ${item}`).join('\n')}\n\n## Outputs\n${spec.outputs.map((item) => `- ${item}`).join('\n')}\n`);
    write(targetRoot, `${route}/index.html`, page(command, spec.boundary, `<h2>Requires</h2><ul>${spec.requires.map((item) => `<li>${escape(item)}</li>`).join('')}</ul><h2>Outputs</h2><ul>${spec.outputs.map((item) => `<li>${escape(item)}</li>`).join('')}</ul>`, version, releaseId, `${baseUrl}/${route}/contract.json`, `${baseUrl}/${route}/`));
  }

  for (const directory of ['contexts', 'contracts', 'schemas']) for (const source of walk(path.join(toolkitRoot, directory))) copy(targetRoot, source, path.join(directory, path.relative(path.join(toolkitRoot, directory), source)));
  const versionRoot = path.join(targetRoot, 'v', releaseId);
  for (const file of walk(targetRoot)) if (!file.startsWith(path.join(targetRoot, 'v') + path.sep)) copy(versionRoot, file, path.relative(targetRoot, file));
  write(versionRoot, '.well-known/kiwe.json', `${JSON.stringify({ ...discovery, canonicalBase: `${baseUrl}/v/${releaseId}`, commands: discovery.immutableCommands }, null, 2)}\n`);
  write(targetRoot, 'sitemap.xml', `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${[`${baseUrl}/`, ...routes.map(({ route }) => `${baseUrl}/${route}/`)].map((url) => `<url><loc>${escape(url)}</loc><lastmod>${escape(entry.updated)}</lastmod></url>`).join('')}</urlset>\n`);
  write(targetRoot, 'robots.txt', `User-agent: *\nAllow: /\nSitemap: ${baseUrl}/sitemap.xml\n`);
  write(targetRoot, `${indexNowKey}.txt`, `${indexNowKey}\n`);
  write(targetRoot, '.htaccess', `Options -Indexes\nDirectoryIndex index.html\n<IfModule mod_headers.c>\nHeader always set X-Content-Type-Options "nosniff"\nHeader always set Referrer-Policy "no-referrer"\nHeader always set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"\n<FilesMatch "\\.(json|md|cjs)$">\nHeader always set Access-Control-Allow-Origin "*"\n</FilesMatch>\n</IfModule>\n`);
  write(targetRoot, '404.html', page('Command not found', 'Use one of the six commands in the registry.', '', version, releaseId, `${baseUrl}/.well-known/kiwe.json`, `${baseUrl}/`));
  write(targetRoot, 'BUILD-METADATA.json', `${JSON.stringify({ schema: 'kiwe.command-registry-build.v2', contractVersion: version, releaseId, sourceHash: `sha256:${sourceHash}`, generatedFrom: published.map((file) => path.relative(repoRoot, file).replaceAll('\\', '/')), generatedAt: entry.updated }, null, 2)}\n`);
  write(targetRoot, 'README.md', '# Generated SEAM command registry\n\nDo not edit manually. Run `node tools/release/build-command-registry.cjs`.\n');
}

if (checkOnly) {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'seam-registry-'));
  try {
    build(temp);
    const errors = compare(temp, publicRoot);
    if (errors.length) { console.error('SEAM command registry drift detected:\n' + errors.map((item) => `- ${item}`).join('\n')); process.exitCode = 1; }
    else console.log('SEAM command registry: PASS');
  } finally { fs.rmSync(temp, { recursive: true, force: true }); }
} else {
  if (path.resolve(publicRoot) !== path.resolve(repoRoot, 'public', 'start.kiwelaunch.com')) throw new Error('Unsafe registry path.');
  fs.mkdirSync(publicRoot, { recursive: true });
  for (const entry of fs.readdirSync(publicRoot, { withFileTypes: true })) {
    if (entry.name !== 'v') fs.rmSync(path.join(publicRoot, entry.name), { recursive: true, force: true });
  }
  build(publicRoot);
  console.log(`Built SEAM command registry at ${publicRoot}`);
}
