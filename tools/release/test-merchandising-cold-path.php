<?php
namespace DSA { class Settings { public function get($key,$default=null){return ['linked_products_enabled'=>false,'co_purchase_daily_sync_enabled'=>false,'bestseller_enabled'=>false];} } }
namespace { define('ABSPATH',__DIR__.'/');class WP_Error{public function __construct(public string $code,public string $message,public array $data=[]){}}$cache=[];$writes=0;$queries=0;function get_transient($key){global$cache;return $cache[$key]??false;}function set_transient(...$args){global$writes;$writes++;throw new RuntimeException('Visitor cache write forbidden');}function current_user_can(...$args){throw new RuntimeException('Non-Woo path must short-circuit');}function absint($x){return abs((int)$x);}
 function is_wp_error($value){return $value instanceof WP_Error;} class NoVisitorQuery{public function __call($name,$args){global$queries;$queries++;throw new RuntimeException('Visitor query forbidden');}}$wpdb=new NoVisitorQuery();
 require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Commerce/Linked_Products_Service.php';require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Product_Merchandising_Workspace_Service.php';
 $checks=[];$check=static function($ok,$label)use(&$checks){$checks[]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
 $owner=new DSA\Commerce\Linked_Products_Service(new DSA\Settings());$rank=new ReflectionMethod($owner,'bestseller_product_ids');$co=new ReflectionMethod($owner,'co_purchased_ids_for_product');
 foreach(['week','month','year']as$period)$check($rank->invoke($owner,$period,4)===[],'Cold '.$period.' visitor ranking omitted');
 $check($co->invoke($owner,123,4)===[],'Cold visitor co-purchase omitted');
 $cache['dsa_bestsellers_month']=['source'=>'woo-order-period-v1','limit'=>20,'ids'=>range(1,20)];$check($rank->invoke($owner,'month',4)===[1,2,3,4],'Prepared larger rank cache slices cheaply');$check($rank->invoke($owner,'month',24)===[],'Insufficient prepared cache never calculates');
 $cache['dsa_cop_123']=range(1,20);$check($co->invoke($owner,123,4)===[1,2,3,4],'Prepared co-purchase cache slices');
 $check($owner->sync_co_purchase_upsells()['products']===0,'Disabled scheduled co-purchase does no work');$owner->sync_bestseller_terms();$check($queries===0&&$writes===0,'Cold/disabled paths issue zero order queries and writes');
 $merch=new DSA\Access\Product_Merchandising_Workspace_Service();$check($merch->review(12) instanceof WP_Error,'Non-Woo read short-circuits');$check($merch->choices(12,[]) instanceof WP_Error,'Non-Woo choices short-circuit');$check($merch->change(12,['field'=>'upsells','ids'=>[],'expected'=>str_repeat('a',64),'confirmed'=>true]) instanceof WP_Error,'Non-Woo mutation short-circuits');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'orderQueries'=>$queries,'writes'=>$writes])."\n";
}
