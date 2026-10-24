const {spawnSync}=require('node:child_process'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),php=process.env.KIWE_PHP||'php';
for(const args of [[],['--without-woo']]){
 const result=spawnSync(php,['tools/release/test-backup-unit-catalog.php',...args],{cwd:root,encoding:'utf8',windowsHide:true});
 if(result.status!==0||/Warning:|Fatal error:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr||'Backup catalog proof failed');
 const receipt=JSON.parse(result.stdout.trim());
	if(receipt.passed!==true||receipt.cleanup!==true||Object.keys(receipt.checks).length<40||Object.values(receipt.checks).some(value=>value!==true))throw Error('Incomplete backup catalog proof');
 console.log(JSON.stringify(receipt));
}
