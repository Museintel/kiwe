<?php
namespace DSA\Commerce;
if(!defined('ABSPATH'))exit;

use DSA\MCP\Authoring_Service as Native;
use DSA\Apps\MCP_Provider_Contract as Contract;
use DSA\Apps\Razorpay_App_Service;

/** Local Store capabilities plug into MCP's existing provider boundary. */
final class MCP_Store_Provider {
 public static function register($registry,int $api): void{
  if($api!==Contract::API||version_compare((string)DSA_VERSION,'8.0.0-rc.481','<')||!\DSA\Apps\Store_App_Service::enabled()||!class_exists(Native::class))return;
  $registry->register('store',Contract::API,static fn()=>['schema'=>Contract::SCHEMA,'api'=>Contract::API,'app'=>'store','setup'=>['state'=>'ready','needs'=>[],'providerConsentRequired'=>false],'operations'=>self::operations()]);
 }
 private static function text(int $n): array{return['type'=>'string','maxLength'=>$n];}
 private static function integer(int $n=0): array{return['type'=>'integer','minimum'=>$n];}
 private static function choice(array $values): array{return['type'=>'string','maxLength'=>100,'enum'=>$values];}
 private static function object(array $fields,array $required=[]): array{return['type'=>'object','properties'=>$fields,'required'=>$required,'additionalProperties'=>false];}
 public static function operations(): array{
  $service=new MCP_Store_Authoring();$allowed=static fn()=>MCP_Store_Authoring::allowed();$out=self::object(['resultJson'=>self::text(6000)],['resultJson']);
  $input=self::object(['area'=>self::choice(MCP_Store_Authoring::AREAS),'id'=>self::integer(),'parentId'=>self::integer(),'relatedId'=>self::integer(),'page'=>['type'=>'integer','minimum'=>1,'maximum'=>1000],'search'=>self::text(100),'country'=>self::text(2),'locationKind'=>self::choice(['city','postcode'])],['area']);
  $read=self::object(['area'=>self::text(100),'page'=>self::integer(1),'hasMore'=>['type'=>'boolean'],'dataJson'=>self::text(24000),'guidance'=>self::text(1500)],['area','page','hasMore','dataJson','guidance']);
  $ops=['store.authoring.read'=>['scope'=>'store.authoring.read','label'=>'Read applicable native product and Store setup records','effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>$input,'outputSchema'=>$read,'permission'=>$allowed,'discoverPermission'=>$allowed,'execute'=>static fn($a)=>$service->read($a)]];
  $gatewayFields=array_fill_keys(['storeEnabled','gatewayInstalled','gatewayActive','gatewayEnabled','checkoutExperienceEnabled','standardMethodChoices','kiweSubscriptionQuotes'],['type'=>'boolean']);
  $gatewayFields['mode']=self::choice(['unconfigured','test','live']);
  $gatewayAllowed=static fn()=>class_exists(Razorpay_App_Service::class)&&Razorpay_App_Service::allowed();
  $ops['store.razorpay.status']=[
   'scope'=>'store.authoring.read','label'=>'Check Razorpay gateway and Kiwe checkout configuration',
   'effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>self::object([]),
   'outputSchema'=>self::object($gatewayFields,array_keys($gatewayFields)),
   'permission'=>$gatewayAllowed,'discoverPermission'=>$gatewayAllowed,'execute'=>static fn()=>Razorpay_App_Service::status(),
  ];
  $quoteFields=array_fill_keys(['firstChargeMinor','renewalMinor','firstTermMonths','intervalMonths','recurringLines','oneTimeLines'],self::integer());
  $quoteFields['currency']=self::choice(['INR']);
  $ops['store.subscription.quote']=[
   'scope'=>'store.authoring.read','label'=>'Review Kiwe renewal terms on an unpaid WooCommerce order',
   'effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>self::object(['orderId'=>self::integer(1)],['orderId']),
   'outputSchema'=>self::object($quoteFields,array_keys($quoteFields)),
   'permission'=>static fn($a)=>class_exists(Subscription_Quote_Service::class)&&Subscription_Quote_Service::can_review_order($a['orderId']),
   'discoverPermission'=>static fn()=>class_exists(Subscription_Quote_Service::class)&&Subscription_Quote_Service::can_review(),
   'execute'=>static fn($a)=>Subscription_Quote_Service::review_order($a['orderId']),
  ];
  $definitions=[
   'store.product.create'=>['create',[],[]],
   'store.product.save'=>['save',['id'],[]],
   'store.product.publish'=>['publish',['id'],[]],
   'store.product.inventory'=>['inventory',['id'],[]],
   'store.product.delivery'=>['delivery',['id'],[]],
   'store.product.sale'=>['sale',['id'],[]],
   'store.product.nutrition'=>['nutrition',['id'],[]],
   'store.product.relationships'=>['relationships',['id'],[]],
   'store.product.companion'=>['offer',['id'],[]],
   'store.product.options'=>['options',['id'],[]],
   'store.product.variation'=>['variation',['id','parentId'],[]],
   'store.attribute.change'=>['attribute',['id','parentId'],['value'=>['type'=>'boolean']]],
   'store.tax.setting'=>['tax-setting',[],[]],
   'store.tax.class'=>['tax-class',[],[]],
   'store.tax.rate'=>['tax-rate',['id'],['action'=>self::choice(['create','value','geography','rules'])]],
   'store.shipping.zone'=>['shipping-zone',['id'],['action'=>self::choice(['create','edit'])]],
   'store.shipping.method'=>['shipping-method',['id','parentId'],['action'=>self::choice(['enabled','rates','class-rates']),'page'=>['type'=>'integer','minimum'=>1,'maximum'=>1000]]],
   'store.shipping.class'=>['shipping-class',['id'],[]],
   'store.checkout.change'=>['checkout',[],[]],
   'store.display.change'=>['display',[],[]],
   'store.offerpolicy.change'=>['offer-policy',[],[]],
   'store.product.tax'=>['product-tax',['id'],[]],
   'store.business.change'=>['business',[],[]],
   'store.coupon.change'=>['coupon',['id'],['action'=>self::choice(['create','details','policy'])]],
  ];
  foreach($definitions as$id=>[$base,$ids,$extra]){
   $kinds=match($base){'attribute'=>['attribute','attribute-value'],'tax-rate'=>['tax-rate-create','tax-rate','tax-geography','tax-rules'],'shipping-zone'=>['shipping-create','shipping-zone'],'shipping-method'=>['shipping-method','shipping-rates','shipping-class-rates'],'coupon'=>['coupon-create','coupon','coupon-policy'],default=>[$base]};$keys=[];foreach($kinds as$nativeKind)$keys=array_merge($keys,MCP_Store_Authoring::fields($nativeKind));$description='Native fields: '.implode(', ',array_values(array_unique($keys))).'. Read current values first. Do not supply expected, confirmed or acceptEffect.';
   $fields=['fieldsJson'=>self::text(7000)+['description'=>$description]]+$extra;foreach($ids as$key)$fields[$key]=self::integer();$required=['fieldsJson',...$ids];foreach(array_keys($extra)as$key)if($key!=='page')$required[]=$key;
   $kind=static function($a)use($base):string{return match($base){'attribute'=>!empty($a['value'])?'attribute-value':'attribute','tax-rate'=>match($a['action']){'create'=>'tax-rate-create','value'=>'tax-rate','geography'=>'tax-geography','rules'=>'tax-rules'},'shipping-zone'=>$a['action']==='create'?'shipping-create':'shipping-zone','shipping-method'=>match($a['action']){'enabled'=>'shipping-method','rates'=>'shipping-rates','class-rates'=>'shipping-class-rates'},'coupon'=>match($a['action']){'create'=>'coupon-create','details'=>'coupon','policy'=>'coupon-policy'},default=>$base};};
   $ops[$id]=['scope'=>$base==='publish'?'store.authoring.publish':'store.authoring.write','label'=>'Use the native Store '.str_replace('-',' ',$base).' task','effect'=>'write','approval'=>in_array($base,['create','save'],true)?'none':'required','accessVersion'=>1,'inputSchema'=>self::object($fields,$required),'outputSchema'=>$out,'permission'=>static fn($a)=>MCP_Store_Authoring::kind_allowed($kind($a)),'discoverPermission'=>static fn()=>MCP_Store_Authoring::kind_allowed($base),'prepare'=>static fn($a)=>$service->prepare($kind($a),$a),'current'=>static fn($a)=>$service->prepare($kind($a),$a)['nativeExpected'],'execute'=>static fn($a,$expected)=>$service->apply($kind($a),$a,$expected)];
  }
  $groupRead=self::object(['kind'=>self::choice(['categories','tags','tag-groups']),'id'=>self::integer(),'page'=>['type'=>'integer','minimum'=>1,'maximum'=>1000],'search'=>self::text(100)],['kind']);
  $ops['store.groups.read']=['scope'=>'store.authoring.read','label'=>'Read native Store categories, tags and tag groups','effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>$groupRead,'outputSchema'=>self::object(['resultJson'=>self::text(24000)],['resultJson']),'permission'=>$allowed,'discoverPermission'=>$allowed,'execute'=>static function($a){$kind=$a['kind'];$page=$a['page']??1;$data=$kind==='tag-groups'?Native::native((new \DSA\Access\Tag_Group_Service())->authoring_read('product')):Native::native((new \DSA\Access\Taxonomy_App_Service())->authoring_terms($kind==='tags'?'product_tag':'product_cat',$a['id']??0,['page'=>$page,'search'=>$a['search']??'']));if($kind==='tag-groups'){$groups=$data['groups'];$data['groups']=array_slice($groups,($page-1)*8,8);$data['hasMore']=$page*8<count($groups);$data['page']=$page;}return['resultJson'=>Native::json($data,24000)];}];
  $groupFields=self::object(['kind'=>self::choice(['categories','tags']),'id'=>self::integer(),'name'=>self::text(200),'parent'=>self::integer()],['kind','id','name']);
  $ops['store.group.change']=['scope'=>'store.authoring.write','label'=>'Create or rename a native Store category or tag','effect'=>'write','approval'=>'required','accessVersion'=>1,'inputSchema'=>$groupFields,'outputSchema'=>$out,'permission'=>$allowed,'discoverPermission'=>$allowed,'prepare'=>static fn($a)=>self::group($a),'current'=>static fn($a)=>self::group($a)['nativeExpected'],'execute'=>static fn($a,$expected)=>self::change_group($a,$expected)];
  $tagFields=self::object(['action'=>self::choice(['create','rename','move']),'id'=>self::integer(),'fieldsJson'=>self::text(5000)],['action','id','fieldsJson']);
  $args=static fn($a)=>['kind'=>'product','action'=>$a['action'],'id'=>$a['id'],'fields'=>Native::object($a['fieldsJson'],['name','sourceId','targetId','tagIds'])];
  $ops['store.tag-group.change']=['scope'=>'store.authoring.write','label'=>'Change native Store tag groups and exact selected members','effect'=>'write','approval'=>'required','accessVersion'=>1,'inputSchema'=>$tagFields,'outputSchema'=>$out,'permission'=>$allowed,'discoverPermission'=>$allowed,'prepare'=>static fn($a)=>\DSA\MCP\Tag_Group_Authoring::prepare($args($a)),'current'=>static fn($a)=>\DSA\MCP\Tag_Group_Authoring::prepare($args($a))['nativeExpected'],'execute'=>static fn($a,$expected)=>['resultJson'=>Native::json(\DSA\MCP\Tag_Group_Authoring::apply($args($a),$expected))]];
  return$ops;
 }
 private static function group(array $a): array{
  if(!MCP_Store_Authoring::allowed())throw new \RuntimeException('Store authoring is unavailable.');if($a['id']&&isset($a['parent']))throw new \RuntimeException('This native rename changes only its label.');$before=Native::native((new \DSA\Access\Taxonomy_App_Service())->authoring_terms($a['kind']==='tags'?'product_tag':'product_cat',$a['id']));return Native::plan('Review a native Store content group',$a['id']?$before['expected']:Native::digest($before),$a['id']?$before:[],$a,true);
 }
 private static function change_group(array $a,string $expected): array{
  return Native::mutate('store-groups',static function()use($a,$expected){
   if(!hash_equals(self::group($a)['nativeExpected'],$expected))throw new \RuntimeException('Store groups changed. Read them before preparing a new review.');
   $fields=['name'=>$a['name']];if($a['id'])$fields['expected']=$expected;elseif(isset($a['parent']))$fields['parent']=$a['parent'];
   return['resultJson'=>Native::json(Native::native((new \DSA\Access\Taxonomy_App_Service())->authoring_change($a['kind']==='tags'?'product_tag':'product_cat',$a['id'],$fields)))];
  });
 }
}
