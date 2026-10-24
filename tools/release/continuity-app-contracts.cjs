const {spawnSync}=require('node:child_process'),fs=require('node:fs'),assert=require('node:assert/strict');
const result=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-continuity-app.php'],{encoding:'utf8',windowsHide:true});if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);console.log(result.stdout.trim());
const schemas=JSON.parse(fs.readFileSync('wp-content/mu-plugins/dsa/includes/Access/business-fields.json'));
for(const [area,schema]of Object.entries(schemas)){assert.equal(new Set(schema.fields.map(f=>f.path)).size,schema.fields.length);for(const f of schema.fields){assert(f.label&&f.path&&f.type);assert(!/password|apiKey|secret|bricks|rank_math/i.test(f.path));}}
const ui=fs.readFileSync('tools/workspace-prototype/native-continuity.jsx','utf8');for(const type of ['palette','services','image','person','boolean','textarea','countries'])assert(ui.includes("'"+type+"'"),'Missing renderer: '+type);
const native=fs.readFileSync('wp-content/mu-plugins/dsa/includes/Access/Continuity_App_Service.php','utf8');assert(!/wp_schedule|CREATE TABLE|ALTER TABLE|set_transient/.test(native));assert(native.includes('->save_section(')&&native.includes('->save_indexing(')&&native.includes('->select(')&&native.includes('->change('));
assert(native.includes("'attachment_field'")&&native.includes('wp_update_post(')&&native.includes("'_wp_attachment_image_alt'")&&native.includes('update_post_meta(')&&native.includes('delete_post_meta('),'Attachment details must use native WordPress post and image-alt authorities');
assert(ui.includes('function AttachmentEditor')&&ui.includes('Editing them does not add or remove the file from Design Context.')&&ui.includes('Edit file details'),'Design library must keep native metadata editing distinct from explicit curation');
assert(native.includes("'service_plan'")&&native.includes('planExpected')&&native.includes('save_service_plan'),'Unconnected Services must use a single-plan optimistic writer');
assert(ui.includes("operation:'service_plan'")&&ui.includes("action:record?'save':'delete'")&&ui.includes('Each plan saves independently.'),'Services client must save or remove only the opened plan');
const pages=fs.readFileSync('wp-content/mu-plugins/dsa/includes/Access/Page_Publishing_Service.php','utf8');assert.equal((pages.match(/\$fields\['edit_date'\]\s*=\s*true/g)||[]).length,2,'draft scheduling and publish-now must mark intentional native date edits');
console.log('PASS continuity field renderers and native authorities');
(async()=>{
 const {workflowClient}=await import('../workspace-prototype/workflow-client.mjs');
 for(const endpoint of ['https://site.test/wp-json/dsa/v1/workspace/continuity/services','https://site.test/?rest_route=/dsa/v1/workspace/continuity/services']){
  let seen;const request=workflowClient({endpoint,nonce:'fixture'},async(url,options)=>{seen={url:new URL(url),options};return {ok:true,headers:new Headers({'Content-Type':'application/json'}),json:async()=>({recordMode:true})};},'https://site.test');
  await request('',undefined,{mode:'records',page:6,search:'Care',unapproved:'ignore'});
  assert.equal(seen.url.searchParams.get('mode'),'records');assert.equal(seen.url.searchParams.get('page'),'6');assert.equal(seen.url.searchParams.get('search'),'Care');assert.equal(seen.url.searchParams.has('unapproved'),false);assert.equal(seen.options.cache,'no-store');
	await request('',undefined,{status:'spam',type:'review',page:2});assert.equal(seen.url.searchParams.get('status'),'spam');assert.equal(seen.url.searchParams.get('type'),'review');assert.equal(seen.url.searchParams.get('page'),'2');
	await request('',undefined,{operation:'versions',id:17,revisionId:999});assert.equal(seen.url.searchParams.get('operation'),'versions');assert.equal(seen.url.searchParams.get('id'),'17');assert.equal(seen.url.searchParams.has('revisionId'),false);
	const editorExpected='a'.repeat(64);
	await request('',undefined,{operation:'service_versions',id:17,editorExpected,unapproved:'ignore'});
	assert.equal(seen.url.searchParams.get('operation'),'service_versions');assert.equal(seen.url.searchParams.get('id'),'17');assert.equal(seen.url.searchParams.get('editorExpected'),editorExpected,'Service history must receive the exact reviewed editor proof');assert.equal(seen.url.searchParams.has('unapproved'),false);
 }
 console.log('PASS continuity transport preserves bounded service, feedback, page-version and exact Service editor review proof for both REST URL formats');
})().catch(error=>{console.error(error);process.exitCode=1;});
