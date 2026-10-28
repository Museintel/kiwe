<?php
declare(strict_types=1);
namespace DSA { final class Settings {
 public function get($group,$fallback=[]){return $GLOBALS['raw'][$group]??$fallback;}
 public function compare_and_update_selected($expected,$patch){if($GLOBALS['refuse']||$GLOBALS['raw']!==$expected)return false;$GLOBALS['writes']++;foreach($patch as $group=>$fields)$GLOBALS['raw'][$group]=array_replace($GLOBALS['raw'][$group]??[],$fields);if($GLOBALS['alter'])$GLOBALS['raw']['commerce']['fbt_title']='Unexpected';return true;}
} }
namespace {
define('ABSPATH',__DIR__.'/');define('DSA_OPTION_SETTINGS','dsa_settings');define('KIWE_REACT_WORKSPACE',true);
require __DIR__.'/fixtures/app-runtime-autoloader.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Commerce_App_Service.php';
class WP_Error {function __construct(public $code,public $message='',public $data=[]){} }
function is_wp_error($value){return $value instanceof WP_Error;}function WC(){return null;}function wc_get_product(...$args){throw new RuntimeException('Display policy must not load products');}
function current_user_can($cap){return in_array($cap,$GLOBALS['caps'],true);}function wp_salt($scheme){return 'synthetic-display-policy-key';}function get_option($key,$default=null){return $key===DSA_OPTION_SETTINGS?$GLOBALS['raw']:($key===DSA\Apps\Store_App_Service::OPTION?$GLOBALS['storeOn']:$default);}function sanitize_text_field($v){return trim(strip_tags($v));}function do_action(...$args){$GLOBALS['actions'][]=$args[0];}
$storeOn=true;$caps=['edit_products','kiwe_manage_context'];$writes=0;$refuse=false;$alter=false;$actions=[];$checks=0;
$raw=['commerce'=>['cart_badges_enabled'=>true,'stock_badge_alert_threshold'=>10,'stock_badge_urgent_threshold'=>2,'stock_badge_alert_text'=>'Low stock','stock_badge_urgent_text'=>'Very low stock','fbt_title'=>'Pairs well','fbt_max_products'=>6,'fbt_show_out_of_stock'=>false,'cross_sells_product_panel_enabled'=>true,'add_to_cart_mode'=>'default','first_cart_confetti_enabled'=>true,'cart_show_delivery'=>false,'cod_gate'=>['enabled'=>false]],'private'=>['key'=>'synthetic-private-value'],'future'=>['keep'=>[7,false]]];
function check($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
try{
 $service=new DSA\Access\Commerce_App_Service();$first=$service->display_policy_record();
 check(!is_wp_error($first)&&count($first['fields'])===11&&$writes===0&&!str_contains(json_encode($first),'synthetic-private-value'),'Read is allowlisted, write-free and excludes unrelated private data');
 $before=$raw;$result=$service->save_display_policy_record(['expected'=>$first['expected'],'changes'=>['fbt_title'=>'Try together','stock_badge_urgent_threshold'=>3]]);$wanted=$before;$wanted['commerce']['fbt_title']='Try together';$wanted['commerce']['stock_badge_urgent_threshold']=3;check(!is_wp_error($result)&&$raw===$wanted&&$writes===1,'One atomic save changes only the selected existing native controls');
 check(is_wp_error($service->save_display_policy_record(['expected'=>$first['expected'],'changes'=>['cart_badges_enabled'=>false]]))&&$raw===$wanted&&$writes===1,'A stale policy cannot replay over a newer change');
 foreach([['fbt_max_products'=>13],['stock_badge_urgent_threshold'=>11],['cart_badges_enabled'=>'false'],['native_api_key'=>'wrong-owner'],['add_to_cart_mode'=>'unknown'],['fbt_title'=>str_repeat('x',161)]]as$bad){$before=$raw;check(is_wp_error($service->save_display_policy_record(['expected'=>$service->display_policy_record()['expected'],'changes'=>$bad]))&&$raw===$before,'Invalid or unowned change cannot partially save');}
 $before=$raw;$refuse=true;check(is_wp_error($service->save_display_policy_record(['expected'=>$service->display_policy_record()['expected'],'changes'=>['fbt_title'=>'Refused']]))&&$raw===$before,'Native compare-and-save refusal preserves current settings');$refuse=false;
 $caps=['edit_products'];check($service->display_policy_record()['canEdit']===false&&is_wp_error($service->save_display_policy_record(['expected'=>'','changes'=>['fbt_title'=>'No']])),'Product staff can view but lack the context writer authority');
 $caps=[];check(is_wp_error($service->display_policy_record()),'Unprivileged role cannot read store configuration');
 $caps=['edit_products','kiwe_manage_context'];$storeOn=false;check(is_wp_error($service->display_policy_record())&&is_wp_error($service->save_display_policy_record(['expected'=>'','changes'=>['fbt_title'=>'No']])),'Inactive Store has no display-policy operation');
 echo json_encode(['passed'=>true,'cleanup'=>true,'checks'=>$checks,'nativeSqlExecuted'=>false,'productLoads'=>0]).PHP_EOL;
}catch(Throwable $error){echo json_encode(['passed'=>false,'cleanup'=>true,'checks'=>$checks,'error'=>$error->getMessage()]).PHP_EOL;exit(1);}
}
