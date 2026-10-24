/* Execute the real presentation with synthetic, role-filtered server catalogs. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const file = path.join(root, 'wp-content/mu-plugins/dsa/assets/js/modules/surface-panels.js');
const source = fs.readFileSync(file, 'utf8').replace(/export /g, '');
const context = {};
vm.runInNewContext(source + '\nthis.renderPreferences = renderNotifications;', context);
let checks = 0;
function check(value, message) { assert(value, message); checks += 1; }
const ordinary = [
 {id:'account_update',label:'Your account',description:'Changes to your own account.'},
 {id:'cart_reminder',label:'Saved cart reminders',description:'A cart you started.'},
];
const owner = {id:'admin_new_order',label:'New store orders',audience:'admin'};
const channels = [
 {id:'app',label:'App notifications',available:true},
 {id:'email',label:'Email',available:false},
 {id:'sms',label:'SMS',available:false},
 {id:'whatsapp',label:'WhatsApp',available:false},
];
function render(profile, preferences, topics=ordinary) {
 return context.renderPreferences({visualProfile:profile,config:{topics,channels},preferences,user:{loggedIn:true}});
}
function inputs(html, name) {
 return Array.from(html.matchAll(/<input\b[^>]*>/g), match=>match[0]).filter(input=>input.includes('name="'+name+'"'));
}
function selected(html, name) {
 return inputs(html,name).filter(input=>/\schecked(?:\s|>)/.test(input)).map(input=>input.match(/value="([^"]*)"/)[1]);
}
for (const profile of ['legacy','kiwe2027']) {
 const defaults=render(profile,{inboxTopics:['account_update','cart_reminder'],topics:[],channels:[]});
 check(!defaults.includes('<legend>Show in Kiwe</legend>')&&!inputs(defaults,'inboxTopics').length,profile+' exposes no core inbox switch');
 check(defaults.includes('<legend>Send these updates to me</legend>'),profile+' has one delivery topic list');
 check(inputs(defaults,'topics').length===ordinary.length,profile+' never duplicates event fields');
 check(defaults.includes('Your updates are always available in Kiwe.'),profile+' explains core inbox availability once');
 check(selected(defaults,'topics').length===0&&selected(defaults,'channels').length===0,profile+' enrolls no delivery');
 check(!defaults.includes('value="admin_new_order"'),profile+' ordinary catalog exposes no staff topic');
 check(inputs(defaults,'channels').length===1&&inputs(defaults,'channels')[0].includes('value="app"'),profile+' hides unavailable delivery methods');
 check(defaults.includes('Email, SMS and WhatsApp appear here when the website has them ready.'),profile+' explains later gateway setup');
 const old=render(profile,{inboxTopics:[],topics:['account_update'],channels:['app']},ordinary.concat(owner));
 check(!inputs(old,'inboxTopics').length,profile+' stale inbox metadata never becomes a UI control');
 check(JSON.stringify(selected(old,'topics'))===JSON.stringify(['account_update']),profile+' existing delivery choices remain selected');
 check(inputs(old,'topics').length===3,profile+' authorized staff delivery topics share the same single list');
 check(selected(old,'channels').join(',')==='app',profile+' existing push choice remains selected without enrollment');
}
const escaped = render('legacy',{inboxTopics:[],topics:[],channels:[]},[{id:'a"b',label:'<script>unsafe</script>',description:'Safe & readable'}]);
check(escaped.includes('value="a&quot;b"')&&!escaped.includes('<script>unsafe'), 'Topic values and labels stay escaped');
console.log(JSON.stringify({passed:true,checks,scope:'Real legacy and Kiwe 2027 preference renderers; core inbox is not configurable; only one role-scoped optional delivery journey'}));
