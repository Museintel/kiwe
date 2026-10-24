<?php
namespace DSA\Onboarding;
use DSA\Schema\Schema_Template_Service as Templates;
if(!defined('ABSPATH'))exit;

/** Core conditional native search settings writer, shared by App editing and exact snapshot recovery. */
class Search_Settings_Writer {
 protected string $lock='';
 protected static function row(string $name,int $limit=196608):?array{
  global$wpdb;$size=$wpdb->get_results($wpdb->prepare("SELECT option_id,OCTET_LENGTH(option_value) bytes FROM {$wpdb->options} WHERE option_name=%s LIMIT 2",$name),ARRAY_A);
  if($wpdb->last_error||count($size)>1||$size&&(int)$size[0]['bytes']>$limit)throw new \RuntimeException('SEO settings exceed their reviewed read boundary.');
  if(!$size)return null;
  $row=$wpdb->get_row($wpdb->prepare("SELECT option_id,CAST(option_value AS BINARY) bytes FROM {$wpdb->options} WHERE option_id=%d AND option_name=%s",(int)$size[0]['option_id'],$name),ARRAY_A);
  if($wpdb->last_error||!$row||strlen($row['bytes'])>$limit)throw new \RuntimeException('SEO settings changed during review.');return ['id'=>(int)$row['option_id'],'bytes'=>$row['bytes']];
 }

 protected function owns():bool{global$wpdb;return $this->lock!==''&&(string)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)=CONNECTION_ID()',$this->lock))==='1';}

 protected function replace(string $name,?array $before,?string $after):bool{
  global$wpdb;if(!$this->owns())return false;
  if($before===null){if($after===null)return true;$sql=$wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) SELECT %s,%s,'no' WHERE IS_USED_LOCK(%s)=CONNECTION_ID()",$name,$after,$this->lock);if($wpdb->query($sql)!==1)return false;wp_cache_delete($name,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');}
  else{$sql=$after===null?$wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_id=%d AND option_name=%s AND BINARY option_value=%s AND IS_USED_LOCK(%s)=CONNECTION_ID()",$before['id'],$name,$before['bytes'],$this->lock):$wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_id=%d AND option_name=%s AND BINARY option_value=%s AND IS_USED_LOCK(%s)=CONNECTION_ID()",$after,$before['id'],$name,$before['bytes'],$this->lock);$n=$wpdb->query($sql);if($n!==1)return false;wp_cache_delete($name,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');}
  if($before===null){$value=Templates::decode($after);do_action('add_option_'.$name,$name,$value);do_action('added_option',$name,$value);}
  elseif($after===null){do_action('delete_option_'.$name,$name);do_action('deleted_option',$name);}
  else{$value=Templates::decode($after);$old=Templates::decode($before['bytes']);do_action('update_option_'.$name,$old,$value,$name);do_action('updated_option',$name,$old,$value);}
  return (self::row($name)['bytes']??null)===$after;
 }

 public static function compare_defaults(?array $before,?array $after):bool{
  if($after!==null&&Search_Defaults_Model::validate($after)===false)return false;
  global$wpdb;$service=new self();$service->lock='kiwe_defaults_'.substr(hash('sha256',(string)$wpdb->dbname.'|'.$wpdb->prefix),0,40);
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$service->lock))!=='1')return false;
  try{
   $row=self::row(Search_Defaults_Model::OPTION,65536);$expected=$before===null?null:maybe_serialize($before);$next=$after===null?null:maybe_serialize($after);
   if(($row['bytes']??null)!==$expected)return false;
   return $expected===$next?$service->owns():$service->replace(Search_Defaults_Model::OPTION,$row,$next);
  }catch(\Throwable$e){return false;}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$service->lock));}
 }
}
