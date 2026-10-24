<?php
declare(strict_types=1);
// WordPress loads these compatibility functions when the CLI's optional mbstring extension is absent.
if (!function_exists('mb_strlen')) { function mb_strlen($value){return count(preg_split('//u',(string)$value,-1,PREG_SPLIT_NO_EMPTY)?:[]);} }
if (!function_exists('mb_substr')) { function mb_substr($value,$start,$length=null){$chars=preg_split('//u',(string)$value,-1,PREG_SPLIT_NO_EMPTY)?:[];return implode('',array_slice($chars,$start,$length));} }
// Synthetic WordPress I/O only. The actual Kiwe services and capability policy execute.
define('ABSPATH', __DIR__ . '/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/SEO_App_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Native_Indexing_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Search_Report_Snapshot.php';
if (!function_exists('wp_json_encode')) { function wp_json_encode($v,$f=0){return json_encode($v,$f);} }
if (!function_exists('rest_url')) { function rest_url($p=''){return home_url('/wp-json/'.$p);} }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a){return $a;} }
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Bricks/Design_Context_Binding_Service.php';
define('DSA_URL', 'https://example.test/kiwe/');
define('DSA_VERSION', 'test');
class WP_Error {
 public function __construct(public string $code, public string $message='', public array $data=[]) {}
 public function get_error_message(){return $this->message;}
 public function get_error_code(){return $this->code;}
 public function get_error_data(){return $this->data;}
}
class WP_User {
 public string $first_name='';
 public string $description='';
 public string $user_url='';
 public string $user_login='';
 public string $user_email='';
 public string $user_pass='';
 public string $user_registered='2026-09-01 00:00:00';
 public string $locale='en_US';
 public array $allcaps=[];
 public function __construct(public int $ID, public array $roles, public string $display_name='Synthetic person'){$this->user_login='person'.$ID;$this->user_email='person'.$ID.'@example.test';}
 public function set_role($role){$this->roles=$role?[(string)$role]:[];return true;}
}
class WorkspaceDenied extends RuntimeException {}
class WorkspaceRedirect extends RuntimeException {}
class WP_Query {public array $posts; public int $found_posts=0; public int $max_num_pages=0; private array $args; public function get($key){return $this->args[$key]??null;} public function __construct($args){$GLOBALS['post_query']=$args;$this->args=$args;if(!empty($GLOBALS['query_throw']))throw new RuntimeException('synthetic query failure');$this->posts=$GLOBALS['query_posts']??[];}}
$actor=1;$multisite=false;$outside_blog=[];$denied=[];$usermeta=[];$writes=[];$failed_meta='';$fail_add=false;$fail_remove=false;$concurrent_term=0;
$options=['kiwe_site_owner_policy_v1'=>[]];
$users=[1=>new WP_User(1,['administrator']),2=>new WP_User(2,['kiwe_super_admin']),3=>new WP_User(3,['author']),4=>new WP_User(4,['subscriber']),5=>new WP_User(5,['contributor']),6=>new WP_User(6,['editor'])];
$terms=[]; foreach([10,20,30] as $id)$terms[$id]=(object)['term_id'=>$id,'taxonomy'=>'category','name'=>'Category '.$id,'slug'=>'category-'.$id,'parent'=>0,'description'=>'Preserve'];
$posts=[101=>(object)['ID'=>101,'post_type'=>'post','post_status'=>'publish'],102=>(object)['ID'=>102,'post_type'=>'post','post_status'=>'draft'],103=>(object)['ID'=>103,'post_type'=>'product','post_status'=>'publish']];
$relationships=[101=>[10,30],102=>[10],103=>[10]];
function __($s,$d=''){return $s;} function _n($singular,$plural,$number,$domain=''){return $number===1?$singular:$plural;} function esc_html__($s,$d=''){return $s;} function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function absint($s){return abs((int)$s);} function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));} function wp_html_excerpt($s,$n,$more=''){return implode('',array_slice(preg_split('//u',$s,-1,PREG_SPLIT_NO_EMPTY),0,$n));}
function wp_unslash($s){return $s;} function is_wp_error($v){return $v instanceof WP_Error;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function get_network_option($network,$k,$d=false){return get_option($k,$d);}
function get_role($role){return (object)['capabilities'=>array_fill_keys(['read','manage_options','edit_posts','edit_pages','edit_users','manage_categories'],true)];}
function is_multisite(){return $GLOBALS['multisite'];} function is_user_member_of_blog($id){return !in_array($id,$GLOBALS['outside_blog'],true);}
function get_current_blog_id(){return 1;} function get_current_user_id(){return $GLOBALS['actor'];}
function get_userdata($id){return $GLOBALS['users'][$id]??false;} function wp_get_current_user(){return get_userdata(get_current_user_id());}
function is_user_logged_in(){return (bool)wp_get_current_user();} function is_admin(){return true;} function wp_doing_ajax(){return false;} function wp_doing_cron(){return false;}
function get_post($id){return $GLOBALS['posts'][$id]??null;} function get_post_type($id){return get_post($id)->post_type??false;}
function user_can($id,$cap,...$args){$id=$id instanceof WP_User?$id->ID:$id;$u=get_userdata($id);if(!$u||in_array($cap.':'.($args[0]??0),$GLOBALS['denied'],true)||in_array($cap,$GLOBALS['denied'],true))return false;if(in_array($cap,['edit_user','edit_user_meta'],true))$cap='edit_users';return !empty($GLOBALS['access']->client_capabilities($u->allcaps,[],[],$u)[$cap]);}
function current_user_can($cap,...$args){
 if(in_array($cap.':'.($args[0]??0),$GLOBALS['denied'],true)||in_array($cap,$GLOBALS['denied'],true))return false;
 if(in_array($cap,['edit_user','edit_user_meta'],true))return user_can(get_current_user_id(),'edit_users');
 if($cap==='delete_user')return user_can(get_current_user_id(),'delete_users');
 if($cap==='promote_user')return user_can(get_current_user_id(),'promote_users');
 if($cap==='edit_post')return user_can(get_current_user_id(),'edit_posts')&&in_array(get_post_type($args[0]),['post','attachment'],true);
 if($cap==='read_post')return user_can(get_current_user_id(),'read')&&get_post($args[0]);
 if($cap==='edit_term')return current_user_can('manage_categories');
 return user_can(get_current_user_id(),$cap);
}
function get_user_meta($id,$key,$single=true){return $GLOBALS['usermeta'][$id][$key]??'';}
function update_user_meta($id,$key,$v){if($key===$GLOBALS['failed_meta'])return false;$GLOBALS['writes'][]=[$id,$key,$v];$GLOBALS['usermeta'][$id][$key]=$v;if(isset($GLOBALS['after_native_meta_write']))($GLOBALS['after_native_meta_write'])($id,$key,$v);return true;}
function get_users($args){$GLOBALS['user_query']=$args;return $GLOBALS['query_users']??[];}
function get_taxonomy($name){return in_array($name,['category','post_tag'],true)?(object)['public'=>true,'cap'=>(object)['manage_terms'=>'manage_categories','edit_terms'=>'manage_categories','assign_terms'=>'edit_posts']]:false;}
function get_term($id,$taxonomy=''){return $taxonomy==='post_tag'?($GLOBALS['tag_terms'][$id]??null):($GLOBALS['terms'][$id]??null);}
function get_terms($args){$GLOBALS['term_query']=$args;return $GLOBALS['query_terms']??array_values($GLOBALS['terms']);}
function term_exists($term,$taxonomy=''){foreach(($taxonomy==='post_tag'?($GLOBALS['tag_terms']??[]):$GLOBALS['terms'])as$id=>$value)if(is_numeric($term)&&(int)$term===(int)$id||is_string($term)&&strcasecmp((string)$value->name,$term)===0)return ['term_id'=>(string)$id,'term_taxonomy_id'=>(string)$id];return 0;}
function wp_insert_term($name,$taxonomy){$GLOBALS['term_write']=[$name,$taxonomy];if($taxonomy==='post_tag'){$id=90+count($GLOBALS['tag_terms']??[]);$GLOBALS['tag_terms'][$id]=(object)['term_id'=>$id,'taxonomy'=>'post_tag','name'=>$name,'slug'=>sanitize_key($name)];return ['term_id'=>$id];}if(!($GLOBALS['kiwe_post_term_fixture']??false))return ['term_id'=>90];$id=90+count($GLOBALS['terms']??[]);$GLOBALS['terms'][$id]=(object)['term_id'=>$id,'taxonomy'=>$taxonomy,'name'=>$name,'slug'=>sanitize_key($name)];return ['term_id'=>$id];}
function wp_update_term($id,$taxonomy,$input){$GLOBALS['term_write']=[$id,$taxonomy,$input];foreach($input as $k=>$v)$GLOBALS['terms'][$id]->$k=$v;if(isset($GLOBALS['after_native_term_write']))($GLOBALS['after_native_term_write'])($id);return ['term_id'=>$id];}
function wp_get_object_terms($id,$tax,$args){return $GLOBALS['relationships'][$id]??[];}
function wp_add_object_terms($id,$ids,$tax){if($GLOBALS['fail_add'])return new WP_Error('db');$GLOBALS['relationships'][$id]=array_values(array_unique([...$GLOBALS['relationships'][$id],...$ids]));if($GLOBALS['concurrent_term'])$GLOBALS['relationships'][$id][]=$GLOBALS['concurrent_term'];return $ids;}
function wp_remove_object_terms($id,$ids,$tax){if($GLOBALS['fail_remove'])return false;$GLOBALS['relationships'][$id]=array_values(array_diff($GLOBALS['relationships'][$id],$ids));return true;}
function wp_verify_nonce($nonce,$key){return $nonce===$key;}
function check_admin_referer($key){if(!wp_verify_nonce($_POST['_wpnonce']??'',$key))throw new WorkspaceDenied('nonce');}
function wp_die($s,$title='',$args=[]){throw new WorkspaceDenied((string)$s);}
function wp_safe_redirect($url){throw new WorkspaceRedirect($url);} function admin_url($p){return 'https://example.test/wp-admin/'.$p;}
function home_url($p=''){return 'https://example.test'.$p;}
function add_query_arg($a,$b,$c=null){return $c??$b;}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}function delete_transient($k){unset($GLOBALS['transients'][$k]);}
function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;return true;}function wp_generate_uuid4(){return '11111111-2222-4333-8444-555555555555';}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Site_Owner_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Template_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Analytics/Analytics_Report_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Analytics_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Media_App_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Workspace_Admin_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Context_Media_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Context_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Navigation_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/AI_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Backup_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Content_Archive_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Backup/Unit_Catalog_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/PhoneKey/PhoneKey_Bridge.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Event_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Team_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Category_Workspace_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Score_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Defaults_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Sitemap_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Profile_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/User_Profile_Service.php';
// Presentation tests replace the independently tested journey projection, not native business I/O.
eval('namespace DSA\\Onboarding; final class Design_Context_Journey_Service { public const PAGE="kiwe-design-context"; public static function url(){return \admin_url("admin.php?page=".self::PAGE);} public static function projection(){return null;} public static function progress_markup(){} public static function feature_enabled($id){$p=\get_option(Design_Context_Profile_Service::OPTION_PROFILE,[]);return $id==="services"?!empty($p["services"]["enabled"]):!empty($p["about"]["team"]["enabledByOwner"]);} }');
$access=new DSA\Access\WordPress_Role_Access_Service();
$options[DSA\Access\Site_Owner_Service::OPTION]=['enabled'=>true,'ownerId'=>2];
$count=0;
function check($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);$GLOBALS['count']++;echo 'PASS '.$label."\n";}
function denied($fn){try{$fn();return false;}catch(WorkspaceDenied $e){return true;}}
