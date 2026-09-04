const {spawnSync}=require('node:child_process');
const {resolve}=require('node:path');
const result=spawnSync('php',['tools/release/test-native-pages.php',...process.argv.slice(2)],{cwd:resolve(__dirname,'../..'),encoding:'utf8'});
process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
if(result.error)throw result.error;
if(result.status!==0||/Warning:|Fatal error:/.test(result.stdout||''))process.exit(1);
