<?php
declare(strict_types=1);
namespace DSA {class Settings {public function get($key,$default=[]){return $GLOBALS['raw'][$key]??$default;}}}
namespace {
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';define('DSA_OPTION_SETTINGS','dsa_settings');
class WP_Error {public function __construct(public $code,public $message='',public $data=[]){} }
function is_wp_error($v){return $v instanceof WP_Error;}function wc_get_product($id){throw new RuntimeException('Policy must not load products');}
function current_user_can($cap,...$args){return !empty($GLOBALS['caps'][$cap]);}function wp_salt($key){return 'fixture-secret';}
function get_option($key,$default=[]){return $GLOBALS['raw'];}
function update_option($key,$next,$autoload){$GLOBALS['writes']++;if($GLOBALS['refuse'])return false;$GLOBALS['raw']=$next;if($GLOBALS['alter'])$GLOBALS['raw']['commerce']['cart_show_fbt']=false;return true;}
$raw=['commerce'=>['upsell_banner_enabled'=>false,'cart_upsell_discounts_enabled'=>false,'cart_show_delivery'=>false,'cart_show_fbt'=>false,'bestseller_enabled'=>true,'cod_gate'=>['enabled'=>true,'strikes_to_block'=>2]],'private'=>['key'=>'fixture-only'],'custom'=>['untouched'=>7]];
$caps=['edit_products'=>true,'kiwe_manage_context'=>true];$writes=0;$refuse=false;$alter=false;$locked=false;$releases=0;
$wpdb=new class {public $prefix='fixture_';public function prepare($s,...$args){return $s;}public function get_var($s){if(str_contains($s,'GET_LOCK'))return $GLOBALS['locked']?0:1;$GLOBALS['releases']++;return 1;}};
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Commerce_App_Service.php';$service=new DSA\Access\Commerce_App_Service();$checks=0;
function check($ok,$name){if(!$ok)throw new RuntimeException($name);$GLOBALS['checks']++;echo "PASS $name\n";}
$r=$service->offer_policy_record();check($r['canEdit']&&count($r['switches'])===2&&!$r['switches']['cart_show_delivery']&&$writes===0&&!str_contains(json_encode($r),'fixture-only'),'Policy reads allowlisted values without secrets or writes');
$before=$raw;$input=['key'=>'cart_show_delivery','value'=>true,'acceptEffect'=>true,'expected'=>$r['expected']];$out=$service->save_offer_policy_record($input);$wanted=$before;$wanted['commerce']['cart_show_delivery']=true;
check(!is_wp_error($out)&&$raw===$wanted,'One switch changes while all other raw settings remain');
check(is_wp_error($service->save_offer_policy_record($input))&&$writes===1,'Old policy snapshot cannot replay');
$input['expected']=$out['expected'];check(!is_wp_error($service->save_offer_policy_record($input))&&$writes===1,'Unchanged switch avoids native write');
$raw['custom']['untouched']=8;check(is_wp_error($service->save_offer_policy_record($input))&&$writes===1,'Unrelated native settings edit invalidates policy snapshot');$raw['custom']['untouched']=7;
$input['key']='cart_show_fbt';
foreach([['key'=>'upsell_banner_enabled'],['key'=>'cart_upsell_discounts_enabled'],['key'=>'cod_gate'],['key'=>['upsell_banner_enabled']],['value'=>'true'],['acceptEffect'=>false],['expected'=>'bad'],['private'=>'injected']]as$bad)check(is_wp_error($service->save_offer_policy_record(array_replace($input,$bad)))&&$writes===1,'Unsupported or malformed write denied: '.array_key_first($bad));
$caps['kiwe_manage_context']=false;check(!$service->offer_policy_record()['canEdit']&&is_wp_error($service->save_offer_policy_record($input)),'Product-only staff can read but cannot set store-wide offers');$caps['kiwe_manage_context']=true;
$locked=true;check(is_wp_error($service->save_offer_policy_record($input))&&$writes===1,'Busy policy lock returns without writes');$locked=false;
$refuse=true;check(is_wp_error($service->save_offer_policy_record($input))&&!$raw['commerce']['cart_show_fbt'],'Refused option update fails readback');$refuse=false;
$alter=true;check(is_wp_error($service->save_offer_policy_record($input)),'Altered option write cannot report success');$alter=false;
$out=$service->save_offer_policy_record($input);check(!is_wp_error($out)&&$out['switches']['cart_show_fbt'],'FBT display reaches effective settings');
$before=$raw;$cartRead=$service->offer_policy_record();$cartInput=['key'=>'cart_show_delivery','value'=>false,'acceptEffect'=>true,'expected'=>$cartRead['expected']];$out=$service->save_offer_policy_record($cartInput);$wanted=$before;$wanted['commerce']['cart_show_delivery']=false;
check(!is_wp_error($out)&&$raw===$wanted&&!$out['switches']['cart_show_delivery'],'Delivery display changes one native setting and preserves offers, providers and commerce rules');
check(is_wp_error($service->save_offer_policy_record($cartInput)),'Stale delivery display change is refused');
$caps['kiwe_manage_context']=false;check(is_wp_error($service->save_offer_policy_record(array_replace($cartInput,['value'=>false,'expected'=>$out['expected']]))),'Product editor cannot change store delivery display');$caps['kiwe_manage_context']=true;
$out=$service->save_offer_policy_record(array_replace($cartInput,['value'=>false,'expected'=>$out['expected']]));check(!is_wp_error($out)&&!$out['switches']['cart_show_delivery'],'Client can restore default-off delivery display');
$caps['edit_products']=false;check(is_wp_error($service->offer_policy_record()),'Non-commerce role cannot read policy');$caps['edit_products']=true;
$raw['commerce']='broken';check(is_wp_error($service->offer_policy_record()),'Malformed existing settings require review');
echo "$checks offer-policy checks passed.\n";
}
