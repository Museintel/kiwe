<?php
namespace DSA\Apps;
if (!defined('ABSPATH')) exit;

/** Optional external-client connection; no credentials, transport or site scans on render. */
final class MCP_App_Service extends Optional_App_Service {
 public const ID='mcp', NAME='MCP', CAPABILITY='kiwe_manage_context', PAGE='kiwe-mcp-app';
 public const DESCRIPTION='Connect your AI to guide website setup and permitted tasks across Kiwe and its Apps.';
 public static function requested(): bool { return in_array(get_option(static::option(),'0'),[true,1,'1'],true); }
 public static function can_connect(): bool {
  return is_user_logged_in() && !\DSA\Access\Team_Workspace_Service::suspended(get_current_user_id()) && (current_user_can('kiwe_manage_context') || current_user_can('edit_posts') || current_user_can('edit_pages') || current_user_can('edit_products'));
 }
 /** Prepare storage during the already-explicit On action; never on a visitor render. */
 public static function change(bool $enabled,string $expected) {
  if($enabled && self::allowed() && Runtime_Registry::installed(self::ID) && hash_equals(self::expected(),$expected)){
   try{\DSA\MCP\Records::install();}catch(\Throwable){return new \WP_Error('kiwe_mcp_storage','The connection could not be prepared. Ask your website manager to check the database and retry.');}
  }
  return parent::change($enabled,$expected);
 }
 /** Fixed request-path admission only; PHP services stay outside ordinary site requests. */
 public static function relevant_request(): bool {
  $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
  $route=is_string($_GET['rest_route']??null)?$_GET['rest_route']:'';
  $action=is_string($_REQUEST['action']??null)?$_REQUEST['action']:'';
  $page=is_string($_GET['page']??null)?$_GET['page']:'';
  return str_contains($path,'/kiwe-mcp/v1/') || str_starts_with($route,'/kiwe-mcp/v1/')
   || str_contains($path,'/.well-known/oauth-authorization-server') || str_contains($path,'/.well-known/openid-configuration') || str_contains($path,'/.well-known/oauth-protected-resource')
   || str_starts_with($action,'kiwe_mcp_') || $page===self::PAGE || isset($_GET['kiwe_bricks_draft_preview']);
 }
 public function register(): void {
  parent::register();
  // A deactivation/settings transition invalidates delegated access before any later re-enable.
  add_action('updated_option',static function($name,$old,$new): void {
   $off=$name===self::option()&&$old!==$new&&!in_array($new,[true,1,'1'],true);
   $deactivated=$name==='active_plugins'&&is_array($old)&&is_array($new)&&in_array('kiwe-mcp/kiwe-mcp.php',$old,true)&&!in_array('kiwe-mcp/kiwe-mcp.php',$new,true);
   if(!$off&&!$deactivated)return;
   update_option('kiwe_mcp_revocation_epoch_v1',wp_generate_uuid4(),false);
  },10,3);
  if(!self::relevant_request())return;
  // WordPress creates WP_Rewrite after plugins_loaded. REST URL resolution and
  // the maintained adapter need that native object, while still preceding init.
  add_action('after_setup_theme',static function(): void {
   if(self::enabled() && class_exists('DSA\\MCP\\Engine')) (new \DSA\MCP\Engine())->register();
  },35);
 }
}
