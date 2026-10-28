<?php
declare(strict_types=1);
namespace DSA { class Settings { function defaults(){return['ai'=>['max_native_tokens'=>3000,'token_saver_enabled'=>true]];} function get($key,$default=[]){return$this->defaults()[$key]??$default;} } }
namespace {
define('ABSPATH',__DIR__.'/');define('DSA_VERSION','8.0.0-rc.437');
require __DIR__.'/fixtures/app-runtime-autoloader.php';
$base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
eval(str_replace('public const BUNDLED = true;','public const BUNDLED = false;',substr(file_get_contents($base.'Apps/Package_Layout.php'),5)));
require $base.'AI/AI_Broker_Service.php';require $base.'AI/AI_Prompt_Service.php';require $base.'AI/AI_Feature_Policy.php';
$caps=['kiwe_manage_guest_applications'];$options=[];$checks=[];$writes=[];
function current_user_can($v){return in_array($v,$GLOBALS['caps'],true);}function get_option($k,$d=false){return$GLOBALS['options'][$k]??$d;}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}function wp_generate_uuid4(){return'11111111-1111-4111-a111-111111111111';}function apply_filters($key,$value,...$args){return$value;}function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function absint($v){return abs((int)$v);}function __($v,$d=''){return$v;}function wp_salt($v){return'synthetic-optional-app-test';}function admin_url($p){return'https://site.test/wp-admin/'.$p;}function wp_cache_delete(...$args){}function update_option($k,$v,$auto=null){$GLOBALS['writes'][]=$k;$GLOBALS['options'][$k]=$v;return true;}
class WP_Error {function __construct(public $code,public $message,public $data=[]){}}
class OptionalDatabase{public $prefix='fixture_';public $held=false;function prepare($q,...$a){return$q;}function get_var($q){if(str_contains($q,'GET_LOCK')){if($this->held)return'0';$this->held=true;return'1';}$this->held=false;return'1';}}
$wpdb=new OptionalDatabase();
function check($v,$label){$GLOBALS['checks'][$label]=(bool)$v;if(!$v)throw new RuntimeException($label);}
try{
 foreach(['guest','ai','sitegraph']as$id){$owner=DSA\Apps\Runtime_Registry::owner($id);check(!$owner::enabled(),'Missing optional App stays off '.$id);}
 check(DSA\Apps\Guest_App_Service::change(true,DSA\Apps\Guest_App_Service::expected()) instanceof WP_Error&&!$writes,'Missing Guest package cannot be enabled or create a shadow state');
 $caps=[];check(DSA\Apps\AI_App_Service::change(true,DSA\Apps\AI_App_Service::expected()) instanceof WP_Error&&!$writes,'Role cannot enable AI');
 $broker=new DSA\AI\AI_Broker_Service(new DSA\Settings());check(!class_exists('DSA\\AI\\AI_Provider_Service',false),'Constructing the shared broker does not load absent paid transport');
 $status=$broker->status();check(!$status['provider']['configured']&&$status['provider']['reason']==='ai_app_unavailable','Absent AI has an honest inert provider status');
 $result=$broker->request(['service'=>'studio','capability'=>'draft','user'=>'Synthetic prompt']);check($result['called']===false&&$result['reason']==='ai_app_unavailable'&&!$writes,'Absent AI cannot send reserve or audit a paid request');
 $prompt=$broker->build_prompt(['business'=>['name'=>'Example']],'Create a homepage');check(str_contains($prompt['user'],'Example')&&str_contains($prompt['user'],'Create a homepage')&&!class_exists('DSA\\AI\\AI_Provider_Service',false),'Complete deterministic external authoring prompt remains without AI');
 check(DSA\Apps\Runtime_Contract::IDS===['seo','store','guest','ai','sitegraph'],'Five independent software identities are fixed');
 $contract=['schema'=>'kiwe.app-provider.v1','id'=>'guest','coreApi'=>2,'version'=>'1.0.0','requiresPhp'=>'8.2','requiresWp'=>'7.1','requiresWoo'=>'','namespaces'=>[],'classes'=>['DSA\\Access\\Guest_Engine_Service'=>'includes/Access/Guest_Engine_Service.php'],'assets'=>[]];
 check(DSA\Apps\Runtime_Contract::validate($contract),'Exact optional Guest class is admitted');$contract['classes']['DSA\\Security\\Secret_Store']='includes/Access/Guest_Engine_Service.php';check(!DSA\Apps\Runtime_Contract::validate($contract),'Guest cannot claim a Core security class');
 require $base.'Backup/Configuration_Snapshot_Service.php';$recovery=DSA\Backup\Configuration_Snapshot_Service::option_groups()['Kiwe'];
 check(in_array('kiwe_guest_app_enabled_v1',$recovery,true)&&in_array('kiwe_sitegraph_app_enabled_v1',$recovery,true),'Same-site settings recovery retains optional workspace choices even when their engines are absent');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'labels'=>array_keys($checks),'nativeSqlExecuted'=>false,'networkRequests'=>0,'cleanup'=>true])."\n";
}catch(Throwable$e){echo json_encode(['passed'=>false,'checks'=>$checks,'error'=>$e->getMessage()])."\n";exit(1);}
}
