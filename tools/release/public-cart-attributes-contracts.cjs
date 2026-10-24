const fs=require('fs'),path=require('path'),{spawnSync}=require('child_process');
const root=path.join(__dirname,'../..');
const behavior=spawnSync(process.execPath,[path.join(__dirname,'public-cart-attributes-behavior.cjs')],{encoding:'utf8'});
if(behavior.status!==0)throw Error(behavior.stdout+behavior.stderr);
const admin=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Admin/Admin.php'),'utf8');
if(/name="bricks\[/.test(admin)||/name="search\[bricks_bridge_enabled\]"/.test(admin))throw Error('Core integration switches must not return');
const schema=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Design/Seam_Vocabulary_Schema.php'),'utf8');
for(const name of ['data-kiwe-add-to-cart','data-kiwe-cart-quantity','data-kiwe-cart-status-target'])if(!schema.includes("'attribute' => '"+name+"'"))throw Error('Missing canonical frontend attribute '+name);
for(const mode of ['parent','child','renamed','inactive','missing']){
 const proof=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'test-bricks-auto-controls.php'),mode],{encoding:'utf8'});
 if(proof.status!==0)throw Error(proof.stdout+proof.stderr);
}
for(const mode of ['fresh','existing']){
 const proof=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'test-sitegraph-only-fresh-install.php'),mode],{encoding:'utf8'});
 if(proof.status!==0)throw Error(proof.stdout+proof.stderr);
}
console.log(JSON.stringify({passed:true,browserBehavior:JSON.parse(behavior.stdout.trim()),themeModes:5,installModes:2,noAdminSwitches:true}));
