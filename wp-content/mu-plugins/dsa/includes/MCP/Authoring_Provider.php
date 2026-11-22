<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

/** Additional content scopes never expand an existing connection's approved pins. */
final class Authoring_Provider {
 public const MINIMUM_CORE='8.0.0-rc.483';
 public static function supported_core(string $version): bool{return version_compare($version,self::MINIMUM_CORE,'>=');}
 private static function text(int $n): array{return['type'=>'string','maxLength'=>$n];}
 private static function integer(int $min=0): array{return['type'=>'integer','minimum'=>$min];}
 private static function choice(array $v): array{return['type'=>'string','enum'=>$v,'maxLength'=>100];}
 private static function object(array $p,array $required=[]): array{return['type'=>'object','properties'=>$p,'required'=>$required,'additionalProperties'=>false];}
 private static function ids(int $n=40): array{return['type'=>'array','items'=>['type'=>'integer','minimum'=>1],'maxItems'=>$n];}
 public static function operations(): array{
  if(!defined('DSA_VERSION')||!self::supported_core(DSA_VERSION)||!defined(\DSA\Access\Taxonomy_App_Service::class.'::AUTHORING_API')||!defined(\DSA\Access\Tag_Group_Service::class.'::AUTHORING_API'))return[];
  $s=new Authoring_Service();$can=static fn()=>Authoring_Service::allowed();$out=self::object(['resultJson'=>self::text(6000)],['resultJson']);$type=self::choice(['post','page']);
  $read=self::object(['area'=>self::choice(Authoring_Service::AREAS),'page'=>self::integer(1),'hasMore'=>['type'=>'boolean'],'expected'=>self::text(64),'dataJson'=>self::text(14000),'guidance'=>self::text(1500)],['area','page','hasMore','expected','dataJson','guidance']);
  $operations=['mcp.authoring.read'=>['scope'=>'mcp.authoring.read','label'=>'Read native drafts, editable media and content groups for authoring','effect'=>'read','approval'=>'none','accessVersion'=>1,'inputSchema'=>self::object(['area'=>self::choice(Authoring_Service::AREAS),'type'=>$type,'id'=>self::integer(),'page'=>self::integer(1),'offset'=>self::integer(),'taxonomy'=>self::text(100),'kind'=>self::choice(['post']),'search'=>self::text(100)],['area']),'outputSchema'=>$read,'permission'=>$can,'discoverPermission'=>$can,'execute'=>static fn($a)=>$s->read($a)]];
  $fields=self::object(['title'=>self::text(200),'content'=>self::text(7000),'status'=>self::choice(['draft','pending','publish','private','future']),'scheduledLocal'=>self::text(16),'categories'=>self::ids(),'tags'=>self::ids(),'featuredImageId'=>self::integer(),'commentsAllowed'=>['type'=>'boolean']]);
  $content=self::object(['type'=>$type,'id'=>self::integer(),'fields'=>$fields],['type','id','fields']);
  $defs=[
   'mcp.content.save'=>['save','Save an agreed native post or page draft',$content,'mcp.authoring.write','none'],
   'mcp.content.publish'=>['publish','Review publishing or changing native published content',$content,'mcp.authoring.publish','required'],
   'mcp.content.trash'=>['trash','Review recoverable native content Trash or restore',self::object(['type'=>$type,'id'=>self::integer(1),'action'=>self::choice(['trash','restore'])],['type','id','action']),'mcp.authoring.write','required'],
   'mcp.taxonomy.change'=>['taxonomy','Create or rename a native category or tag',self::object(['taxonomy'=>self::text(100),'id'=>self::integer(),'fields'=>self::object(['name'=>self::text(200),'parent'=>self::integer()],['name'])],['taxonomy','id','fields']),'mcp.authoring.write','none'],
   'mcp.tag-group.change'=>['tag-group','Change native tag groups and their exact selected members',self::object(['kind'=>self::choice(['post']),'action'=>self::choice(['create','rename','move']),'id'=>self::integer(),'fields'=>self::object(['name'=>self::text(100),'sourceId'=>self::integer(),'targetId'=>self::integer(),'tagIds'=>self::ids(20)])],['kind','action','id','fields']),'mcp.authoring.write','none'],
   'mcp.media.change'=>['media','Import checksum-pinned public media or edit native media text',self::object(['action'=>self::choice(['import','update']),'id'=>self::integer(),'fieldsJson'=>self::text(7000)],['action','id','fieldsJson']),'mcp.authoring.write','none'],
  ];
  foreach($defs as$id=>[$kind,$label,$input,$scope,$approval]){
   if($kind==='media')$input['properties']['fieldsJson']['description']='Native fields: '.implode(', ',Media_Authoring::all_fields()).'. Import uses an exact public source and checksum; update edits only native media text.';
   $args=static function($a)use($kind){if($kind==='media'){$a['fields']=Authoring_Service::object($a['fieldsJson'],Media_Authoring::all_fields());unset($a['fieldsJson']);}return$a;};
   $permission=static fn($a)=>Authoring_Service::kind_allowed($kind)&&(!in_array($kind,['save','publish','trash'],true)||Authoring_Service::can_record($a['type'],$a['id']));
   $operations[$id]=['scope'=>$scope,'label'=>$label,'effect'=>'write','approval'=>$approval,'accessVersion'=>1,'inputSchema'=>$input,'outputSchema'=>$out,'permission'=>$permission,'discoverPermission'=>static fn()=>Authoring_Service::kind_allowed($kind),'prepare'=>static fn($a)=>$s->prepare($kind,$args($a)),'current'=>static fn($a)=>$s->prepare($kind,$args($a))['nativeExpected'],'execute'=>static fn($a,$expected)=>$s->apply($kind,$args($a),$expected)];
  }return$operations;
 }
}
