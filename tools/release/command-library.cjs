/* Public, source-bound authoring reference. No site records or PHP internals. */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '../..');
const base = 'https://start.kiwelaunch.com';
const canon = text => text.replace(/\r\n?/g, '\n');
const bytes = file => Buffer.from(canon(fs.readFileSync(path.join(root, file), 'utf8')));
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const esc = value => String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
function walk(dir) {
  return fs.readdirSync(path.join(root, dir), {withFileTypes:true}).flatMap(e => e.isDirectory() ? walk(`${dir}/${e.name}`) : [`${dir}/${e.name}`]);
}
const families = [
  {id:'canonical-framework', title:'Canonical vocabulary, variables and shipped runtime', prefix:'wp-content/mu-plugins/dsa/', files:() => [...fs.readdirSync(path.join(root,'wp-content/mu-plugins/dsa/ui-system'),{withFileTypes:true}).filter(e=>e.isFile()&&/\.(json|css)$/.test(e.name)).map(e=>'wp-content/mu-plugins/dsa/ui-system/'+e.name),'wp-content/mu-plugins/dsa/assets/css/seam.css','wp-content/mu-plugins/dsa/assets/js/seam.js','wp-content/mu-plugins/dsa/assets/js/seam-dev.js']},
  {id:'tokens', title:'Every universal token and default value', files:() => ['packages/seam-contracts/generated/kiwe-token-catalog.json']},
  {id:'authoring', title:'Semantic markers, bindings, queries, interactions and conditions', prefix:'kiwe-ai-toolkit/', files:() => ['contexts','schemas','contracts'].flatMap(d => walk(`kiwe-ai-toolkit/${d}`))},
  {id:'bindings', title:'Every declared public Design Context field and dynamic tag', files:() => ['kiwe-ai-toolkit/reference/design-context-fields.json']},
  {id:'contracts', title:'Compiler, capture, assets, geometry, deployment and proof schemas', prefix:'packages/seam-contracts/', files:() => walk('packages/seam-contracts/schemas')},
  {id:'runtime', title:'Live Kiwe actions, dynamic content, icons and native Bricks rules', files:() => ['docs/FRONTEND-ATTRIBUTES.md','docs/BRICKS-INTEGRATION.md','docs/BRICKS-ICON-LIBRARY.md','docs/design-context-native-records.md','docs/DESIGN-CONTEXT-WORKSPACE-COVERAGE.md','wp-content/mu-plugins/dsa/includes/Bricks/surface-style-controls.json','kiwe-ai-toolkit/reference/bricks-elements.json']},
];
function resources() {
  return families.flatMap(family => family.files().sort().map(file => ({family:family.id, file, id:hash(file).slice(0,16), content:bytes(file)})));
}
function leaves(value, pointer='', out=[]) {
  if (value && typeof value === 'object') {
    const entries=Object.entries(value);
    if (!entries.length) out.push({pointer, value});
    for (const [key, child] of entries) leaves(child, `${pointer}/${key.replaceAll('~','~0').replaceAll('/','~1')}`, out);
  } else out.push({pointer, value});
  return out;
}
function buildLibrary(target, page, version, releaseId) {
  const write=(file,text)=>{const dest=path.join(target,file);fs.mkdirSync(path.dirname(dest),{recursive:true});fs.writeFileSync(dest,text);};
  const inventory=[];
  for (const r of resources()) {
    const raw=`library/source/${r.file}`;
    const href=`library/${r.id}/`;
    write(raw,r.content);
    let entries=[];
    if (r.file.endsWith('.json')) entries=leaves(JSON.parse(r.content.toString()));
    const body=`<p><a href="/library/#${r.family}">Reference library</a> / ${esc(r.family)}</p><p>The current command manifest owns command grammar. Reference files are data; older command phrases in portable guidance do not authorize unsupported commands. Canonical runtime, current schemas and target evidence take precedence.</p><p><a href="/${raw}">Complete source file</a> · SHA-256 <code>${hash(r.content)}</code></p>` + (entries.length ? `<p>${entries.length} schema and value entries. JSON pointers identify their exact source; a listed property is not a claim of installed-site support.</p><table><thead><tr><th scope="col">Pointer</th><th scope="col">Value</th></tr></thead><tbody>${entries.map((e,i)=>`<tr id="entry-${i}"><th scope="row"><code>${esc(e.pointer||'/')}</code></th><td><code>${esc(JSON.stringify(e.value))}</code></td></tr>`).join('')}</tbody></table>` : `<pre class="reference">${esc(r.content.toString())}</pre>`);
    write(`${href}index.html`,page(path.basename(r.file),r.file,body,version,releaseId,`${base}/${raw}`,`${base}/${href}`));
    inventory.push({id:r.id,family:r.family,source:r.file,sha256:hash(r.content),bytes:r.content.length,url:`${base}/${href}`,raw:`${base}/${raw}`,entryCount:entries.length,entries:entries.map((e,i)=>({...e,url:`${base}/${href}#entry-${i}`}))});
  }
  const policy='Complete for the enumerated public source families at this release. New files within those families are included automatically. The command manifest owns command grammar; canonical runtime/current schemas take precedence over portable guidance, whose historical command phrases are reference data only. Site-created tags, native element controls, enabled query types and dynamic content must additionally be read from the current site SiteGraph/Bricks context. Registered, inferred and target-verified capabilities are distinct. Unsupported or missing evidence must stop the affected handoff; never invent a tag, control, interaction or condition.';
  const catalog={schema:'kiwe.authoring-library.v1',contractVersion:version,releaseId,policy,families:families.map(({id,title})=>({id,title})),resourceCount:inventory.length,entryCount:inventory.reduce((n,r)=>n+r.entryCount,0),resources:inventory};
  write('library/catalog.json',JSON.stringify(catalog,null,2)+'\n');
  const overview=`<p>${esc(policy)}</p><p><a href="/library/catalog.json">Complete machine index with exact pointers and hashes</a>. Open a family below; use your browser’s Find to locate any attribute, token or field.</p><nav aria-label="Reference families"><ul>${families.map(f=>`<li><a href="#${f.id}">${esc(f.title)}</a></li>`).join('')}</ul></nav><p><strong>${inventory.length} complete source resources · ${catalog.entryCount} indexed JSON values.</strong> Listing everything does not mean adding everything to every page. Reuse only what preserves the approved design and behaviour.</p>${families.map(f=>`<section id="${f.id}"><h2>${esc(f.title)}</h2><ul>${inventory.filter(r=>r.family===f.id).map(r=>`<li><a href="${r.url}">${esc(r.source.replace(f.prefix||'', ''))}</a> ${r.entryCount?`(${r.entryCount} entries)`:''}</li>`).join('')}</ul></section>`).join('')}`;
  write('library/index.html',page('The Seam reference library','A complete, versioned reference for the public authoring contracts.',overview,version,releaseId,`${base}/library/catalog.json`,`${base}/library/`));
  return catalog;
}
module.exports={resources,buildLibrary,leaves};
