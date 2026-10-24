const {spawnSync}=require('node:child_process');
const fs=require('node:fs');
for(const args of [[],['--disabled']]){
 const result=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-team-app.php',...args],{encoding:'utf8',windowsHide:true});
 if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);
 process.stdout.write(result.stdout);
}
const source=fs.readFileSync('wp-content/mu-plugins/dsa/includes/Access/Team_App_Service.php','utf8');
const ui=fs.readFileSync('tools/workspace-prototype/native-team.jsx','utf8');
if(/set_transient|wp_schedule|CREATE TABLE|ALTER TABLE|get_users\(/.test(source))throw Error('Team app duplicated storage, queries or background work');
for(const token of ['aria-expanded={open}','aria-controls="native-account-invitation"','first.current?.focus()','toggle.current?.focus()'])if(!ui.includes(token))throw Error('Team invitation lost keyboard disclosure behavior: '+token);
console.log('PASS team app delegates bounded storage work to existing native owner');
const account=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-account-profile.php'],{encoding:'utf8',windowsHide:true});
if(account.status!==0||/Warning:|Fatal error:|Deprecated:/.test(account.stdout+account.stderr))throw Error(account.stdout+account.stderr);
console.log(account.stdout.trim());
const lifecycle=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-account-lifecycle.php'],{encoding:'utf8',windowsHide:true});
if(lifecycle.status!==0||/Warning:|Fatal error:|Deprecated:/.test(lifecycle.stdout+lifecycle.stderr))throw Error(lifecycle.stdout+lifecycle.stderr);
console.log(lifecycle.stdout.trim());
import('../workspace-prototype/team-client.mjs').then(({initialAccountId})=>{
 const assert=require('node:assert/strict');
 for(const input of ['0',0,undefined,'',null,false,'unavailable',-1,1.5,{},[],Number.MAX_SAFE_INTEGER+1])assert.equal(initialAccountId(input),0,'No account reference for '+String(input));
 for(const input of ['24',24])assert.equal(initialAccountId(input),24,'Localized account ID retains its numeric identity');
 console.log('PASS WordPress string bootstrap opens real account references without a false missing-user warning');
}).catch(error=>{console.error(error);process.exitCode=1;});
