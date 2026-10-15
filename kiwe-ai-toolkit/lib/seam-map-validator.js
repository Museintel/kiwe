import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const token = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/;
const sourcePath = /^(?!\/)(?!.*(?:^|\/)\.\.(?:\/|$))[^\\?#]+\.html?$/i;
const commonTags = new Set(['{post_title}','{post_content}','{post_excerpt}','{post_date}','{post_url}','{post_id}','{post_author}','{featured_image}','{author_name}','{author_url}','{author_bio}','{author_avatar}','{site_title}','{site_tagline}','{site_url}','{term_name}','{term_description}']);

const isObject = value => Boolean(value) && typeof value === 'object' && !Array.isArray(value);
const asArray = value => Array.isArray(value) ? value : [];
const digest = value => crypto.createHash('sha256').update(value).digest('hex');
const finding = (findings, level, message, file = '') => findings.push({ level, message, file });
const readJson = (file, findings, label) => { try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (error) { finding(findings, 'fail', `${label} is not valid JSON: ${error.message}`, file); return null; } };
const exactPointer = (root, pointer) => {
  if (!/^\/(?:[^/~]|~[01])+(?:\/(?:[^/~]|~[01])+)*$/.test(String(pointer || ''))) return undefined;
  let value = root;
  for (const part of pointer.slice(1).split('/').map(item => item.replaceAll('~1', '/').replaceAll('~0', '~'))) {
    if (Array.isArray(value) && /^\d+$/.test(part)) value = value[Number(part)];
    else if (isObject(value) && Object.prototype.hasOwnProperty.call(value, part)) value = value[part];
    else return undefined;
  }
  return value;
};

function locate(target) {
  const resolved = path.resolve(target || '.');
  if (fs.existsSync(resolved) && fs.statSync(resolved).isFile()) return { root: path.dirname(resolved), mapPath: resolved };
  for (const candidate of [path.join(resolved, 'seam', 'seam-map.json'), path.join(resolved, 'seam-map.json')]) if (fs.existsSync(candidate)) return { root: resolved, mapPath: candidate };
  return { root: resolved, mapPath: '' };
}

function graphIndex(graph) {
  const nested = isObject(graph?.siteGraph) ? graph.siteGraph : {};
  const bricks = isObject(graph?.bricks) ? graph.bricks : isObject(nested.bricks) ? nested.bricks : {};
  const tags = new Set(commonTags);
  for (const entry of [...asArray(bricks.dynamicTags), ...asArray(bricks.kiweDynamicTags)]) {
    const raw = isObject(entry) ? entry.name || entry.tag : entry;
    const value = String(raw || '').trim();
    if (value) tags.add(value.startsWith('{') ? value : `{${value.replace(/[{}]/g, '')}}`);
  }
  const queries = new Set(asArray(bricks.queryLoopTypes).map(entry => isObject(entry) ? String(entry.objectType || '') : String(entry || '')).filter(Boolean));
  const modules = isObject(graph?.kiwe?.modules) ? graph.kiwe.modules : isObject(nested?.kiwe?.modules) ? nested.kiwe.modules : {};
  const moduleIds = new Set(asArray(modules.items || modules.modules || modules.registered).map(entry => isObject(entry) ? String(entry.id || entry.module || entry.key || '') : String(entry || '')).filter(Boolean));
  const bindings = graph?.dynamicBindings?.schema === 'kiwe.dynamic-bindings.v1' && isObject(graph.dynamicBindings.fields) ? graph.dynamicBindings.fields : {};
  return { tags, queries, moduleIds, bindings };
}

function anchorsIn(html) {
  return Array.from(html.matchAll(/\bdata-seam-anchor\s*=\s*(["'])(.*?)\1/gi), match => match[2]);
}

function contextMarkersIn(html, findings, rel) {
  const out=[];
  for(const node of html.match(/<[a-z][^>]*\bdata-seam-fields?\s*=\s*["'][^>]*>/gi)||[]) {
    const attribute=name=>node.match(new RegExp(`\\b${name}\\s*=\\s*(["'])(.*?)\\1`,'i'))?.[2];
    const anchor=attribute('data-seam-anchor'),single=attribute('data-seam-field'),multiple=attribute('data-seam-fields');
    if(single!==undefined&&multiple!==undefined) {finding(findings,'fail','Use one Design Context marker form per node.',rel);continue;}
    if(multiple!==undefined) {
      let fields;try{fields=JSON.parse(multiple.replaceAll('&quot;','"').replaceAll('&#39;',"'").replaceAll('&amp;','&'));}catch{finding(findings,'fail','Multi-field marker is not a JSON slot map.',rel);continue;}
      if(!isObject(fields)||!Object.keys(fields).length||Object.entries(fields).some(([slot,value])=>!['text','image','link','alt','aria-label','title'].includes(slot)||typeof value!=='string'||!/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)*$/.test(value))){finding(findings,'fail','Multi-field marker has an invalid slot or public field.',rel);continue;}
      for(const [slot,binding]of Object.entries(fields))out.push({anchor,slot,binding});
    }else out.push({anchor,binding:single,slot:attribute('data-seam-slot')});
  }
  return out;
}

export function validateSeamMap(target = '.') {
  const located = locate(target);
  const findings = [];
  if (!located.mapPath) { finding(findings, 'fail', 'No seam/seam-map.json file found.'); return result(located.root, '', findings); }
  const map = readJson(located.mapPath, findings, 'SEAM Map');
  if (!map) return result(located.root, located.mapPath, findings);
  if (map.schema !== 'kiwe.seam-map.v1' || map.mode !== 'strict' || map.sourcePolicy !== 'marker-only') finding(findings, 'fail', 'SEAM Map must use kiwe.seam-map.v1 strict marker-only mode.', located.mapPath);
  if (!Array.isArray(map.requiresHumanReview) || map.requiresHumanReview.length) finding(findings, 'fail', 'Strict SEAM Map requires an empty human-review queue.', located.mapPath);

  const sourceByPath = new Map(asArray(map.sources).map(entry => [String(entry?.path || ''), entry]));
  const documentPaths = new Set();
  const siteGraphStitches = [];
  for (const document of asArray(map.documents)) {
    const rel = String(document?.path || '');
    if (!sourcePath.test(rel) || documentPaths.has(rel)) { finding(findings, 'fail', `Invalid or duplicate SEAM document path: ${rel}.`, located.mapPath); continue; }
    documentPaths.add(rel);
    const source = sourceByPath.get(rel);
    const file = path.resolve(located.root, rel);
    if (!source || !fs.existsSync(file)) { finding(findings, 'fail', `SEAM source is missing: ${rel}.`, rel); continue; }
    const bytes = fs.readFileSync(file);
    if (!/^[a-f0-9]{64}$/i.test(String(source.sha256 || '')) || digest(bytes) !== String(source.sha256).toLowerCase()) finding(findings, 'fail', `SEAM source fingerprint changed: ${rel}.`, rel);
    const sourceAnchors = anchorsIn(bytes.toString('utf8'));
    if (sourceAnchors.some(value => !token.test(value)) || new Set(sourceAnchors).size !== sourceAnchors.length) finding(findings, 'fail', `SEAM source anchors must be valid and unique: ${rel}.`, rel);
    const stitches = asArray(document.stitches);
    const markers=contextMarkersIn(bytes.toString('utf8'),findings,rel);
    for (const {anchor,binding,slot} of markers) {
      const matches = stitches.filter(stitch=>stitch.kind==='field' && stitch.anchor===anchor && stitch.binding===binding && (!slot || stitch.slot===slot));
      if(matches.length!==1) finding(findings,'fail','Preview field marker has no exact closed field stitch.',rel);
    }
    const keys = stitches.map(stitch => String(stitch?.key || ''));
    if (keys.some(value => !token.test(value)) || new Set(keys).size !== keys.length) finding(findings, 'fail', `SEAM stitch keys must be valid and unique: ${rel}.`, located.mapPath);
    const declared = new Set(stitches.flatMap(stitch => stitch?.kind === 'collection' ? [stitch.containerAnchor, stitch.prototypeAnchor] : [stitch?.anchor]).map(String));
    const orphaned = sourceAnchors.filter(value => !declared.has(value));
    const absent = Array.from(declared).filter(value => !sourceAnchors.includes(value));
    if (orphaned.length || absent.length || Number(document?.closure?.declaredAnchors) !== declared.size || document?.closure?.status !== 'complete' || asArray(document?.closure?.unresolved).length) finding(findings, 'fail', `SEAM closure is incomplete for ${rel}. Orphaned: ${orphaned.join(', ') || 'none'}; missing: ${absent.join(', ') || 'none'}.`, located.mapPath);
    for (const stitch of stitches) {
      if (!['field','collection','launcher','native-element','interaction','condition','static','template-boundary'].includes(stitch?.kind)) finding(findings, 'fail', `Unsupported stitch kind ${stitch?.kind || 'missing'}.`, located.mapPath);
      if (!isObject(stitch?.authority) || !['sitegraph','owner','approved-source','wordpress-contract'].includes(stitch.authority.source) || !String(stitch.authority.pointer || '').trim() || !String(stitch.authority.evidence || '').trim()) finding(findings, 'fail', `Stitch ${stitch?.key || 'unknown'} lacks explicit authority.`, located.mapPath);
      if (stitch?.authority?.source === 'sitegraph') siteGraphStitches.push(stitch);
      if (stitch?.kind === 'field' && (!/^\{[A-Za-z_][^{}<>\r\n]*\}$/.test(String(stitch.dynamicTag || '')) || /^\{(?:echo|do_action|wp_query|php|execute)(?::|\})/i.test(stitch.dynamicTag))) finding(findings, 'fail', `Stitch ${stitch.key} has an unsafe dynamic tag.`, located.mapPath);
      if(stitch?.kind==='field'){
        const slot=stitch.slot,guard=stitch.guard||{},expected={image:'src',link:'href',alt:'alt','aria-label':'aria-label',title:'title'}[slot];
        if(!['text','image','link','alt','aria-label','title'].includes(slot)||(slot==='text'&&guard.expectedText===undefined&&guard.textRange===undefined)||(expected&&guard.expectedAttribute?.name!==expected)||(slot!=='text'&&guard.textRange!==undefined))finding(findings,'fail',`Stitch ${stitch.key} lacks a supported slot and exact source guard.`,located.mapPath);
      }
      if (stitch?.kind === 'field' && stitch.binding !== undefined) {
        if (!/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)*$/.test(String(stitch.binding)) || stitch.authority?.source !== 'sitegraph') finding(findings,'fail',`Stitch ${stitch.key} needs an exact public Design Context binding and SiteGraph authority.`,located.mapPath);
        const matching = markers.filter(marker=>marker.anchor===stitch.anchor && marker.binding===stitch.binding && (!marker.slot||marker.slot===stitch.slot));
        if (matching.length !== 1) finding(findings,'fail',`Stitch ${stitch.key} is missing its exact preview field marker.`,rel);
      }
      if (stitch?.kind === 'interaction' && /(?:<script|javascript:|\beval\s*\(|\bFunction\s*\(|\bfetch\s*\()/i.test(JSON.stringify(stitch.interactions))) finding(findings, 'fail', `Stitch ${stitch.key} contains executable interaction code.`, located.mapPath);
    }
    const boundaries = stitches.filter(stitch => stitch?.kind === 'template-boundary');
    if (new Set(boundaries.map(stitch => String(stitch.outputPath || '').toLowerCase())).size !== boundaries.length || boundaries.some(stitch => !sourcePath.test(String(stitch.outputPath || '')))) finding(findings, 'fail', `Template-boundary output paths must be valid and unique in ${rel}.`, located.mapPath);
    if (boundaries.filter(stitch => stitch.runtime === 'include').length > 1 || boundaries.some(stitch => !['include','exclude'].includes(stitch.runtime))) finding(findings, 'fail', `Template boundaries require explicit runtime ownership with at most one include in ${rel}.`, located.mapPath);
  }
  for (const source of sourceByPath.keys()) if (!documentPaths.has(source)) finding(findings, 'fail', `Fingerprint has no SEAM document contract: ${source}.`, located.mapPath);

  if (siteGraphStitches.length) {
    const declaration = map.siteGraph || {};
    const graphRel = String(declaration.path || '');
    const graphPath = path.resolve(located.root, graphRel);
    if (declaration.schema !== 'kiwe.site-graph.v1' || graphRel !== 'seam/site-graph.json' || !fs.existsSync(graphPath)) finding(findings, 'fail', 'SiteGraph stitches require seam/site-graph.json authority evidence.', located.mapPath);
    else {
      const bytes = fs.readFileSync(graphPath);
      const graph = readJson(graphPath, findings, 'SEAM SiteGraph');
      if (digest(bytes) !== String(declaration.hash || '').toLowerCase()) finding(findings, 'fail', 'SEAM SiteGraph content hash changed.', graphRel);
      if (graph?.schema !== 'kiwe.site-graph.v1' || String(graph.revision || graph.generatedAt || '') !== String(declaration.revision || '')) finding(findings, 'fail', 'SEAM SiteGraph schema or revision does not match the map.', graphRel);
      if (graph) {
        const index = graphIndex(graph);
        for (const stitch of siteGraphStitches) {
          if (exactPointer(graph, stitch.authority.pointer) === undefined) finding(findings, 'fail', `SiteGraph pointer does not exist: ${stitch.authority.pointer}.`, graphRel);
          if (stitch.kind === 'field' && !index.tags.has(stitch.dynamicTag)) finding(findings, 'fail', `SiteGraph does not prove dynamic tag ${stitch.dynamicTag}.`, graphRel);
          if (stitch.kind === 'field' && stitch.binding) {
            const field = index.bindings[stitch.binding];
            if (!field || field.tag !== stitch.dynamicTag || (['image','link'].includes(stitch.slot) && field.kind !== stitch.slot) || (['alt','aria-label','title'].includes(stitch.slot)&&!['text','number'].includes(field.kind))) finding(findings,'fail',`Design Context binding ${stitch.binding} does not prove this exact tag and slot.`,graphRel);
            if (graph.dynamicBindings?.coverage?.complete === false) finding(findings,'fail','Design Context has undeclared bindings; repair the catalogue before compiling.',graphRel);
          }
          if (stitch.kind === 'collection' && !index.queries.has(String(stitch.query?.objectType || ''))) finding(findings, 'fail', `SiteGraph does not prove query type ${stitch.query?.objectType || 'missing'}.`, graphRel);
          if (stitch.kind === 'launcher' && !index.moduleIds.has(String(stitch.module || ''))) finding(findings, 'fail', `SiteGraph does not prove Kiwe module ${stitch.module || 'missing'}.`, graphRel);
        }
      }
    }
  } else if (map.siteGraph?.schema !== null || map.siteGraph?.path !== null || map.siteGraph?.revision !== null || map.siteGraph?.hash !== null) finding(findings, 'fail', 'Static SEAM Maps must declare null SiteGraph authority fields together.', located.mapPath);
  return result(located.root, located.mapPath, findings);
}

function result(root, mapPath, findings) {
  const counts = findings.reduce((all, item) => ({ ...all, [item.level]: (all[item.level] || 0) + 1 }), {});
  return { ok: !findings.some(item => item.level === 'fail'), root, mapPath, counts, findings };
}
