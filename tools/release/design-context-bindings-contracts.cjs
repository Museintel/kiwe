const {spawnSync}=require('node:child_process');
for(const [command,args] of [['php',['tools/release/test-design-context-bindings.php']],['php',['tools/release/test-design-context-bindings.php','--non-woo']],['php',['tools/release/test-design-context-binding-compatibility.php']],['php',['tools/release/test-commerce-bindings.php']],['php',['tools/release/test-commerce-bindings.php','--non-woo']],[process.execPath,['tools/release/test-design-context-markers.mjs']]]){
 const result=spawnSync(command,args,{encoding:'utf8',windowsHide:true});
 if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);
 process.stdout.write(result.stdout);
}
