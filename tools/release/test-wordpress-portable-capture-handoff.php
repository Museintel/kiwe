<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
return static function(\wpdb $wpdb):array {
 $proof=['schema'=>'kiwe.portable-capture-handoff-native.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
 $check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $deny=static function(callable$fn,string$label)use($check){try{$fn();}catch(\DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
 $actor=get_current_user_id();$service=new \DSA\Backup\Portable_Capture_Job_Service();$worker=\DSA\Backup\Portable_Capture_Worker_Service::class;
 $vault=\DSA\Backup\Configuration_Snapshot_Service::private_directory(true);$control=$vault.'/portable-control.lock';$controlExisted=file_exists($control);$id=null;$dir=null;$held=null;$artifact=null;$subscriber=0;
 $ownedWorker=false;$before=$wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N);
 try{
  $check(\DSA\Access\Backup_Workspace_Service::allowed(),'existing native account has its unchanged Backups authority');
  $check(!file_exists($vault.'/worker.kiwe')&&!file_exists($vault.'/worker.lock')&&!glob($vault.'/portable-job-*'),'native handoff probe begins without replacing existing worker state or another capture');$ownedWorker=true;
  $check(!$worker::readiness()['ready'],'unobserved native worker is unavailable');
  $state=$service->create();$id=$state['id'];$dir=\DSA\Backup\Archive_Job_Files::directory('portable',$id);$phrase='Synthetic native encrypted worker phrase';
  $deny(static fn()=>$service->enqueue($id,$phrase),'native queue refuses a secret before worker observation');
  $driver=new $worker();$driver->register();$check(has_action('shutdown',[$worker,'tick'])===PHP_INT_MAX,'native driver is registered only for the CLI cron shutdown');remove_action('shutdown',[$worker,'tick'],PHP_INT_MAX);
  $check($worker::tick()===['claimed'=>0,'failed'=>0,'busy'=>false]&&$worker::readiness()['ready'],'native driver records authenticated readiness without starting a capture');
  $check((fileperms($vault.'/worker.kiwe')&0777)===0600&&(fileperms($vault.'/worker.lock')&0777)===0600,'native heartbeat and worker lock remain private');
  $check($service->enqueue($id,$phrase)['status']==='queued','native account queues capture without synchronous inventory or SQL capture');
  $handoff=$dir.'/handoff.kiwe';$raw=file_get_contents($handoff);$info=json_decode(file_get_contents($dir.'/info.json'),true);$sealed=\DSA\Security\Secret_Store::decrypt_with_status($raw);
  $check($sealed['status']==='ok'&&!str_contains($raw,$phrase)&&!str_contains(json_encode($info),$phrase),'native secret owner protects the handoff and journal contains no recovery passphrase');
  $data=json_decode($sealed['value'],true);$check($data['id']===$id&&$data['actor']===$actor&&$data['attempt']===1&&$data['expiresAt']-$data['createdAt']===900,'native encrypted handoff binds job account source attempt and expiry');
  $check((fileperms($handoff)&0777)===0600,'native recovery handoff has private file permissions');
  $deny(static fn()=>$service->enqueue($id,$phrase),'native queued secret cannot be replaced');
  $held=fopen($vault.'/worker.lock','c+b');flock($held,LOCK_EX);$start=hrtime(true);$check($worker::tick()['busy']&&is_file($handoff)&&(hrtime(true)-$start)/1e9<0.25,'native competing worker has no deliberate wait or secret consumption');flock($held,LOCK_UN);fclose($held);$held=null;
  wp_set_current_user(0);$deny(static fn()=>$service->run_queued($id,static function(){throw new \DSA\Backup\Archive_Error('Synthetic secret cancellation');}),'native queued worker cancellation refuses a completed set');
  $check(get_current_user_id()===0&&!is_file($handoff),'native cancelled claim consumes once and restores anonymous cron principal');wp_set_current_user($actor);
  $state=$service->status($id);$check($state['status']==='failed'&&$state['attempt']===1&&$state['error']==='capture-failed'&&!$state['fullSiteReady'],'native cancelled capture records only a redacted retryable attempt');
  $check(!$service->run_queued($id),'native consumed worker handoff cannot replay');
  $service->enqueue($id,$phrase);$data=json_decode(\DSA\Security\Secret_Store::decrypt(file_get_contents($handoff)),true);$data['createdAt']=time()-901;$data['expiresAt']=$data['createdAt']+900;\DSA\Backup\Archive_Job_Files::write($dir,'handoff.kiwe',\DSA\Security\Secret_Store::encrypt(json_encode($data)));
  wp_set_current_user(0);$result=$worker::tick();$check($result===['claimed'=>1,'failed'=>1,'busy'=>false]&&get_current_user_id()===0,'native driver claims exactly one expired queued attempt and restores its principal');wp_set_current_user($actor);
  $check($service->status($id)['error']==='handoff-refused'&&!is_file($handoff),'native expired secret is retired with no original-site capture');
  $service->enqueue($id,$phrase);\DSA\Backup\Archive_Job_Files::write($dir,'handoff.kiwe','owned damaged ciphertext');$deny(static fn()=>$service->run_queued($id),'native damaged ciphertext refused');$check(!is_file($handoff)&&$service->status($id)['error']==='handoff-refused','native damaged secret cannot escape into a failure message');
  $service->enqueue($id,$phrase);$data=json_decode(\DSA\Security\Secret_Store::decrypt(file_get_contents($handoff)),true);$data['binding']=str_repeat('0',64);\DSA\Backup\Archive_Job_Files::write($dir,'handoff.kiwe',\DSA\Security\Secret_Store::encrypt(json_encode($data)));$deny(static fn()=>$service->run_queued($id),'native changed source handoff refused');
  foreach(get_users(['role'=>'subscriber','number'=>10,'fields'=>'ID'])as$userId){wp_set_current_user((int)$userId);if(current_user_can('read')&&!\DSA\Access\Backup_Workspace_Service::allowed()){$subscriber=(int)$userId;break;}}wp_set_current_user($actor);
  $check($subscriber>0,'native permission denial uses an existing read-only subscriber without changing account records');
  $service->enqueue($id,$phrase);$data=json_decode(\DSA\Security\Secret_Store::decrypt(file_get_contents($handoff)),true);$data['actor']=$subscriber;$info=json_decode(file_get_contents($dir.'/info.json'),true);$info['actor']=$subscriber;
  \DSA\Backup\Archive_Job_Files::write($dir,'info.json',json_encode($info));\DSA\Backup\Archive_Job_Files::write($dir,'handoff.kiwe',\DSA\Security\Secret_Store::encrypt(json_encode($data)));
  wp_set_current_user(0);$deny(static fn()=>$service->run_queued($id),'native worker checks real current subscriber permissions before capture');$check(get_current_user_id()===0&&!is_file($handoff),'native denied principal is never retained as cron context');
  wp_set_current_user($subscriber);$check($service->remove($id)['removed'],'native read-only account can remove its own refused encrypted attempt');$id=null;wp_set_current_user($actor);
  $state=$service->create();$id=$state['id'];$dir=\DSA\Backup\Archive_Job_Files::directory('portable',$id);$service->enqueue($id,$phrase);$artifact=$dir.'/unknown.keep';file_put_contents($artifact,'owned unknown sentinel');
  $data=json_decode(\DSA\Security\Secret_Store::decrypt(file_get_contents($dir.'/handoff.kiwe')),true);$data['createdAt']=time()-901;$data['expiresAt']=$data['createdAt']+900;\DSA\Backup\Archive_Job_Files::write($dir,'handoff.kiwe',\DSA\Security\Secret_Store::encrypt(json_encode($data)));
  $deny(static fn()=>$service->run_queued($id),'native failed handoff preserves an unreviewed artifact');$check(is_file($artifact)&&$service->status($id)['error']==='cleanup-required','native refusal never deletes an unknown artifact');unlink($artifact);$artifact=null;$service->remove($id);$id=null;
  $ready=json_decode(\DSA\Security\Secret_Store::decrypt(file_get_contents($vault.'/worker.kiwe')),true);$ready['observedAt']=time()-901;\DSA\Backup\Archive_Job_Files::write($vault,'worker.kiwe',\DSA\Security\Secret_Store::encrypt(json_encode($ready)));$check(!$worker::readiness()['ready'],'native stale heartbeat cannot retain readiness');
  $check($wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N)===$before,'native queued claims preserve SQL transaction ownership and defaults');$proof['passed']=true;
 }catch(\Throwable$e){$proof['error']=$e->getMessage();}
 finally{
  remove_action('shutdown',[$worker,'tick'],PHP_INT_MAX);
  if(is_resource($held)){flock($held,LOCK_UN);fclose($held);}if($artifact&&is_file($artifact)&&!is_link($artifact))unlink($artifact);
  if($id){try{$info=json_decode(file_get_contents($dir.'/info.json'),true);wp_set_current_user($info['actor']);$service->remove($id);$id=null;}catch(\Throwable$e){$proof['cleanupError']='Owned native handoff job retirement failed';}}
  wp_set_current_user($actor);$retired=$id===null;
  if($ownedWorker&&$retired){foreach(['worker.kiwe','worker.lock']as$name){$path=$vault.'/'.$name;if(is_link($path)||!is_file($path)||!unlink($path))$retired=false;}}
  if(!$controlExisted&&$retired){$guard=@fopen($control,'c+b');if(!$guard||!flock($guard,LOCK_EX|LOCK_NB)||glob($vault.'/portable-job-*'))$retired=false;else{flock($guard,LOCK_UN);fclose($guard);$guard=null;if(is_link($control)||!unlink($control))$retired=false;}if(is_resource($guard))fclose($guard);}
  $proof['cleanup']=$retired&&!isset($proof['cleanupError']);$proof['checks']['owned native jobs worker readiness locks and hook retired']=$proof['cleanup'];$proof['passed']=$proof['passed']&&$proof['cleanup'];
 }
 return$proof;
};
