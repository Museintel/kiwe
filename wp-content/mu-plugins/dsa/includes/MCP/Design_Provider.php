<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

/** Explicit public design evidence; creative generation stays in the connected client. */
final class Design_Provider {
 public static function input_schema():array {
  $text=static fn($n)=>['type'=>'string','maxLength'=>$n];
  return['type'=>'object','properties'=>[
   'area'=>['type'=>'string','enum'=>['guide','sitegraph','framework','catalogue'],'maxLength'=>30],
   'resource'=>['type'=>'string','enum'=>['products','posts','pages','media','terms','menus'],'maxLength'=>30],
   'page'=>['type'=>'integer','minimum'=>1,'maximum'=>1000],
   'search'=>$text(80),'taxonomy'=>$text(80),'postType'=>$text(80),
   'part'=>['type'=>'integer','minimum'=>0,'maximum'=>349],
   'expected'=>$text(64),
  ],'required'=>['area'],'additionalProperties'=>false];
 }
 public static function operations():array {
  if(!defined('DSA_VERSION')||version_compare(DSA_VERSION,'8.0.0-rc.485','<')||!defined(\DSA\Site_Graph\Design_Context_Service::class.'::MCP_DESIGN_API'))return[];
  $can=static fn()=>Design_Service::allowed();
  return['mcp.design.read'=>['scope'=>'mcp.design.read','label'=>'Read public design evidence and the Start preview workflow','effect'=>'read','approval'=>'none','accessVersion'=>1,
   'inputSchema'=>self::input_schema(),'outputSchema'=>['type'=>'object','properties'=>['resultJson'=>['type'=>'string','maxLength'=>24000]],'required'=>['resultJson'],'additionalProperties'=>false],
   'discoverPermission'=>$can,'permission'=>$can,'execute'=>static fn($args)=>(new Design_Service())->read($args)]];
 }
}
