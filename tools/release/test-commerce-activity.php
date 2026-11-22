<?php
declare(strict_types=1);
namespace DSA\Apps { final class Store_App_Service { public static function enabled(): bool { return $GLOBALS['storeOn']; } } }
namespace Automattic\WooCommerce\Utilities { final class OrderUtil { public static function custom_orders_table_usage_is_enabled(): bool { return $GLOBALS['hpos']; } } }
namespace Automattic\WooCommerce\Internal\DataStores\Orders { final class OrdersTableDataStore { public static function get_orders_table_name(): string { return 'fixture_wc_orders'; } } }
namespace {
 define('ABSPATH',__DIR__.'/');
 $checks=0;$storeOn=true;$hpos=true;$allowed=true;$hooks=[];$where='';$throwUsers=false;$captured=[];
 function check($ok,$why){$GLOBALS['checks']++;if(!$ok)throw new \RuntimeException($why);}
 function current_user_can($cap){return $GLOBALS['allowed'];}function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',(string)$v);}
 function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$name][]=$callback;}function remove_action($name,$callback,$priority=10){$GLOBALS['hooks'][$name]=array_values(array_filter($GLOBALS['hooks'][$name]??[],fn($v)=>$v!==$callback));}
 function wc_get_is_paid_statuses(){return ['processing','completed'];}
 function get_users($args){$GLOBALS['captured']=$args;$query=(object)['query_vars'=>$args,'query_where'=>' WHERE 1=1'];foreach($GLOBALS['hooks']['pre_user_query']??[]as$fn)$fn($query);$GLOBALS['where']=$query->query_where;if($GLOBALS['throwUsers'])throw new \RuntimeException('Native query failed');return [(object)['ID'=>9,'roles'=>['subscriber']]];}
 $wpdb=new class {public string $users='fixture_users',$posts='fixture_posts',$postmeta='fixture_postmeta';public function prepare($sql,$args){foreach($args as$arg)$sql=preg_replace('/%s/',"'".addslashes($arg)."'",$sql,1);return $sql;}};
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/Paid_Customer_Query.php';
 $query=\DSA\Commerce\Paid_Customer_Query::users(['number'=>13,'offset'=>12,'count_total'=>false]);
 check(count($query)===1&&$query[0]->roles===['subscriber'],'A paid Subscriber is not excluded by a customer-role assumption');
 check(str_contains($where,'fixture_wc_orders')&&str_contains($where,'customer_id=fixture_users.ID')&&str_contains($where,"type='shop_order'")&&str_contains($where,"'wc-processing','wc-completed'"),'HPOS buyers use native paid statuses and account ID');
 check(!str_contains($where,'email')&&!isset($captured['role__in'])&&$captured['number']===13&&$captured['offset']===12,'No typed-email ownership inference, all-account scan or post-pagination filtering');
 check($captured['cache_results']===false&&empty($hooks['pre_user_query']),'Order changes cannot leave a cached buyer classification or global query filter');
 $hpos=false;\DSA\Commerce\Paid_Customer_Query::users(['number'=>13]);check(str_contains($where,'fixture_posts')&&str_contains($where,"meta_key='_customer_user'")&&!str_contains($where,'wc_orders'),'Legacy storage uses the authoritative native customer relation');
 $throwUsers=true;try{\DSA\Commerce\Paid_Customer_Query::users(['number'=>13]);}catch(\RuntimeException $e){}check(empty($hooks['pre_user_query']),'Native-query failure removes the scoped restriction');$throwUsers=false;
 $allowed=false;check(\DSA\Commerce\Paid_Customer_Query::users([])===[],'Buyer directory denies a lower role');$allowed=true;$storeOn=false;check(\DSA\Commerce\Paid_Customer_Query::users([])===[],'Store-off directory stays inert');$storeOn=true;

 $admin=false;$ajax=false;$payPage=true;$orderId=51;$payMethod='razorpay';$key='synthetic-order-key';$dequeued=[];$enqueued=[];$done=[];
 class WC_Order {public function get_payment_method(){return $GLOBALS['payMethod'];}public function get_order_key(){return 'synthetic-order-key';}}
 function is_admin(){return $GLOBALS['admin'];}function wp_doing_ajax(){return $GLOBALS['ajax'];}function is_checkout_pay_page(){return $GLOBALS['payPage'];}function get_query_var($v){return $GLOBALS['orderId'];}function absint($v){return abs((int)$v);}function wc_get_order($id){return $id===51?new WC_Order():false;}function wc_clean($v){return trim($v);}function wp_unslash($v){return $v;}
 function wp_scripts(){return $GLOBALS['scripts'];}function wp_script_is($handle,$state){return $state==='done'?in_array($handle,$GLOBALS['done'],true):in_array($handle,$GLOBALS['enqueued'],true);}function wp_dequeue_script($handle){$GLOBALS['dequeued'][]=$handle;$GLOBALS['enqueued']=array_values(array_diff($GLOBALS['enqueued'],[$handle]));}function wp_enqueue_script($handle){$GLOBALS['enqueued'][]=$handle;}
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/Gateway_Asset_Service.php';
 $reset=static function(){$_GET=['key'=>'synthetic-order-key'];$GLOBALS['enqueued']=['razorpay-checkout-js'];$GLOBALS['dequeued']=[];$GLOBALS['done']=[];$GLOBALS['scripts']=(object)['registered'=>['razorpay-checkout-js'=>(object)['src'=>'https://checkout.razorpay.com/v1/checkout.js','deps'=>[]]]];return new \DSA\Commerce\Gateway_Asset_Service();};
 foreach(['admin','ajax']as$flag){$s=$reset();$GLOBALS[$flag]=true;$s->prepare_receipt();check(!$dequeued,'Native gateway bypass for '.$flag);$GLOBALS[$flag]=false;}
 $s=$reset();$payPage=false;$s->prepare_receipt();check(!$dequeued,'Ordinary storefront and checkout remain unchanged');$payPage=true;
 $s=$reset();$_GET['pay_for_order']='true';$s->prepare_receipt();check(!$dequeued,'Pay-existing form keeps its original assets');
 $s=$reset();$_GET['key']='wrong-key';$s->prepare_receipt();check(!$dequeued,'Invalid receipt key cannot alter gateway assets');
 $s=$reset();unset($_GET['key']);$s->prepare_receipt();check(!$dequeued,'Missing receipt authority leaves the original assets untouched');
 $s=$reset();$payMethod='other';$s->prepare_receipt();check(!$dequeued,'Other payment gateways remain unchanged');$payMethod='razorpay';
 $s=$reset();$scripts->registered['extension']=(object)['src'=>'extension.js','deps'=>['razorpay-checkout-js']];$s->prepare_receipt();check(!$dequeued,'Another extension declared SDK dependency wins');
 $s=$reset();$scripts->registered['razorpay-checkout-js']->src='https://other.example.test/custom.js';$s->prepare_receipt();check(!$dequeued,'Unknown provider asset stays unchanged');
 $s=$reset();$s->prepare_receipt();check($dequeued===['razorpay-checkout-js'],'Validated native receipt omits one duplicate head SDK');
 $scripts->registered['razorpay_checkout']=(object)['src'=>'https://checkout.razorpay.com/v1/checkout.js','deps'=>[]];$scripts->registered['razorpay_wc_script']=(object)['src'=>'native-script.js','deps'=>['razorpay_checkout']];$enqueued[]='razorpay_wc_script';$s->preserve_fallback();check(!in_array('razorpay-checkout-js',$enqueued,true),'Original gateway SDK/consumer dependency chain is retained once');
 $s=$reset();$s->prepare_receipt();$s->preserve_fallback();check(in_array('razorpay-checkout-js',$enqueued,true),'Unknown or hosted gateway flow restores its original SDK');
 $s=$reset();$done=['razorpay-checkout-js'];$s->prepare_receipt();check(!$dequeued,'Already printed assets are never altered');
 $storeOn=false;$hooks=[];(new \DSA\Commerce\Gateway_Asset_Service())->register();check(!$hooks,'Store-off and non-Woo have no new asset hooks');
 echo json_encode(['passed'=>true,'checks'=>$checks,'scope'=>'isolated native boundary primitives; real SQL and browser qualification separate'])."\n";
}
