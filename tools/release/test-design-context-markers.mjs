import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {validateSeamMap} from '../../kiwe-ai-toolkit/lib/seam-map-validator.js';
const root=fs.mkdtempSync(path.join(os.tmpdir(),'kiwe-markers-'));
const sha=value=>crypto.createHash('sha256').update(value).digest('hex');
const html='<main data-seam-anchor="layout"><p data-seam-anchor="mission" data-seam-field="about.mission" data-seam-slot="text">Preview mission</p></main>';
const graph={schema:'kiwe.site-graph.v1',generatedAt:'revision-1',siteGraph:{bricks:{dynamicTags:['{kiwe_business_mission}','{site_title}']}},dynamicBindings:{schema:'kiwe.dynamic-bindings.v1',coverage:{complete:true},fields:{'about.mission':{tag:'{kiwe_business_mission}',kind:'text'}}}};
const map={schema:'kiwe.seam-map.v1',mode:'strict',sourcePolicy:'marker-only',requiresHumanReview:[],siteGraph:{schema:'kiwe.site-graph.v1',path:'seam/site-graph.json',revision:'revision-1',hash:''},sources:[{path:'home.html',sha256:''}],documents:[{path:'home.html',closure:{status:'complete',declaredAnchors:2,unresolved:[]},stitches:[{kind:'static',key:'layout',anchor:'layout',semantic:'layout',authority:{source:'approved-source',pointer:'home.html',evidence:'Approved source'},reason:'Layout'},{kind:'field',key:'mission',anchor:'mission',semantic:'mission',binding:'about.mission',slot:'text',dynamicTag:'{kiwe_business_mission}',authority:{source:'sitegraph',pointer:'/dynamicBindings/fields/about.mission',evidence:'Public catalogue'},guard:{expectedText:'Preview mission'}}]}]};
let count=0;
function check(input=html,m=structuredClone(map),g=structuredClone(graph),ok=true){
 const bytes=JSON.stringify(g);m.siteGraph.hash=sha(bytes);m.sources[0].sha256=sha(input);
 fs.writeFileSync(path.join(root,'home.html'),input);fs.writeFileSync(path.join(root,'seam/site-graph.json'),bytes);fs.writeFileSync(path.join(root,'seam/seam-map.json'),JSON.stringify(m));
 const result=validateSeamMap(root);assert.equal(result.ok,ok,JSON.stringify(result.findings));count++;
}
try {
 fs.mkdirSync(path.join(root,'seam'));
 check();
 const tag=structuredClone(map);tag.documents[0].stitches[1].dynamicTag='{site_title}';check(html,tag,graph,false);
 const privatePath=structuredClone(map);privatePath.documents[0].stitches[1].binding='identity.password';check(html,privatePath,graph,false);
 check(html.replace('about.mission','about.vision'),map,graph,false);
 check(html.replace('<main ','<main data-seam-field="identity.siteName" '),map,graph,false);
 check(html.replace('data-seam-slot="text"','data-seam-slot="image"'),map,graph,false);
 const incomplete=structuredClone(graph);incomplete.dynamicBindings.coverage.complete=false;check(html,map,incomplete,false);
 const noAuthority=structuredClone(map);noAuthority.documents[0].stitches[1].authority.source='owner';check(html,noAuthority,graph,false);
 const legacy=structuredClone(map);delete legacy.documents[0].stitches[1].binding;check(html.replace(/ data-seam-(?:field|slot)="[^"]+"/g,''),legacy,graph);
 const multi=html.replace('data-seam-field="about.mission" data-seam-slot="text"',`data-seam-fields='{"text":"about.mission","aria-label":"about.mission"}' aria-label="Preview mission"`),multiMap=structuredClone(map);
 multiMap.documents[0].stitches.push({...structuredClone(map.documents[0].stitches[1]),key:'mission-label',slot:'aria-label',guard:{expectedAttribute:{name:'aria-label',value:'Preview mission'}}});
 check(multi,multiMap,graph);
 check(multi.replace('"aria-label":','"onerror":'),multiMap,graph,false);
 const badGuard=structuredClone(multiMap);badGuard.documents[0].stitches[2].guard.expectedAttribute.name='onclick';check(multi,badGuard,graph,false);
 const wrongKind=structuredClone(graph);wrongKind.dynamicBindings.fields['about.mission'].kind='image';check(multi,multiMap,wrongKind,false);
 console.log(JSON.stringify({passed:true,checks:count,scope:'Canonical command validator, exact preview markers and legacy maps'}));
} finally {fs.rmSync(root,{recursive:true,force:true});}
