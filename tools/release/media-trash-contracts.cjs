const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..'),php=process.env.KIWE_PHP||'php';
const run=(args)=>{const r=spawnSync(php,args,{cwd:root,encoding:'utf8',windowsHide:true});if(r.status!==0||/Warning:|Fatal error:|Deprecated:/.test(r.stdout+r.stderr))throw Error(r.stdout+r.stderr);return r.stdout;};
const model=JSON.parse(run(['tools/release/test-media-image-index.php']).trim());assert(model.passed&&model.checks.length>=11);
for(const file of ['includes/Onboarding/Context_Media_Service.php','includes/Access/Trash_Workspace_Service.php'])run(['-l','wp-content/mu-plugins/dsa/'+file]);
run(['-l','tools/release/test-wordpress-media-trash.php']);
const selection=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Onboarding/Context_Selection_Service.php'),'utf8');assert(selection.includes('Context_Media_Service::automatic( $id )'),'server must reject inherited toggle changes');
const journey=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Journey_Service.php'),'utf8');assert(journey.includes("m.meta_value='1'")&&journey.includes('Context_Selection_Service::MEDIA_META'),'progress must retain independent explicit-media evidence');
for(const file of ['native-continuity.jsx','native-media-workspace.jsx']){const source=fs.readFileSync(path.join(root,'tools/workspace-prototype',file),'utf8');assert(source.includes('TrashRecordAction'),'media must share the in-page Trash review');assert(!/confirm\(["']Move this file to Trash/.test(source),'media must not open a blocking native browser prompt');}
console.log(JSON.stringify({passed:true,imageIndexChecks:model.checks.length,nativeProof:'test-wordpress-media-trash.php',privateOnly:true}));
