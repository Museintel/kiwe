const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..');const reports=[];
for(const script of ['tools/release/test-saved-items-consistency.php','tools/release/test-saved-items-client.cjs']){
 const run=spawnSync(script.endsWith('.php')?(process.env.KIWE_PHP||'php'):process.execPath,[script],{cwd:root,encoding:'utf8',windowsHide:true,maxBuffer:3e6});
 if(run.status!==0||/Warning:|Fatal error:|Deprecated:/.test(run.stdout+run.stderr))throw Error(run.stdout+run.stderr);
 const proof=JSON.parse(run.stdout);if(!proof.passed)throw Error('Saved-list behavior qualification failed');reports.push(proof);
}
const service=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Saved/Saved_Items_Service.php'),'utf8');
const snapshot=service.slice(service.indexOf('public function admin_snapshot'),service.indexOf('public function items_for_user'));
if(/get_results|array_map|SELECT/.test(snapshot)||!snapshot.includes('Saved_Report_Index'))throw Error('Private report must use its bounded owner');
console.log(JSON.stringify({passed:true,checks:reports.reduce((n,r)=>n+r.checks,0),reports,scope:'Behavior with synthetic boundaries; isolated native SQL and responsive browser review remain separate gates'}));
