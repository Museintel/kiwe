<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!defined('ARRAY_N'))define('ARRAY_N','ARRAY_N');
if(!defined('KIWE_PORTABLE_QA_INCLUDE')){
 function is_multisite(){return false;}
 require __DIR__.'/fixtures/portable-wpdb.php';
}
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container','Portable_Database_Capture','Portable_Source_Observation']as$class)require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$class.'.php';
use DSA\Backup\Portable_Archive_Cipher as Cipher;
use DSA\Backup\Portable_Archive_Container as Files;
use DSA\Backup\Portable_Database_Capture as Database;
use DSA\Backup\Portable_File_Inventory as Inventory;
use DSA\Backup\Portable_Source_Observation as Observation;
$runner=static function(?wpdb $native=null):array{
 $proof=['schema'=>'kiwe.portable-source-observation-proof.v1','passed'=>false,'cleanup'=>false,'native'=>$native!==null,'checks'=>[]];
 $check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $reject=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
 $base=sys_get_temp_dir().'/kiwe-observation-'.bin2hex(random_bytes(12));$root=$base.'/source';$owned=[];$streams=[];$phrase='Synthetic observed source capture phrase';
 $stream=static function()use(&$streams){$s=fopen('php://memory','w+b');$streams[]=$s;return$s;};
 $db=$native??new wpdb();
 $idle=static function()use($db,$native):bool{return $native!==null?(string)$db->get_var('SELECT @@SESSION.in_transaction')==='0':!$db->active;};
 try{
  mkdir($base,0700);mkdir($root,0700);mkdir($root.'/uploads',0700);
  file_put_contents($root.'/post.txt','synthetic private content');$owned[]=$root.'/post.txt';file_put_contents($root.'/uploads/asset.bin',random_bytes(700000));$owned[]=$root.'/uploads/asset.bin';
  $plan=Inventory::scan($root);$database=$stream();$files=$stream();$r=Observation::capture($root,$plan,$db,$database,$files,$phrase);
  rewind($database);$dbReview=Database::verify($database,$phrase);rewind($files);$fileReview=Files::verify($files,$phrase);
  $check($r['observedStable']&&$r['phases']===['opened','closing']&&$r['schema']===Observation::SCHEMA,'one ordered source observation brackets file capture inside the database read view');
  $check($idle()&&$r['database']['transactionEnded'],'successful observation ends the owned native transaction');
  $check($dbReview['rows']===3&&count($dbReview['tables'])===12&&$fileReview['inventory']===$plan,'independent member review verifies the native rows and exact observed files');
  $check($r['database']['archiveId']!==$r['files']['archiveId'],'database and file members use independent fresh encryption contexts');
  $check(!$r['writerFreezeVerified']&&!$r['databaseFileConsistencyVerified'],'observed stability never claims an atomic writer freeze');
  $check(!str_contains(json_encode($r),$root)&&!str_contains(json_encode($r),'private content')&&!str_contains(json_encode($r),$phrase),'source observation receipt contains no source root bodies or recovery secret');
  $check($r['fileInventorySha256']===hash('sha256',json_encode($plan,JSON_UNESCAPED_SLASHES)),'observation binds the complete reviewed file inventory');
  rewind($files);$retriedDatabase=$stream();$retried=Observation::capture_existing_files($root,$plan,$db,$retriedDatabase,$files,$phrase);
  $check($retried['observedStable']&&$retried['files']===$r['files']&&$retried['database']['archiveId']!==$r['database']['archiveId']&&$idle(),'retained authenticated files bind a fresh ended native read view');
  $check($retried['phases']===['opened','closing']&&!$retried['writerFreezeVerified']&&!$retried['databaseFileConsistencyVerified'],'private file reuse retains both source drift checkpoints and consistency limits');
  rewind($files);$held=stream_get_contents($files);$badFile=$stream();fwrite($badFile,substr($held,0,-1));rewind($badFile);$beforeReuse=$stream();
  $reject(static fn()=>Observation::capture_existing_files($root,$plan,$db,$beforeReuse,$badFile,$phrase),'truncated retained ciphertext refuses before native database capture');
  $check(fstat($beforeReuse)['size']===0&&$idle(),'invalid retained ciphertext opens no native transaction or database output');
  $changed=$plan;$changed['files'][0]['sha256']=str_repeat('0',64);rewind($files);
  $reject(static fn()=>Observation::capture_existing_files($root,$changed,$db,$stream(),$files,$phrase),'retained encrypted inventory cannot be substituted for another reviewed source');
  $alternate=$stream();Files::capture($root,$plan,$alternate,new Cipher(Cipher::new_header(),$phrase));rewind($alternate);$replacement=stream_get_contents($alternate);$reuseDatabase=$stream();$replaced=false;rewind($files);
  $replace=static function()use($reuseDatabase,$files,$replacement,&$replaced){if(!$replaced&&fstat($reuseDatabase)['size']>0){$replaced=true;ftruncate($files,0);rewind($files);fwrite($files,$replacement);rewind($files);}};
  $reject(static fn()=>Observation::capture_existing_files($root,$plan,$db,$reuseDatabase,$files,$phrase,$replace),'another authenticated ciphertext for identical files cannot replace the retained archive during capture');
  $check($replaced&&$idle(),'retained archive substitution refuses after ending the owned read view');
  ftruncate($files,0);rewind($files);fwrite($files,$held);rewind($files);
  $watchedDatabase=$stream();$watchedFiles=$stream();$paused=false;$readViewChecks=0;
  $watch=static function($sql)use($watchedFiles,$watchedDatabase,&$readViewChecks,$native){$size=fstat($watchedFiles)['size'];if($size>0&&$size<500000&&fstat($watchedDatabase)['size']===0&&str_starts_with($sql,'SELECT CONNECTION_ID()'))++$readViewChecks;return$native!==null?$sql:null;};
  $pause=static function()use($watchedFiles,$watchedDatabase,&$paused){if(!$paused&&fstat($watchedFiles)['size']>0&&fstat($watchedDatabase)['size']===0){$paused=true;usleep(2100000);}};
  if($native!==null)add_filter('query',$watch,PHP_INT_MAX);else{$priorHook=$db->hook;$db->hook=$watch;}
  try{$watched=Observation::capture($root,$plan,$db,$watchedDatabase,$watchedFiles,$phrase,$pause);}finally{if($native!==null)remove_filter('query',$watch,PHP_INT_MAX);else$db->hook=$priorHook;}
  $check($paused&&$readViewChecks>0&&$watched['database']['transactionEnded'],'long file work inspects its exact live native read view before database bytes are emitted');
  rewind($watchedDatabase);rewind($watchedFiles);$check(Database::verify($watchedDatabase,$phrase)['rows']===3&&Files::verify($watchedFiles,$phrase)['inventory']===$plan,'supervised file work still produces independently authenticated unchanged members');
  $lostDatabase=$stream();$lostFiles=$stream();$lost=false;
  $lose=static function()use($db,$lostFiles,$lostDatabase,&$lost){if(!$lost&&fstat($lostFiles)['size']>0&&fstat($lostDatabase)['size']===0){$lost=true;$db->query('ROLLBACK');usleep(2100000);}};
  $reject(static fn()=>Observation::capture($root,$plan,$db,$lostDatabase,$lostFiles,$phrase,$lose),'read view lost during long file work refuses capture before finishing the file member');
  $check($lost&&$idle()&&fstat($lostFiles)['size']<500000,'lost read view stops file output promptly and restores the native transaction');
  rewind($lostFiles);$reject(static fn()=>Files::verify($lostFiles,$phrase),'file output interrupted by a lost read view cannot authenticate a completion footer');
  $bound=$r+['recoveryArchiveId'=>bin2hex(random_bytes(16)),'recoverySha256'=>hash('sha256','owned synthetic recovery binding')];$seal=Observation::seal($bound,$phrase);
  $check(Observation::open($seal,$phrase)===$bound&&substr($seal,0,8)==='KIWEPSO1','portable observation authenticates its complete source and recovery binding');
  $check(!str_contains($seal,'fileInventorySha256')&&!str_contains($seal,$phrase)&&!str_contains($seal,$root),'portable observation keeps source metadata and recovery secret encrypted');
  $reject(static fn()=>Observation::open($seal,'wrong synthetic portable phrase'),'wrong observation passphrase refused');
  foreach([0,7,12,strlen($seal)-1]as$size)$reject(static fn()=>Observation::open(substr($seal,0,$size),$phrase),'truncated observation refused '.$size);
  $reject(static fn()=>Observation::open($seal.'tail',$phrase),'trailing observation content refused');$bad=$seal;$bad[strlen($bad)-1]=chr(ord($bad[strlen($bad)-1])^1);$reject(static fn()=>Observation::open($bad,$phrase),'damaged portable observation refused');
  $reject(static fn()=>Observation::open('{"schema":"kiwe.portable-source-observation.v1","siteHash":"same-site-only"}',$phrase),'selective same-site envelope cannot be mistaken for a portable observation');
  foreach(['writerFreezeVerified','databaseFileConsistencyVerified','recoverySha256','recoveryArchiveId','schema','extra','database','phases','fileCount']as$key){$bad=$bound;if($key==='writerFreezeVerified'||$key==='databaseFileConsistencyVerified')$bad[$key]=true;elseif($key==='fileCount')$bad[$key]=Inventory::MAX_FILES+1;elseif($key==='phases')$bad[$key]=['closing','opened'];elseif($key==='database')$bad[$key]['transactionEnded']=false;else$bad[$key]='invalid';$reject(static fn()=>Observation::seal($bad,$phrase),'invalid source observation cannot be sealed '.$key);}
  $length=unpack('Nsize',substr($seal,8,4))['size'];$prefix=substr($seal,0,12+$length);$c=new Cipher(json_decode(substr($seal,12,$length),true),$phrase);$bad=$bound;$bad['writerFreezeVerified']=true;$authenticated=$prefix.$c->seal(json_encode($bad),0,'index');$reject(static fn()=>Observation::open($authenticated,$phrase),'authenticated observation cannot claim unverified writer freeze');
  $bad=$bound;$bad['database']['archiveId']=$bad['files']['archiveId'];$reject(static fn()=>Observation::seal($bad,$phrase),'observation member contexts cannot alias');
  $reject(static fn()=>Observation::capture($root,$plan,$db,$stream(),$database,$phrase),'nonempty source member cannot be replaced');$check($idle(),'invalid sink restores the owned native read view');
  $same=$stream();$reject(static fn()=>Observation::capture($root,$plan,$db,$same,$same,$phrase),'aliased database and file sinks refused before native capture');
  file_put_contents($root.'/post.txt','changed before snapshot');$partial=$stream();$reject(static fn()=>Observation::capture($root,$plan,$db,$partial,$stream(),$phrase),'source changed before transaction refused');$check(fstat($partial)['size']===0&&$idle(),'preexisting drift produces no database output or transaction');file_put_contents($root.'/post.txt','synthetic private content');
  foreach(['changed','added','removed','metadata','cancelled','lost-read-view']as$case){
   $plan=Inventory::scan($root);$database=$stream();$files=$stream();$triggered=false;$priorModified=filemtime($root.'/post.txt');
   $budget=static function()use($case,$database,$files,$root,$db,&$triggered):void{
    if($triggered||fstat($database)['size']<1)return;$triggered=true;
    if($case==='changed')file_put_contents($root.'/post.txt','different content while the read view is open');
    if($case==='added')file_put_contents($root.'/new.txt','new source dependency');
    if($case==='removed')unlink($root.'/post.txt');
    if($case==='metadata')touch($root.'/post.txt',time()+10);
    if($case==='cancelled')throw new DSA\Backup\Archive_Error('Synthetic native capture cancellation');
    if($case==='lost-read-view')$db->query('ROLLBACK');
   };
   $reject(static fn()=>Observation::capture($root,$plan,$db,$database,$files,$phrase,$budget),'active native capture refuses '.$case);
   $check($triggered&&$idle(),'refusal '.$case.' ends the owned native read view');rewind($database);$reject(static fn()=>Database::verify($database,$phrase),'refusal '.$case.' cannot produce an accepted database footer');
   if(is_file($root.'/new.txt'))unlink($root.'/new.txt');file_put_contents($root.'/post.txt','synthetic private content');touch($root.'/post.txt',$priorModified);
  }
  $plan=Inventory::scan($root);$database=$stream();$files=$stream();$triggered=false;
  $budget=static function()use($files,$database,$root,&$triggered):void{if(!$triggered&&fstat($files)['size']>0&&fstat($database)['size']===0){$triggered=true;file_put_contents($root.'/uploads/asset.bin','changed during file capture');}};
  $reject(static fn()=>Observation::capture($root,$plan,$db,$database,$files,$phrase,$budget),'file changes during capture refused inside the native snapshot');$check($triggered&&$idle(),'during-file drift ends the owned native snapshot');rewind($database);$reject(static fn()=>Database::verify($database,$phrase),'during-file drift cannot publish a completed database member');
  $proof['passed']=true;
 }catch(Throwable$e){$proof['error']=$e->getMessage();}
 finally{
  foreach($streams as$s)if(is_resource($s))fclose($s);foreach(array_reverse($owned)as$path)if(is_file($path))unlink($path);if(is_file($root.'/new.txt'))unlink($root.'/new.txt');foreach([$root.'/uploads',$root,$base]as$dir)if(is_dir($dir))rmdir($dir);
  $proof['cleanup']=!file_exists($base)&&$idle();$proof['checks']['all exact owned files and temporary streams retired']=$proof['cleanup'];$proof['passed']=$proof['passed']&&$proof['cleanup'];
 }
 return$proof;
};
if(defined('KIWE_PORTABLE_QA_INCLUDE'))return$runner;
$result=$runner();echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($result['passed']?0:1);
