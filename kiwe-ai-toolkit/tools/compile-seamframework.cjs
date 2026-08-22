#!/usr/bin/env node
const { spawnSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

function usage() {
  console.log('Usage: node tools/compile-seamframework.cjs <input.html> [output-dir]');
  console.log('');
  console.log('Deterministically compiles a raw HTML/CSS page draft into website/bricks-paste.html,');
  console.log('then runs validate-seamframework.cjs. This is the non-creative first-pass Seam compiler.');
}

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  usage();
  process.exit(0);
}

const inputPath = process.argv[2] ? path.resolve(process.argv[2]) : '';
const outputRoot = path.resolve(process.argv[3] || process.cwd());
const websiteDir = path.join(outputRoot, 'website');
const outputPath = path.join(websiteDir, 'bricks-paste.html');

if (!inputPath || !fs.existsSync(inputPath) || !fs.statSync(inputPath).isFile()) {
  console.error(JSON.stringify({
    ok: false,
    schema: 'kiwe.seamframework-compile.v1',
    error: 'KIWE_MISSING_ARTIFACT',
    message: 'Input HTML file is required.'
  }, null, 2));
  process.exit(1);
}

function hash(value, length = 8) {
  return crypto.createHash('sha1').update(String(value)).digest('hex').slice(0, length);
}

function slug(value, fallback = 'appsite') {
  const cleaned = String(value || '')
    .toLowerCase()
    .replace(/<[^>]*>/g, ' ')
    .replace(/&[a-z0-9#]+;/gi, ' ')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 44);
  return cleaned || fallback;
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function read(file) {
  return fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, '');
}

function firstMatch(text, regex, fallback = '') {
  const match = String(text || '').match(regex);
  return match ? String(match[1] || '').trim() : fallback;
}

function addClass(tag, className) {
  if (new RegExp(`\\b${className}\\b`).test(tag)) return tag;
  if (/\bclass\s*=\s*["'][^"']*["']/i.test(tag)) {
    return tag.replace(/\bclass\s*=\s*(["'])([^"']*)\1/i, (_m, quote, classes) => `class=${quote}${classes} ${className}${quote}`);
  }
  return tag.replace(/>$/, ` class="${className}">`);
}

function addAttr(tag, name, value) {
  if (new RegExp(`\\b${name}\\s*=`, 'i').test(tag)) return tag;
  return tag.replace(/>$/, ` ${name}="${escapeHtml(value)}">`);
}

function stripScripts(html) {
  return String(html || '')
    .replace(/<script\b[\s\S]*?<\/script>/gi, '')
    .replace(/\s+on[a-z]+\s*=\s*(["']).*?\1/gi, '')
    .replace(/\s+on[a-z]+\s*=\s*[^\s>]+/gi, '');
}

function extractStyles(html) {
  const styles = [];
  const without = String(html || '').replace(/<style\b[^>]*>([\s\S]*?)<\/style>/gi, (_match, css) => {
    styles.push(String(css || ''));
    return '';
  });
  return { html: without, css: styles.join('\n\n') };
}

function extractBody(html) {
  const body = firstMatch(html, /<body\b[^>]*>([\s\S]*?)<\/body>/i, '');
  return body || html
    .replace(/<!doctype[^>]*>/gi, '')
    .replace(/<html\b[^>]*>|<\/html>/gi, '')
    .replace(/<head\b[\s\S]*?<\/head>/gi, '')
    .trim();
}

function removeBackdrop(css) {
  return String(css || '')
    .replace(/--[a-z0-9_-]*(?:backdrop|filter|blur)[a-z0-9_-]*\s*:\s*[^;{}]+;/gi, '')
    .replace(/-webkit-backdrop-filter\s*:\s*[^;{}]+;/gi, '')
    .replace(/backdrop-filter\s*:\s*[^;{}]+;/gi, '');
}

function splitSelectorList(selectorText) {
  const out = [];
  let current = '';
  let depth = 0;
  for (const ch of String(selectorText || '')) {
    if (ch === '(' || ch === '[') depth++;
    if (ch === ')' || ch === ']') depth = Math.max(0, depth - 1);
    if (ch === ',' && depth === 0) {
      out.push(current.trim());
      current = '';
    } else {
      current += ch;
    }
  }
  if (current.trim()) out.push(current.trim());
  return out;
}

function prefixSelector(selector, rootClass) {
  const trimmed = String(selector || '').trim();
  if (!trimmed || trimmed.startsWith('@')) return trimmed;
  if (trimmed.includes(rootClass)) return trimmed;
  if (/^(html|body|:root)\b/i.test(trimmed)) {
    return trimmed.replace(/^(html|body|:root)\b/i, `.${rootClass}`);
  }
  return `.${rootClass} ${trimmed}`;
}

function prefixCss(css, rootClass) {
  const body = String(css || '');
  function matchingBrace(text, openIndex) {
    let depth = 0;
    for (let i = openIndex; i < text.length; i++) {
      if (text[i] === '{') depth++;
      if (text[i] === '}') {
        depth--;
        if (depth === 0) return i;
      }
    }
    return -1;
  }
  function processChunk(text) {
    let out = '';
    let i = 0;
    while (i < text.length) {
      const open = text.indexOf('{', i);
      if (open < 0) {
        out += text.slice(i);
        break;
      }
      const selector = text.slice(i, open);
      const close = matchingBrace(text, open);
      if (close < 0) {
        out += text.slice(i);
        break;
      }
      const inner = text.slice(open + 1, close);
      const trimmed = selector.trim();
      const leading = selector.slice(0, selector.length - selector.trimStart().length);
      if (trimmed.startsWith('@media') || trimmed.startsWith('@supports') || trimmed.startsWith('@container')) {
        out += `${leading}${trimmed}{${processChunk(inner)}}`;
      } else if (trimmed.startsWith('@keyframes') || trimmed.startsWith('@font-face') || trimmed.startsWith('@page')) {
        out += `${selector}{${inner}}`;
      } else if (trimmed.startsWith('@')) {
        out += `${leading}${trimmed}{${inner}}`;
      } else {
        out += `${leading}${splitSelectorList(selector).map((part) => prefixSelector(part, rootClass)).join(', ')}{${inner}}`;
      }
      i = close + 1;
    }
    return out;
  }
  return processChunk(body);
}

function collectToken(value, prop, state) {
  const raw = String(value || '').trim();
  if (!raw || /^var\(/i.test(raw) || /^url\(/i.test(raw)) return raw;
  if (/data:image|base64/i.test(raw)) return raw;
  const tokenName = `--${state.prefix}-${slug(prop, 'value')}-${hash(`${prop}:${raw}`)}`;
  if (!state.tokens.has(tokenName)) {
    state.tokens.set(tokenName, raw);
  }
  return `var(${tokenName}, ${raw})`;
}

function tokeniseDeclarationValue(prop, value, state) {
  const raw = String(value || '').trim();
  if (!raw) return raw;
  if (/url\(\s*data:/i.test(raw)) return raw;
  if (/gradient\(/i.test(raw)) return collectToken(raw, prop, state);
  const literalColor = /(?:#[0-9a-f]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)|oklch\([^)]*\)|color-mix\([^)]*\))/i;
  const literalLength = /(?:^|[\s,(])-?\d*\.?\d+(?:px|rem|em|vw|vh|vmin|vmax|ch|ex|cqw|cqh|%)(?=$|[\s,);])/i;
  const literalShadow = /\b\d+px\b[\s\S]*(?:#[0-9a-f]{3,8}\b|rgba?\()/i;
  if (literalColor.test(raw) || literalShadow.test(raw)) return collectToken(raw, prop, state);
  if (literalLength.test(raw) && !/^calc\(|^clamp\(|^min\(|^max\(/i.test(raw)) return collectToken(raw, prop, state);
  return raw;
}

function tokeniseCss(css, rootClass, prefix) {
  const state = { prefix, tokens: new Map() };
  let body = String(css || '').replace(/\/\*[\s\S]*?\*\//g, '');
  body = removeBackdrop(body);
  body = body.replace(/([a-z-][a-z0-9-]*)\s*:\s*([^;{}]+);/gi, (match, prop, value) => {
    if (String(prop).startsWith('--')) return match;
    const next = tokeniseDeclarationValue(prop, value, state);
    return `${prop}:${next};`;
  });
  body = prefixCss(body, rootClass);
  const tokenCss = Array.from(state.tokens.entries())
    .map(([name, value]) => `  ${name}: ${value};`)
    .join('\n');
  return {
    css: tokenCss ? `.${rootClass}{\n${tokenCss}\n}\n\n${body}` : body,
    tokenCount: state.tokens.size
  };
}

function enhanceMarkup(html, rootClass) {
  let body = stripScripts(html);
  body = body.replace(/<main\b([^>]*)>/gi, (tag) => addClass(tag, 'seam-main'));
  body = body.replace(/<section\b([^>]*)>/gi, (tag) => addAttr(addClass(tag, 'seam-section'), 'data-role', 'section'));
  body = body.replace(/<header\b([^>]*)>/gi, (tag) => addAttr(addClass(tag, 'seam-section'), 'data-role', 'header'));
  body = body.replace(/<footer\b([^>]*)>/gi, (tag) => addAttr(addClass(tag, 'seam-section'), 'data-role', 'footer'));
  body = body.replace(/<nav\b([^>]*)>/gi, (tag) => addAttr(addClass(tag, 'seam-nav'), 'data-role', 'nav'));
  body = body.replace(/<([a-z][a-z0-9-]*)\b([^>]*\bclass\s*=\s*["'][^"']*(?:card|tile|promo|story|panel)[^"']*["'][^>]*)>/gi, (match) => addAttr(addClass(match, 'seam-card'), 'data-role', 'card'));
  body = body.replace(/<([a-z][a-z0-9-]*)\b([^>]*\bclass\s*=\s*["'][^"']*(?:track|carousel|scroller)[^"']*["'][^>]*)>/gi, (match) => {
    if (/\bseam-nav\b/.test(match)) return match;
    let next = addAttr(addClass(match, 'seam-horizontal-rail'), 'data-flow', 'horizontal-rail');
    if (/\bproduct[a-z0-9_-]*(?:track|rail|grid|list)|(?:track|rail|grid|list)[a-z0-9_-]*product\b/i.test(match)) {
      next = addAttr(addAttr(next, 'data-project-role', 'dynamic-product-rail'), 'data-kiwe-query-template', 'products');
    } else if (/\bcategor(?:y|ies)[a-z0-9_-]*(?:track|rail|grid|list)|(?:track|rail|grid|list)[a-z0-9_-]*categor(?:y|ies)\b/i.test(match)) {
      next = addAttr(addAttr(next, 'data-project-role', 'dynamic-category-rail'), 'data-kiwe-query-template', 'categories');
    }
    return next;
  });
  body = body.replace(/<((?:a|button))\b([^>]*)>([\s\S]{0,120}?)(cart|bag)([\s\S]{0,120}?)<\/\1>/gi, (match) => /\bdata-dsa-open-module\s*=/i.test(match) ? match : match.replace(/^<([a-z]+)/i, '<$1 data-dsa-open-module="cart"'));
  body = body.replace(/<((?:a|button))\b([^>]*)>([\s\S]{0,120}?)(account|profile|login)([\s\S]{0,120}?)<\/\1>/gi, (match) => /\bdata-dsa-open-module\s*=/i.test(match) ? match : match.replace(/^<([a-z]+)/i, '<$1 data-dsa-open-module="profile"'));
  body = body.replace(/<((?:a|button))\b([^>]*)>([\s\S]{0,120}?)(search)([\s\S]{0,120}?)<\/\1>/gi, (match) => /\bdata-dsa-open-module\s*=/i.test(match) ? match : match.replace(/^<([a-z]+)/i, '<$1 data-dsa-open-module="search"'));
  body = body.replace(/<((?:a|button))\b([^>]*)>([\s\S]{0,120}?)(menu)([\s\S]{0,120}?)<\/\1>/gi, (match) => /\bdata-dsa-open-module\s*=/i.test(match) ? match : match.replace(/^<([a-z]+)/i, '<$1 data-dsa-open-module="menu"'));
  body = body.replace(/<a\b([^>]*)>/gi, (tag, attrs) => {
    if (/\bdata-kiwe-(?:contact|social)\s*=/i.test(tag)) return tag;
    const href = String(attrs || '').match(/\bhref\s*=\s*["']([^"']+)["']/i)?.[1] || '';
    let contact = '';
    if (/^tel:/i.test(href)) contact = 'phone';
    else if (/^mailto:/i.test(href)) contact = 'email';
    else if (/(?:wa\.me|api\.whatsapp\.com|whatsapp:\/\/)/i.test(href)) contact = 'whatsapp';
    else if (/(?:google\.[^/]+\/maps|maps\.apple\.com|openstreetmap\.org)/i.test(href)) contact = 'directions';
    if (contact) return addAttr(tag, 'data-kiwe-contact', contact);
    const networks = [
      ['instagram', /(?:^|\.)instagram\.com/i], ['facebook', /(?:^|\.)facebook\.com/i],
      ['x', /(?:^|\.)(?:x|twitter)\.com/i], ['youtube', /(?:^|\.)(?:youtube\.com|youtu\.be)/i],
      ['pinterest', /(?:^|\.)pinterest\.[a-z.]+/i], ['linkedin', /(?:^|\.)linkedin\.com/i]
    ];
    try {
      const host = new URL(href, 'https://kiwe.invalid').hostname;
      const network = networks.find((entry) => entry[1].test(host));
      return network ? addAttr(tag, 'data-kiwe-social', network[0]) : tag;
    } catch (_error) {
      return tag;
    }
  });
  body = body.replace(/<button\b([^>]*)>([\s\S]*?)<\/button>/gi, (match, attrs, inner) => {
    if (/\bdata-kiwe-save\s*=/i.test(match)) return match;
    if (/(?:wishlist|bookmark|\bsave\b|♡|♥)/i.test(`${attrs} ${inner}`)) {
      return match.replace(/<button\b/i, '<button data-kiwe-save="auto"');
    }
    return match;
  });
  if (!new RegExp(`class=["'][^"']*${rootClass}`).test(body)) {
    body = `<div class="${rootClass}" data-seamflow-compiled="true">\n${body.trim()}\n</div>`;
  }
  return body;
}

const source = read(inputPath);
const title = firstMatch(source, /<title\b[^>]*>([\s\S]*?)<\/title>/i, path.basename(inputPath, path.extname(inputPath)));
const rootClass = `kiwe-seam-${slug(title || path.basename(inputPath), 'page')}`;
const projectPrefix = slug(rootClass.replace(/^kiwe-/, ''), 'seam-project');
const extracted = extractStyles(source);
const body = extractBody(extracted.html);
const enhanced = enhanceMarkup(body, rootClass);
const tokenised = tokeniseCss(extracted.css, rootClass, projectPrefix);
const compiledAt = new Date().toISOString();
const output = `<!doctype html>
<html lang="en" data-kiwe-theme="light" data-brx-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title || 'Kiwe Seam Page')}</title>
  <meta name="kiwe-seamflow-compiler" content="compile-seamframework.cjs">
  <meta name="kiwe-seamflow-source-sha256" content="${crypto.createHash('sha256').update(source).digest('hex')}">
  <style>
/* Kiwe SeamFlow deterministic token layer.
   Distinctness / visual thesis: preserve the supplied creative draft while routing anonymous design values through named project tokens.
   Selector-fit checklist: project selectors are scoped under .${rootClass}; Seam classes/attributes remain semantic and do not own visual CSS.
   Validation: run node kiwe-ai-toolkit/tools/validate-seamframework.cjs website/bricks-paste.html.
*/
${tokenised.css}
  </style>
</head>
<body>
${enhanced}
<!-- Kiwe SeamFlow compiled ${compiledAt}; page-only artifact, no AppShell/DSA runtime shell. -->
</body>
</html>
`;

fs.mkdirSync(websiteDir, { recursive: true });
fs.writeFileSync(outputPath, output, 'utf8');

const validator = path.join(__dirname, 'validate-seamframework.cjs');
const validation = spawnSync(process.execPath, [validator, outputPath], {
  cwd: path.resolve(__dirname, '..'),
  encoding: 'utf8'
});

let validationJson = null;
try {
  validationJson = JSON.parse(validation.stdout || '{}');
} catch (_) {}

const result = {
  ok: validation.status === 0 && (!validationJson || validationJson.ok !== false),
  schema: 'kiwe.seamframework-compile.v1',
  compiler: 'compile-seamframework.cjs',
  input: inputPath,
  output: outputPath,
  rootClass,
  tokenCount: tokenised.tokenCount,
  removedRuntimeScripts: /<script\b/i.test(source),
  validator: {
    command: `node ${path.relative(process.cwd(), validator).replace(/\\/g, '/')} ${path.relative(process.cwd(), outputPath).replace(/\\/g, '/')}`,
    exitCode: validation.status,
    result: validationJson || null,
    stderr: validation.stderr || ''
  }
};

console.log(JSON.stringify(result, null, 2));
process.exit(result.ok ? 0 : 1);
