const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const {readWorkspaceCss}=require('../workspace-prototype/workspace-css.cjs');
const cases=[
 ['Team','native-team.jsx','Access/Team_App_Service.php','team-app'],
 ['Categories','native-taxonomy.jsx','Access/Taxonomy_App_Service.php','taxonomy-app'],
 ['DSA Menu','native-navigation.jsx','Access/Navigation_App_Service.php','navigation-app'],
 ['AI assistance','ai-app-entry.jsx','Access/AI_Workspace_Service.php','ai-app'],
 ['Posts','native-posts.jsx','Access/Post_App_Service.php','posts-app'],
 ['Identity','native-identity.jsx','Access/Identity_App_Service.php','identity-app'],
 ['Pages and Design Library','native-continuity.jsx','Access/Continuity_App_Service.php','continuity-app'],
 ['Guest','native-workflow.jsx','Access/Guest_App_Service.php','guest-app'],
 ['Notifications','native-workflow.jsx','Access/Notification_App_Service.php','notification-app'],
 ['Commerce','native-workflow.jsx','Access/Commerce_App_Service.php','commerce-app'],
 ['SEO','seo-core-workspace.jsx','SEO/SEO_Refinement_Service.php','seo-object-app'],
];
let checked=0;
for(const [label,source,service,asset] of cases){
 const ui=read('tools/workspace-prototype/'+source);
 const php=read('wp-content/mu-plugins/dsa/includes/'+service);
 const css=readWorkspaceCss(asset+'.css');
 const js=read('wp-content/mu-plugins/dsa/assets/js/'+asset+'.js');
 if(!ui.includes('WorkspaceHeader')||!ui.includes('kiwe-screen'))throw Error(label+': shared client screen missing');
 if(!php.includes('Workspace_Chrome_Service::projection('))throw Error(label+': shared site/role projection missing');
 if(!php.includes('Workspace_Design_Service::app_dependencies('))throw Error(label+': native document must depend on shared styles');
 if(!css.includes('.kiwe-workspace-header')||!css.includes('.kiwe-account-menu')||!css.includes('.kiwe-screen>main'))throw Error(label+': built geometry absent');
 if(!js.includes('Account and website'))throw Error(label+': built header absent');
 if(/<b className="(?:native-mark|native-nav-mark|ai-brand)/.test(ui))throw Error(label+': old plugin mark remains');
 checked++;
}
const chrome=read('wp-content/mu-plugins/dsa/includes/Access/Workspace_Chrome_Service.php');
const design=read('tools/workspace-prototype/workspace-screen.css');
if(!chrome.includes("current_user_can( 'kiwe_use_notifications' )")||!chrome.includes('Site_Identity_Service::workspace_brand()')||!chrome.includes('Workspace_Notice_UI::notice_count('))throw Error('Shared chrome must retain site identity and role-scoped notices');
if(!design.includes('--kiwe-space-md')||!design.includes('--kiwe-radius-md')||!design.includes('grid-template-columns:repeat(2,minmax(0,1fr))'))throw Error('Seam geometry or mobile tile contract missing');
const shell=read('wp-content/mu-plugins/dsa/includes/Access/Workspace_Shell_Service.php');
if(!shell.includes('Site_Identity_Service::workspace_brand()')||!shell.includes('task_catalog()'))throw Error('PHP Home fallback must retain site identity and role-derived catalog');

const continuity=read('tools/workspace-prototype/native-continuity.jsx');
for(const token of ['continuity-page-tabs','continuity-page-filters','continuity-library-tabs','continuity-library-filters','continuity-feedback-tabs','continuity-feedback-filters','continuity-service-tabs','continuity-service-filters','RecordCardFooter']){
 if(!continuity.includes(token))throw Error('Pages, feedback, services, or Design Library lost shared collection anatomy: '+token);
}
const navigation=read('tools/workspace-prototype/native-navigation.jsx');
for(const token of ['native-nav-tabs','native-nav-filters','Find a menu category','+ New menu category'])if(!navigation.includes(token))throw Error('Navigation lost shared collection anatomy: '+token);
if(!navigation.includes("openLinks(null,menu.id)")||!navigation.includes('>+ New link</button>'))throw Error('Navigation links must be created inside their destination menu category');
if(/native-nav-tabs[\s\S]{0,600}>\+ New link<\/button>/.test(navigation))throw Error('Navigation must not expose a screen-level New link action');

const taxonomy=read('tools/workspace-prototype/native-taxonomy.jsx');
for(const token of ['native-tax-tabs','kiwe-task-filters',"config.view==='tags'?'Tag categories':'Tags'",'!isTag&&<div className="native-tax-card-controls'])if(!taxonomy.includes(token))throw Error('Categories or Tags lost the agreed presentation: '+token);
if(!taxonomy.includes("config.view==='tags'?'Create and rename the words used to describe posts and products, or organize them into tag categories.'"))throw Error('Tags must remain simple native metadata and category management');

const workflow=read('tools/workspace-prototype/native-workflow.jsx');
for(const token of ['wf-guest-tabs','wf-guest-filters','wf-notification-tabs','function OrderBrowse','wf-order-filters','wf-product-filters'])if(!workflow.includes(token))throw Error('Daily or commerce collection lost shared anatomy: '+token);
if(!workflow.includes('(history.page>1||history.hasNext)'))throw Error('Notification history must hide terminal pagination');
for(const token of ['Product journey','Create / edit','Organize','SeoEditorPanel','setSelected(next)'])if(!workflow.includes(token))throw Error('Product journey lost its saved-record flow: '+token);
const postJourney=read('tools/workspace-prototype/post-journey-editor.jsx');
for(const token of ['CoreTabs','Save draft and organize','TermPicker','View revisions','SeoEditorPanel'])if(!postJourney.includes(token))throw Error('Post journey lost a contextual step: '+token);
const seo=read('tools/workspace-prototype/seo-core-workspace.jsx');
for(const token of ['objectScope','category','Clear','SeoEditorPanel'])if(!seo.includes(token))throw Error('SEO core workspace lost native category context: '+token);

const team=read('tools/workspace-prototype/native-team.jsx');
for(const token of ['native-team-tabs','native-team-filters','team_sort'])if(!team.includes(token))throw Error('Team lost shared collection anatomy: '+token);
if(!design.includes('.kiwe-task-tabs')||!design.includes('.kiwe-task-filters')||!design.includes('.continuity-library-actions')||!design.includes('.kiwe-record-card-footer'))throw Error('Shared task, discovery, peer-action, or footer geometry missing');

console.log(JSON.stringify({passed:true,screens:checked,collections:12,scope:'Source and built private UI inventory; browser/native role acceptance remains separate'}));
