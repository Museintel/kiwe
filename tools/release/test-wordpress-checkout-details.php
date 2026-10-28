<?php
/** Named lab only: real Woo fields/SQL/crypto, in-memory cart, no order or delivery. */
if ( PHP_SAPI !== 'cli' || ! defined('KIWE_WORKSPACE_TEST_SITE') || !KIWE_WORKSPACE_TEST_SITE || home_url() !== 'https://plum-giraffe-539524.hostingersite.com' ) exit(2);
use DSA\Commerce\Abandoned_Cart_Service as Carts;
use DSA\Commerce\Checkout_Details_Model as Details;
use DSA\Access\Checkout_Policy_Workspace_Service as Policy;
use DSA\Security\Secret_Store as Secrets;
$proof=['schema'=>'kiwe.native-checkout-details.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
$check=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$actor=get_current_user_id();$prefix=$wpdb->prefix;$wc=WC();$originalWoo=[$wc->session,$wc->customer,$wc->cart];$options=[];$tables=[];$filters=[];$http=0;$mail=0;
foreach(['phone','company','address_2']as$key)$options['woocommerce_checkout_'.$key.'_field']=get_option('woocommerce_checkout_'.$key.'_field',null);
$settingsBefore=get_option(DSA_OPTION_SETTINGS);$rawFields=$wc->checkout()->get_checkout_fields();
$filter=static function($hook,$callback,$args=1)use(&$filters){add_filter($hook,$callback,PHP_INT_MAX,$args);$filters[]=[$hook,$callback];};
try{
 $check(current_user_can('kiwe_manage_context')&&current_user_can('manage_woocommerce')&&current_user_can('edit_others_shop_orders'),'Named native actor can manage checkout and private cart details');
 $filter('pre_http_request',static function()use(&$http){$http++;return new WP_Error('qa_closed','No transport');},3);$filter('pre_wp_mail',static function()use(&$mail){$mail++;return false;},2);
 $settings=new DSA\Settings();$analytics=new DSA\Commerce\Store_Analytics_Service($settings);$payload=new DSA\Commerce\Cart_Payload_Service($settings,new DSA\Commerce\Linked_Products_Service($settings),$analytics);
 $channels=new DSA\Communications\Channel_Service($settings,new DSA\Communications\Email_Service($settings),new DSA\PhoneKey\PhoneKey_Bridge($payload));$carts=new Carts($settings,$analytics,$channels);$policy=new Policy($carts);$carts->maybe_install();
 $before=$policy->policy();$check(!is_wp_error($before),'Native policy is available');
 $wanted=['phone'=>'required','company'=>'hidden','address_2'=>'optional'];$after=$policy->change_checkout_fields(['operation'=>'checkoutFields','fields'=>$wanted,'expected'=>$before['expected']]);
 $check(!is_wp_error($after)&&$after['checkoutFields']===$wanted,'Native field controls save and read back');
 foreach($wanted as$key=>$value)$check(get_option('woocommerce_checkout_'.$key.'_field')===$value,'Original native option owns '.$key);
 $wc->checkout()->checkout_fields=null;$native=$wc->checkout()->get_checkout_fields('billing');$check(!empty($native['billing_phone']['required'])&&!isset($native['billing_company']),'Native Woo fields require phone and omit company');
 $check(is_wp_error($policy->change_checkout_fields(['operation'=>'checkoutFields','fields'=>['phone'=>'optional','company'=>'hidden','address_2'=>'optional'],'expected'=>$before['expected']]))||$before['checkoutFields']===$wanted,'A changed review cannot overwrite current field controls');
 $scope=$prefix.'checkout127_'.bin2hex(random_bytes(4)).'_';
 foreach(['dsa_abandoned_carts','dsa_abandoned_cart_reminders']as$suffix){$table=$scope.$suffix;$source=$prefix.$suffix;if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table.$source))throw new RuntimeException('Exact table names required');$check($wpdb->query("CREATE TEMPORARY TABLE `{$table}` LIKE `{$source}`")!==false,'Private temporary cart schema '.$suffix);$tables[]=$table;}
 $wpdb->prefix=$scope;
 $filter('pre_option_dsa_abandoned_cart_db_version',static fn()=>'4');$filter('pre_option_dsa_abandoned_cart_details_schema',static fn()=>'1');
 $filter('dsa_purchase_identity_gate_state',static fn()=>['enabled'=>false,'allowed'=>true]);
 $filter('pre_option_woocommerce_enable_guest_checkout',static fn()=>'yes');
 $filter('woocommerce_cart_needs_shipping',static fn()=>false);
 wp_set_current_user(0);$wc->session=new class extends WC_Session {};$wc->customer=new WC_Customer();$wc->customer->set_billing_country('IN');$wc->customer->set_billing_state('MH');$wc->cart=new WC_Cart();
 $product=new WC_Product_Simple();$product->set_name('Checkout detail fixture');$product->set_price('10');$product->set_virtual(true);$wc->cart->set_cart_contents(['fixture'=>['data'=>$product,'product_id'=>0,'variation_id'=>0,'quantity'=>1,'variation'=>[]]]);$wc->cart->set_totals(['subtotal'=>10,'cart_contents_total'=>10,'total'=>10]);
 $carts->capture_current_cart('cart_change');$id=(int)$wpdb->get_var("SELECT MAX(id) FROM `{$tables[0]}`");$check($id>0,'Real native SQL captures the isolated anonymous cart');
 $draft=['billing_first_name'=>'Synthetic','billing_last_name'=>'Visitor','billing_email'=>'checkout@example.test','billing_phone'=>'+15555550127','billing_country'=>'IN','billing_state'=>'MH','billing_city'=>'Example city','billing_postcode'=>'411001','billing_address_1'=>'Synthetic fixture address','ship_to_different_address'=>'0'];
 $checkout=new DSA\Commerce\Checkout_Service($settings,$payload);$listener=[$carts,'capture_checkout_details'];add_action('dsa_checkout_draft_saved',$listener,90,1);$checkout->save_draft($draft,false);
 $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `{$tables[0]}` WHERE id=%d",$id),ARRAY_A);$sealed=$row['checkout_snapshot_enc']??'';$details=Details::decode(Secrets::decrypt($sealed));
 $check($details&&$details['billing']['address_1']===$draft['billing_address_1']&&$details['billing']['phone']===$draft['billing_phone']&&!str_contains($sealed,'Synthetic'),'Native DSA draft captures encrypted contact and address');
 $checkout->save_draft(['billing_address_1'=>'','billing_phone'=>''],false);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `{$tables[0]}` WHERE id=%d",$id),ARRAY_A);$details=Details::decode(Secrets::decrypt($row['checkout_snapshot_enc']));$check($details['billing']['address_1']===''&&$details['billing']['phone']===''&&$row['contact_phone_enc']===null,'Explicit contact and street clears replace the previous snapshot');
 $invalid=$checkout->save_draft([],true);$check(empty($invalid['valid'])&&isset($invalid['errors']['billing_phone']),'Native DSA validation refuses a missing required phone');
 $carts->capture_checkout_review(http_build_query($draft));$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `{$tables[0]}` WHERE id=%d",$id),ARRAY_A);$details=Details::decode(Secrets::decrypt($row['checkout_snapshot_enc']));$check($details['source']==='woocommerce'&&$details['billing']['city']==='Example city','Classic Woo checkout supplies the same private detail model');
 $apiCustomer=new WC_Customer();foreach($draft as$key=>$value){$setter='set_'.$key;if(method_exists($apiCustomer,$setter))$apiCustomer->$setter($value);}$apiCustomer->set_shipping_country('IN');$apiCustomer->set_shipping_state('MH');$apiCustomer->set_shipping_city('Shipping fixture city');$apiCustomer->set_shipping_address_1('Synthetic shipping fixture');$request=new WP_REST_Request('POST','/wc/store/v1/cart/update-customer');$apiListener=[$carts,'capture_store_api_details'];add_action('woocommerce_store_api_cart_update_customer_from_request',$apiListener,90,2);try{do_action('woocommerce_store_api_cart_update_customer_from_request',$apiCustomer,$request);}finally{remove_action('woocommerce_store_api_cart_update_customer_from_request',$apiListener,90);}
 $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM `{$tables[0]}` WHERE id=%d",$id),ARRAY_A);$details=Details::decode(Secrets::decrypt($row['checkout_snapshot_enc']));$check($details['source']==='store-api'&&$details['billing']['phone']===$draft['billing_phone']&&$details['shipping']['address_1']==='Synthetic shipping fixture'&&!empty($details['differentShipping']),'Real Woo Store API action captures its native customer and separate shipping details');
 $check($apiCustomer->get_id()===0&&$request instanceof WP_REST_Request,'Store API fixture creates no saved customer or order');
 wp_set_current_user($actor);$review=$carts->review_record($id);$check(!is_wp_error($review)&&$review['checkoutDetails']['billing']['address_1']===$draft['billing_address_1'],'Order manager sees the actual entered details in review');
 $list=$carts->workspace_rows(['status'=>'active']);$check(!str_contains(wp_json_encode($list),'Synthetic fixture address')&&!str_contains(wp_json_encode($list),'checkout_snapshot'),'Collection projection does not expose private address snapshots');
 wp_set_current_user(0);$check(is_wp_error($carts->review_record($id)),'Anonymous request cannot read private captured details');
 $check($http===0&&$mail===0,'No paid provider, mail, payment or order request');$proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();$proof['line']=$e->getLine();}
finally{
 if(isset($listener))remove_action('dsa_checkout_draft_saved',$listener,90);
 [$wc->session,$wc->customer,$wc->cart]=$originalWoo;$wc->checkout()->checkout_fields=$rawFields;$wpdb->prefix=$prefix;wp_set_current_user($actor);
 foreach(array_reverse($filters)as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);
 foreach($options as$key=>$value)if($value===null)delete_option($key);else update_option($key,$value);
 foreach($tables as$table)$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
 $proof['cleanup']=$wpdb->prefix===$prefix&&get_current_user_id()===$actor&&get_option(DSA_OPTION_SETTINGS)===$settingsBefore;
 foreach($options as$key=>$value)$proof['cleanup']=$proof['cleanup']&&get_option($key,null)===$value;
 $proof['httpAttempts']=$http;$proof['mailAttempts']=$mail;
}
echo wp_json_encode($proof)."\n";if(!$proof['passed']||!$proof['cleanup'])exit(1);
