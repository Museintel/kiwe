<?php
require __DIR__.'/test-workflow-apps.php';
if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Shipping_Workspace_Service.php';
class WC_Shipping_Flat_Rate {public $id='flat_rate';public function __construct(public int $instance_id){}public $instance_settings=[];public function get_instance_option_key(){return 'woocommerce_'.$this->id.'_'.$this->instance_id.'_settings';}public function init_instance_settings(){$this->instance_settings=get_option($this->get_instance_option_key(),[]);}public function get_instance_form_fields(){return array_fill_keys(array_merge(['title','cost','tax_status','requires','min_amount','ignore_discounts','increment_weight','type','no_class_cost'],array_map(static fn($r)=>'class_cost_'.$r['term_id'],$GLOBALS['shippingClasses']??[])),['type'=>'text']);}}
class WC_Shipping_Free_Shipping extends WC_Shipping_Flat_Rate {public $id='free_shipping';}
class WC_Shipping_Local_Pickup extends WC_Shipping_Flat_Rate {public $id='local_pickup';}
class Custom_Flat_Rate extends WC_Shipping_Flat_Rate {}
class WC_Shipping_Zones {public static function get_shipping_method($id){$class=['flat_rate'=>'WC_Shipping_Flat_Rate','free_shipping'=>'WC_Shipping_Free_Shipping','local_pickup'=>'WC_Shipping_Local_Pickup'][$GLOBALS['shippingMethods'][$id]['method_id']]??'WC_Shipping_Flat_Rate';return empty($GLOBALS['customHandler'])?new $class($id):new Custom_Flat_Rate($id);}}
class WC_Cache_Helper {public static function invalidate_cache_group($k){$GLOBALS['invalidated'][]=$k;}public static function get_transient_version($k,$refresh){$GLOBALS['invalidated'][]=$k;}}
class WC_REST_Shipping_Zone_Methods_V2_Controller {public function update_fields($id,$method,$request){$GLOBALS['nativeWrites']++;if(isset($request->params['settings'])){if(empty($GLOBALS['refuseShipping'])){$method->init_instance_settings();$next=array_replace($method->instance_settings,$request->params['settings']);if(!empty($GLOBALS['alterRate']))$next['class_cost_99']='changed';update_option($method->get_instance_option_key(),$next);}return $method;}if(empty($GLOBALS['refuseShipping']))$GLOBALS['shippingMethods'][$id]['is_enabled']=$request->params['enabled']?1:0;if(!empty($GLOBALS['alterShipping']))$GLOBALS['shippingMethods'][$id]['method_order']=99;return $method;}}
$actor=3;$caps[3]=array_merge($caps[3],['kiwe_manage_context']);$zones=[];for($i=1;$i<=22;$i++)$zones[]=['zone_id'=>$i,'zone_name'=>'Zone '.$i,'zone_order'=>$i];
$shippingMethods=[31=>['zone_id'=>21,'instance_id'=>31,'method_id'=>'flat_rate','method_order'=>1,'is_enabled'=>1],32=>['zone_id'=>21,'instance_id'=>32,'method_id'=>'custom_courier','method_order'=>2,'is_enabled'=>1]];$nativeWrites=0;$invalidated=[];
$wpdb=new class {public $prefix='wp_';public $last_error='';public $args=[];public function prepare($s,...$args){$this->args=$args;return $s;}public function get_var($s){return str_contains($s,'GET_LOCK')&&!empty($GLOBALS['shippingLocked'])?'0':'1';}public $snapshot=[];public function query($s){$GLOBALS['transactionQueries'][]=$s;if(!empty($GLOBALS['transactionUnavailable']))return false;if($s==='START TRANSACTION')foreach(['zones','zoneLocations','shippingMethods','opts']as$k)$this->snapshot[$k]=$GLOBALS[$k]??[];if($s==='ROLLBACK')foreach($this->snapshot as$k=>$v)$GLOBALS[$k]=$v;return 0;}public function get_results($s,$mode){
 if(str_contains($s,'product_shipping_class'))return array_slice($GLOBALS['shippingClasses']??[],0,201);
 if(str_contains($s,'information_schema.TABLES'))return array_map(static fn($v)=>['TABLE_NAME'=>$v,'ENGINE'=>empty($GLOBALS['nonTransactional'])?'InnoDB':'MyISAM'],$this->args);
 if(str_contains($s,'ORDER BY zone_id ASC LIMIT 201'))return array_slice($GLOBALS['zones'],0,201);

 if(!empty($GLOBALS['shippingReadError'])){$this->last_error='fixture';return null;}$this->last_error='';
 if(str_contains($s,'woocommerce_shipping_zone_methods')){$rows=array_values(array_filter($GLOBALS['shippingMethods'],fn($r)=>$r['zone_id']===$this->args[0]));if(str_contains($s,'instance_id ='))return array_values(array_filter($rows,fn($r)=>$r['instance_id']===$this->args[1]));return array_slice($rows,$this->args[1]??0,str_contains($s,'LIMIT 101')?101:21);}
 if(str_contains($s,'woocommerce_shipping_zone_locations')){if(isset($GLOBALS['zoneLocations']))return array_slice($GLOBALS['zoneLocations'],$this->args[1]??0,str_contains($s,'LIMIT 201')?201:21);return array_slice(array_map(static fn($i)=>['location_id'=>$i,'location_type'=>'postcode','location_code'=>'100'.$i],range(1,22)),$this->args[1]??0,str_contains($s,'LIMIT 201')?201:21);}
 if(str_contains($s,'WHERE zone_id'))return array_values(array_filter($GLOBALS['zones'],fn($r)=>$r['zone_id']===$this->args[0]));return array_slice($GLOBALS['zones'],$this->args[0],21);
}};
$shipping=new DSA\Access\Shipping_Workspace_Service();
$list=$shipping->zones();check(count($list['items'])===20&&$list['hasNext']&&$list['canEdit']&&$nativeWrites===0,'Shipping list uses bounded rows without native mutations');
$last=$shipping->zones(['page'=>2]);check(count($last['items'])===2&&!$last['hasNext'],'Zone page two exposes remaining zones');
$fallback=$shipping->detail(0);check($fallback['id']===0&&$fallback['name']==='Rest of the world','Native fallback zone zero supported');
$detail=$shipping->detail(21);check(count($detail['items'])===2&&$detail['items'][0]['canEdit']&&!$detail['items'][1]['canEdit'],'Core availability exposed while extensions remain read-only');
$locations=$shipping->detail(21,['view'=>'locations','page'=>2]);check(count($locations['items'])===2&&!$locations['hasNext'],'Region rules page beyond twenty');
check(is_wp_error($shipping->detail(999))&&is_wp_error($shipping->method(22,31)),'Missing zone and cross-zone method denied');
$m=$shipping->method(21,31);$body=['enabled'=>false,'acceptEffect'=>true,'expected'=>$m['expected']];
// Base WP_REST_Request stores query params; add set_param in the shared fixture.
$saved=$shipping->save(21,31,$body);check(is_array($saved)&&!$saved['enabled']&&$nativeWrites===1&&$shippingMethods[31]['method_order']===1,'Client toggles only native method availability');
check($invalidated===['shipping_zones','shipping'],'Native zone and shipping caches invalidated');
check(is_wp_error($shipping->save(21,31,$body)),'Old shipping snapshot cannot replay');$body['expected']=$saved['expected'];$n=$nativeWrites;check(is_array($shipping->save(21,31,$body))&&$nativeWrites===$n,'No-op availability avoids native write');
foreach([['enabled'=>'no'],['acceptEffect'=>false],['settings'=>['cost'=>'0']],['expected'=>'bad']]as$bad)check(is_wp_error($shipping->save(21,31,array_replace($body,$bad))),'Malformed or injected delivery change refused');
$shippingLocked=true;check(is_wp_error($shipping->save(21,31,$body)),'Busy delivery lock returns promptly');$shippingLocked=false;
$body['enabled']=true;$refuseShipping=true;check(is_wp_error($shipping->save(21,31,$body)),'Refused native toggle cannot report success');$refuseShipping=false;
$alterShipping=true;check(is_wp_error($shipping->save(21,31,$body)),'Changed native method order fails preservation check');$alterShipping=false;
$customHandler=true;$m=$shipping->method(21,31);check(is_wp_error($shipping->save(21,31,array_replace($body,['expected'=>$m['expected']]))),'Custom core handler remains protected');$customHandler=false;
check(is_wp_error($shipping->method(21,32)),'Extension method settings never projected');
$caps[3]=array_diff($caps[3],['kiwe_manage_context']);check(!$shipping->zones()['canEdit']&&is_wp_error($shipping->save(21,31,$body)),'Product staff can review but cannot toggle store shipping');
$actor=2;check(is_wp_error($shipping->zones()),'Editorial role denied delivery setup');$actor=3;
$shippingReadError=true;check(is_wp_error($shipping->zones()),'Database failure is not an empty shipping catalog');
echo "Shipping workspace checks passed.\n";
