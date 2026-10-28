<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** One explicit install, advanced by bounded native requests. Closing the page leaves a resumable job. */
final class Installer_Service {
 public const OPTION='kiwe_apps_install_jobs_v1';
 private function save(array $jobs): void { update_option(self::OPTION,$jobs,false);if(get_option(self::OPTION,[])!==$jobs)throw new \RuntimeException('App progress could not be saved. Refresh and review.'); }
 public function jobs(): array { $v=get_option(self::OPTION,[]);return is_array($v)?$v:[]; }
 private function authority(): void { if(!Native_State::allowed())throw new \RuntimeException('Your website administrator needs native permission to install and activate plugins.'); }
 public function review(string $id,bool $automatic): array {
  $this->authority();if($automatic!==Native_State::automatic($id)&&!current_user_can('update_plugins'))throw new \RuntimeException('Your website administrator manages automatic plugin updates.');$catalogue=new Catalogue_Service();$catalogue->refresh();$release=$catalogue->release($id);$native=Native_State::snapshot();$plan=Install_Plan::create($id,$release,$native,$automatic);
  Package_Verifier::capacity(WP_PLUGIN_DIR,$id==='store'&&empty($native['woo']['installed'])?268435456:Package_Verifier::MAX_UNPACKED);
  return['plan'=>Install_Plan::review($plan),'expected'=>hash('sha256',Signed_Packet::canonical([$release,Install_Plan::fingerprint($native),$automatic]))];
 }
 public function start(string $id,bool $automatic,string $expected): array {
  $this->authority();if(!Release_Coordinator::acquire())throw new \RuntimeException('Another website update is finishing. Retry shortly.');
  try{
   $review=$this->review($id,$automatic);if(!hash_equals($review['expected'],$expected))throw new \RuntimeException('The software or release changed. Review the App again.');
   $jobs=$this->jobs();if(isset($jobs[$id])&&!in_array($jobs[$id]['status']??'',['complete','cancelled'],true))throw new \RuntimeException('Resume the existing App installation first.');
   $release=(new Catalogue_Service())->release($id);$plan=Install_Plan::create($id,$release,Native_State::snapshot(),$automatic);
   $jobs[$id]=['id'=>wp_generate_uuid4(),'plan'=>$plan,'cursor'=>0,'status'=>'queued','message'=>'Ready to install.','updatedAt'=>time(),'expectedNative'=>$plan['native'],'enable'=>true];$this->save($jobs);return$this->projection($jobs[$id]);
  }finally{Release_Coordinator::release();}
 }
 private function file(array $job): string {
  if(preg_match('/^[a-f0-9-]{36}$/D',$job['id'])!==1)throw new \RuntimeException('Invalid App installation reference.');
  $root=wp_normalize_path(\DSA\Runtime\Release_Storage::downloads());if(is_link($root))throw new \RuntimeException('WordPress cannot prepare App download storage.');
  return$root.'/'.$job['id'].'.zip';
 }
 private function download(array $job): void {
  $r=$job['plan']['release'];$file=$this->file($job);
  if(is_file($file)&&filesize($file)===$r['bytes']&&hash_equals($r['sha256'],(string)hash_file('sha256',$file)))return;
  if(is_file($file)&&!unlink($file))throw new \RuntimeException('The interrupted App download could not be replaced.');
  $response=wp_safe_remote_get($r['packageUrl'],['timeout'=>45,'redirection'=>0,'stream'=>true,'filename'=>$file,'limit_response_size'=>$r['bytes']+1,'headers'=>['Accept'=>'application/zip']]);
  if(is_wp_error($response)||(int)wp_remote_retrieve_response_code($response)!==200)throw new \RuntimeException('The App download was interrupted. Your installed features continue working; resume to retry.');
  Package_Verifier::archive($file,$r);
 }
 private function current_release(array $job): void {
  $r=(new Catalogue_Service())->release($job['plan']['id']);if(!hash_equals($r['sha256'],$job['plan']['release']['sha256']))throw new \RuntimeException('A different App release is now available. Review before continuing.');
 }
 private function reconcile(array $job,string $step): array {
  $native=Native_State::snapshot();$before=$job['expectedNative'];$id=$job['plan']['id'];
  if(Install_Plan::fingerprint($native)===Install_Plan::fingerprint($before))return$native;
  // A killed native request may have completed its one declared step before progress was saved.
  $allowed=$before;
  if($step==='install_woo'&&!$before['woo']['installed']&&$native['woo']['installed']&&version_compare($native['woo']['version'],$job['plan']['release']['requiresWoo'],'>='))$allowed['woo']=$native['woo'];
  if($step==='activate_woo'&&$native['woo']['version']===$before['woo']['version']&&$native['woo']['active'])$allowed['woo']=$native['woo'];
  if(in_array($step,['place','activate','confirm'],true)&&($native['apps'][$id]['version']??'')===$job['plan']['version']){Package_Verifier::directory(WP_PLUGIN_DIR.'/kiwe-'.$id,$job['plan']['release']);$allowed['apps'][$id]=$native['apps'][$id];}
  if(Install_Plan::fingerprint($native)!==Install_Plan::fingerprint($allowed))throw new \RuntimeException('Website software changed during installation. Review it before resuming.');
  return$native;
 }
 public function advance(string $id,string $job_id,int $cursor): array {
  $this->authority();if(!in_array($id,Runtime_Contract::IDS,true))throw new \RuntimeException('Choose a supported App.');
  if(!Release_Coordinator::acquire())throw new \RuntimeException('Another website update is finishing. Retry shortly.');
  $jobs=$this->jobs();$job=$jobs[$id]??null;
  try{
   if(!is_array($job)||!hash_equals($job['id'],$job_id)||$job['cursor']!==$cursor)throw new \RuntimeException('App progress changed. Refresh before continuing.');
   if(in_array($job['status'],['complete','cancelled'],true))return$this->projection($job);
   $step=$job['plan']['steps'][$cursor]??'';if($step==='')throw new \RuntimeException('The App plan has no next step.');
   $this->current_release($job);$this->reconcile($job,$step);Native_State::tools();
   $job['status']='running';$job['message']='Finishing '.$this->label($step).'.';$job['updatedAt']=time();$jobs[$id]=$job;$this->save($jobs);
   $r=$job['plan']['release'];$plugin='kiwe-'.$id.'/kiwe-'.$id.'.php';
   switch($step){
    case'install_woo':
     $native=Native_State::snapshot();if(!$native['woo']['installed']||(!$job['plan']['native']['woo']['installed']&&!$native['woo']['active'])){
      require_once ABSPATH.'wp-admin/includes/plugin-install.php';$info=plugins_api('plugin_information',['slug'=>'woocommerce','fields'=>['sections'=>false]]);
      if(is_wp_error($info)||!is_string($info->download_link??null)||!preg_match('#^https://downloads\.wordpress\.org/plugin/woocommerce\.[0-9.]+\.zip$#D',$info->download_link))throw new \RuntimeException('The official WooCommerce package is unavailable. Resume when WordPress.org is reachable.');
      $upgrader=new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());$result=$upgrader->install($info->download_link,['overwrite_package'=>!empty($native['woo']['installed'])]);if(is_wp_error($result)||!$result)throw new \RuntimeException('WooCommerce could not finish installing. Existing plugins and business data were preserved.');
     }
     $native=Native_State::snapshot();if(!$native['woo']['installed']||version_compare($native['woo']['version'],$r['requiresWoo'],'<'))throw new \RuntimeException('The installed WooCommerce version is incompatible.');break;
    case'activate_woo':Native_State::activate('woocommerce/woocommerce.php');break;
    case'download':$this->download($job);break;
    case'verify':Package_Verifier::archive($this->file($job),$r);Package_Verifier::capacity(WP_PLUGIN_DIR,Package_Verifier::MAX_UNPACKED);break;
    case'place':
     Package_Verifier::archive($this->file($job),$r);$state=Code_Recovery::state($id);
     if(($state['release']['sha256']??'')===$r['sha256']&&in_array($state['phase']??'',['placing','awaiting_boot','accepted'],true)){Package_Verifier::directory(WP_PLUGIN_DIR.'/kiwe-'.$id,$r);}
     else{Code_Recovery::prepare($r);$upgrader=new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());$result=$upgrader->install($this->file($job),['overwrite_package'=>true]);if(is_wp_error($result)||!$result)throw new \RuntimeException('WordPress could not finish this App installation. Your previous features are protected; resume to retry or cancel and review.');Package_Verifier::directory(WP_PLUGIN_DIR.'/kiwe-'.$id,$r);}
     if(is_plugin_active($plugin))Code_Recovery::awaiting_boot($id);break;
    case'activate':Native_State::activate($plugin);Code_Recovery::awaiting_boot($id);break;
    case'confirm':
     Package_Verifier::directory(WP_PLUGIN_DIR.'/kiwe-'.$id,$r);$provider=Runtime_Registry::provider($id);
     if(!is_plugin_active($plugin)||($provider['version']??'')!==$r['version'])throw new \RuntimeException('The App has not completed a fresh WordPress boot. Refresh and resume.');
     $state=Code_Recovery::state($id);if(($state['phase']??'')!=='accepted'||($state['release']['sha256']??'')!==$r['sha256'])throw new \RuntimeException('The App boot is still awaiting recovery verification. Refresh and resume.');
     if(Native_State::automatic($id)!==$job['plan']['automatic'])Native_State::automatic_choice($id,$job['plan']['automatic']);$owner=Runtime_Registry::owner($id);$changed=$owner::change(true,$owner::expected());if(is_wp_error($changed))throw new \RuntimeException($changed->get_error_message());
     if(is_file($this->file($job)))unlink($this->file($job));break;
    default:throw new \RuntimeException('Unknown App installation step.');
   }
   $job['expectedNative']=Native_State::snapshot();$job['cursor']++;$job['status']=$job['cursor']>=count($job['plan']['steps'])?'complete':'queued';$job['message']=$job['status']==='complete'?'Installed and ready.':'Ready for '.$this->label($job['plan']['steps'][$job['cursor']]).'.';$job['updatedAt']=time();$jobs[$id]=$job;$this->save($jobs);return$this->projection($job);
  }catch(\Throwable $error){if(is_array($job)&&hash_equals($job['id'], $job_id)&&$job['cursor']===$cursor){$job['status']='failed';$job['message']=$error->getMessage();$job['updatedAt']=time();$jobs[$id]=$job;$this->save($jobs);}throw$error;}
  finally{Release_Coordinator::release();}
 }
 private function label(string $step): string {return['install_woo'=>'WooCommerce installation','activate_woo'=>'WooCommerce activation','download'=>'App download','verify'=>'App authentication','place'=>'native App installation','activate'=>'App activation','confirm'=>'final verification'][$step]??'App review';}
 public function cancel(string $id,string $job_id): void {
  $this->authority();if(!Release_Coordinator::acquire())throw new \RuntimeException('Another website update is finishing.');
  try{$jobs=$this->jobs();$job=$jobs[$id]??null;if(!is_array($job)||!hash_equals($job['id'],$job_id)||$job['status']==='complete')throw new \RuntimeException('Review the current App installation first.');$state=Code_Recovery::state($id);if(($state['release']['sha256']??'')===$job['plan']['release']['sha256']&&in_array($state['phase']??'',['placing','awaiting_boot','recovering','accepted'],true))Code_Recovery::recover(false,$id);$file=$this->file($job);if(is_file($file)&&!unlink($file))throw new \RuntimeException('The interrupted App download could not be retired.');$job['status']='cancelled';$job['message']='App installation cancelled. Any newly installed shared WooCommerce dependency stays available.';$jobs[$id]=$job;$this->save($jobs);}finally{Release_Coordinator::release();}
 }
 public function projection(array $job): array { return['id'=>$job['id'],'app'=>$job['plan']['id'],'version'=>$job['plan']['version'],'status'=>$job['status'],'cursor'=>$job['cursor'],'total'=>count($job['plan']['steps']),'message'=>$job['message'],'updatedAt'=>$job['updatedAt']]; }
}
