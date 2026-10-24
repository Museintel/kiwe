<?php
declare(strict_types=1);
require __DIR__.'/test-workflow-apps.php';

function wp_timezone(){return new DateTimeZone($GLOBALS['couponZone']??'Asia/Kolkata');}
function get_post_type_object($name){return (object)['cap'=>(object)['create_posts'=>'edit_shop_coupons']];}
function wc_format_coupon_code($code){return strtolower($code);}
function wc_get_coupon_id_by_code($code){foreach($GLOBALS['couponValues']??[]as$id=>$v)if($v['code']===$code&&($v['status']??'publish')==='publish')return $id;return 0;}
class WP_Query {public array $posts=[];public function __construct($args){$GLOBALS['couponQuery']=$args;foreach($GLOBALS['couponValues']??[]as$id=>$v)if($v['code']===($args['title']??'')&&!in_array($id,$args['post__not_in']??[],true))$this->posts[]=$id;$this->posts=array_slice($this->posts,0,$args['posts_per_page']);}}
class WC_Coupon {
 public array $values;public int $id;
 public function __construct($id=0){$this->id=is_string($id)?wc_get_coupon_id_by_code($id):$id;$this->values=$GLOBALS['couponValues'][$this->id]??$GLOBALS['couponDefaults'];if($this->values['limit_usage_to_x_items']===0)$this->values['limit_usage_to_x_items']=null;}
 public function get_id(){return $this->id;}
 public function get_meta_data(){return array_map(static fn($v)=>new class($v){public function __construct(public array $v){}public function get_data(){return $this->v;}},$this->values['custom']);}
 public function __call($name,$args){$key=substr($name,4);if(str_starts_with($name,'get_'))return $this->values[$key];if(str_starts_with($name,'set_')){$this->values[$key]=$key==='date_expires'&&$args[0]!==null?new DateTimeImmutable('@'.$args[0]):$args[0];return;}throw new RuntimeException('Unknown fake coupon operation');}
 public function save(){if(!empty($GLOBALS['refuseCoupon']))return 0;$GLOBALS['writes']++;if(!$this->id)$this->id=count($GLOBALS['couponValues'])+300;$GLOBALS['posts'][$this->id]=(object)['ID'=>$this->id,'post_type'=>'shop_coupon','post_status'=>$this->values['status']??'publish'];if(!empty($GLOBALS['alterCoupon']))$this->values['minimum_amount']='99';$GLOBALS['couponValues'][$this->id]=$this->values;return $this->id;}
}
$actor=3;$caps[3]=array_merge($caps[3],['edit_shop_coupons','edit_others_shop_coupons']);
$posts[201]=(object)['ID'=>201,'post_type'=>'shop_coupon','post_status'=>'publish'];
$couponValues=[201=>['code'=>'welcome','discount_type'=>'percent','amount'=>'10','description'=>'Welcome','virtual'=>false,'custom'=>[],'date_expires'=>null,'usage_count'=>2,'usage_limit'=>10,'usage_limit_per_user'=>1,'limit_usage_to_x_items'=>null,'individual_use'=>true,'free_shipping'=>false,'exclude_sale_items'=>true,'minimum_amount'=>'25','maximum_amount'=>'','product_ids'=>[102],'excluded_product_ids'=>[],'product_categories'=>[10],'excluded_product_categories'=>[],'email_restrictions'=>['synthetic@example.invalid']]];
$couponDefaults=array_replace($couponValues[201],['code'=>'','amount'=>'0','status'=>'draft','usage_count'=>0,'usage_limit'=>0,'usage_limit_per_user'=>0,'minimum_amount'=>'','product_ids'=>[],'product_categories'=>[],'email_restrictions'=>[]]);$couponValues[201]['status']='publish';
$r=$store->coupon_record(201);check(is_array($r)&&$r['editable']&&$r['restrictionCounts']['emailRestrictions']===1&&!str_contains(json_encode($r),'synthetic@'),'Coupon projection exposes counts without email restrictions');
$body=['amount'=>'15','description'=>'Changed','expected'=>$r['expected']];$out=$store->save_coupon_record(201,$body);check(is_array($out)&&$out['amount']==='15'&&$couponValues[201]['minimum_amount']==='25'&&$couponValues[201]['product_ids']===[102]&&$couponValues[201]['usage_count']===2,'Native coupon edit preserves limits usage and product rules');
check(is_wp_error($store->save_coupon_record(201,$body)),'Coupon stale replay denied');$body['expected']=$out['expected'];$n=$writes;$out=$store->save_coupon_record(201,$body);check(is_array($out)&&$writes===$n,'Unchanged coupon skips native save');
foreach(['0','-1','101','1e2',[],null]as$bad)check(is_wp_error($store->save_coupon_record(201,array_replace($body,['amount'=>$bad]))),'Invalid coupon amount denied');
check(is_wp_error($store->save_coupon_record(201,$body+['usageLimit'=>0])),'Coupon scope excludes usage limit changes');
$couponValues[201]['usage_count']=3;check(is_wp_error($store->save_coupon_record(201,$body)),'Changed usage invalidates coupon snapshot');
$body['expected']=$store->coupon_record(201)['expected'];$body['amount']='20';$refuseCoupon=true;check(is_wp_error($store->save_coupon_record(201,$body)),'Refused native coupon save detected');$refuseCoupon=false;
$alterCoupon=true;check(is_wp_error($store->save_coupon_record(201,$body)),'Altered restriction readback cannot report success');$alterCoupon=false;
foreach(['code'=>'kiwe-pair-1-2','discount_type'=>'extension_credit','virtual'=>true,'custom'=>[['key'=>'extension_owner','value'=>'fixture']]]as$key=>$value){$previous=$couponValues[201][$key];$couponValues[201][$key]=$value;$r=$store->coupon_record(201);check(!$r['editable']&&is_wp_error($store->save_coupon_record(201,['amount'=>'9','description'=>'No','expected'=>$r['expected']])),'Managed coupon denied: '.$key);$couponValues[201][$key]=$previous;}
$actor=2;check(is_wp_error($store->coupon_record(201)),'Editorial staff denied coupon');$actor=3;$request=new WP_REST_Request('POST',[],['id'=>201]);$request->headers['x-kiwe-mutation']='';check($store->save_coupon($request)->status===403,'Coupon mutation requires proof');
echo "Coupon focused checks passed.\n";

// RC115: creation and policy changes use native fields with failure boundaries.
$new=['name'=>'NEW-PROMO','type'=>'percent','amount'=>'7.5','description'=>'Draft offer'];
$created=$store->create_coupon_record($new);check(is_array($created)&&$created['status']==='draft'&&$created['name']==='new-promo'&&$created['amount']==='7.5','New coupon is a native draft');
check(is_wp_error($store->create_coupon_record($new)),'Duplicate creation and retry refused');
foreach(['kiwe-generated','x','invalid code']as$code)check(is_wp_error($store->create_coupon_record(array_replace($new,['name'=>$code]))),'Reserved or invalid new code refused');
check(is_wp_error($store->create_coupon_record($new+['status'=>'publish'])),'Creation cannot inject activation');
$id=$created['id'];$fields=['status','expiresLocal','usageLimit','perUserLimit','minimum','maximum','individualUse','excludeSaleItems'];
$body=array_intersect_key($created,array_flip($fields));$body=array_replace($body,['status'=>'publish','expiresLocal'=>'2027-01-02T12:30:00','usageLimit'=>20,'perUserLimit'=>2,'minimum'=>'50','maximum'=>'500','expected'=>$created['expected']]);
check(is_wp_error($store->save_coupon_policy_record($id,$body)),'Coupon activation needs native publication permission');$caps[3][]='publish_shop_coupons';
$before=$store->coupon_record($id);$body['expected']=$before['expected'];$result=$store->save_coupon_policy_record($id,$body);
check(is_array($result)&&$result['status']==='publish'&&$result['usageLimit']===20&&$result['expiresAt']===(new DateTimeImmutable('2027-01-02 12:30:00',wp_timezone()))->getTimestamp(),'Policy activation expiry and usage reach native fields');
check($result['amount']==='7.5'&&$result['name']==='new-promo'&&$result['restrictionCounts']['emailRestrictions']===0,'Policy preserves discount identity and targeting');
check(is_wp_error($store->save_coupon_policy_record($id,$body)),'Policy stale replay denied');$body['expected']=$result['expected'];$n=$writes;
check(is_array($store->save_coupon_policy_record($id,$body))&&$n===$writes,'Unchanged policy skips persistence');
foreach([['usageLimit'=>'2'],['perUserLimit'=>-1],['minimum'=>'501'],['expiresLocal'=>'2027-02-30T12:00:00'],['individualUse'=>'yes'],['status'=>'trash'],['shippingMethod'=>'free_shipping']]as$bad)check(is_wp_error($store->save_coupon_policy_record($id,array_replace($body,$bad))),'Malformed or unsupported coupon policy refused');
$couponZone='America/New_York';$body['expected']=$store->coupon_record($id)['expected'];check(is_wp_error($store->save_coupon_policy_record($id,array_replace($body,['expiresLocal'=>'2027-11-07T01:30:00']))),'Ambiguous coupon expiry refused');$couponZone='Asia/Kolkata';$body['expected']=$store->coupon_record($id)['expected'];
$refuseCoupon=true;check(is_wp_error($store->save_coupon_policy_record($id,array_replace($body,['status'=>'draft']))),'Refused policy save cannot report success');$refuseCoupon=false;
$paused=$store->save_coupon_policy_record($id,array_replace($body,['status'=>'draft','expiresLocal'=>'']));check(is_array($paused)&&$paused['status']==='draft'&&$paused['expiresAt']===null,'Pause and expiry removal reach native fields');
$couponValues[$id]['custom']=[['key'=>'extension_rule','value'=>1]];$managed=$store->coupon_record($id);check(is_wp_error($store->save_coupon_policy_record($id,array_replace($body,['expected'=>$managed['expected']]))),'Managed coupon policy stays protected');
$actor=2;check(is_wp_error($store->create_coupon_record(array_replace($new,['name'=>'editor-promo'])))&&is_wp_error($store->save_coupon_policy_record($id,$body)),'Editor denied creation and policy');$actor=3;
$req=new WP_REST_Request('POST',$new);$req->headers['x-kiwe-mutation']='';check($store->create_coupon($req)->status===403,'Creation mutation proof required');$req=new WP_REST_Request('POST',$body,['id'=>$id]);$req->headers['x-kiwe-mutation']='';check($store->save_coupon_policy($req)->status===403,'Policy mutation proof required');
echo "Coupon lifecycle checks passed.\n";

// RC118: native delivery flag and per-basket item allowance, including old clients.
$couponValues[$id]['custom']=[];$before=$store->coupon_record($id);
$extra=array_intersect_key($before,array_flip($fields));$extra+=['freeShipping'=>true,'itemLimit'=>2,'expected'=>$before['expected']];
$delivery=$store->save_coupon_policy_record($id,$extra);
check(is_array($delivery)&&$delivery['freeShipping']===true&&$delivery['itemLimit']===2&&$couponValues[$id]['free_shipping']===true&&$couponValues[$id]['limit_usage_to_x_items']===2,'Delivery and item allowance use native fields');
check($delivery['name']===$before['name']&&$delivery['amount']===$before['amount']&&$delivery['usageCount']===$before['usageCount']&&$delivery['restrictionCounts']===$before['restrictionCounts'],'Delivery policy preserves coupon identity usage and targeting');
check(is_wp_error($store->save_coupon_policy_record($id,$extra)),'Delivery policy stale retry refused');$extra['expected']=$delivery['expected'];$n=$writes;
check(is_array($store->save_coupon_policy_record($id,$extra))&&$n===$writes,'Unchanged delivery policy skips persistence');
foreach([['freeShipping'=>1],['freeShipping'=>null],['itemLimit'=>'2'],['itemLimit'=>-1],['itemLimit'=>1.5],['itemLimit'=>1000001],['itemLimit'=>false]]as$bad)check(is_wp_error($store->save_coupon_policy_record($id,array_replace($extra,$bad))),'Malformed delivery or item allowance rejected');
$legacy=array_diff_key($extra,array_flip(['freeShipping','itemLimit']));$legacy['usageLimit']=21;
$old=$store->save_coupon_policy_record($id,$legacy);check(is_array($old)&&$old['freeShipping']&&$old['itemLimit']===2,'Older policy payload preserves new native fields');
$extra=array_replace($extra,['expected'=>$old['expected'],'freeShipping'=>false,'itemLimit'=>null]);$cleared=$store->save_coupon_policy_record($id,$extra);
check(is_array($cleared)&&!$cleared['freeShipping']&&$cleared['itemLimit']===null,'Delivery and item limit can be explicitly cleared');
$couponValues[$id]['discount_type']='fixed_cart';$couponValues[$id]['limit_usage_to_x_items']=3;$cart=$store->coupon_record($id);$extra=array_replace($extra,['expected'=>$cart['expected'],'freeShipping'=>true,'itemLimit'=>3]);
$cartSaved=$store->save_coupon_policy_record($id,$extra);check(is_array($cartSaved)&&$cartSaved['itemLimit']===3&&$cartSaved['freeShipping'],'Cart coupon delivery change preserves its saved item allowance');
check(is_wp_error($store->save_coupon_policy_record($id,array_replace($extra,['expected'=>$cartSaved['expected'],'itemLimit'=>4]))),'Cart coupon cannot change item allowance');
$couponValues[$id]['discount_type']='fixed_product';$extra=array_replace($extra,['expected'=>$store->coupon_record($id)['expected'],'itemLimit'=>0]);$zero=$store->save_coupon_policy_record($id,$extra);
check(is_array($zero)&&$zero['itemLimit']===null,'Fixed product coupon normalizes native zero unlimited allowance');
$refuseCoupon=true;check(is_wp_error($store->save_coupon_policy_record($id,array_replace($extra,['expected'=>$zero['expected'],'freeShipping'=>false]))),'Failed delivery save is not success');$refuseCoupon=false;
echo "Coupon delivery checks passed.\n";
