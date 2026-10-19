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
