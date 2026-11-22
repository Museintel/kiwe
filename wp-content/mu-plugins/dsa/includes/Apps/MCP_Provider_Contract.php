<?php
namespace DSA\Apps;
if (!defined('ABSPATH')) exit;

/** Shared, inert ABI for current/future Apps. It grants no runtime or provider authority. */
final class MCP_Provider_Contract {
 public const API=1;
 public const SCHEMA='kiwe.mcp-provider.v1';
 public const SETUP_STATES=['unconfigured','needs-owner','needs-permissions','needs-business-facts','ready','not-applicable','unavailable'];
 public static function compatible(int $api): bool { return $api===self::API; }
 /** Called by an App bootstrap; its factory is evaluated only by an explicit MCP request. */
 public static function register(string $app,int $api,callable $factory): bool {
  if(!self::id($app)||!self::compatible($api))return false;
  add_action('kiwe_mcp_register_providers',static function($registry,int $runtimeApi)use($app,$api,$factory):void{
   if(self::compatible($runtimeApi))$registry->register($app,$api,$factory);
  },10,2);return true;
 }
 public static function id(string $id): bool { return Runtime_Contract::id($id); }
 public static function operation(string $id,string $app): bool {
  return self::id($app) && strlen($id)<=100 && str_starts_with($id,$app.'.') && preg_match('/^[a-z][a-z0-9.-]+$/D',$id)===1;
 }
}
