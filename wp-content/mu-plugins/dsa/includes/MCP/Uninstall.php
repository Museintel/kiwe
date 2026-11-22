<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

/** Native App removal discards delegated connections only; no business record is touched. */
final class Uninstall {
 public static function run(): void {
  if(!defined('WP_UNINSTALL_PLUGIN')||WP_UNINSTALL_PLUGIN!=='kiwe-mcp/kiwe-mcp.php'||is_multisite()||!current_user_can('delete_plugins'))throw new \RuntimeException('Native MCP removal permission is required.');
  global $wpdb;$table=$wpdb->prefix.'kiwe_mcp_records';if(preg_match('/^[A-Za-z0-9_]+$/D',$table)!==1)throw new \RuntimeException('Invalid native table identity.');
  if($wpdb->query('DROP TABLE IF EXISTS `'.$table.'`')===false)throw new \RuntimeException('MCP connection removal could not be confirmed.');
  foreach(['kiwe_mcp_storage_v1','kiwe_mcp_revocation_epoch_v1','kiwe_mcp_app_enabled_v1']as$key)delete_option($key);
 }
}
