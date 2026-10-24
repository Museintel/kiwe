<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);

return static function(callable $boundaries,string $recoveryPath,string $observationPath,string $phrase):array{
 $proof=['schema'=>'kiwe.portable-download-native.v1','scope'=>'native existing principals and private transport of an authenticated synthetic capture','passed'=>false,'cleanup'=>false,'checks'=>[]];
 $check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $deny=static function(callable $fn,string $label)use($check){try{$fn();}catch(\DSA\Backup\Archive_Error $e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
 $service=new \DSA\Backup\Portable_Capture_Job_Service();$actor=get_current_user_id();$vault=\DSA\Backup\Configuration_Snapshot_Service::private_directory(true);$control=$vault.'/portable-control.lock';$existed=file_exists($control);$id=null;$dir=null;$input=null;$info=null;$subscriber=0;
 $denyCaps=static function(array $caps):array{unset($caps['kiwe_manage_context'],$caps['edit_users']);return$caps;};
 global $wpdb;$sqlBefore=$wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N);
 try{
  $check(\DSA\Access\Backup_Workspace_Service::allowed(),'unchanged existing native client has Backups authority');
  $check(!glob($vault.'/portable-job-*'),'probe does not replace an existing native capture job');
  $input=fopen($recoveryPath,'rb');$review=\DSA\Backup\Portable_Recovery_Set::verify($input,$phrase);fclose($input);$input=null;
  $observation=file_get_contents($observationPath);$observed=\DSA\Backup\Portable_Source_Observation::open($observation,$phrase);
  $sha=hash_file('sha256',$recoveryPath);$check($observed['recoverySha256']===$sha&&$observed['recoveryArchiveId']===$review['archiveId'],'synthetic encrypted transport fixture and observation authenticate before creating the owned test completion');
  $state=$service->create();$id=$state['id'];$dir=\DSA\Backup\Archive_Job_Files::directory('portable',$id);$info=json_decode(file_get_contents($dir.'/info.json'),true);
  // This is only a native transport fixture, never evidence of original-site worker capture.
  foreach(['recovery'=>$recoveryPath,'observation'=>$observationPath]as$name=>$source)if(!copy($source,$dir.'/'.$name.'.kiwe')||!chmod($dir.'/'.$name.'.kiwe',0600))throw new RuntimeException('Owned native fixture copy failed');
  $info['status']='captured';$info['attempt']=1;$info['bytes']=filesize($recoveryPath);$info['reservedBytes']=$info['bytes']+strlen($observation);$info['archiveSha256']=$sha;$info['observationSha256']=hash('sha256',$observation);\DSA\Backup\Archive_Job_Files::write($dir,'info.json',json_encode($info));
  $check(dirname($dir)===$vault&&!str_starts_with(realpath($vault),realpath(ABSPATH).'/')&&(fileperms($dir.'/recovery.kiwe')&0777)===0600,'native transport fixture remains private outside the website document root');
  $proof['boundaries']=$boundaries($service,$id,$phrase);$check($proof['boundaries']['passed']===true&&$proof['boundaries']['cleanup']===true,'native filesystem and common corruption interruption contention boundaries pass');
  $headers=0;$begin=static function()use(&$headers){++$headers;};$emit=static fn()=>null;
  wp_set_current_user(0);$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'anonymous native caller cannot read captured bytes');wp_set_current_user($actor);$check($headers===0,'native anonymous denial precedes every transport header');
  foreach(get_users(['role'=>'subscriber','number'=>10,'fields'=>'ID'])as$userId){wp_set_current_user((int)$userId);if(current_user_can('read')&&!\DSA\Access\Backup_Workspace_Service::allowed()){$subscriber=(int)$userId;break;}}wp_set_current_user($actor);
  $check($subscriber>0,'permission proof uses an existing read-only subscriber without changing role password or consent');
  wp_set_current_user($subscriber);$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'another native account cannot download the client-owned encrypted capture');$deny(static fn()=>$service->remove($id),'another native account cannot remove the client-owned capture');wp_set_current_user($actor);
  $owner=\DSA\Access\Site_Owner_Service::owner_id();$check($owner>0&&$owner!==$actor,'existing technical owner is a separate native account');wp_set_current_user($owner);$check(\DSA\Access\Backup_Workspace_Service::allowed(),'technical owner already has native Backups authority');$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'even another authorized account cannot download the client-owned job');wp_set_current_user($actor);
  $prefix=$wpdb->prefix;$wpdb->prefix='qa_other_source_';try{$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'changed native source binding cannot read the captured fixture');}finally{$wpdb->prefix=$prefix;}
  $check($headers===0,'ownership permission and source-binding denial emit no headers');
  $sent=0;try{$deny(static function()use($service,$id,$begin,$denyCaps,&$sent){return $service->stream_review_capture($id,'recovery',$begin,static function($bytes)use($denyCaps,&$sent){$sent+=strlen($bytes);add_filter('user_has_cap',$denyCaps,PHP_INT_MAX,4);});},'native Backups authority lost during transport cannot release a complete capture');$check($sent>0&&$sent<$info['bytes']&&!\DSA\Access\Backup_Workspace_Service::allowed(),'native current permission denial withholds completion without changing a role record');}finally{remove_filter('user_has_cap',$denyCaps,PHP_INT_MAX);}
  $info['actor']=$subscriber;\DSA\Backup\Archive_Job_Files::write($dir,'info.json',json_encode($info));wp_set_current_user($subscriber);$deny(static fn()=>$service->stream_review_capture($id,'recovery',$begin,$emit),'current native Backups permission is required even when the private fixture belongs to that account');
  $check($service->remove($id)['removed'],'native read-only owner can retire its own ciphertext after Backups authority is unavailable');$id=null;wp_set_current_user($actor);
  $check($wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N)===$sqlBefore,'native download proof preserves caller SQL transaction ownership and defaults');$proof['passed']=true;
 }catch(\Throwable $e){$proof['error']=$e->getMessage();}
 finally{
  remove_filter('user_has_cap',$denyCaps,PHP_INT_MAX);
  if(is_resource($input))fclose($input);
  if($id){try{$latest=json_decode(file_get_contents($dir.'/info.json'),true);wp_set_current_user($latest['actor']);$service->remove($id);$id=null;}catch(\Throwable $e){$proof['cleanupError']='Owned native download job could not be retired';}}
  wp_set_current_user($actor);$retired=$id===null;
  if(!$existed&&$retired){$guard=@fopen($control,'c+b');if(!$guard||!flock($guard,LOCK_EX|LOCK_NB)||glob($vault.'/portable-job-*'))$retired=false;else{flock($guard,LOCK_UN);fclose($guard);$guard=null;if(is_link($control)||!unlink($control))$retired=false;}if(is_resource($guard))fclose($guard);}
  $proof['cleanup']=$retired&&!isset($proof['cleanupError']);$proof['checks']['owned native transport job quota lock and changed principal are retired']=$proof['cleanup'];$proof['passed']=$proof['passed']&&$proof['cleanup'];
 }
 return$proof;
};
