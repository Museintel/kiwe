<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

/** A closed JSON-Schema subset: no external references, transforms, regex or unknown fields. */
final class Schema {
 private const KEYS=['type','properties','required','additionalProperties','items','enum','minimum','maximum','minLength','maxLength','minItems','maxItems','description'];
 /** JSON Schema maps must stay objects on the wire, including an empty map.
  * Internal array schemas/pins remain unchanged for existing connections. */
 public static function wire(array $schema): array {
  if(($schema['type']??'')==='object'){
   $properties=[];foreach($schema['properties']??[] as$key=>$child)$properties[$key]=self::wire($child);
   $schema['properties']=(object)$properties;
  }elseif(($schema['type']??'')==='array'&&isset($schema['items']))$schema['items']=self::wire($schema['items']);
  return$schema;
 }
 public static function contract($s,int $depth=0): bool {
  if(!is_array($s)||$depth>8||strlen((string)wp_json_encode($s))>16384||array_diff(array_keys($s),self::KEYS)
   ||!in_array($s['type']??null,['object','array','string','integer','number','boolean'],true))return false;
  if($depth===0&&$s['type']!=='object')return false;
  if(isset($s['description'])&&(!is_string($s['description'])||strlen($s['description'])>500))return false;
  if(isset($s['enum'])&&(!is_array($s['enum'])||count($s['enum'])>64||array_filter($s['enum'],fn($v)=>!is_scalar($v))))return false;
  foreach(['minimum','maximum','minLength','maxLength','minItems','maxItems']as$key)if(isset($s[$key])&&(!is_int($s[$key])||$s[$key]<0||$s[$key]>65536))return false;
  if($s['type']==='object'){
   if(($s['additionalProperties']??null)!==false||!is_array($s['properties']??null)||count($s['properties'])>48||!is_array($s['required']??[]))return false;
   foreach($s['properties']as$key=>$child)if(!is_string($key)||preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D',$key)!==1||!self::contract($child,$depth+1))return false;
   foreach($s['required']??[]as$key)if(!is_string($key)||!array_key_exists($key,$s['properties']))return false;
  }elseif($s['type']==='array'){
   if(!isset($s['maxItems'])||$s['maxItems']>256||!self::contract($s['items']??null,$depth+1))return false;
  }elseif($s['type']==='string'&&(!isset($s['maxLength'])||$s['maxLength']>65536))return false;
  return true;
 }
 public static function value($v,array $s,int $depth=0): bool {
  if($depth>8)return false;
  if(isset($s['enum'])&&!in_array($v,$s['enum'],true))return false;
  switch($s['type']){
   case 'object':
    if(!is_array($v)||($v!==[]&&array_is_list($v))||array_diff(array_keys($v),array_keys($s['properties'])))return false;
    foreach($s['required']??[]as$key)if(!array_key_exists($key,$v))return false;
    foreach($v as$key=>$item)if(!self::value($item,$s['properties'][$key],$depth+1))return false;
    return true;
   case 'array':
    if(!is_array($v)||!array_is_list($v)||count($v)>$s['maxItems']||count($v)<($s['minItems']??0))return false;
    foreach($v as$item)if(!self::value($item,$s['items'],$depth+1))return false;return true;
   case 'string':return is_string($v)&&strlen($v)<=($s['maxLength']??0)&&strlen($v)>=($s['minLength']??0);
   case 'boolean':return is_bool($v);
   case 'integer':case 'number':return ($s['type']==='integer'?is_int($v):(is_int($v)||is_float($v)))&&is_finite((float)$v)&&$v>=($s['minimum']??-INF)&&$v<=($s['maximum']??INF);
  }return false;
 }
}
