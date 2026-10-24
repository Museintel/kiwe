<?php
require __DIR__.'/test-coupon-targets.php';
function sanitize_email($v){return preg_replace('/[^a-zA-Z0-9!#$%&\x27*+\-\/=?^_`{|}~@.]/','',$v);}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL)!==false;}
$actor=3;$deniedObjects=[];$couponValues[201]['custom']=[];$couponValues[201]['email_restrictions']=array_map(static fn($n)=>'person'.$n.'@example.invalid',range(1,21));
$before=$store->coupon_record(201);$page=$store->coupon_customers_record(201,['page'=>2]);
check(count($page['items'])===1&&$page['items'][0]==='person21@example.invalid'&&!$page['hasNext']&&$page['count']===21,'Customer rules page beyond twenty');
check(!str_contains(json_encode($before),'person21@'),'Ordinary coupon summary does not expose email rules');
$found=$store->coupon_customers_record(201,['search'=>'person21']);check($found['items']===['person21@example.invalid'],'Search finds an off-page saved email rule');
$body=['operation'=>'add','rules'=>[' NEW@Example.Invalid ','*@company.invalid'],'expected'=>$before['expected']];$saved=$store->save_coupon_customers_record(201,$body);
check(is_array($saved)&&count($couponValues[201]['email_restrictions'])===23&&in_array('person21@example.invalid',$couponValues[201]['email_restrictions'],true)&&in_array('new@example.invalid',$couponValues[201]['email_restrictions'],true),'Add normalizes rules and preserves off-page choices');
check($saved['amount']===$before['amount']&&$saved['restrictionCounts']['productIds']===$before['restrictionCounts']['productIds']&&$saved['usageCount']===$before['usageCount'],'Customer rule save preserves coupon policy');
check(is_wp_error($store->save_coupon_customers_record(201,$body)),'Stale customer rule replay refused');$body['expected']=$saved['expected'];$n=$writes;
check(is_array($store->save_coupon_customers_record(201,$body))&&$n===$writes,'Duplicate additions skip persistence');
foreach([['rules'=>[]],['rules'=>['bad']],['rules'=>['x@*.invalid']],['rules'=>['a b@example.invalid']],['rules'=>[9]],['rules'=>array_fill(0,21,'x@example.invalid')],['operation'=>'replace'],['amount'=>'50']]as$bad)check(is_wp_error($store->save_coupon_customers_record(201,array_replace($body,$bad))),'Malformed or overbroad customer change refused');
$removed=$store->save_coupon_customers_record(201,['operation'=>'remove','rules'=>['person21@example.invalid'],'expected'=>$saved['expected']]);
check(is_array($removed)&&count($couponValues[201]['email_restrictions'])===22&&!in_array('person21@example.invalid',$couponValues[201]['email_restrictions'],true)&&in_array('person1@example.invalid',$couponValues[201]['email_restrictions'],true),'Remove changes only selected email rules');
check(is_wp_error($store->save_coupon_customers_record(201,['operation'=>'remove','rules'=>['missing@example.invalid'],'expected'=>$removed['expected']])),'Missing removal is rejected');
$body['expected']=$removed['expected'];$body['rules']=['another@example.invalid'];$refuseCoupon=true;check(is_wp_error($store->save_coupon_customers_record(201,$body)),'Refused native customer save cannot report success');$refuseCoupon=false;
$couponValues[201]['minimum_amount']='17';$body['expected']=$store->coupon_record(201)['expected'];$alterCoupon=true;check(is_wp_error($store->save_coupon_customers_record(201,$body)),'Altered native coupon policy cannot report success');$alterCoupon=false;
$couponValues[201]['custom']=[['key'=>'owner','value'=>'extension']];check(is_wp_error($store->coupon_customers_record(201))&&is_wp_error($store->save_coupon_customers_record(201,$body)),'Managed coupon email rules cannot be read or changed');$couponValues[201]['custom']=[];
$actor=2;check(is_wp_error($store->coupon_customers_record(201))&&is_wp_error($store->save_coupon_customers_record(201,$body)),'Editor cannot read or change customer rules');$actor=3;
$request=new WP_REST_Request('POST',$body,['id'=>201]);$request->headers['x-kiwe-mutation']='';check($store->save_coupon_customers($request)->status===403,'Customer mutation requires proof');
$couponValues[201]['email_restrictions']=['*@company.invalid'];$b=$store->coupon_record(201);$empty=$store->save_coupon_customers_record(201,['operation'=>'remove','rules'=>['*@company.invalid'],'expected'=>$b['expected']]);check(is_array($empty)&&$couponValues[201]['email_restrictions']===[],'Last-rule removal clears only native email restriction');
echo "Coupon customer checks passed.\n";
