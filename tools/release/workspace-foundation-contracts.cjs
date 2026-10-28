const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { createRequire } = require('node:module');
const localRequire = createRequire(path.resolve(__dirname, '../workspace-prototype/package.json'));
const postcss = localRequire('postcss');
const css = require('../workspace-prototype/workspace-css.cjs');
const tokens = require('../workspace-prototype/generate-workspace-tokens.cjs');
const root = path.resolve(__dirname, '../..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
let assertions = 0;
function check(condition, message) { assertions++; assert.ok(condition, message); }
function shared(text) { const rows = css.records(postcss.parse(text)); return { rows, byKey: new Map(rows.map((r,i) => [r.key,i])) }; }
function split(original, foundation) { return css.factor(original, shared(foundation)); }
function remaining(result, selector, prop) { let n=0; postcss.parse(result.css).walkRules(r => { if(r.selector===selector) r.walkDecls(prop,()=>n++); }); return n; }

// Expected cascade outcomes, independently specified, rather than snapshots of the implementation.
let result = split('.a{color:red}.b{color:blue}', '.b{color:blue}');
check(remaining(result,'.b','color')===1, 'Equal-specificity overlapping classes retain the late winner');
result = split('.a{color:red}.b{color:blue}', '.a{color:red}');
check(remaining(result,'.a','color')===0 && remaining(result,'.b','color')===1, 'An early shared rule can move without changing the late local winner');
result = split('.a{color:red}.b{color:blue;padding:3px}', '.b{color:blue;padding:3px}');
check(remaining(result,'.b','color')===1 && remaining(result,'.b','padding')===0, 'Preserve only the declaration whose source order matters');
for (const [a,b] of [['button.a','input.b'],['#one','#two'],['.a::before','.b::after'],['[data-mode="one"]','[data-mode="two"]']]) {
  result=split(`${a}{color:red}${b}{color:blue}`,`${b}{color:blue}`);
  check(result.removedDeclarations===1, 'Provably disjoint subjects permit extraction: '+a);
}
result=split('.a{color:red!important}.b{color:blue}', '.b{color:blue}');
check(result.removedDeclarations===1, 'Importance determines the winner independently of order');
result=split('.a{color:red!important}.b{color:blue!important}', '.b{color:blue!important}');
check(result.removedDeclarations===0, 'Two important declarations still preserve source order');
result=split('.a{font:inherit}.b{line-height:inherit}', '.b{line-height:inherit}');
check(result.removedDeclarations===0, 'Font shorthand and line-height overlap even with equal value strings');
for (const [a,b] of [['width','inline-size'],['margin-left','margin-inline-start'],['border','border-image'],['word-wrap','overflow-wrap'],['page-break-before','break-before'],['all','color']]) {
  result=split(`.a{${a}:initial}.b{${b}:inherit}`,`.b{${b}:inherit}`);
  check(result.removedDeclarations===0, 'Shorthand, logical property or alias retains its order: '+a+'/'+b);
}
result=split('@media(min-width:1px){.a{color:red}}@supports(display:grid){.b{color:blue}}','@supports(display:grid){.b{color:blue}}');
check(result.removedDeclarations===0, 'Overlapping conditional contexts are conservatively retained');
result=split('.a{color:red}.b{color:blue}.c{color:green}', '.b{color:blue}.c{color:green}');
check(result.removedDeclarations===0, 'A retained predecessor propagates ordering to later shared declarations');
result=split('.a{color:red}.b{color:red}', '.b{color:red}');
check(result.removedDeclarations===1, 'Truly identical property/value declarations need no duplicate');
check(css.specificities(':where(#id,.a)').has('0,0,0'), ':where has zero specificity');
check(css.specificities(':is(#id,.a) button').has('1,0,1'), ':is uses its most specific branch');
check(css.specificities('.a:has(> #id)').has('1,1,0'), ':has counts its relative selector');
check(css.specificities(':nth-child(2n of #id,.a)').has('1,1,0'), 'nth-child includes its of selector');
check(css.specificities('.a:before').has('0,1,1'), 'Legacy pseudo-elements retain element weight');
check(css.specificities(':future-state(.a)')===null, 'Unknown functional syntax is not guessed');
result=split(':future-state(.a){color:red}.b{color:blue}', '.b{color:blue}');
check(result.removedDeclarations===0, 'Unknown selector syntax retains its source ordering');
for (const source of ['@layer a{.a{color:red}}','@scope(.x){.a{color:red}}','.a{& .b{color:red}}']) {
  assertions++; assert.throws(()=>css.records(postcss.parse(source)), /Unsupported|Nested/, 'Unknown cascade contexts must fail closed');
}
check(css.records(postcss.parse('@keyframes a{from{opacity:0}to{opacity:1}}.a{color:red}')).length===1, 'Animation steps are not ordinary cascade candidates');
assertions++; assert.throws(()=>split('.a{color:red}', '.missing{color:blue}'), /complete shared foundation/, 'An incomplete source bundle cannot silently lose geometry');

async function main() {
  const expected=tokens.generate(), actual=read('wp-content/mu-plugins/dsa/assets/css/workspace-tokens.css');
  check(actual===expected, 'Shipped private token CSS equals the real canonical PHP projection');
  const foundation=await css.foundation();
  check(read('wp-content/mu-plugins/dsa/assets/css/workspace-foundation.css')===foundation.css, 'Shared asset is reproducible and contains canonical tokens');
  check(foundation.rows.length>=240&&foundation.css.includes('.kiwe-workspace-header')&&foundation.css.includes('.kiwe-account-menu'), 'Shared geometry includes one responsive header and account disclosure');
  check(!read('tools/workspace-prototype/workspace-screen.css').includes('.kiwe-screen-header'), 'Retired task-header rules cannot compete with the common native/React header');
  check(foundation.css.includes('.kiwe-client-shell .kiwe-shell-app-card.is-off')&&foundation.css.includes('--workspace-tile-surface:var(--kiwe-color-surface-sunken)'), 'Disabled apps use the shared sunken token without muting readable text');
  const owners=['Access/AI_Workspace_Service','Access/Commerce_App_Service','Access/Continuity_App_Service','Access/Dock_Workspace_Service','Access/Guest_App_Service','Access/Identity_App_Service','Access/Media_App_Service','Access/Navigation_App_Service','Access/Notification_App_Service','Access/Post_App_Service','Access/Taxonomy_App_Service','Access/Team_App_Service','Access/Trash_Workspace_Service','SEO/SEO_Refinement_Service','Access/Task_Workspace_UI'];
  for(const owner of owners)check(read('wp-content/mu-plugins/dsa/includes/'+owner+'.php').includes('Workspace_Design_Service::app_dependencies('),'Selected native styles have an explicit dependency: '+owner);
  const publicAssets=read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
  check(!publicAssets.includes('workspace-foundation')&&!publicAssets.includes('workspace-tokens'), 'No public page or dock foundation dependency');
  const manifest=read('wp-content/mu-plugins/dsa/includes/Runtime/Package_Manifest.php');
  check(manifest.includes("'assets/css/workspace-foundation.css'")&&manifest.includes("'assets/css/workspace-tokens.css'"),'Missing critical private assets block incomplete installation');
  const php=spawnSync('php',[path.join(__dirname,'test-workspace-foundation.php')],{encoding:'utf8',windowsHide:true});
  check(php.status===0, 'Actual PHP palette/dependency behavior: '+php.stdout+php.stderr);
  console.log(JSON.stringify({passed:true,assertions,sharedRules:foundation.rows.length,php:JSON.parse(php.stdout),scope:'Build-only cascade regressions and actual PHP with synthetic WordPress I/O; native/browser acceptance is separate'}));
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});
