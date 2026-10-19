import crypto from 'node:crypto';
import fs from 'node:fs';

const object = value => value && typeof value === 'object' && !Array.isArray(value);
const array = value => Array.isArray(value) ? value : [];
const own = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
const fallbacks = Object.freeze({
  'commerce.product.priceText': 'commerce.product.price',
  'commerce.product.imageAlt': 'commerce.product.title'
});
const unsafe = field => !object(field) || field.private === true || field.public === false || /private|planning|internal/i.test(String(field.scope || '')) || /^\{(?:echo|php|eval)(?:[:}])/i.test(String(field.tag || ''));

/** Inspect exported authority without changing it or making any provider call. */
export function inspectBindings(source, { fields: selected = [] } = {}) {
  const bytes = typeof source === 'string' ? fs.readFileSync(source) : Buffer.from(JSON.stringify(source));
  const graph = typeof source === 'string' ? JSON.parse(bytes.toString('utf8')) : source;
  const bricks = graph?.bricks || graph?.siteGraph?.bricks || {};
  const catalogue = [...array(bricks.dynamicTags), ...array(bricks.kiweDynamicTags)];
  const tags = new Set(catalogue.map(value => object(value) ? value.name || value.tag : value).filter(value => typeof value === 'string').map(value => value.startsWith('{') ? value : `{${value}}`));
  const fields = graph?.dynamicBindings?.schema === 'kiwe.dynamic-bindings.v1' && object(graph.dynamicBindings.fields) ? graph.dynamicBindings.fields : {};
  const names = selected.length ? [...new Set(selected)] : Object.keys(fields);
  const results = names.map(name => {
    const field = own(fields, name) ? fields[name] : null;
    if (!unsafe(field) && tags.has(field.tag)) return { requested: name, status: 'native', binding: name, tag: field.tag, kind: field.kind, scope: field.scope };
    const replacement = fallbacks[name], next = replacement && own(fields, replacement) ? fields[replacement] : null;
    if (!unsafe(field) && !unsafe(next) && tags.has(next.tag) && field.owner === 'woocommerce' && next.owner === field.owner && field.scope === 'current-public-product' && next.scope === field.scope && field.kind === 'text' && next.kind === field.kind) {
      return { requested: name, status: 'native-fallback', binding: replacement, tag: next.tag, kind: next.kind, scope: next.scope, reason: 'Advertised tag absent from exported native catalogue; use the declared same-record native field. Keep the original export and verify native formatting during conversion.' };
    }
    return { requested: name, status: 'blocked', binding: null, tag: null, reason: !field ? 'No declared public binding.' : 'No proven compatible native tag/fallback; do not change SiteGraph or invent one.' };
  });
  const blocked = results.filter(item => item.status === 'blocked').length;
  return { schema: 'kiwe.binding-preflight.v1', ok: selected.length ? blocked === 0 : true, inspectionOnly: true, projectReadinessCertified: false, sourceSha256: crypto.createHash('sha256').update(bytes).digest('hex'), nativeCataloguePresent: catalogue.length > 0, requestedFields: selected.length ? selected : 'all-advertised', counts: { fields: results.length, native: results.filter(item => item.status === 'native').length, fallback: results.filter(item => item.status === 'native-fallback').length, blocked }, results };
}
