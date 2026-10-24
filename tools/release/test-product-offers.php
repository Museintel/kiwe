<?php
declare(strict_types=1);
require __DIR__.'/test-workflow-apps.php';
$actor=3;$failProduct=false;$alterReadback=false;$products[102]=new WC_Product_Simple(102);$products[103]=new WC_Product_Simple(103);$products[103]->values['status']='publish';
$offer=$store->offer_record(102);$price=$products[102]->values['regular_price'];
$body=['expected'=>$offer['expected'],'productId'=>103,'discount'=>'12.50','discountType'=>'percent','discountScope'=>'both'];
$saved=$store->save_offer_record(102,$body);
check(is_array($saved)&&$saved['productId']===103&&$saved['discount']==='12.5'&&get_post_meta(102,'_sc_upsell_discount_scope')==='both','Cart offer uses existing Kiwe discount metadata');
check($products[102]->values['regular_price']===$price&&$products[102]->values['stock_quantity']===8,'Cart offer preserves native price and stock');
check(is_wp_error($store->save_offer_record(102,$body)),'Offer stale replay denied');
foreach([['productId'=>102],['productId'=>999],['productId'=>'103'],['discount'=>'101'],['discount'=>'-1'],['discount'=>'1e2'],['discountType'=>'unknown'],['discountScope'=>'cart']]as$bad){$input=array_replace($body,['expected'=>$saved['expected']],$bad);$beforeWrites=$writes;check(is_wp_error($store->save_offer_record(102,$input))&&$writes===$beforeWrites,'Invalid offer rejected before metadata writes '.json_encode($bad));}
$body['expected']=$saved['expected'];$body['discountType']='fixed';$body['discount']='125.00';$saved=$store->save_offer_record(102,$body);check(is_array($saved)&&$saved['discount']==='125','Fixed amount is not capped at 100 percent');
$products[102]->values['name']='Native edit';check(is_wp_error($store->save_offer_record(102,array_replace($body,['expected'=>$saved['expected']]))),'Native product change invalidates offer');
$saved=$store->offer_record(102);$body=array_replace($body,['expected'=>$saved['expected'],'productId'=>0]);$saved=$store->save_offer_record(102,$body);check(is_array($saved)&&$saved['productId']===0&&$saved['discount']==='125','Removing companion preserves discount configuration');
$refuseOfferMeta=true;$failed=$store->save_offer_record(102,array_replace($body,['expected'=>$saved['expected'],'discount'=>'15']));check(is_wp_error($failed)&&$failed->get_error_code()==='kiwe_commerce_offer_readback','Refused offer metadata returns uncertainty');$refuseOfferMeta=false;
$actor=2;check(is_wp_error($store->offer_record(102)),'Editor cannot read product offers');check(is_wp_error($store->save_offer_record(102,$body)),'Editor cannot write product offers');$actor=3;
$request=new WP_REST_Request('POST',$body,['id'=>102]);$request->headers['x-kiwe-mutation']='';check($store->save_offer($request)->status===403,'Offer write requires mutation proof');
check($store->save_offer(new WP_REST_Request('GET',$body,['id'=>102]))->status===403,'GET cannot mutate offer');
$products[102]->type='grouped';check(is_wp_error($store->offer_record(102)),'Grouped and third-party bundle editors stay separate');
echo "Product offer boundary checks passed.\n";
