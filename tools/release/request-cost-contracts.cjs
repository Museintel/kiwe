const {spawnSync}=require('node:child_process'),{resolve}=require('node:path');
for(const file of ['test-request-option-primer.php','test-phonekey-visitor-cache.php','test-clean-conversion-template-isolation.php']){
 const r=spawnSync(process.env.KIWE_PHP||'php',['tools/release/'+file],{cwd:resolve(__dirname,'../..'),encoding:'utf8',windowsHide:true});
 process.stdout.write(r.stdout||'');process.stderr.write(r.stderr||'');
 if(r.error)throw r.error;
 if(r.status!==0||/Warning:|Fatal error:|Deprecated:/.test((r.stdout||'')+(r.stderr||'')))process.exit(1);
 if(file==='test-clean-conversion-template-isolation.php'){
  if(!r.stdout.includes('large restoration source stays separate')||!r.stdout.includes('failed invalidation refuses snapshot update and deletion')||!r.stdout.includes('signature tampering and deletion retain source authority'))process.exit(1);
 }else{const p=JSON.parse(r.stdout.trim());if(p.passed!==true||p.checks<1)process.exit(1);}
}
