<?php
/** Real Kiwe controller with native-owner spies. Real Woo stock/totals are qualified separately. */
declare(strict_types=1);
namespace DSA\Utilities {
 final class Atomic_Rate_Limiter { public static function allow(...$args): bool { return true; } }
 final class Origin_Checker {}
}
namespace DSA\Commerce {
 final class Cart_Payload_Service {
  public int $refreshes=0;
  public function load_cart(): bool { return true; }
  public function refresh_after_mutation($cart,$before): void {
   $this->refreshes++;
   if (\did_action('woocommerce_after_calculate_totals')===$before) $cart->calculate();
  }
  public function cart_data(): array { return ['available'=>true,'count'=>\WC()->cart->get_cart_contents_count(),'total'=>'native-total']; }
 }
 final class Store_Analytics_Service {
  public array $events=[]; public int $claims=0;
  public static string $source=''; public static int $marks=0; public static int $resets=0;
  public static function mark_cart_source($source): void { self::$source=$source; self::$marks++; }
  public static function reset_cart_source(): void { self::$source=''; self::$resets++; }
  public function cart_item_data_for_upsell($product,$trigger): array { return ['dsa_upsell_pending'=>true,'dsa_trigger_id'=>$trigger]; }
  public function claim_cart_upsell_discount(...$args): bool { $this->claims++; return true; }
  public function record_cart_event($event): void { $this->events[]=$event; }
 }
}
namespace {
 define('ABSPATH',__DIR__.'/'); define('MINUTE_IN_SECONDS',60);
 final class WP_Error {
  public function __construct(public string $code,public string $message='',public array $data=[]) {}
 }
 final class WP_REST_Response { public function __construct(public array $data,public int $status=200) {} }
 final class WP_REST_Request {
  public function __construct(private array $params) {}
  public function get_param($key) { return $this->params[$key]??null; }
  public function get_json_params(): array { return $this->params; }
 }
 function __($text,$domain=''): string { return $text; }
 function absint($value): int { return abs((int)$value); }
 function sanitize_text_field($value): string { return trim(strip_tags((string)$value)); }
 function sanitize_key($value): string { return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value)); }
 function wp_strip_all_tags($value): string { return strip_tags((string)$value); }
 function get_current_user_id(): int { return $GLOBALS['actor']; }
 function is_wp_error($value): bool { return $value instanceof WP_Error; }
 function did_action($hook): int { return $GLOBALS['actions'][$hook]??0; }
 function WC() { return $GLOBALS['wc']; }
 function wc_get_product($id) { return $GLOBALS['products'][$id]??false; }
 function wc_get_notices($type): array { return $GLOBALS['notices']; }
 function wc_clear_notices(): void { $GLOBALS['notices']=[]; }
 function wc_add_notice($message,$type='error'): void { $GLOBALS['notices'][]=['notice'=>$message]; }
 function apply_filters($hook,$value,...$args) {
  if (str_ends_with($hook,'_validation')) $GLOBALS['hookCalls'][]=[$hook,$value,...$args];
  foreach ($GLOBALS['filters'][$hook]??[] as $callback) $value=$callback($value,...$args);
  return $value;
 }
 final class Cart_Test_Product {
  public bool $purchasable=true,$inStock=true,$managed=false,$sold=false;
  public int $stock=10; public string $backorders='no'; public array $attributes=[];
  public function __construct(public int $id,public string $type='simple',public int $parent=0,public int $stockOwner=0) {}
  public function get_id(): int { return $this->id; }
  public function is_type($type): bool { return $type===$this->type; }
  public function get_parent_id(): int { return $this->parent; }
  public function get_variation_attributes(): array { return $this->attributes; }
  public function is_purchasable(): bool { return $this->purchasable; }
  public function is_in_stock(): bool { return $this->inStock; }
  public function is_sold_individually(): bool { return $this->sold; }
  public function managing_stock(): bool { return $this->managed; }
  public function get_stock_quantity(): int { return $this->stock; }
  public function get_stock_managed_by_id(): int { return $this->stockOwner?:$this->id; }
  public function backorders_allowed(): bool { return $this->backorders!=='no'; }
  public function has_enough_stock($quantity): bool { return !$this->managed||$this->backorders_allowed()||$this->stock>=$quantity; }
 }
 final class Cart_Test_Session {
  public int $cookies=0;
  public function set_customer_session_cookie($value): void { $this->cookies++; }
  public function get_customer_id(): string { return 'synthetic-cart-session'; }
 }
 final class Cart_Test_Cart {
  public array $rows=[],$lastAdd=[]; public int $adds=0,$updates=0,$removes=0,$sessions=0,$cookies=0;
  public bool $failAdd=false,$failUpdate=false,$failRemove=false,$loaded=true;
  public function calculate(): void { $GLOBALS['actions']['woocommerce_after_calculate_totals']=did_action('woocommerce_after_calculate_totals')+1; }
  public function get_cart(): array { $this->loaded=true; return $this->rows; }
  public function get_cart_item($key): array { return $this->loaded?($this->rows[$key]??[]):[]; }
  public function get_cart_contents_count(): int { return array_sum(array_column($this->rows,'quantity')); }
  public function get_cart_item_quantities(): array {
   $groups=[];
   foreach ($this->rows as $row) { $id=$row['data']->get_stock_managed_by_id(); $groups[$id]=($groups[$id]??0)+$row['quantity']; }
   return $groups;
  }
  public function add_to_cart($id,$quantity,$variation,$attributes,$data) {
   $this->adds++; $this->lastAdd=func_get_args(); $product=wc_get_product($variation?:$id);
   if ($this->failAdd) { wc_add_notice('<b>Native add rejected.</b>'); return false; }
   $key=md5(serialize([$id,$variation,$attributes,$data]));
   if ($product->sold) { $quantity=1; if (isset($this->rows[$key])) { wc_add_notice('Only one is allowed.'); return false; } }
   $groups=$this->get_cart_item_quantities();
   if (!$product->is_in_stock()||!$product->has_enough_stock(($groups[$product->get_stock_managed_by_id()]??0)+$quantity)) {
    wc_add_notice('Native stock rejected.'); return false;
   }
   $this->rows[$key]=array_merge($data,['product_id'=>$id,'variation_id'=>$variation,'variation'=>$attributes,'data'=>$product,'quantity'=>($this->rows[$key]['quantity']??0)+$quantity]);
   $this->calculate(); return $key;
  }
  public function set_quantity($key,$quantity,$refresh=true): bool {
   $this->updates++;
   if ($this->failUpdate) { wc_add_notice('<b>Native update rejected.</b>'); return false; }
   $this->rows[$key]['quantity']=$quantity; if ($refresh) $this->calculate(); return true;
  }
  public function remove_cart_item($key): bool {
   $this->removes++; if ($this->failRemove) return false;
   unset($this->rows[$key]); $this->calculate(); return true;
  }
  public function set_session(): void { $this->sessions++; }
  public function maybe_set_cart_cookies(): void { $this->cookies++; }
  public function get_cart_hash(): string { return 'native-hash'; }
 }
 require getenv('KIWE_CART_CONTROLLER_FILE')?:dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Rest/Cart_Controller.php';
 $proof=['passed'=>false,'checks'=>[],'errors'=>[],'scope'=>'Actual Kiwe controller/native-owner spies; no network, cookies, database or order'];
 function check($ok,string $name): void { $GLOBALS['proof']['checks'][$GLOBALS['case'].': '.$name]=(bool)$ok; }
 function fixture(int $actor=0): array {
  $GLOBALS['actor']=$actor; $GLOBALS['notices']=[]; $GLOBALS['filters']=[]; $GLOBALS['hookCalls']=[]; $GLOBALS['actions']=[];
  DSA\Commerce\Store_Analytics_Service::$source=''; DSA\Commerce\Store_Analytics_Service::$marks=0; DSA\Commerce\Store_Analytics_Service::$resets=0;
  $product=new Cart_Test_Product(11); $GLOBALS['products']=[11=>$product];
  $cart=new Cart_Test_Cart(); $session=new Cart_Test_Session(); $GLOBALS['wc']=(object)['cart'=>$cart,'session'=>$session];
  $payload=new DSA\Commerce\Cart_Payload_Service(); $analytics=new DSA\Commerce\Store_Analytics_Service();
  return [new DSA\Rest\Cart_Controller($payload,$analytics),$cart,$product,$payload,$analytics,$session];
 }
 function row(Cart_Test_Cart $cart,Cart_Test_Product $product,string $key='item',int $quantity=1): void {
  $cart->rows[$key]=['product_id'=>$product->parent?:$product->id,'variation_id'=>$product->parent?$product->id:0,'data'=>$product,'quantity'=>$quantity,'custom_option'=>'native-owned'];
 }
 function denied($response): bool { return $response instanceof WP_Error&&($response->data['status']??0)===409; }
 function accepted($response): bool { return $response instanceof WP_REST_Response&&$response->status===200&&($response->data['ok']??false)===true; }
 function scenario(string $name,Closure $run): void {
  $GLOBALS['case']=$name;
  try { $run(); } catch (Throwable $e) { check(false,'unexpected exception'); $GLOBALS['proof']['errors'][$name]=get_class($e).': '.$e->getMessage(); }
 }
 foreach ([0,17] as $actor) {
  scenario('add denial actor '.$actor,static function()use($actor){
   [$controller,$cart,$product,$payload,$analytics,$session]=fixture($actor);
   $GLOBALS['filters']['woocommerce_add_to_cart_validation'][]=static function(){ wc_add_notice('<strong>Eligibility required.</strong>'); return false; };
   $response=$controller->add_item(new WP_REST_Request(['productId'=>11,'quantity'=>2,'triggerId'=>9]));
   check(denied($response)&&$response->message==='Eligibility required.','extension error returned as readable native notice');
   check($cart->adds===0&&$cart->get_cart_contents_count()===0,'no native mutation');
   check($session->cookies===0&&$cart->sessions===0&&$payload->refreshes===0&&$analytics->claims===0,'no cookie claim save or refresh after denial');
   check(DSA\Commerce\Store_Analytics_Service::$marks===0&&DSA\Commerce\Store_Analytics_Service::$source==='','no mutation attribution after denial');
  });
  scenario('update denial actor '.$actor,static function()use($actor){
   [$controller,$cart,$product,$payload,$analytics]=fixture($actor); row($cart,$product);
   $GLOBALS['filters']['woocommerce_update_cart_validation'][]=static function($allowed,$key,$item,$quantity){
    check($allowed===true&&$key==='item'&&$item['quantity']===1&&$item['custom_option']==='native-owned'&&$quantity===3,'four native update arguments');
    wc_add_notice('<b>Quantity is restricted.</b>'); return false;
   };
   $response=$controller->update_item(new WP_REST_Request(['key'=>'item','quantity'=>3]));
   check(denied($response)&&$response->message==='Quantity is restricted.','extension update denial returned');
   check($cart->updates===0&&$cart->rows['item']['quantity']===1&&$payload->refreshes===0&&$analytics->events===[],'no mutation analytics or refresh');
  });
 }
 scenario('normalized variation and owned data',static function(){
  [$controller,$cart,$product,$payload,$analytics]=fixture();
  $variation=new Cart_Test_Product(12,'variation',11); $variation->attributes=['attribute_pa_size'=>'small']; $GLOBALS['products'][12]=$variation;
  $GLOBALS['filters']['woocommerce_add_to_cart_validation'][]=static function(...$args){
   check(count($args)===6&&array_slice($args,0,5)===[true,11,2,12,['attribute_pa_size'=>'small']],'six native add arguments with normalized identities');
   check(($args[5]['dsa_trigger_id']??0)===9&&($args[5]['dsa_source_context']??'')==='cart_upsell','owned upsell context delivered to validation'); return true;
  };
  $response=$controller->add_item(new WP_REST_Request(['productId'=>12,'quantity'=>2,'triggerId'=>9]));
  check(accepted($response)&&$cart->adds===1&&$cart->get_cart_contents_count()===2,'one successful native variation mutation');
  check($payload->refreshes===1&&did_action('woocommerce_after_calculate_totals')===1&&$cart->sessions===1,'one calculation and one authoritative refresh');
  check($analytics->claims===1&&DSA\Commerce\Store_Analytics_Service::$resets===1,'existing owned claim and attribution cleanup');
  check(isset($response->data['item'],$response->data['claim'],$response->data['cart'],$response->data['fragments'],$response->data['cart_hash']),'public response fields retained');
 });
 foreach (['yes','notify'] as $policy) foreach ([0,-3] as $stock) {
  scenario('backorders '.$policy.' stock '.$stock,static function()use($policy,$stock){
   [$controller,$cart,$product]=fixture(); $product->managed=true; $product->stock=$stock; $product->backorders=$policy;
   $response=$controller->add_item(new WP_REST_Request(['productId'=>11,'quantity'=>2]));
   check(accepted($response)&&$cart->adds===1,'native backorder add admitted');
   $key=array_key_first($cart->rows); $response=$controller->update_item(new WP_REST_Request(['key'=>$key,'quantity'=>4]));
   check(accepted($response)&&$cart->updates===1&&$cart->get_cart_contents_count()===4,'native backorder update admitted');
  });
 }
 scenario('native cumulative add stock',static function(){
  [$controller,$cart,$product]=fixture(); $product->managed=true; $product->stock=3; row($cart,$product,'existing',2);
  $response=$controller->add_item(new WP_REST_Request(['productId'=>11,'quantity'=>2]));
  check(denied($response)&&$cart->get_cart_contents_count()===2,'existing quantities count against native add stock');
 });
 foreach (['simple','variation'] as $type) {
  scenario('shared stock update '.$type,static function()use($type){
   [$controller,$cart,$product,$payload,$analytics]=fixture(); $product->managed=true; $product->stock=5;
   if ($type==='variation') { $product->type='variation'; $product->parent=20; $product->stockOwner=20; }
   row($cart,$product,'item',2); $sibling=clone $product; $sibling->id=12; if ($type==='simple')$sibling->id=11; row($cart,$sibling,'sibling',2);
   $response=$controller->update_item(new WP_REST_Request(['key'=>'item','quantity'=>4]));
   check(denied($response)&&($response->data['max_qty']??null)===3,'sibling quantity reduces available line quantity');
   check($cart->updates===0&&$cart->get_cart_contents_count()===4&&$analytics->events===[],'over-stock update leaves both rows exact');
   $response=$controller->update_item(new WP_REST_Request(['key'=>'item','quantity'=>3]));
   check(accepted($response)&&$cart->get_cart_contents_count()===5&&$cart->updates===1,'replacement quantity allowed at shared-stock boundary');
   check($payload->refreshes===1&&did_action('woocommerce_after_calculate_totals')===1,'no duplicate calculation on update');
  });
 }
 scenario('independent variation stock',static function(){
  [$controller,$cart,$product]=fixture(); $product->managed=true; $product->stock=3; $product->type='variation'; $product->parent=20;
  row($cart,$product); $sibling=clone $product; $sibling->id=12; row($cart,$sibling,'sibling',3);
  check(accepted($controller->update_item(new WP_REST_Request(['key'=>'item','quantity'=>3]))),'separate stock owners do not reduce availability');
 });
 foreach (['out','zero','negative','sold'] as $reason) {
  scenario('reject positive update '.$reason,static function()use($reason){
   [$controller,$cart,$product]=fixture(); row($cart,$product);
   if ($reason==='out') $product->inStock=false;
   if ($reason==='zero'||$reason==='negative') { $product->managed=true; $product->stock=$reason==='zero'?0:-1; }
   if ($reason==='sold') $product->sold=true;
   check(denied($controller->update_item(new WP_REST_Request(['key'=>'item','quantity'=>2])))&&$cart->updates===0,'native purchase restriction prevents positive update');
  });
 }
 scenario('sold individually native add',static function(){
  [$controller,$cart,$product]=fixture(); $product->sold=true;
  check(accepted($controller->add_item(new WP_REST_Request(['productId'=>11,'quantity'=>5])))&&$cart->get_cart_contents_count()===1,'native add quantity normalization retained');
  check(denied($controller->add_item(new WP_REST_Request(['productId'=>11,'quantity'=>1])))&&$cart->get_cart_contents_count()===1,'native duplicate restriction retained');
 });
 scenario('explicit removal despite purchase restriction',static function(){
  [$controller,$cart,$product,$payload,$analytics]=fixture(); row($cart,$product); $product->inStock=false; $product->managed=true; $product->stock=-1;
  $GLOBALS['filters']['woocommerce_update_cart_validation'][]=static fn()=>false;
  $response=$controller->update_item(new WP_REST_Request(['key'=>'item','quantity'=>0]));
  check(accepted($response)&&$cart->removes===1&&$cart->get_cart_contents_count()===0,'native remove path remains usable');
  check($GLOBALS['hookCalls']===[]&&count($analytics->events)===1&&$analytics->events[0]['event_type']==='cart_remove','removal independent of positive-purchase validation');
 });
 foreach (['add','update','remove'] as $operation) {
  scenario('native failure '.$operation,static function()use($operation){
   [$controller,$cart,$product,$payload,$analytics]=fixture();
   if ($operation!=='add') row($cart,$product);
   $property='fail'.ucfirst($operation); $cart->$property=true;
   $response=$operation==='add'?$controller->add_item(new WP_REST_Request(['productId'=>11,'quantity'=>1])):$controller->update_item(new WP_REST_Request(['key'=>'item','quantity'=>$operation==='remove'?0:2]));
   check(denied($response),'native failure never reported as success');
   check($payload->refreshes===0&&$analytics->events===[]&&$cart->sessions===0,'no success-only side effects');
  });
 }
 scenario('fallback validation messages',static function(){
  [$controller,$cart,$product]=fixture(); $GLOBALS['filters']['woocommerce_add_to_cart_validation'][]=static fn()=>false;
  $response=$controller->add_item(new WP_REST_Request(['productId'=>11]));
  check(denied($response)&&$response->message!=='','denial without extension notice has readable fallback');
 });
 scenario('lazy native session key-only request',static function(){
  [$controller,$cart,$product,$payload]=fixture();row($cart,$product);$cart->loaded=false;
  $response=$controller->update_item(new WP_REST_Request(['key'=>'item','quantity'=>2]));
  check(accepted($response)&&$cart->updates===1&&$cart->rows['item']['quantity']===2,'native lazy session restored before key lookup');
  check($payload->refreshes===1&&did_action('woocommerce_after_calculate_totals')===1,'restoring the cart does not add a calculation');
 });
 $proof['passed']=!in_array(false,$proof['checks'],true)&&!$proof['errors'];
 echo json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
 exit($proof['passed']?0:1);
}
