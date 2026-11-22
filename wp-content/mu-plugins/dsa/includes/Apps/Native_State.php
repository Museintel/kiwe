<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** Native software state is inspected only by administration and update work. */
final class Native_State {
 public static function tools(): void {
  require_once ABSPATH.'wp-admin/includes/plugin.php';
  require_once ABSPATH.'wp-admin/includes/file.php';
  require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
 }
 public static function can_review(): bool { return current_user_can('install_plugins')||current_user_can('update_plugins'); }
 public static function allowed(): bool {
  return !is_multisite()&&current_user_can('install_plugins')&&current_user_can('activate_plugins')&&wp_is_file_mod_allowed('kiwe_apps');
 }
 public static function can_recover(): bool { return !is_multisite() && wp_is_file_mod_allowed('kiwe_apps') && current_user_can('activate_plugins') && (current_user_can('update_plugins') || current_user_can('install_plugins')); }
 public static function can_remove(string $id): bool { return $id==='mcp'&&!Package_Layout::bundled($id)&&self::allowed()&&current_user_can('delete_plugins'); }
 public static function removal_expected(string $id): string { return hash('sha256',Signed_Packet::canonical([self::snapshot(),get_option(Code_Recovery::RELEASES,[]),Code_Recovery::state($id)])); }
 public static function remove(string $id,string $expected): void {
  if(!self::can_remove($id)||!hash_equals(self::removal_expected($id),$expected))throw new \RuntimeException('Refresh the App review before removing its code.');
  self::tools();$known=(array)get_option(Code_Recovery::RELEASES,[]);$release=$known[$id]??null;
  if(!is_array($release)||!Signed_Packet::release($release))throw new \RuntimeException('An authenticated installed release is required for removal.');
  Code_Recovery::prepare($release);
  try {
   $file='kiwe-'.$id.'/kiwe-'.$id.'.php';deactivate_plugins($file,true,false);$result=delete_plugins([$file]);
   if(is_wp_error($result)||file_exists(WP_PLUGIN_DIR.'/kiwe-'.$id))throw new \RuntimeException('WordPress could not confirm App removal.');
   Code_Recovery::removed($id);wp_clean_plugins_cache(true);
  }catch(\Throwable $error){Code_Recovery::recover_removal($id);throw$error;}
 }
 public static function filesystem(): void {
  self::tools();
  if(get_filesystem_method([],WP_PLUGIN_DIR)!=='direct'||!WP_Filesystem([],WP_PLUGIN_DIR))throw new \RuntimeException('This Apps release needs WordPress direct filesystem access. Ask the hosting administrator to configure native file ownership.');
 }
 public static function snapshot(): array {
  self::tools();wp_clean_plugins_cache(false);$plugins=get_plugins();$apps=[];
  foreach(Runtime_Contract::IDS as$id){$file='kiwe-'.$id.'/kiwe-'.$id.'.php';if(isset($plugins[$file]))$apps[$id]=['version'=>(string)$plugins[$file]['Version'],'active'=>is_plugin_active($file)];}
  $woo='woocommerce/woocommerce.php';
  return['apps'=>$apps,'woo'=>['installed'=>isset($plugins[$woo]),'active'=>is_plugin_active($woo),'version'=>(string)($plugins[$woo]['Version']??'')],'seoOwner'=>SEO_App_Service::external_owner()];
 }
 public static function automatic(string $id): bool { return in_array('kiwe-'.$id.'/kiwe-'.$id.'.php',(array)get_site_option('auto_update_plugins',[]),true); }
 public static function automatic_choice(string $id,bool $enabled): void {
  if(!in_array($id,Runtime_Contract::IDS,true)||!current_user_can('update_plugins'))throw new \RuntimeException('Native plugin update permission is required.');
  $file='kiwe-'.$id.'/kiwe-'.$id.'.php';$values=(array)get_site_option('auto_update_plugins',[]);$values=array_values(array_diff($values,[$file]));if($enabled)$values[]=$file;
  update_site_option('auto_update_plugins',$values);
  if(self::automatic($id)!==$enabled)throw new \RuntimeException('The native automatic-update choice could not be confirmed.');
 }
 public static function activate(string $file): void {
  if(!current_user_can('activate_plugin',$file))throw new \RuntimeException('Your account cannot activate this plugin.');
  $requirements=validate_plugin_requirements($file);if(is_wp_error($requirements))throw new \RuntimeException($requirements->get_error_message());
  $result=activate_plugin($file,'',false,false);if(is_wp_error($result))throw new \RuntimeException($result->get_error_message());
  if(!is_plugin_active($file))throw new \RuntimeException('WordPress could not confirm plugin activation.');
 }
}
