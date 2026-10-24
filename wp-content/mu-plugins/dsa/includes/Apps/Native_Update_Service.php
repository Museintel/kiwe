<?php
namespace DSA\Apps;
if (!defined('ABSPATH')) exit;

/** External Update URI provider using WordPress's existing update schedule and owner choices. */
final class Native_Update_Service {
 private static ?array $writing=null;
 private static string $temporary='';
 private static bool $lease=false;
 private static function id(string $plugin): string {
  foreach(Runtime_Contract::IDS as$id)if($plugin==='kiwe-'.$id.'/kiwe-'.$id.'.php')return$id;return'';
 }
 private static function compatible(array $release): bool {
  if(!Runtime_Contract::supports($release['id'],$release['version']))return false;
  global$wp_version;
  if(version_compare(PHP_VERSION,$release['requiresPhp'],'<')||version_compare((string)$wp_version,$release['requiresWp'],'<'))return false;
  if($release['id']==='store'){$native=Native_State::snapshot();if(!$native['woo']['installed']||version_compare($native['woo']['version'],$release['requiresWoo'],'<'))return false;}
  return true;
 }
 public static function offer($update,array $data,string $plugin,array $locales) {
  $id=self::id($plugin);if($id===''||!Catalogue_Service::maintenance_context()||($data['UpdateURI']??'')!==Signed_Packet::ORIGIN.'/apps/v1/'.$id)return$update;
  try{
   $catalogue=new Catalogue_Service();$catalogue->refresh();$r=$catalogue->release($id);if(!self::compatible($r))return false;
   return['id'=>Signed_Packet::ORIGIN.'/apps/v1/'.$id,'slug'=>'kiwe-'.$id,'plugin'=>$plugin,'version'=>$r['version'],'url'=>'https://kiwelaunch.com','package'=>$r['packageUrl'],'tested'=>(string)($GLOBALS['wp_version']??''),'requires'=>$r['requiresWp'],'requires_php'=>$r['requiresPhp'],'autoupdate'=>false];
  }catch(\Throwable){return false;}
 }
 public static function download($reply,string $package,$upgrader,array $extra) {
  $id=self::id((string)($extra['plugin']??''));if($id===''||($extra['action']??'')!=='update')return$reply;
  if(self::$writing!==null)return new \WP_Error('kiwe_app_update_busy','Another App update is finishing.');
  try{
   if(is_multisite()||!wp_is_file_mod_allowed('kiwe_apps')||(!current_user_can('update_plugins')&&!(wp_doing_cron()&&Native_State::automatic($id))))throw new \RuntimeException('Native plugin update permission or the owner’s automatic-update choice is required.');
   if(!Release_Coordinator::acquire())throw new \RuntimeException('Another Kiwe code update is running.');
   self::$lease=true;self::$writing=['id'=>$id,'release'=>null];register_shutdown_function([self::class,'shutdown']);
   $catalogue=new Catalogue_Service();$catalogue->refresh();$r=$catalogue->release($id);
   if($package!==$r['packageUrl']||!self::compatible($r))throw new \RuntimeException('The update URL or compatibility no longer matches the authenticated release.');
   Native_State::tools();Package_Verifier::capacity(WP_PLUGIN_DIR,Package_Verifier::MAX_UNPACKED);$file=\DSA\Runtime\Release_Storage::downloads().'/update-'.$id.'-'.wp_generate_uuid4().'.zip';if(!$file)throw new \RuntimeException('WordPress could not prepare update storage.');self::$temporary=$file;
   $response=wp_safe_remote_get($package,['timeout'=>45,'redirection'=>0,'stream'=>true,'filename'=>$file,'limit_response_size'=>$r['bytes']+1]);
   if(is_wp_error($response)||(int)wp_remote_retrieve_response_code($response)!==200)throw new \RuntimeException('The App update download was interrupted. The installed version is preserved.');
   Package_Verifier::archive($file,$r);Code_Recovery::prepare($r);self::$writing=['id'=>$id,'release'=>$r];return$file;
  }catch(\Throwable $error){self::shutdown();return new \WP_Error('kiwe_app_update',$error->getMessage());}
 }
 public static function installed($response,array $extra,array $result) {
  if(self::$writing===null||self::id((string)($extra['plugin']??''))!==self::$writing['id'])return$response;
  try{
   if(is_wp_error($response))throw new \RuntimeException('WordPress refused this App update.');$id=self::$writing['id'];$r=self::$writing['release'];
   if(!is_array($r)||rtrim(wp_normalize_path((string)($result['destination']??'')),'/')!==rtrim(wp_normalize_path(WP_PLUGIN_DIR.'/kiwe-'.$id),'/'))throw new \RuntimeException('The native update destination is not this App.');
   Package_Verifier::directory(WP_PLUGIN_DIR.'/kiwe-'.$id,$r);$state=Code_Recovery::state($id);
   if(!empty($state['active'])){Code_Recovery::restore_activation($id);Code_Recovery::awaiting_boot($id);}else Code_Recovery::accept_inactive($id);
   foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(WP_PLUGIN_DIR.'/kiwe-'.$id,\FilesystemIterator::SKIP_DOTS))as$file)if($file->isFile()&&$file->getExtension()==='php'&&function_exists('opcache_invalidate'))@opcache_invalidate($file->getPathname(),true);
   return$response;
  }catch(\Throwable $error){try{Code_Recovery::recover(false,self::$writing['id']);}catch(\Throwable){}return new \WP_Error('kiwe_app_update_verification',$error->getMessage());}
 }
 public static function complete($upgrader,array $extra): void {if(self::$writing!==null)self::shutdown();}
 public static function shutdown(): void {
  if(self::$temporary!==''&&is_file(self::$temporary))@unlink(self::$temporary);
  self::$temporary='';self::$writing=null;if(self::$lease){self::$lease=false;Release_Coordinator::release();}
 }
}
