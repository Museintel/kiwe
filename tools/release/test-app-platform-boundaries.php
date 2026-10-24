<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');define('WP_CONTENT_DIR',sys_get_temp_dir().'/kiwe-unused-app-boundary');define('DB_NAME','boundary');define('WC_VERSION','11.2.1');
$source=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
require __DIR__.'/fixtures/app-runtime-autoloader.php';
eval(str_replace('public const BUNDLED = true;','public const BUNDLED = false;',substr(file_get_contents($source.'Apps/Package_Layout.php'),5)));
require $source.'Utilities/Origin_Checker.php';
require $source.'Access/Task_Workspace_UI.php';
use DSA\Apps\Library_Service as Library;
use DSA\Apps\Release_Coordinator as Coordinator;
use DSA\Apps\Native_Update_Service as Updates;
use DSA\Apps\Store_App_Service as Store;
use DSA\Apps\SEO_App_Service as SEO;
$checks=[];$caps=[];$writes=[];$hooks=[];$options=[Store::OPTION=>'1',SEO::OPTION=>'1','design_context'=>['business'=>'complete'],'saved_bookmarks'=>['post:9']];$network=0;$filemods=true;$multi=false;$nonce=true;
class WP_Error{public function __construct(public $code,public $message){}public function get_error_code(){return$this->code;}public function get_error_message(){return$this->message;}}
class WP_REST_Request{public function __construct(public string $method='POST',public array $body=[],public array $headers=['x-wp-nonce'=>'wp_rest','x-kiwe-mutation'=>'1']){}public function get_method(){return$this->method;}public function get_json_params(){return$this->body;}public function get_header($k){return$this->headers[strtolower($k)]??'';}}
class WP_REST_Response{public array $headers=[];public function __construct(public $data,public int $status=200){}public function header($k,$v){$this->headers[$k]=$v;}}
class BoundaryDB{public string $prefix='wp_fixture_';public bool $locked=false;public array $queries=[];public function prepare($q,...$args){return[$q,$args];}public function get_var($query){$this->queries[]=$query[0];if(str_contains($query[0],'GET_LOCK')){if($this->locked)return'0';$this->locked=true;return'1';}if(str_contains($query[0],'RELEASE_LOCK')){$this->locked=false;return'1';}return'0';}}
$wpdb=new BoundaryDB();
function WC(){return true;}function is_multisite(){return$GLOBALS['multi'];}function current_user_can($cap,...$args){return in_array($cap,$GLOBALS['caps'],true);}function wp_is_file_mod_allowed($why){return$GLOBALS['filemods'];}function wp_doing_cron(){return false;}function is_admin(){return false;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_unslash($v){return$v;}function __($v,$domain=''){return$v;}
function get_option($k,$fallback=false){return$GLOBALS['options'][$k]??$fallback;}function update_option($k,$v,$autoload=null){$GLOBALS['writes'][]=$k;$GLOBALS['options'][$k]=$v;return true;}function get_site_option($k,$d=false){return get_option($k,$d);}function wp_json_encode($v){return json_encode($v);}
function wp_verify_nonce($n,$a){return$GLOBALS['nonce']&&$n===$a;}function home_url($path='/'){return'https://site.test'.$path;}function site_url($p='/'){return home_url($p);}function wp_parse_url($v){return parse_url($v);}function admin_url($p){return home_url('/wp-admin/'.$p);}function add_query_arg($q,$u){return$u.'?'.http_build_query($q);}
function is_wp_error($v){return$v instanceof WP_Error;}function add_action($name,$fn,$priority=10,$args=1){$GLOBALS['hooks'][$name][]=$fn;}function add_filter(...$args){add_action(...$args);}function wp_safe_remote_get(...$args){$GLOBALS['network']++;throw new RuntimeException('Unexpected network request');}
function check(bool $ok,string $label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks'][$label]=true;}
$_SERVER['HTTP_ORIGIN']='https://site.test';$_SERVER['HTTP_HOST']='site.test';$_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='POST';
$before=$options;
check(!Store::enabled()&&!SEO::enabled(),'Requested engines stay absent when their packages are not installed');
check($options===$before&&!$writes&&$network===0,'Absent capability reads preserve complete business context and bookmarks without writes or network');
check(!class_exists('DSA\\Commerce\\Store_Commerce_Analytics_Service',false)&&!class_exists('DSA\\Commerce\\Cart_Payload_Service',false)&&!class_exists('DSA\\SEO\\SEO_Engine_Service',false),'Absent capabilities construct neither business engine');
$library=new Library();
foreach([[],['read'],['manage_woocommerce'],['kiwe_manage_context'],['kiwe_manage_ai_preferences'],['activate_plugins']]as$i=>$role){$caps=$role;check(!$library->permission(new WP_REST_Request()),'Native software authority is not granted through business roles '.$i);}
$caps=['install_plugins','activate_plugins','update_plugins'];check($library->permission(new WP_REST_Request()),'Native software manager has a nonce-protected same-origin route');
$nonce=false;check(!$library->permission(new WP_REST_Request()),'Missing native nonce denies software actions');$nonce=true;
$_SERVER['HTTP_ORIGIN']='https://other.test';check(!$library->permission(new WP_REST_Request()),'Foreign-origin software actions denied');$_SERVER['HTTP_ORIGIN']='https://site.test';
check(!$library->permission(new WP_REST_Request('POST',[],['x-wp-nonce'=>'wp_rest'])),'Software writes require explicit mutation proof');
foreach([['operation'=>'unknown'],['operation'=>'advance','app'=>'unknown','job'=>str_repeat('a',36),'cursor'=>0],['operation'=>'refresh','extra'=>true],['operation'=>'start','app'=>'seo','automatic'=>'1','expected'=>str_repeat('a',64)]]as$i=>$input){$r=$library->mutate(new WP_REST_Request('POST',$input));check($r->status>=400,'Malformed exact-schema library action denied '.$i);}
$caps=['update_plugins'];$r=$library->mutate(new WP_REST_Request('POST',['operation'=>'review','app'=>'seo','automatic'=>false]));check($r->status===409,'Update-only permission cannot install new software');
$caps=['install_plugins','activate_plugins','update_plugins'];$filemods=false;check(!DSA\Apps\Native_State::allowed(),'Hosting file-modification restriction is preserved');$filemods=true;$multi=true;check(!DSA\Apps\Native_State::allowed(),'Unqualified multisite installation is refused');$multi=false;
check(Updates::offer('unchanged',[],'unrelated/unrelated.php',[])==='unchanged','Unrelated native update offers are untouched');
check(Updates::download(false,'https://other.test/package.zip',null,['plugin'=>'other/other.php','action'=>'update'])===false,'Unrelated native downloads use their own owner');
check(Coordinator::acquire()&&$wpdb->locked,'Core can acquire the shared native writer lease');
check(!Coordinator::acquire(),'A second writer in this request is refused');
$r=Updates::download(false,'https://app.kiwelaunch.com/apps/v1/releases/seo/1.0.0/kiwe-seo.zip',null,['plugin'=>'kiwe-seo/kiwe-seo.php','action'=>'update']);
check(is_wp_error($r)&&$wpdb->locked,'A refused App update cannot release the Core writer’s lease');
Coordinator::release();check(!$wpdb->locked,'Only the acquired lease is released');
check($options===$before&&!$writes&&$network===0,'All denials and conflict paths preserve native state and send no requests');
define('REST_REQUEST',true);$caps=[];check(!DSA\Apps\Catalogue_Service::maintenance_context(),'Public REST requests cannot initiate catalogue maintenance');$caps=['install_plugins'];check(DSA\Apps\Catalogue_Service::maintenance_context(),'An authorized native software manager can check the catalogue through REST');
echo json_encode(['passed'=>true,'cleanup'=>true,'checks'=>count($checks),'labels'=>array_keys($checks),'scope'=>'Real Core/App permission, absence and writer coordination with synthetic WordPress I/O'],JSON_UNESCAPED_SLASHES)."\n";
