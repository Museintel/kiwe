<?php
declare(strict_types=1);
require __DIR__.'/test-workflow-apps.php';

class WC_Order {
 public string $status='processing';public int $updates=0;public bool $fail=false;public float $refunded=0;public string $total='24.00';
 public function get_id(){return 301;}public function get_order_number(){return '301';}public function get_type(){return 'shop_order';}public function get_status(){return $this->status;}public function get_total(){return $this->total;}public function get_currency(){return 'INR';}public function get_payment_method_title(){return 'Test payment';}public function get_customer_note(){return 'Leave at reception';}public function get_total_refunded(){return $this->refunded;}public function get_date_modified(){return null;}
 public function get_address($kind){return ['first_name'=>'Synthetic','last_name'=>'Customer','address_1'=>'Example street','email'=>'synthetic@example.invalid','secret'=>'hidden'];}
 public function get_items($type){return [];}
 public function get_payment_method($context='view'){return 'synthetic';}public function get_transaction_id($context='view'){return '';}public function get_date_paid($context='view'){return null;}public function get_date_created($context='view'){return null;}public function get_customer_id($context='view'){return 0;}public function get_refunds(){return [];}public function needs_payment(){return false;}public function read_meta_data($force=false){}public function get_meta_data(){return [];}
 public function get_data(){return ['status'=>$this->status,'total'=>$this->total,'refunded'=>$this->refunded];}
 public function get_data_store(){return new class{function read($order){}};}
 public function update_status($status,$note,$manual){$this->updates++;if($this->fail)return false;$this->status=$status;return true;}
}
$fixtureOrder=new WC_Order();function wc_get_order($id){return $id===301?$GLOBALS['fixtureOrder']:false;}function wc_get_order_notes($args){return [];}function wc_get_order_status_name($status){return ['processing'=>'Processing','on-hold'=>'On hold','completed'=>'Completed','cancelled'=>'Cancelled','pending'=>'Pending payment'][$status]??$status;}
$orderDb=new class {public string $prefix='fixture_';public int $lock=1;public int $released=0;public function prepare($query,...$args){return $query;}public function get_var($query){if(str_contains($query,'RELEASE_LOCK')){$this->released++;return 1;}return $this->lock;}};$wpdb=$orderDb;
$caps[1]=array_merge($caps[1],['edit_shop_orders','edit_others_shop_orders','edit_shop_order']);$actor=1;
$orderRequest=static fn($body=[])=>new WP_REST_Request('POST',$body,['id'=>301]);
$orderRead=$store->read_order(new WP_REST_Request('GET',[],['id'=>301]));check($orderRead->status===200&&$orderRead->data['canComplete'],'Permitted staff can inspect processing order');
check(!isset($orderRead->data['billing']['secret'])&&$orderRead->headers['Cache-Control']==='no-store, private','Order projection excludes arbitrary fields and cannot be cached');
$complete=['expected'=>$orderRead->data['expected'],'confirmed'=>true];
$actor=3;check($store->read_order(new WP_REST_Request('GET',[],['id'=>301]))->status===403&&$store->complete_order($orderRequest($complete))->status===403,'Product staff cannot read or complete orders');$actor=1;
$r=$orderRequest($complete);$r->headers['x-kiwe-mutation']='';check($store->complete_order($r)->status===403,'Completion requires mutation proof');
check($store->complete_order($orderRequest(['expected'=>$complete['expected'],'confirmed'=>false]))->status===400,'Completion requires explicit confirmation');
$orderDb->lock=0;check($store->complete_order($orderRequest($complete))->status===409&&$fixtureOrder->updates===0,'Busy order fails closed without writes');$orderDb->lock=1;
check($store->complete_order($orderRequest(array_replace($complete,['expected'=>'stale'])))->status===409&&$orderDb->released===1,'Stale completion is denied and lock released');
$fixtureOrder->fail=true;check($store->complete_order($orderRequest($complete))->status===409&&$orderDb->released===2,'Native write failure is explicit and releases lock');$fixtureOrder->fail=false;
$result=$store->complete_order($orderRequest($complete));check($result->status===200&&$result->data['status']==='completed'&&$result->data['total']==='24.00','Fulfilment changes only status through native lifecycle');
$updates=$fixtureOrder->updates;check($store->complete_order($orderRequest($complete))->status===409&&$fixtureOrder->updates===$updates,'Duplicate completion cannot run native actions twice');
foreach(['pending','on-hold','cancelled','refunded']as$status){$fixtureOrder->status=$status;$read=$store->read_order(new WP_REST_Request('GET',[],['id'=>301]));check(!$read->data['canComplete']&&$store->complete_order($orderRequest(['expected'=>$read->data['expected'],'confirmed'=>true]))->status===409,'No completion from '.$status);}
$fixtureOrder->status='processing';$fixtureOrder->refunded=1;$read=$store->read_order(new WP_REST_Request('GET',[],['id'=>301]));check(!$read->data['canComplete'],'Partially refunded orders require separate review');
echo "Order fulfilment boundary checks passed.\n";
