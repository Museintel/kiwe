<?php
namespace DSA\Apps;
if (!defined('ABSPATH')) exit;

/** Data-only contract. The native updater separately authenticates every package byte. */
final class Core_Profile_Contract {
 public static function validate(array $manifest,bool $mcpActive): array {
  $c=$manifest['apps']??null;
  if(!is_array($c)||count($c)!==3||array_diff(array_keys($c),['profile','coreApi','minimum'])||($c['profile']??'')!=='core'
   ||!is_int($c['coreApi']??null)||$c['coreApi']<1||$c['coreApi']>64||!is_array($c['minimum']??null)||count($c['minimum'])>24)throw new \RuntimeException('The Core App compatibility contract is invalid.');
  $ids=array_keys($c['minimum']);foreach($c['minimum']as$id=>$version)if(!is_string($id)||!Runtime_Contract::id($id)||!is_string($version)||!Runtime_Contract::version($version))throw new \RuntimeException('The Core App compatibility identity or version is invalid.');
  $expected=$c['coreApi']===1?['seo','store']:(in_array($c['coreApi'],[2,3],true)?['seo','store','guest','ai','sitegraph']:Runtime_Contract::IDS);
  $bridge=$c['coreApi']===Runtime_Contract::API&&$ids===['seo','store','mcp'];
  $forward=$c['coreApi']>=Runtime_Contract::API&&!array_diff(Runtime_Contract::IDS,$ids);
  if($ids!==$expected&&!$bridge&&!$forward)throw new \RuntimeException('The Core App compatibility contract is invalid.');
  if($c['coreApi']===1||$bridge)foreach(['includes/Access/Guest_Engine_Service.php','includes/AI/AI_Provider_Service.php','includes/Access/SiteGraph_Workspace_Service.php']as$file)if(!isset($manifest['files'][$file]))throw new \RuntimeException('The compatibility bridge must retain its native workspace engines.');
  if($c['coreApi']<Runtime_Contract::API&&$mcpActive)throw new \RuntimeException('Disable MCP before reviewing an older Core compatibility profile.');
  // Unknown future optional identities cannot already be active in this Core.
  // Validate their metadata, retain every known floor, and grant them no runtime.
  return array_intersect_key($c['minimum'],array_flip(Runtime_Contract::IDS));
 }
}
