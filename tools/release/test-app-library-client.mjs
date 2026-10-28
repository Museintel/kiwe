import assert from 'node:assert/strict';
import {APP_IDS,checkedAppLibrary} from '../workspace-prototype/apps-client-contract.mjs';
const good={items:APP_IDS.map(id=>({id})),catalogue:{available:true,message:''}};
assert.equal(checkedAppLibrary(good),good);
assert.equal(checkedAppLibrary({...good,items:[...good.items].reverse()}).items[0].id,'sitegraph');
for(const items of [good.items.slice(0,2),good.items.slice(1),[...good.items,good.items[0]],[...good.items.slice(0,4),good.items[0]],[...good.items.slice(0,4),{id:'unknown'}],[null,...good.items.slice(1)]]) assert.throws(()=>checkedAppLibrary({...good,items}));
assert.throws(()=>checkedAppLibrary({...good,catalogue:null}));
assert.throws(()=>checkedAppLibrary({...good,catalogue:{available:'true',message:''}}));
console.log(JSON.stringify({passed:true,checks:10,scope:'Five-App client discovery, complete native inventory and malformed/duplicate response refusal'}));
