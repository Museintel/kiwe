<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('ABSPATH',__DIR__.'/');define('ARRAY_N','ARRAY_N');
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_multisite(){return $GLOBALS['multisite']??false;}
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Cipher.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Portable_Archive_Cipher.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Portable_Database_Capture.php';
use DSA\Backup\Portable_Archive_Cipher as Cipher;
use DSA\Backup\Portable_Database_Capture as Capture;

require __DIR__.'/fixtures/portable-wpdb.php';

$proof=['schema'=>'kiwe.portable-database-synthetic-proof.v1','passed'=>false,'cleanup'=>true,'native'=>false,'checks'=>[]];
$check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
$phrase='Synthetic portable database QA phrase';
$cipher=new Cipher(Cipher::new_header(),$phrase);$resources=[];
$stream=static function($bytes='')use(&$resources){$s=fopen('php://memory','w+b');fwrite($s,$bytes);rewind($s);$resources[]=$s;return $s;};
$capture=static function(wpdb$db,?callable$budget=null)use($stream,$cipher){$s=$stream();$r=Capture::capture($db,$s,$cipher,$budget);rewind($s);return [$s,$r];};
$records=static function(string$raw)use($cipher){$len=unpack('Nn',substr($raw,8,4))['n'];$at=12+$len;$r=[];$seq=0;while($at<strlen($raw)){$kind=$raw[$at];$n=unpack('Nn',substr($raw,$at+1,4))['n'];$frame=substr($raw,$at+5,$n);$r[]=['kind'=>$kind,'plain'=>$cipher->open($frame,$seq,$kind==='I'?'index':($kind==='M'?'manifest':'data'))];$at+=5+$n;++$seq;}return $r;};
$reseal=static function(array$r,string$magic='KIWEPDB2')use($cipher){$header=json_encode($cipher->header(),JSON_UNESCAPED_SLASHES);$raw=$magic.pack('N',strlen($header)).$header;$h=hash_init('sha256');foreach($r as$i=>$rec){$plain=$rec['plain'];if($rec['kind']==='M'){$meta=json_decode($plain,true);$meta['frames']=$i;$meta['transcript']=hash_final($h);$plain=json_encode($meta,JSON_UNESCAPED_SLASHES);}$f=$cipher->seal($plain,$i,$rec['kind']==='I'?'index':($rec['kind']==='M'?'manifest':'data'));$packet=$rec['kind'].pack('N',strlen($f)).$f;$raw.=$packet;if($rec['kind']!=='M')hash_update($h,$packet);}return $raw;};
try {
 $db=new wpdb();$before=serialize($db->tables);[$s,$receipt]=$capture($db);$raw=stream_get_contents($s);rewind($s);$accepted=Capture::verify($s,$phrase);
 $check($receipt['transactionEnded']&&!$db->active&&$db->isolation==='READ-COMMITTED'&&!$db->readonly&&!$db->suppressed,'owned transaction ends and caller session defaults restored');
 $check(serialize($db->tables)===$before,'capture performs no native table value changes');
 $check($receipt['tables']===12&&$receipt['rows']===3&&$accepted['rows']===3&&count($accepted['tables'])===12,'complete single-site core table set and row totals verified');
 $check(hash('sha256',$raw)===$receipt['sha256']&&strlen($raw)===$receipt['outputBytes'],'transport receipt matches complete encrypted output');
 $check(!str_contains($raw,'synthetic')&&!str_contains($raw,'wp_posts')&&!str_contains($raw,$phrase),'encrypted stream contains no source table payload or recovery phrase');
 $check(count(array_filter($db->log,static fn($v)=>preg_match('/^(?:INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|COMMIT)\b/i',$v)))===0,'capture issues no data writes DDL or commit');
 $check(count(array_filter($db->log,static fn($v)=>str_starts_with($v,'START TRANSACTION')))===1&&count(array_filter($db->log,static fn($v)=>$v==='ROLLBACK'))===1,'exactly one snapshot transaction and rollback');
 $db=new wpdb();$phases=[];$s=$stream();Capture::capture($db,$s,new Cipher(Cipher::new_header(),$phrase),null,static function($phase)use($db,&$phases,$check){$check($db->active&&$db->readonly&&$db->isolation==='REPEATABLE-READ','checkpoint '.$phase.' runs inside the verified native read view');$phases[]=$phase;});$check($phases===['opened','closing']&&!$db->active,'ordered capture checkpoints both precede owned transaction cleanup');
 foreach(['opened','closing']as$phase){$db=new wpdb();$partial=$stream();$reject(static fn()=>Capture::capture($db,$partial,new Cipher(Cipher::new_header(),$phrase),null,static function($current)use($phase){if($current===$phase)throw new DSA\Backup\Archive_Error('Synthetic source observation refusal');}),'source checkpoint '.$phase.' can refuse completion');$check(!$db->active&&!$db->readonly&&$db->isolation==='READ-COMMITTED','checkpoint '.$phase.' refusal restores exact caller defaults');rewind($partial);$reject(static fn()=>Capture::verify($partial,$phrase),'checkpoint '.$phase.' refusal cannot authenticate a completed member');}
 $db=new wpdb();$partial=$stream();$reject(static fn()=>Capture::capture($db,$partial,new Cipher(Cipher::new_header(),$phrase),null,static function()use($db){$db->query('ROLLBACK');}),'checkpoint cannot silently replace the capture read view');$check(!$db->active&&!$db->readonly,'lost checkpoint read view still restores caller defaults');
 $db=new wpdb();$connectionGuard=Capture::connection_guard($db);$connectionGuard();$check(!$db->active&&!$db->readonly&&$db->isolation==='READ-COMMITTED'&&count(array_filter($db->log,static fn($sql)=>$sql!=='SELECT CONNECTION_ID()'))===0,'idle archive work supervises the existing connection through reads without changing session defaults');
 $db->connection='99';$reject(static fn()=>$connectionGuard(),'connection supervision refuses a replacement session outside the read view');$check(!$db->active&&!$db->readonly,'refused connection supervision never opens a transaction');
 // Original byte fixture remains bound to its original cipher for the following parser checks.
 $r=$records($raw);$cells=[];foreach($r as$rec)if(str_starts_with($rec['plain'],'R'))$cells[]=json_decode(substr($rec['plain'],1),true)['cells'][1];
 $check($cells===[['n'],['b',''],['b',base64_encode("synthetic\x00binary\xff")]],'SQL NULL empty bytes and non UTF8 binary remain distinct');
 $check($accepted['source']['sqlMode']==='STRICT_TRANS_TABLES'&&$accepted['source']['timeZone']==='+00:00','source SQL mode and canonical UTC read timezone retained inside verified encrypted metadata');
 foreach(['SYSTEM','+05:30','America/New_York']as$zone){$db=new wpdb();$db->timeZone=$zone;$before=serialize($db->tables);[$s,$a]=$capture($db);$v=Capture::verify($s,$phrase);$check($v['source']['timeZone']==='+00:00'&&$db->timeZone===$zone&&serialize($db->tables)===$before&&!$db->active,'capture normalizes UTC and restores exact caller timezone '.$zone);}
 $db=new wpdb();$db->timeZone='SYSTEM';$db->hook=static fn($sql)=>$sql==="SET SESSION time_zone='+00:00'"?[]:null;$reject(static fn()=>$capture($db),'ignored UTC session change refuses before transaction start');$check($db->timeZone==='SYSTEM'&&!$db->active&&!array_filter($db->log,static fn($sql)=>str_starts_with($sql,'START TRANSACTION')),'failed UTC normalization preserves the original idle session');
 $db=new wpdb();$db->timeZone='+05:30';$partial=$stream();$reject(static fn()=>Capture::capture($db,$partial,new Cipher(Cipher::new_header(),$phrase),null,static function($phase)use($db){if($phase==='opened')$db->timeZone='SYSTEM';}),'timezone drift inside the read view refuses completion');$check($db->timeZone==='+05:30'&&!$db->active&&!$db->readonly,'timezone drift refusal restores caller session timezone and transaction defaults');rewind($partial);$reject(static fn()=>Capture::verify($partial,$phrase),'timezone drift partial cannot authenticate as complete');
 $db=new wpdb();$db->timeZone='SYSTEM';$db->hook=static fn($sql)=>$sql==="SET SESSION time_zone='SYSTEM'"?[]:null;$reject(static fn()=>$capture($db),'failed caller timezone restoration refuses successful capture');$check(!$db->active,'failed timezone cleanup retires the owned read transaction');
 $db=new wpdb();$db->version='8.0.40';$db->comment='MySQL Community Server - GPL';[$s,$a]=$capture($db);$b=Capture::verify($s,$phrase);$check($b['source']['family']==='MySQL'&&!$db->active,'enabled MySQL native transaction-monitoring model accepted');
 $db=new wpdb();for($i=4;$i<=130;$i++)$db->tables['wp_posts']['rows'][]=[(string)$i,'part'.$i];[$s,$a]=$capture($db);$b=Capture::verify($s,$phrase);$check($a['rows']===130&&$b['rows']===130,'multiple bounded pages captured within one read view');
 $check(!array_filter($db->log,static fn($sql)=>str_contains($sql,' OFFSET ')),'capture uses no growing row offset');
 $check(count(array_filter($db->log,static fn($sql)=>str_starts_with($sql,'EXPLAIN SELECT ')&&str_contains($sql,' WHERE ')))===2,'every subsequent populated page verifies a native primary range plan');
 $db=new wpdb();$db->tables['wp_posts']['rows']=[];for($i=0;$i<130;++$i)$db->tables['wp_posts']['rows'][]=['18446744073709551'.str_pad((string)(350+$i),3,'0',STR_PAD_LEFT),'big'];[$s,$a]=$capture($db);$rr=$records(stream_get_contents($s));$ids=[];foreach($rr as$rec)if(str_starts_with($rec['plain'],'R'))$ids[]=base64_decode(json_decode(substr($rec['plain'],1),true)['cells'][0][1]);
 $check($ids===array_column($db->tables['wp_posts']['rows'],0)&&$a['rows']===130,'cursor retains integer bytes beyond PHP float precision across pages');
 $check((bool)array_filter($db->log,static fn($sql)=>str_contains($sql,'CAST(X\'')&&str_contains($sql,'AS DECIMAL(65,0)')),'integer cursor comparison uses an exact native decimal literal');
 $db=new wpdb();for($i=4;$i<=130;$i++)$db->tables['wp_posts']['rows'][]=[(string)$i,'x'];$db->hook=static fn($sql)=>str_starts_with($sql,'EXPLAIN SELECT ')&&str_contains($sql,' WHERE ')?[['key'=>'PRIMARY','type'=>'index','Extra'=>'']]:null;$reject(static fn()=>$capture($db),'non-range subsequent native plan refused');$check(!$db->active&&!$db->readonly,'range-plan refusal restores caller transaction defaults');
 $db=new wpdb();$db->tables['wp_posts']['columns'][0][1]='double';$reject(static fn()=>$capture($db),'ambiguous floating primary-key traversal refused');$check(!$db->active&&!$db->readonly,'unsupported cursor type restores native transaction defaults');
 $db=new wpdb();$db->tables['wp_posts']['columns'][0][2]='YES';$reject(static fn()=>$capture($db),'nullable primary-key metadata refused');
 // The large-row stream crosses cell boundaries while preserving NULL and empty cells.
 $db=new wpdb();$t=&$db->tables['wp_posts'];$t['columns'][]=['empty_value','longblob','YES','',null];$t['columns'][]=['null_value','longblob','YES','',null];$t['columns'][]=['tail','longblob','NO','',null];
 $t['ddl']=str_replace('PRIMARY KEY','`empty_value` longblob DEFAULT NULL,`null_value` longblob DEFAULT NULL,`tail` longblob NOT NULL, PRIMARY KEY',$t['ddl']);
 $largeBytes=str_repeat("\x00binary\xff",40000);$t['rows']=[['1',$largeBytes,'',null,'tail']];[$s,$largeReceipt]=$capture($db);$largeRaw=stream_get_contents($s);rewind($s);$largeReview=Capture::verify($s,$phrase);$largeRecords=$records($largeRaw);$opening=null;$chunks=[];$closing=null;
 foreach($largeRecords as$rec){if(str_starts_with($rec['plain'],'L'))$opening=json_decode(substr($rec['plain'],1),true);if(str_starts_with($rec['plain'],'C'))$chunks[]=substr($rec['plain'],9);if(str_starts_with($rec['plain'],'Z'))$closing=json_decode(substr($rec['plain'],1),true);}
 $check($opening['cells']===[1,strlen($largeBytes),0,null,4],'large-row length header distinguishes empty and NULL cells');
 $check(implode('',$chunks)==='1'.$largeBytes.'tail'&&$largeReview['bytes']===strlen($largeBytes)+5,'binary chunks preserve exact ordered bytes across cell boundaries');
 $check(count($chunks)===2&&strlen($chunks[0])===Capture::ROW_CHUNK_BYTES&&$closing['bytes']===$largeReview['bytes'],'large row uses bounded canonical chunks and an authenticated closing record');
 $check((bool)array_filter($db->log,static fn($sql)=>str_starts_with($sql,'SELECT SUBSTRING(CAST(')),'large values are fetched in bounded native cell slices');
 $aliases=[];foreach($db->log as$sql)if(str_starts_with($sql,'SELECT CASE WHEN ')&&str_contains($sql,' FROM `wp_posts` ')){preg_match_all('/ AS (_kiwe_[a-z]+(?:_[0-9]+)?)/',$sql,$aliases);break;}$check(count($aliases[1])===12&&count(array_unique($aliases[1]))===12,'five-column native row projection assigns a distinct short result name to every cell length total and cursor');
 $check(!array_filter($db->log,static fn($sql)=>str_contains($sql,' OFFSET '))&&!$db->active&&$largeReceipt['transactionEnded'],'large-row path retains keyset traversal and transaction cleanup');
 $db=new wpdb();$db->tables['wp_posts']['rows']=[['1',str_repeat('x',Capture::MAX_ROW_BYTES-1)]];[$s,$a]=$capture($db);$b=Capture::verify($s,$phrase);$check($a['bytes']===Capture::MAX_ROW_BYTES&&$b['bytes']===Capture::MAX_ROW_BYTES,'exact 16 MiB row boundary captures and authenticates completely');unset($db);
 foreach(['missing','length','connection','lostview','cancel']as$case){
  $db=new wpdb();$db->tables['wp_posts']['rows']=[['1',$largeBytes]];$seen=0;$db->hook=static function($sql,$db)use($case,&$seen){if(!str_starts_with($sql,'SELECT SUBSTRING('))return null;++$seen;if($case==='missing')return [];if($case==='length')return [['x','1']];if($case==='connection'){$db->connection='99';$db->hook=null;}if($case==='lostview'){$db->active=false;$db->hook=null;}return null;};$partial=$stream();
  $reject(static function()use($db,$partial,$cipher,$case,&$seen){return Capture::capture($db,$partial,$cipher,static function()use($case,&$seen){if($case==='cancel'&&$seen>0)throw new DSA\Backup\Archive_Error('Synthetic slice cancellation');});},'large-row capture '.$case.' refuses completion');$check(!$db->active&&!$db->suppressed,'large-row '.$case.' retires its native transaction');rewind($partial);$reject(static fn()=>Capture::verify($partial,$phrase),'large-row '.$case.' partial cannot authenticate as complete');
 }
 foreach(['position','small','nullkey','stringlength','offset','size','truncated','extra','swap','missingclose','hash','duplicateclose']as$case){
  $bad=$largeRecords;$chunkIndex=[];$endIndex=null;
  foreach($bad as$i=>$rec){$type=$rec['plain'][0];if($type==='L'&&in_array($case,['position','small','nullkey','stringlength'],true)){$x=json_decode(substr($rec['plain'],1),true);if($case==='position')$x['position']=1;if($case==='small')$x['cells']=[1,1,0,null,4];if($case==='nullkey')$x['cells'][0]=null;if($case==='stringlength')$x['cells'][1]=(string)$x['cells'][1];$bad[$i]['plain']='L'.json_encode($x);}
   if($type==='C')$chunkIndex[]=$i;if($type==='Z')$endIndex=$i;
  }
  $i=$chunkIndex[0];if($case==='offset')$bad[$i]['plain']='C'.pack('N',1).substr($bad[$i]['plain'],5);if($case==='size')$bad[$i]['plain']=substr($bad[$i]['plain'],0,5).pack('N',1).substr($bad[$i]['plain'],9);if($case==='truncated')$bad[$i]['plain']=substr($bad[$i]['plain'],0,-1);if($case==='extra')$bad[$i]['plain'].='x';if($case==='swap')[$bad[$i],$bad[$chunkIndex[1]]]=[$bad[$chunkIndex[1]],$bad[$i]];if($case==='missingclose')array_splice($bad,$endIndex,1);if($case==='hash'){$x=json_decode(substr($bad[$endIndex]['plain'],1),true);$x['sha256']=str_repeat('0',64);$bad[$endIndex]['plain']='Z'.json_encode($x);}if($case==='duplicateclose')array_splice($bad,$endIndex+1,0,[$bad[$endIndex]]);
  $reject(static fn()=>Capture::verify($stream($reseal($bad)),$phrase),'authenticated large-row grammar '.$case.' refused');
 }
 $legacy=$r;foreach($legacy as$i=>$rec)if(in_array($rec['kind'],['I','M'],true)){$x=json_decode($rec['plain'],true);$x['schema']='kiwe.portable-database.v1';$legacy[$i]['plain']=json_encode($x);}$v1=Capture::verify($stream($reseal($legacy,'KIWEPDB1')),$phrase);$check($v1['schema']==='kiwe.portable-database.v1'&&$v1['rows']===3,'legacy v1 member remains readable with its original row grammar');
 $bad=$largeRecords;foreach($bad as$i=>$rec)if(in_array($rec['kind'],['I','M'],true)){$x=json_decode($rec['plain'],true);$x['schema']='kiwe.portable-database.v1';$bad[$i]['plain']=json_encode($x);}$reject(static fn()=>Capture::verify($stream($reseal($bad,'KIWEPDB1')),$phrase),'legacy format cannot smuggle the new large-row grammar');
 $reject(static fn()=>Capture::verify($stream($reseal($r,'KIWEPDB1')),$phrase),'format magic and schema must agree');
 foreach(['active','autocommit','multisite','baseprefix','prefix','version','monitor','instrumented']as$case){
  $db=new wpdb();if($case==='active')$db->active=true;if($case==='autocommit')$db->autocommit='0';if($case==='multisite')$GLOBALS['multisite']=true;if($case==='baseprefix')$db->base_prefix='other_';if($case==='prefix')$db->prefix="../bad";if($case==='version')$db->version='5.7.44';if(in_array($case,['monitor','instrumented'],true)){$db->version='8.0.40';$db->comment='MySQL Community';$db->$case=false;}
  $reject(static fn()=>$capture($db),'unsupported or caller-owned boundary '.$case.' refused');$GLOBALS['multisite']=false;
  $check(!array_filter($db->log,static fn($sql)=>preg_match('/^(?:SET|START|ROLLBACK)/',$sql)),'boundary '.$case.' never changes caller transaction defaults or ownership');
 }
 foreach(['missingcore','view','engine','nopk','prefixkey','trigger','visibility','foreign','generated','externalDDL','versioned','unsafeName','longDDL','columnlimit','rowlimit','rowbytes']as$case){
  $db=new wpdb();$t=&$db->tables['wp_posts'];
  if($case==='missingcore')unset($db->tables['wp_users']);if($case==='view')$t['type']='VIEW';if($case==='engine')$t['engine']='MyISAM';if($case==='nopk')$t['primary']=[];if($case==='prefixkey')$t['primary'][0][2]='1';if($case==='trigger')$db->triggers=true;if($case==='visibility')$db->grants=['GRANT SELECT ON '.chr(96).'qa'.chr(96).'.* TO synthetic'];if($case==='foreign')$db->external=true;
  if($case==='generated')$t['columns'][1][3]='STORED GENERATED';if($case==='externalDDL')$t['ddl'].=' DATA DIRECTORY='.chr(39).'/external'.chr(39);if($case==='versioned')$t['ddl'].=' WITH SYSTEM VERSIONING';if($case==='unsafeName')$t['columns'][1][0]='unsafe-name';if($case==='longDDL')$t['ddl'].=str_repeat('x',65536);if($case==='columnlimit')$t['columns']=array_fill(0,65,$t['columns'][0]);if($case==='rowbytes')$t['rows']=[['1',str_repeat('x',Capture::MAX_ROW_BYTES)]];if($case==='rowlimit')$db->hook=static fn($sql)=>$sql==='SELECT COUNT(*) FROM '.chr(96).'wp_posts'.chr(96)?[[(string)(Capture::MAX_TABLE_ROWS+1)]]:null;
  $reject(static fn()=>$capture($db),'unsupported database content '.$case.' refused');$check(!$db->active&&$db->isolation==='READ-COMMITTED'&&!$db->readonly&&!$db->suppressed,'refusal '.$case.' rolls back and restores caller session');
 }
 $db=new wpdb();$db->outgoing=true;$reject(static fn()=>$capture($db),'outgoing external foreign key refused through native metadata');$check(!$db->active&&!$db->readonly,'outgoing foreign-key refusal retires owned transaction');
 $db=new wpdb();$db->hook=static function($sql,$db){if(str_contains($sql,'CASE WHEN')){$db->last_error='Private SQL details must not leak';return false;}return null;};$reject(static fn()=>$capture($db),'native row query failure refused');$check(!$db->active&&!$db->readonly,'query failure ends owned read transaction');
 $db=new wpdb();$calls=0;$partial=$stream();$reject(static function()use($db,$partial,$cipher,&$calls){Capture::capture($db,$partial,$cipher,static function()use(&$calls){if(++$calls===20)throw new DSA\Backup\Archive_Error('Synthetic cancellation');});},'capture cancellation refused');$check(!$db->active&&$db->isolation==='READ-COMMITTED'&&!$db->readonly,'cancelled capture restores transaction session defaults');rewind($partial);$reject(static fn()=>Capture::verify($partial,$phrase),'cancelled output cannot verify as complete');
 $db=new wpdb();$db->hook=static function($sql,$db){if(str_contains($sql,'CASE WHEN')){$db->connection='42';$db->hook=null;}return null;};$reject(static fn()=>$capture($db),'reconnected source refused');$check(!$db->active&&!$db->suppressed,'reconnected source still rolls back and restores error visibility');
 foreach([0,11,strlen($raw)-1,intdiv(strlen($raw),2)]as$n)$reject(static fn()=>Capture::verify($stream(substr($raw,0,$n)),$phrase),'truncated transport '.$n.' refused');
 $reject(static fn()=>Capture::verify($stream($raw.'extra'),$phrase),'trailing bytes refused');$reject(static fn()=>Capture::verify($stream($raw),'Wrong synthetic database phrase'),'wrong database passphrase refused');
 $bad=$raw;$bad[strlen($bad)-3]=chr(ord($bad[strlen($bad)-3])^1);$reject(static fn()=>Capture::verify($stream($bad),$phrase),'tampered final authenticated frame refused');
 $bad=$r;[$bad[1],$bad[2]]=[$bad[2],$bad[1]];$reject(static fn()=>Capture::verify($stream($reseal($bad)),$phrase),'authenticated misplaced table end refused');
 foreach(['rowposition','celltype','badbase64','nullprimary','schemasha','tabletotal','footerrows','footertables','prefixpath']as$case){
  $bad=$r;foreach($bad as$i=>$rec){
   if(str_starts_with($rec['plain'],'R')&&in_array($case,['rowposition','celltype','badbase64','nullprimary'],true)){$x=json_decode(substr($rec['plain'],1),true);if($case==='rowposition')$x['position']=999;if($case==='celltype')$x['cells'][1]=['unknown'];if($case==='badbase64')$x['cells'][1]=['b','%%%'];if($case==='nullprimary')$x['cells'][0]=['n'];$bad[$i]['plain']='R'.json_encode($x);break;}
   if(str_starts_with($rec['plain'],'T')&&$case==='schemasha'){$x=json_decode(substr($rec['plain'],1),true);$x['schemaSha256']=str_repeat('0',64);$bad[$i]['plain']='T'.json_encode($x);break;}
   if(str_starts_with($rec['plain'],'E')&&$case==='tabletotal'){$x=json_decode(substr($rec['plain'],1),true);$x['rows']=999;$bad[$i]['plain']='E'.json_encode($x);break;}
   if($rec['kind']==='M'&&in_array($case,['footerrows','footertables'],true)){$x=json_decode($rec['plain'],true);$x[$case==='footerrows'?'rows':'tables']=999;$bad[$i]['plain']=json_encode($x);break;}
   if($rec['kind']==='I'&&$case==='prefixpath'){$x=json_decode($rec['plain'],true);$x['prefix']='../';$bad[$i]['plain']=json_encode($x);break;}
  }$reject(static fn()=>Capture::verify($stream($reseal($bad)),$phrase),'authenticated invalid metadata '.$case.' refused');
 }
 $s=$stream('already exists');$reject(static fn()=>Capture::capture(new wpdb(),$s,$cipher),'nonempty sink refused before native inspection');
 $s=$stream($raw);$check(fread($s,1)==='K','fixture stream position moved');$reject(static fn()=>Capture::verify($s,$phrase),'noninitial input offset refused');
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
finally{foreach($resources as$s)if(is_resource($s))fclose($s);$GLOBALS['multisite']=false;}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($proof['passed']?0:1);
