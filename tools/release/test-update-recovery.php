<?php
declare(strict_types=1);
// Real loader recovery in a newly created external disposable filesystem, never a website.
$sandbox=$argv[1]??'';
$case=$argv[2]??'copy-failure';
$repository=realpath(dirname(__DIR__,2));
if(!is_dir($sandbox)||realpath($sandbox)===$repository||str_starts_with(str_replace('\\','/',realpath($sandbox)),str_replace('\\','/',$repository).'/'))throw new RuntimeException('Use an existing external sandbox.');
$content=$sandbox.'/content';if(file_exists($content))throw new RuntimeException('Use a fresh sandbox.');
mkdir($content.'/mu-plugins/dsa',0777,true);mkdir($content.'/upgrade',0777,true);
mkdir($sandbox.'/public_html',0777,true);define('ABSPATH',$sandbox.'/public_html/');$_SERVER['DOCUMENT_ROOT']=rtrim(ABSPATH,'/');define('WP_CONTENT_DIR',$content);define('WP_DEBUG',false);define('WP_DEBUG_LOG',false);define('WP_DEBUG_DISPLAY',false);
$options=[];$notices=[];$checks=[];
function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0777,true);}
function wp_normalize_path($v){return str_replace('\\','/',$v);}function trailingslashit($v){return rtrim($v,'/\\').'/';}
function wp_generate_uuid4(){$v=bin2hex(random_bytes(16));return substr($v,0,8).'-'.substr($v,8,4).'-'.substr($v,12,4).'-'.substr($v,16,4).'-'.substr($v,20);}function add_action(...$args){$GLOBALS['notices'][]=$args;}function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}function update_option($k,$v,...$args){$GLOBALS['options'][$k]=$v;return true;}function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
function sanitize_text_field($v){return strip_tags((string)$v);}function check($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks'][]=$label;}
function wp_json_encode($v,...$args){return json_encode($v,...$args);}
function fixture_wp_loaded(){foreach($GLOBALS['notices'] as $hook)if($hook[0]==='wp_loaded')($hook[1])();}
$mu=$content.'/mu-plugins';$backup=$content.'/upgrade/kiwe-backup-fixture';$privateCase=str_starts_with($case,'private-');if($privateCase){$case=substr($case,8);require $repository.'/wp-content/mu-plugins/dsa/includes/Runtime/Private_Storage.php';$backup=DSA\Runtime\Private_Storage::directory('releases',true).'/kiwe-backup-'.wp_generate_uuid4();}
copy($repository.'/wp-content/mu-plugins/dsa.php',$mu.'/dsa.php');
file_put_contents($mu.'/dsa/dsa.php',"<?php define('DSA_VERSION','9.9.9');");
file_put_contents($mu.'/kiwe-incident-guard.php','new guard');
mkdir($backup.'/dsa',0777,true);
file_put_contents($backup.'/dsa/dsa.php',"<?php define('DSA_VERSION','8.0.0-rc.82');");
file_put_contents($backup.'/dsa/marker.txt','previous package');
file_put_contents($backup.'/dsa.php','previous loader');
file_put_contents($backup.'/kiwe-incident-guard.php','previous guard');
if($privateCase){file_put_contents($content.'/upgrade/native-scratch.txt','scratch');unlink($content.'/upgrade/native-scratch.txt');rmdir($content.'/upgrade');check(is_file($backup.'/dsa/marker.txt'),'Native scratch cleanup cannot erase durable Core recovery');}
$record=['schema'=>1,'state'=>'awaiting_boot','oldVersion'=>'8.0.0-rc.82','newVersion'=>'9.9.9','backup'=>$backup,'createdAt'=>time()];
if($case==='boot-exception'){
	file_put_contents($mu.'/dsa/dsa.php',"<?php throw new RuntimeException('Synthetic boot failure');");
	file_put_contents($mu.'/kiwe-update-transaction.json',json_encode($record));
	require $mu.'/dsa.php';
	check(file_get_contents($mu.'/dsa/marker.txt')==='previous package','Bootstrap exception restores the previous package');
	check(!file_exists($mu.'/kiwe-update-transaction.json'),'Bootstrap exception retires transaction only after recovery');
}elseif($case==='successful-boot'){
	file_put_contents($mu.'/dsa/dsa.php',"<?php define('DSA_VERSION','9.9.9'); define('KIWE_PACKAGE_BOOT_COMPLETE',true);");
	file_put_contents($mu.'/kiwe-update-transaction.json',json_encode($record));
	require $mu.'/dsa.php';
	check(is_dir($backup),'Even registered services retain rollback storage until wp_loaded');
	fixture_wp_loaded();
	check(!is_dir($backup)&&!file_exists($mu.'/kiwe-update-transaction.json'),'Confirmed matching package boot retires backup and transaction');
}elseif($case==='expired-install'){
	$record['state']='installing';$options['kiwe_update_lock']=['time'=>time()-1000,'token'=>'fixture'];
	file_put_contents($mu.'/kiwe-update-transaction.json',json_encode($record));
	require $mu.'/dsa.php';
	check(DSA_VERSION==='8.0.0-rc.82','Expired interrupted installation boots the restored package');
	check(file_get_contents($mu.'/dsa.php')==='previous loader'&&!file_exists($mu.'/kiwe-update-transaction.json'),'Interrupted installation restores loader and retires transaction');
}elseif($case==='false-boot'){
	file_put_contents($mu.'/kiwe-update-transaction.json',json_encode($record));
	require $mu.'/dsa.php';
	check(is_dir($backup),'Defining a version without successful boot must retain rollback storage');
	fixture_wp_loaded();
	check(file_exists($mu.'/kiwe-update-transaction.json'),'Unconfirmed boot retains transaction');
}elseif($case==='active-install'){
	$record['state']='installing';$options['kiwe_update_lock']=['time'=>time(),'token'=>'fixture'];
	file_put_contents($mu.'/kiwe-update-transaction.json',json_encode($record));
	require $mu.'/dsa.php';
	check(is_dir($backup.'/dsa')&&file_get_contents($mu.'/kiwe-incident-guard.php')==='new guard','A concurrent visitor cannot roll back the active installer');
	check(!defined('DSA_VERSION'),'Kiwe bootstrap waits while the release pair is in transition');
}else{
	require $mu.'/dsa.php';
	file_put_contents($mu.'/kiwe-update-transaction.json',json_encode($record));
	if(in_array($case,['copy-failure','service-failure','guard-copy-failure'],true)){
		$blocked=$case==='guard-copy-failure'?'kiwe-incident-guard.php':'dsa.php';
		rename($mu.'/'.$blocked,$mu.'/entry-before-fixture.php');mkdir($mu.'/'.$blocked);
		if($case==='service-failure'){
			require $repository.'/wp-content/mu-plugins/dsa/includes/Runtime/Update_Service.php';
			$service=new DSA\Runtime\Update_Service();$method=new ReflectionMethod($service,'rollback_now');
			check(false===$method->invoke($service,$mu,$backup),'Updater delegates failed recovery without deleting its backup');
		}else check(false===kiwe_mu_rollback_update($record),'Failed entry-file copy must return failure');
		check(is_file($backup.'/dsa/dsa.php')&&is_file($backup.'/dsa.php'),'Failed recovery preserves the previous package and loader');
		check(file_exists($mu.'/kiwe-update-transaction.json'),'Failed recovery preserves its retry transaction');
		if($case==='guard-copy-failure'){
			rmdir($mu.'/'.$blocked);
			check(true===kiwe_mu_rollback_update($record),'Recovery retries successfully after entry-copy obstruction is cleared');
			check(file_get_contents($mu.'/dsa/marker.txt')==='previous package','Retried restore recovers the original package');
		}
	}elseif($case==='path-escape'){
		$bad=$record;$bad['backup']=$backup.'/../kiwe-backup-fixture';
		check(false===kiwe_mu_rollback_update($bad),'Backup paths containing traversal are refused');
		check(is_file($backup.'/dsa/marker.txt'),'Invalid path cannot delete recovery files');
	}elseif($case==='normal'){
		check(true===kiwe_mu_rollback_update($record),'Recovery returns success for a complete restored pair');
		check(file_get_contents($mu.'/dsa/marker.txt')==='previous package'&&file_get_contents($mu.'/dsa.php')==='previous loader'&&file_get_contents($mu.'/kiwe-incident-guard.php')==='previous guard','Recovery restores package, loader and guard together');
		check(!file_exists($mu.'/kiwe-update-transaction.json'),'Completed recovery retires transaction');
	}else throw new RuntimeException('Unknown fixture case.');
}
echo json_encode(['passed'=>true,'case'=>($privateCase?'private-':'').$case,'checks'=>$checks,'debugLogCreated'=>file_exists($content.'/debug.log')])."\n";
