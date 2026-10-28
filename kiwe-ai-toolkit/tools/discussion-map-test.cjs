const assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const original=path.resolve(__dirname,'../fixtures/discussion-launchers-valid');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'kiwe-discussion-map-'));
if(path.dirname(temp)!==os.tmpdir()||!path.basename(temp).startsWith('kiwe-discussion-map-'))throw Error('Owned fixture directory required');
(async()=>{const {validateSeamMap}=await import('../lib/seam-map-validator.js');let checks=0;
const map=JSON.parse(fs.readFileSync(original+'/seam/seam-map.json')),graph=JSON.parse(fs.readFileSync(original+'/seam/site-graph.json'));
fs.mkdirSync(temp+'/seam');fs.copyFileSync(original+'/index.html',temp+'/index.html');
function scenario(change,expected,label){const m=structuredClone(map),g=structuredClone(graph);fs.copyFileSync(original+'/index.html',temp+'/index.html');change(m,g);const text=JSON.stringify(g,null,2)+'\n';m.siteGraph.hash=crypto.createHash('sha256').update(text).digest('hex');fs.writeFileSync(temp+'/seam/site-graph.json',text);fs.writeFileSync(temp+'/seam/seam-map.json',JSON.stringify(m));const result=validateSeamMap(temp);assert.equal(result.ok,expected,label+': '+JSON.stringify(result.findings));checks++;}
scenario(()=>{},true,'all three exact target kinds');
for(const target of[7,'7',null,['current'],'{post_id}','{echo:unsafe}'])scenario(m=>m.documents[0].stitches[0].discussionTarget=target,false,'reject unsupported target '+JSON.stringify(target));
scenario(m=>m.documents[0].stitches[0].module='search',false,'target cannot attach to another module');
scenario(m=>m.documents[0].stitches[0].authority.source='owner',false,'owner prose does not prove native target support');
scenario((m,g)=>delete g.kiwe.discussion,false,'missing native target contract');
scenario((m,g)=>g.kiwe.discussion.core.websitePageId=0,false,'website feedback is not fabricated');
scenario((m,g)=>g.bricks.dynamicTags=[],false,'loop tag needs the actual native catalogue');
scenario((m,g)=>g.kiwe.discussion.bricks.target='{post_id}',false,'a different tag cannot replace native context admission');
scenario((m,g)=>g.kiwe.modules.items=[],false,'the target module must be exposed');
function testimonial(m,g,otherDocument=false){
 g.bricks.dynamicTags.push({name:'{kiwe_testimonial_target_id}'});
 g.bricks.queryLoopTypes=[{objectType:'kiwe_testimonials'}];
 g.kiwe.discussion.bricks.testimonialTarget='{kiwe_testimonial_target_id}';g.kiwe.discussion.bricks.testimonialQuery='kiwe_testimonials';
 m.documents[0].stitches[0].discussionTarget='{kiwe_testimonial_target_id}';
 const collection={kind:'collection',key:'testimonials',containerAnchor:'testimonials',prototypeAnchor:'testimonial',query:{objectType:'kiwe_testimonials'},authority:{source:'sitegraph',pointer:'/bricks/queryLoopTypes/0',evidence:'Synthetic native public array query'}};
 const region='<section data-seam-anchor="testimonials"><article data-seam-anchor="testimonial">Real source prototype</article></section>';
 if(otherDocument){const html='<!doctype html><html lang="en"><head><title>Other fixture</title></head><body>'+region+'</body></html>';fs.writeFileSync(temp+'/other.html',html);m.sources.push({path:'other.html',sha256:crypto.createHash('sha256').update(html).digest('hex')});m.documents.push({...structuredClone(m.documents[0]),path:'other.html',stitches:[collection],closure:{status:'complete',declaredAnchors:2,unresolved:[]}});}
 else{const html=fs.readFileSync(temp+'/index.html','utf8').replace('</body>',region+'</body>');fs.writeFileSync(temp+'/index.html',html);m.sources[0].sha256=crypto.createHash('sha256').update(html).digest('hex');m.documents[0].stitches.push(collection);m.documents[0].closure.declaredAnchors=5;}
}
scenario((m,g)=>testimonial(m,g),true,'testimonial target has an exact same-document public collection');
scenario((m,g)=>{testimonial(m,g);g.bricks.dynamicTags=g.bricks.dynamicTags.slice(0,1);},false,'testimonial target needs the exact native tag');
scenario((m,g)=>{testimonial(m,g);g.bricks.queryLoopTypes=[];},false,'testimonial source needs the native array query');
scenario((m,g)=>{testimonial(m,g);delete g.kiwe.discussion.bricks.testimonialTarget;},false,'testimonial contract cannot be inferred from an available tag');
scenario((m,g)=>testimonial(m,g,true),false,'another document cannot authorize testimonial loop scope');
console.log(JSON.stringify({passed:true,checks,scope:'Strict authoring map, native contextual targets and unavailable-capability refusals'}));
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>fs.rmSync(temp,{recursive:true,force:true}));
