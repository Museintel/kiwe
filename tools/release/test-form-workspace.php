<?php
namespace {
define('ABSPATH',__DIR__); define('ARRAY_A','ARRAY_A');
class WP_User { public $ID=1; public $roles=['administrator']; }
class WP_REST_Request implements \ArrayAccess {
 public function __construct(public array $q=[],public string $method='GET',public string $nonce='ok'){}
 function get_query_params(){return $this->q;} function get_method(){return $this->method;} function get_header($k){return $this->nonce;}
 function offsetExists($o):bool{return isset($this->q[$o]);}function offsetGet($o):mixed{return $this->q[$o]??null;}function offsetSet($o,$v):void{$this->q[$o]=$v;}function offsetUnset($o):void{unset($this->q[$o]);}
}
class WP_REST_Response {function __construct(public $data, public $status=200){} }
class WP_Error {function __construct(public $code,public $message){} }
$actor=new WP_User();$suspended=false;$member=true;$multi=false;$nonce=true;$origin=true;$owner=false;$checks=[];
function is_user_logged_in(){global $actor;return $actor->ID>0;}function current_user_can($cap){global $actor;return $cap==='read'||($actor->roles[0]==='administrator'&&in_array($cap,['manage_options','kiwe_manage_context']));}
function wp_get_current_user(){global $actor;return $actor;}function get_current_user_id(){global $actor;return $actor->ID;}function is_multisite(){global $multi;return $multi;}function is_user_member_of_blog($id){global $member;return $member;}
function wp_verify_nonce($a,$b){global $nonce;return $nonce&&$a==='ok';}function __($s,$d=''){return $s;}function sanitize_text_field($s){return trim(strip_tags($s));}function sanitize_textarea_field($s){return strip_tags($s);}
function get_post($id){return (object)['post_title'=>'Contact'];}
function check($ok,$label){global $checks;$checks[]=['passed'=>(bool)$ok,'label'=>$label];if(!$ok)throw new \RuntimeException($label);}
class Db {
 public $prefix='wp_',$last_error='';public array $queries=[];
 function esc_like($s){return $s;}function prepare($sql,...$args){$args=count($args)===1&&is_array($args[0])?$args[0]:$args;foreach($args as $a)$sql=preg_replace('/%[sd]/',is_int($a)?(string)$a:"'".addslashes($a)."'",$sql,1);return $sql;}
 function get_var($sql){$this->queries[]=$sql;return str_starts_with($sql,'SHOW')?'wp_bricks_form_submissions':25;}
 function get_results($sql,$mode){$this->queries[]=$sql;if(str_contains($sql,'GROUP BY'))return [['form_id'=>'abc123','post_id'=>7,'entries'=>25]];return array_map(fn($i)=>['id'=>$i,'form_id'=>'abc123','post_id'=>7,'created_at'=>'2026-10-17 10:00:00'],range(25,5));}
 function get_row($sql,$mode){$this->queries[]=$sql;return ['id'=>25,'form_id'=>'abc123','post_id'=>7,'created_at'=>'2026-10-17 10:00:00','form_data'=>json_encode(['email'=>['type'=>'email','value'=>'example@example.test'],'password'=>['type'=>'password','value'=>'never disclose'],'secret'=>['type'=>'text','value'=>'never disclose']])];}
}
$wpdb=new Db();
}
namespace DSA\Utilities {class Origin_Checker {static function is_same_site_request(){return $GLOBALS['origin'];}}}
namespace DSA\Access {
class Team_Workspace_Service {static function suspended($id){return $GLOBALS['suspended'];}}
class Workspace_Activity_Service {static function snapshots(){return ['forms'=>['ceiling'=>25]];}static function attach_snapshots($response,$snapshots){return $response;}}
class Site_Owner_Service {static function is_owner(){return $GLOBALS['owner'];}}
class WordPress_Role_Access_Service {static function role_for($u){return $u->roles[0];}}
class Task_Workspace_UI {static function text($s,$limit=200){return is_string($s)?substr(\sanitize_text_field($s),0,$limit):'';}static function id($v){return is_scalar($v)?filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):0;}static function response($v){return new \WP_REST_Response($v);}static function failure($v,$status){return new \WP_REST_Response(['message'=>$v->message],$status);}}
}
namespace Bricks\Integrations\Form {class Submission_Database {static function get_table_name(){return 'wp_bricks_form_submissions';}static function get_form_settings($post,$form){return ['submissionFormName'=>'Business enquiries','fields'=>[['id'=>'email','label'=>'Email']]];}}}
namespace {
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Form_Workspace_Service.php';
$service=new \DSA\Access\Form_Workspace_Service();
foreach(['administrator','kiwe_super_admin','editor','author','contributor','subscriber','customer','shop_manager','kiwe_web_designer','kiwe_user'] as $role){$actor->roles=[$role];$owner=$role==='kiwe_super_admin';check(\DSA\Access\Form_Workspace_Service::allowed()===in_array($role,['administrator','kiwe_super_admin']),'Owner-only inbox for '.$role);if(!in_array($role,['administrator','kiwe_super_admin']))check($service->detail(new WP_REST_Request(['id'=>25]))->status===403,'Direct private message denial for '.$role);}
$actor->roles=['administrator'];$owner=false;
$nonce=false;check($service->listing(new WP_REST_Request())->status===403,'Nonce denial before native data');$nonce=true;$origin=false;check($service->listing(new WP_REST_Request())->status===403,'Origin denial');$origin=true;
$multi=true;$member=false;check(!\DSA\Access\Form_Workspace_Service::allowed(),'Cross-site actor denial');$multi=false;$member=true;
$suspended=true;check(!\DSA\Access\Form_Workspace_Service::allowed(),'Suspended owner denial');$suspended=false;
check($service->listing(new WP_REST_Request([], 'POST'))->status===403,'No mutation endpoint');
foreach([['form'=>['bad']],['form'=>"' UNION SELECT"],['before'=>'-5'],['before'=>'bad']] as $q)check($service->listing(new WP_REST_Request($q))->status===400,'Malformed cursor or form filter refused');
$list=$service->listing(new WP_REST_Request(['form'=>'abc123','before'=>30]));check($list->status===200&&count($list->data['items'])===20&&$list->data['hasNext']&&$list->data['total']===25,'Bounded keyset list and exact total');
check(!str_contains(json_encode($list->data),'example@'),'List omits visitor messages');
check((bool)array_filter($wpdb->queries,fn($q)=>str_contains($q,'id<30')&&str_contains($q,"form_id='abc123'")),'Both filters reach prepared native query');
$detail=$service->detail(new WP_REST_Request(['id'=>25]));check($detail->status===200&&count($detail->data['item']['fields'])===1,'Detail omits password and secret fields');
check(!str_contains(json_encode($detail->data),'never disclose'),'Secrets never enter private projection');
$fields=\DSA\Access\Form_Workspace_Service::public_fields(['message'=>['type'=>'textarea','value'=>"Line one\n<script>bad</script>Line two"],'file'=>['type'=>'file','value'=>['file'=>'/server/private']],'hidden'=>['type'=>'hidden','value'=>'nonce']],[]);
check(count($fields)===1&&str_contains($fields[0]['value'],"\n")&&!str_contains($fields[0]['value'],'<script>'),'Plain multiline text, no HTML or file-system paths');
echo json_encode(['passed'=>true,'checks'=>count($checks),'cleanup'=>true,'scope'=>'Synthetic PHP/SQL boundaries; native Bricks and browser acceptance remain separate'])."\n";
}
