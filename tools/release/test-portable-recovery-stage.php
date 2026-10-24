<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
define('ARRAY_N','ARRAY_N');
function is_multisite(){return false;}
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container','Portable_Database_Capture','Portable_Source_Compatibility','Portable_Source_Observation','Portable_Recovery_Set','Portable_Recovery_Stage']as$class)require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$class.'.php';
require __DIR__.'/fixtures/portable-wpdb.php';
use DSA\Backup\Portable_Database_Capture as Database;
use DSA\Backup\Portable_Source_Observation as Observation;
use DSA\Backup\Portable_Recovery_Set as Set;
use DSA\Backup\Portable_Recovery_Stage as Stage;
use DSA\Backup\Portable_File_Inventory as Inventory;

$proof=['schema'=>'kiwe.private-recovery-stage-proof.v1','runtime'=>PHP_VERSION,'scope'=>'synthetic encrypted source, real local filesystem and independent CLI only','passed'=>false,'cleanup'=>false,'checks'=>[],'limitations'=>['Scripted database model does not execute SQL. No native destination activation, URL/prefix rewrite, original-site offline recovery, provider/inode quota or Windows ACL acceptance.']];
$check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
$root=str_replace('\\','/',sys_get_temp_dir()).'/kiwe-stage-qa-'.bin2hex(random_bytes(12));$source=$root.'/source';$parent=$root.'/private';$public=$root.'/public';$other=$root.'/other';
$phrase='Synthetic private staging phrase only';$streams=[];$owned=[];$dirs=[];$stagePath=null;$extra=[];
$temp=static function()use(&$streams){$s=fopen('php://temp/maxmemory:2097152','w+b');$streams[]=$s;return$s;};
$from=static function(string$bytes)use($temp){$s=$temp();fwrite($s,$bytes);rewind($s);return$s;};
$mkdir=static function(string$p)use(&$dirs){if(!mkdir($p,0700))throw new RuntimeException('Fixture directory failed');chmod($p,0700);$dirs[]=$p;};
$write=static function(string$p,string$v)use(&$owned){file_put_contents($p,$v);chmod($p,0600);$owned[]=$p;};
$attempts=static fn()=>array_values(array_filter(scandir($parent),static fn($n)=>str_starts_with($n,'kiwe-recovery-')));
try{
 $mkdir($root);foreach([$source,$parent,$public,$other]as$p)$mkdir($p);
 foreach(['wp-admin','wp-admin/includes','wp-includes','wp-content','wp-content/plugins','wp-content/plugins/example','wp-content/mu-plugins','wp-content/mu-plugins/kiwe','wp-content/themes','wp-content/themes/example','wp-content/uploads']as$d)$mkdir($source.'/'.$d);
 $core=['index.php','wp-blog-header.php','wp-load.php','wp-settings.php','wp-config.php','wp-login.php','wp-cron.php','wp-includes/version.php','wp-includes/load.php','wp-includes/functions.php','wp-admin/admin.php','wp-admin/includes/admin.php'];
 $code='<?php throw new RuntimeException("Captured code must stay inert");';
 foreach(array_merge($core,['wp-content/plugins/example/main.php','wp-content/mu-plugins/kiwe-loader.php','wp-content/mu-plugins/kiwe/entry.php','wp-content/themes/example/style.css'])as$f)$write($source.'/'.$f,$f==='wp-config.php'?'<?php /* SyntheticConfigSecret */':$code);
 $write($source.'/wp-content/uploads/empty.bin','');$write($source.'/wp-content/uploads/random.bin',random_bytes(1100000));$write($source.'/wp-content/uploads/compressible.bin',str_repeat("\0",2000000));
 $write($public.'/index.php','Original prepared destination');$write($public.'/wp-config.php','Original destination connection and salts');$nativeBefore=[hash_file('sha256',$public.'/index.php'),hash_file('sha256',$public.'/wp-config.php')];
 $plan=Inventory::scan($source);$db=new wpdb();$large=random_bytes(Database::INLINE_ROW_BYTES+8193);$db->tables['wp_posts']['rows'][]=['4',$large];
 $dbStream=$temp();$fileStream=$temp();$observation=Observation::capture($source,$plan,$db,$dbStream,$fileStream,$phrase);rewind($dbStream);rewind($fileStream);
 $layout=['schema'=>DSA\Backup\Portable_Source_Compatibility::SCHEMA,'namePolicy'=>$plan['namePolicy'],'scope'=>'ordinary-single-site-native-layout','platform'=>['php'=>PHP_VERSION,'phpMinimum'=>'8.2','wordpress'=>'7.1.2','wordpressDatabaseVersion'=>61234,'kiwe'=>'8.0.0-fixture','operatingSystem'=>PHP_OS_FAMILY],'layout'=>['core'=>$core,'content'=>'wp-content','plugins'=>'wp-content/plugins','mustUsePlugins'=>'wp-content/mu-plugins','themeRoots'=>['wp-content/themes'],'uploads'=>'wp-content/uploads'],'dependencies'=>['activePlugins'=>['wp-content/plugins/example/main.php'],'mustUseEntries'=>['wp-content/mu-plugins/kiwe-loader.php'],'themes'=>['template'=>['directory'=>'wp-content/themes/example','root'=>'wp-content/themes'],'stylesheet'=>['directory'=>'wp-content/themes/example','root'=>'wp-content/themes']],'kiwe'=>['loader'=>'wp-content/mu-plugins/kiwe-loader.php','package'=>'wp-content/mu-plugins/kiwe','entry'=>'wp-content/mu-plugins/kiwe/entry.php'],'loadedFiles'=>['wp-config.php']],'exclusions'=>$plan['exclusions'],'limits'=>['databaseCaptureVerified'=>false,'databaseFileConsistencyVerified'=>false,'externalServicesCaptured'=>false,'destinationCompatibilityVerified'=>false]];
 $out=$temp();$setReceipt=Set::create($dbStream,$fileStream,$layout,$out,$phrase);rewind($out);$archive=stream_get_contents($out);$observation['recoveryArchiveId']=$setReceipt['archiveId'];$observation['recoverySha256']=$setReceipt['sha256'];$report=Observation::seal($observation,$phrase);
 $prepare=static fn(string$a,?string$r=null,?callable$b=null)=>Stage::prepare($from($a),$r??$report,$phrase,$parent,$public,$b);
 $result=$prepare($archive);$stagePath=$result['directory'];$review=Stage::inspect($stagePath,$public,$phrase);
 $check($result['status']==='staged'&&$result['files']===count($plan['files'])&&$result['tables']===12&&$result['rows']===4,'complete private staging and independent reinspection succeed');
 $check($result['activated']===false&&$result['fullSiteReady']===false&&$result['consistency']==='members-authenticated-only','private preparation makes no activation or atomic consistency claim');
 $check([hash_file('sha256',$public.'/index.php'),hash_file('sha256',$public.'/wp-config.php')]===$nativeBefore,'prepared destination files connection and salts remain exact');
 $check(file_get_contents($stagePath.'/site/index.php')===$code,'captured PHP stays inert and exact');
 $check(filesize($stagePath.'/site/wp-content/uploads/empty.bin')===0&&hash_file('sha256',$stagePath.'/site/wp-content/uploads/random.bin')===hash_file('sha256',$source.'/wp-content/uploads/random.bin'),'zero length and multiframe binary files stage exactly');
 $check(filesize($stagePath.'/site/wp-content/uploads/compressible.bin')===2000000&&$result['fileBytes']>strlen($archive),'capacity uses expanded file bytes rather than compressed transport bytes');
 $check(!str_contains(file_get_contents($stagePath.'/complete.receipt'),$phrase)&&!str_contains(file_get_contents($stagePath.'/complete.receipt'),'SyntheticConfigSecret'),'completion receipt keeps phrase and captured settings out of plaintext');
 $records=file_get_contents($stagePath.'/database.records');$check(substr($records,0,8)==='KIWEDRS1'&&strlen($records)===$review['receipt']['records']['bytes'],'bounded inert database record stream has exact completion descriptor');
 $offset=8;$inline=[];$assembled='';$types=[];$lastKind='';
 while($offset<strlen($records)){$kind=$records[$offset];$n=unpack('Nsize',substr($records,$offset+1,4))['size'];$p=substr($records,$offset+5,$n);$offset+=5+$n;$types[]=$p[0];$lastKind=$kind;if($p[0]==='R')$inline[]=json_decode(substr($p,1),true)['cells'];if($p[0]==='C')$assembled.=substr($p,9);}
 $check($offset===strlen($records)&&$lastKind==='M'&&count($inline)===3&&$inline[0][1]===['n']&&$inline[1][1]===['b','']&&base64_decode($inline[2][1][1],true)==="synthetic\0binary\xff",'NULL empty and non UTF8 cells remain exact inert data');
 $check(in_array('L',$types,true)&&in_array('Z',$types,true)&&$assembled==='4'.$large,'large row chunks preserve exact binary cell order without SQL execution');
 if(PHP_OS_FAMILY!=='Windows'){$check((fileperms($stagePath)&0777)===0700&&(fileperms($stagePath.'/site/wp-config.php')&0777)===0600,'native POSIX directory and sensitive file permissions are private');}else $proof['windowsAclVerified']=false;
 $baselineEntries=scandir($stagePath);
 $foreign=$stagePath.'/foreign.txt';file_put_contents($foreign,'Preserve unknown owner data');chmod($foreign,0600);
 $reject(static fn()=>Stage::retire($stagePath,$public,$phrase),'unknown root entry refuses retirement before deleting any owned data');$check(is_file($stagePath.'/complete.receipt')&&file_get_contents($foreign)==='Preserve unknown owner data','unknown-entry refusal preserves completion and foreign data');unlink($foreign);
 mkdir($stagePath.'/site/.git',0700);file_put_contents($stagePath.'/site/.git/foreign','Keep hidden foreign data');chmod($stagePath.'/site/.git/foreign',0600);
 $reject(static fn()=>Stage::retire($stagePath,$public,$phrase),'unexpected excluded directory cannot hide from stage retirement');unlink($stagePath.'/site/.git/foreign');rmdir($stagePath.'/site/.git');
 $old=file_get_contents($stagePath.'/site/index.php');file_put_contents($stagePath.'/site/index.php','Newer owner change');
 $reject(static fn()=>Stage::retire($stagePath,$public,$phrase),'changed staged content refuses deletion');$check(file_get_contents($stagePath.'/site/index.php')==='Newer owner change'&&scandir($stagePath)===$baselineEntries,'changed-data refusal preserves all stage entries');file_put_contents($stagePath.'/site/index.php',$old);
 $reject(static fn()=>Stage::inspect($stagePath,$other,$phrase),'stage receipt refuses another destination root');
 $reject(static fn()=>Stage::retire($stagePath,$public,'Wrong synthetic recovery phrase'),'wrong phrase cannot retire a completed stage');
 $receipt=file_get_contents($stagePath.'/complete.receipt');$bad=$receipt;$bad[strlen($bad)-1]=chr(ord($bad[strlen($bad)-1])^1);file_put_contents($stagePath.'/complete.receipt',$bad);
 $reject(static fn()=>Stage::inspect($stagePath,$public,$phrase),'damaged completion authentication is refused');file_put_contents($stagePath.'/complete.receipt',$receipt);
 Stage::retire($stagePath,$public,$phrase);$check(!file_exists($stagePath),'reviewed complete private stage retires all owned entries');$stagePath=null;
 $lock=fopen($parent.'/.kiwe-recovery.lock','r+b');flock($lock,LOCK_EX|LOCK_NB);
 $reject(static fn()=>$prepare($archive),'contended private lease refuses a second writer immediately');$check($attempts()===[],'lease denial does not create or remove another attempt');flock($lock,LOCK_UN);fclose($lock);
 $reject(static fn()=>Stage::prepare($from($archive),$report,'Wrong synthetic recovery phrase',$parent,$public),'wrong phrase creates no private attempt');$check($attempts()===[],'wrong phrase leaves no staged files');
 $wrong=$observation;$wrong['recoverySha256']=str_repeat('0',64);$reject(static fn()=>$prepare($archive,Observation::seal($wrong,$phrase)),'different recovery source report refused');
 $wrong=$observation;$wrong['database']['rows']++;$reject(static fn()=>$prepare($archive,Observation::seal($wrong,$phrase)),'authenticated mismatched member totals refused before plaintext staging');$check($attempts()===[],'mismatched-member refusal retires copied ciphertext members');
 $bad=substr($archive,0,-1);$wrong=$observation;$wrong['recoverySha256']=hash('sha256',$bad);$reject(static fn()=>$prepare($bad,Observation::seal($wrong,$phrase)),'truncated set with resigned report still fails complete archive verification');$check($attempts()===[],'truncated review leaves no accepted or partial stage');
 $reject(static fn()=>Stage::prepare($from($archive),$report,$phrase,$public,$public),'public root cannot be its own private staging parent');
 $mkdir($public.'/bad-private');$reject(static fn()=>Stage::prepare($from($archive),$report,$phrase,$public.'/bad-private',$public),'private staging inside the running website is refused');
 $interrupted=false;$reject(static function()use($prepare,$archive,$parent,&$interrupted){$prepare($archive,null,static function()use($parent,&$interrupted){foreach(new FilesystemIterator($parent,FilesystemIterator::SKIP_DOTS)as$d){$p=$d->getPathname().'/site/wp-content/uploads/random.bin';clearstatcache(true,$p);if(is_file($p)&&filesize($p)>500000){$interrupted=true;throw new DSA\Backup\Archive_Error('Synthetic private write interruption');}}});},'interrupted plaintext write is refused');
 $check($interrupted&&$attempts()===[],'interrupted private file write removes exact owned files and directories');
 $injected=null;$reject(static function()use($prepare,$archive,$parent,&$injected){$prepare($archive,null,static function()use($parent,&$injected){if($injected!==null)return;foreach(new FilesystemIterator($parent,FilesystemIterator::SKIP_DOTS)as$d){if(is_dir($d->getPathname().'/site')){$injected=$d->getPathname().'/foreign.txt';file_put_contents($injected,'Unknown newer data');chmod($injected,0600);}}});},'unknown data injected during preparation prevents final acceptance');
 $check($injected!==null&&file_get_contents($injected)==='Unknown newer data'&&count($attempts())===1,'failure cleanup preserves unknown newer paths instead of recursively deleting them');unlink($injected);rmdir(dirname($injected));
 $write($root.'/source.kiwebackup',$archive);$write($root.'/source.kiwesource',$report);
 $entry=realpath(__DIR__.'/../../wp-content/mu-plugins/dsa/recovery/portable-recovery.php');$cmd=[PHP_BINARY];if(php_ini_loaded_file())array_push($cmd,'-c',php_ini_loaded_file());elseif(extension_loaded('openssl')){ $extension=dirname(PHP_BINARY).'/ext/'.(PHP_OS_FAMILY==='Windows'?'php_openssl.dll':'openssl.so');if(is_file($extension))array_push($cmd,'-d','extension='.$extension); }array_push($cmd,$entry,'--prepare','--archive='.$root.'/source.kiwebackup','--observation='.$root.'/source.kiwesource','--parent='.$parent,'--public-root='.$public);
 $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($p))throw new RuntimeException('Independent CLI fixture failed');fwrite($pipes[0],$phrase."\n");fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);$cli=json_decode($stdout,true);
 $check($code===0&&($cli['status']??'')==='staged'&&$stderr==='','independent recovery entry works without loading WordPress functions');$stagePath=$cli['directory'];
 $check(!str_contains($stdout,$phrase)&&!str_contains($stdout,'SyntheticConfigSecret')&&!str_contains($stdout,'wp_posts'),'CLI result exposes no phrase captured credentials or table values');Stage::retire($stagePath,$public,$phrase);$stagePath=null;
 $check([hash_file('sha256',$public.'/index.php'),hash_file('sha256',$public.'/wp-config.php')]===$nativeBefore&&$db->log!==[]&&count(array_filter($db->log,static fn($sql)=>preg_match('/^(?:INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|RENAME)/',$sql)))===0,'all preparation denial interruption and retirement paths preserve live files and execute no SQL writes');
 $check($attempts()===[],'all completed and failed attempts are retired');$proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
finally{
 if($stagePath!==null&&is_dir($stagePath)){try{Stage::retire($stagePath,$public,$phrase);}catch(Throwable$e){$proof['retirementError']='Changed owned fixture requires inspection';}}
 foreach($streams as$s)if(is_resource($s))fclose($s);
 foreach(array_reverse($owned)as$p)if(is_file($p))unlink($p);
 if(is_file($parent.'/.kiwe-recovery.lock')&&!is_link($parent.'/.kiwe-recovery.lock')&&filesize($parent.'/.kiwe-recovery.lock')===0)unlink($parent.'/.kiwe-recovery.lock');
 foreach(array_reverse($dirs)as$p)if(is_dir($p))@rmdir($p);
 $proof['cleanup']=!file_exists($root);$proof['passed']=$proof['passed']&&$proof['cleanup'];
}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($proof['passed']?0:1);
