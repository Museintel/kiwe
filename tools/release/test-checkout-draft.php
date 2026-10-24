<?php
/** Actual checkout owner, synthetic Woo boundaries. No records, order or transport. */
namespace DSA { class Settings {public bool $enabled=true;public array $commerce=['cart_show_delivery'=>false];function get($key,$fallback=null){return $key==='enabled'?$this->enabled:($key==='commerce'?$this->commerce:$fallback);}} }
namespace DSA\Commerce {
 class Store_Recovery_Gate_Service {public static bool $paused=false;static function is_paused(){return self::$paused;}}
 class Cart_Payload_Service {function product_total(){return '$440.00';}function discount_summary(bool $productsOnly=false){return ['productsOnly'=>$productsOnly];}}
}
namespace {
 define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';$checks=[];$allowed=true;$options=[];$saves=0;
 function check($ok,$label){if(!$ok)throw new \RuntimeException($label);$GLOBALS['checks'][]=$label;}
 function __($s,...$a){return $s;}function apply_filters($h,$v){return ['enabled'=>true,'allowed'=>$GLOBALS['allowed']];}
 function do_action($hook,...$args){if($hook==='dsa_checkout_draft_saved')$GLOBALS['checkoutJourneyEvents']=1+($GLOBALS['checkoutJourneyEvents']??0);}
 function sanitize_key($v){return preg_replace('/[^a-z0-9_]/','',strtolower($v));}function sanitize_text_field($v){return trim(strip_tags($v));}function sanitize_textarea_field($v){return trim(strip_tags($v));}function sanitize_email($v){return trim($v);}function wp_unslash($v){return $v;}
 function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL)!==false;}function is_user_logged_in(){return false;}
 function get_option($k,$v=null){return $GLOBALS['options'][$k]??$v;}function get_bloginfo($k){return 'UTF-8';}function wp_strip_all_tags($v){return strip_tags($v);}function esc_url_raw($v){return $v;}function wc_get_checkout_url(){return 'https://example.invalid/pay/';}function wc_price($v){return '$'.number_format($v,2);}
 class DraftSession {public array $values=[];function get($k,$fallback=[]){return $this->values[$k]??$fallback;}function set($k,$v){$this->values[$k]=$v;}}
 class WC_Customer {
  public array $values=[];
  function set_billing_city($v){$this->values['billing_city']=$v;}function get_billing_city(){return $this->values['billing_city']??'';}
  function set_shipping_city($v){$this->values['shipping_city']=$v;}function get_shipping_city(){return $this->values['shipping_city']??'';}
  function set_billing_country($v){$this->values['billing_country']=$v;}function get_billing_country(){return $this->values['billing_country']??'';}
  function set_shipping_country($v){$this->values['shipping_country']=$v;}function get_shipping_country(){return $this->values['shipping_country']??'';}
  function set_billing_email($v){$this->values['billing_email']=$v;}function get_billing_email(){return $this->values['billing_email']??'';}
  function set_shipping_email($v){$this->values['shipping_email']=$v;}function get_shipping_email(){return $this->values['shipping_email']??'';}
 }
 class DraftCart {public bool $needs=true,$address=true,$inclusive=false;function needs_shipping(){return $this->needs;}function needs_shipping_address(){return $this->needs&&$this->address;}function get_cart_contents_count(){return 2;}function get_total($context='view'){return $context==='edit'?523.16:'$523.16';}function get_cart_contents_total(){return 440;}function get_cart_contents_tax(){return 44;}function display_prices_including_tax(){return $this->inclusive;}function calculate_totals(){throw new \RuntimeException('Unexpected recalculation');}}
 class DraftCheckout {
  function get_checkout_fields(){return ['billing'=>['billing_email'=>['type'=>'email','required'=>true,'label'=>'Email'],'billing_city'=>['type'=>'text','required'=>true,'label'=>'City'],'billing_country'=>['type'=>'text','required'=>true,'label'=>'Country']],'shipping'=>['shipping_city'=>['type'=>'text','required'=>true,'label'=>'Shipping city'],'shipping_country'=>['type'=>'text','required'=>true,'label'=>'Shipping country']],'order'=>['order_comments'=>['type'=>'textarea','required'=>false,'label'=>'Notes']]];}
  function get_value($key){return '';}
 }
 class DraftWoo {public $cart,$session,$customer,$checkout;public $countries=null;function checkout(){return $this->checkout;}}
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/Checkout_Service.php';
 $settings=new \DSA\Settings();$service=new \DSA\Commerce\Checkout_Service($settings,new \DSA\Commerce\Cart_Payload_Service());
 check(!$service->available(),'Non-Woo cannot expose checkout or create a draft');
 eval('function WC(){return $GLOBALS["wc"];}');$wc=new DraftWoo();$wc->cart=new DraftCart();$wc->session=new DraftSession();$wc->customer=new WC_Customer();$wc->checkout=new DraftCheckout();
 $billing=['billing_email'=>'shopper@example.invalid','billing_city'=>'Pune','billing_country'=>'IN','ship_to_different_address'=>'0'];
 $r=$service->save_draft($billing,true);check($r['valid']&&!$r['checkout']['shipToDifferent'],'Unchecked shipping validates billing alone');
 check($wc->customer->get_shipping_city()==='Pune'&&$wc->customer->get_shipping_country()==='IN','Unchecked shipping primes native destination from billing');
 check($r['checkout']['cartTotal']==='$440.00'&&$r['checkout']['discountSummary']['productsOnly']===true,'Default checkout projects product charges and product discounts');
 check($r['checkout']['checkoutUrl']==='https://example.invalid/pay/','Destination is configured Woo checkout URL');
 $r=$service->save_draft(['ship_to_different_address'=>'1'],true);check(!$r['valid']&&isset($r['errors']['shipping_city'],$r['errors']['shipping_country']),'Checked shipping validates native required fields');
 $r=$service->save_draft(['ship_to_different_address'=>'1','shipping_city'=>'Mumbai','shipping_country'=>'IN'],true);check($r['valid']&&$wc->customer->get_shipping_city()==='Mumbai','Valid separate shipping becomes native active destination');
 $r=$service->save_draft(['ship_to_different_address'=>'0','shipping_city'=>'Mumbai','shipping_country'=>'IN'],true);check($r['valid']&&!$r['checkout']['shipToDifferent']&&$wc->customer->get_shipping_city()==='Pune','Explicit unchecked shipping wins over retained address');
 check($wc->session->get('dsa_checkout_draft')['shipping_city']==='Mumbai','Separate address draft survives switching off');
 check(!$service->ship_to_different_address_checked(true),'Native checkbox hydrates explicit false');
 $r=$service->save_draft(['ship_to_different_address'=>'1'],true);check($r['valid']&&$r['checkout']['values']['shipping_city']==='Mumbai','Switching on restores prior separate address');
 check($service->checkout_value('default','billing_city')==='Pune','Native checkout field hook uses the same draft');
 $merged=$service->merge_draft_into_posted_data(['ship_to_different_address'=>false,'shipping_city'=>'','billing_city'=>'Edited on checkout']);check($merged['shipping_city']===''&&$merged['billing_city']==='Edited on checkout','Final native unchecked flag and edits override saved draft');
 $merged=$service->merge_draft_into_posted_data(['ship_to_different_address'=>true,'shipping_city'=>'']);check($merged['shipping_city']==='Mumbai','Checked native checkout recovers a missing required draft field');
 $settings->commerce['cart_show_delivery']=true;$r=$service->contract();check($r['cartTotal']==='$523.16'&&$r['cartShowDelivery']&&$r['discountSummary']['productsOnly']===false,'Delivery policy projects native full total and full discounts');
 $fallback=new \DSA\Commerce\Checkout_Service($settings);$settings->commerce['cart_show_delivery']=false;check($fallback->contract()['cartTotal']==='$440.00','Uninjected owner preserves product-only price policy');
 $wc->cart->inclusive=true;check($fallback->contract()['cartTotal']==='$484.00','Fallback product price respects native inclusive tax basis');$wc->cart->inclusive=false;
 $wc->cart->needs=false;$r=$service->save_draft(['ship_to_different_address'=>'1','shipping_city'=>'','shipping_country'=>''],true);check($r['valid']&&!$r['checkout']['needsShipping']&&!$r['checkout']['shipToDifferent']&&!isset($r['checkout']['groups']['shipping']),'Virtual cart cannot require shipping or expose its toggle');
 $wc->cart->needs=true;$wc->cart->address=false;$r=$service->save_draft(['ship_to_different_address'=>'1'],true);check($r['valid']&&!$r['checkout']['needsShipping']&&!$r['checkout']['shipToDifferent'],'Native ship-to-billing-only policy overrides copied separate flag');
 $allowed=false;check(!$service->available(),'Purchase identity gate still denies checkout');$allowed=true;
 \DSA\Commerce\Store_Recovery_Gate_Service::$paused=true;check(!$service->available(),'Recovery pause still denies checkout');\DSA\Commerce\Store_Recovery_Gate_Service::$paused=false;
 $settings->enabled=false;check(!$service->available(),'Disabled Surface remains inert');$settings->enabled=true;
 $service->clear_after_order();check($wc->session->get('dsa_checkout_draft')===[]&&$wc->session->get('dsa_checkout_errors')===[],'Native completion clears owned draft and errors');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'scope'=>'Actual Checkout_Service with synthetic native boundaries; no order, database, delivery or native integration proof']).PHP_EOL;
}
