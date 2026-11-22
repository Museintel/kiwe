<?php
namespace DSA\SEO;
if(!defined('ABSPATH'))exit;

use DSA\Apps\MCP_Provider_Contract as Contract;
use DSA\MCP\Authoring_Service as Native;

/** Optional native SEO authoring, independent of paid AI and external-provider credentials. */
final class MCP_SEO_Provider {
 public static function register($registry,int $api):void{
  if($api!==Contract::API||version_compare((string)DSA_VERSION,'8.0.0-rc.484','<')||!\DSA\Apps\SEO_App_Service::enabled()||!class_exists(Native::class)||!class_exists(\DSA\MCP\Campaign_Authoring::class)||!defined(SEO_Refinement_Service::class.'::AUTHORING_API'))return;
  $registry->register('seo',Contract::API,static fn()=>['schema'=>Contract::SCHEMA,'api'=>Contract::API,'app'=>'seo','setup'=>['state'=>'ready','needs'=>[],'providerConsentRequired'=>false],'operations'=>self::operations()]);
 }
 private static function text(int $n):array{return['type'=>'string','maxLength'=>$n];}
 private static function object(array $p,array $r=[]):array{return['type'=>'object','properties'=>$p,'required'=>$r,'additionalProperties'=>false];}
 private static function choice(array $v):array{return['type'=>'string','enum'=>$v,'maxLength'=>100];}
 public static function allowed():bool{return \DSA\MCP\Credentials::actor_allowed(get_current_user_id())&&\DSA\Apps\SEO_App_Service::enabled();}
 private static function service():SEO_Refinement_Service{return new SEO_Refinement_Service(\DSA\Plugin::instance()->ai_broker(),new \DSA\Onboarding\Design_Context_Profile_Service());}
 public static function operations():array{
  $can=static fn()=>self::allowed();$out=self::object(['resultJson'=>self::text(6000)],['resultJson']);$fields=self::object(['seoTitle'=>self::text(200),'metaDescription'=>self::text(320),'excerpt'=>self::text(1000),'searchPhrases'=>['type'=>'array','items'=>self::text(120),'maxItems'=>10]]);
  $record=['scope'=>self::choice(['post','page','product','service','term']),'id'=>['type'=>'integer','minimum'=>1],'taxonomy'=>self::text(100)];
  $ops=['seo.authoring.read'=>['scope'=>'seo.authoring.read','label'=>'Read native editable SEO evidence and defaults','effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>self::object(['area'=>self::choice(['record','defaults']),...$record],['area']),'outputSchema'=>self::object(['resultJson'=>self::text(24000)],['resultJson']),'permission'=>$can,'discoverPermission'=>$can,'execute'=>static function($a){if($a['area']==='defaults'){if(!SEO_Defaults_Service::authoring_allowed())throw new \RuntimeException('Your role cannot manage website SEO defaults.');$v=['settings'=>SEO_Defaults_Service::settings(),'expected'=>SEO_Defaults_Service::expected(),'variables'=>SEO_Defaults_Service::VARIABLES,'newsAutomatic'=>SEO_Defaults_Service::news_automatic(),'sections'=>array_keys(SEO_Defaults_Service::defaults())];}else{$v=Native::native(self::service()->authoring_record($a['scope']??'',$a['id']??0,$a['taxonomy']??''));}return['resultJson'=>Native::json($v,24000)];}]];
  $defs=[
   'seo.object.save'=>['object','Review exact native record SEO changes',self::object([...$record,'fields'=>$fields],['scope','id','fields'])],
   'seo.defaults.save'=>['defaults','Review a native SEO default section',self::object(['section'=>self::choice(array_keys(SEO_Defaults_Service::defaults())),'valueJson'=>self::text(14000)],['section','valueJson'])],
   'seo.checklist.refresh'=>['refresh','Request a bounded native SEO checklist refresh',self::object([])],
  ];
  foreach($defs as$id=>[$kind,$label,$input])$ops[$id]=['scope'=>'seo.authoring.write','label'=>$label,'effect'=>'write','approval'=>$kind==='refresh'?'none':'required','accessVersion'=>1,'inputSchema'=>$input,'outputSchema'=>$out,'permission'=>$can,'discoverPermission'=>$can,'prepare'=>static fn($a)=>self::prepare($kind,$a),'current'=>static fn($a)=>self::prepare($kind,$a)['nativeExpected'],'execute'=>static fn($a,$expected)=>self::apply($kind,$a,$expected)];
  return$ops;
 }
 private static function defaults(array $a):array{
  if(!SEO_Defaults_Service::authoring_allowed())throw new \RuntimeException('Your current role cannot manage native SEO defaults.');
  $before=SEO_Defaults_Service::settings();if(!array_key_exists($a['section'],$before))throw new \RuntimeException('Choose a native SEO defaults section.');
  $value=json_decode($a['valueJson'],true,16,JSON_THROW_ON_ERROR);$after=$before;$after[$a['section']]=$value;$after=Native::native(SEO_Defaults_Service::validate($after));
  if(!\DSA\Apps\Store_App_Service::enabled())foreach(['postTypes','taxonomies']as$key)foreach(['product','product_cat','product_tag']as$product)if(($after[$key][$product]??null)!==($before[$key][$product]??null))throw new \RuntimeException('Product SEO requires active Store.');
  return['before'=>$before,'after'=>$after,'expected'=>SEO_Defaults_Service::expected()];
 }
 public static function prepare(string $kind,array $a):array{
  if(!self::allowed())throw new \RuntimeException('Native Kiwe SEO is unavailable or another provider owns SEO.');
  if($kind==='object'){
   $record=Native::native(self::service()->authoring_record($a['scope'],$a['id'],$a['taxonomy']??''));$item=$record['item']??$record;$fields=$a['fields'];
   if(!$fields||array_diff(array_keys($fields),$a['scope']==='post'?['seoTitle','metaDescription','searchPhrases','excerpt']:['seoTitle','metaDescription','searchPhrases']))throw new \RuntimeException('Use only the described native SEO fields.');
   if($a['scope']==='term'&&array_diff(['seoTitle','metaDescription','searchPhrases'],array_keys($fields)))throw new \RuntimeException('A native category save needs all three SEO fields.');
   return \DSA\MCP\Campaign_Authoring::review('Review native SEO for '.$item['label'],$item['expected'],['id'=>$item['id'],'scope'=>$a['scope'],'current'=>$item['current']],$fields);
  }
  if($kind==='defaults'){$v=self::defaults($a);return \DSA\MCP\Campaign_Authoring::review('Review native SEO defaults: '.$a['section'],Native::digest([$v['expected'],\DSA\Onboarding\SEO_Context_Service::goal_hash()]),$v['before'][$a['section']],$v['after'][$a['section']]);}
  if($kind==='refresh'){
   if(!current_user_can('kiwe_manage_context'))throw new \RuntimeException('Your role cannot request this checklist refresh.');
   return Native::plan('Request the existing bounded read-only SEO worker',Native::digest(\DSA\Onboarding\Search_Report_Snapshot::read()),[],['request'=>'Refresh saved checklist; no LLM, Google request or public content change']);
  }
  throw new \RuntimeException('Choose a described native SEO operation.');
 }
 public static function apply(string $kind,array $a,string $expected):array{
  return Native::mutate('seo',static function()use($kind,$a,$expected){
   if(!hash_equals(self::prepare($kind,$a)['nativeExpected'],$expected))throw new \RuntimeException('The record, goal or native SEO settings changed. Review fresh evidence.');
   if($kind==='object'){$s=self::service();$body=['scope'=>$a['scope'],'expected'=>$expected,'fields'=>$a['fields']];$v=Native::native($a['scope']==='term'?$s->authoring_save_term($a['taxonomy']??'',$a['id'],$body):$s->authoring_save_object($a['id'],$body));$result=['saved'=>!empty($v['saved']),'item'=>$v['item']];}
   elseif($kind==='defaults'){$v=self::defaults($a);$saved=Native::native((new SEO_Defaults_Service())->authoring_save(['expected'=>$v['expected'],'settings'=>$v['after']]));$result=['saved'=>!empty($saved['saved']),'expected'=>$saved['expected']];}
   elseif($kind==='refresh'){if(true!==(new SEO_Score_Service())->request())throw new \RuntimeException('The native checklist request could not be confirmed.');$result=['requested'=>true,'pending'=>!empty(\DSA\Onboarding\Search_Report_Snapshot::read()['pending'])];}
   else throw new \RuntimeException('Choose a described native SEO operation.');
   return['resultJson'=>Native::json($result)];
  });
 }
}
