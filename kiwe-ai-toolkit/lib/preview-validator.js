import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { validateSeamMap } from './seam-map-validator.js';
import schemas from './authoring-schemas.cjs';

const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const object = value => value && typeof value === 'object' && !Array.isArray(value);
const array = value => Array.isArray(value) ? value : [];
const hex = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);

/** Verify local evidence and readiness consistency. Browser execution remains the author's job. */
export function validatePreview(target = '.') {
  const root = path.resolve(target), findings = [], checked = new Set();
  const fail = message => findings.push({ level: 'fail', message });
  const file = relative => {
    if (typeof relative !== 'string' || !relative || path.isAbsolute(relative) || /[\\?#\u0000]/.test(relative) || relative.split('/').some(part => !part || part === '.' || part === '..' || part === '.git')) throw Error('Unsafe or noncanonical project path.');
    const resolved = path.resolve(root, relative);
    if (!resolved.startsWith(root + path.sep)) throw Error('Path escapes preview root.');
    let current = root;
    for (const part of relative.split('/')) { current = path.join(current, part); if (fs.lstatSync(current).isSymbolicLink()) throw Error('Linked preview evidence is not admitted.'); }
    if (!fs.statSync(resolved).isFile() || fs.statSync(resolved).size > 67108864) throw Error('Ordinary bounded evidence file required.');
    return resolved;
  };
  const json = relative => { try { return JSON.parse(fs.readFileSync(file(relative), 'utf8')); } catch (error) { fail(`${relative}: ${error.message}`); return null; } };
  const verify = (entry, label) => {
    try {
      if (!object(entry) || !hex(entry.sha256) || !Number.isSafeInteger(entry.bytes) || entry.bytes < 0) throw Error('Exact path/bytes/SHA-256 required.');
      const bytes = fs.readFileSync(file(entry.path));
      if (bytes.length !== entry.bytes || sha(bytes) !== entry.sha256) throw Error('Evidence bytes changed.');
      return bytes;
    } catch (error) { fail(`${label}: ${error.message}`); return null; }
  };
  const receipt = json('authoring/preview-receipt.json');
  const discovery = json('ideate/kiwe-ideate-discovery.json');
  const bootstrap = json('authoring/bootstrap-receipt.json');
  if (!schemas.discovery(discovery)) fail('Discovery JSON Schema: '+schemas.discovery.errors.slice(0,5).map(e=>`${e.instancePath} ${e.message}`).join('; '));
  if (!schemas.receipt(receipt)) fail('Preview receipt JSON Schema: '+schemas.receipt.errors.slice(0,5).map(e=>`${e.instancePath} ${e.message}`).join('; '));
  if (discovery?.schema !== 'kiwe.ideate-discovery.v5' || discovery?.approval?.status !== 'approved') fail('The formal reviewed and approved /ideate artifact is required.');
  if (!object(bootstrap) || bootstrap.canonicalStart !== 'https://start.kiwelaunch.com/start.md' || typeof bootstrap.contractVersion !== 'string' || !['canonical','attached-kit'].includes(bootstrap.source) || !['connected-tool','task-capsule','attached-export','none'].includes(bootstrap.projectDataTransport)) fail('A truthful canonical/attached-kit bootstrap receipt and project-data transport are required.');
  const kit = json('start/handoff.json');
  if (kit?.schema !== 'kiwe.authoring-kit.v1' || kit.canonicalStart !== 'https://start.kiwelaunch.com/start.md' || !Array.isArray(kit.files) || !kit.files.length || kit.files.length > 2000 || kit.contractVersion !== bootstrap?.contractVersion) fail('A matching pinned Start kit must accompany a reusable preview handoff.');
  else {
    const names = new Set();
    for (const entry of kit.files) {
      if (!object(entry) || typeof entry.path !== 'string') { fail('Malformed Start kit member.'); continue; }
      if (names.has(entry.path)) fail('Duplicate Start kit member.'); names.add(entry.path);
      verify({ ...entry, path: `start/${entry.path}` }, 'Pinned Start kit');
    }
    for (const required of ['start.md','command-manifest.json','contracts/authoring-delivery.md','contexts/ideate.md','contexts/preview.md','schemas/ideate-discovery.schema.json','schemas/preview-receipt.schema.json','bin/kiwe.js','lib/preview-validator.js','PROMPT.txt']) if (!names.has(required)) fail(`Missing Start dependency ${required}.`);
  }
  const bootstrapResources = array(bootstrap?.resources), resourceNames = new Set();
  for (const entry of bootstrapResources) { verify(entry,'Bootstrap contract'); if(object(entry))resourceNames.add(entry.path); }
  for (const name of ['start/start.md','start/command-manifest.json','start/contracts/authoring-delivery.md','start/contexts/preview.md']) if (!resourceNames.has(name)) fail(`Bootstrap did not commit to ${name}.`);
  if (receipt?.schema !== 'kiwe.preview-receipt.v2' || !['ready-for-design-review','source-exported-unverified','failed'].includes(receipt?.status)) fail('New previews require the explicit v2 receipt and readiness state.');
  if (receipt) {
    const brief = receipt.approvedBrief;
    if (brief?.path !== 'ideate/kiwe-ideate-discovery.json' || !hex(brief?.sha256)) fail('An exact approved-brief commitment is required.');
    else { try { if (sha(fs.readFileSync(file(brief.path))) !== brief.sha256) fail('The approved brief changed after receipt generation.'); } catch (error) { fail(error.message); } }
    if (!Array.isArray(receipt.sourceAssetInventory) || !receipt.sourceAssetInventory.length || receipt.sourceAssetInventory.length > 10000) fail('Bounded complete source/asset inventory required.');
    for (const entry of array(receipt.sourceAssetInventory)) {
      if (!object(entry) || typeof entry.path !== 'string') { fail('Malformed preview inventory member.'); continue; }
      if (checked.has(entry.path)) fail('Duplicate preview inventory entry.'); checked.add(entry.path);
      verify(entry, 'Preview source/asset');
    }
    const actualFiles = [], visit = dir => {
      for (const entry of fs.readdirSync(dir,{withFileTypes:true})) {
        const relative = path.relative(root,path.join(dir,entry.name)).replaceAll('\\','/');
        if (/^(?:start|authoring|ideate|seam|evidence|\.git|node_modules)(?:\/|$)/.test(relative)) continue;
        if (entry.isSymbolicLink()) { fail('Linked visitor source refused.'); continue; }
        if (entry.isDirectory()) visit(path.join(dir,entry.name));
        else if (/\.(?:html?|css|m?js|png|jpe?g|webp|gif|svg|ico|woff2?|ttf|avif|mp4|webm)$/i.test(entry.name)) actualFiles.push(relative);
        if (actualFiles.length > 10000) throw Error('Visitor-source inventory exceeds preview budget.');
      }
    };
    try { visit(root); for (const name of actualFiles) if (!checked.has(name)) fail(`Uninventoried visitor source ${name}.`); } catch(error) { fail(error.message); }
    const runtime = receipt.runtimeResult;
    if (!object(runtime) || !['passed','unavailable','failed'].includes(runtime.status) || !Array.isArray(runtime.viewports) || !Array.isArray(runtime.proof)) fail('Explicit runtime state, viewport list and proof list required.');
    const proofPaths = new Set();
    for (const entry of array(runtime?.proof)) {
      const bytes=verify(entry, 'Runtime proof');
      if (!object(entry)) continue;
      if (proofPaths.has(entry.path)) fail('Each browser proof must reference its own capture.'); proofPaths.add(entry.path);
      if(bytes && !(bytes.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])) || bytes.subarray(0,3).equals(Buffer.from([255,216,255])) || (bytes.toString('ascii',0,4)==='RIFF' && bytes.toString('ascii',8,12)==='WEBP'))) fail('Browser proof must be an actual PNG/JPEG/WebP capture, not a text assertion.');
    }
    if (receipt.status === 'ready-for-design-review') {
      const widths = array(runtime?.viewports).filter(v=>Number.isInteger(v.width)&&Number.isInteger(v.height)&&v.height>=240).map(v=>v.width);
      if (runtime?.status !== 'passed' || !widths.some(w=>w>=1024) || !widths.some(w=>w>=240&&w<=480) || array(runtime?.proof).length<2) fail('Readiness requires referenced desktop and phone browser proof.');
      if(discovery?.contentReadiness?.runtimeSmokeTest !== 'passed')fail('Discovery and runtime proof must agree about browser checks.');
    } else if (receipt.status === 'source-exported-unverified') {
      if (runtime?.status !== 'unavailable' || typeof runtime.reason !== 'string' || !runtime.reason.trim()) fail('An unverified checkpoint must identify the unavailable browser capability.');
      else findings.push({level:'unverified',message:runtime.reason});
    } else fail('The receipt records a failed preview.');
  }
  const map = validateSeamMap(root);
  for (const finding of map.findings.filter(item=>item.level==='fail')) fail(finding.message);
  if (receipt?.semanticMapValidation?.status !== 'passed') fail('Passing recorded semantic-map validation is required.');
  const ok = !findings.some(item=>item.level==='fail');
  return {schema:'kiwe.preview-validation.v1',ok,status:ok?receipt.status:'failed',readyForDesignReview:ok&&receipt.status==='ready-for-design-review',root,inventoryFiles:checked.size,semanticMapPassed:map.ok,browserExecutedByValidator:false,findings};
}
