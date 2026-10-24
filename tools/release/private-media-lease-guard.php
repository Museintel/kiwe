<?php
/** Temporary operator lease. Captured code is inert at another root or after expiry. */
if(!defined('ABSPATH'))return;
return static function(array $c):bool{
 if(realpath(rtrim(ABSPATH,'/\\'))!==$c['root'])return false;
 $file=$c['private'].'/media-lease.private.json';$s=@lstat($file);
 if(!$s||is_link($file)||($s['mode']&0170000)!==0100000||$s['nlink']!==1||($s['mode']&0077)||$s['size']>4096)return false;
 $lease=json_decode(file_get_contents($file),true);
 if(!is_array($lease)||($lease['schema']??null)!=='kiwe.private-media-lease.v1'||($lease['root']??null)!==$c['root']||($lease['active']??false)!==true||!is_int($lease['issuedAt']??null)||!is_int($lease['expiresAt']??null)||$lease['issuedAt']>time()||$lease['expiresAt']<=time()||$lease['expiresAt']-$lease['issuedAt']>5400)return false;
 $message='Media uploads are paused while a backup is being prepared. Try again shortly.';
 $upload=static function(array $file)use($message):array{$file['error']=$message;return$file;};
 add_filter('wp_handle_upload_prefilter',$upload,PHP_INT_MAX);
 add_filter('wp_handle_sideload_prefilter',$upload,PHP_INT_MAX);
 add_filter('pre_delete_attachment',static fn()=>false,PHP_INT_MAX);
 add_filter('wp_image_editors',static fn()=>[],PHP_INT_MAX);
 add_filter('compressx_allowed_image_auto_optimization',static fn()=>false,PHP_INT_MAX);
 add_filter('rest_pre_dispatch',static function($result,$server,$request)use($message){if(preg_match('#^/wp/v2/media(?:/|$)#D',$request->get_route())&&in_array($request->get_method(),['POST','PUT','PATCH','DELETE'],true))return new WP_Error('kiwe_media_backup_lease',$message,['status'=>503]);return$result;},PHP_INT_MAX,3);
 add_action('admin_init',static function()use($message){$action=is_string($_REQUEST['action']??null)?$_REQUEST['action']:'';if($_FILES||str_starts_with($action,'compressx_')||in_array($action,['upload-attachment','image-editor','crop-image','media-create-image-subsizes'],true)){if(!headers_sent())header('Retry-After: 300');wp_die($message,'Backup in progress',['response'=>503]);}},PHP_INT_MIN);
 add_action('admin_notices',static function()use($message){echo'<div class="notice notice-info"><p>'.esc_html($message).'</p></div>';});
 return true;
};
