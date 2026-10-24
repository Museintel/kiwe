<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Cipher.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Portable_Archive_Cipher.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Portable_File_Inventory.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Portable_Archive_Container.php';
use DSA\Backup\Portable_Archive_Container as Container;
use DSA\Backup\Portable_Archive_Cipher as Cipher;
use DSA\Backup\Portable_File_Inventory as Inventory;
$proof=['schema'=>'kiwe.portable-container-proof.v1','runtime'=>PHP_VERSION,'passed'=>false,'cleanup'=>false,'checks'=>[],'limitations'=>['Synthetic local CLI only; no native Linux, database capture, live consistency lease, actor/job boundary, filesystem extraction, interrupted-transfer resumption or migration acceptance.']];
$check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable $fn,string $label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error $e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
$root=sys_get_temp_dir().'/kiwe-container-'.bin2hex(random_bytes(12));$owned=[];$dirs=[];$streams=[];$phrase='Synthetic portable container QA phrase';
$put=static function(string $relative,string $value)use($root,&$owned){$path=$root.'/'.$relative;if(file_put_contents($path,$value)!==strlen($value))throw new RuntimeException('Fixture write failed');$owned[]=$path;};
$dir=static function(string $relative)use($root,&$dirs){$path=$root.(''===$relative?'':'/'.$relative);if(!mkdir($path,0700))throw new RuntimeException('Fixture directory failed');$dirs[]=$path;};
$temp=static function()use(&$streams){$s=fopen('php://temp/maxmemory:2097152','w+b');if(!$s)throw new RuntimeException('Fixture stream failed');$streams[]=$s;return$s;};
$verify=static function(string $bytes,string $key)use($temp){$s=$temp();fwrite($s,$bytes);rewind($s);return Container::verify($s,$key);};
try{
 $dir('');$dir('empty');$dir('wp-content');$dir('wp-content/uploads');$dir('wp-content/uploads/empty-child');$dir('wp-content/cache');
 $binary="Private synthetic binary \0 ".chr(255).random_bytes(Cipher::CHUNK_BYTES+200);$put('wp-content/uploads/confidential-asset.bin',$binary);$put('private-site-config.php','<?php // synthetic settings only');$put('empty-file','');$put('wp-content/cache/excluded.cache','excluded synthetic cache');
 $plan=Inventory::scan($root);$cipher=new Cipher(Cipher::new_header(),$phrase);$sink=$temp();$receipt=Container::capture($root,$plan,$sink,$cipher);rewind($sink);$archive=stream_get_contents($sink);rewind($sink);$review=Container::verify($sink,$phrase);
 $check($review['inventory']===$plan,'complete encrypted inventory round trip');
 $check(in_array('empty',$review['inventory']['directories'],true)&&in_array('wp-content/uploads/empty-child',$review['inventory']['directories'],true),'empty directories preserved');
 $check($review['inventory']['exclusions']===[['path'=>'wp-content/cache','reason'=>'Rebuildable cache']],'declared exclusions retained');
 $check($review['inventory']['files'][0]['bytes']===0,'empty file preserved');
 $check($receipt['frames']===$review['frames']&&$receipt['bytes']===strlen($archive)&&hash_equals($receipt['sha256'],hash('sha256',$archive)),'transport receipt matches actual complete bytes');
 foreach(['confidential-asset.bin','private-site-config.php','empty-child','Rebuildable cache','Private synthetic binary','excluded synthetic cache',$phrase,$root]as$i=>$private)$check(!str_contains($archive,$private),'cleartext excludes synthetic private value '.$i);
 $check(strlen($archive)>strlen($binary),'binary multi-frame content captured');
 $reject(static fn()=>$verify($archive,'Incorrect synthetic container phrase'),'wrong phrase refused');
 foreach([0,7,11,strlen($archive)-1,strlen($archive)-100]as$length)$reject(static fn()=>$verify(substr($archive,0,$length),$phrase),'truncated container '.$length.' refused');
 $reject(static fn()=>$verify($archive.'trailing',$phrase),'appended content refused');
 $bad=$archive;$bad[0]='X';$reject(static fn()=>$verify($bad,$phrase),'wrong container magic refused');
 $bad=substr_replace($archive,pack('N',PHP_INT_MAX),8,4);$reject(static fn()=>$verify($bad,$phrase),'oversized clear header refused');
 $headerSize=unpack('Nsize',substr($archive,8,4))['size'];$start=12+$headerSize;$packets=[];$offset=$start;
 while($offset<strlen($archive)){$length=unpack('Nsize',substr($archive,$offset+1,4))['size'];$packets[]=substr($archive,$offset,5+$length);$offset+=5+$length;}
 $prefix=substr($archive,0,$start);$check($offset===strlen($archive)&&$packets[0][0]==='I'&&$packets[count($packets)-1][0]==='M','index and final manifest have bounded envelopes');
 $bad=$packets;$bad[2]=$packets[3];$bad[3]=$packets[2];$reject(static fn()=>$verify($prefix.implode('',$bad),$phrase),'reordered encrypted frames refused');
 $bad=$packets;array_splice($bad,2,1);$reject(static fn()=>$verify($prefix.implode('',$bad),$phrase),'missing encrypted frame refused');
 $bad=$packets;array_splice($bad,2,0,[$packets[2]]);$reject(static fn()=>$verify($prefix.implode('',$bad),$phrase),'duplicated encrypted frame refused');
 $bad=$packets;array_pop($bad);$reject(static fn()=>$verify($prefix.implode('',$bad),$phrase),'missing final completeness record refused');
 $bad=$packets;$last=count($bad)-1;$bad[$last][strlen($bad[$last])-1]=chr(ord($bad[$last][strlen($bad[$last])-1])^1);$reject(static fn()=>$verify($prefix.implode('',$bad),$phrase),'tampered final manifest refused');
 $bad=$packets;$bad[3][strlen($bad[3])-1]=chr(ord($bad[3][strlen($bad[3])-1])^1);$reject(static fn()=>$verify($prefix.implode('',$bad),$phrase),'tampered metadata refused');
 $bad=$packets;$bad[1]=substr_replace($bad[1],pack('N',Cipher::CHUNK_BYTES+46),1,4);$reject(static fn()=>$verify($prefix.implode('',$bad),$phrase),'oversized frame refused before allocation');
 // Possession of a recovery phrase must not make structurally unsafe plaintext acceptable.
 $reseal=static function(array $packetList,int $position,string $plain,string $kind='D')use($cipher,$prefix){$purpose=$kind==='M'?'manifest':($kind==='I'?'index':'data');$f=$cipher->seal($plain,$position,$purpose);$packetList[$position]=$kind.pack('N',strlen($f)).$f;return$prefix.implode('',$packetList);};
 $reject(static fn()=>$verify($reseal($packets,1,'R{"path":"../escape"}'),$phrase),'authenticated unsafe path refused');
 $reject(static fn()=>$verify($reseal($packets,1,'Z{"path":"empty"}'),$phrase),'authenticated unknown record refused');
 $reject(static fn()=>$verify($reseal($packets,1,'Bdata without file'),$phrase),'authenticated misplaced file body refused');
 $reject(static fn()=>$verify($reseal($packets,1,'E{"bytes":0,"sha256":"'.hash('sha256','').'"}'),$phrase),'authenticated misplaced file end refused');
 $last=count($packets)-1;$manifest=json_decode($cipher->open(substr($packets[$last],5),$last,'manifest'),true);$manifest['transcript']=str_repeat('0',64);
 $reject(static fn()=>$verify($reseal($packets,$last,json_encode($manifest),'M'),$phrase),'authenticated wrong completeness transcript refused');
 $changed=$plan;$changed['bytes']++;$reject(static fn()=>Container::capture($root,$changed,$temp(),$cipher),'inconsistent reviewed byte total refused');
 $changed=$plan;$changed['files'][0]['path']='../escape';$reject(static fn()=>Container::capture($root,$changed,$temp(),$cipher),'unsafe reviewed path refused');
 $changed=$plan;$changed['directories'][]='EMPTY';sort($changed['directories']);$reject(static fn()=>Container::capture($root,$changed,$temp(),$cipher),'reviewed case collision refused');
 $changed=$plan;$changed['directories']=array_values(array_filter($changed['directories'],static fn($p)=>$p!=='wp-content/uploads'));$reject(static fn()=>Container::capture($root,$changed,$temp(),$cipher),'missing reviewed parent refused');
 $put('private-site-config.php','changed synthetic private configuration');$reject(static fn()=>Container::capture($root,$plan,$temp(),$cipher),'source changed after reviewed inventory refused');
 $put('private-site-config.php','<?php // synthetic settings only');$partial=$temp();$calls=0;
 $reject(static function()use($root,$plan,$partial,$cipher,&$calls){Container::capture($root,$plan,$partial,$cipher,static function()use(&$calls){if(++$calls>15)throw new DSA\Backup\Archive_Error('Synthetic budget expired');});},'bounded capture interruption refused');rewind($partial);$interrupted=stream_get_contents($partial);$check(strlen($interrupted)>0,'interruption leaves only an incomplete private stream');$reject(static fn()=>$verify($interrupted,$phrase),'interrupted capture cannot verify');
 $input=$temp();fwrite($input,$archive);rewind($input);$calls=0;$reject(static function()use($input,$phrase,&$calls){Container::verify($input,$phrase,static function()use(&$calls){if(++$calls>3)throw new DSA\Backup\Archive_Error('Synthetic review budget expired');});},'bounded verification interruption refused');
 $nonempty=$temp();fwrite($nonempty,'existing private data');rewind($nonempty);$reject(static fn()=>Container::capture($root,$plan,$nonempty,$cipher),'nonempty sink refused');
 $position=$temp();fwrite($position,$archive);$reject(static fn()=>Container::verify($position,$phrase),'reader not at container beginning refused');
 $emptyRoot=$root.'/empty';$emptyPlan=Inventory::scan($emptyRoot);$emptySink=$temp();$emptyReceipt=Container::capture($emptyRoot,$emptyPlan,$emptySink,$cipher);rewind($emptySink);$check(Container::verify($emptySink,$phrase)['inventory']===$emptyPlan&&$emptyReceipt['frames']===2,'empty dedicated directory has complete index and manifest');
 // Change membership or an already-read file after substantial body output. No final manifest may escape.
 $drift=static function(callable $mutate,callable $undo,string $label)use($temp,$root,$plan,$cipher,$reject,$verify,$phrase,$check){
  $output=$temp();$changed=false;
  try{
   $reject(static function()use($root,$plan,$output,$cipher,$mutate,&$changed){Container::capture($root,$plan,$output,$cipher,static function()use($output,$mutate,&$changed){if(!$changed&&ftell($output)>5000){$mutate();$changed=true;}});},$label.' capture refused');
   $check($changed,$label.' injected after file output');rewind($output);$partial=stream_get_contents($output);$reject(static fn()=>$verify($partial,$phrase),$label.' cannot verify as complete');
  }finally{$undo();}
 };
 $drift(static fn()=>$put('late-added-file','new synthetic asset'),static fn()=>unlink($root.'/late-added-file'),'file added during capture');
 $drift(static fn()=>$dir('late-added-empty'),static fn()=>rmdir($root.'/late-added-empty'),'empty directory added during capture');
 $drift(static fn()=>rmdir($root.'/wp-content/uploads/empty-child'),static fn()=>mkdir($root.'/wp-content/uploads/empty-child',0700),'reviewed empty directory removed during capture');
 $drift(static fn()=>$put('private-site-config.php','changed after this file was captured'),static fn()=>$put('private-site-config.php','<?php // synthetic settings only'),'previously captured file changed');
 $put('wp-content/cache/excluded.cache','cache changes are declared outside recovery');Inventory::unchanged_tree($root,$plan);$check(true,'declared cache payload changes do not alter the reviewed recoverable file set');
 $inside=$root.'/owned-container-output';$file=fopen($inside,'w+b');$streams[]=$file;$owned[]=$inside;$reject(static fn()=>Container::capture($root,$plan,$file,$cipher),'backup sink inside source refused');
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
finally{
 foreach($streams as$s)if(is_resource($s))fclose($s);
 foreach(array_unique(array_reverse($owned))as$path)if(is_file($path)&&!unlink($path))$proof['cleanupError']='Owned fixture file cleanup failed';
 foreach(array_reverse($dirs)as$path)if(is_dir($path)&&!rmdir($path))$proof['cleanupError']='Owned fixture directory cleanup failed';
 $proof['cleanup']=!file_exists($root);if(!$proof['cleanup'])$proof['passed']=false;
}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($proof['passed']&&$proof['cleanup']?0:1);
