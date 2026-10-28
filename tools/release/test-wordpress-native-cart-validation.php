<?php
/** CLI-only actual Woo cart fixture. The common owner isolates all database writes. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SEO_NATIVE_TEST')||KIWE_SEO_NATIVE_TEST!==true)throw new RuntimeException('Owned native cart fixture required');
function kiwe_pt3_native_run(array $profile):array{
 global $wpdb;
 $proof=['passed'=>false,'cleanup'=>false,'checks'=>[],'observations'=>['profile'=>$profile['id']]];
 $check=static function($ok,string$name)use(&$proof){$proof['checks'][$name]=(bool)$ok;if(!$ok)throw new RuntimeException($name);};
 $callbacks=[];
 $filter=static function($hook,$callback,$argc=1)use(&$callbacks){add_filter($hook,$callback,PHP_INT_MAX,$argc);$callbacks[]=[$hook,$callback];return$callback;};
 $remove=static function($hook,$callback){remove_filter($hook,$callback,PHP_INT_MAX);};
 try{
  foreach(['litespeed_purge_all','litespeed_purge_post','litespeed_purge_url']as$hook)remove_all_actions($hook);
  $settingsValue=unserialize(base64_decode($profile['settingsValue'],true),['allowed_classes'=>false]);
  $check(is_array($settingsValue)&&hash_equals($profile['settingsHash'],hash('sha256',serialize($settingsValue))),'Exact captured store settings loaded only into isolated native options');
  update_option(DSA_OPTION_SETTINGS,$settingsValue,false);update_option('kiwe_store_app_enabled_v1','1',false);
  foreach($profile['checkoutOptions']as$name=>$value){if(!in_array($name,['woocommerce_enable_guest_checkout','woocommerce_checkout_phone_field','woocommerce_checkout_company_field','woocommerce_checkout_address_2_field'],true))throw new RuntimeException('Only native checkout profile options');update_option($name,$value,false);}
  $check(DSA\Apps\Store_App_Service::enabled()&&WC_VERSION==='11.2.1','Actual active Store and qualified Woo engine');
  $settings=new DSA\Settings();
  $make=static function()use($settings){$analytics=new DSA\Commerce\Store_Analytics_Service($settings);$payload=new DSA\Commerce\Cart_Payload_Service($settings,new DSA\Commerce\Linked_Products_Service($settings),$analytics);return[new DSA\Rest\Cart_Controller($payload,$analytics),$payload];};
  $request=static function(array$params):WP_REST_Request{$r=new WP_REST_Request('POST');foreach($params as$key=>$value)$r->set_param($key,$value);return$r;};
  $add=static function(int$id,int$qty=1,array$extra=[])use($make,$request){return$make()[0]->add_item($request(['productId'=>$id,'quantity'=>$qty]+$extra));};
  $update=static function(string$key,int$qty)use($make,$request){return$make()[0]->update_item($request(['key'=>$key,'quantity'=>$qty]));};
  $ok=static fn($r)=>$r instanceof WP_REST_Response&&$r->get_status()===200&&!empty($r->get_data()['ok']);
  $deny=static fn($r)=>is_wp_error($r)&&($r->get_error_data()['status']??0)===409;
  $product=static function(string$name,int$stock=5,string$backorders='no',bool$managed=true,bool$sold=false):WC_Product_Simple{
   $p=new WC_Product_Simple();$p->set_name('Synthetic PT3 '.$name);$p->set_status('publish');$p->set_regular_price('100');$p->set_virtual(true);$p->set_tax_status('none');$p->set_manage_stock($managed);$p->set_stock_quantity($stock);$p->set_backorders($backorders);$p->set_stock_status($stock>0?'instock':($backorders!=='no'?'onbackorder':'outofstock'));$p->set_sold_individually($sold);$p->save();return$p;
  };
  $simple=$product('ordinary');$unmanaged=$product('unmanaged',0,'no',false);$unmanaged->set_stock_status('instock');$unmanaged->save();$sold=$product('single',3,'no',true,true);
  $actor=(int)$wpdb->get_var('SELECT COALESCE(MAX(ID),0)+1 FROM '.$wpdb->users);
  $check(false!==$wpdb->insert($wpdb->users,['ID'=>$actor,'user_login'=>'synthetic-pt3-customer','user_pass'=>wp_hash_password(wp_generate_password(24)),'user_email'=>'pt3@example.invalid','user_registered'=>current_time('mysql',true),'display_name'=>'Synthetic PT3 customer']),'Synthetic native customer seeded only in temporary tables');
  update_user_meta($actor,$wpdb->prefix.'capabilities',['customer'=>true]);clean_user_cache($actor);
  wp_set_current_user(0);wc_load_cart();$cart=WC()->cart;
  $reset=static function()use($cart){$cart->empty_cart();wc_clear_notices();};
  $stockBefore=get_post_meta($simple->get_id(),'_stock',true);
  foreach([0,$actor]as$user){
   $reset();wp_set_current_user($user);WC()->session=new WC_Session_Handler();WC()->session->init();WC()->customer=new WC_Customer($user,true);$label=$user?'signed-in':'guest';$calls=[];
   $reject=$filter('woocommerce_add_to_cart_validation',static function(...$args)use(&$calls){$calls[]=$args;wc_add_notice('<b>Native extension eligibility.</b>','error');return false;},6);
   $response=$add($simple->get_id(),2);
   $check($deny($response)&&$response->get_error_message()==='Native extension eligibility.'&&$cart->is_empty(),$label.' native extension add denial leaves empty cart');
   $check(count($calls)===1&&count($calls[0])===6&&array_slice($calls[0],0,5)===[true,$simple->get_id(),2,0,[]],$label.' six native add validation arguments');
   $remove('woocommerce_add_to_cart_validation',$reject);
   $before=did_action('woocommerce_after_calculate_totals');$response=$add($simple->get_id(),1);$data=$ok($response)?$response->get_data():[];$key=$data['item']['key']??'';
   $check($ok($response)&&$cart->get_cart_contents_count()===1&&$key!=='',$label.' successful actual Kiwe native add');
   $check(did_action('woocommerce_after_calculate_totals')===$before+1,$label.' successful add calculates once');
   $reject=$filter('woocommerce_update_cart_validation',static function($valid,$actualKey,$values,$qty)use($key,$check){$check($valid===true&&$actualKey===$key&&$values['quantity']===1&&$qty===2,'Native update filter sees actual old row and requested replacement');wc_add_notice('<b>Native extension quantity.</b>','error');return false;},4);
   $response=$update($key,2);$check($deny($response)&&$response->get_error_message()==='Native extension quantity.'&&$cart->get_cart_contents_count()===1,$label.' native extension quantity denial leaves prior cart');$remove('woocommerce_update_cart_validation',$reject);
   $before=did_action('woocommerce_after_calculate_totals');$response=$update($key,2);
   $check($ok($response)&&$cart->get_cart_contents_count()===2&&did_action('woocommerce_after_calculate_totals')===$before+1,$label.' actual quantity update calculates once');
   [$controller,$payload]=$make();$checkout=new DSA\Commerce\Checkout_Service($settings,$payload);$contract=$checkout->contract();
   $check(!empty($contract['available'])&&empty($contract['accountRequired'])&&!empty($contract['checkoutUrl']),$label.' configured native checkout remains available without order');
   $phone=null;foreach($contract['groups']as$fields)foreach($fields as$field)if(($field['key']??'')==='billing_phone')$phone=$field;
   $check(is_array($phone)&&!empty($phone['required']),$label.' existing required billing phone remains required');
   $response=$update($key,0);$check($ok($response)&&$cart->is_empty(),$label.' native removal remains available');
  }
  $reset();wp_set_current_user(0);WC()->session=new WC_Session_Handler();WC()->session->init();WC()->customer=new WC_Customer(0,true);
  foreach(['yes','notify']as$policy)foreach([0,-3]as$stock){
   $reset();$p=$product('backorder '.$policy.' '.$stock,$stock,$policy);$response=$add($p->get_id(),2);$key=$ok($response)?$response->get_data()['item']['key']:'';
   $check($ok($response)&&$cart->get_cart_contents_count()===2,'Native backorder '.$policy.' at '.$stock.' admits add');
   $response=$update($key,4);$check($ok($response)&&$cart->get_cart_contents_count()===4,'Native backorder '.$policy.' at '.$stock.' admits update');
   $items=$response->get_data()['cart']['items'];$check($items[0]['maxQuantity']===99,'Backorder '.$policy.' at '.$stock.' retains working plus control');
   $check((int)wc_get_product($p->get_id())->get_stock_quantity()===$stock,'Cart does not reserve or decrement backorder inventory '.$policy.' '.$stock);
  }
  foreach([0,-2]as$stock){$reset();$p=$product('no backorders '.$stock,$stock);$check($deny($add($p->get_id()))&&$cart->is_empty(),'Native no-backorder stock '.$stock.' rejects add');}
  $reset();$response=$add($simple->get_id(),3);$key=$response->get_data()['item']['key'];$response=$add($simple->get_id(),3);
  $check($deny($response)&&$cart->get_cart_contents_count()===3,'Native cumulative add stock cannot be bypassed');
  $check($deny($update($key,6))&&$cart->get_cart_contents_count()===3,'Native positive update cannot exceed stock');
  $check($ok($update($key,2))&&$cart->get_cart_contents_count()===2,'Lowering quantity within native stock remains valid');
  $reset();$check($ok($add($unmanaged->get_id(),4))&&$cart->get_cart_contents_count()===4,'Native unmanaged product admits ordinary quantities');
  $reset();$response=$add($sold->get_id(),4);$key=$response->get_data()['item']['key'];
  $check($ok($response)&&$cart->get_cart_contents_count()===1,'Native sold-individually add normalization retained');
  $check($deny($add($sold->get_id()))&&$cart->get_cart_contents_count()===1,'Native sold-individually duplicate rejected');
  $check($deny($update($key,2))&&$cart->get_cart_contents_count()===1,'Native sold-individually update rejected truthfully');
  $reset();
  $parent=new WC_Product_Variable();$parent->set_name('Synthetic PT3 variable');$parent->set_status('publish');$parent->set_manage_stock(true);$parent->set_stock_quantity(5);$parent->set_stock_status('instock');
  $attribute=new WC_Product_Attribute();$attribute->set_name('Size');$attribute->set_options(['Small','Large']);$attribute->set_visible(true);$attribute->set_variation(true);$parent->set_attributes([$attribute]);$parent->save();
  $variations=[];foreach(['Small','Large']as$size){$v=new WC_Product_Variation();$v->set_parent_id($parent->get_id());$v->set_status('publish');$v->set_attributes(['size'=>$size]);$v->set_regular_price('100');$v->set_virtual(true);$v->set_tax_status('none');$v->set_manage_stock(false);$v->save();$variations[]=$v;}
  WC_Product_Variable::sync($parent->get_id());
  $seen=[];$observe=$filter('woocommerce_add_to_cart_validation',static function(...$args)use(&$seen){$seen[]=$args;return true;},6);
  $first=$add($variations[0]->get_id(),2);$second=$add($parent->get_id(),2,['variationId'=>$variations[1]->get_id()]);$remove('woocommerce_add_to_cart_validation',$observe);
  $check($ok($first)&&$ok($second)&&$cart->get_cart_contents_count()===4,'Actual native variation IDs and parent-ID request both add');
  $check(count($seen)===2&&$seen[0][1]===$parent->get_id()&&$seen[0][3]===$variations[0]->get_id()&&$seen[0][4]===['attribute_size'=>'Small'],'Actual variation validation uses parent variation and native attributes');
  $key=$first->get_data()['item']['key'];$response=$update($key,4);
  $check($deny($response)&&($response->get_error_data()['max_qty']??null)===3&&$cart->get_cart_contents_count()===4,'Parent-managed stock includes sibling variation quantity');
  $items=$second->get_data()['cart']['items'];$check(count($items)===2&&$items[0]['maxQuantity']===3&&$items[1]['maxQuantity']===3,'Variation plus controls expose remaining shared stock');
  $check($ok($update($key,3))&&$cart->get_cart_contents_count()===5,'Shared parent stock accepts exact replacement boundary');
  $check($deny($add($variations[0]->get_id()))&&$cart->get_cart_contents_count()===5,'Native shared parent stock also guards repeated additions');
  $reset();$grouped=new WC_Product_Grouped();$grouped->set_name('Synthetic PT3 grouped');$grouped->set_status('publish');$grouped->set_children([$simple->get_id(),$unmanaged->get_id()]);$grouped->save();
  $check($deny($add($grouped->get_id()))&&$cart->is_empty(),'Grouped parent does not invent unselected native child choices');
  $check($ok($add($simple->get_id())),'Native grouped child remains independently purchasable');
  $reset();$check($ok($add($unmanaged->get_id())),'Native unmanaged row seeded for manual out-of-stock transition');
  $key=array_key_first($cart->get_cart());$out=wc_get_product($unmanaged->get_id());$out->set_stock_status('outofstock');$out->save();$cart->cart_contents[$key]['data']=wc_get_product($unmanaged->get_id());
  $check($deny($update($key,2)),'Newly out-of-stock row rejects positive quantity update');
  $reject=$filter('woocommerce_update_cart_validation',static fn()=>false,4);
  $check($ok($update($key,0))&&$cart->is_empty(),'Explicit native removal survives stock and purchase eligibility restrictions');$remove('woocommerce_update_cart_validation',$reject);
  $check(get_post_meta($simple->get_id(),'_stock',true)===$stockBefore,'Cart validation and mutation never reduce actual inventory');
  $proof['observations']['nativeCartClass']=get_class($cart);$proof['observations']['nativeStockClass']=get_class($simple);$proof['observations']['wordpress']=$GLOBALS['wp_version'];$proof['observations']['woo']=WC_VERSION;$proof['observations']['bricks']=defined('BRICKS_VERSION')?BRICKS_VERSION:null;
  $check(($GLOBALS['kiwe_seo_test_http_calls']??0)===0&&($GLOBALS['kiwe_seo_test_mail_calls']??0)===0,'No provider request mail payment or campaign');
  $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_refund')")===0,'No native order created');
  $proof['passed']=true;
 }catch(Throwable$e){$proof['error']=$e->getMessage();$proof['frames']=array_map(static fn($f)=>['file'=>basename($f['file']??''),'line'=>$f['line']??0,'function'=>$f['function']??''],array_slice($e->getTrace(),0,6));}
 finally{foreach($callbacks as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);try{if(WC()->cart)WC()->cart->empty_cart();wp_set_current_user(0);do_action('shutdown');remove_all_actions('shutdown');}catch(Throwable$e){$proof['passed']=false;$proof['shutdownError']=$e->getMessage();}kiwe_seo_test_cleanup($proof);}
 $proof['passed']=$proof['passed']&&$proof['cleanup']&&!in_array(false,$proof['checks'],true);return$proof;
}
