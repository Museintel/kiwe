<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** One native lease shared by Core and App writers. No public request hook. */
final class Release_Coordinator {
 private static ?string $token=null;
 public static function acquire(): bool {
  if(self::$token!==null)return false;
  global$wpdb;$key='kiwe-release-'.substr(hash('sha256',(defined('DB_NAME')?DB_NAME:'').'|'.$wpdb->prefix),0,32);
  if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key)))return false;
  self::$token=$key;return true;
 }
 public static function release(): void {
  if(self::$token===null)return;
  global$wpdb;$key=self::$token;self::$token=null;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));
 }
}
