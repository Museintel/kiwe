<?php
declare(strict_types=1);
namespace DSA { class Settings {} }
namespace DSA\Commerce {class Abandoned_Cart_Service {} class COD_Gate_Service {function __construct($s){}function config(){return ['enabled'=>false];}}}
namespace DSA\Access {class Task_Workspace_UI {static function text($s,$n){return $s;}}}
namespace {
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';define('DSA_OPTION_SETTINGS','dsa_settings');
class WP_Error {function __construct(public $code,public $message='',public $data=[]){} } class WC_Order {}
function WC(){}function is_wp_error($v){return $v instanceof WP_Error;}
$options=['dsa_settings'=>['private'=>'synthetic only','commerce'=>['untouched'=>7]],'woocommerce_cod_settings'=>['enabled'=>'no'],'woocommerce_enable_guest_checkout'=>'yes'];
$caps=['edit_products'=>true,'kiwe_manage_context'=>true,'manage_woocommerce'=>true];$logged=false;$verified=false;$special=false;$writes=0;$busy=false;$refuse=false;$checks=0;
function get_option($k,$d=null){return $GLOBALS['options'][$k]??$d;}function update_option($k,$v){++$GLOBALS['writes'];if(!$GLOBALS['refuse'])$GLOBALS['options'][$k]=$v;return !$GLOBALS['refuse'];}
function current_user_can($c){return !empty($GLOBALS['caps'][$c]);}function wp_salt($s){return 'synthetic';}
function apply_filters($k,$d,...$a){return $k==='dsa_phonekey_purchase_verified_contact_enabled'?$GLOBALS['special']:($k==='dsa_phonekey_account_identity_verified'?$GLOBALS['verified']:$d);}
function is_user_logged_in(){return $GLOBALS['logged'];}function get_current_user_id(){return 5;}function __($s,...$a){return $s;}
$wpdb=new class {public $prefix='fixture_';function prepare($s,...$a){return $s;}function get_var($s){return str_contains($s,'GET_LOCK')&&$GLOBALS['busy']?0:1;}};
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/Purchase_Identity_Gate.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Checkout_Policy_Workspace_Service.php';
function check($ok,$name){if(!$ok)throw new RuntimeException($name);++$GLOBALS['checks'];}
$gate=new DSA\Commerce\Purchase_Identity_Gate();$service=new DSA\Access\Checkout_Policy_Workspace_Service(new DSA\Commerce\Abandoned_Cart_Service());
check(!$gate->enabled()&&$gate->state()['allowed'],'Native guest choice allows anonymous purchase by default');
$read=$service->policy();check($read['guestCheckout']&&$read['canEditGuestCheckout']&&$writes===0&&!str_contains(json_encode($read),'synthetic only'),'Allowlisted read does not write or disclose settings');
$original=$options;$input=['operation'=>'guestCheckout','enabled'=>false,'expected'=>$read['expected']];$out=$service->change_guest_checkout($input);$wanted=$original;$wanted['woocommerce_enable_guest_checkout']='no';
check(!is_wp_error($out)&&$options===$wanted&&$writes===1,'Only the native guest option changes');
check($gate->enabled()&&$gate->state()['state']==='signup_required','Required accounts remain enforced');
$logged=true;check($gate->state()['state']==='verification_required','Required account retains verification');$verified=true;check($gate->state()['allowed'],'Verified customer proceeds');$logged=false;
check(is_wp_error($service->change_guest_checkout($input))&&$writes===1,'Stale policy cannot replay');
$input['expected']=$out['expected'];check(!is_wp_error($service->change_guest_checkout($input))&&$writes===1,'Unchanged value avoids writes');
foreach([['enabled'=>'true'],['operation'=>'other'],['expected'=>'stale'],['extra'=>true]]as$bad)check(is_wp_error($service->change_guest_checkout(array_replace($input,$bad)))&&$writes===1,'Malformed or stale save denied');
$caps['manage_woocommerce']=false;check(is_wp_error($service->change_guest_checkout($input))&&!$service->policy()['canEditGuestCheckout'],'Native Woo authority required');$caps['manage_woocommerce']=true;
$caps['kiwe_manage_context']=false;check(is_wp_error($service->change_guest_checkout($input)),'Store-wide Kiwe authority required');$caps['kiwe_manage_context']=true;
$busy=true;check(is_wp_error($service->change_guest_checkout($input))&&$writes===1,'Busy save does not write');$busy=false;
$input['enabled']=true;$refuse=true;check(is_wp_error($service->change_guest_checkout($input)),'Refused write cannot report success');$refuse=false;
$out=$service->change_guest_checkout($input);check(!is_wp_error($out)&&$out['guestCheckout']&&$gate->state()['allowed'],'Native choice re-enables actual purchase state');
$special=true;check($gate->enabled()&&$gate->state()['state']==='signup_required','Explicit specialist integration remains authoritative');$special=false;
$options['woocommerce_enable_guest_checkout']='broken';check(is_wp_error($service->policy()),'Malformed native option needs review');
echo json_encode(['passed'=>true,'checks'=>$checks])."\n";
}
