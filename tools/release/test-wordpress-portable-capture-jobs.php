<?php
/** Native permission/journal fixture only. It never captures the original site's database or files. */
if(!defined('ABSPATH')||PHP_SAPI!=='cli')return null;
return static function(\wpdb $wpdb):array{
 $proof=['schema'=>'kiwe.portable-capture-jobs-native-proof.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
 $check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new \RuntimeException($label);};
 $deny=static function(callable$fn,string$label)use($check){try{$fn();}catch(\DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new \RuntimeException('Expected native capture refusal: '.$label);};
 $actor=get_current_user_id();$service=new \DSA\Backup\Portable_Capture_Job_Service();$id=null;$held=null;$ownedUnknown=null;
 $vault=\DSA\Backup\Configuration_Snapshot_Service::private_directory(true);$control=$vault.'/portable-control.lock';$controlExisted=file_exists($control);
 $before=$wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N);
 try{
  $check($actor>0&&\DSA\Access\Backup_Workspace_Service::allowed(),'existing native client backup authority is used without role changes');
  $state=$service->create();$id=$state['id'];$dir=\DSA\Backup\Archive_Job_Files::directory('portable',$id);$check($state['status']==='pending'&&!$state['fullSiteReady'],'native capture starts as a private incomplete attempt');
  $check(dirname($dir)===$vault&&!str_starts_with($dir,rtrim(ABSPATH,'/').'/'),'native job directory is outside the public website');
  $check((fileperms($dir)&0777)===0700&&(fileperms($dir.'/info.json')&0777)===0600&&(fileperms($control)&0777)===0600,'native directory journal and quota lock permissions are private');
  wp_set_current_user(0);$deny(static fn()=>$service->status($id),'native anonymous actor cannot read a capture');$deny(static fn()=>$service->remove($id),'native anonymous actor cannot remove a capture');wp_set_current_user($actor);
  $held=fopen($dir.'/lock','c+b');flock($held,LOCK_EX);$start=hrtime(true);$deny(static fn()=>$service->status($id),'native live worker lock prevents competing access');$check((hrtime(true)-$start)/1e9<0.25,'native lock contention fails without a deliberate worker wait');flock($held,LOCK_UN);fclose($held);$held=null;
  $phrase='Synthetic private native job phrase';$deny(static fn()=>$service->run($id,$phrase,$wpdb,static function(){throw new \DSA\Backup\Archive_Error('Synthetic private cancellation secret');}),'native capture cancellation refuses to publish a completed set');
  $state=$service->status($id);$check($state['status']==='failed'&&$state['attempt']===1&&$state['bytes']===0&&$state['error']==='capture-failed','native failed attempt has a durable redacted journal');
  $check(!str_contains(json_encode($state),$phrase)&&!str_contains(json_encode($state),$vault),'native job projection contains no recovery phrase or private path');
  $check(array_values(array_diff(scandir($dir),['.','..','info.json','lock']))===[],'native failed attempt retires every owned member and review file');
  $info=json_decode(\DSA\Backup\Archive_Job_Files::read($dir,'info.json',16384),true);$info['status']='running';$info['reservedBytes']=67108864;\DSA\Backup\Archive_Job_Files::write($dir,'info.json',json_encode($info));\DSA\Backup\Archive_Job_Files::write($dir,'database.kiwe','owned interrupted ciphertext');
  $state=$service->status($id);$check($state['status']==='interrupted'&&$state['restartRequired']&&!$state['fullSiteReady'],'native stale journal requires a fresh attempt after its worker lock is released');
  $deny(static fn()=>$service->download_path($id),'native interrupted attempt has no completed full-site download');
  $ownedUnknown=$dir.'/unknown.keep';file_put_contents($ownedUnknown,'owned unknown sentinel');$deny(static fn()=>$service->remove($id),'native unknown artifact prevents destructive retirement');$check(is_file($dir.'/database.kiwe')&&is_file($ownedUnknown),'native unknown artifact refusal preserves interrupted bytes and sentinel');unlink($ownedUnknown);$ownedUnknown=null;
  $check($service->remove($id)['removed']&&!file_exists($dir),'native interrupted job can be retired through its existing account authority');$id=null;
  $check($wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N)===$before,'native permission and journal checks preserve caller database defaults and read-view ownership');$proof['passed']=true;
 }catch(\Throwable$e){$proof['error']=$e->getMessage();}
 finally{
  if(is_resource($held)){flock($held,LOCK_UN);fclose($held);}wp_set_current_user($actor);
  if($ownedUnknown&&is_file($ownedUnknown)&&!is_link($ownedUnknown))unlink($ownedUnknown);
  if($id){try{$service->remove($id);$id=null;}catch(\Throwable$e){$proof['cleanupError']='Owned capture job retirement failed';}}
  $retired=$id===null;
  if(!$controlExisted&&$retired){$guard=@fopen($control,'c+b');if(!$guard||!flock($guard,LOCK_EX|LOCK_NB)||glob($vault.'/portable-job-*')){$retired=false;}else{flock($guard,LOCK_UN);fclose($guard);$guard=null;if(is_link($control)||!unlink($control))$retired=false;}if(is_resource($guard))fclose($guard);}
  $proof['cleanup']=$retired&&!isset($proof['cleanupError']);$proof['checks']['all exact owned native capture jobs and new quota lock retired']=$proof['cleanup'];$proof['passed']=$proof['passed']&&$proof['cleanup'];
 }
 return$proof;
};
