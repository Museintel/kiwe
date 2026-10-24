<?php
// Synthetic physical option boundary. Real SQL is qualified by the native handover fixture.
if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
if (!function_exists('maybe_serialize')) { function maybe_serialize($v) { return is_array($v)||is_object($v)?serialize($v):(string)$v; } }
if (!function_exists('wp_cache_delete')) { function wp_cache_delete($k,$g='') { return true; } }
final class SeoDefaultsOptionDB {
 public string $options='wp_options',$dbname='synthetic',$prefix='wp_',$last_error='';
 public function prepare($sql,...$args) { return ['sql'=>$sql,'args'=>$args]; }
 public function get_var($p) { return str_contains($p['sql'],'RELEASE_LOCK')?1:(empty($GLOBALS['seo_defaults_lease_lost'])?1:0); }
 public function get_results($p,$output) {
  $name=$p['args'][0];if(!array_key_exists($name,$GLOBALS['options']))return [];
  return [['option_id'=>abs(crc32($name))+1,'bytes'=>strlen(maybe_serialize($GLOBALS['options'][$name]))]];
 }
 public function get_row($p,$output) {
  $name=$p['args'][1];if(!array_key_exists($name,$GLOBALS['options']))return null;
  return ['option_id'=>abs(crc32($name))+1,'bytes'=>maybe_serialize($GLOBALS['options'][$name])];
 }
 public function query($p) {
  if(isset($GLOBALS['seo_defaults_query_hook']))($GLOBALS['seo_defaults_query_hook'])($p['sql']);
  if(!empty($GLOBALS['failWrite'])||!empty($GLOBALS['fail_option']))return false;
  if(!empty($GLOBALS['seo_defaults_lease_lost']))return 0;
  $sql=$p['sql'];$a=$p['args'];
  if(str_starts_with($sql,'INSERT')){[$name,$next]=$a;if(array_key_exists($name,$GLOBALS['options']))return 0;}
  elseif(str_starts_with($sql,'UPDATE')){[$next,$id,$name,$before]=$a;if(!array_key_exists($name,$GLOBALS['options'])||maybe_serialize($GLOBALS['options'][$name])!==$before)return 0;}
  else{[$id,$name,$before]=$a;if(!array_key_exists($name,$GLOBALS['options'])||maybe_serialize($GLOBALS['options'][$name])!==$before)return 0;$next=null;}
  if($next===null)unset($GLOBALS['options'][$name]);else $GLOBALS['options'][$name]=unserialize($next,['allowed_classes'=>false]);
  $GLOBALS['option_writes'][]=[$name,false];return 1;
 }
}
$wpdb=new SeoDefaultsOptionDB();
require_once dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Settings_Handover_Service.php';
