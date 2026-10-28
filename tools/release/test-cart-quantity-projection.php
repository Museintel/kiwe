<?php
/** Real cart payload quantity projection; no native writes or transport. */
declare(strict_types=1);
namespace DSA { final class Settings { public function get($name,$fallback=[]) { return []; } public function all(): array { return ['commerce'=>['cart_badges_enabled'=>false]]; } } }
namespace DSA\Commerce { final class Linked_Products_Service {} final class Store_Analytics_Service {} }
namespace {
 define('ABSPATH',__DIR__.'/');
 function WC() { return $GLOBALS['wc']; }
 function sanitize_text_field($value) { return (string)$value; }
 function wp_strip_all_tags($value) { return strip_tags($value); }
 function wp_specialchars_decode($value,...$args) { return html_entity_decode($value); }
 function __($value,$domain='') { return $value; }
 function get_bloginfo($name) { return 'UTF-8'; }
 final class Quantity_Product {
  public bool $managed=true,$backorders=false,$sold=false; public int $stock=5,$nativeMax=5,$id=11;
  public function managing_stock() { return $this->managed; }
  public function get_stock_quantity() { return $this->stock; }
  public function get_stock_managed_by_id() { return $this->id; }
  public function backorders_allowed() { return $this->backorders; }
  public function is_sold_individually() { return $this->sold; }
  public function get_max_purchase_quantity() { return $this->nativeMax; }
  public function get_name() { return 'Synthetic product'; }
 }
 final class Quantity_Cart {
  public array $rows=[];
  public function get_cart() { return $this->rows; }
  public function get_cart_item_quantities() { $groups=[];foreach($this->rows as$row){$id=$row['data']->get_stock_managed_by_id();$groups[$id]=($groups[$id]??0)+$row['quantity'];}return $groups; }
  public function get_product_price($product) { return '10'; }
  public function get_product_subtotal($product,$qty) { return (string)(10*$qty); }
 }
 require getenv('KIWE_CART_PAYLOAD_FILE')?:dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/Cart_Payload_Service.php';
 $proof=['passed'=>false,'checks'=>[]];$method=new ReflectionMethod(DSA\Commerce\Cart_Payload_Service::class,'cart_items');
 foreach(['ordinary'=>[true,false,false,5,5,1,0,5],'backorder zero'=>[true,true,false,0,-1,1,0,99],'backorder negative'=>[true,true,false,-2,-1,2,0,99],'unmanaged'=>[false,false,false,0,-1,1,0,99],'sold individually'=>[false,false,true,0,1,1,0,1],'shared stock'=>[true,false,false,5,5,2,2,3],'native extension max'=>[false,true,false,0,3,1,0,3],'newly reduced stock'=>[true,false,false,1,1,2,0,2],'route maximum'=>[true,false,false,500,500,1,0,99]]as$name=>$values){
  [$managed,$backorders,$sold,$stock,$nativeMax,$qty,$other,$expected]=$values;
  $product=new Quantity_Product();$product->managed=$managed;$product->backorders=$backorders;$product->sold=$sold;$product->stock=$stock;$product->nativeMax=$nativeMax;
  $cart=new Quantity_Cart();$cart->rows=['item'=>['data'=>$product,'product_id'=>11,'quantity'=>$qty]];if($other)$cart->rows['sibling']=['data'=>$product,'product_id'=>11,'quantity'=>$other];$GLOBALS['wc']=(object)['cart'=>$cart];
  $service=new DSA\Commerce\Cart_Payload_Service(new DSA\Settings(),static fn()=>new DSA\Commerce\Linked_Products_Service(),static fn()=>new DSA\Commerce\Store_Analytics_Service());
  $row=$method->invoke($service)[0];$proof['checks'][$name.': actionable quantity maximum']=$row['maxQuantity']===$expected;
  $proof['checks'][$name.': native stock remains a separate fact']=$row['stockQuantity']===($managed?$stock:null)&&$row['quantity']===$qty;
 }
 $proof['passed']=!in_array(false,$proof['checks'],true);echo json_encode($proof,JSON_PRETTY_PRINT).PHP_EOL;exit($proof['passed']?0:1);
}
