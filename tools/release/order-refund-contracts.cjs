const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..'),php=process.env.KIWE_PHP||'C:/Users/shariq/Documents/dsa-dual-surface/.tools/php-8.4.23/php.exe',checks=[];
const check=(ok,label)=>{checks.push({label,passed:!!ok});if(!ok)throw Error(label);};
for(const file of ['wp-content/mu-plugins/dsa/includes/Access/Order_Workspace_Service.php','tools/release/test-wordpress-order-refunds.php','tools/release/test-order-refund-money.php']){const r=spawnSync(php,['-l',path.join(root,file)],{encoding:'utf8'});check(r.status===0,'PHP syntax '+file);}
const r=spawnSync(php,[path.join(root,'tools/release/test-order-refund-money.php')],{encoding:'utf8'});
if(r.status!==0)throw Error(r.stdout+r.stderr);const money=JSON.parse(r.stdout);check(money.passed&&money.checks===31,'Exact money and disabled-feature behavior');
const owner=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Access/Order_Workspace_Service.php'),'utf8'),ui=fs.readFileSync(path.join(root,'tools/workspace-prototype/native-order-refund.jsx'),'utf8');
check(owner.includes('wc_create_refund( [')&&!owner.includes('process_refund(')&&!owner.includes('process_payment('),'Native Woo refund API owns gateway invocation');
check(owner.includes("'stage' => 'pending'")&&owner.includes('refund_save_guard')&&owner.includes('hash_hmac')&&!owner.includes('START TRANSACTION'),'Signed durable pending marker without pretending to roll back a provider');
check(owner.includes("'restock' => false")&&owner.includes("'record_elsewhere'")&&owner.includes('stockReviewed'),'Record-only recovery refuses automatic stock/provider replay');
check(owner.includes("'beforeRefunds'")&&owner.includes('$expectedIds !== $actualIds'),'Fresh native identities and conflicts are compared');
check(owner.includes("defined( 'DB_NAME' ) ? DB_NAME")&&owner.includes('private function lock_name'),'Ordinary and refund actions share a database-scoped native lock');
check(ui.includes('WorkspaceDialog')&&ui.includes('CoreTabs')&&ui.includes('crypto.randomUUID()')&&!ui.includes('setInterval')&&!ui.includes('localStorage'),'Shared compact journey retains one in-memory intent');
console.log(JSON.stringify({passed:true,checks:checks.length,moneyChecks:money.checks,scope:'Source/disabled/money behavior; exact native storage and browser acceptance remain separate'}));
