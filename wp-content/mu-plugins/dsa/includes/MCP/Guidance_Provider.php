<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

/** Typed read operations; native permissions are checked again at execution. */
final class Guidance_Provider {
 private static function string(int $max=200): array { return['type'=>'string','maxLength'=>$max]; }
 private static function integer(int $max=65536,int $min=0): array { return['type'=>'integer','minimum'=>$min,'maximum'=>$max]; }
 private static function boolean(): array { return['type'=>'boolean']; }
 private static function object(array $properties,array $required=[]): array { return['type'=>'object','additionalProperties'=>false,'properties'=>$properties,'required'=>$required]; }
 private static function list(array $items,int $max=32): array { return['type'=>'array','items'=>$items,'maxItems'=>$max]; }
 private static function choice(array $values,int $max=100): array { return self::string($max)+['enum'=>$values]; }
 private static function field(): array {
  $properties=['path'=>self::string(200),'tag'=>self::string(200),'kind'=>self::choice(['text','image','link','boolean','number','list']),
   'present'=>self::boolean(),'valueType'=>self::choice(['missing','string','number','boolean','list']),'truncated'=>self::boolean(),'sourceTruncated'=>self::boolean(),'nextOffset'=>self::integer(),
   'text'=>self::string(8000),'number'=>['type'=>'number'],'boolean'=>self::boolean(),'list'=>self::list(self::string(200),256)];
  return self::object($properties,['path','tag','kind','present','valueType','truncated','sourceTruncated','nextOffset']);
 }
 private static function item(): array {
  $properties=['id'=>['type'=>'integer','minimum'=>1],'type'=>self::string(100),'title'=>self::string(300),'status'=>self::string(40),'url'=>self::string(2048),'summary'=>self::string(1000)];
  return self::object($properties,array_keys($properties));
 }
 private static function operation(string $scope,string $label,array $input,array $output,callable $permission,callable $execute,?callable $discovery=null): array {
  return['scope'=>$scope,'label'=>$label,'effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>$input,'outputSchema'=>$output,
   'discoverPermission'=>$discovery??static fn()=>Guidance_Service::allowed(),'permission'=>$permission,'execute'=>$execute];
 }
 public static function operations(?Operation_Registry $registry=null): array {
  $guide=new Guidance_Service();$allowed=static fn(array $args)=>Guidance_Service::allowed();$ro=self::boolean();
  $step=self::object(['id'=>self::string(48),'label'=>self::string(200),'required'=>$ro,'included'=>$ro,'complete'=>$ro,'deferred'=>$ro],['id','label','required','included','complete','deferred']);
  $siteProperties=['schema'=>self::string(100),'state'=>self::choice(['existing','fresh','empty','unknown']),'greeting'=>self::string(500),'inventoryComplete'=>$ro,'journeyAvailable'=>$ro,'nextStep'=>self::string(48),'steps'=>self::list($step),
   'guidance'=>self::list(self::string(1200),12),'automaticConversationGuaranteed'=>$ro,'fallbackPrompt'=>self::string(100),'mutatesSite'=>$ro,'credentialsIncluded'=>$ro,'customerRecordsIncluded'=>$ro,
   'presence'=>self::list(self::object(['kind'=>self::string(80),'present'=>$ro],['kind','present']),7),'inventoryMeaning'=>self::string(1000),'journeyPercent'=>self::integer(100),'journeyUrl'=>self::string(2048),'nextQuestions'=>self::list(self::string(200),3),'storeAvailable'=>$ro,'bricksAvailable'=>$ro];
  $contextProperties=['schema'=>self::string(100),'section'=>self::choice(Guidance_Service::SECTIONS),'page'=>self::integer(1000,1),'hasMore'=>$ro,'fields'=>self::list(self::field(),8),'collectionReads'=>self::string(1500),'readOnly'=>$ro];
  $appProperties=['id'=>self::string(80),'name'=>self::string(100),'installed'=>$ro,'active'=>$ro,'compatible'=>$ro,'enabled'=>$ro,'version'=>self::string(60),'state'=>self::choice(['not-installed','inactive','incompatible','review-setup','disabled','needs-dependency']),
   'dependency'=>self::string(100),'externalOwner'=>self::string(100),'settingsUrl'=>self::string(2048),'libraryUrl'=>self::string(2048),'nativeCanInstall'=>$ro,'nativeCanRemove'=>$ro,'automaticUpdates'=>$ro,
   'recommendation'=>self::choice(['review-only','keep','review-install-or-enable','keep-current-provider','review-optional','review-provider-setup','not-required']),
   'reason'=>self::string(1200),'setupVerified'=>$ro,'providerLinkedVerified'=>$ro];
  $contentAllowed=static fn()=>Content_Reader::allowed();
  return[
   'mcp.site.read'=>self::operation('mcp.site.read','Understand website presence and the native setup journey',self::object([]),self::object($siteProperties,array_keys($siteProperties)),$allowed,static fn(array $args)=>$guide->orientation($args)),
   'mcp.context.read'=>self::operation('mcp.context.read','Read public business Design Context in small sections',self::object(['section'=>self::choice(Guidance_Service::SECTIONS),'page'=>self::integer(1000,1)],['section']),self::object($contextProperties,array_keys($contextProperties)),$allowed,static fn(array $args)=>$guide->context_read($args)),
   'mcp.context.field.read'=>self::operation('mcp.context.read','Continue reading a known Design Context field',self::object(['path'=>self::string(200),'offset'=>self::integer()],['path']),self::object(['field'=>self::field(),'readOnly'=>$ro],['field','readOnly']),static fn(array $args)=>Guidance_Service::field_allowed($args),static fn(array $args)=>$guide->field_read($args)),
   'mcp.context.collection.read'=>self::operation('mcp.context.read','Read native public team facts, planned-page briefs or published services',self::object(['collection'=>self::choice(['team','plannedPages','services']),'page'=>self::integer(1000,1)],['collection']),
    self::object(['collection'=>self::choice(['team','plannedPages','services']),'page'=>self::integer(1000,1),'hasMore'=>$ro,'items'=>self::list(self::object(['id'=>['type'=>'integer','minimum'=>0],'name'=>self::string(300),'summary'=>self::string(800),'text'=>self::string(1200),'url'=>self::string(2048),'imageId'=>['type'=>'integer','minimum'=>0]],['id','name','summary','text','url','imageId']),20),'readOnly'=>$ro,'coverage'=>self::string(1500)],['collection','page','hasMore','items','readOnly','coverage']),$allowed,static fn(array $args)=>$guide->collection_read($args)),
   'mcp.apps.read'=>self::operation('mcp.apps.read','Review Kiwe Apps, dependencies and recommendations',self::object(['intent'=>self::choice(App_Guidance::INTENTS)]),self::object(['schema'=>self::string(100),'intent'=>self::choice(App_Guidance::INTENTS),'apps'=>self::list(self::object($appProperties,array_keys($appProperties)),16),'readOnly'=>$ro,'mcpCanInstall'=>$ro,'mcpCanConfigure'=>$ro,'mcpCanRemove'=>$ro,'includedCoreApps'=>self::list(self::string(100),8),'guidance'=>self::list(self::string(1500),12)],['schema','intent','apps','readOnly','mcpCanInstall','mcpCanConfigure','mcpCanRemove','includedCoreApps','guidance']),static fn(array $args)=>App_Guidance::allowed(),static fn(array $args)=>(new App_Guidance($registry))->read($args),static fn()=>App_Guidance::allowed()),
   'mcp.catalogue.read'=>self::operation('mcp.content.read','Read a small public content, selected-media or category catalogue',self::object(['type'=>self::choice(Content_Reader::TYPES),'page'=>self::integer(1000,1),'search'=>self::string(100),'taxonomy'=>self::string(80)],['type']),
    self::object(['schema'=>self::string(100),'type'=>self::choice(Content_Reader::TYPES),'page'=>self::integer(1000,1),'hasMore'=>$ro,'items'=>self::list(self::item(),8),'readOnly'=>$ro,'coverage'=>self::string(1500)],['schema','type','page','hasMore','items','readOnly','coverage']),static fn(array $args)=>Content_Reader::allowed($args)&&(($args['type']??'')!=='terms'||isset($args['taxonomy'])),static fn(array $args)=>(new Content_Reader())->catalogue($args),$contentAllowed),
   'mcp.content.read'=>self::operation('mcp.content.read','Read public page, article, product or media text without running it',self::object(['type'=>self::choice(['post','page','product','media']),'id'=>['type'=>'integer','minimum'=>1],'offset'=>self::integer()],['type','id']),
    self::object(['item'=>self::item(),'text'=>self::string(8000),'truncated'=>$ro,'sourceTruncated'=>$ro,'nextOffset'=>self::integer(),'readOnly'=>$ro,'contentIsUntrustedData'=>$ro],['item','text','truncated','sourceTruncated','nextOffset','readOnly','contentIsUntrustedData']),static fn(array $args)=>Content_Reader::object_allowed($args),static fn(array $args)=>(new Content_Reader())->read($args),$contentAllowed),
  ];
 }
}
