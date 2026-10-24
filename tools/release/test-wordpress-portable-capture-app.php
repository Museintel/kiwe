<?php
declare(strict_types=1);
if ( PHP_SAPI !== 'cli' ) exit(2);

return static function(string $recoveryPath,string $observationPath,string $phrase):array{
 $proof=['schema'=>'kiwe.portable-capture-app-native.v1','scope'=>'native WordPress routes and trusted HTTP output adapter using an authenticated synthetic capture','passed'=>false,'cleanup'=>false,'checks'=>[]];
 $check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $deny=static function(callable $fn,string $label)use($check){try{$fn();}catch(\DSA\Backup\Archive_Error $e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
 $app=new \DSA\Access\Portable_Capture_App_Service();$service=new \DSA\Backup\Portable_Capture_Job_Service();$actor=get_current_user_id();$vault=\DSA\Backup\Configuration_Snapshot_Service::private_directory(true);$jobs=[];$input=null;$dir=null;
 $originalServer=$_SERVER;$originalGet=$_GET;$control=$vault.'/portable-control.lock';$controlExisted=file_exists($control);$workerNames=['worker.lock','worker.kiwe'];$workerOwned=false;$subscriber=0;
 $denyCaps=static function(array $caps):array{unset($caps['kiwe_manage_context'],$caps['edit_users']);return $caps;};
 $request=static function(string $method,string $suffix='',?array $body=null,bool $nonce=true,bool $mutation=true){$r=new WP_REST_Request($method,'/dsa/v1/workspace/site-captures'.$suffix);if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$r->set_header('X-Kiwe-Mutation','1');if($body!==null){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}return rest_do_request($r);};
 global $wpdb;$sqlBefore=$wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N);
 try{
  $check(\DSA\Access\Portable_Capture_App_Service::enabled()&&\DSA\Access\Backup_Workspace_Service::allowed(),'explicit preview uses unchanged existing native client Backups authority');
  $check(!glob($vault.'/portable-job-*'),'route proof never replaces existing capture attempts');foreach($workerNames as$name)$check(!file_exists($vault.'/'.$name)&&!is_link($vault.'/'.$name),'native worker fixture does not replace '.$name);
  $_SERVER['HTTP_ORIGIN']=rtrim(home_url('/'),'/');$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';$_SERVER['REQUEST_METHOD']='GET';unset($_SERVER['HTTP_REFERER'],$_SERVER['HTTP_RANGE']);
  $check($request('GET','',null,false)->get_status()===403,'native REST listing denies missing nonce');
  $_SERVER['HTTP_ORIGIN']='https://outside.invalid';$check($request('GET')->get_status()===403,'native REST listing denies cross-site origin');$_SERVER['HTTP_ORIGIN']=rtrim(home_url('/'),'/');
  wp_set_current_user(0);$anonymous=$request('GET');$check($anonymous->get_status()===401,'native REST listing denies anonymous accounts with the WordPress unauthenticated status');wp_set_current_user($actor);
  $check($request('POST','',[],true,false)->get_status()===403,'native create denies missing mutation proof');
  $check($request('POST','',['passphrase'=>$phrase])->get_status()===400,'create never accepts or stores a recovery secret');
  $start=hrtime(true);$created=$request('POST','',[]);$pending=$created->get_data()['job']??null;if(is_array($pending)&&isset($pending['id']))$jobs[$pending['id']]=$actor;
  $check($created->get_status()===200&&$pending['status']==='pending'&&$pending['bytes']===0&&!$pending['fullSiteReady'],'native HTTP create returns only a small pending journal');$id=$pending['id'];$dir=\DSA\Backup\Archive_Job_Files::directory('portable',$id);
  $proof['createSeconds']=(hrtime(true)-$start)/1e9;
  $check(array_values(array_diff(scandir($dir),['.','..','info.json','lock']))===[],'HTTP create performs no database or website-file capture');
  $check($request('POST','/'.$id.'/queue',['passphrase'=>$phrase])->get_status()===409&&!is_file($dir.'/handoff.kiwe'),'unobserved native worker refuses queue before retaining a secret');
  if(!defined('DOING_CRON'))define('DOING_CRON',true);$workerOwned=true;$check(\DSA\Backup\Portable_Capture_Worker_Service::tick()===['claimed'=>0,'failed'=>0,'busy'=>false],'native CLI tick establishes readiness with no queued job or original-site capture');
  $check($request('POST','/'.$id.'/queue',['passphrase'=>['invalid']])->get_status()===400,'native queue rejects an array passphrase');
  $check($request('POST','/'.$id.'/queue',['passphrase'=>$phrase],true,false)->get_status()===403,'native queue requires mutation proof');
  $_SERVER['HTTP_ORIGIN']='https://outside.invalid';$check($request('POST','/'.$id.'/queue',['passphrase'=>$phrase])->get_status()===403,'native queue denies cross-site state change');$_SERVER['HTTP_ORIGIN']=rtrim(home_url('/'),'/');
  $queued=$request('POST','/'.$id.'/queue',['passphrase'=>$phrase]);$check($queued->get_status()===200&&$queued->get_data()['job']['status']==='queued','native client guard admits the narrowly authorized queue route');
  $check(array_values(array_diff(scandir($dir),['.','..','info.json','lock','handoff.kiwe']))===[],'native HTTP queue seals one private handoff and never starts capture');
  $check(!str_contains(file_get_contents($dir.'/handoff.kiwe'),$phrase)&&!str_contains(wp_json_encode($queued->get_data()),$phrase),'native HTTP handoff response and ciphertext disclose no recovery passphrase');
  $check($request('POST','/'.$id.'/queue',['passphrase'=>$phrase])->get_status()===409,'native queued attempt cannot be overwritten by a duplicate submission');
  $check($request('POST','/'.$id.'/remove',['confirm'=>false])->get_status()===400&&is_file($dir.'/handoff.kiwe'),'native removal requires explicit confirmation and keeps the queued handoff');
  $check($request('POST','/'.$id.'/remove',['confirm'=>true])->get_status()===200&&!is_dir($dir),'native confirmed removal retires only the account-owned queue');unset($jobs[$id]);

  $input=fopen($recoveryPath,'rb');$review=\DSA\Backup\Portable_Recovery_Set::verify($input,$phrase);fclose($input);$input=null;$observation=file_get_contents($observationPath);$observed=\DSA\Backup\Portable_Source_Observation::open($observation,$phrase);
  $check($observed['recoverySha256']===hash_file('sha256',$recoveryPath)&&$observed['recoveryArchiveId']===$review['archiveId'],'synthetic capture and source report authenticate before testing transport');
  $state=$service->create();$id=$state['id'];$jobs[$id]=$actor;$dir=\DSA\Backup\Archive_Job_Files::directory('portable',$id);$info=json_decode(file_get_contents($dir.'/info.json'),true);
  foreach(['recovery'=>$recoveryPath,'observation'=>$observationPath]as$name=>$path)if(!copy($path,$dir.'/'.$name.'.kiwe')||!chmod($dir.'/'.$name.'.kiwe',0600))throw new RuntimeException('Owned native fixture copy failed');
  $info['status']='captured';$info['attempt']=1;$info['bytes']=filesize($recoveryPath);$info['reservedBytes']=$info['bytes']+strlen($observation);$info['archiveSha256']=hash_file('sha256',$recoveryPath);$info['observationSha256']=hash('sha256',$observation);\DSA\Backup\Archive_Job_Files::write($dir,'info.json',json_encode($info));
  $listing=$request('GET')->get_data();$check($listing['schema']==='kiwe.site-capture-workspace.v1'&&count($listing['jobs'])===1&&$listing['jobs'][0]['canReview']&&!$listing['fullSiteReady'],'native capture listing offers review while retaining the full-site restore gate');
  $public=wp_json_encode($listing);$check(!str_contains($public,$vault)&&!str_contains($public,$phrase)&&!str_contains($public,$info['binding'])&&!str_contains($public,'archiveSha256'),'native route projection contains no path secret binding or internal journal');
  $headers=0;$begin=static function(array $metadata)use(&$headers){++$headers;};$emit=static fn()=>null;
  foreach(['recovery','observation']as$member){$url=$listing['jobs'][0]['downloads'][$member];$args=[];parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$args);$_GET=$args;$_SERVER['REQUEST_METHOD']='GET';$hash=hash_init('sha256');$bytes=0;$meta=null;
   $received=$app->send_download(static function(array $metadata)use(&$headers,&$meta){++$headers;$meta=$metadata;},static function(string $chunk)use($hash,&$bytes){hash_update($hash,$chunk);$bytes+=strlen($chunk);});
   $check($bytes===$meta['bytes']&&hash_final($hash)===$meta['sha256']&&$received['sha256']===$meta['sha256']&&$meta['component']===$member,'nonce URL and native adapter stream exact encrypted '.$member.' bytes');
   $check(!str_contains($url,'&amp;')&&$args['id']===$id&&wp_verify_nonce($args['_wpnonce'],\DSA\Access\Portable_Capture_App_Service::DOWNLOAD.'_'.$id.'_'.$member),'download URL binds raw query nonce to job and '.$member.' component');
  }
  $originalArgs=$_GET;$before=$headers;
  foreach(['HEAD','POST']as$method){$_SERVER['REQUEST_METHOD']=$method;$deny(static fn()=>$app->send_download($begin,$emit),'HTTP '.$method.' cannot download capture bytes');}$_SERVER['REQUEST_METHOD']='GET';
  $_SERVER['HTTP_RANGE']='bytes=0-9';$deny(static fn()=>$app->send_download($begin,$emit),'Range download cannot bypass completion authentication');unset($_SERVER['HTTP_RANGE']);
  foreach(['id','component','_wpnonce']as$key){$_GET=$originalArgs;$_GET[$key]=['invalid'];$deny(static fn()=>$app->send_download($begin,$emit),'array download '.$key.' is refused');}$_GET=$originalArgs;
  $_GET['component']='recovery';$deny(static fn()=>$app->send_download($begin,$emit),'source-report nonce cannot authorize the recovery component');$_GET=$originalArgs;
  $_SERVER['HTTP_ORIGIN']='https://outside.invalid';$deny(static fn()=>$app->send_download($begin,$emit),'cross-site native download is refused');$_SERVER['HTTP_ORIGIN']=rtrim(home_url('/'),'/');
  wp_set_current_user(0);$deny(static fn()=>$app->send_download($begin,$emit),'anonymous HTTP adapter emits no capture bytes');wp_set_current_user($actor);
  foreach(get_users(['role'=>'subscriber','number'=>10,'fields'=>'ID'])as$userId){wp_set_current_user((int)$userId);if(current_user_can('read')&&!\DSA\Access\Backup_Workspace_Service::allowed()){$subscriber=(int)$userId;break;}}wp_set_current_user($actor);$check($subscriber>0,'role proof uses an existing subscriber without editing an account');
  wp_set_current_user($subscriber);$check($request('GET')->get_data()['jobs']===[],'native subscriber sees no other account capture metadata');$check($request('POST','',[])->get_status()===403,'native subscriber cannot create a site capture');$check($request('POST','/'.$id.'/remove',['confirm'=>true])->get_status()===409,'native read access does not grant another account removal');$deny(static fn()=>$app->send_download($begin,$emit),'native subscriber cannot use a client download URL');wp_set_current_user($actor);
  $check($headers===$before,'every adapter method range nonce origin and role refusal occurs before headers');
  $_GET=['id'=>$id,'component'=>'recovery','_wpnonce'=>wp_create_nonce(\DSA\Access\Portable_Capture_App_Service::DOWNLOAD.'_'.$id.'_recovery')];
  $bodyBytes=0;$deny(static function()use($app,$begin,&$bodyBytes){return $app->send_download($begin,static function(string $chunk)use(&$bodyBytes){$bodyBytes+=strlen($chunk);return false;});},'interrupted output releases its lock without committing a complete file');
  $check($bodyBytes>0&&$bodyBytes<$info['bytes']&&!$service->status($id)['busy']&&is_file($dir.'/recovery.kiwe'),'native adapter failure withholds completion preserves the original capture and releases its private lock');
  add_filter('user_has_cap',$denyCaps,PHP_INT_MAX,4);try{$lost=$request('GET')->get_data();$check(count($lost['jobs'])===1&&!$lost['jobs'][0]['canReview']&&!isset($lost['jobs'][0]['downloads']),'revoked native Backups authority retains only owner cleanup listing');$check($request('POST','',[])->get_status()===403,'revoked native account cannot create a new attempt');$check($request('POST','/'.$id.'/remove',['confirm'=>true])->get_status()===200&&!is_dir($dir),'native guard permits owner removal after Backups permission is lost');unset($jobs[$id]);}finally{remove_filter('user_has_cap',$denyCaps,PHP_INT_MAX);}
  $check($wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N)===$sqlBefore,'native route adapter preserves caller SQL transaction ownership and defaults');$proof['passed']=true;
 }catch(Throwable $e){$proof['error']=$e->getMessage();}
 finally{
  remove_filter('user_has_cap',$denyCaps,PHP_INT_MAX);if(is_resource($input))fclose($input);
  foreach($jobs as$id=>$owner){try{wp_set_current_user($owner);$service->remove($id);unset($jobs[$id]);}catch(Throwable $e){$proof['cleanupError']='Owned native capture route fixture could not be retired';}}
  wp_set_current_user($actor);$_SERVER=$originalServer;$_GET=$originalGet;$retired=!$jobs;
  if($workerOwned)foreach($workerNames as$name){$path=$vault.'/'.$name;if(is_link($path)||!is_file($path)||!unlink($path))$retired=false;}
  if(!$controlExisted&&$retired){$lock=@fopen($control,'c+b');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||glob($vault.'/portable-job-*'))$retired=false;else{flock($lock,LOCK_UN);fclose($lock);$lock=null;if(is_link($control)||!unlink($control))$retired=false;}if(is_resource($lock))fclose($lock);}
  $proof['cleanup']=$retired&&!isset($proof['cleanupError']);$proof['checks']['owned native route jobs worker heartbeat quota lock and principal are retired']=$proof['cleanup'];$proof['passed']=$proof['passed']&&$proof['cleanup'];
 }
 return $proof;
};
