<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

/** Version-gated native setup; no write scope is added to an existing grant. */
final class Setup_Provider {
 public const MINIMUM_CORE='8.0.0-rc.479';
 public static function supported_core(string $version): bool {return version_compare($version,self::MINIMUM_CORE,'>=');}
 public static function connected_writes(?Operation_Registry $registry): array {
  if(!$registry||!class_exists(Auth_Context::class,false))return[];$g=Auth_Context::current();if(!$g)return[];
  return array_column(array_filter($registry->describe($g['scopes'],$g['pins']),static fn($op)=>$op['effect']==='write'),'id');
 }
 private static function text(int $max=100): array{return['type'=>'string','maxLength'=>$max];}
 private static function integer(int $min=0,?int $max=null): array{return['type'=>'integer','minimum'=>$min]+($max===null?[]:['maximum'=>$max]);}
 private static function object(array $fields,array $required=[]): array{return['type'=>'object','properties'=>$fields,'required'=>$required,'additionalProperties'=>false];}
 private static function choice(array $values): array{return['type'=>'string','enum'=>array_values($values),'maxLength'=>100];}
 private static function operation(string $scope,string $label,array $input,array $output,callable $permission,callable $execute,callable $discover): array{return['scope'=>$scope,'label'=>$label,'effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>$input,'outputSchema'=>$output,'permission'=>$permission,'discoverPermission'=>$discover,'execute'=>$execute];}
 public static function operations(Operation_Registry $registry): array {
  if(!defined('DSA_VERSION')||!self::supported_core(DSA_VERSION)||!defined(\DSA\Access\Continuity_App_Service::class.'::MCP_SETUP_API')||\DSA\Access\Continuity_App_Service::MCP_SETUP_API!==1)return[];
  $s=new Setup_Service($registry);$out=self::object(['resultJson'=>self::text(6000)],['resultJson']);$context=static fn()=>Setup_Service::context_allowed();$settings=static fn()=>Setup_Service::settings_allowed();$nav=static fn()=>Setup_Service::navigation_allowed();$software=static fn()=>Setup_Service::software_allowed();
  $field=self::object(['path'=>self::text(200),'label'=>self::text(300),'definitionJson'=>self::text(3000),'valueJson'=>self::text(8000),'needsNativeRead'=>['type'=>'boolean']],['path','label','definitionJson','valueJson','needsNativeRead']);
  $readOut=self::object(['schema'=>self::text(100),'area'=>self::choice(Setup_Service::AREAS),'expected'=>self::text(64),'page'=>self::integer(1,1000),'hasMore'=>['type'=>'boolean'],'fields'=>['type'=>'array','items'=>$field,'maxItems'=>8],'nativeUrl'=>self::text(2048),'guidance'=>self::text(1500)],['schema','area','expected','page','hasMore','fields','nativeUrl','guidance']);
  $job=['app'=>self::choice(array_values(array_diff(\DSA\Apps\Runtime_Contract::IDS,['mcp']))),'job'=>self::text(36),'installationIntent'=>self::text(34)];
  $operations=[
   'mcp.setup.read'=>self::operation('mcp.setup.read','Read native edit-ready setup fields and their current rules',self::object(['area'=>self::choice(Setup_Service::AREAS),'page'=>self::integer(1,1000),'id'=>self::integer()],['area']),$readOut,static fn()=>Setup_Service::any_allowed(),static fn($a)=>$s->read($a),static fn()=>Setup_Service::any_allowed()),
   'mcp.navigation.read'=>self::operation('mcp.setup.read','Read a bounded native navigation group or its entries',self::object(['id'=>self::integer(),'page'=>self::integer(1,1000)]),$out,$nav,static fn($a)=>$s->navigation_read($a),$nav),
   'mcp.app.install.status'=>self::operation('mcp.apps.manage','Read this connection’s approved native installation job',self::object($job,array_keys($job)),$out,$software,static fn($a)=>$s->job_read($a),$software),
  ];
  $service=self::object(['recordId'=>self::integer(),'stableId'=>self::text(100),'title'=>self::text(500),'summary'=>self::text(2000),'description'=>self::text(5000),'status'=>self::choice(['draft','publish']),'imageId'=>self::integer(),'parentId'=>self::integer(),'menuOrder'=>self::integer(),'categoryPaths'=>self::text(1000)],['title']);
  $definitions=[
   'mcp.context.change'=>['context','Update one agreed public business field',self::object(['area'=>self::choice(['contact','story','brand','website-plan']),'field'=>self::text(160),'valueJson'=>self::text(8000)],['area','field','valueJson']),$context,'none'],
   'mcp.identity.change'=>['identity','Update one agreed business identity field',self::object(['field'=>self::text(100),'valueJson'=>self::text(8000)],['field','valueJson']),$context,'none'],
   'mcp.appshell.change'=>['appshell','Update an available Dock, screen or App appearance setting',self::object(['group'=>self::text(80),'field'=>self::text(100),'valueJson'=>self::text(8000)],['group','field','valueJson']),$settings,'none'],
   'mcp.feedback.change'=>['feedback','Review and change native feedback policy',self::object(['field'=>self::text(100),'valueJson'=>self::text(100)],['field','valueJson']),static fn()=>\DSA\Access\App_Workspace_Service::feedback_allowed(),'required'],
   'mcp.journey.change'=>['journey','Update an applicable native setup choice',self::object(['step'=>self::text(80),'action'=>self::choice(['include','later','off','resume','designer'])],['step','action']),$context,'none'],
   'mcp.services.activate'=>['service-activate','Review starting the native Services collection',self::object([]),$context,'required'],
   'mcp.service.save'=>['service','Save a native Service through its current writer',self::object(['record'=>$service],['record']),$context,'none'],
   'mcp.service.plan'=>['service-plan','Save a native service plan without replacing its neighbours',self::object(['record'=>$service],['record']),$context,'none'],
   'mcp.navigation.change'=>['navigation','Update native menu groups, labels or approved content links',self::object(['action'=>self::choice(['create','rename','configure','contents','add','label']),'menuId'=>self::integer(),'valueJson'=>self::text(8000)],['action','menuId','valueJson']),$nav,'none'],
   'mcp.app.change'=>['app','Review an optional App On or Off change',self::object(['app'=>$job['app'],'enabled'=>['type'=>'boolean']],['app','enabled']),$software,'required'],
   'mcp.app.install'=>['install','Review a signed native App installation and any Woo dependency',self::object(['app'=>$job['app'],'automatic'=>['type'=>'boolean']],['app','automatic']),$software,'required'],
   'mcp.app.install.advance'=>['install-advance','Continue this exact approved native App installation',self::object($job+['cursor'=>self::integer(0,10)],array_merge(array_keys($job),['cursor'])),$software,'none'],
   'mcp.app.install.cancel'=>['install-cancel','Cancel this connection’s native install with its existing recovery',self::object($job,array_keys($job)),$software,'none'],
  ];
  foreach($definitions as$id=>[$kind,$label,$input,$permission,$approval])$operations[$id]=[
   'scope'=>str_starts_with($id,'mcp.app.')?'mcp.apps.manage':'mcp.setup.write','label'=>$label,'effect'=>'write','approval'=>$approval,'accessVersion'=>1,
   'inputSchema'=>$input,'outputSchema'=>$out,'permission'=>in_array($kind,['service','service-plan'],true)?static fn($a)=>Setup_Service::service_allowed($a):$permission,'discoverPermission'=>$permission,
   'prepare'=>static fn($a)=>$s->prepare($kind,$a),'current'=>static fn($a)=>$s->prepare($kind,$a)['nativeExpected'],'execute'=>static fn($a,$expected)=>$s->apply($kind,$a,$expected),
  ];
  return$operations;
 }
}
