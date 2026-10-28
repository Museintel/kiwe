const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'../..'),source='wp-content/mu-plugins/dsa/includes/Bricks/Design_Context_Binding_Service.php';
const php="define('ABSPATH',__DIR__);require $argv[1];echo json_encode(\\DSA\\Bricks\\Design_Context_Binding_Service::fields(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);";
const run=cp.spawnSync('php',['-r',php,path.join(root,source)],{cwd:root,encoding:'utf8',windowsHide:true});
if(run.status!==0)throw Error(run.stderr||'Public binding projection failed');
const fields=JSON.parse(run.stdout);if(Object.keys(fields).length<80||fields['contact.email']?.tag!=='{kiwe_store_email}')throw Error('Complete native public binding definitions required');
const value={schema:'kiwe.public-binding-reference.v1',source,sourceSha256:crypto.createHash('sha256').update(fs.readFileSync(path.join(root,source),'utf8').replace(/\r\n?/g,'\n')).digest('hex'),policy:'Public field definitions only; no values. Current-site dynamicBindings additionally owns record, group, collection and configured commerce tags. Private/planning fields are never inferred from matching labels.',fields};
const target=path.join(root,'kiwe-ai-toolkit/reference/design-context-fields.json'),text=JSON.stringify(value,null,2)+'\n';
if(process.argv.includes('--check')){if(fs.readFileSync(target,'utf8').replace(/\r\n?/g,'\n')!==text)throw Error('Public binding reference drift');}
else{fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,text);}
console.log(JSON.stringify({passed:true,fields:Object.keys(fields).length}));

// Fixed public artwork only. Never serialize site settings or private PHP internals.
const iconOwner='wp-content/mu-plugins/dsa/includes/Bricks/Icon_Library_Service.php',spriteOwner='wp-content/mu-plugins/dsa/assets/icons/lucide/sprite.svg';
const iconPhp="define('ABSPATH',__DIR__);function __($v,$d=''){return $v;}require $argv[1];$out=[];foreach(\\DSA\\Bricks\\Icon_Library_Service::definitions()as$key=>$label)$out[$key]=['tag'=>'{'.$key.'}','label'=>$label,'svg'=>\\DSA\\Bricks\\Icon_Library_Service::render($key)];echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);";
const ir=cp.spawnSync('php',['-r',iconPhp,path.join(root,iconOwner)],{cwd:root,encoding:'utf8',windowsHide:true});if(ir.status!==0)throw Error('Public icon projection failed: '+ir.stderr);
const icons=JSON.parse(ir.stdout);if(!icons.kiwe_icon_app_ios?.svg.includes('0 0 48 48')||!icons.kiwe_icon_app_android?.svg)throw Error('Complete existing app artwork required');
const iconValue={schema:'kiwe.public-icon-reference.v1',source:iconOwner,sourceSha256:crypto.createHash('sha256').update(fs.readFileSync(path.join(root,iconOwner),'utf8').replace(/\r\n?/g,'\n')).digest('hex'),spriteSha256:crypto.createHash('sha256').update(fs.readFileSync(path.join(root,spriteOwner),'utf8').replace(/\r\n?/g,'\n')).digest('hex'),policy:'Fixed public artwork and native Bricks icon tags only. App icons/actions require RC393 or current target evidence. Artwork does not establish app-store enrollment, installation or notification consent.',icons};
const iconTarget=path.join(root,'kiwe-ai-toolkit/reference/icons.json'),iconText=JSON.stringify(iconValue,null,2)+'\n';
if(process.argv.includes('--check')){if(!fs.existsSync(iconTarget)||fs.readFileSync(iconTarget,'utf8').replace(/\r\n?/g,'\n')!==iconText)throw Error('Public icon reference drift');}else fs.writeFileSync(iconTarget,iconText);
console.log(JSON.stringify({passed:true,publicIcons:Object.keys(icons).length}));

// Only the PHP runtime owns capability semantics. Portable packs project these
// groups without replacing their independent layout/presentation vocabularies.
const capabilityOwner='wp-content/mu-plugins/dsa/includes/Design/Seam_Vocabulary_Schema.php';
const capabilityPhp="define('ABSPATH',__DIR__);require $argv[1];echo json_encode(\\DSA\\Design\\Seam_Vocabulary_Schema::contract(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);";
const cr=cp.spawnSync('php',['-r',capabilityPhp,path.join(root,capabilityOwner)],{cwd:root,encoding:'utf8',windowsHide:true});
if(cr.status!==0)throw Error('Public capability projection failed: '+cr.stderr);
const capabilities=JSON.parse(cr.stdout).capabilityAttributes.groups;
const capabilityTargets=['wp-content/mu-plugins/dsa/ui-system/seam-vocabulary.json','wp-content/mu-plugins/dsa/framework-system/contracts/seam-vocabulary.json','kiwe-ai-toolkit/packs/appshell-theme/seam-vocabulary.json'];
for(const relative of capabilityTargets){
 const target=path.join(root,relative),original=fs.readFileSync(target,'utf8'),value=JSON.parse(original);
 for(const key of ['appshellLaunchers','discussion','forms'])value.capabilityAttributes.groups[key]=capabilities[key];
 const output=JSON.stringify(value,null,2)+'\n';
 if(process.argv.includes('--check')){if(original.replace(/\r\n?/g,'\n')!==output)throw Error('Capability projection drift: '+relative);}
 else fs.writeFileSync(target,output);
}
console.log(JSON.stringify({passed:true,capabilityGroups:3,projections:capabilityTargets.length}));
