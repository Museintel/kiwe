<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

/** SEO facts and native notification campaigns have independent, newly consented scopes. */
final class Growth_Provider {
 public const MINIMUM_CORE='8.0.0-rc.484';
 private static function text(int $n):array{return['type'=>'string','maxLength'=>$n];}
 private static function choice(array $v):array{return['type'=>'string','enum'=>$v,'maxLength'=>100];}
 private static function object(array $p,array $r=[]):array{return['type'=>'object','properties'=>$p,'required'=>$r,'additionalProperties'=>false];}
 public static function operations():array{
  if(!defined('DSA_VERSION')||version_compare(DSA_VERSION,self::MINIMUM_CORE,'<')||!defined(\DSA\Access\Campaign_Workspace_Service::class.'::NATIVE_API'))return[];
  $out=self::object(['resultJson'=>self::text(6000)],['resultJson']);$readOut=self::object(['resultJson'=>self::text(24000)],['resultJson']);$s=new Campaign_Authoring();$can=static fn()=>Campaign_Authoring::allowed();
  $ops=['mcp.seo.guidance'=>['scope'=>'mcp.seo.read','label'=>'Read current SEO goal, provider and saved checklist evidence','effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>self::object([]),'outputSchema'=>$out,'permission'=>static fn()=>SEO_Guidance::allowed(),'discoverPermission'=>static fn()=>SEO_Guidance::allowed(),'execute'=>static fn()=>SEO_Guidance::read()],
   'mcp.campaign.read'=>['scope'=>'mcp.campaign.read','label'=>'Read native campaign drafts and aggregate consent-qualified audience','effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>self::object(['area'=>self::choice(['catalog','drafts','draft','sources','audience','delivery','automations']),'id'=>self::text(100),'type'=>self::choice(['newsletter','article','offer','product']),'search'=>self::text(80),'page'=>['type'=>'integer','minimum'=>1,'maximum'=>1000]],['area']),'outputSchema'=>$readOut,'permission'=>$can,'discoverPermission'=>$can,'execute'=>static fn($a)=>$s->read($a)]];
  $defs=[
   'mcp.campaign.save'=>['save','Save an agreed private native campaign draft','mcp.campaign.write','none',self::object(['id'=>self::text(100),'fieldsJson'=>self::text(14000)+['description'=>'Full native draft: name, type, sourceId, group, channels (ordered), copies (channel: title/body), scheduledAt (Unix seconds or 0). Read the native catalogue for available types, groups, provider readiness and copy limits. Empty id creates a draft; no send is implied.']],['id','fieldsJson'])],
   'mcp.campaign.queue'=>['queue','Review the exact campaign and opted-in audience before queuing','mcp.campaign.send','required',self::object(['id'=>self::text(100)],['id'])],
   'mcp.campaign.control'=>['control','Review cancellation or continuation of the current campaign','mcp.campaign.send','required',self::object(['action'=>self::choice(['cancel','resume']),'id'=>self::text(100)],['action','id'])],
   'mcp.campaign.automation'=>['automation','Review native saved-cart or order delivery automation','mcp.campaign.policy','required',self::object(['kind'=>self::choice(['cart','orders']),'fieldsJson'=>self::text(10000)],['kind','fieldsJson'])],
  ];
  foreach($defs as$id=>[$kind,$label,$scope,$approval,$input]){
   if($kind==='automation'&&!\DSA\Apps\Store_App_Service::enabled())continue;
   $permission=$kind==='automation'?static fn()=>Campaign_Authoring::allowed()&&\DSA\Apps\Store_App_Service::enabled()&&current_user_can('edit_others_shop_orders')&&current_user_can('kiwe_manage_context'):$can;
   $ops[$id]=['scope'=>$scope,'label'=>$label,'effect'=>'write','approval'=>$approval,'accessVersion'=>1,'inputSchema'=>$input,'outputSchema'=>$out,'permission'=>$permission,'discoverPermission'=>$permission,'prepare'=>static fn($a)=>$s->prepare($kind,$a),'current'=>static fn($a)=>$s->prepare($kind,$a)['nativeExpected'],'execute'=>static fn($a,$expected)=>$s->apply($kind,$a,$expected)];
  }
  return$ops;
 }
}
