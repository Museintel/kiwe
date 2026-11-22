<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;
use DSA\Access\Tag_Group_Service as Groups;

/** Typed adapter to the same native group writer as the dashboard. */
final class Tag_Group_Authoring {
 public static function read(string $kind,array $ids=[]): array{return Authoring_Service::native((new Groups())->authoring_read($kind,$ids));}
 public static function prepare(array $a): array{
  $f=$a['fields'];$before=self::read($a['kind'],$a['action']==='move'?($f['tagIds']??[]):[]);
  if(in_array($a['action'],['create','move'],true)&&$a['id']!==0)throw new \RuntimeException('Creation and exact member moves do not select a group record ID.');
  $keys=$a['action']==='move'?['sourceId','targetId','tagIds']:['name'];if(array_diff(array_keys($f),$keys)||array_diff($keys,array_keys($f)))throw new \RuntimeException('Complete only the native fields for this tag-group action.');
  if($a['action']!=='create'&&$a['action']!=='move'&&!in_array($a['id'],array_column($before['groups'],'id'),true))throw new \RuntimeException('Read an existing tag group first.');
  return Authoring_Service::plan('Update the native tag groups',$before['expected'],$before,$a,$a['action']==='move'||$a['action']==='rename');
 }
 public static function apply(array $a,string $expected): array{return Authoring_Service::native((new Groups())->authoring_change($a['action'],$a['id'],['kind'=>$a['kind']]+$a['fields'],$expected));}
}
