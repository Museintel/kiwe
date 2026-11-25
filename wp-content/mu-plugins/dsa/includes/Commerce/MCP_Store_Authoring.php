<?php
namespace DSA\Commerce;
if(!defined('ABSPATH'))exit;

use DSA\Apps\Store_App_Service as Store;
use DSA\Access\Commerce_App_Service as Products;
use DSA\Access\Product_Attribute_Workspace_Service as Attributes;
use DSA\Access\Product_Variation_Workspace_Service as Variations;
use DSA\Access\Product_Merchandising_Workspace_Service as Merchandising;
use DSA\Access\Tax_Workspace_Service as Taxes;
use DSA\Access\Shipping_Workspace_Service as Shipping;
use DSA\Access\Shipping_Class_Workspace_Service as ShippingClasses;
use DSA\Access\Checkout_Policy_Workspace_Service as Checkout;
use DSA\Access\Taxonomy_App_Service as Taxonomies;
use DSA\MCP\Authoring_Service as Native;

/** Store-owned adapters; instantiated only by the optional, admitted MCP request. */
final class MCP_Store_Authoring {
 public const API=1;
 public const AREAS=['products','product','inventory','delivery','sale','nutrition','relationships','offer','options','variations','variation','attributes','attribute-values','tax','tax-classes','tax-rates','tax-rate','tax-create','tax-geography','tax-rules','tax-locations','shipping','shipping-zone','shipping-method','shipping-rates','shipping-class-rates','shipping-create','shipping-classes','checkout','display','offer-policy','coupons','product-tax','business','business-contact'];
 public static function allowed(): bool{return Store::enabled()&&\DSA\MCP\Credentials::actor_allowed(get_current_user_id())&&current_user_can('edit_products');}
 public static function kind_allowed(string $kind): bool{
  if(!self::allowed())return false;
  if($kind==='publish')return current_user_can('publish_products');
  if($kind==='create'){$type=get_post_type_object('product');return$type&&current_user_can($type->cap->create_posts);}
  if(str_starts_with($kind,'coupon'))return current_user_can('edit_shop_coupons')&&current_user_can('edit_others_shop_coupons');
  if(in_array($kind,['attribute','attribute-value'],true))return current_user_can('manage_product_terms')&&current_user_can('edit_product_terms');
  if(str_starts_with($kind,'tax-')||str_starts_with($kind,'shipping-')||in_array($kind,['checkout','display','offer-policy','business'],true))return current_user_can('kiwe_manage_context');
  return true;
 }
 private static function check(): void{if(!self::allowed())throw new \RuntimeException('Store authoring requires the active native Store and your current product-editing role.');}
 private static function clean($v){if(!is_array($v))return$v;$out=[];foreach($v as$k=>$value){if(in_array($k,['nonce','token','secret','controls','downloadUrl'],true))continue;$out[$k]=self::clean($value);}return$out;}
 private static function state(string $area,array $a): array{
  self::check();$id=$a['id']??0;$parent=$a['parentId']??0;$related=$a['relatedId']??0;$page=$a['page']??1;if($page<1||$page>1000)throw new \RuntimeException('Choose a bounded native Store page.');$q=['page'=>$page,'search'=>$a['search']??''];if(in_array($area,['tax-create','tax-geography'],true))$q['kind']=$a['country']??'';if($area==='tax-rules')$q['kind']=$a['locationKind']??'postcode';$p=new Products();
  $data=match($area){
   'product','inventory','delivery'=>$p->product_record($id),
   'sale'=>$p->sale_record($id),'nutrition'=>$p->nutrition_record($id),'relationships'=>(new Merchandising())->review($id),'offer'=>$p->offer_record($id),
   'options'=>(new Variations())->setup($id),'variations'=>(new Variations())->variations($id,$q),'variation'=>(new Variations())->variation($parent,$id),
   'attributes'=>(new Attributes())->catalog($q),'attribute-values'=>(new Attributes())->values($id,$q),
   'tax'=>(new Taxes())->settings(),'tax-classes'=>(new Taxes())->classes(),'tax-rates'=>(new Taxes())->rates($q),'tax-rate'=>(new Taxes())->rate($id),'tax-create'=>(new Taxes())->creation($q),'tax-geography'=>(new Taxes())->geography($id,$q),'tax-rules'=>(new Taxes())->rules($id,$q),'tax-locations'=>(new Taxes())->locations($id,$q),
   'shipping'=>(new Shipping())->zones($q),'shipping-zone'=>(new Shipping())->zone_settings($id,$q),'shipping-method'=>(new Shipping())->method($parent,$id),'shipping-rates'=>(new Shipping())->rates($parent,$id),'shipping-class-rates'=>(new Shipping())->class_rates($parent,$id,$q),'shipping-create'=>(new Shipping())->creation(),'shipping-classes'=>(new ShippingClasses())->classes($q),
   'checkout'=>(new Checkout())->policy(),'display'=>$p->display_policy_record(),'offer-policy'=>$p->offer_policy_record(),'coupons'=>$id?$p->coupon_record($id):$p->coupon_catalogue($q),
   'product-tax'=>$p->product_tax_record($id),
   'business','business-contact'=>(new \DSA\Access\Continuity_App_Service())->field_snapshot($area==='business'?'store':'contact'),
   default=>throw new \RuntimeException('Choose an implemented native Store area.'),
  };
  return self::clean(Native::native($data));
 }
 public function read(array $a): array{
  self::check();$area=$a['area'];$page=$a['page']??1;if($page<1||$page>1000)throw new \RuntimeException('Choose a bounded native Store page.');$hasMore=false;
  if($area==='products'){
   $q=['limit'=>9,'offset'=>($page-1)*8,'orderby'=>'ID','order'=>'DESC','status'=>['draft','pending','publish','private']];if(!current_user_can('edit_others_products'))$q['author']=get_current_user_id();if(!empty($a['search']))$q['s']=$a['search'];$rows=wc_get_products($q);$data=[];foreach(array_slice($rows,0,8)as$product){$id=(int)$product->get_id();if(current_user_can('edit_post',$id)&&current_user_can('read_post',$id))$data[]=array_intersect_key(Native::native((new Products())->product_record($id)),array_flip(['id','name','type','status','price','weight','weightUnit','expected']));}$hasMore=count($rows)>8;
  }else{$data=self::state($area,$a);$hasMore=!empty($data['hasNext']);}
  return['area'=>$area,'page'=>$page,'hasMore'=>$hasMore,'dataJson'=>Native::json($data,24000),'guidance'=>'Store and Woo retain these native records. Read current values and IDs before saving. Financial policy, published products and product choices need an exact native review. Subscription quotes use a separate operation with native order permissions.'];
 }
 /** Fixed field allowlists are adapters, not arbitrary options, meta or REST targets. */
 public static function fields(string $kind): array{return match($kind){
  'create'=>['name','type','imageId'],
  'save','publish'=>['name','price','status','description','shortDescription','categories','tags','imageId','galleryIds','reviewsAllowed','subscriptionTerms'],
  'inventory'=>['manageStock','sku','stockStatus','stock'],
  'delivery'=>['virtual','shippingClassId','weight','length','width','height'],
  'sale'=>['salePrice','startsAt','endsAt'],'nutrition'=>['imageId'],
  'relationships'=>['field','ids'],'offer'=>['productId','discountType','discountScope','discount'],
  'options'=>['key','attribute','default','remove'],
  'variation'=>['attributes','enabled','price','salePrice','startsAt','endsAt','sku','manageStock','stock','stockStatus','backorders','imageId','description','virtual','weight','length','width','height','shippingClassId','taxClass','position','subscriptionTerms'],
  'attribute','attribute-value'=>['action','name','slug'],
   'tax-setting','tax-rate'=>['key','value'],'tax-class'=>['name'],'tax-rate-create'=>['name','rate','priority','order','compound','shipping','country','state','class','cities','postcodes'],
  'tax-geography'=>['country','state'],'tax-rules'=>['kind','op','codes','ids'],
  'shipping-zone'=>['operation','name','order','locations'],'shipping-create'=>['name','order','country','postcode','kind','title','cost'],
  'shipping-method'=>['enabled'],'shipping-rates','shipping-class-rates'=>['changes'],
  'shipping-class'=>['action','name'],
  'checkout'=>['operation','enabled','fields'],'display'=>['changes'],'offer-policy'=>['key','value'],
  'product-tax'=>['taxStatus','taxClass'],'coupon-create'=>['name','type','amount','description'],'coupon'=>['amount','description'],
  'coupon-policy'=>['freeShipping','itemLimit','status','maximum','minimum','expiresLocal','usageLimit','perUserLimit','individualUse','excludeSaleItems'],
  'business'=>['area','field','valueJson'],
  default=>throw new \RuntimeException('This Store authoring action is not implemented.'),
 };}
 private static function area(string $kind): string{return match($kind){'save','publish'=>'product','attribute'=>'attributes','attribute-value'=>'attribute-values','tax-setting'=>'tax','tax-class'=>'tax-classes','tax-rate-create'=>'tax-create','shipping-class'=>'shipping-classes','coupon','coupon-policy'=>'coupons',default=>$kind};}
 private static function initial(string $kind,array $a,array $f): array{
  if($kind==='create'){if(!self::kind_allowed($kind))throw new \RuntimeException('Your role cannot create products.');return['expected'=>Native::digest(['new-product',get_current_user_id()]),'status'=>'draft'];}
  if($kind==='coupon-create'){if(!current_user_can('edit_shop_coupons')||!current_user_can('edit_others_shop_coupons'))throw new \RuntimeException('Your role cannot create coupons.');return['expected'=>Native::digest(['new-coupon',get_current_user_id()]),'status'=>'draft'];}
  if($kind==='attribute-value')return self::state('attribute-values',array_replace($a,['id'=>$a['parentId']??0]));
  if($kind==='shipping-create'&&($a['id']??0)>0)return self::state('shipping-zone',$a);
  if(in_array($kind,['tax-rate-create','tax-geography'],true))return self::state(self::area($kind),$a+['country'=>$f['country']??'']);
  if($kind==='tax-rules')return self::state('tax-rules',$a+['locationKind'=>$f['kind']??'postcode']);
  if($kind==='business')return self::state(($f['area']??'')==='contact'?'business-contact':'business',$a);
  return self::state(self::area($kind),$a);
 }
 public function prepare(string $kind,array $a): array{
  self::check();if(!self::kind_allowed($kind))throw new \RuntimeException('Your current native Store role cannot make this change.');$f=Native::object($a['fieldsJson'],self::fields($kind));if(!$f)throw new \RuntimeException('Choose the exact native Store fields to change.');$before=self::initial($kind,$a,$f);
  if(in_array($kind,['attribute','attribute-value','shipping-class'],true)&&!in_array($f['action']??'', ['create','rename'],true))throw new \RuntimeException('Authoring admits creation or rename. Removal keeps its separately reviewed native cleanup workflow.');
  if($kind==='business'){
   if(!in_array($f['area']??null,['store','contact'],true)||!is_string($f['field']??null)||!is_string($f['valueJson']??null)||$f['field']==='commerce.enabled'||($f['area']==='contact'&&!str_starts_with($f['field'],'contact.address.')))throw new \RuntimeException('Choose a native Store business or Store address field. App availability stays with the separate Apps review.');
   $matches=array_values(array_filter($before['fields'],static fn($field)=>$field['path']===$f['field']));if(count($matches)!==1)throw new \RuntimeException('Read a currently available native Store business field.');json_decode($f['valueJson'],true,16,JSON_THROW_ON_ERROR);
  }
  if(in_array($kind,['save','publish'],true)){
   if($kind==='save'&&(!in_array($before['status'],['draft','pending'],true)||!in_array($f['status']??$before['status'],['draft','pending'],true)))throw new \RuntimeException('Use the separately scoped publish action for a live, private or published product.');
   if($kind==='publish'&&!current_user_can('publish_products'))throw new \RuntimeException('Your native role cannot publish this product.');
   foreach(['description','shortDescription']as$key)if(isset($f[$key]))Native::safe_html($f[$key]);
  }
  if(isset($before['canEdit'])&&$before['canEdit']!==true)throw new \RuntimeException('This native task requires its site manager or external provider.');
  if(($before['large']??false)===true)throw new \RuntimeException('This large native configuration needs its existing editing screen.');
  $version=\DSA\Apps\Runtime_Registry::provider('store')['version']??'';$expected=Native::digest([$kind,$a['id']??0,$a['parentId']??0,$a['relatedId']??0,$before,DSA_VERSION,$version]);
  $preview=$before;foreach(['description','shortDescription']as$key)if(isset($preview[$key]))$preview[$key]='Existing native text ('.strlen($preview[$key]).' bytes)';
  return Native::plan('Review native Store '.str_replace('-',' ',$kind),$expected,$preview,$f,!in_array($kind,['create','save'],true));
 }
 private static function complete(array $current,array $f,array $keys): array{return array_replace(array_intersect_key($current,array_flip($keys)),$f);}
 public function apply(string $kind,array $a,string $expected): array{
  $productKinds=['save','publish','inventory','delivery','sale','nutrition','relationships','offer','options','variation','product-tax'];$product=in_array($kind,$productKinds,true);$id=$kind==='variation'?($a['parentId']??0):($a['id']??0);
  return Native::mutate($product?'store-product:'.$id:'store-setup',function()use($kind,$a,$expected,$product,$id){if($product&&$id&&function_exists('clean_post_cache'))clean_post_cache($id);return$this->apply_native($kind,$a,$expected);});
 }
 private function apply_native(string $kind,array $a,string $expected): array{
  self::check();if(!hash_equals($this->prepare($kind,$a)['nativeExpected'],$expected))throw new \RuntimeException('The native Store record or configuration changed. Read it before preparing a new review.');
  $f=Native::object($a['fieldsJson'],self::fields($kind));$before=self::initial($kind,$a,$f);$id=$a['id']??0;$parent=$a['parentId']??0;$p=new Products();$nativeExpected=$before['expected']??'';$input=$f+['expected'=>$nativeExpected];
  $effect=$input+['acceptEffect'=>true];$confirmed=$input+['confirmed'=>true];
  $result=match($kind){
   'create'=>$p->create_product_record($f),
   'save','publish'=>$p->save_product_record($id,self::complete($before,$input,['name','price','status','categories'])),
   'inventory'=>$p->save_inventory_record($id,self::complete($before,$input,['manageStock','sku','stockStatus','stock'])),
   'delivery'=>$p->save_delivery_record($id,self::complete($before,$input,self::fields($kind))),
   'sale'=>$p->save_sale_record($id,self::complete($before,$input,self::fields($kind))),
   'nutrition'=>$p->save_nutrition_record($id,$input),
   'relationships'=>(new Merchandising())->change($id,$confirmed),
   'offer'=>$p->save_offer_record($id,self::complete($before,$input,self::fields($kind))),
   'options'=>(new Variations())->change_attribute($id,$confirmed),
   'variation'=>(new Variations())->save_variation($parent,$id,self::complete($before,$confirmed,self::fields($kind))),
   'attribute'=>(new Attributes())->change($id,0,$effect),
   'attribute-value'=>(new Attributes())->change($parent,$id,$effect,true),
   'tax-setting'=>(new Taxes())->save($effect),'tax-class'=>(new Taxes())->create_class($effect),'tax-rate-create'=>(new Taxes())->create_rate($effect),'tax-rate'=>(new Taxes())->save_rate($id,$effect),'tax-geography'=>(new Taxes())->save_geography($id,$effect),'tax-rules'=>(new Taxes())->save_rules($id,$effect),
   'shipping-zone'=>(new Shipping())->save_zone($id,$effect),'shipping-create'=>(new Shipping())->create_delivery($effect,$id),'shipping-method'=>(new Shipping())->save($parent,$id,$effect),'shipping-rates'=>(new Shipping())->save_rates($parent,$id,$effect),'shipping-class-rates'=>(new Shipping())->save_class_rates($parent,$id,$effect+['page'=>$a['page']??1]),'shipping-class'=>(new ShippingClasses())->change($id,$effect),
   'checkout'=>$this->checkout($f,$before),
   'display'=>$p->save_display_policy_record($input),'offer-policy'=>$p->save_offer_policy_record($effect),
   'product-tax'=>$p->save_product_tax_record($id,$effect),
   'business'=>(new \DSA\Access\Continuity_App_Service())->change_field($f['area'],['path'=>$f['field'],'value'=>json_decode($f['valueJson'],true,16,JSON_THROW_ON_ERROR),'expected'=>$before['expected']]),
   'coupon-create'=>$p->create_coupon_record($f),'coupon'=>$p->save_coupon_record($id,self::complete($before,$input,self::fields($kind))),'coupon-policy'=>$p->save_coupon_policy_record($id,self::complete($before,$input,self::fields($kind))),
   default=>throw new \RuntimeException('This native Store action is not implemented.'),
  };
  $saved=self::clean(Native::native($result));if(!is_array($saved))throw new \RuntimeException('Read the native Store result before retrying.');
  // Keep receipts bounded; the separately scoped read returns current full data.
  $receipt=array_intersect_key($saved,array_flip(['id','name','status','expected','changed','action','zoneId','instanceId','message','guestCheckout','checkoutFields','switches']));$receipt['nativeTask']=$kind;$receipt['confirmed']=true;
  if($kind==='business'){$receipt['field']=$f['field'];$receipt['valueJson']=Native::json($saved['values'][$f['field']]??null,2000);}
  return['resultJson'=>Native::json($receipt,6000)];
 }
 private function checkout(array $f,array $before){$owner=new Checkout();$operation=$f['operation']??'';return match($operation){'guestCheckout'=>$owner->change_guest_checkout(['operation'=>$operation,'enabled'=>$f['enabled']??null,'expected'=>$before['expected']]),'checkoutFields'=>$owner->change_checkout_fields(['operation'=>$operation,'fields'=>$f['fields']??null,'expected'=>$before['expected']]),'policy'=>$owner->change_policy(['fields'=>$f['fields']??null,'expected'=>$before['expected'],'confirmed'=>true]),default=>throw new \RuntimeException('Choose guest checkout, native field requirements or the existing checkout policy.')};}
}
