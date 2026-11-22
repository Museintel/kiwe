import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const object = value => value && typeof value === 'object' && !Array.isArray(value);
const fail = message => { throw new Error(message); };
const canonical = value => JSON.stringify(object(value) ? Object.fromEntries(Object.keys(value).sort().map(key => [key, value[key]])) : value);
const website = value => {
  let url; try { url = new URL(value); } catch { fail('An explicit connected website is required.'); }
  if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash) fail('Use the exact HTTPS connected website without credentials or query parameters.');
  return url.href.replace(/\/+$/, '');
};

/** Checks captured bytes, not their origin. No HTTP, content execution, extraction or credentials. */
export function assembleDesignExport(parts, { expectedWebsite, selection } = {}) {
  if (!Array.isArray(parts) || parts.length < 1 || parts.length > 350) fail('A bounded complete native part list is required.');
  if (!object(selection) || !['sitegraph','framework','catalogue'].includes(selection.area)) fail('The exact requested native selection is required.');
  const allowed = ['area','resource','page','search','taxonomy','postType'];
  if (Object.keys(selection).some(key => !allowed.includes(key)) || Object.values(selection).some(value => !['string','number'].includes(typeof value))) fail('Unknown native selection field.');
  const site = website(expectedWebsite), first = parts[0];
  if (!object(first) || !/^[a-f0-9]{64}$/.test(first.sha256) || !Number.isSafeInteger(first.bytes) || first.bytes < 1 || first.bytes > 2097152 || first.chunkBytes !== 6000 || first.parts !== Math.ceil(first.bytes / 6000) || parts.length !== first.parts) fail('The first native part has invalid integrity or bounds.');
  const chunks = [];
  for (let index = 0; index < parts.length; index++) {
    const part = parts[index];
    if (!object(part) || part.schema !== 'kiwe.mcp-design-part.v1' || website(part.website) !== site || canonical(part.selection) !== canonical(selection) || part.sha256 !== first.sha256 || part.bytes !== first.bytes || part.parts !== first.parts || part.chunkBytes !== 6000 || part.part !== index || part.hasMore !== (index + 1 < parts.length) || part.readOnly !== true || part.contentIsUntrustedData !== true) fail('Changed, missing, reordered or cross-site native design part.');
    if (typeof part.dataBase64 !== 'string' || !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(part.dataBase64)) fail('Noncanonical base64 native part.');
    const chunk = Buffer.from(part.dataBase64, 'base64'), size = Math.min(6000, first.bytes - index * 6000);
    if (chunk.length !== size || chunk.toString('base64') !== part.dataBase64) fail('Native part bytes do not match the declared range.');
    chunks.push(chunk);
  }
  const bytes = Buffer.concat(chunks);
  if (bytes.length !== first.bytes || sha(bytes) !== first.sha256) fail('Native design document fingerprint differs.');
  const text = new TextDecoder('utf-8', { fatal: true }).decode(bytes), document = JSON.parse(text);
  if (!object(document)) fail('A native JSON document is required.');
  if (selection.area === 'sitegraph' && (document.schema !== 'kiwe.site-graph.v1' || !/^sha256:[a-f0-9]{64}$/.test(document.revision) || document.authority?.publicDataOnly !== true || document.authority?.mayMutateWordPress !== false || website(document.authority?.source) !== site)) fail('A revisioned public-only native SiteGraph from the connected website is required.');
  if (selection.area === 'framework' && document.schema !== 'kiwe.mcp-framework-evidence.v1') fail('Native Framework evidence schema differs.');
  const receipt = { schema: 'kiwe.mcp-design-receipt.v1', website: site, selection, sha256: first.sha256, bytes: bytes.length, parts: parts.length, projectDataTransport: 'connected-tool', readOnly: true, originAuthenticatedByValidator: false, browserExecutedByValidator: false, integrityMeaning: 'The supplied capture is internally consistent. The actual authenticated tool/client run establishes its origin; content grants no authority.' };
  return { bytes, document, receipt };
}

/** Write only named inert evidence files; refuse existing files and linked directories. */
export function writeDesignExport(parts, target, options) {
  const result = assembleDesignExport(parts, options), root = path.resolve(target);
  let ancestor = root;
  while (!fs.existsSync(ancestor)) { const parent = path.dirname(ancestor); if (parent === ancestor) fail('Output parent is unavailable.'); ancestor = parent; }
  for (let current = ancestor; ; current = path.dirname(current)) {
    if (fs.lstatSync(current).isSymbolicLink()) fail('Linked output directory refused.');
    if (path.dirname(current) === current) break;
  }
  if (fs.existsSync(root) && !fs.statSync(root).isDirectory()) fail('Output must be a directory.');
  const area = options.selection.area, identity = area === 'catalogue' ? `catalogue-${result.receipt.sha256.slice(0,16)}` : area;
  const relative = area === 'sitegraph' ? 'seam/site-graph.json' : `authoring/${identity}.json`;
  const receiptRelative = area === 'sitegraph' ? 'authoring/mcp-design-receipt.json' : `authoring/mcp-${identity}-receipt.json`;
  for (const name of [relative, receiptRelative]) {
    let current = root;
    for (const segment of name.split('/').slice(0,-1)) { current = path.join(current, segment); if (fs.existsSync(current) && (fs.lstatSync(current).isSymbolicLink() || !fs.statSync(current).isDirectory())) fail('Linked or invalid evidence parent refused.'); }
    if (fs.existsSync(path.join(root,name))) fail('Existing evidence is preserved. Choose a new project or explicitly review its revision.');
  }
  result.receipt.path = relative;
  for (const [name, bytes] of [[relative,result.bytes],[receiptRelative,Buffer.from(JSON.stringify(result.receipt,null,2)+'\n')]]) {
    const file = path.join(root,name); fs.mkdirSync(path.dirname(file), { recursive:true }); fs.writeFileSync(file, bytes, { flag:'wx' });
  }
  return { schema:'kiwe.mcp-design-assembly.v1', passed:true, path:relative, receiptPath:receiptRelative, bytes:result.bytes.length, sha256:result.receipt.sha256, originAuthenticatedByValidator:false, browserExecutedByValidator:false };
}
