<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container']as$class)require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$class.'.php';
use DSA\Backup\Portable_File_Inventory as Inventory;
use DSA\Backup\Portable_Archive_Cipher as Cipher;
use DSA\Backup\Portable_Archive_Container as Container;
$proof=['schema'=>'kiwe.portable-name-policy-proof.v1','runtime'=>PHP_VERSION,'platform'=>PHP_OS_FAMILY,'passed'=>false,'cleanup'=>false,'checks'=>[],'skips'=>[]];
$check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
$phrase='Synthetic path policy recovery phrase';$cipher=new Cipher(Cipher::new_header(),$phrase);$streams=[];$owned=[];$dirs=[];$root=sys_get_temp_dir().'/kiwe-name-policy-'.bin2hex(random_bytes(12));
$temp=static function()use(&$streams){$s=fopen('php://temp/maxmemory:1048576','w+b');if(!$s)throw new RuntimeException('Fixture stream failed');$streams[]=$s;return$s;};
$verify=static function(string$bytes)use($temp,$phrase){$s=$temp();fwrite($s,$bytes);rewind($s);return Container::verify($s,$phrase);};
// Complete, authenticated POSIX fixture can also be reviewed on Windows without writing its names.
$values=['Case'=>'upper','case'=>'lower','literal\\backslash.bin'=>"binary\0".chr(255),'nul.txt'=>'reserved on Windows','question?.bin'=>'question','colon:asset'=>'colon','trailing.'=>'dot','trailing '=>'space','quoted".bin'=>'quote','empty\\directory/child'=>'child'];ksort($values,SORT_STRING);
$plan=['files'=>[],'directories'=>['empty\\directory'],'exclusions'=>[],'bytes'=>0,'namePolicy'=>'posix'];
foreach($values as$path=>$value){$plan['files'][]=['path'=>$path,'bytes'=>strlen($value),'sha256'=>hash('sha256',$value)];$plan['bytes']+=strlen($value);}
$build=static function(array$inventory,array$content)use($cipher){
 $summary=['schema'=>Container::SCHEMA,'files'=>count($inventory['files']),'directories'=>count($inventory['directories']),'exclusions'=>count($inventory['exclusions']),'bytes'=>$inventory['bytes'],'namePolicy'=>$inventory['namePolicy']];
 $header=json_encode($cipher->header(),JSON_UNESCAPED_SLASHES);$archive='KIWEPFC2'.pack('N',strlen($header)).$header;$sequence=0;$transcript=hash_init('sha256');
 $emit=static function(string$plain,string$purpose)use($cipher,&$archive,&$sequence,$transcript){$frame=$cipher->seal($plain,$sequence,$purpose);$packet=($purpose==='index'?'I':($purpose==='manifest'?'M':'D')).pack('N',strlen($frame)).$frame;$archive.=$packet;if($purpose!=='manifest')hash_update($transcript,$packet);$sequence++;};
 $emit(json_encode($summary),'index');foreach($inventory['directories']as$path)$emit('R'.json_encode(['path'=>$path]),'data');foreach($inventory['exclusions']as$row)$emit('X'.json_encode($row),'data');
 foreach($inventory['files']as$row){$emit('F'.json_encode($row),'data');if($row['bytes'])$emit('B'.$content[$row['path']],'data');$emit('E'.json_encode(['bytes'=>$row['bytes'],'sha256'=>$row['sha256']]),'data');}
 $emit(json_encode($summary+['frames'=>$sequence,'transcript'=>hash_final($transcript)]),'manifest');return$archive;
};
try{
 $check(Inventory::validate_plan($plan)==='posix','complete POSIX metadata plan accepted');
 $archive=$build($plan,$values);$review=$verify($archive);$check($review['inventory']===$plan,'authenticated POSIX filenames and byte hashes survive review exactly');
 $check(array_column($review['inventory']['files'],'path')===array_keys($values),'backslashes are literal bytes and case-distinct files remain distinct');
 Inventory::assert_destination_paths($review['inventory'],'posix');$check(true,'POSIX destination name preflight accepts exact source names');
 $reject(static fn()=>Inventory::assert_destination_paths($review['inventory'],'portable'),'Windows-incompatible destination refused without any writes');
 $check(!str_contains($archive,'namePolicy')&&!str_contains($archive,'posix')&&!str_contains($archive,'literal\\backslash')&&!str_contains($archive,'reserved on Windows'),'private name policy filenames and bodies remain encrypted');
 $pair=['files'=>array_slice($plan['files'],0,2),'directories'=>[],'exclusions'=>[],'bytes'=>10,'namePolicy'=>'posix'];
 $check(Inventory::validate_plan($pair)==='posix','case-distinct POSIX pair accepted');$reject(static fn()=>Inventory::assert_destination_paths($pair,'portable'),'case-distinct source pair refused for case-insensitive destination');
 foreach(['','/root','../escape','a/../escape','a//b','a/.','a/','a'.chr(0),'a'.chr(31),'a'.chr(127),'bad'.chr(255)]as$i=>$path)$reject(static fn()=>Inventory::relative($path,'posix'),'unsafe POSIX metadata path '.$i.' refused');
 foreach(['nul.txt','COM1/asset','LPT'.chr(0xc2).chr(0xb9).'.txt','trailing.','trailing ','colon:asset','literal\\backslash','question?.bin']as$i=>$path){Inventory::relative($path,'posix');$check(true,'literal POSIX-only name '.$i.' accepted');$reject(static fn()=>Inventory::relative($path,'portable'),'POSIX-only name '.$i.' refused under portable policy');}
 $reject(static fn()=>Inventory::name_policy('automatic'),'unknown source filename policy refused');
 $reject(static fn()=>Inventory::assert_destination_paths($plan,'automatic'),'unknown destination policy refused');
 $bad=$plan;unset($bad['namePolicy']);$reject(static fn()=>Inventory::validate_plan($bad),'legacy unauthenticated filename plan refused');
 $bad=$plan;$bad['namePolicy']='automatic';$reject(static fn()=>$verify($build($bad,$values)),'authenticated unknown name policy refused');
 $bad=$plan;$bad['namePolicy']='portable';$reject(static fn()=>$verify($build($bad,$values)),'authenticated misleading portable name policy refused');
 $bad=$plan;$bad['files'][0]['path']='../escape';$reject(static fn()=>$verify($build($bad,['../escape'=>'upper']+$values)),'authenticated traversal under POSIX policy refused');
 $bad=$plan;$bad['files'][0]['path']='Case';$bad['exclusions'][]=['path'=>'empty\\directory','reason'=>'excluded'];$reject(static fn()=>Inventory::validate_plan($bad),'directory exclusion overlap refused');
 $bad=$plan;$bad['bytes']++;$reject(static fn()=>Inventory::validate_plan($bad),'name preflight also refuses inconsistent byte inventory');
 $bad=$plan;$bad['extra']=true;$reject(static fn()=>Inventory::assert_destination_paths($bad,'posix'),'destination preflight refuses unreviewed extra inventory fields');
 // Mutating the authenticated index cannot reclassify a complete archive, even with the phrase.
 $headerSize=unpack('Nsize',substr($archive,8,4))['size'];$start=12+$headerSize;$length=unpack('Nsize',substr($archive,$start+1,4))['size'];$index=json_decode($cipher->open(substr($archive,$start+5,$length),0,'index'),true);$index['namePolicy']='portable';$frame=$cipher->seal(json_encode($index),0,'index');$bad=substr($archive,0,$start).'I'.pack('N',strlen($frame)).$frame.substr($archive,$start+5+$length);
 $reject(static fn()=>$verify($bad),'reclassified index cannot match the original completeness transcript');
 if(PHP_OS_FAMILY==='Windows'){
  $proof['skips'][]='Actual literal POSIX filenames require the native Linux fixture; metadata review and destination refusal ran here';
  $reject(static fn()=>Inventory::scan(sys_get_temp_dir(),50000,Inventory::MAX_BYTES,null,'posix'),'Windows capture refuses POSIX source interpretation');
 }else{
  if(!mkdir($root,0700))throw new RuntimeException('Fixture root failed');$dirs[]=$root;
  foreach($plan['directories']as$path){mkdir($root.'/'.$path,0700);$dirs[]=$root.'/'.$path;}
  foreach($values as$path=>$value){if(file_put_contents($root.'/'.$path,$value)!==strlen($value))throw new RuntimeException('POSIX fixture write failed');$owned[]=$root.'/'.$path;}
  $actual=Inventory::scan($root);$check($actual===$plan,'actual native POSIX inventory preserves every filename exactly');
  $sink=$temp();$receipt=Container::capture($root,$actual,$sink,$cipher);rewind($sink);$accepted=Container::verify($sink,$phrase);$check($accepted['inventory']===$actual,'native POSIX capture and complete verification preserve the source tree');
  $check($receipt['frames']===$accepted['frames'],'native POSIX file capture receipt matches verified completeness');
  foreach($actual['files']as$i=>$entry)$check(hash_file('sha256',Inventory::unchanged($root,$entry,null,'posix'))===$entry['sha256'],'native filename '.$i.' resolves to its exact original bytes');
  $reject(static fn()=>Inventory::scan($root,50000,Inventory::MAX_BYTES,null,'portable'),'portable source request refuses POSIX-only names instead of renaming');
  Inventory::unchanged_tree($root,$actual);$check(true,'whole native tree recheck uses its authenticated POSIX policy');
 }
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
finally{
 foreach($streams as$s)if(is_resource($s))fclose($s);
 foreach(array_reverse($owned)as$path)if(is_file($path))unlink($path);
 foreach(array_reverse($dirs)as$path)if(is_dir($path))rmdir($path);
 $proof['cleanup']=!file_exists($root);$proof['passed']=$proof['passed']&&$proof['cleanup'];
}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($proof['passed']?0:1);
