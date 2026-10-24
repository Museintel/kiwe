<?php
declare(strict_types=1);
require __DIR__.'/test-workflow-apps.php';
$actor=3;$failProduct=false;$alterReadback=false;
class DeliveryProduct extends WC_Product_Simple {
 public function save(){ $id=parent::save(); if(!empty($GLOBALS['refuseWeight']))$GLOBALS['products'][$id]->values['weight']='999'; return $id; }
}
$products[102]=new DeliveryProduct(102);
$product=$store->workspace()['products'][0];
$input=['expected'=>$product['expected'],'virtual'=>false,'weight'=>'1.25','length'=>'20','width'=>'10','height'=>'5','shippingClassId'=>20,'price'=>'999'];
$price=$product['price'];$saved=$store->save_delivery_record(102,$input);
check(is_array($saved)&&$saved['weight']==='1.25'&&$saved['shippingClassId']===20&&$saved['price']===$price,'Delivery writes Woo fields without changing price');
check(is_wp_error($store->save_delivery_record(102,$input)),'Stale delivery editor rejected');
$input['expected']=$saved['expected'];$before=$writes;
foreach([['virtual'=>'false'],['weight'=>'-1'],['length'=>12],['width'=>'1e9'],['height'=>'1,5'],['shippingClassId'=>999]]as$bad)check(is_wp_error($store->save_delivery_record(102,array_replace($input,$bad)))&&$writes===$before,'Invalid delivery field rejected before write: '.array_key_first($bad));
$input['virtual']=true;$saved=$store->save_delivery_record(102,$input);check($saved['virtual']&&$saved['weight']==='1.25','Virtual mode retains stored dimensions');
$input['expected']=$saved['expected'];$input['weight']='';$input['shippingClassId']=0;$saved=$store->save_delivery_record(102,$input);check($saved['weight']===''&&$saved['shippingClassId']===0,'Blank measurement and no shipping class clear native fields');
$input['expected']=$saved['expected'];$opts['woocommerce_weight_unit']='g';check(is_wp_error($store->save_delivery_record(102,$input)),'Store unit change invalidates delivery editor');$opts['woocommerce_weight_unit']='kg';
$failProduct=true;check(is_wp_error($store->save_delivery_record(102,$input)),'Woo save failure is not success');$failProduct=false;
$refuseWeight=true;check(is_wp_error($store->save_delivery_record(102,$input)),'Native measurement readback catches refused value');$refuseWeight=false;
$actor=2;check(is_wp_error($store->save_delivery_record(102,$input)),'Editor role cannot edit product delivery');$actor=3;
$request=new WP_REST_Request('POST',$input,['id'=>102]);$request->headers['x-kiwe-mutation']='';check($store->save_delivery($request)->status===403,'Delivery requires mutation proof');
$request=new WP_REST_Request('GET',$input,['id'=>102]);check($store->save_delivery($request)->status===403,'GET cannot mutate delivery');
$products[102]->type='variable';$input['expected']=DSA\Access\Commerce_App_Service::fingerprint($products[102]);check(is_wp_error($store->save_delivery_record(102,$input)),'Variable product delivery remains outside simple-product editor');
echo "Product delivery boundary checks passed.\n";
