const {spawnSync}=require('node:child_process');
const proof={passed:false,checks:0,nativeSqlExecuted:false};
for(const [binary,args] of [[process.env.KIWE_PHP||'php',['tools/release/test-workspace-dashboard-policy.php']],[process.env.KIWE_PHP||'php',['tools/release/test-optional-native-workflow.php']],[process.execPath,['tools/release/test-tag-search-client.cjs']]]){
 const r=spawnSync(binary,args,{encoding:'utf8',windowsHide:true,maxBuffer:2e6});
 if(r.status!==0||/Warning:|Fatal error:|Deprecated:/.test(r.stdout+r.stderr))throw Error(r.stdout+r.stderr);
 const p=JSON.parse(r.stdout.trim());if(!p.passed)throw Error('Incomplete behavior proof');proof.checks+=p.checks;
}
proof.passed=true;proof.scope='Public search continuation/stale response and Super Admin dashboard policy; actual native SQL and signed deployment are separate';console.log(JSON.stringify(proof));
