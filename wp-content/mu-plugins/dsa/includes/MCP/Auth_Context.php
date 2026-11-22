<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

/** Request-local resource authority; never a WordPress authentication provider. */
final class Auth_Context {
 private static ?Authorization_Service $auth=null;
 private static string $bearer='';
 public static function establish(Authorization_Service $auth,string $bearer): array {
  self::clear();$grant=$auth->authenticate($bearer);self::$auth=$auth;self::$bearer=$bearer;wp_set_current_user($grant['actor']);return$grant;
 }
 public static function clear(): void { self::$auth=null;self::$bearer=''; }
 public static function current(): ?array {
  if(!self::$auth||self::$bearer==='')return null;
  try{$grant=self::$auth->authenticate(self::$bearer);return get_current_user_id()===$grant['actor']?$grant:null;}catch(\Throwable){self::clear();return null;}
 }
}
