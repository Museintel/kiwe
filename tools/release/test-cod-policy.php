<?php
/** Behavioural boundaries around the native COD policy; no checkout/payment transport. */
namespace DSA { class Settings { public function get($key,$fallback=[]){ return $GLOBALS['settings'][$key]??$fallback; } } }
namespace {
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php'; define('MINUTE_IN_SECONDS',60);
class WP_Error { public array $errors=[]; public function add($code,$message){$this->errors[$code]=$message;} }
class WC_Order {
 public array $meta=[]; public array $notes=[]; public int $saves=0;
 public function __construct(public int $id,public string $phone,public string $method='cod',public string $status='completed'){}
 public function get_id(){return $this->id;} public function get_billing_phone(){return $this->phone;}
 public function get_payment_method(){return $this->method;} public function get_customer_id(){return 1;}
 public function get_status(){return $this->status;} public function get_date_paid(){return $this->method==='card'?123:null;}
 public function get_meta($key,$single=true){return $this->meta[$key]??'';}
 public function update_meta_data($key,$value){$this->meta[$key]=$value;}
 public function delete_meta_data($key){unset($this->meta[$key]);}
 public function add_order_note($note){$this->notes[]=$note;} public function save(){++$this->saves;return $this->id;}
}
function WC(){return (object)['customer'=>new class {function get_billing_phone(){return '+919999999901';}}];}
function wc_get_order($id){return $GLOBALS['orders'][$id]??null;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_unslash($v){return $v;} function __($v,$d=''){return $v;}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function update_user_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;return true;}
function get_current_user_id(){return 1;} function wp_salt($kind){return 'fake-fixture-salt';}
function pk_hmac($v){return hash_hmac('sha256',$v,wp_salt('auth'));}
function pk_normalize_phone($v){return preg_replace('/[^0-9+]/','',$v);}
function pk_factor($id,$type,$anchor=''){return ['status'=>'verified'];}
function pk_phone_provider_ready(){return true;}
function get_transient($key){return $GLOBALS['tokens'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['tokens'][$key]=$value;return true;}
function delete_transient($key){unset($GLOBALS['tokens'][$key]);return true;}
function add_filter(...$args){$GLOBALS['hooks'][$args[0]]=$args[1];}
function add_action(...$args){$GLOBALS['hooks'][$args[0]]=$args[1];}
class Fixture_DB {public string $prefix='qa_';public function prepare($q,...$a){return $q;}public function get_var($q){return 1;} }
$wpdb=new Fixture_DB();
$settings=['commerce'=>['cod_gate'=>['enabled'=>true,'strikes_to_block'=>1,'regain'=>'prepaid_success']]];
$options=['dsa_cod_reputation'=>[pk_hmac('+919999999902')=>['strikes'=>1,'completed'=>0]]];$orders=[];$tokens=[];$hooks=[];
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/COD_Gate_Service.php';
$service=new \DSA\Commerce\COD_Gate_Service(new \DSA\Settings());$checks=0;
function check($condition,$label){if(!$condition){fwrite(STDERR,"FAIL $label\n");exit(1);}++$GLOBALS['checks'];}
$errors=new WP_Error();$_POST=[];
$service->validate_classic_checkout(['payment_method'=>'cod','billing_phone'=>'+919999999902'],$errors);
check(isset($errors->errors['dsa_cod_verify']),'Blocked final submitted phone cannot borrow the old Woo customer phone or a verified factor');
$service->register();check(!isset($hooks['woocommerce_store_api_validate_checkout']),'Store API uses the native prepayment order hook');
$blocked=new WC_Order(1,'+919999999902');$thrown=false;try{$service->flag_unverified_store_api_order($blocked);}catch(\Throwable $e){$thrown=true;}
check($thrown&&$blocked->saves===0,'Store API refuses blocked final native order before flagging or payment');
$allowed=new WC_Order(2,'+919999999903');$service->flag_unverified_store_api_order($allowed);$service->flag_unverified_store_api_order($allowed);
check(($allowed->meta['_dsa_cod_verified']??'')==='yes'&&count($allowed->notes)===0,'Repeated order hook preserves verified classification');
$orders[2]=$allowed;$service->record_completed(2);$first=$options['dsa_cod_reputation'];$service->record_completed(2);
check($first===$options['dsa_cod_reputation']&&$service->reputation(pk_hmac($allowed->phone))['completed']===1,'Repeated completed event counts only once');
$cancelled=new WC_Order(3,'+919999999904','cod','cancelled');$orders[3]=$cancelled;$service->record_cancelled(3);$first=$options['dsa_cod_reputation'];$service->record_cancelled(3);
check($first===$options['dsa_cod_reputation']&&$service->reputation(pk_hmac($cancelled->phone))['strikes']===1,'Repeated cancellation does not stack strikes');
$unpaid=new WC_Order(4,'+919999999904','bacs');$orders[4]=$unpaid;$service->record_completed(4);
check($service->reputation(pk_hmac($unpaid->phone))['strikes']===1,'Unpaid non-COD completion cannot regain prepaid privileges');
$paid=new WC_Order(5,'+919999999904','card');$orders[5]=$paid;$service->record_completed(5);
check($service->reputation(pk_hmac($paid->phone))['strikes']===0,'Native paid non-COD completion can regain one strike');
$settings['commerce']['cod_gate']['enabled']=false;$first=$options;$orders[6]=new WC_Order(6,'+919999999905','cod','cancelled');$service->record_cancelled(6);$service->record_completed(6);
check($first===$options&&$orders[6]->saves===0,'Disabled policy performs no reputation or order writes');
echo json_encode(['passed'=>true,'checks'=>$checks])."\n";
}
