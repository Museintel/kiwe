<?php
namespace DSA\Secure;
if(!defined('ABSPATH'))exit;

/** Authored content is checked by its native nonce/capability/sanitization owner. */
final class SecureTrack_Payload_Service {
 /** Late inspection after a native adapter has admitted its authored fields. */
 public static function allows_body(string $raw): bool {
  if(!function_exists('stp_scan_payload')||!function_exists('stp_cfg')||empty(stp_cfg()['adaptive_waf'])||stp_enforcement_paused())return true;
  return (int)stp_scan_payload($raw)['score']<(int)(stp_cfg()['waf_block_score']??80);
 }
 public static function authored_fields(): array {
  if(!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD']??'')),['POST','PUT','PATCH'],true)||!is_user_logged_in())return[];
  $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
  if(str_ends_with($path,'/wp-admin/post.php')){
   $id=absint($_POST['post_ID']??0);$nonce=$_POST['_wpnonce']??null;
   if(($_POST['action']??'')==='editpost'&&$id&&is_string($nonce)&&wp_verify_nonce(wp_unslash($nonce),'update-post_'.$id)&&current_user_can('edit_post',$id))return['post_title','content','excerpt'];
  }
  if(str_ends_with($path,'/wp-admin/admin-ajax.php')&&($_POST['action']??'')==='bricks_save_post'&&($_REQUEST['action']??'')==='bricks_save_post'){
   $id=absint($_POST['postId']??0);$nonce=$_POST['nonce']??null;
   if($id&&is_string($nonce)&&wp_verify_nonce(wp_unslash($nonce),'bricks-nonce-builder')&&current_user_can('edit_post',$id)&&current_user_can('unfiltered_html')&&is_callable(['Bricks\\Capabilities','current_user_can_use_builder'])&&\Bricks\Capabilities::current_user_can_use_builder($id))return['header','content','footer','components'];
  }
  $nonce=$_SERVER['HTTP_X_WP_NONCE']??($_GET['_wpnonce']??null);if(!is_string($nonce)||!wp_verify_nonce(wp_unslash($nonce),'wp_rest'))return[];
  $route=is_string($_GET['rest_route']??null)?trim($_GET['rest_route'],'/'):'';
  if($route===''){$base=(string)wp_parse_url(rest_url(),PHP_URL_PATH);if($base!==''&&str_starts_with($path,$base))$route=trim(substr($path,strlen($base)),'/');}
  foreach(get_post_types(['show_in_rest'=>true],'objects')as$type){
   $prefix=($type->rest_namespace?:'wp/v2').'/'.($type->rest_base?:$type->name);
   if(!preg_match('~^'.preg_quote($prefix,'~').'(?:/([1-9][0-9]*)(?:/(?:autosaves|revisions))?)?$~D',$route,$m))continue;
   $id=isset($m[1])?(int)$m[1]:0;
   if($id){$post=get_post($id);if(!$post||$post->post_type!==$type->name||!current_user_can('edit_post',$id))return[];}
   elseif(!current_user_can($type->cap->create_posts))return[];
   return['title','content','excerpt'];
  }
  return[];
 }
 public static function body(string $raw,array $fields): string {
  if(!$fields)return$raw;
  $value=json_decode($raw,true);if(is_array($value)&&!array_is_list($value)){foreach($fields as$key)unset($value[$key]);return(string)wp_json_encode($value);}
  $contentType=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
  // PHP already parsed this native form; its non-authored fields remain scanned.
  if(str_starts_with($contentType,'application/x-www-form-urlencoded')||str_starts_with($contentType,'multipart/form-data'))return'';
  return$raw;
 }
}
