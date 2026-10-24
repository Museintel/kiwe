const {spawnSync}=require('node:child_process');
const fs=require('node:fs');
const php=process.env.KIWE_PHP||process.env.PHP_BINARY||'php';
for(const args of [[],['--disabled']]){
 const result=spawnSync(php,['tools/release/test-post-app.php',...args],{encoding:'utf8',windowsHide:true});
 const output=(result.stdout||'')+(result.stderr||'');
 if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(output))throw Error(output);
 process.stdout.write(output);
}
const source=fs.readFileSync('wp-content/mu-plugins/dsa/includes/Access/Post_App_Service.php','utf8');
const owner=fs.readFileSync('wp-content/mu-plugins/dsa/includes/Access/Post_Workspace_Service.php','utf8');
const ui=fs.readFileSync('tools/workspace-prototype/native-posts.jsx','utf8');
const journey=fs.readFileSync('tools/workspace-prototype/post-journey-editor.jsx','utf8');
if(/set_transient|wp_schedule|CREATE TABLE|ALTER TABLE/.test(source+owner))throw Error('Posts workspace must not add storage or background work');
if(!owner.includes("'number'=>20")||!owner.includes("current_user_can( 'edit_others_posts' )"))throw Error('Author search must be capability-gated and bounded');
for(const token of ['wp_insert_post','wp_update_post','wp_set_post_categories','wp_set_post_terms','wp_insert_term','wp_verify_nonce','Origin_Checker::mutation_allowed'])if(!owner.includes(token)&&!source.includes(token))throw Error('Posts workspace missing native boundary: '+token);
for(const token of ['heading.current?.focus()','CoreTabs','Save draft and organize','View revisions','createTerm={client.createTerm}'])if(!journey.includes(token))throw Error('Post journey lost a native step or keyboard transition: '+token);
for(const token of ['data-post-id','requestAnimationFrame(()=>document.querySelector(selector)?.focus())'])if(!ui.includes(token))throw Error('Posts workspace lost keyboard task transition: '+token);
console.log('PASS posts app delegates bounded writing to native WordPress post, category and tag owners');
