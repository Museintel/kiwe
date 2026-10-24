const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..'),php=process.env.KIWE_PHP||process.env.PHP_BINARY||'php',receipts=[];
for(const [file,args]of [['test-shipping-class-lifecycle.php',[]],['test-shipping-class-lifecycle.php',['--non-woo']],['test-tax-provider-disposition.php',[]]]){
 const r=spawnSync(php,[path.join(__dirname,file),...args],{cwd:root,encoding:'utf8',maxBuffer:8*1024*1024});
 assert.equal(r.status,0,r.stderr||r.stdout);assert(!/Warning:|Fatal error:|Deprecated:/.test(r.stdout+r.stderr),'Fixture diagnostics are not accepted');
 const lines=r.stdout.trim().split(/\r?\n/),receipt=JSON.parse(lines.at(-1));assert(receipt.passed&&receipt.checks.length&&receipt.checks.every(c=>c.passed));receipts.push({file,mode:receipt.mode||'provider',checks:receipt.checks.length});
}
const source=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Access/Shipping_Class_Workspace_Service.php'),'utf8');
for(const owner of ['wp_insert_term','wp_update_term','wp_delete_term','get_taxonomy','current_user_can','GET_LOCK','ROLLBACK','clean_term_cache'])assert(source.includes(owner),'Native authority '+owner);
assert(!/wp_schedule|dbDelta|set_shipping_class_id|update_option\(/.test(source),'Catalog cannot create background work, shadow storage, assignment or rate writes');
const role=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php'),'utf8');assert(role.includes('shipping/classes(?:/[1-9][0-9]*)?'),'Client REST guard permits the narrow class lifecycle routes');
console.log(JSON.stringify({passed:true,receipts,scope:'Native service behavior with synthetic persistence; separate WordPress and browser acceptance required.'}));
