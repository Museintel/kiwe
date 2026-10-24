<?php
namespace DSA\Runtime;
if (!defined('ABSPATH')) exit;

/** The ordinary visitor checks only known markers, never updater scratch or a directory inventory. */
final class Release_Storage {
 public static function app_path(): string { return Private_Storage::path('apps'); }
 public static function apps(): string { return Private_Storage::directory('apps',true); }
 public static function app_marker(string $id): string {
  if(!in_array($id,\DSA\Apps\Runtime_Contract::IDS,true))throw new \RuntimeException('Unknown App marker.');
  return self::app_path().'/'.$id.'-boot.pending';
 }
 public static function pending_app(): bool {
  try{foreach(\DSA\Apps\Runtime_Contract::IDS as$id)if(is_file(self::app_marker($id)))return true;}
  catch(\Throwable){/* No installation can begin without verified private storage. */}
  return false;
 }
 public static function downloads(): string {
  $root=self::apps().'/downloads';if(is_link($root)||(!is_dir($root)&&!wp_mkdir_p($root)))throw new \RuntimeException('Private App download storage is unavailable.');
  @chmod($root,0700);return$root;
 }
}
