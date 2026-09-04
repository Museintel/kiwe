<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$options=[]; $meta=[]; $users=[]; $actor=1; $hook='personal_options_update'; $commerce=false; $service_queries=0;
$wpdb=(object)['prefix'=>'wp_'];
class WP_User { public function __construct(public int $ID, public string $display_name, public string $description='', public string $user_url='', public array $caps=[]) {} }
function __(string $s, string $d=''): string { return $s; }
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_user_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;}
function metadata_exists($type,$id,$key){return array_key_exists($key,$GLOBALS['meta'][$id]??[]);}
function get_userdata($id){return $GLOBALS['users'][$id]??false;}
function get_user_by($field,$id){return get_userdata($id);}
function get_users($args){return array_values(array_filter($GLOBALS['users'],fn($u)=>get_user_meta($u->ID,$args['meta_key'])===$args['meta_value']));}
function is_multisite(){return false;}
function user_can($id,$cap,...$args){$u=$id instanceof WP_User?$id:get_userdata($id);return (bool)($u->caps[$cap]??false);}
function get_current_user_id(){return $GLOBALS['actor'];}
function current_user_can($cap,...$args){return user_can(get_current_user_id(),$cap,...$args);}
function current_filter(){return $GLOBALS['hook'];}
function wp_verify_nonce($nonce,$action){return $nonce===$action;}
function wp_unslash($v){return $v;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_textarea_field($v){return sanitize_text_field($v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function absint($v){return abs((int)$v);}
function esc_url_raw($v,$protocols=null){return preg_match('~^https?://~i',(string)$v)?$v:'';}
function wp_update_user($data){foreach($data as $key=>$v)if($key!=='ID')$GLOBALS['users'][$data['ID']]->$key=$v;return $data['ID'];}
function apply_filters($hook,$value,...$args){return $hook==='kiwe_design_context_commerce_available'?$GLOBALS['commerce']:$value;}
function get_pages($args){return $GLOBALS['test_pages']??[];}
function get_post_type($id){foreach($GLOBALS['test_pages']??[] as $page)if($page->ID===$id)return 'page';return false;}
function get_the_title($id){foreach($GLOBALS['test_pages']??[] as $page)if($page->ID===$id)return $page->post_title;return '';}
function wp_strip_all_tags($v){return strip_tags((string)$v);}
function get_bloginfo($key){return ['name'=>'Native title','description'=>'Native tagline','language'=>'en-IN'][$key]??'';}
function wp_timezone_string(){return 'Asia/Kolkata';}
function post_type_exists($type){return false;}
function get_post_types($args,$output='names'){$GLOBALS['service_queries']++;return [];}
function get_post_meta($id,$key,$single=true){return $GLOBALS['test_page_meta'][$id][$key]??'';}
function get_post_status($id){return 'publish';}
function get_site_icon_url($size){return 'https://test.example/icon.png';}
function wp_get_attachment_url($id){return 'https://test.example/image.png';}
function get_avatar_url($id,$args){return 'https://test.example/avatar.png';}
function sanitize_hex_color($v){return preg_match('/^#[a-f0-9]{6}$/i',$v)?$v:null;}
class WP_Error { function __construct(public $code,public $message,public $data=[]){} }
function is_wp_error($v){return $v instanceof WP_Error;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;return true;}
function set_theme_mod($key,$value){$GLOBALS['options']['theme_'.$key]=$value;}
function sanitize_email($v){return filter_var($v,FILTER_SANITIZE_EMAIL);}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
function wp_attachment_is_image($id){return $id===12;}
function wp_kses_post($v){return strip_tags($v,'<p><b>');}
function sanitize_title($v){return sanitize_key($v);}
function wp_generate_uuid4(){return '11111111-2222-4333-8444-555555555555';}

require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/User_Profile_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Profile_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php';
// Only stub the unrelated identity provider; tests exercise the real context and user services.
eval('namespace DSA\\Site; class Site_Identity_Service { const OPTION_LOGO_INVERSE="inverse"; static function attachment_id($key=""){return 12;} static function store_phone(){return "public business phone";} static function store_email(){return "hello@example.test";} static function logo_url($v=""){return "https://test.example/logo.png";} }');
use DSA\Onboarding\User_Profile_Service as Users;
use DSA\Onboarding\Design_Context_Profile_Service as Context;
function check($pass,$name){if(!$pass)throw new RuntimeException($name);echo "PASS $name\n";}
function invoke($object,$name,...$args){return (new ReflectionMethod($object,$name))->invoke($object,...$args);}
$users=[1=>new WP_User(1,'Administrator','Bio','https://example.test',['manage_options'=>true,'edit_user'=>true,'kiwe_manage_team'=>true,'kiwe_manage_context'=>true]),2=>new WP_User(2,'Writer','Writer bio','',['edit_posts'=>true]),3=>new WP_User(3,'Subscriber','Private info','',['read'=>true]),4=>new WP_User(4,'Shop manager','','',['edit_products'=>true])];
$meta=[1=>[Context::USER_META_TEAM_MEMBER=>'1',Context::USER_META_TEAM_TITLE=>'Lead',Context::USER_META_LINKEDIN=>'https://linkedin.com/in/lead', 'session_tokens'=>'secret','user_pass'=>'secret'],2=>[Context::USER_META_TEAM_MEMBER=>'1'],3=>[Context::USER_META_TEAM_MEMBER=>'1'],4=>[]];
$ctx=new Context(); $profile=new Users();
check(Users::eligible(1)&&Users::eligible(2)&&Users::eligible(4)&&!Users::eligible(3),'capability-based staff eligibility excludes subscribers');
$resolved=invoke($ctx,'resolve_people',['about'=>['team'=>['enabled'=>false,'members'=>[['id'=>'legacy-writer','userId'=>2],['name'=>'Unlinked legacy']]]]]);
check(count($resolved['about']['team']['members'])===2,'only selected eligible users enter context; old option cannot override selection');
check($resolved['about']['team']['members'][1]['id']==='legacy-writer','existing stable Bricks member selectors preserved');
check(!str_contains(json_encode($resolved),'secret')&&!str_contains(json_encode($resolved),'user_email')&&!str_contains(json_encode($resolved),'Private info'),'team projection excludes credentials, contact identifiers and non-team users');
$actor=2;
Users::update_public_fields(2,['bio'=>'<b>Updated bio</b>','website'=>'javascript:alert(1)','linkedin'=>'https://linkedin.com/in/writer','teamTitle'=>'CEO','role'=>'administrator','kiwe_public_team_member'=>'1']);
check($users[2]->description==='Updated bio'&&$users[2]->user_url===''&&get_user_meta(2,Context::USER_META_TEAM_TITLE)==='','self-edit sanitizes profile without role/title escalation');
Users::update_public_fields(1,['bio'=>'Hijacked']);
check($users[1]->description==='Bio','self-edit cannot target another user');
$_POST=['kiwe_team_nonce'=>'kiwe_team_profile_2','kiwe_team_member'=>'0','kiwe_team_title'=>'CEO'];
$profile->save_team_fields(2);
check(get_user_meta(2,Context::USER_META_TEAM_MEMBER)==='1','non-admin cannot change team selection');
$actor=1; $_POST['kiwe_team_nonce']='bad'; $profile->save_team_fields(2);
check(get_user_meta(2,Context::USER_META_TEAM_MEMBER)==='1','invalid nonce cannot change selection');
$_POST['kiwe_team_nonce']='kiwe_team_profile_2';$profile->save_team_fields(2);
check(get_user_meta(2,Context::USER_META_TEAM_MEMBER)==='0'&&get_user_meta(2,Context::USER_META_TEAM_TITLE)==='CEO','admin can deselect and set public title');
check(!Users::is_team_member(2),'deselection immediately removes dynamic public eligibility');
$_POST=['kiwe_team_nonce'=>'kiwe_team_profile_3','kiwe_team_member'=>'1'];$profile->save_team_fields(3);
check(!Users::is_team_member(3),'forged subscriber selection stays disabled');
check(!invoke($ctx,'services_enabled',[])&&invoke($ctx,'services_enabled',['services'=>['items'=>[['title'=>'Existing plan']]]]),'fresh services are opt-in; existing service plans stay enabled');
check(!invoke($ctx,'services_enabled',['services'=>['enabled'=>false,'items'=>[['title'=>'Plan']]]]),'explicit service disable overrides legacy content');
$disabled=['services'=>['enabled'=>false,'sourcePostType'=>'service','items'=>[['recordId'=>20]]]];
check(invoke($ctx,'apply_services_context',$disabled,1)===$disabled,'disabled services do not query or mutate posts');
$options[Context::OPTION_PROFILE]=['identity'=>['siteName'=>'Stale name','tagline'=>'Stale tagline','siteType'=>'ecommerce'],'commerce'=>['enabled'=>true],'services'=>['enabled'=>false,'items'=>[['title'=>'Kept plan']]]];
$p=$ctx->current();
check($p['identity']['siteName']==='Native title'&&$p['identity']['tagline']==='Native tagline','native identity wins over stale context');
check(!$p['commerce']['enabled']&&!$ctx->commerce_available(),'store unavailable without active commerce even if site type says ecommerce');
check($service_queries===0,'disabled services never inspect service catalogs on read');
check($p['services']['items'][0]['title']==='Kept plan','disabled plan is preserved');
$commerce=true;check($ctx->commerce_available(),'commerce adapter can enable Store without hardcoded site configuration');
$public=$ctx->public_context(false);
check($public['resources']['source']==='wordpress-media-library'&&$public['resources']['query']['mimeType']==='','media source delegates all MIME types to existing paginated catalog');
check($public['services']['items']===[],'disabled service data is absent from public context');
echo "Native Design Context behavior tests passed.\n";
$commerce=false;$actor=1;
$before=$ctx->current();$options_before=$options;
$result=$ctx->save_section('brand',['brand'=>['tone'=>'warm'],'identity'=>['siteName'=>'Injected'],'contact'=>['email'=>'attacker@example.test'],'seo'=>['allowIndexing'=>false]],1);
check(!is_wp_error($result),'independent Brand save succeeds despite unrelated incomplete contact data');
check($result['identity']===$before['identity']&&$result['contact']===$before['contact'],'section save ignores unrelated identity/contact fields');
check(!isset($options['blogname'])&&!isset($options['blog_public'])&&!isset($options['dsa_settings']),'Brand save never invokes unrelated native setting writers');
check(!isset($options[Context::OPTION_SEO_REPORT]),'section saves never run full-catalog SEO scans');
check(is_wp_error($ctx->save_section('store',[],1)),'Store write unavailable on non-commerce site');
check(is_wp_error($ctx->save_section('brand',[],3)),'Subscriber cannot save business sections');
$users[1]->caps['manage_options']=false;
$result=$ctx->save_section('website-plan',['contentPlan'=>['existingPages'=>[['id'=>99,'visibility'=>'secondary']]]],1);
check(!is_wp_error($result)&&$result['contentPlan']['existingPages']===$before['contentPlan']['existingPages'],'client cannot mutate page indexing through website plan');
$test_pages=[(object)['ID'=>25,'post_title'=>'New native draft','post_status'=>'draft']];
$test_page_meta=[25=>[Context::PAGE_VISIBILITY_META=>'secondary']];
$p=$ctx->current();
check($p['contentPlan']['existingPages']===[['id'=>25,'name'=>'New native draft','status'=>'draft','visibility'=>'secondary']],'new native pages and indexing immediately replace stale context inventory');
define('RANK_MATH_VERSION','test');$test_page_meta[25]['rank_math_robots']=['index'];
check($ctx->current()['contentPlan']['existingPages'][0]['visibility']==='primary','native Rank Math choice reaches Design Context');
$options[Context::OPTION_PROFILE]['contentPlan']['plannedPages']=[['name'=>'Retained planning note','visibility'=>'primary']];
$result=$ctx->save_section('website-plan',['contentPlan'=>['plannedPages'=>[['name'=>'Injected']]]],1);
check($result['contentPlan']['plannedPages'][0]['name']==='Retained planning note','retired planning input cannot erase or replace preserved notes');
