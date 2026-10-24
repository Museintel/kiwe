<?php
/** Exact private native fixture. Never run against live orders or outside its isolated prefix. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
use DSA\Access\Order_Workspace_Service as Orders;
use DSA\Access\Site_Owner_Service as Owner;
use Automattic\WooCommerce\Utilities\OrderUtil;
$proof=['schema'=>'kiwe.native-order-operations.v1','version'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'woocommerce'=>WC_VERSION,'storage'=>KIWE_ORDER_TEST_STORAGE,'checks'=>[],'cleanup'=>false];
$users=[];$orderIds=[];$productIds=[];$filters=[];$http=[];$mail=[];$actor=get_current_user_id();$prefix='kiweorderqa_'.bin2hex(random_bytes(5));$orders=new Orders();
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$filter=static function($hook,$cb,$argc=1)use(&$filters){add_filter($hook,$cb,PHP_INT_MAX,$argc);$filters[]=[$hook,$cb];};
$request=static function($method,$id,$body=[],$suffix=''){$r=new WP_REST_Request($method,'/dsa/v1/workspace/commerce/orders/'.$id.$suffix);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_header('X-Kiwe-Mutation','1');$r->set_header('Content-Type','application/json');if($method==='POST')$r->set_body(wp_json_encode($body));return rest_do_request($r);};
$act=static function($id,$operation,$patch=[])use($orders,$request){$review=$orders->review($id);if(is_wp_error($review))throw new RuntimeException($review->get_error_code());return $request('POST',$id,array_replace(['operation'=>$operation,'expected'=>$review['expected'],'confirmed'=>true],$patch),'/actions');};
$make=static function($method='bacs',$status='pending')use(&$orderIds,&$productIds,$prefix){$p=new WC_Product_Simple();$p->set_name($prefix.' stock');$p->set_status('publish');$p->set_regular_price('20');$p->set_manage_stock(true);$p->set_stock_quantity(10);$p->save();$productIds[]=$p->get_id();$o=wc_create_order();if(is_wp_error($o))throw new RuntimeException('Synthetic native order');$orderIds[]=$o->get_id();$o->set_payment_method($method);$o->set_payment_method_title('Synthetic '.$method);$o->set_address(['first_name'=>'Synthetic','last_name'=>'Customer','email'=>'synthetic@example.invalid','phone'=>'+15555550101','address_1'=>'Example street','country'=>'US'],'billing');$o->set_customer_note('Synthetic delivery instruction');$o->set_currency('INR');$o->add_product($p,2);$o->add_meta_data('_synthetic_vendor_order','preserve');$o->calculate_totals(false);$o->save();if($status!=='pending')$o->update_status($status);return[$o->get_id(),$p->get_id()];};
try{
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'complete exact installed candidate');
 $assert(Orders::available()&&!wp_using_ext_object_cache(),'native order owners and process-local cache');
 $assert(OrderUtil::custom_orders_table_usage_is_enabled()===(KIWE_ORDER_TEST_STORAGE==='hpos'),'declared native order storage active');
 $filter('pre_http_request',static function()use(&$http){$http[]=true;return new WP_Error('qa_closed','No outbound test work');},3);
 $filter('pre_wp_mail',static function()use(&$mail){$mail[]=array_map(static fn($x)=>($x['class']??'').($x['type']??'').$x['function'],debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,8));return true;},2);
 foreach(WC()->mailer()->get_emails()as$email)$filter('woocommerce_email_enabled_'.$email->id,static fn()=>false,3);
 foreach(['customer_partially_refunded_order','customer_fully_refunded_order']as$emailId)$filter('woocommerce_email_enabled_'.$emailId,static fn()=>false,3);
 $filter('pre_option_'.Owner::OPTION,static function()use(&$users){return$users['owner']??0;});
 foreach(['owner'=>'administrator','client'=>'administrator','manager'=>'shop_manager','editor'=>'editor','author'=>'author','subscriber'=>'subscriber','customer'=>'customer']as$key=>$role){$id=wp_insert_user(['user_login'=>$prefix.'_'.$key,'user_pass'=>wp_generate_password(30),'user_email'=>$prefix.'_'.$key.'@example.invalid','role'=>$role]);$assert(!is_wp_error($id),'native synthetic actor '.$key);$users[$key]=(int)$id;}
 wp_set_current_user($users['client']);
 foreach(['cod','bacs','cheque']as$id){$g=WC()->payment_gateways()->payment_gateways()[$id];$g->enabled='yes';}
 update_option('woocommerce_manage_stock','yes');
 [$id,$product]=$make();$proof['fixtureOrderId']=$id;
 $invoice=wc_get_order($id);$shipping=new WC_Order_Item_Shipping();$shipping->set_method_title('Synthetic delivery');$shipping->set_method_id('flat_rate');$shipping->set_total('3');$invoice->add_item($shipping);$fee=new WC_Order_Item_Fee();$fee->set_name('Synthetic handling');$fee->set_tax_status('none');$fee->set_total('2');$invoice->add_item($fee);$coupon=new WC_Order_Item_Coupon();$coupon->set_code('synthetic-receipt');$coupon->set_discount('1');$invoice->add_item($coupon);$invoice->calculate_totals(false);$invoice->save();
 $read=$request('GET',$id);$row=$read->get_data();$direct=$orders->review($id);$proof['initialReview']=['httpStatus'=>$read->get_status(),'code'=>$row['code']??null,'message'=>$row['message']??null,'projected'=>array_intersect_key($row,array_flip(['status','canHold','canCancel','canRecordPayment','paymentOwner','paidAt','transactionId','total'])),'directError'=>is_wp_error($direct)?$direct->get_error_code():null,'nativeClass'=>get_class(wc_get_order($id)),'nativeCapabilities'=>[current_user_can('edit_shop_orders'),current_user_can('edit_others_shop_orders'),current_user_can('edit_shop_order',$id)]];$assert($read->get_status()===200&&$row['canHold']&&$row['canCancel']&&$row['canRecordPayment']&&$row['paymentOwner']==='offline','native unpaid offline order review');
 $assert($read->get_headers()['Cache-Control']==='no-store, private'&&$row['billing']['email']==='synthetic@example.invalid','private bounded order projection');
 $assert(count($row['charges'])===3&&$row['shippingMethods']===['Synthetic delivery'],'native shipping fee and coupon values share bounded invoice review');
 $assert(str_contains($row['paymentUrl'],'order-pay')&&!str_contains(wp_json_encode($row),'order_key'),'native payment page private link without separate key field');
 $r=new WP_REST_Request('GET','/dsa/v1/workspace/commerce/orders/'.$id);$assert(rest_do_request($r)->get_status()===403,'order read requires native nonce');
 foreach(['manager'=>200,'editor'=>403,'author'=>403,'subscriber'=>403,'customer'=>403]as$role=>$status){wp_set_current_user($users[$role]);$assert($request('GET',$id)->get_status()===$status,'native '.$role.' order visibility');if($status===403)$assert($request('POST',$id,['operation'=>'cancel','expected'=>$row['expected'],'confirmed'=>true],'/actions')->get_status()===403,'native '.$role.' action denied');}
 wp_set_current_user($users['client']);
 $body=['operation'=>'hold','expected'=>$row['expected'],'confirmed'=>true];
 $r=new WP_REST_Request('POST','/dsa/v1/workspace/commerce/orders/'.$id.'/actions');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_body(wp_json_encode($body));$r->set_header('Content-Type','application/json');$assert(rest_do_request($r)->get_status()===403,'order mutation proof required');
 $assert($request('POST',$id,array_replace($body,['confirmed'=>false]),'/actions')->get_status()===400,'explicit one-action review required');
 $assert($request('POST',$id,array_replace($body,['total'=>'1']),'/actions')->get_status()===400,'arbitrary financial fields refused');
 $assert($request('POST',$id,array_replace($body,['operation'=>'capture']),'/actions')->get_status()===400,'no arbitrary gateway capture action');
 $second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$key='kiwe_order_'.substr(hash('sha256',DB_NAME.':'.$GLOBALS['wpdb']->prefix.':'.$id),0,40);$assert((string)$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$key))==='1','independent native lock acquired');$assert($request('POST',$id,$body,'/actions')->get_status()===409&&wc_get_order($id)->get_status()==='pending','busy order refuses without write');$second->get_var($second->prepare('SELECT RELEASE_LOCK(%s)',$key));$second->close();
 wc_get_order($id)->add_order_note('Native concurrent note',0,true);$assert($request('POST',$id,$body,'/actions')->get_status()===409&&wc_get_order($id)->get_status()==='pending','native notes invalidate reviewed snapshot');
 $held=$act($id,'hold');$heldRow=$held->get_data();$proof['holdResult']=['httpStatus'=>$held->get_status(),'code'=>$heldRow['code']??null,'status'=>$heldRow['status']??null,'stock'=>wc_get_product($product)->get_stock_quantity(),'manageStock'=>get_option('woocommerce_manage_stock')];$assert($held->get_status()===200&&$heldRow['status']==='on-hold'&&wc_get_product($product)->get_stock_quantity()===8,'native hold reserves stock once');
 $beforePayment=$orders->review($id);$paymentBody=['operation'=>'payment','expected'=>$beforePayment['expected'],'confirmed'=>true,'reference'=>'SYNTHETIC-BANK-RECEIPT'];
 $paid=$request('POST',$id,$paymentBody,'/actions');$paidRow=$paid->get_data();$proof['paymentResult']=['status'=>$paid->get_status(),'code'=>$paidRow['code']??null];
 $assert($paid->get_status()===200&&$paidRow['paidAt']&&$paidRow['transactionId']==='SYNTHETIC-BANK-RECEIPT'&&$paidRow['status']==='processing','native offline receipt date reference and processing');
 $assert(wc_get_product($product)->get_stock_quantity()===8,'payment never reduces held stock twice');
 $assert($request('POST',$id,$paymentBody,'/actions')->get_status()===409&&wc_get_product($product)->get_stock_quantity()===8,'replayed receipt cannot run twice');
 $assert(!$paidRow['canCancel']&&!$paidRow['canHold']&&!$paidRow['canRecordPayment'],'settled order has no unpaid or repeat receipt controls');
 $note=$act($id,'note',['note'=>'Synthetic internal review']);$assert($note->get_status()===200&&array_filter($note->get_data()['notes'],static fn($x)=>$x['body']==='Synthetic internal review'&&!$x['customer']),'private native note saved and read back');
 $complete=$act($id,'complete');$assert($complete->get_status()===200&&$complete->get_data()['status']==='completed'&&$complete->get_data()['total']===$row['total'],'native fulfilment preserves finances');
 $assert(wc_get_order($id)->get_meta('_synthetic_vendor_order')==='preserve','unrelated native vendor metadata preserved');
 [$cancelId,$cancelProduct]=$make('cheque');$hold=$act($cancelId,'hold');$cancelBefore=$orders->review($cancelId);$cancel=$act($cancelId,'cancel');$assert($hold->get_status()===200&&$cancel->get_status()===200&&wc_get_product($cancelProduct)->get_stock_quantity()===10,'native unpaid cancellation releases stock once');
 $assert($request('POST',$cancelId,['operation'=>'cancel','expected'=>$cancelBefore['expected'],'confirmed'=>true],'/actions')->get_status()===409&&wc_get_product($cancelProduct)->get_stock_quantity()===10,'cancel replay cannot repeat stock release');
 [$cod,$codProduct]=$make('cod','processing');$codReview=$orders->review($cod);$proof['codInitial']=['status'=>$codReview['status'],'paidAt'=>$codReview['paidAt'],'canRecordPayment'=>$codReview['canRecordPayment']];
 $assert($codReview['status']==='processing'&&!$codReview['paidAt']&&$codReview['canRecordPayment'],'native manually processing COD without receipt date can be reviewed');
 $codPaid=$act($cod,'payment',['reference'=>'SYNTHETIC-CASH']);$assert($codPaid->get_status()===200&&$codPaid->get_data()['paidAt']&&wc_get_product($codProduct)->get_stock_quantity()===8,'native COD processing receipt without second stock reduction');
 $assert(!$orders->review($cod)['canRecordPayment']&&$act($cod,'payment')->get_status()===409,'native saved COD receipt refuses another payment completion');
 $assert(!has_filter('woocommerce_valid_order_statuses_for_payment_complete',null)||apply_filters('woocommerce_valid_order_statuses_for_payment_complete',['pending'],wc_get_order($cancelId))===['pending'],'scoped payment status filter retired');
 [$online]=$make('synthetic_card');$onlineReview=$orders->review($online);$assert(!$onlineReview['canRecordPayment']&&$onlineReview['paymentOwner']==='gateway'&&$act($online,'payment')->get_status()===409,'online payment remains gateway owned');
 [$disabled]=$make();$gateway=WC()->payment_gateways()->payment_gateways()['bacs'];$gateway->enabled='no';$assert(!$orders->review($disabled)['canRecordPayment']&&$act($disabled,'payment')->get_status()===409,'disabled offline gateway cannot record receipt');$gateway->enabled='yes';
 $assert($act($disabled,'payment',['reference'=>'<script>bad</script>'])->get_status()===400,'unsafe receipt reference refused before native save');
 $stale=$orders->review($disabled);wc_get_order($disabled)->payment_complete('SYNTHETIC-NATIVE');$assert($request('POST',$disabled,['operation'=>'payment','expected'=>$stale['expected'],'confirmed'=>true],'/actions')->get_status()===409,'native external settlement invalidates prior review');
 [$failure,$failureProduct]=$make('cheque');$act($failure,'hold');$failureBefore=$orders->review($failure);$throw=static function($orderId)use($failure){if($orderId===$failure)throw new Error('Synthetic native lifecycle failure');};add_action('woocommerce_order_status_cancelled',$throw,PHP_INT_MAX);
 $failed=$request('POST',$failure,['operation'=>'cancel','expected'=>$failureBefore['expected'],'confirmed'=>true],'/actions');remove_action('woocommerce_order_status_cancelled',$throw,PHP_INT_MAX);
 $proof['failureResult']=['httpStatus'=>$failed->get_status(),'code'=>$failed->get_data()['code']??null,'savedStatus'=>$orders->review($failure)['status'],'stock'=>wc_get_product($failureProduct)->get_stock_quantity()];$assert($failed->get_status()===409&&$orders->review($failure)['status']==='cancelled','uncertain native outcome is not falsely rolled back');
 $assert($request('POST',$failure,['operation'=>'cancel','expected'=>$failureBefore['expected'],'confirmed'=>true],'/actions')->get_status()===409&&wc_get_product($failureProduct)->get_stock_quantity()===10,'uncertain cancellation cannot replay');
 [$refunded]=$make();$refund=wc_create_refund(['order_id'=>$refunded,'amount'=>0,'refund_payment'=>false,'restock_items'=>false]);if(!is_wp_error($refund))$orderIds[]=$refund->get_id();$assert(!is_wp_error($refund)&&!$orders->review($refunded)['canRecordPayment']&&!$orders->review($refunded)['canCancel'],'zero-amount native refund still protects order operations');
 $assert($request('GET',$product)->get_status()===404,'product IDs do not imply order identity');
 $createdDate=(new DateTimeImmutable($row['created']))->setTimezone(wp_timezone())->format('Y-m-d');$query=new WP_REST_Request('GET','/dsa/v1/workspace/commerce');$query->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$query->set_query_params(['tab'=>'orders','kind'=>'bacs','from'=>$createdDate,'to'=>$createdDate,'status'=>'all','sort'=>'total_desc']);$queue=rest_do_request($query);$queueData=$queue->get_data();$queueIds=array_column($queueData['orders']??[],'id');$assert($queue->get_status()===200&&isset($queueData['orderStatuses'])&&count($queueIds)<=20&&in_array($id,$queueIds,true)&&!in_array($cod,$queueIds,true)&&!in_array($online,$queueIds,true),'native bounded order queue filters actual bank orders by inclusive current website date');
 $query->set_query_params(['tab'=>'orders','from'=>'2026-02-30']);$assert(rest_do_request($query)->get_status()===400,'invalid calendar order date refused');
 $assert(is_wp_error($orders->query_filters(['from'=>'2026-10-08','to'=>'2026-10-07'])),'reversed native order date range refused');
 for($i=0;$i<21;$i++)wc_get_order($id)->add_order_note('Synthetic history '.$i,0,true);$history=$orders->review($id);$assert(count($history['notes'])===20&&$history['notesHaveMore'],'native recent-note sentinel keeps response bounded');
 [$large]=$make();$big=wc_get_order($large);for($i=0;$i<101;$i++){$line=new WC_Order_Item_Product();$line->set_name('Synthetic extra '.$i);$big->add_item($line);}$big->save();$largeReview=$orders->review($large);$assert($largeReview['itemsTruncated']&&!$largeReview['canRecordPayment']&&!$largeReview['canAddNote']&&$act($large,'cancel')->get_status()===409,'oversized order is bounded and review-only');
 $assert(!$http&&!$mail,'no provider HTTP or customer mail attempts with native email channels disabled in fixture');
 $proof['passed']=true;
}catch(Throwable$e){$proof['passed']=false;$proof['error']=$e->getMessage();}
finally{
 try{
  foreach($filters as[$hook,$cb])if($hook==='pre_option_'.Owner::OPTION)remove_filter($hook,$cb,PHP_INT_MAX);
  wp_set_current_user($users['owner']??$actor);
  foreach(array_reverse($orderIds)as$id){$o=wc_get_order($id);if($o)$o->delete(true);}
  foreach(array_reverse($productIds)as$id){$p=wc_get_product($id);if($p)$p->delete(true);}
  require_once ABSPATH.'wp-admin/includes/user.php';wp_set_current_user($actor);foreach(array_reverse($users)as$id)wp_delete_user($id);
  foreach($filters as[$hook,$cb])remove_filter($hook,$cb,PHP_INT_MAX);
  foreach($orderIds as$id)$assert(!wc_get_order($id),'synthetic native order retired');
  foreach($productIds as$id)$assert(!wc_get_product($id),'synthetic native product retired');
  foreach($users as$id)$assert(!get_userdata($id),'synthetic native actor retired');
  $proof['cleanup']=true;
 }catch(Throwable$e){$proof['passed']=false;$proof['cleanupError']=$e->getMessage();}
 $proof['httpAttempts']=count($http);$proof['mailAttempts']=count($mail);
 if($mail)$proof['mailCallers']=$mail;
}
echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES).PHP_EOL;
