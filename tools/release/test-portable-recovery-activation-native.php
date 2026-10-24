<?php
declare(strict_types=1);
if ( PHP_SAPI!=='cli' ) exit(2);

// Reuse the actual native ownership fixture, its identities, SQL authority and cleanup.
function kiwe_recovery_prepared_config( array $connection,string $prefix ): string {
 $out="<?php\n";foreach(['DB_HOST'=>'host','DB_NAME'=>'database','DB_USER'=>'username','DB_PASSWORD'=>'password']as$key=>$name)$out.='define('.var_export($key,true).','.var_export($connection[$name],true).");\n";
 foreach(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']as$key)$out.='define('.var_export($key,true).','.var_export(hash('sha256','Synthetic prepared '.$key),true).");\n";
 return$out."\$table_prefix = '".$prefix."';\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH . 'wp-settings.php';\n";
}
function kiwe_recovery_activation_native_checks( array $c ): array {
 foreach(['Portable_Recovery_Configuration','Portable_Recovery_Activation']as$class)require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$class.'.php';
 $p=['schema'=>'kiwe.native-recovery-activation-proof.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
 $check=static function(bool$ok,string$label)use(&$p){$p['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $deny=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
 $class=DSA\Backup\Portable_Recovery_Activation::class; $db=DSA\Backup\Portable_Recovery_Database::class;$stage=DSA\Backup\Portable_Recovery_Stage::class;$inventory=DSA\Backup\Portable_File_Inventory::class;
 $parent=$c['root'].'/activations';$c['mkdir']($parent);$result=null;$completed=false;$oldTree=$inventory::scan($c['public'],50000,8589934592,null,'posix',true);$oldRoot=lstat($c['public']);$oldConfig=file_get_contents($c['public'].'/wp-config.php');
 $activate=static fn($plan)=>$class::activate($c['config'],$c['databasePath'],$c['stagePath'],$c['public'],$c['phrase'],$parent,$plan);
 try{
  $plan=$class::reviewed_plan($c['config'],$c['databasePath'],$c['stagePath'],$c['public'],$c['phrase']);$check($plan['codeReview']===null&&$plan['destinationIsolation']===null&&$plan['withheldStateReview']===null,'review requires explicit code isolation and withheld-state decisions');
  $deny(static fn()=>$activate($plan),'unapproved review cannot switch root');$check($inventory::scan($c['public'],50000,8589934592,null,'posix',true)===$oldTree,'review denial preserves complete prepared root including excluded cache');
  $plan['codeReview']='exact-captured-code-approved';$plan['destinationIsolation']='requests-writers-host-cron-paused';$plan['withheldStateReview']='original-private-review-complete';
  $oldIndex=file_get_contents($c['public'].'/index.php');file_put_contents($c['public'].'/index.php','Newer prepared work');$deny(static fn()=>$activate($plan),'newer prepared files invalidate exact reviewed cutover');file_put_contents($c['public'].'/index.php',$oldIndex);
  $v=$plan;$v['quarantinedTables']=[];$deny(static fn()=>$activate($v),'native withheld operational histories cannot be silently accepted');
  $path=$c['stagePath'].'/site/wp-content/uploads/sample.bin';$sample=file_get_contents($path);file_put_contents($path,'changed');$deny(static fn()=>$activate($plan),'changed inert source files cannot activate');file_put_contents($path,$sample);
  $result=$activate($plan);$dir=$result['directory'];$check($result['activated']===true&&$result['fullSiteReady']===false&&$result['retainedOriginalRoot']===true&&$result['retainedOriginalDatabase']===true,'isolated combined cutover retains both original owners without claiming full acceptance');
  $activeConfig=file_get_contents($c['public'].'/wp-config.php');$check(str_contains($activeConfig,"\$table_prefix = '".$c['target']."';")&&!str_contains($activeConfig,'Captured code stays inert'),'captured configuration never becomes executable destination configuration');
  foreach(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']as$key)$check(str_contains($activeConfig,var_export(hash('sha256','Synthetic prepared '.$key),true)),'native destination salt exact after cutover: '.$key);
  $check(str_contains($activeConfig,var_export($c['config']['password'],true))&&str_contains($activeConfig,var_export($c['config']['database'],true)),'native destination connection exact after cutover');
  $check(file_get_contents($c['public'].'/.htaccess')==='Prepared host rules'&&file_get_contents($c['public'].'/.user.ini')==='Prepared php rules'&&!file_exists($c['public'].'/wp-content/db.php')&&!file_exists($c['public'].'/wp-content/object-cache.php'),'prepared host directives retained and captured native drop-ins held inert');
  $check(file_get_contents($c['public'].'/wp-content/uploads/sample.bin')===$sample,'media file bytes remain exact through activation');
  $check(file_get_contents($dir.'/before/wp-config.php')===$oldConfig&&$inventory::scan($dir.'/before',50000,8589934592,null,'posix',true)===$oldTree,'full original root privately retained including files excluded from ordinary capture');
  $inspect=static fn()=>$class::inspect($c['config'],$dir,$c['public'],$c['phrase']);$check($inspect()['ownerId']===3,'independent activation inspection verifies native owner and both retained roots');
  $deny(static fn()=>$db::inspect($c['config'],$c['databasePath'],$c['public'],$c['phrase']),'legacy inactive inspection cannot treat activated root as original');$deny(static fn()=>$db::retire($c['config'],$c['databasePath'],$c['public'],$c['phrase']),'legacy inactive retirement cannot drop active recovered database');
  $reverse=static fn()=>$class::reverse($c['config'],$dir,$c['public'],$c['phrase']);file_put_contents($c['public'].'/newer.bin','Newer activity');$deny($reverse,'newer active file blocks reversal');unlink($c['public'].'/newer.bin');
  file_put_contents($dir.'/before/newer.bin','Newer original activity');$deny($reverse,'newer retained original file blocks reversal');unlink($dir.'/before/newer.bin');
  if(function_exists('symlink')){symlink($c['source'],$c['public'].'/linked');$deny($inspect,'linked active dependency blocks inspection');unlink($c['public'].'/linked');}
  else{$p['unavailableChecks']=['linked active dependency blocks inspection'=>'Host disables the PHP symlink function; no native link-creation proof is claimed.'];}
	  $q=$c['quote'];$query=$c['query'];$name=DSA\Backup\Portable_Table_Definition::identifier($c['target'].'posts');$content=$query('SELECT post_content FROM '.$name.' WHERE ID=55')[0][0];$query('UPDATE '.$name.' SET post_content='.$q('Newer native content').' WHERE ID=55');$deny($reverse,'newer recovered native row blocks reversal');$query('UPDATE '.$name.' SET post_content='.$q($content).' WHERE ID=55');
	  $originalUsers=DSA\Backup\Portable_Table_Definition::identifier($c['live'].'users');$display=$query('SELECT display_name FROM '.$originalUsers.' WHERE ID=9')[0][0];$query('UPDATE '.$originalUsers.' SET display_name='.$q('Newer original user work').' WHERE ID=9');$deny($reverse,'newer original non-owner native row blocks reversal');$query('UPDATE '.$originalUsers.' SET display_name='.$q($display).' WHERE ID=9');
  $bytes=file_get_contents($dir.'/activation.receipt');file_put_contents($dir.'/activation.receipt',substr($bytes,0,-1).chr(ord($bytes[-1])^1));$deny($inspect,'tampered encrypted activation receipt blocks inspection');file_put_contents($dir.'/activation.receipt',$bytes);
  $check(!str_contains($bytes,$c['password'])&&!str_contains($bytes,$c['config']['password'])&&!str_contains($bytes,$oldConfig),'activation journal exposes no native password or configuration plaintext');
  $child=proc_open([PHP_BINARY,$c['public'].'/index.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$check(proc_close($child)===0&&$err===''&&$out==='Synthetic activated website','captured synthetic website PHP runs only after accepted complete switch');
  $connectionPath=$c['root'].'/activation-connection.json';$c['write']($connectionPath,json_encode($c['config']));$entry=realpath(__DIR__.'/../../wp-content/mu-plugins/dsa/recovery/portable-recovery.php');
  $cli=static function($action)use($entry,$connectionPath,$c,$dir){$cmd=[PHP_BINARY,$entry,'--'.$action,'--activation='.$dir,'--public-root='.$c['public']];if($action!=='activation-retire')$cmd[]='--connection='.$connectionPath;$child=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],$c['phrase']."\n");fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($child),$err,json_decode($out,true),$out];};
  $out=$cli('activation-inspect');$check($out[0]===0&&$out[1]===''&&$out[2]['activated']===true&&!str_contains($out[3],$c['config']['password']),'independent CLI authenticates combined activation with data-blind output');
  $out=$cli('activation-reverse');$check($out[0]===0&&$out[1]===''&&$out[2]['activated']===false,'independent CLI restores exact normal before-state');
  $newRoot=lstat($c['public']);$check($newRoot['ino']===$oldRoot['ino']&&$newRoot['dev']===$oldRoot['dev']&&($newRoot['mode']&0777)===($oldRoot['mode']&0777)&&$inventory::scan($c['public'],50000,8589934592,null,'posix',true)===$oldTree,'normal reversal restores original inode permissions and all file bytes');
  $check($db::inspect($c['config'],$c['databasePath'],$c['public'],$c['phrase'])['ownershipVerified']===true,'normal reversal retains unchanged inactive native namespace for its existing owner');
  $check($stage::inspect($c['stagePath'],$c['public'],$c['phrase'])['receipt']===$c['review']['receipt'],'normal reversal preserves complete authenticated original source');
  $out=$cli('activation-retire');$check($out[0]===0&&$out[1]===''&&!is_dir($dir),'independent CLI retires only exact reversed private files and receipt');$completed=true;
  if(function_exists('kiwe_recovery_interruption_native_checks')){$p['interruption']=kiwe_recovery_interruption_native_checks($c);$p['checks']['native interruption checkpoints and retirement accepted']=$p['interruption']['passed']&&$p['interruption']['cleanup'];if(!$p['checks']['native interruption checkpoints and retirement accepted'])return$p;}
  $p['passed']=true;
 }finally{
  if(!$completed&&$result){try{if(is_dir($result['directory'].'/before'))$class::reverse($c['config'],$result['directory'],$c['public'],$c['phrase']);$class::retire($result['directory'],$c['public'],$c['phrase']);}catch(Throwable$e){$p['cleanupFailure']=true;}}
  if(is_file($parent.'/.kiwe-activation.lock'))unlink($parent.'/.kiwe-activation.lock');if(is_dir($parent)&&scandir($parent)===['.','..'])rmdir($parent);$p['cleanup']=!is_dir($parent);
 }
 return$p;
}
require __DIR__.'/test-portable-recovery-ownership-native.php';
