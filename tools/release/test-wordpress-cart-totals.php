<?php
// Native getter/formatting adapter fixtures. No order, customer, coupon, rate or site-option writes.
if (PHP_SAPI !== 'cli' || !defined('KIWE_WORKSPACE_TEST_SITE') || KIWE_WORKSPACE_TEST_SITE !== true || !defined('KIWE_WORKSPACE_EXPECTED_VERSION')) exit(2);
$out = ['schema'=>'kiwe.native-cart-totals.v1','checks'=>[],'passed'=>false,'cleanup'=>false,'scope'=>'Native Woo projection with in-memory component fixtures; no checkout/provider transaction'];
$assert = static function($ok,$label) use (&$out) { $out['checks'][]=['label'=>$label,'passed'=>(bool)$ok]; if (!$ok) throw new RuntimeException($label); };
$wc = WC(); $before = [$wc->cart,$wc->session,$wc->customer]; $shipping=$wc->shipping(); $shippingBefore=$shipping->get_packages(); $settingsBefore=get_option(DSA_OPTION_SETTINGS); $filters=[]; $inclusive=false; $needs=true; $ready=true; $http=0; $mail=0;
$filter=static function($hook,$cb,$argc=1) use (&$filters) { add_filter($hook,$cb,PHP_INT_MAX,$argc); $filters[]=[$hook,$cb]; };
try {
 $manifest=DSA\Runtime\Package_Manifest::verify(true);
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'exact complete runtime');
 $filter('pre_http_request',static function()use(&$http){$http++;return new WP_Error('kiwe_no_delivery','No test transport');});
 $filter('pre_wp_mail',static function()use(&$mail){$mail++;return false;});
 $wc->session=new class extends WC_Session {};
 $wc->customer=new WC_Customer();
 $wc->customer->set_shipping_country('IN'); $wc->customer->set_shipping_state('MH'); $wc->customer->set_shipping_postcode('411001');
 $wc->cart=new WC_Cart();
 $product=new WC_Product_Simple(); $product->set_name('Synthetic cart fixture'); $product->set_price('440'); $product->set_tax_status('none');
 $wc->cart->set_cart_contents(['fixture'=>['data'=>$product,'quantity'=>1,'product_id'=>0,'variation_id'=>0]]);
 $filter('woocommerce_cart_display_prices_including_tax',static function()use(&$inclusive){return $inclusive;});
 $filter('woocommerce_cart_needs_shipping',static function()use(&$needs){return $needs;});
 $filter('woocommerce_cart_ready_to_calc_shipping',static function()use(&$ready){return $ready;});
 $filter('pre_option_woocommerce_ship_to_countries',static fn()=> '');
 $filter('pre_transient_wc_shipping_method_count',static fn()=>['legacy'=>0,'enabled'=>1,'disabled'=>0,'version'=>WC_Cache_Helper::get_transient_version('shipping')]);
 $filter('pre_option_woocommerce_shipping_cost_requires_address',static fn()=> 'no');
 $wc->session->set('chosen_shipping_methods',['fixture']);
 $packages=new ReflectionProperty(WC_Shipping::class,'packages');
 $packages->setValue($shipping,[['rates'=>['fixture'=>new WC_Shipping_Rate('fixture','Synthetic delivery',83.16,[], 'flat_rate')]]]);
 $settings=new DSA\Settings();
 $newPayload=static function()use($settings){$service=new DSA\Commerce\Cart_Payload_Service($settings,new DSA\Commerce\Linked_Products_Service($settings),new DSA\Commerce\Store_Analytics_Service($settings)); (new ReflectionProperty($service,'totals_refreshed'))->setValue($service,true);return $service;};
 $wc->cart->set_totals(['subtotal'=>440,'cart_contents_total'=>440,'shipping_total'=>83.16,'total'=>523.16]);
 $first=$newPayload()->totals_summary();
 $out['firstProjection']=$first;
 $assert($newPayload()->product_total()===$newPayload()->money_text(wc_price(440)),'product-only headline excludes native delivery');
 $assert(count($first['lines'])===2&&$first['lines'][0]['amount']===$newPayload()->money_text(wc_price(440))&&$first['lines'][1]['amount']===$newPayload()->money_text(wc_price(83.16)),'product and delivery components project separately');
 $assert($first['total']===$newPayload()->money_text($wc->cart->get_total())&&str_contains($first['note'],'may change at checkout'),'native payable total and provisional delivery note');
 $wc->cart->set_coupon_discount_totals(['fixture'=>20]);$wc->cart->set_coupon_discount_tax_totals(['fixture'=>2]);
 $fee=$wc->cart->fees_api()->add_fee(['name'=>'Gift wrap','amount'=>5]);$fee->total=5;$fee->tax=.5;
 $negative=$wc->cart->fees_api()->add_fee(['name'=>'Credit','amount'=>-3]);$negative->total=-3;$negative->tax=-.3;
 $wc->cart->set_totals(['subtotal'=>100,'subtotal_tax'=>10,'cart_contents_total'=>80,'cart_contents_tax'=>8,'shipping_total'=>10,'shipping_tax'=>1,'total_tax'=>9.2,'total'=>101.2]);
 $exclusive=$newPayload()->totals_summary();$types=array_column($exclusive['lines'],'type');
 $assert($exclusive['lines'][1]['amount']==='-'.$newPayload()->money_text(wc_price(20))&&$exclusive['lines'][2]['amount']===$newPayload()->money_text(wc_price(5)),'exclusive coupon and positive fee');
 $assert($exclusive['lines'][3]['amount']===$newPayload()->money_text(wc_price(-3))&&in_array('tax',$types,true),'negative fee and separate tax');
 $assert($exclusive['total']===$newPayload()->money_text(wc_price(101.2)),'adapter preserves native total rather than resumming it');
 $productDiscount=$newPayload()->discount_summary(true);
 $assert($newPayload()->product_total()===$newPayload()->money_text(wc_price(80))&&count($productDiscount['lines'])===1&&$productDiscount['lines'][0]['amount']==='-'.$newPayload()->money_text(wc_price(20))&&$productDiscount['beforeDiscount']===$newPayload()->money_text(wc_price(100)),'exclusive product discount excludes both positive and negative fees, shipping and their taxes');
 $inclusive=true;$included=$newPayload()->totals_summary();
 $productDiscount=$newPayload()->discount_summary(true);$assert($newPayload()->product_total()===$newPayload()->money_text(wc_price(88))&&$productDiscount['totalDiscount']==='-'.$newPayload()->money_text(wc_price(22))&&$productDiscount['beforeDiscount']===$newPayload()->money_text(wc_price(110)),'inclusive product discount uses product tax only');
 $assert($included['lines'][0]['amount']===$newPayload()->money_text(wc_price(110))&&$included['lines'][1]['amount']==='-'.$newPayload()->money_text(wc_price(22)),'inclusive products and coupon tax use same basis');
 $assert($included['lines'][2]['amount']===$newPayload()->money_text(wc_price(5.5))&&$included['lines'][3]['amount']===$newPayload()->money_text(wc_price(-3.3))&&$included['lines'][4]['amount']===$newPayload()->money_text(wc_price(11)),'inclusive fees and shipping include their own tax');
 $assert(!in_array('tax',array_column($included['lines'],'type'),true)&&str_contains($included['note'],'Includes '),'included tax is a note, never charged twice');
 $packages->setValue($shipping,[]);$pending=$newPayload()->totals_summary();
 $assert($pending['lines'][4]['amount']==='Calculated at checkout'&&!str_contains($pending['note'],'may change'),'missing shipping is pending, not free');
 $packages->setValue($shipping,[['rates'=>['fixture'=>new WC_Shipping_Rate('fixture','Collection',0,[],'local_pickup')]]]);$wc->cart->set_shipping_total(0);$wc->cart->set_shipping_tax(0);
 $free=$newPayload()->totals_summary();$assert($free['lines'][4]['amount']===$newPayload()->money_text(wc_price(0))&&str_contains($free['lines'][4]['label'],'Collection'),'confirmed zero-cost shipping remains explicit');
 $needs=false;$digital=$newPayload()->totals_summary();$assert(!in_array('shipping',array_column($digital['lines'],'type'),true),'non-shippable cart omits delivery');
 $privateItems=new ReflectionMethod(DSA\Commerce\Cart_Payload_Service::class,'cart_items');$wc->cart->get_cart();$wc->cart->set_cart_contents(['fixture'=>['data'=>$product,'quantity'=>2,'product_id'=>0,'variation_id'=>0,'line_total'=>400]]);
 $items=$privateItems->invoke($newPayload());$assert($items[0]['subtotal']===$newPayload()->money_text($wc->cart->get_product_subtotal($product,2)),'visible line prices use native pre-discount display and quantity');
 $linked=new DSA\Commerce\Linked_Products_Service($settings);$bridge=new DSA\Bricks\Bricks_Integration(new DSA\Element_Registry(),$settings,$linked);
 $realIds=get_posts(['post_type'=>'product','post_status'=>'publish','posts_per_page'=>3,'fields'=>'ids','no_found_rows'=>true]);$assert(count($realIds)>0,'native product fixture available without inserting a record');
 $nativeLimit=max(3,min(100,(int)$settings->get('commerce',[])['bestseller_limit']));$nativeRanking=['source'=>'woo-order-period-v1','limit'=>$nativeLimit,'ids'=>array_map('intval',$realIds)];
 $filter('pre_transient_dsa_bestsellers_year',static fn()=>$nativeRanking);
 $controls=apply_filters('bricks/elements/block/controls',[]);$assert(isset($controls['bestSellers'],$controls['bestSellersPeriod']['options']['year']),'native Bricks authoring keeps saved bestseller control IDs');
 $catalog=$bridge->add_dynamic_tags([]);$assert(in_array('{woo_product_bestseller}',array_column($catalog,'name'),true)&&in_array('{kiwe_product_bestseller}',array_column($catalog,'name'),true),'both old and Kiwe badge tags discoverable');
 $query=$bridge->bestseller_query_vars(['post_type'=>'product','posts_per_page'=>3],['bestSellers'=>true,'bestSellersPeriod'=>'year'],'qa','block');$assert($query['post__in']===array_map('intval',$realIds),'native public query uses shared maintained ranking');
 $actual=new WP_Query($query);$assert(array_map(static fn($p)=>(int)$p->ID,$actual->posts)===array_map('intval',$realIds),'native WP query renders only ranked published products in rank order');
 $post=get_post($realIds[0]);$productForBadge=wc_get_product($realIds[0]);$assert($bridge->render_dynamic_tag('{woo_product_bestseller}',$post)===($productForBadge->is_visible()?'Bestseller':''),'native legacy badge respects Woo product visibility');
 $assert($bridge->render_dynamic_tag('{woo_product_bestseller}',$post,'image')==='','native badge cannot be used as an image');

 // Automatic offers and the FBT rail use native products with request-local metadata.
 $assert(count($realIds)>=2,'two published products available for native offer fixtures');
 [$triggerId,$companionId]=array_map('intval',array_slice($realIds,0,2));
 $fixtureSettings=$settingsBefore;$fixtureSettings['commerce']['upsell_banner_enabled']=false;$fixtureSettings['commerce']['cart_upsell_discounts_enabled']=false;$fixtureSettings['commerce']['linked_products_enabled']=false;$fixtureSettings['commerce']['commerce_recommendations_enabled']=false;$fixtureSettings['commerce']['fbt_enabled']=false;unset($fixtureSettings['commerce']['cart_show_fbt']);
 $fakeSettings=static function()use(&$fixtureSettings){return $fixtureSettings;};$filter('pre_option_'.DSA_OPTION_SETTINGS,$fakeSettings);
 $offerMeta=['_sc_upsell_product_id'=>$companionId,'_sc_upsell_discount'=>10,'_sc_upsell_discount_type'=>'percent','_sc_upsell_discount_scope'=>'both'];
 $filter('get_post_metadata',static function($value,$id,$key,$single)use($triggerId,&$offerMeta){return (int)$id===$triggerId&&array_key_exists($key,$offerMeta)?[$offerMeta[$key]]:$value;},4);
 foreach($realIds as$id)$filter('pre_transient_dsa_cop_'.$id,static fn()=>[]);
 $main=clone wc_get_product($triggerId);$companion=clone wc_get_product($companionId);
 foreach([$main,$companion]as$p){$p->set_virtual(true);$p->set_tax_status('none');$p->set_cross_sell_ids([]);}
 $main->set_price(100);$companion->set_price(50);
 $wc->cart=new WC_Cart();$wc->cart->set_cart_contents(['main'=>['data'=>$main,'product_id'=>$triggerId,'variation_id'=>0,'quantity'=>1,'line_subtotal'=>100,'line_total'=>100]]);
 $offerOwner=new DSA\Commerce\Store_Analytics_Service(new DSA\Settings());
 $offers=$offerOwner->cart_upsell_offers();$assert(count($offers)===1&&$offers[0]['state']==='pending'&&$offers[0]['id']===$companionId,'configured companion works with every obsolete global gate off');
 $assert(!empty($offerOwner->cart_item_data_for_upsell($companionId,$triggerId))&&$offerOwner->cart_item_data_for_upsell($triggerId,$companionId)===[],'only the configured companion gets native offer data');
 $rail=(new DSA\Commerce\Linked_Products_Service(new DSA\Settings()))->cart_recommendations();$assert(count($rail)===1&&$rail[0]['id']===$companionId,'FBT defaults on for a configured companion despite legacy service gates');
 $fixtureSettings['commerce']['cart_show_fbt']=false;$assert((new DSA\Commerce\Linked_Products_Service(new DSA\Settings()))->cart_recommendations()===[],'client display choice hides a configured FBT rail');$fixtureSettings['commerce']['cart_show_fbt']=true;
 $offerMeta['_sc_upsell_product_id']=0;$assert($offerOwner->cart_upsell_offers()===[]&&(new DSA\Commerce\Linked_Products_Service(new DSA\Settings()))->cart_recommendations()===[],'no configured companion or relationship means no invented recommendations');$offerMeta['_sc_upsell_product_id']=$companionId;
 $wc->cart->cart_contents['companion']=['data'=>$companion,'product_id'=>$companionId,'variation_id'=>0,'quantity'=>1,'line_subtotal'=>50,'line_total'=>50];
 $offers=$offerOwner->cart_upsell_offers();$assert(count($offers)===1&&$offers[0]['state']==='eligible','adding the companion changes the same offer to ready for Apply');
 $assert((new DSA\Commerce\Linked_Products_Service(new DSA\Settings()))->cart_recommendations()===[],'FBT excludes products already in the cart');
 $offerMeta['_sc_upsell_discount']=0;$assert($offerOwner->cart_upsell_offers()===[]&&is_wp_error($offerOwner->claim_cart_upsell_discount($companionId,$triggerId)),'zero discount has no invented claim');$offerMeta['_sc_upsell_discount']=10;
 $wc->cart->cart_contents['companion']=array_replace($wc->cart->cart_contents['companion'],['dsa_upsell_trigger_id'=>$triggerId,'dsa_upsell_discount'=>10,'dsa_upsell_discount_type'=>'percent','dsa_upsell_discount_scope'=>'both','dsa_upsell_unit_price'=>50,'dsa_upsell_trigger_unit_price'=>100]);
 $offerOwner->sync_cart_upsell_coupons($wc->cart);$codes=$wc->cart->get_applied_coupons();$assert(count($codes)===1,'native public coupon synchronization creates one configured claim');$nativeCode=reset($codes);$nativeCoupon=new WC_Coupon($nativeCode);
 $assert($offerOwner->validate_upsell_coupon(true,$nativeCoupon),'native virtual coupon validates a current configured claim without global activation');
 $originalActor=get_current_user_id();wp_set_current_user(0);
 try{
  $wc->cart->calculate_totals();$assert(abs($wc->cart->get_discount_total()-15)<0.01&&abs($wc->cart->get_cart_contents_total()-135)<0.01,'native Woo calculates the configured 10 percent pair saving once');
  $wc->cart->calculate_totals();$assert(abs($wc->cart->get_cart_contents_total()-135)<0.01&&$main->get_price()==100&&$companion->get_price()==50,'repeat native recalculation does not compound prices or discounts');
  $offerMeta['_sc_upsell_product_id']=0;$wc->cart->calculate_totals();$assert(!$wc->cart->has_discount($nativeCode)&&abs($wc->cart->get_discount_total())<0.01&&abs($wc->cart->get_cart_contents_total()-150)<0.01,'removing the configured companion retires its prior native cart claim');
 }finally{wp_set_current_user($originalActor);}
 $typed=DSA\Backup\Portable_Store_Recovery::clean_settings(['commerce'=>['cart_show_fbt'=>false],'abandoned_cart'=>[]]);$assert($typed['commerce']['cart_show_fbt']===false,'shared portable Store recovery preserves the explicit FBT display choice');
 remove_filter('pre_option_'.DSA_OPTION_SETTINGS,$fakeSettings,PHP_INT_MAX);

 // Actual native Woo calculations, with request-local products/session only.
 $wc->cart=new WC_Cart();$main=clone $main;$main->set_price(100);$main->set_virtual(true);$main->set_tax_status('none');
 $wc->cart->set_cart_contents(['work'=>['data'=>$main,'product_id'=>$triggerId,'variation_id'=>0,'quantity'=>1]]);
 $mutationPayload=new DSA\Commerce\Cart_Payload_Service($settings,new DSA\Commerce\Linked_Products_Service($settings),new DSA\Commerce\Store_Analytics_Service($settings));
 $mutationCart=$wc->cart;$checkpoint=did_action('woocommerce_after_calculate_totals');$wc->cart->calculate_totals();$nativeCalculated=did_action('woocommerce_after_calculate_totals');
 $mutationPayload->refresh_after_mutation($mutationCart,$checkpoint);$firstMutation=$mutationPayload->cart_data();
 $assert($nativeCalculated===$checkpoint+1&&did_action('woocommerce_after_calculate_totals')===$nativeCalculated,'native completed mutation and complete payload calculate only once');
 $assert($firstMutation['count']===1&&$firstMutation['total']===$mutationPayload->money_text($wc->cart->get_total()),'reused calculation retains authoritative native count and total');
 $checkpoint=did_action('woocommerce_after_calculate_totals');$wc->cart->set_quantity('work',2,true);$nativeCalculated=did_action('woocommerce_after_calculate_totals');$mutationPayload->refresh_after_mutation($mutationCart,$checkpoint);$updated=$mutationPayload->cart_data();
 $assert($nativeCalculated===$checkpoint+1&&did_action('woocommerce_after_calculate_totals')===$nativeCalculated&&$updated['count']===2,'a native quantity change recalculates once and a reused response owner does not retain the old quantity');
 $checkpoint=did_action('woocommerce_after_calculate_totals');$wc->cart->set_quantity('work',3,false);$mutationPayload->refresh_after_mutation($mutationCart,$checkpoint);$fallback=$mutationPayload->cart_data();
 $assert(did_action('woocommerce_after_calculate_totals')===$checkpoint+1&&$fallback['count']===3,'a native mutation without its normal calculation gets exactly one complete fallback calculation');

 $assert($http===0&&$mail===0&&get_option(DSA_OPTION_SETTINGS)===$settingsBefore,'no transport or Kiwe setting changes');$out['passed']=true;
} catch(Throwable $error) { $out['error']=$error->getMessage(); }
finally { foreach($filters as[$hook,$cb])remove_filter($hook,$cb,PHP_INT_MAX); [$wc->cart,$wc->session,$wc->customer]=$before; (new ReflectionProperty(WC_Shipping::class,'packages'))->setValue($shipping,$shippingBefore); $out['cleanup']=get_option(DSA_OPTION_SETTINGS)===$settingsBefore&&$shipping->get_packages()===$shippingBefore; }
$out['passed']=$out['passed']&&$out['cleanup'];echo wp_json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;
