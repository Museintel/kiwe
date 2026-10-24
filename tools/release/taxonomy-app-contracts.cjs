const {spawnSync}=require('node:child_process');
const {resolve}=require('node:path');
const {readFileSync}=require('node:fs');
const root=resolve(__dirname,'../..');
for(const fixture of ['test-taxonomy-app.php','test-tag-groups.php']){
 const result=spawnSync('php',['tools/release/'+fixture],{cwd:root,encoding:'utf8'});
 process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
 if(result.error||result.status!==0||/Warning:|Fatal error:|Deprecated:/.test((result.stdout||'')+(result.stderr||'')))process.exit(1);
}
const source=readFileSync(resolve(root,'wp-content/mu-plugins/dsa/includes/Access/Taxonomy_App_Service.php'),'utf8');
for(const token of ['register_rest_route','wp_insert_term','wp_update_term','wp_add_object_terms','wp_remove_object_terms','X-WP-Nonce','mutation_allowed','service_definitions'])if(!source.includes(token))throw Error('taxonomy adapter lost '+token);
if(/\b(?:set_transient|wp_schedule_single_event|\bwpdb\b|update_option\s*\()/.test(source))throw Error('taxonomy adapter must not add storage or background work');
const ui=readFileSync(resolve(root,'tools/workspace-prototype/native-taxonomy.jsx'),'utf8');
if(!ui.includes("config.view==='services'?all.filter(item=>item.objectType==='service')"))throw Error('dedicated Services view must filter unrelated category authorities');
for(const token of ["config.view==='tags'",'<TagGroups config={config}/>','Post tags','Product tags',"config.view==='tags'?'Tag categories':'Tags'"])if(!ui.includes(token))throw Error('taxonomy app lost native tag or category presentation: '+token);
if(!ui.includes('!isTag&&<div className="native-tax-card-controls'))throw Error('Routine tag cards must not expose category SEO or indexing controls');
const tagGroups=readFileSync(resolve(root,'wp-content/mu-plugins/dsa/includes/Access/Tag_Group_Service.php'),'utf8');
for(const token of ['kiwe_tag_groups_v1','_kiwe_tag_group_id','product_tag','post_tag','source_changed','mutation_allowed'])if(!tagGroups.includes(token))throw Error('tag folder adapter lost '+token);
if(/register_taxonomy|wp_insert_term|wp_delete_term/.test(tagGroups))throw Error('tag folders must not create a shadow taxonomy or mutate native tag identity');
for(const token of ['element.showModal()','aria-modal="true"','event.key===\'Escape\'','event.key===\'Tab\'','restoreDetail.current'])if(!ui.includes(token))throw Error('taxonomy app lost keyboard dialog/detail behavior: '+token);
const css=readFileSync(resolve(root,'tools/workspace-prototype/native-taxonomy.css'),'utf8');
if(!css.includes(':focus-visible')||!css.includes('.native-tax-modal::backdrop'))throw Error('taxonomy app lost visible focus or native dialog backdrop');
console.log('PASS taxonomy app static native-authority checks');
