<?php
declare(strict_types=1);
require __DIR__.'/test-coupons.php';
function get_term($id,$taxonomy){return in_array($id,$GLOBALS['terms'],true)?(object)['term_id'=>$id,'name'=>'Group '.$id]:null;}
class TargetProduct extends WC_Product_Simple {public function is_type($type){return $type===$this->type;}}
$actor=3;$caps[3][]='assign_terms';$couponValues[201]['code']='target-promo';$couponValues[201]['custom']=[];
$products[102]=new TargetProduct(102);for($i=401;$i<=421;$i++){$products[$i]=new TargetProduct($i);$products[$i]->values['name']='Target product '.$i;}
$couponValues[201]['product_ids']=range(401,421);$couponValues[201]['excluded_product_ids']=[];
$r=$store->coupon_targets_record(201,['selectedPage'=>2]);check(count($r['selected'])===1&&$r['selected'][0]['id']===421&&!$r['selectedHasNext'],'Target selected records page beyond twenty');
$r=$store->coupon_targets_record(201,[]);check(count($r['selected'])===20&&$r['selectedHasNext']&&!str_contains(json_encode($r),'synthetic@'),'Target projection bounded and customer addresses hidden');
$body=['group'=>'products','operation'=>'add','ids'=>[102],'expected'=>$r['expected']];$out=$store->save_coupon_targets_record(201,$body);
check(is_array($out)&&count($couponValues[201]['product_ids'])===22&&in_array(421,$couponValues[201]['product_ids'],true),'Adding preserves off-page target IDs');
check($couponValues[201]['email_restrictions']===['synthetic@example.invalid']&&$couponValues[201]['amount']==='20'&&$couponValues[201]['usage_count']===3,'Target edits preserve customer restrictions discount and usage');
check(is_wp_error($store->save_coupon_targets_record(201,$body)),'Target stale replay denied');$body['expected']=$out['expected'];$n=$writes;check(is_array($store->save_coupon_targets_record(201,$body))&&$n===$writes,'Already included target skips native save');
$removed=$store->save_coupon_targets_record(201,array_replace($body,['operation'=>'remove','ids'=>[401]]));check(is_array($removed)&&!in_array(401,$couponValues[201]['product_ids'],true)&&in_array(421,$couponValues[201]['product_ids'],true),'Removing one target preserves other pages');
$body['expected']=$removed['expected'];check(is_wp_error($store->save_coupon_targets_record(201,array_replace($body,['group'=>'excludedProducts','ids'=>[102]]))),'Opposite target membership refused');
foreach([['ids'=>['102']],['ids'=>range(1,21)],['ids'=>[]],['group'=>'emails'],['operation'=>'replace'],['amount'=>'1']]as$bad)check(is_wp_error($store->save_coupon_targets_record(201,array_replace($body,$bad))),'Invalid or overbroad targeting payload refused');
check(is_wp_error($store->save_coupon_targets_record(201,array_replace($body,['ids'=>[99999]]))),'Missing product cannot be injected');
$couponValues[201]['product_ids'][]=99999;$r=$store->coupon_targets_record(201,['selectedPage'=>2]);$missing=array_values(array_filter($r['selected'],fn($v)=>$v['id']===99999));check(count($missing)===1&&!$missing[0]['available'],'Missing saved target has an explicit placeholder');
$body['expected']=$r['expected'];check(is_array($store->save_coupon_targets_record(201,array_replace($body,['operation'=>'remove','ids'=>[99999]]))),'Missing saved target can be explicitly removed');
$body['expected']=$store->coupon_record(201)['expected'];$category=$store->save_coupon_targets_record(201,array_replace($body,['group'=>'categories','ids'=>[20]]));check(is_array($category)&&$couponValues[201]['product_categories']===[10,20],'Category targeting uses native coupon categories');
$caps[3]=array_values(array_diff($caps[3],['assign_terms']));check(is_wp_error($store->coupon_targets_record(201,['group'=>'categories']))&&is_wp_error($store->save_coupon_targets_record(201,array_replace($body,['group'=>'categories','ids'=>[20]]))),'Category target task requires taxonomy assignment authority');$caps[3][]='assign_terms';
$body=['group'=>'products','operation'=>'remove','ids'=>[102],'expected'=>$store->coupon_record(201)['expected']];$refuseCoupon=true;check(is_wp_error($store->save_coupon_targets_record(201,$body)),'Refused target save cannot report success');$refuseCoupon=false;
$couponValues[201]['minimum_amount']='25';$body['expected']=$store->coupon_record(201)['expected'];$alterCoupon=true;check(is_wp_error($store->save_coupon_targets_record(201,$body)),'Hook-altered unrelated rules fail target readback');$alterCoupon=false;
$couponValues[201]['custom']=[['key'=>'extension','value'=>'managed']];$body['expected']=$store->coupon_record(201)['expected'];check(is_wp_error($store->save_coupon_targets_record(201,$body)),'Managed target editing denied');
$actor=2;check(is_wp_error($store->coupon_targets_record(201,[]))&&is_wp_error($store->save_coupon_targets_record(201,$body)),'Editor denied target reads and writes');$actor=3;
$request=new WP_REST_Request('POST',$body,['id'=>201]);$request->headers['x-kiwe-mutation']='';check($store->save_coupon_targets($request)->status===403,'Target write needs mutation proof');
echo "Coupon targeting checks passed.\n";

function map_meta_cap($cap,$actor,$id){return $GLOBALS['variationMapped']??['edit_product'];}
$couponValues[201]['custom']=[];$products[430]=new TargetProduct(430);$products[430]->type='variation';$products[430]->values['parent_id']=102;$deniedObjects=['edit_post'=>[430]];
$variationBody=['group'=>'products','operation'=>'add','ids'=>[430],'expected'=>$store->coupon_record(201)['expected']];$variationOut=$store->save_coupon_targets_record(201,$variationBody);check(is_array($variationOut)&&in_array(430,$couponValues[201]['product_ids'],true),'Unmapped variation uses accessible parent for targeting without global primitive');
$variationBody['expected']=$variationOut['expected'];$variationMapped=['do_not_allow'];check(is_wp_error($store->save_coupon_targets_record(201,$variationBody)),'Explicit native variation denial cannot use parent fallback');
$variationMapped=['custom_variation_authority'];check(is_wp_error($store->save_coupon_targets_record(201,$variationBody)),'Custom variation mapping retains its permission requirement');
$variationMapped=['edit_product'];$deniedObjects['edit_post'][]=102;check(is_wp_error($store->save_coupon_targets_record(201,$variationBody)),'Parent denial blocks unmapped variation selection');unset($deniedObjects);
