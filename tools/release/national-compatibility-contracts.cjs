const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const root=path.resolve(__dirname,'../..');
const result=cp.spawnSync('php',[path.join(__dirname,'test-national-compatibility.php')],{cwd:root,encoding:'utf8',windowsHide:true});
if(result.status!==0)throw Error(result.stderr||result.stdout);
const proof=JSON.parse(result.stdout);if(!proof.passed||!proof.cleanup||proof.checks.some(c=>!c.passed))throw Error('Compatibility behavior failed');
const integration=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php'),'utf8');
const image=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/includes/Bricks/Filter_Image_Service.php'),'utf8');
if(!integration.includes('( new Filter_Image_Service() )->register()')||/wp_ajax_|MutationObserver|fetch\(/.test(image))throw Error('Native image owner must have no public resolver or observer');
console.log(JSON.stringify({passed:true,cleanup:true,behaviorChecks:proof.checks.length,nativeFilterOwner:true,extraPublicRequests:0}));
