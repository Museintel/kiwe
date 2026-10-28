const {spawnSync}=require('node:child_process');
const path=require('node:path');
let failed=false;
for(const[file,minimum]of[['test-native-cart-validation.php',50],['test-cart-quantity-projection.php',18]]){
 const result=spawnSync('php',[path.join(__dirname,file)],{encoding:'utf8',windowsHide:true});
 let proof;try{proof=JSON.parse(result.stdout);}catch{}
 const count=Object.keys(proof?.checks||{}).length;
 const passed=result.status===0&&proof?.passed===true&&count>=minimum&&Object.values(proof.checks).every(Boolean);
 console.log(`${passed?'PASS':'FAIL'} ${file}: ${count} actual-service checks`);
 if(!passed){process.stderr.write(result.stderr||result.stdout||'Fixture unavailable');failed=true;}
}
if(failed)process.exit(1);
