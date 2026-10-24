<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Archive_Container','Portable_Database_Capture','Portable_Source_Compatibility','Portable_Recovery_Set']as$class)require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$class.'.php';
use DSA\Backup\Portable_Archive_Cipher as Cipher;
use DSA\Backup\Portable_Archive_Container as Files;
use DSA\Backup\Portable_Database_Capture as Database;
use DSA\Backup\Portable_File_Inventory as Inventory;
use DSA\Backup\Portable_Recovery_Set as Set;
$runner=static function(?array $native=null):array{
$proof=['schema'=>'kiwe.portable-recovery-set-proof.v1','scope'=>'synthetic encrypted members and native local files only','runtime'=>PHP_VERSION,'passed'=>false,'cleanup'=>false,'checks'=>[],'limitations'=>['No original database capture, live consistency boundary, job, download UI, extraction or destination restoration acceptance.']];
$check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
$streams=[];$owned=[];$dirs=[];$root=sys_get_temp_dir().'/kiwe-recovery-set-'.bin2hex(random_bytes(12));$phrase=$native['phrase']??'Synthetic complete recovery set phrase';
$temp=static function()use(&$streams){$s=fopen('php://temp/maxmemory:2097152','w+b');if(!$s)throw new RuntimeException('Fixture stream failed');$streams[]=$s;return$s;};
$bytes=static function($stream){rewind($stream);$value=stream_get_contents($stream);rewind($stream);return$value;};
$from=static function(string$value)use($temp){$s=$temp();fwrite($s,$value);rewind($s);return$s;};
$verify=static fn(string$value)=>Set::verify($from($value),$phrase);
// This independent builder is a malformed-input/transport fixture. Native SQL capture has separate acceptance.
$database=static function(Cipher$cipher,string$payload)use($temp){
 $source=['family'=>'MariaDB','version'=>'10.11.11-MariaDB','charset'=>'utf8mb4','collation'=>'utf8mb4_general_ci','sqlMode'=>'STRICT_TRANS_TABLES','timeZone'=>'+00:00'];$suffixes=['comments','commentmeta','links','options','postmeta','posts','term_relationships','term_taxonomy','termmeta','terms','usermeta','users'];sort($suffixes,SORT_STRING);
 $header=json_encode($cipher->header(),JSON_UNESCAPED_SLASHES);$archive='KIWEPDB2'.pack('N',strlen($header)).$header;$sequence=0;$transcript=hash_init('sha256');$allbytes=0;
 $emit=static function(string$plain,string$purpose)use($cipher,&$archive,&$sequence,$transcript){$frame=$cipher->seal($plain,$sequence,$purpose);$packet=($purpose==='index'?'I':($purpose==='manifest'?'M':'D')).pack('N',strlen($frame)).$frame;$archive.=$packet;if($purpose!=='manifest')hash_update($transcript,$packet);$sequence++;};
 $emit(json_encode(['schema'=>Database::SCHEMA,'prefix'=>'wp_','source'=>$source,'tables'=>12,'rows'=>1],JSON_UNESCAPED_SLASHES),'index');
 foreach($suffixes as$suffix){
  $name='wp_'.$suffix;$q=chr(96);$ddl='CREATE TABLE '.$q.$name.$q.' ('.$q.'id'.$q.' bigint unsigned NOT NULL,'.$q.'payload'.$q.' longblob DEFAULT NULL, PRIMARY KEY ('.$q.'id'.$q.')) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  $columns=[['name'=>'id','type'=>'bigint unsigned','nullable'=>false,'extra'=>'','collation'=>null],['name'=>'payload','type'=>'longblob','nullable'=>true,'extra'=>'','collation'=>null]];$primary=['id'];$hasrow=$suffix==='posts';
  $table=['name'=>$name,'create'=>$ddl,'columns'=>$columns,'primary'=>$primary,'rows'=>$hasrow?1:0,'schemaSha256'=>hash('sha256',json_encode(['create'=>$ddl,'columns'=>$columns,'primary'=>$primary],JSON_UNESCAPED_SLASHES))];$emit('T'.json_encode($table,JSON_UNESCAPED_SLASHES),'data');$hash=hash_init('sha256');$n=0;
  if($hasrow){$row='R'.json_encode(['position'=>0,'cells'=>[['b',base64_encode('1')],['b',base64_encode($payload)]]],JSON_UNESCAPED_SLASHES);$emit($row,'data');hash_update($hash,pack('N',strlen($row)).$row);$n=1+strlen($payload);$allbytes+=$n;}
  $emit('E'.json_encode(['name'=>$name,'rows'=>$hasrow?1:0,'bytes'=>$n,'sha256'=>hash_final($hash)]),'data');
 }
 $emit(json_encode(['schema'=>Database::SCHEMA,'tables'=>12,'rows'=>1,'bytes'=>$allbytes,'frames'=>$sequence,'transcript'=>hash_final($transcript)]),'manifest');$stream=$temp();fwrite($stream,$archive);rewind($stream);return$stream;
};
try{
 mkdir($root,0700);$dirs[]=$root;
 foreach(['wp-admin','wp-admin/includes','wp-includes','wp-content','wp-content/plugins','wp-content/plugins/example','wp-content/mu-plugins','wp-content/mu-plugins/kiwe','wp-content/themes','wp-content/themes/example','wp-content/uploads']as$dir){mkdir($root.'/'.$dir,0700);$dirs[]=$root.'/'.$dir;}
 $core=['index.php','wp-blog-header.php','wp-load.php','wp-settings.php','wp-config.php','wp-login.php','wp-cron.php','wp-includes/version.php','wp-includes/load.php','wp-includes/functions.php','wp-admin/admin.php','wp-admin/includes/admin.php'];
 foreach(array_merge($core,['wp-content/plugins/example/main.php','wp-content/mu-plugins/kiwe-loader.php','wp-content/mu-plugins/kiwe/entry.php','wp-content/themes/example/style.css','wp-content/uploads/asset.bin'])as$file){file_put_contents($root.'/'.$file,$file==='wp-content/uploads/asset.bin'?random_bytes(2200000):'<?php /* synthetic private source */');$owned[]=$root.'/'.$file;}
 $plan=Inventory::scan($root);$source=['schema'=>DSA\Backup\Portable_Source_Compatibility::SCHEMA,'namePolicy'=>$plan['namePolicy'],'scope'=>'ordinary-single-site-native-layout','platform'=>['php'=>PHP_VERSION,'phpMinimum'=>'8.2','wordpress'=>'7.1.2','wordpressDatabaseVersion'=>61234,'kiwe'=>'8.0.0-fixture','operatingSystem'=>PHP_OS_FAMILY],'layout'=>['core'=>$core,'content'=>'wp-content','plugins'=>'wp-content/plugins','mustUsePlugins'=>'wp-content/mu-plugins','themeRoots'=>['wp-content/themes'],'uploads'=>'wp-content/uploads'],'dependencies'=>['activePlugins'=>['wp-content/plugins/example/main.php'],'mustUseEntries'=>['wp-content/mu-plugins/kiwe-loader.php'],'themes'=>['template'=>['directory'=>'wp-content/themes/example','root'=>'wp-content/themes'],'stylesheet'=>['directory'=>'wp-content/themes/example','root'=>'wp-content/themes']],'kiwe'=>['loader'=>'wp-content/mu-plugins/kiwe-loader.php','package'=>'wp-content/mu-plugins/kiwe','entry'=>'wp-content/mu-plugins/kiwe/entry.php'],'loadedFiles'=>['wp-config.php']],'exclusions'=>$plan['exclusions'],'limits'=>['databaseCaptureVerified'=>false,'databaseFileConsistencyVerified'=>false,'externalServicesCaptured'=>false,'destinationCompatibilityVerified'=>false]];
 $nativeBytes=null;if($native!==null){$nativeBytes=$bytes($native['stream']);$n=unpack('Nsize',substr($nativeBytes,8,4))['size'];$dbCipher=new Cipher(json_decode(substr($nativeBytes,12,$n),true),$phrase);}else $dbCipher=new Cipher(Cipher::new_header(),$phrase);$fileCipher=new Cipher(Cipher::new_header(),$phrase);$db=$nativeBytes===null?$database($dbCipher,"synthetic private post\0".chr(255)):$from($nativeBytes);$file=$temp();Files::capture($root,$plan,$file,$fileCipher);rewind($file);
 Set::source($source,$plan);$check(true,'complete native source dependency contract matches its exact file inventory');
 $out=$temp();$receipt=Set::create($db,$file,$source,$out,$phrase);$archive=$bytes($out);$review=$verify($archive);
 $check($receipt['frames']===$review['frames']&&$receipt['outputBytes']===strlen($archive)&&$receipt['sha256']===hash('sha256',$archive),'complete recovery transport receipt matches the verified bytes');
 $check($review['source']===$source&&$review['files']['inventory']===$plan&&count($review['database']['tables'])===12&&$review['database']['rows']===($native['rows']??1),'source database and file members verified together before returning metadata');
 $check($review['database']['archiveId']!==$review['files']['archiveId']&&$review['archiveId']!==$review['database']['archiveId'],'three independent encryption contexts bind the recovery set');
 $check($review['databaseFileConsistencyVerified']===false&&$review['destinationCompatibilityVerified']===false&&$receipt['consistency']==='members-authenticated-only','member completeness does not claim source consistency or migration acceptance');
 $factoryCalls=[];$input=$from($archive);$temporary=static function($kind)use($temp,&$factoryCalls){$factoryCalls[]=$kind;return$temp();};$privateReview=Set::verify($input,$phrase,null,$temporary);$check($factoryCalls===['source','database','files']&&$privateReview['archiveId']===$review['archiveId'],'private owner can allocate all three bounded recovery review streams');
 $input=$from($archive);$reject(static fn()=>Set::verify($input,$phrase,null,static fn()=>$input),'review factory cannot alias the input stream');$check(is_resource($input),'rejected input alias preserves the caller input stream');
 $input=$from($archive);$nonempty=$from('already populated');$reject(static fn()=>Set::verify($input,$phrase,null,static fn()=>$nonempty),'review factory refuses a nonempty temporary stream');$check(!is_resource($nonempty),'invalid owned temporary stream retired on refusal');
 $input=$from($archive);$duplicate=$temp();$reject(static fn()=>Set::verify($input,$phrase,null,static fn()=>$duplicate),'review factory refuses reuse of one temporary member');$check(!is_resource($duplicate),'duplicate review refusal retires its previously owned temporary stream');
 foreach(['wp-config.php','synthetic private post','asset.bin','database','namePolicy',$root,$phrase]as$i=>$value)$check(!str_contains($archive,$value),'outer recovery set keeps synthetic private value '.$i.' encrypted');
 $reject(static fn()=>Set::verify($from($archive),'wrong synthetic phrase'),'wrong recovery phrase refused');
 foreach([0,7,11,strlen($archive)-1,strlen($archive)-100]as$n)$reject(static fn()=>$verify(substr($archive,0,$n)),'truncated complete set '.$n.' refused');
 $reject(static fn()=>$verify($archive.'tail'),'trailing recovery content refused');
 $bad=$archive;$bad[strlen($bad)-1]=chr(ord($bad[strlen($bad)-1])^1);$reject(static fn()=>$verify($bad),'damaged set footer refused');
 $headerSize=unpack('Nsize',substr($archive,8,4))['size'];$offset=12+$headerSize;$prefix=substr($archive,0,$offset);$packets=[];
 while($offset<strlen($archive)){$size=unpack('Nsize',substr($archive,$offset+1,4))['size'];$packets[]=substr($archive,$offset,5+$size);$offset+=5+$size;}
 $reject(static fn()=>$verify($prefix.implode('',array_slice($packets,0,-1))),'missing set completeness manifest refused');
 $bad=$packets;array_splice($bad,2,1);$reject(static fn()=>$verify($prefix.implode('',$bad)),'missing member frame refused');
 $bad=$packets;[$bad[2],$bad[3]]=[$bad[3],$bad[2]];$reject(static fn()=>$verify($prefix.implode('',$bad)),'reordered member frames refused');
 $bad=$packets;array_splice($bad,2,0,[$packets[2]]);$reject(static fn()=>$verify($prefix.implode('',$bad)),'duplicated member frame refused');
 $header=json_decode(substr($archive,12,$headerSize),true);$outerCipher=new Cipher($header,$phrase);
 $reseal=static function(int$position,string$plain,string$kind='D')use($outerCipher,$prefix,$packets){$frame=$outerCipher->seal($plain,$position,$kind==='I'?'index':($kind==='M'?'manifest':'data'));$changed=$packets;$changed[$position]=$kind.pack('N',strlen($frame)).$frame;return$prefix.implode('',$changed);};
 $reject(static fn()=>$verify($reseal(1,'P'.json_encode(['kind'=>'unknown','bytes'=>1,'sha256'=>str_repeat('0',64)]))),'authenticated unknown recovery member refused');
 $reject(static fn()=>$verify($reseal(1,'P'.json_encode(['kind'=>'database','bytes'=>1,'sha256'=>str_repeat('0',64)]))),'authenticated member order change refused');
 $reject(static fn()=>$verify($reseal(2,'Bcorrupted source layout')),'authenticated member body digest mismatch refused');
 $reject(static fn()=>$verify($reseal(1,'P'.json_encode(['kind'=>'source','bytes'=>8388609,'sha256'=>str_repeat('0',64)]))),'authenticated oversize source member refused before allocation');
 $reject(static fn()=>$verify($reseal(1,'Bbody before member')),'authenticated misplaced member body refused');
 $reject(static fn()=>$verify($reseal(0,json_encode(['schema'=>Set::SCHEMA,'memberCount'=>3,'consistency'=>'live-site-frozen']),'I')),'authenticated unproved source-freeze claim refused');
 // A holder of the phrase can re-seal an outer envelope; inner members must still pass their own review.
 $wrap=static function(array$members)use($phrase){
  $cipher=new Cipher(Cipher::new_header(),$phrase);$header=json_encode($cipher->header(),JSON_UNESCAPED_SLASHES);$output='KIWEPRS1'.pack('N',strlen($header)).$header;$sequence=0;$hash=hash_init('sha256');$descriptors=[];
  $emit=static function(string$plain,string$purpose)use($cipher,&$output,&$sequence,$hash){$frame=$cipher->seal($plain,$sequence,$purpose);$packet=($purpose==='index'?'I':($purpose==='manifest'?'M':'D')).pack('N',strlen($frame)).$frame;$output.=$packet;if($purpose!=='manifest')hash_update($hash,$packet);$sequence++;};
  $index=['schema'=>Set::SCHEMA,'memberCount'=>3,'consistency'=>'members-authenticated-only'];$emit(json_encode($index),'index');
  foreach($members as$kind=>$value){$descriptor=['kind'=>$kind,'bytes'=>strlen($value),'sha256'=>hash('sha256',$value)];$descriptors[]=$descriptor;$emit('P'.json_encode($descriptor),'data');foreach(str_split($value,Cipher::CHUNK_BYTES-1)as$chunk)$emit('B'.$chunk,'data');$emit('E'.json_encode($descriptor),'data');}
  $emit(json_encode($index+['members'=>$descriptors,'frames'=>$sequence,'transcript'=>hash_final($hash)]),'manifest');return$output;
 };
 $validMembers=['source'=>json_encode($source,JSON_UNESCAPED_SLASHES),'database'=>$bytes($db),'files'=>$bytes($file)];
 $members=$validMembers;$members['database']=substr($members['database'],0,-1);$reject(static fn()=>$verify($wrap($members)),'complete authenticated outer set cannot hide a truncated database member');
 $members=$validMembers;$members['files'][strlen($members['files'])-1]=chr(ord($members['files'][strlen($members['files'])-1])^1);$reject(static fn()=>$verify($wrap($members)),'complete authenticated outer set cannot hide a damaged file member');
 $members=$validMembers;$changed=$source;$changed['layout']['uploads']='missing';$members['source']=json_encode($changed);$reject(static fn()=>$verify($wrap($members)),'authenticated import also refuses cross-member dependency mismatch');
 $members=$validMembers;$members['source']='{}';$reject(static fn()=>$verify($wrap($members)),'authenticated empty native dependency contract refused during import');
 // Cross-member validation is enforced before any set bytes are emitted.
 foreach(['missing-dependency','unsafe-path','incomplete-core','wrong-policy','unproved-consistency','unproved-provider','extra-field','bad-version','wrong-kiwe-root','wrong-theme-root']as$case){
  $changed=$source;
  if($case==='missing-dependency')$changed['dependencies']['loadedFiles']=['missing.php'];
  if($case==='unsafe-path')$changed['layout']['uploads']='../escape';
  if($case==='incomplete-core')array_pop($changed['layout']['core']);
  if($case==='wrong-policy')$changed['namePolicy']=$source['namePolicy']==='posix'?'portable':'posix';
  if($case==='unproved-consistency')$changed['limits']['databaseFileConsistencyVerified']=true;
  if($case==='unproved-provider')$changed['limits']['externalServicesCaptured']=true;
  if($case==='extra-field')$changed['extra']='unreviewed';
  if($case==='bad-version')$changed['platform']['php']='5.6.0';
  if($case==='wrong-kiwe-root')$changed['dependencies']['kiwe']['package']='wp-content/plugins';
  if($case==='wrong-theme-root')$changed['dependencies']['themes']['template']['root']='wp-content/plugins';
  $reject(static fn()=>Set::source($changed,$plan),'cross-member '.$case.' refused');
 }
 rewind($db);rewind($file);$empty=$temp();$changed=$source;$changed['layout']['uploads']='missing';$reject(static fn()=>Set::create($db,$file,$changed,$empty,$phrase),'mismatched source set creation refused');$check(fstat($empty)['size']===0,'unreviewed native dependency failure emits no set output');
 rewind($db);rewind($file);$sameContext=$temp();Files::capture($root,$plan,$sameContext,$dbCipher);rewind($sameContext);$reject(static fn()=>Set::create($db,$sameContext,$source,$temp(),$phrase),'reused database and file encryption context refused');
 $members=$validMembers;$members['files']=$bytes($sameContext);$reject(static fn()=>$verify($wrap($members)),'authenticated import refuses reused member encryption contexts');
 $swapped=$database(new Cipher(Cipher::new_header(),$phrase),'other private source');$dbBytes=$bytes($swapped);$badDb=$from(substr($dbBytes,0,-1));rewind($file);$reject(static fn()=>Set::create($badDb,$file,$source,$temp(),$phrase),'incomplete input database cannot become a complete set');
 rewind($db);rewind($file);$nonempty=$from('retained');$reject(static fn()=>Set::create($db,$file,$source,$nonempty,$phrase),'nonempty output refused');
 rewind($db);$reject(static fn()=>Set::create($db,$db,$source,$temp(),$phrase),'aliased input members refused');
 $partial=$temp();rewind($db);rewind($file);$calls=0;$reject(static function()use($db,$file,$source,$partial,$phrase,&$calls){Set::create($db,$file,$source,$partial,$phrase,static function()use($partial,&$calls){$calls++;if(ftell($partial)>2000)throw new DSA\Backup\Archive_Error('Synthetic set capture interruption');});},'interrupted complete-set capture refused');$check(fstat($partial)['size']>0&&$calls>0,'interruption happened after private set output began');$reject(static fn()=>$verify($bytes($partial)),'interrupted recovery set cannot verify');
 $calls=0;$reject(static fn()=>Set::verify($from($archive),$phrase,static function()use(&$calls){if(++$calls===8)throw new DSA\Backup\Archive_Error('Synthetic set review interruption');}),'bounded set verification interruption refused');
 rewind($db);rewind($file);$mutated=false;$changedOutput=$temp();$originalDb=$bytes($db);
 try{
  $reject(static function()use($db,$file,$source,$changedOutput,$phrase,&$mutated){Set::create($db,$file,$source,$changedOutput,$phrase,static function()use($changedOutput,$db,&$mutated){if(!$mutated&&ftell($changedOutput)>1000){fseek($db,-1,SEEK_END);$last=fread($db,1);fseek($db,-1,SEEK_END);fwrite($db,chr(ord($last)^1));rewind($db);$mutated=true;}});},'database member changed after complete review refused');
  $check($mutated,'member mutation occurs after outer capture output begins');$reject(static fn()=>$verify($bytes($changedOutput)),'changed-member output cannot verify as complete');
 }finally{ftruncate($db,0);rewind($db);fwrite($db,$originalDb);rewind($db);}
 $proof['nativeDatabaseMember']=$native!==null;$proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
finally{
 foreach($streams as$s)if(is_resource($s))fclose($s);
 foreach(array_reverse($owned)as$p)if(is_file($p))unlink($p);
 foreach(array_reverse($dirs)as$p)if(is_dir($p))rmdir($p);
 $proof['cleanup']=!file_exists($root);$proof['passed']=$proof['passed']&&$proof['cleanup'];
}
return $proof;
};
if(defined('KIWE_PORTABLE_QA_INCLUDE'))return $runner;
$proof=$runner();
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($proof['passed']?0:1);
