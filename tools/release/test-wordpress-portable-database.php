<?php
/** Native callable fixture: a disposable exact prefix, never the installed site's tables. */
if ( ! defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) return null;
return static function( \wpdb $wpdb, ?callable $member_review = null ): array {
 $proof=[ 'schema'=>'kiwe.portable-database-native-proof.v1','passed'=>false,'cleanup'=>false,'checks'=>[] ];
 $check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new \RuntimeException($label);};
 $deny=static function(callable $fn,string $label)use($check){try{$fn();}catch(\DSA\Backup\Archive_Error $e){$check(true,$label);return;}throw new \RuntimeException('Expected bounded refusal: '.$label);};
 $old_prefix=$wpdb->prefix;$old_base=$wpdb->base_prefix;$previous=$wpdb->suppress_errors(true);
 $prefix='kiweportable_'.bin2hex(random_bytes(6)).'_';$names=[];$made=[];$streams=[];$fixture_active=false;
 $core=['comments','commentmeta','links','options','postmeta','posts','term_relationships','term_taxonomy','termmeta','terms','usermeta','users'];
 $query=static function(string $sql)use($wpdb){$wpdb->last_error='';$result=$wpdb->query($sql);if(false===$result||''!==$wpdb->last_error)throw new \RuntimeException('Owned native fixture SQL failed');return $result;};
 $newstream=static function()use(&$streams){$s=fopen('php://memory','w+b');if(!$s)throw new \RuntimeException('Owned stream unavailable');$streams[]=$s;return $s;};
 $phrase='Synthetic portable database native phrase';
 try {
  $version=(string)$wpdb->get_var('SELECT VERSION()');
  $proof['vendor']=str_contains($version,'MariaDB')?'MariaDB':'other';
  if($proof['vendor']!=='MariaDB')throw new \RuntimeException('This owned fixture currently requires MariaDB transaction inspection; MySQL native acceptance remains pending');
  $before=$wpdb->get_row('SELECT @@SESSION.in_transaction, @@SESSION.tx_isolation, @@SESSION.tx_read_only, @@SESSION.autocommit',ARRAY_N);
  $check(is_array($before)&&count($before)===4&&(string)$before[0]==='0'&&(string)$before[3]==='1','native owner connection starts idle with autocommit');
  foreach($core as$suffix){
   $name=$prefix.$suffix;$names[]=$name;
   $check(preg_match('/^kiweportable_[a-f0-9]{12}_[a-z_]+$/D',$name)===1,'exact owned table guard '.$suffix);
   if($wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$name))!==null)throw new \RuntimeException('Owned fixture name unexpectedly exists');
   $query('CREATE TABLE '.chr(96).$name.chr(96).' (id bigint unsigned NOT NULL, payload longblob DEFAULT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');$made[]=$name;
  }
  $table=chr(96).$prefix.'posts'.chr(96);
  $query("INSERT INTO ".$table." (id,payload) VALUES (1,NULL),(2,''),(3,UNHEX('73796E7468657469630062696E617279FF'))");
  $source_hash=hash('sha256',serialize($wpdb->get_results('SELECT id,HEX(payload),payload IS NULL FROM '.$table.' ORDER BY id',ARRAY_N)));
  $wpdb->prefix=$prefix;$wpdb->base_prefix=$prefix;
  $cipher=new \DSA\Backup\Portable_Archive_Cipher(\DSA\Backup\Portable_Archive_Cipher::new_header(),$phrase);$s=$newstream();
  $receipt=\DSA\Backup\Portable_Database_Capture::capture($wpdb,$s,$cipher);
  rewind($s);$verified=\DSA\Backup\Portable_Database_Capture::verify($s,$phrase);
  $check($receipt['transactionEnded']&&$receipt['tables']===12&&$receipt['rows']===3&&$verified['rows']===3&&count($verified['tables'])===12,'actual native single snapshot encrypted capture verifies all 12 owned tables');
  $expected=[];$cells=[[['b',base64_encode('1')],['n']],[['b',base64_encode('2')],['b','']],[['b',base64_encode('3')],['b',base64_encode("synthetic\x00binary\xff")]]];$h=hash_init('sha256');
  foreach($cells as$i=>$row){$plain='R'.json_encode(['position'=>$i,'cells'=>$row],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);hash_update($h,pack('N',strlen($plain)).$plain);}$expected_sha=hash_final($h);
  $totals=array_values(array_filter($verified['totals'],static fn($v)=>$v['name']===$prefix.'posts'));
  $check(count($totals)===1&&hash_equals($expected_sha,$totals[0]['sha256']),'actual native NULL empty and binary bytes have expected authenticated row transcript');
  $after=$wpdb->get_row('SELECT @@SESSION.in_transaction, @@SESSION.tx_isolation, @@SESSION.tx_read_only, @@SESSION.autocommit',ARRAY_N);
  $check($after===$before,'successful capture ends native transaction and restores exact caller session defaults');
  $check(hash('sha256',serialize($wpdb->get_results('SELECT id,HEX(payload),payload IS NULL FROM '.$table.' ORDER BY id',ARRAY_N)))===$source_hash,'native source rows preserved after capture');
  if($member_review){
   rewind($s);$proof['recoverySet']=$member_review(['stream'=>$s,'phrase'=>$phrase,'rows'=>$verified['rows']]);
   $check(is_array($proof['recoverySet'])&&($proof['recoverySet']['passed']??false)===true&&($proof['recoverySet']['cleanup']??false)===true,'actual native database member participates in accepted recovery-set boundary checks');
   $check($wpdb->get_row('SELECT @@SESSION.in_transaction, @@SESSION.tx_isolation, @@SESSION.tx_read_only, @@SESSION.autocommit',ARRAY_N)===$before,'recovery-set review preserves native caller session defaults');
   $check(hash('sha256',serialize($wpdb->get_results('SELECT id,HEX(payload),payload IS NULL FROM '.$table.' ORDER BY id',ARRAY_N)))===$source_hash,'recovery-set review preserves every native owned source row');
  }
  rewind($s);$deny(static fn()=>\DSA\Backup\Portable_Database_Capture::verify($s,'Wrong native synthetic backup phrase'),'actual native encrypted member refuses wrong phrase');
  rewind($s);$raw=stream_get_contents($s);$damaged=$newstream();fwrite($damaged,substr($raw,0,-1));rewind($damaged);$deny(static fn()=>\DSA\Backup\Portable_Database_Capture::verify($damaged,$phrase),'actual native truncated member cannot verify as complete');
  $query('START TRANSACTION');$fixture_active=true;
  $deny(static fn()=>\DSA\Backup\Portable_Database_Capture::capture($wpdb,$newstream(),$cipher),'actual already active caller transaction refused');
  $check((string)$wpdb->get_var('SELECT @@SESSION.in_transaction')==='1','refusal leaves caller transaction active without implicit commit or rollback');
  $query('ROLLBACK');$fixture_active=false;
  $query('ALTER TABLE '.chr(96).$prefix.'links'.chr(96).' ENGINE=MyISAM');
  $deny(static fn()=>\DSA\Backup\Portable_Database_Capture::capture($wpdb,$newstream(),$cipher),'actual non transactional native table refused');
  $check($wpdb->get_row('SELECT @@SESSION.in_transaction, @@SESSION.tx_isolation, @@SESSION.tx_read_only, @@SESSION.autocommit',ARRAY_N)===$before,'engine refusal rolls back owned view and restores exact native defaults');
  $query('ALTER TABLE '.chr(96).$prefix.'links'.chr(96).' ENGINE=InnoDB');
  $calls=0;$cancelled=$newstream();
  $deny(static function()use($wpdb,$cancelled,$cipher,&$calls){\DSA\Backup\Portable_Database_Capture::capture($wpdb,$cancelled,$cipher,static function()use(&$calls){if(++$calls===20)throw new \DSA\Backup\Archive_Error('Synthetic native cancellation');});},'actual capture cancellation refused');
  $check($wpdb->get_row('SELECT @@SESSION.in_transaction, @@SESSION.tx_isolation, @@SESSION.tx_read_only, @@SESSION.autocommit',ARRAY_N)===$before,'cancellation restores exact native transaction defaults');
  rewind($cancelled);$deny(static fn()=>\DSA\Backup\Portable_Database_Capture::verify($cancelled,$phrase),'cancelled native output has no accepted completion footer');
  $proof['passed']=true;
 }catch(\Throwable$e){$proof['error']=$e instanceof \DSA\Backup\Archive_Error?$e->getMessage():'Native owned database fixture did not complete: '.$e->getMessage();}
 finally{
  if($fixture_active){try{$query('ROLLBACK');}catch(\Throwable$e){$proof['cleanupError']='Owned fixture transaction retirement failed';}}
  $wpdb->prefix=$old_prefix;$wpdb->base_prefix=$old_base;
  foreach($streams as$s)if(is_resource($s))fclose($s);
  $retired=true;
  foreach(array_reverse($made)as$name){
   try{if(!preg_match('/^kiweportable_[a-f0-9]{12}_[a-z_]+$/D',$name))throw new \RuntimeException('Owned guard failed');$query('DROP TABLE '.chr(96).$name.chr(96));if($wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$name))!==null)$retired=false;}catch(\Throwable$e){$retired=false;}
  }
  $check_prefix=$wpdb->prefix===$old_prefix&&$wpdb->base_prefix===$old_base;
  $proof['checks']['all exact owned tables retired']=$retired;
  $proof['checks']['native original wpdb prefixes restored']=$check_prefix;
  $proof['checks']['fixture has no active native transaction']=(string)$wpdb->get_var('SELECT @@SESSION.in_transaction')==='0';
  $proof['cleanup']=$retired&&$check_prefix&&$proof['checks']['fixture has no active native transaction']&&!isset($proof['cleanupError']);
  $wpdb->suppress_errors($previous);$proof['passed']=$proof['passed']&&$proof['cleanup'];
 }
 return $proof;
};
