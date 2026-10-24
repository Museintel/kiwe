/* Build-only CSS factoring. No new browser runtime or public asset dependency. */
const fs = require('node:fs/promises');
const path = require('node:path');
const postcss = require('postcss');
const csstree = require('css-tree');
const { build } = require('esbuild');
const { gzipSync } = require('node:zlib');
const assets = path.resolve(__dirname, '../../wp-content/mu-plugins/dsa/assets');
let foundationPromise;
const specificityCache = new Map();
const targetCache = new Map();

function add(a, b) { return a.map((n, i) => n + b[i]); }
function maximum(values) { return values.sort((a, b) => b[0] - a[0] || b[1] - a[1] || b[2] - a[2])[0] || [0, 0, 0]; }
function weight(node) {
  if (node.type === 'SelectorList') return maximum(node.children.map(weight));
  if (node.type === 'Selector') return node.children.reduce((sum, child) => add(sum, weight(child)), [0, 0, 0]);
  if (node.type === 'IdSelector') return [1, 0, 0];
  if (['ClassSelector', 'AttributeSelector'].includes(node.type)) return [0, 1, 0];
  if (node.type === 'TypeSelector') return node.name === '*' || node.name.endsWith('|*') ? [0, 0, 0] : [0, 0, 1];
  if (node.type === 'Combinator') return [0, 0, 0];
  if (node.type === 'PseudoElementSelector') { if (node.children) throw Error('Functional pseudo-element'); return [0, 0, 1]; }
  if (node.type === 'PseudoClassSelector') {
    const name = node.name.toLowerCase();
    if (name === 'where') return [0, 0, 0];
    if (['before', 'after', 'first-line', 'first-letter'].includes(name)) return [0, 0, 1];
    if (['is', 'not', 'has'].includes(name)) return maximum((node.children || []).map(weight));
    if (['nth-child', 'nth-last-child'].includes(name)) {
      const nth = node.children?.[0];
      if (nth?.type !== 'Nth') throw Error('Unknown nth selector');
      return add([0, 1, 0], nth.selector ? weight(nth.selector) : [0, 0, 0]);
    }
    if (node.children && !['lang', 'dir', 'nth-of-type', 'nth-last-of-type'].includes(name)) throw Error('Unknown functional selector');
    return [0, 1, 0];
  }
  throw Error('Unsupported specificity node ' + node.type);
}
function specificities(selector) {
  if (!specificityCache.has(selector)) {
    try {
      const ast = csstree.toPlainObject(csstree.parse(selector, { context: 'selectorList' }));
      specificityCache.set(selector, new Set(ast.children.map(node => weight(node).join(','))));
    } catch { specificityCache.set(selector, null); } // Unknown syntax keeps its original position.
  }
  return specificityCache.get(selector);
}
function targets(selector) {
  if (!targetCache.has(selector)) {
    try {
      const ast = csstree.toPlainObject(csstree.parse(selector, { context: 'selectorList' }));
      targetCache.set(selector, ast.children.map(node => {
        const children = node.children.slice(node.children.map(n => n.type).lastIndexOf('Combinator') + 1);
        const result = { specificity: weight(node).join(','), tag: null, id: null, pseudo: '', attributes: {} };
        for (const child of children) {
          if (child.type === 'TypeSelector' && !child.name.includes('*')) result.tag = child.name.split('|').pop().toLowerCase();
          if (child.type === 'IdSelector') result.id = child.name;
          if (child.type === 'PseudoElementSelector' || (child.type === 'PseudoClassSelector' && ['before', 'after', 'first-line', 'first-letter'].includes(child.name))) result.pseudo = child.name;
          if (child.type === 'AttributeSelector' && child.matcher === '=' && child.name?.type === 'Identifier' && child.value && ['String', 'Identifier'].includes(child.value.type)) result.attributes[child.name.name] = String(child.value.value ?? child.value.name).toLowerCase();
        }
        return result;
      }));
    } catch { targetCache.set(selector, null); }
  }
  return targetCache.get(selector);
}
function mayOverlap(a, b) {
  if (!a || !b) return true;
  return a.some(x => b.some(y => x.specificity === y.specificity
    && !(x.tag && y.tag && x.tag !== y.tag) && !(x.id && y.id && x.id !== y.id)
    && x.pseudo === y.pseudo && !Object.keys(x.attributes).some(key => y.attributes[key] !== undefined && x.attributes[key] !== y.attributes[key])));
}
function family(input) {
  const prop = input.replace(/^-(?:webkit|moz|ms|o)-/, '').toLowerCase();
  if (prop.startsWith('--')) return prop;
  if (prop === 'all') return '*';
  if (['word-wrap', 'overflow-wrap'].includes(prop)) return 'overflow-wrap';
  for (const prefix of ['border', 'background', 'font', 'margin', 'padding', 'animation', 'transition', 'flex', 'grid', 'overflow', 'outline', 'list-style', 'mask', 'offset', 'scroll-margin', 'scroll-padding', 'text-decoration', 'contain-intrinsic']) {
    if (prop === prefix || prop.startsWith(prefix + '-')) return prefix;
  }
  if (prop === 'line-height') return 'font';
  // Logical and physical dimensions/aliases can address the same computed property.
  if (/^(?:(?:min|max)-)?(?:width|height|inline-size|block-size)$/.test(prop)) return 'size';
  if (/^(?:page-break|break)-(?:before|after|inside)$/.test(prop)) return 'break';
  if (/^(?:inset|top$|right$|bottom$|left$)/.test(prop)) return 'inset';
  if (/^(?:gap|row-gap|column-gap)$/.test(prop)) return 'gap';
  if (/^(?:place|align|justify)-(?:content|items|self)$/.test(prop)) return 'place';
  if (/^(?:white-space|text-wrap)(?:-|$)/.test(prop)) return 'white-space';
  if (/^(?:columns|column-width|column-count)$/.test(prop)) return 'columns';
  if (/^(?:line-clamp|max-lines|block-ellipsis)$/.test(prop)) return 'line-clamp';
  return prop;
}
function records(root) {
  const out = [];
  root.walkRules(node => {
    const contexts = [];
    for (let parent = node.parent; parent && parent.type !== 'root'; parent = parent.parent) {
      if (parent.type === 'atrule' && /^(?:-webkit-)?keyframes$/.test(parent.name)) return;
      // Layers/scopes/nesting have additional cascade semantics. Refuse extraction
      // rather than silently treating those declarations as unrelated.
      if (parent.type !== 'atrule' || !['media', 'supports', 'container'].includes(parent.name)) throw Error('Unsupported workspace CSS context: ' + parent.type + ':' + (parent.name || 'nested rule'));
      contexts.unshift({ name: parent.name, params: parent.params });
    }
    if (node.nodes.some(item => !['decl', 'comment'].includes(item.type))) throw Error('Nested workspace CSS must be lowered before extraction');
    const declarations = node.nodes.filter(item => item.type === 'decl');
    out.push({ node, contexts, key: JSON.stringify(contexts) + '|' + node.toString(), specificity: specificities(node.selector), targets: targets(node.selector),
      properties: declarations.map(d => ({ node: d, prop: d.prop, family: family(d.prop), important: Boolean(d.important), value: d.value })) });
  });
  return out;
}
function conflict(a, b) {
  if (a.specificity && b.specificity && ![...a.specificity].some(s => b.specificity.has(s))) return false;
  if (!mayOverlap(a.targets, b.targets)) return false;
  return a.properties.some(p => b.properties.some(q => p.important === q.important && (p.family === q.family || p.family === '*' || q.family === '*') && (p.prop !== q.prop || p.value !== q.value)));
}
async function foundation() {
  if (!foundationPromise) foundationPromise = build({ absWorkingDir: __dirname, entryPoints: ['workspace-foundation.css'], bundle: true, minify: true, write: false, outfile: 'workspace-foundation.css' }).then(result => {
    const commonCss = result.outputFiles[0].text;
    const tokenCss = require('./generate-workspace-tokens.cjs').verifySource(require('node:fs').readFileSync(path.join(assets, 'css/workspace-tokens.css'), 'utf8'));
    const css = tokenCss + commonCss;
    const rows = records(postcss.parse(commonCss));
    if (!rows.length || rows.length !== new Set(rows.map(r => r.key)).size) throw Error('Ambiguous workspace foundation');
    return { css, rows, byKey: new Map(rows.map((r, i) => [r.key, i])) };
  });
  return foundationPromise;
}
function factor(css, shared) {
  const root = postcss.parse(css), rows = records(root);
  const present = new Set(rows.map(r => r.key));
  if (shared.rows.some(r => !present.has(r.key))) throw Error('App does not include the complete shared foundation');
  const ranks = new Map(); let commonRank = 0;
  shared.rows.forEach(r => r.properties.forEach((p, i) => ranks.set(r.key + '|' + i, commonRank++)));
  const prior = new Map(), allPrior = [], removed = new Set();
  let originalRank = 0, retainedOrderExceptions = 0;
  // Preserve order per declaration rather than retaining a complete repeated
  // component for one conflicting property. Every original predecessor has its
  // final position when inspected. Unknown selector syntax is conservative.
  for (const row of rows) for (const [index, property] of row.properties.entries()) {
    const rankKey = row.key + '|' + index;
    const candidate = ranks.has(rankKey);
    let position = candidate ? ranks.get(rankKey) : commonRank + originalRank;
    if (candidate) {
      const competitors = property.family === '*' ? allPrior : [...(prior.get(property.family) || []), ...(prior.get('*') || [])];
      if (competitors.some(previous => previous.position > position && conflict({ ...previous.row, properties: [previous.property] }, { ...row, properties: [property] }))) {
        position = commonRank + originalRank; retainedOrderExceptions++;
      } else removed.add(property.node);
    }
    const item = { position, row, property };
    if (!prior.has(property.family)) prior.set(property.family, []);
    prior.get(property.family).push(item); allPrior.push(item); originalRank++;
  }
  for (const node of removed) node.remove();
  let removedRules = 0;
  root.walkRules(node => { if (!node.nodes.length) { node.remove(); removedRules++; } });
  let changed; do { changed = false; root.walkAtRules(node => { if (node.nodes && !node.nodes.length) { node.remove(); changed = true; } }); } while (changed);
  return { css: root.toString(), removedRules, removedDeclarations: removed.size, retainedOrderExceptions };
}
async function writeWorkspaceArtifacts(result, { check = process.argv.includes('--check') } = {}) {
  const shared = await foundation();
  const outputs = [];
  const sharedTarget = path.join(assets, 'css/workspace-foundation.css');
  async function output(target, content) {
    const bytes = Buffer.from(content);
    if (check) { if (!(await fs.readFile(target)).equals(bytes)) throw Error('Native workspace artifact needs rebuilding: ' + target); }
    else await fs.writeFile(target, bytes);
    outputs.push({ file: path.basename(target), bytes: bytes.length, gzipBytes: gzipSync(bytes).length });
  }
  await output(sharedTarget, shared.css);
  for (const file of result.outputFiles) {
    const name = path.basename(file.path), isCss = name.endsWith('.css');
    const factored = isCss ? factor(file.text, shared) : null;
    await output(path.join(assets, isCss ? 'css/' + name : 'js/' + name), factored ? factored.css : file.contents);
    if (factored) Object.assign(outputs[outputs.length - 1], { removedRules: factored.removedRules, removedDeclarations: factored.removedDeclarations, retainedOrderExceptions: factored.retainedOrderExceptions });
  }
  return outputs;
}
function readWorkspaceCss(name) {
  const sync = require('node:fs');
  return sync.readFileSync(path.join(assets, 'css/workspace-foundation.css'), 'utf8') + '\n' + sync.readFileSync(path.join(assets, 'css', name), 'utf8');
}
module.exports = { foundation, factor, conflict, specificities, family, records, writeWorkspaceArtifacts, readWorkspaceCss };
