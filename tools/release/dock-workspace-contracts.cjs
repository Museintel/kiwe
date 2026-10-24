const fs=require('fs'),path=require('path'),{spawnSync}=require('child_process');
const root=path.resolve(__dirname,'../..'),run=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'test-dock-workspace.php')],{encoding:'utf8'});
if(run.status!==0)throw Error(run.stdout+'\n'+run.stderr);const result=JSON.parse(run.stdout.trim());if(!result.passed)throw Error('Dock behavior failed');
const access=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php'),'utf8');
if(!access.includes("'kiwe-dock-settings' === $page")||!access.includes('/dsa/v1/workspace/dock'))throw Error('Client route boundaries missing');
console.log(JSON.stringify(result));
