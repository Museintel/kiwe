<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** Immediate code recovery only. Orders, identities, settings and other business data are never restored. */
final class Code_Recovery {
 public const OPTION='kiwe_apps_code_transaction_v1';
 public const RELEASES='kiwe_apps_installed_releases_v1';
 private static function marker(string $id): string { if(!in_array($id,Runtime_Contract::IDS,true))throw new \RuntimeException('Unknown App boot marker.');return \DSA\Runtime\Release_Storage::app_marker($id); }
 public static function state(string $id=''): array { if($id==='')$id=(string)get_option(self::OPTION.'_last','');if(!in_array($id,Runtime_Contract::IDS,true))return[];$v=get_option(self::OPTION.'_'.$id,[]);return is_array($v)?$v:[]; }
 private static function write(array $state): void {
  update_option(self::OPTION.'_'.$state['id'],$state,false);update_option(self::OPTION.'_last',$state['id'],false);
  if(get_option(self::OPTION.'_'.$state['id'],[])!==$state)throw new \RuntimeException('The App recovery record could not be confirmed.');
 }
 private static function root(string $id): string {
  if(!in_array($id,Runtime_Contract::IDS,true))throw new \RuntimeException('Unknown App recovery target.');
  return wp_normalize_path(WP_PLUGIN_DIR.'/kiwe-'.$id);
 }
 private static function fingerprint(string $root): array {
  $out=[];if(!is_dir($root))return$out;
  if(is_link($root)||wp_normalize_path((string)realpath($root))!==wp_normalize_path($root))throw new \RuntimeException('App recovery refuses redirected directories.');
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS))as$file){
   if($file->isLink()||!$file->isFile()||count($out)>Package_Verifier::MAX_FILES)throw new \RuntimeException('App recovery encountered an unsupported file.');
   $name=wp_normalize_path(substr($file->getPathname(),strlen($root)+1));if(!Runtime_Contract::relative($name))throw new \RuntimeException('App recovery encountered an unsafe path.');
   $out[$name]=['bytes'=>$file->getSize(),'sha256'=>(string)hash_file('sha256',$file->getPathname())];
  }
  ksort($out,SORT_STRING);return$out;
 }
 private static function remove(string $root,array $expected): void {
  if(self::fingerprint($root)!==$expected)throw new \RuntimeException('Newer App files were preserved. Review recovery before removing them.');
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST)as$file){$ok=$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());if(!$ok)throw new \RuntimeException('App file cleanup could not finish.');}
  if(!rmdir($root))throw new \RuntimeException('App directory cleanup could not finish.');
 }
 public static function prepare(array $release): array {
  if(!Signed_Packet::release($release))throw new \RuntimeException('Authenticated App recovery requires a signed release.');
  Native_State::filesystem();$id=$release['id'];$pending=self::state($id);if(is_file(self::marker($id))&&$pending&&!in_array($pending['phase']??'',['accepted','recovered'],true))throw new \RuntimeException('Finish or recover the pending App installation first.');$old=self::state($id);
  if($old&&!in_array($old['phase']??'',['accepted','recovered'],true))throw new \RuntimeException('Finish or recover the previous App update first.');
  $id=$release['id'];$root=self::root($id);$known=(array)get_option(self::RELEASES,[]);$prior=$known[$id]??null;$before=self::fingerprint($root);
  if($before){if(!is_array($prior)){Package_Verifier::directory($root,$release);$prior=$release;}else Package_Verifier::directory($root,$prior);}
  $base=wp_normalize_path(\DSA\Runtime\Release_Storage::apps());
  $backup=$base.'/kiwe-app-recovery-'.wp_generate_uuid4();if(!wp_mkdir_p($backup))throw new \RuntimeException('App recovery storage could not be created.');
  if($before){if(!wp_mkdir_p($backup.'/kiwe-'.$id))throw new \RuntimeException('App recovery storage could not be prepared.');$copied=copy_dir($root,$backup.'/kiwe-'.$id);if(is_wp_error($copied)||self::fingerprint($backup.'/kiwe-'.$id)!==$before)throw new \RuntimeException('The previous App code could not be independently preserved.');}
  // Preserve the current version before retiring its superseded recovery copy.
  if($old&&!empty($old['backup'])&&(file_exists($old['backup'])||is_link($old['backup'])))self::retire($old);
  $active=(array)get_option('active_plugins',[]);$position=array_search('kiwe-'.$id.'/kiwe-'.$id.'.php',$active,true);
  $state=['schema'=>1,'id'=>$id,'phase'=>'placing','release'=>$release,'priorRelease'=>$prior,'before'=>$before,'active'=>is_plugin_active('kiwe-'.$id.'/kiwe-'.$id.'.php'),'activePosition'=>$position===false?count($active):$position,'backup'=>$backup,'createdAt'=>time()];self::write($state);
  if(file_put_contents(self::marker($id),$id,LOCK_EX)!==strlen($id))throw new \RuntimeException('The App boot recovery marker could not be written.');
  return$state;
 }
 public static function awaiting_boot(string $id): void {
  $s=self::state($id);if(!$s||!in_array($s['phase']??'',['placing','awaiting_boot','accepted'],true))throw new \RuntimeException('No App installation is awaiting verification.');
  Package_Verifier::directory(self::root($s['id']),$s['release']);$s['phase']='awaiting_boot';self::write($s);if(file_put_contents(self::marker($id),$id,LOCK_EX)!==strlen($id))throw new \RuntimeException('The App boot marker could not be confirmed.');
 }
 /** Resume only the native activation choice captured before WordPress's silent update deactivation. */
 public static function restore_activation(string $id): void {
  $s=self::state($id);if(!$s||!in_array($s['phase']??'',['placing','awaiting_boot'],true))throw new \RuntimeException('No verified App update activation is pending.');
  Package_Verifier::directory(self::root($id),$s['release']);self::activation_choice($s);
 }
 private static function activation_choice(array $s): void {
  Native_State::tools();$plugin='kiwe-'.$s['id'].'/kiwe-'.$s['id'].'.php';
  if(!$s['active'])deactivate_plugins($plugin,true,false);
  elseif(!is_plugin_active($plugin)){ $active=array_values((array)get_option('active_plugins',[]));array_splice($active,min(count($active),(int)($s['activePosition']??count($active))),0,[$plugin]);update_option('active_plugins',$active); }
 }
 /** Inactive plugins cannot boot without changing the owner's activation choice. */
 public static function accept_inactive(string $id): void {
  $s=self::state($id);if(!$s||$s['phase']!=='placing'||$s['active'])throw new \RuntimeException('Only an authenticated inactive App can be accepted without boot.');
  Package_Verifier::directory(self::root($id),$s['release']);$s['phase']='accepted';$s['boot']='inactive';$s['acceptedAt']=time();self::write($s);$known=(array)get_option(self::RELEASES,[]);$known[$id]=$s['release'];update_option(self::RELEASES,$known,false);if(is_file(self::marker($id)))unlink(self::marker($id));
 }
 /** Called before normal plugins load; the steady visitor path performs one file-existence check. */
 public static function guard(): void { foreach(Runtime_Contract::IDS as$id)self::guard_app($id); }
 private static function defer(string $id): void {
  // A concurrent writer may temporarily have an incomplete directory. Only this
  // request skips that optional plugin; its native activation choice is untouched.
  $plugin='kiwe-'.$id.'/kiwe-'.$id.'.php';
  add_filter('option_active_plugins',static fn($plugins)=>array_values(array_diff((array)$plugins,[$plugin])),PHP_INT_MAX);
 }
 private static function guard_app(string $id): void {
  if(!is_file(self::marker($id)))return;
  $state=self::state($id);if(!$state||!in_array($state['phase']??'',['placing','awaiting_boot','recovering'],true))return;
  if($state['phase']==='recovering'){if(Release_Coordinator::acquire()){try{self::recover(false,$id);}finally{Release_Coordinator::release();}}else self::defer($id);return;}
  try{Package_Verifier::directory(self::root($state['id']),$state['release']);}
  catch(\Throwable){if(Release_Coordinator::acquire()){try{self::recover(false,$state['id']);}finally{Release_Coordinator::release();}}else self::defer($id);return;}
  if($state['phase']==='placing'){if(!Release_Coordinator::acquire()){self::defer($id);return;}try{Native_State::tools();if(is_plugin_active('kiwe-'.$id.'/kiwe-'.$id.'.php')){self::awaiting_boot($id);$state=self::state($id);}else{self::accept_inactive($id);return;}}finally{Release_Coordinator::release();}}
  if($state['phase']!=='awaiting_boot')return;
  register_shutdown_function(static function()use($state):void{
   $error=error_get_last();if(!$error||!in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
   $file=wp_normalize_path((string)$error['file']);if(!str_starts_with($file,self::root($state['id']).'/'))return;
   if(Release_Coordinator::acquire()){try{self::recover(false,$state['id']);}catch(\Throwable){/* Keep the authenticated record and backup for the next technical recovery. */}finally{Release_Coordinator::release();}}
  });
  // A registered provider is not yet a successful boot: init callbacks must finish too.
  add_action('wp_loaded',static function()use($state):void{
   $provider=Runtime_Registry::provider($state['id']);
   if(($provider['version']??'')!==$state['release']['version'])return;
   if(!Release_Coordinator::acquire())return;
   try{$s=self::state($state['id']);if(($s['phase']??'')!=='awaiting_boot'||$s['release']['sha256']!==$state['release']['sha256'])return;$s['phase']='accepted';$s['acceptedAt']=time();self::write($s);$known=(array)get_option(self::RELEASES,[]);$known[$s['id']]=$s['release'];update_option(self::RELEASES,$known,false);if(is_file(self::marker($s['id'])))unlink(self::marker($s['id']));}finally{Release_Coordinator::release();}
  },PHP_INT_MAX);
 }
 public static function recover(bool $strict=true,string $id=''): void {
  $s=self::state($id);if(!$s||!in_array($s['phase']??'',['placing','awaiting_boot','accepted','recovering'],true))throw new \RuntimeException('No immediate App recovery is available.');
  $root=self::root($s['id']);
  // A first install that placed no code already has its exact absent original.
  // Cancelling it must not require an empty recovery directory to survive.
  if($s['before']===[]&&!is_dir($root)){
   if(!Signed_Packet::release($s['release']))throw new \RuntimeException('The App recovery record could not be authenticated.');
   self::complete_recovery($s,$root);return;
  }
  $backup=self::backup($s);
  $original=$backup.'/kiwe-'.$s['id'];$already_restored=$s['before']&&self::fingerprint($root)===$s['before'];
  if($s['before']&&!$already_restored&&self::fingerprint($original)!==$s['before'])throw new \RuntimeException('The previous App code no longer matches its recovery proof.');
  if($s['phase']!=='recovering'){
   if(is_dir($root)&&$strict)Package_Verifier::directory($root,$s['release']);
   $s['failed']=['directory'=>'failed-'.wp_generate_uuid4(),'files'=>self::fingerprint($root)];
   // Write intent before either rename, so a killed recovery can resume exactly.
   $s['phase']='recovering';self::write($s);
  }
  if(is_dir($root)&&!$already_restored){
   $failed=$backup.'/'.$s['failed']['directory'];
   if(self::fingerprint($root)!==$s['failed']['files']||file_exists($failed))throw new \RuntimeException('Newer App code was preserved during recovery. Review it before continuing.');
   if(!rename($root,$failed))throw new \RuntimeException('The failed App directory could not be isolated.');
  }
  if($s['before']&&!$already_restored&&!rename($original,$root))throw new \RuntimeException('The original App directory could not be restored.');
  self::complete_recovery($s,$root);
 }
 private static function complete_recovery(array $s,string $root): void {
  self::activation_choice($s);
  $known=(array)get_option(self::RELEASES,[]);if(is_array($s['priorRelease']))$known[$s['id']]=$s['priorRelease'];else unset($known[$s['id']]);update_option(self::RELEASES,$known,false);
  wp_clean_plugins_cache(true);foreach($s['before']as$name=>$proof)if(function_exists('opcache_invalidate'))@opcache_invalidate($root.'/'.$name,true);
  $s['phase']='recovered';$s['recoveredAt']=time();self::write($s);if(is_file(self::marker($s['id'])))unlink(self::marker($s['id']));
 }
 private static function backup(array $s): string {
  $backup=wp_normalize_path((string)($s['backup']??''));$base=wp_normalize_path(\DSA\Runtime\Release_Storage::app_path().'/kiwe-app-recovery-');
  if(!str_starts_with($backup,$base)||preg_match('/^[a-f0-9-]{36}$/D',substr($backup,strlen($base)))!==1||is_link($backup)||wp_normalize_path((string)realpath($backup))!==$backup)throw new \RuntimeException('The App recovery location is invalid.');
  return$backup;
 }
 private static function retire(array $s): void {
  $backup=self::backup($s);
  foreach(new \DirectoryIterator($backup)as$entry){if($entry->isDot())continue;$name=$entry->getFilename();$expected=$name==='kiwe-'.$s['id']?$s['before']:(($s['failed']['directory']??'')===$name?($s['failed']['files']??null):null);if(!is_array($expected))throw new \RuntimeException('Unrecognized recovery files were preserved.');self::remove($backup.'/'.$name,$expected);}
  if(!rmdir($backup))throw new \RuntimeException('The previous recovery directory could not be retired.');
 }
}
