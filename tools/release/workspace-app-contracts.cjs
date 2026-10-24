const {spawnSync} = require('node:child_process');
for(const [command,args] of [
 ['php',['tools/release/test-workspace-home.php']],
 ['php',['tools/release/test-workspace-entry.php']],
 ['php',['tools/release/test-workspace-notices.php']],
 ['php',['tools/release/test-workspace-notices.php','--no-key']],
 ['php',['tools/release/test-native-task-forms.php']],
 [process.execPath,['tools/release/workspace-native-forms-behavior.cjs']],
 ['php',['tools/release/test-workspace-page-rest.php']],
 ['php',['tools/release/test-business-workspace.php']],
 [process.execPath,['tools/release/workspace-page-name-behavior.cjs']],
]) {
 const result=spawnSync(command,args,{encoding:'utf8',windowsHide:true});
 if(result.status!==0){process.stderr.write((result.stdout||'')+(result.stderr||''));throw result.error||new Error(args[0]+' failed');}
 console.log('PASS '+args[0]);
}
