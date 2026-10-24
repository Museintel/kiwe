const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..');let checks=0;
for(const mode of ['nonwoo','nonwoo-metrics','nonwoo-requested-on','woo-store-off','woo-store-off-metrics','woo','woo-no-surface','woo-paused']){
 const p=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'test-conditional-runtime.php'),mode],{encoding:'utf8'});
 assert.equal(p.status,0,p.stdout+'\n'+p.stderr);const r=JSON.parse(p.stdout);assert.equal(r.passed,true);checks+=r.checks.length;
}
const source=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/assets/js/surface.js'),'utf8');
function fn(name){const a=source.indexOf('\tfunction '+name+'('),b=source.indexOf('\n\tfunction ',a+1);assert(a>=0&&b>a);return source.slice(a,b);}
(async()=>{for(const available of [false,undefined]){
 const c={commerce:{available,settings:{}},data:{restUrl:'https://site.test/rest/'},setTimeout(){throw Error('non-Woo cart timer');},dsaRequest(){throw Error('non-Woo cart request');}};vm.createContext(c);
 vm.runInContext(fn('scheduleCartRefreshSequence')+'\n'+fn('refreshCartState'),c);
 c.scheduleCartRefreshSequence([0,100]);assert.equal(await c.refreshCartState(),null);checks+=2;
}
let gets=0,applied=0;const c={commerce:{available:true,settings:{}},data:{restUrl:'https://site.test/rest/'},nextCartSequence:()=>7,dsaGet:async route=>{assert.equal(route,'/cart');gets++;return{cart:{available:true,count:2}};},applyCartPayload(response,options){assert.equal(response.cart.count,2);assert.equal(options.sequence,7);applied++;}};vm.createContext(c);vm.runInContext(fn('refreshCartState'),c);assert.equal((await c.refreshCartState({rerender:true})).cart.count,2);assert.equal(gets,1);assert.equal(applied,1);checks+=3;
console.log(JSON.stringify({passed:true,checks,scope:'actual Plugin lifecycle and optional services with unrelated owners stubbed; non-Woo browser refresh guards'}));
})().catch(error=>{console.error(error);process.exitCode=1;});
