<?php
/** Disposable native rows and key types only; never captures the installed site's tables. */
if(!defined('ABSPATH')||PHP_SAPI!=='cli')return null;
return static function(\wpdb $wpdb,?callable $member_review=null):array{
 $proof=['schema'=>'kiwe.portable-large-database-native-proof.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
 $check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
 $deny=static function(callable$fn,string$label)use($check){try{$fn();}catch(\DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected native refusal: '.$label);};
 $old_prefix=$wpdb->prefix;$old_base=$wpdb->base_prefix;$previous=$wpdb->suppress_errors(true);$prefix='kiwelarge_'.bin2hex(random_bytes(6)).'_';$made=[];$streams=[];$filter=null;$before=null;
 $core=['comments','commentmeta','links','options','postmeta','posts','term_relationships','term_taxonomy','termmeta','terms','usermeta','users'];
 $specs=[
  'comments'=>['ddl'=>'id bigint unsigned NOT NULL,payload longblob DEFAULT NULL,PRIMARY KEY(id)','cols'=>['id','payload'],'keys'=>['id']],
  'posts'=>['ddl'=>'id bigint unsigned NOT NULL,payload longblob DEFAULT NULL,empty_value longblob DEFAULT NULL,null_value longblob DEFAULT NULL,tail longblob NOT NULL,PRIMARY KEY(id)','cols'=>['id','payload','empty_value','null_value','tail'],'keys'=>['id']],
  'term_relationships'=>['ddl'=>'tenant bigint unsigned NOT NULL,label varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,payload longblob DEFAULT NULL,PRIMARY KEY(tenant,label)','cols'=>['tenant','label','payload'],'keys'=>['tenant','label']],
  'terms'=>['ddl'=>'id decimal(30,9) NOT NULL,payload longblob DEFAULT NULL,PRIMARY KEY(id)','cols'=>['id','payload'],'keys'=>['id']],
  'options'=>['ddl'=>'id varbinary(32) NOT NULL,payload longblob DEFAULT NULL,PRIMARY KEY(id)','cols'=>['id','payload'],'keys'=>['id']],
  'users'=>['ddl'=>'id bigint NOT NULL,payload longblob DEFAULT NULL,PRIMARY KEY(id)','cols'=>['id','payload'],'keys'=>['id']],
  'links'=>['ddl'=>'id datetime(6) NOT NULL,payload longblob DEFAULT NULL,PRIMARY KEY(id)','cols'=>['id','payload'],'keys'=>['id']],
  'postmeta'=>['ddl'=>'id char(32) COLLATE utf8mb4_bin NOT NULL,payload longblob DEFAULT NULL,PRIMARY KEY(id)','cols'=>['id','payload'],'keys'=>['id']],
 ];
 $q=static function(string$name):string{if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$name))throw new RuntimeException('Owned identifier refused');return '`'.$name.'`';};
 $query=static function(string$sql)use($wpdb){$wpdb->last_error='';$r=$wpdb->query($sql);if($r===false||$wpdb->last_error!==''){$code=$wpdb->dbh instanceof \mysqli?mysqli_errno($wpdb->dbh):0;$operation=preg_match('/^(CREATE|INSERT|DROP) TABLE? ?/',$sql,$m)?$m[1]:(str_starts_with($sql,'INSERT INTO ')?'INSERT':'owned');throw new RuntimeException('Owned native fixture '.$operation.' failed (native error '.$code.')');}return$r;};
 $stream=static function()use(&$streams){$s=fopen('php://temp/maxmemory:1048576','w+b');if(!$s)throw new RuntimeException('Owned local stream unavailable');$streams[]=$s;return$s;};
 $phrase='Synthetic large native database recovery phrase';$pages=[];$slices=0;$no_offset=true;$bounded=true;
 try{
  $check(str_contains((string)$wpdb->get_var('SELECT VERSION()'),'MariaDB'),'fixture uses actual native MariaDB');
  $before=$wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N);
  $check(is_array($before)&&count($before)===4&&(string)$before[0]==='0'&&(string)$before[3]==='1','native connection starts idle and owned fixture can establish its view');
  $check((int)$wpdb->get_var('SELECT @@max_allowed_packet')>=\DSA\Backup\Portable_Database_Capture::MAX_ROW_BYTES,'native packet allowance can construct the 16 MiB boundary row');
  foreach($core as$suffix){$name=$prefix.$suffix;$check((bool)preg_match('/^kiwelarge_[a-f0-9]{12}_[a-z_]+$/D',$name),'exact owned table guard '.$suffix);if($wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$name))!==null)throw new RuntimeException('Owned table unexpectedly exists');$specs[$suffix]=$specs[$suffix]??$specs['comments'];$query('CREATE TABLE '.$q($name).' ('.$specs[$suffix]['ddl'].') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');$made[]=$name;}
  $values=[];for($i=1;$i<=2048;++$i){$values[]='('.$i.",'small-".$i."')";if(count($values)===128){$query('INSERT INTO '.$q($prefix.'comments').' VALUES '.implode(',',$values));$values=[];}}
  for($i=0;$i<40;++$i){
   $unsigned='18446744073709551'.str_pad((string)(575+$i),3,'0',STR_PAD_LEFT);$query('INSERT INTO '.$q($prefix.'posts')." VALUES (".$unsigned.",'small','',NULL,'tail')");
   $tenant=(string)(9007199254740991+intdiv($i,8));$labels=['a','B','é','f','m','Z','Ω','🦒'];$label=$labels[$i%8];$query($wpdb->prepare('INSERT INTO '.$q($prefix.'term_relationships').' VALUES (%s,%s,%s)',$tenant,$label,'composite'));
   $decimal=($i<20?'-':'').'9007199254740992.'.str_pad((string)(100000000+$i),9,'0',STR_PAD_LEFT);$query($wpdb->prepare('INSERT INTO '.$q($prefix.'terms').' VALUES (%s,%s)',$decimal,'decimal'));
   $query('INSERT INTO '.$q($prefix.'options')." VALUES (UNHEX('".bin2hex("\x00key".chr($i)."\xff")."'),'binary')");
   $signed=($i<20?'-':'').(string)(9007199254740991+$i);$query($wpdb->prepare('INSERT INTO '.$q($prefix.'users').' VALUES (%s,%s)',$signed,'signed'));
   $date='2026-01-01 12:00:00.'.str_pad((string)$i,6,'0',STR_PAD_LEFT);$query($wpdb->prepare('INSERT INTO '.$q($prefix.'links').' VALUES (%s,%s)',$date,'datetime'));
   $query($wpdb->prepare('INSERT INTO '.$q($prefix.'postmeta').' VALUES (%s,%s)','label-'.str_pad((string)$i,2,'0',STR_PAD_LEFT),'char'));
  }
  $payload=\DSA\Backup\Portable_Database_Capture::MAX_ROW_BYTES-24;$query('INSERT INTO '.$q($prefix.'posts')." VALUES (18446744073709551615,REPEAT(UNHEX('0062696e617279ff'),".intdiv($payload,8)."),'',NULL,'tail')");
  $check((int)$wpdb->get_var('SELECT OCTET_LENGTH(payload) FROM '.$q($prefix.'posts').' WHERE id=18446744073709551615')===$payload,'server constructed the exact maximum binary row without uploading its whole value');
  $native_fingerprint=static function()use($wpdb,$q,$prefix){return hash('sha256',serialize($wpdb->get_results('SELECT id,OCTET_LENGTH(payload),SHA2(payload,256),empty_value IS NULL,null_value IS NULL,tail FROM '.$q($prefix.'posts').' ORDER BY id',ARRAY_N)));};$original=$native_fingerprint();
  $wpdb->prefix=$prefix;$wpdb->base_prefix=$prefix;
  $filter=static function($sql)use(&$pages,&$slices,&$no_offset,&$bounded,$prefix){
   if(str_starts_with($sql,'SELECT CASE WHEN ')){if(str_contains($sql,' OFFSET '))$no_offset=false;if(!str_ends_with($sql,' LIMIT 16')||!str_contains($sql,' FORCE INDEX (PRIMARY)'))$bounded=false;if(preg_match('/ FROM `('.preg_quote($prefix,'/').'[a-z_]+)` FORCE INDEX/',$sql,$m))$pages[$m[1]]=($pages[$m[1]]??0)+1;}
   if(str_starts_with($sql,'SELECT SUBSTRING(CAST(')){++$slices;if(!preg_match('/ AS BINARY\),[0-9]+,([0-9]+)\)/',$sql,$m)||(int)$m[1]>\DSA\Backup\Portable_Database_Capture::ROW_CHUNK_BYTES)$bounded=false;}
   return$sql;
  };add_filter('query',$filter,PHP_INT_MAX);
  $handlers=(int)$wpdb->get_var("SHOW SESSION STATUS LIKE 'Handler_read_next'",1);$started=microtime(true);$cipher=new \DSA\Backup\Portable_Archive_Cipher(\DSA\Backup\Portable_Archive_Cipher::new_header(),$phrase);$s=$stream();$receipt=\DSA\Backup\Portable_Database_Capture::capture($wpdb,$s,$cipher);$elapsed=microtime(true)-$started;$reads=(int)$wpdb->get_var("SHOW SESSION STATUS LIKE 'Handler_read_next'",1)-$handlers;
  remove_filter('query',$filter,PHP_INT_MAX);rewind($s);$review=\DSA\Backup\Portable_Database_Capture::verify($s,$phrase);
  $check($receipt['schema']==='kiwe.portable-database.v2'&&$review['rows']===2329&&$receipt['transactionEnded']&&count($review['tables'])===12,'one actual native read view captures every bounded page and key type');
  $check($no_offset&&$bounded&&$pages[$prefix.'comments']===129&&$slices>=64,'native traversal has bounded primary pages and bounded binary slices without offset');
  $check($reads>=2048&&$reads<2329*8+4096,'actual native index reads stay within the linear fixture allowance');$proof['measurements']=['rows'=>2329,'handlerReadNext'=>$reads,'captureSeconds'=>round($elapsed,3),'sliceQueries'=>$slices,'commentsPages'=>$pages[$prefix.'comments']];
  $check($wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N)===$before,'large capture restores every original native session default');$check($native_fingerprint()===$original,'maximum binary source row and native table values remain unchanged');
  // Independently derive each small row transcript using the native ORDER BY owner.
  foreach($specs as$suffix=>$spec){$name=$prefix.$suffix;$columns=array_map($q,$spec['cols']);$select=implode(',',array_map(static fn($col)=>'CAST('.$col.' AS BINARY)',$columns));$where=$suffix==='posts'?' WHERE id < 18446744073709551615':'';$native=$wpdb->get_results('SELECT '.$select.' FROM '.$q($name).$where.' ORDER BY '.implode(',',array_map($q,$spec['keys'])),ARRAY_N);$h=hash_init('sha256');$position=0;
   foreach($native as$row){$cells=array_map(static fn($v)=>null===$v?['n']:['b',base64_encode($v)],$row);$plain='R'.json_encode(['position'=>$position++,'cells'=>$cells],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);hash_update($h,pack('N',strlen($plain)).$plain);}
   if($suffix==='posts')continue;$total=array_values(array_filter($review['totals'],static fn($v)=>$v['name']===$name));$check(count($total)===1&&$total[0]['rows']===count($native)&&hash_equals($total[0]['sha256'],hash_final($h)),'native ordered byte transcript matches '.$suffix);
  }
  // Decrypt only one bounded frame at a time, independently checking the maximum row.
  rewind($s);$magic=fread($s,8);$n=unpack('Nsize',fread($s,4))['size'];$header=json_decode(fread($s,$n),true,20,JSON_THROW_ON_ERROR);$reader=new \DSA\Backup\Portable_Archive_Cipher($header,$phrase);$sequence=0;$rowHash=hash_init('sha256');$opening=null;$largeChunks=0;$smallPosts=0;$current='';
  while(!feof($s)){$envelope=fread($s,5);if($envelope==='')break;if(strlen($envelope)!==5)throw new RuntimeException('Native encrypted fixture envelope incomplete');$size=unpack('Nsize',substr($envelope,1))['size'];$frame='';while(strlen($frame)<$size){$part=fread($s,$size-strlen($frame));if($part==='')throw new RuntimeException('Native encrypted fixture frame incomplete');$frame.=$part;}$kind=$envelope[0];$plain=$reader->open($frame,$sequence++,$kind==='I'?'index':($kind==='M'?'manifest':'data'));if($kind!=='D')continue;
   if($plain[0]==='T')$current=json_decode(substr($plain,1),true)['name'];if($current!==$prefix.'posts')continue;
   if($plain[0]==='R')++$smallPosts;if($plain[0]==='L')$opening=json_decode(substr($plain,1),true);if($plain[0]==='C'){hash_update($rowHash,substr($plain,9));++$largeChunks;}
  }
  $expected=hash_init('sha256');hash_update($expected,'18446744073709551615');$piece=str_repeat("\x00binary\xff",32768);for($left=$payload;$left>0;){$want=min(strlen($piece),$left);hash_update($expected,substr($piece,0,$want));$left-=$want;}hash_update($expected,'tail');
  $check($magic==='KIWEPDB2'&&$opening['position']===40&&$opening['cells']===[20,$payload,0,null,4]&&$smallPosts===40&&$largeChunks===64,'native v2 large-row grammar preserves length boundaries NULL empty and unsigned maximum ID');
  $check(hash_equals(hash_final($expected),hash_final($rowHash)),'actual 16 MiB binary row authenticates exact independently reconstructed bytes');
  if($member_review){rewind($s);$proof['recoverySet']=$member_review(['stream'=>$s,'phrase'=>$phrase,'rows'=>$review['rows']]);$check(($proof['recoverySet']['passed']??false)===true&&($proof['recoverySet']['cleanup']??false)===true,'native large-row v2 member participates in independent complete recovery-set review');}
  $slices=0;add_filter('query',$filter,PHP_INT_MAX);$partial=$stream();$deny(static function()use($wpdb,$partial,$cipher,&$slices){\DSA\Backup\Portable_Database_Capture::capture($wpdb,$partial,$cipher,static function()use(&$slices){if($slices>=3)throw new \DSA\Backup\Archive_Error('Owned native slice cancellation');});},'actual cancellation after a binary chunk cannot complete');remove_filter('query',$filter,PHP_INT_MAX);$check($slices>=3,'native cancellation occurred during the bounded large row');rewind($partial);$deny(static fn()=>\DSA\Backup\Portable_Database_Capture::verify($partial,$phrase),'native cancelled large-row member cannot verify as complete');
  $check($wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N)===$before&&$native_fingerprint()===$original,'cancelled native large capture preserves session defaults and original owned values');$proof['passed']=true;
 }catch(Throwable$e){$proof['error']=$e->getMessage();}
 finally{
  if($filter)remove_filter('query',$filter,PHP_INT_MAX);$wpdb->prefix=$old_prefix;$wpdb->base_prefix=$old_base;foreach($streams as$s)if(is_resource($s))fclose($s);$retired=true;
  foreach(array_reverse($made)as$name){try{if(!preg_match('/^kiwelarge_[a-f0-9]{12}_[a-z_]+$/D',$name))throw new RuntimeException('Owned retirement guard refused');$query('DROP TABLE '.$q($name));if($wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$name))!==null)$retired=false;}catch(Throwable$e){$retired=false;}}
  $proof['checks']['all exact large-row fixture tables retired']=$retired;$proof['checks']['original native prefixes restored']=$wpdb->prefix===$old_prefix&&$wpdb->base_prefix===$old_base;$proof['checks']['fixture leaves no active native transaction']=(string)$wpdb->get_var('SELECT @@SESSION.in_transaction')==='0';
  $proof['cleanup']=$retired&&$proof['checks']['original native prefixes restored']&&$proof['checks']['fixture leaves no active native transaction'];if($before!==null)$proof['cleanup']=$proof['cleanup']&&$wpdb->get_row('SELECT @@SESSION.in_transaction,@@SESSION.tx_isolation,@@SESSION.tx_read_only,@@SESSION.autocommit',ARRAY_N)===$before;$wpdb->suppress_errors($previous);$proof['passed']=$proof['passed']&&$proof['cleanup'];
 }
 return$proof;
};
