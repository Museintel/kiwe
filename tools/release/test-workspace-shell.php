<?php
declare(strict_types=1);
// Actual shell and role policy; synthetic WordPress I/O. No native acceptance claim.
ob_start();
require __DIR__.'/workspace-test-bootstrap.php';
$package=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/';
foreach(['Apps/App_Card_Service','Settings','Trust/Trust_Service','Notifications/Notification_Preference_Service','Notifications/Admin_Event_Notification_Service','Access/Workspace_Design_Service','Access/Workspace_Notice_UI','Access/Workspace_Shell_Service','Access/Workspace_Activity_Service','Access/Commerce_App_Service','Access/Page_Workspace_Service','Onboarding/Onboarding_Service','Design/Seam_Token_Service','Site/Site_Identity_Service']as $file)require $package.'includes/'.$file.'.php';
function is_network_admin(){return $GLOBALS['network_admin']??false;}function is_user_admin(){return false;}
function apply_filters($hook,$value,...$args){return $hook==='kiwe_design_context_commerce_available'?($GLOBALS['commerce']??false):$value;}
function add_action($hook,$callback,$priority=10,$count=1){$GLOBALS['hooks'][$hook][]=$callback;}
function has_action($hook){return empty($GLOBALS['hooks'][$hook])?false:10;}
function do_action($hook,...$args){$GLOBALS['actions'][]=$hook;foreach($GLOBALS['hooks'][$hook]??[]as $fn)$fn(...$args);}
function get_plugin_page_hookname($page,$parent){return 'toplevel_page_'.$page;}
function nocache_headers(){$GLOBALS['nocache']=true;}
function wp_enqueue_style($name,...$args){$GLOBALS['styles'][$name]=$args;}function wp_add_inline_style($name,$css){$GLOBALS['inline_styles'][$name]=$css;}function wp_enqueue_script($name,...$args){$GLOBALS['scripts'][$name]=$args;}
function wp_print_styles($handles){}function wp_print_scripts($handles){}function wp_style_is($name,$state){return true;}
function wp_localize_script($handle,$name,$value){$GLOBALS['localized'][$name]=$value;}
function get_bloginfo($key){return $key==='name'?'Juniper Studio':'Thoughtful spaces, made for living.';}
function get_theme_mod($key,$default=false){return $default;}
function wp_get_attachment_image_url($id,$size){$GLOBALS['logo_size']=$size;return '/media/wide-logo.svg';}
function language_attributes(){echo 'lang="en"';}function bloginfo($key){echo 'UTF-8';}function wp_site_icon(){}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_attr($s);}function esc_html_e($s,$d=''){echo esc_html($s);}function esc_attr_e($s,$d=''){echo esc_attr($s);}
function wp_strip_all_tags($value){return strip_tags((string)$value);}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function rest_url($path=''){return 'https://site.invalid/wp-json/'.$path;}
function wp_salt($scheme='auth'){return 'synthetic-salt';}function wp_create_nonce($action){return 'synthetic-nonce';}
function get_post_type_object($type){return (object)['cap'=>(object)['edit_others_posts'=>'edit_others_posts']];}
use DSA\Access\Workspace_Shell_Service as Shell;
$shell=new Shell();$pagenow='admin.php';$_SERVER['REQUEST_METHOD']='GET';$_GET=['page'=>'kiwe-workspace'];$hooks=[];
$shell->register();check(!$hooks&&!Shell::eligible('kiwe-workspace'),'rollout is off without explicit configuration');
define('KIWE_CLIENT_SHELL',true);$shell->register();check(has_action('admin_menu')!==false,'explicit opt-in registers admin presentation only');
define('KIWE_REACT_WORKSPACE',true);require_once $package.'includes/Access/Navigation_App_Service.php';require_once $package.'includes/Access/Guest_App_Service.php';
check(Shell::eligible('kiwe-workspace')&&Shell::eligible('kiwe-identity'),'configured client can enter Home and existing Identity');
check(!Shell::eligible('kiwe-store'),'inactive commerce omits Store');$commerce=true;eval('function WC(){return (object)[];}');check(Shell::eligible('kiwe-store'),'active commerce keeps the existing Store route');
check(!Shell::eligible('kiwe-onboarding')&&!Shell::eligible('arbitrary-plugin'),'legacy invitation and unknown pages keep their original renderer');
$actor=2;check(Shell::eligible('kiwe-workspace')&&!Shell::eligible('arbitrary-plugin'),'technical owner can use Home without capturing technical plugins');$actor=4;check(!Shell::eligible('kiwe-workspace'),'subscriber cannot enter staff shell');
$actor=3;check(Shell::eligible('kiwe-workspace')&&!Shell::eligible('kiwe-identity'),'author gets Home without business-edit access');
$actor=1;$denied=['kiwe_manage_context'];check(!Shell::eligible('kiwe-identity'),'missing business capability denies context shell');$denied=['read'];check(!Shell::eligible('kiwe-workspace'),'read capability is required');$denied=[];
$network_admin=true;check(!Shell::eligible('kiwe-workspace'),'network administration is not captured');$network_admin=false;
$multisite=true;$outside_blog=[1];check(!Shell::eligible('kiwe-workspace'),'nonmember of current site is excluded');$multisite=false;$outside_blog=[];
$_SERVER['REQUEST_METHOD']='POST';check(!Shell::eligible('kiwe-identity'),'native form POST is never intercepted');$_SERVER['REQUEST_METHOD']='GET';$pagenow='admin-post.php';check(!Shell::eligible('kiwe-identity'),'native action route is never intercepted');$pagenow='admin.php';
$shell->pages();check(!has_action('load-toplevel_page_kiwe-workspace'),'unregistered page callback is not captured');
add_action('toplevel_page_kiwe-workspace',static function(){});$shell->pages();check(has_action('load-toplevel_page_kiwe-workspace')!==false,'only an existing page receives the document wrapper');
$options[DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE]=['services'=>['enabled'=>true]];
$tasks=(new DSA\Access\Workspace_Admin_Service())->task_catalog();$labels=array_column($tasks,'label');check($tasks[0]['label']==='Pages'&&in_array('Website & Design Context',$labels,true)&&in_array('Services',$labels,true)&&!array_intersect(['Story & audience','Contact & location','Brand style','Website plan'], $labels),'client Home has a direct Services task without duplicate identity sections');check($tasks[0]['group']==='work'&&in_array('setup',array_column($tasks,'group'),true)&&!in_array('Notifications',$labels,true),'catalog separates Work and Setup while the bell owns alerts');check(!array_intersect(['Products','Orders'],$labels),'non-Woo Home omits commerce tasks');$byLabel=array_column($tasks,null,'label');$guestSetup=array_values(array_filter($tasks,static fn($task)=>'Guests'===$task['label']&&'setup'===$task['group']));$guestDaily=array_values(array_filter($tasks,static fn($task)=>'Guests'===$task['label']&&'daily'===$task['group']));check(isset($byLabel['Navigation'])&&$byLabel['Navigation']['group']==='organize'&&count($guestSetup)===1&&count($guestDaily)===1&&str_contains($byLabel['Navigation']['url'],'admin.php')&&str_contains($guestSetup[0]['url'],'area=setup')&&str_contains($guestDaily[0]['url'],'area=daily'),'Navigation lives in Organize while Guests use Setup and Daily context');
$actor=3;$tasks=(new DSA\Access\Workspace_Admin_Service())->task_catalog();check(!str_contains(json_encode($tasks),'kiwe-identity'),'author task catalog contains no business edit shortcut');$actor=5;$tasks=(new DSA\Access\Workspace_Admin_Service())->task_catalog();$profile=array_values(array_filter($tasks,static fn($task)=>'Profile'===$task['label']));check(count($profile)===1&&str_contains($profile[0]['url'],'kiwe-screen=profile')&&!str_contains($profile[0]['url'],'profile.php'),'contributor Profile tile opens DSA instead of WordPress profile');check(in_array('My contributions',array_column($tasks,'label'),true)&&!in_array('Guests',array_column($tasks,'label'),true),'contributor keeps their outgoing Guest work in Work');$actor=2;check(in_array('WordPress dashboard',array_column((new DSA\Access\Workspace_Admin_Service())->task_catalog(),'label'),true),'owner catalog includes native technical escape');$actor=1;
$options[DSA\Site\Site_Identity_Service::OPTION_LOGO]=17;
$brand=DSA\Site\Site_Identity_Service::workspace_brand();check($brand['siteLogo']==='/media/wide-logo.svg'&&$logo_size==='medium'&&$brand['siteTagline']==='Thoughtful spaces, made for living.'&&!empty($brand['identityUrl']),'client brand uses aspect-preserving logo, native tagline and permitted identity route');
$actor=3;check(DSA\Site\Site_Identity_Service::workspace_brand()['identityUrl']==='','author brand does not offer identity editing');$actor=1;
$styles=[];$scripts=[];$actions=[];$localized=[];
add_action('admin_notices',static function(){echo '<div class="update-nag" data-update-reminder>WordPress update</div><section class="notice kiwe-workspace-notice" data-security-reminder>Existing security reminder</section>';});
add_action('admin_print_styles',static function(){foreach($GLOBALS['styles']as $entry)if(isset($entry[0])&&is_string($entry[0]))echo '<link rel="stylesheet" href="'.esc_url($entry[0]).'">';});
add_action('admin_print_footer_scripts',static function(){foreach($GLOBALS['localized']as $name=>$value)echo '<script>window.'.esc_html($name).'='.wp_json_encode($value,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';foreach($GLOBALS['scripts']as $entry)echo '<script src="'.esc_url($entry[0]).'"></script>';});
ob_start();$shell->render('kiwe-workspace','toplevel_page_kiwe-workspace');$home=ob_get_clean();
check(str_contains($home,'kiwe-shell-main')&&!str_contains($home,'id="adminmenu"'),'Home is its own document, without WordPress sidebar markup');
check(!str_contains($home,'data-security-reminder')&&!str_contains($home,'data-update-reminder')&&!str_contains($home,'kiwe-shell-bell-count')&&str_contains($home,'Notifications, 0 new updates')&&!in_array('admin_enqueue_scripts',$actions,true)&&!in_array('admin_footer',$actions,true),'Persistent website notices do not inflate the new-notification badge');
check(($scripts['kiwe-client-shell'][1]??[])===['wp-element']&&count($localized['KIWE_CLIENT_SHELL_DATA']['tasks'])>0,'Home uses WordPress React and a server-derived catalog');
check(($localized['KIWE_CLIENT_SHELL_DATA']['noticeCount']??null)===2&&($localized['KIWE_CLIENT_SHELL_DATA']['viewerId']??null)===1,'Home projects the actual viewer and website alert count to its private app');
check(str_contains($home,'class="kiwe-shell-tiles"')&&!str_contains($home,'kiwe-shell-group--context')&&str_contains($home,'admin.php?page=kiwe-design-context'),'one native task grid renders before React and survives a missing script');
check(str_contains($home,'src="/media/wide-logo.svg"')&&str_contains($home,'Thoughtful spaces, made for living.')&&str_contains($home,'Edit website identity')&&!str_contains($home,'<strong>Juniper Studio</strong>'),'client header displays the full logo and tagline without a redundant site-name label');
check(str_contains($home,'/?kiwe-screen=profile')&&!str_contains($home,'profile.php'),'Account opens the public Kiwe Profile screen rather than wp-admin profile');
check($nocache===true,'private document requests native no-cache headers');
if(isset($argv[1]))file_put_contents($argv[1].'/home.html',$home);
$styles=[];$scripts=[];$localized=[];
add_action('toplevel_page_kiwe-identity',static function(){echo '<form data-original-native-form method="post" action="admin-post.php"></form>';});
ob_start();$shell->render('kiwe-identity','toplevel_page_kiwe-identity');$html=ob_get_clean();
check(str_contains($html,'data-original-native-form'),'existing page callback owns form rendering');
check(($scripts['kiwe-client-shell'][1]??null)===[]&&$localized['KIWE_CLIENT_SHELL_DATA']['tasks']===[],'native context tasks add no React dependency or full catalog');
$actor=4;check(denied(fn()=>$shell->render('kiwe-identity','toplevel_page_kiwe-identity')),'render rechecks authorization');$actor=1;
if(isset($argv[1])){
 foreach(['identity','story','contact','brand','website-plan','services','store']as $section){
  $page='kiwe-'.$section;$hooks['toplevel_page_'.$page]=[static function()use($argv,$section){echo file_get_contents($argv[1].'/'.$section.'.html');}];
  $hooks['admin_enqueue_scripts']=[static function()use($package){wp_enqueue_style('kiwe-onboarding',DSA_URL.'assets/css/onboarding.css',[],DSA_VERSION);}];
  $styles=[];$scripts=[];$localized=[];$_GET=['page'=>$page];ob_start();$shell->render($page,'toplevel_page_'.$page);$document=ob_get_clean();file_put_contents($argv[1].'/shell-'.$section.'.html',$document);
 }
}
define('REST_REQUEST',true);check(!Shell::eligible('kiwe-workspace'),'REST requests are never intercepted');
$summary=ob_get_clean();echo $summary;echo "$count shell behavior checks passed.\n";
