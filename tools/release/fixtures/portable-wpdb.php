<?php
use DSA\Backup\Portable_Database_Capture as Capture;
if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
/** Scripted behavior model. It does not execute SQL or prove native database semantics. */
class wpdb {
 public string $prefix='wp_',$base_prefix='wp_',$last_error='';
 public array $tables=[],$log=[],$warnings=[],$grants=['GRANT ALL PRIVILEGES ON '. "\x60".'qa'."\x60".'.* TO synthetic'];
 public bool $active=false,$readonly=false,$suppressed=false,$monitor=true,$instrumented=true,$triggers=false,$external=false,$outgoing=false;
 public string $isolation='READ-COMMITTED',$version='10.11.11-MariaDB',$comment='MariaDB Server',$autocommit='1',$connection='41',$timeZone='+00:00';
 public $hook=null;
 public function __construct(){
  foreach(['comments','commentmeta','links','options','postmeta','posts','term_relationships','term_taxonomy','termmeta','terms','usermeta','users']as$suffix){
   $name=$this->prefix.$suffix;$q=chr(96);
   $this->tables[$name]=['ddl'=>'CREATE TABLE '.$q.$name.$q.' ('.$q.'id'.$q.' bigint unsigned NOT NULL,'.$q.'payload'.$q.' longblob DEFAULT NULL, PRIMARY KEY ('.$q.'id'.$q.')) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'columns'=>[['id','bigint unsigned','NO','',null],['payload','longblob','YES','',null]],'primary'=>[['id','1',null]],'rows'=>[],'engine'=>'InnoDB','type'=>'BASE TABLE','collation'=>'utf8mb4_general_ci'];
  }
  $this->tables['wp_posts']['rows']=[['1',null],['2',''],['3',"synthetic\x00binary\xff"]];
 }
 public function suppress_errors($v=true){$old=$this->suppressed;$this->suppressed=(bool)$v;return $old;}
 public function prepare($sql,...$values){$i=0;return preg_replace_callback('/%[sd]/',static function($m)use(&$i,$values){$v=$values[$i++];return $m[0]==='%d'?(string)(int)$v:"'".str_replace("'","''",(string)$v)."'";},$sql);}
 public function query($sql){$r=$this->execute($sql);return $r===false?false:0;}
 public function get_results($sql,$output=null){return $this->execute($sql);}
 public function get_var($sql){$r=$this->execute($sql);return $r===false?null:($r[0][0]??null);}
 private static function compare($a,$b,$type){
  if(preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|year)\b/',$type)){
   $negativeA=str_starts_with($a,'-');$negativeB=str_starts_with($b,'-');if($negativeA!==$negativeB)return $negativeA?-1:1;
   $a=ltrim($a,'-');$b=ltrim($b,'-');$pa=explode('.',$a);$pb=explode('.',$b);$ai=ltrim($pa[0],'0')?:'0';$bi=ltrim($pb[0],'0')?:'0';
   $cmp=strlen($ai)<=>strlen($bi);if(!$cmp)$cmp=strcmp($ai,$bi);if(!$cmp){$n=max(strlen($pa[1]??''),strlen($pb[1]??''));$cmp=strcmp(str_pad($pa[1]??'',$n,'0'),str_pad($pb[1]??'',$n,'0'));}return $negativeA?-$cmp:$cmp;
  }
  // ASCII behavior only. Actual charset/collation ordering needs the native fixture.
  return strcmp($a,$b);
 }
 private function selected($row,$table,$where){
  if($where==='')return true;$matches=[];preg_match_all("/\x60([^\x60]+)\x60 ([>=]) (?:CAST\(|CONVERT\()?X'([a-f0-9]*)'/",$where,$matches,PREG_SET_ORDER);
  if(!$matches)throw new RuntimeException('Synthetic cursor parser refused SQL');$equal=true;$range=false;$allowed=false;
  foreach($matches as$m){$i=array_search($m[1],array_column($table['columns'],0),true);$cmp=self::compare($row[$i],hex2bin($m[3]),$table['columns'][$i][1]);if($m[2]==='>'){$range=true;$allowed=$allowed||($equal&&$cmp>0);$equal=true;}else $equal=$equal&&$cmp===0;}
  return $range?$allowed:$equal;
 }
 private function execute($sql){
  $this->log[]=$sql;if($this->hook){$r=($this->hook)($sql,$this);if($r!==null)return $r;}
  if($sql==='SELECT CONNECTION_ID()')return [[$this->connection]];
  if(str_starts_with($sql,'SELECT CONNECTION_ID(), VERSION()'))return [[$this->connection,$this->version,$this->comment,$this->autocommit,'qa','utf8mb4','utf8mb4_general_ci','STRICT_TRANS_TABLES',$this->timeZone]];
  if($sql==='SELECT @@SESSION.time_zone')return [[$this->timeZone]];
  if(preg_match("/^SET SESSION time_zone='([a-zA-Z0-9_+\\/:.-]+)'$/D",$sql,$zone)){$this->timeZone=$zone[1];return [];}
  if(str_starts_with($sql,'SELECT @@SESSION.in_transaction'))return [[$this->active?'1':'0',$this->isolation,$this->readonly?'1':'0']];
  if(preg_match('/^SELECT @@SESSION.(?:tx_isolation|transaction_isolation)/',$sql))return [[$this->isolation,$this->readonly?'1':'0']];
  if(str_starts_with($sql,'SET SESSION TRANSACTION ISOLATION LEVEL ')){$this->isolation=str_replace(' ','-',substr($sql,40));return [];}
  if(str_starts_with($sql,'SET SESSION TRANSACTION READ ')){$this->readonly=str_ends_with($sql,'ONLY');return [];}
  if(str_starts_with($sql,'START TRANSACTION')){$this->active=true;return [];}
  if($sql==='ROLLBACK'){$this->active=false;return [];}
  if($sql==='SHOW WARNINGS')return $this->warnings;
  if($sql==='SELECT DATABASE()')return [['qa']];
  if($sql==='SHOW GRANTS')return array_map(static fn($v)=>[$v],$this->grants);
  if(str_starts_with($sql,'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0)'))return [['4096']];
  if(str_contains($sql,'FROM performance_schema.setup_instruments'))return [[$this->monitor?'YES':'NO']];
  if(str_contains($sql,'FROM performance_schema.setup_consumers'))return [['events_transactions_current',$this->monitor?'YES':'NO'],['global_instrumentation','YES'],['thread_instrumentation','YES']];
  if(str_contains($sql,'FROM performance_schema.threads'))return [['7',$this->instrumented?'YES':'NO']];
  if(str_contains($sql,'FROM performance_schema.events_transactions_current'))return $this->active?[['ACTIVE',$this->readonly?'READ ONLY':'READ WRITE',str_replace('-',' ',$this->isolation),null]]:[];
  if(str_contains($sql,'FROM information_schema.TABLES')){
   $r=[];$tables=$this->tables;ksort($tables,SORT_STRING);foreach($tables as$n=>$t)if(str_starts_with($n,$this->prefix))$r[]=[$n,$t['type'],$t['engine'],$t['collation']];return $r;
  }
  if(str_contains($sql,'FROM information_schema.TRIGGERS'))return $this->triggers?[['synthetic']]:[];
  if(str_contains($sql,'FROM information_schema.KEY_COLUMN_USAGE'))return (str_contains($sql,'REFERENCED_TABLE_NAME IS NOT NULL')?$this->outgoing:$this->external)?[['other']]:[];
  if(preg_match('/^SELECT \* FROM \x60([^\x60]+)\x60 LIMIT 0$/',$sql))return [];
  if(preg_match('/^SHOW CREATE TABLE \x60([^\x60]+)\x60$/',$sql,$m))return [[$m[1],$this->tables[$m[1]]['ddl']]];
  if(str_contains($sql,'FROM information_schema.COLUMNS')&&preg_match("/TABLE_NAME = '([^']+)'/",$sql,$m))return $this->tables[$m[1]]['columns'];
  if(str_contains($sql,'FROM information_schema.STATISTICS')&&preg_match("/TABLE_NAME = '([^']+)'/",$sql,$m))return $this->tables[$m[1]]['primary'];
  if(str_contains($sql,'FROM information_schema.COLLATIONS'))return [['utf8mb4']];
  if(preg_match('/^SELECT COUNT\(\*\) FROM \x60([^\x60]+)\x60$/',$sql,$m))return [[(string)count($this->tables[$m[1]]['rows'])]];
  if(str_starts_with($sql,'EXPLAIN SELECT '))return [['key'=>'PRIMARY','type'=>str_contains($sql,' WHERE ')?'range':'index','Extra'=>'Using where']];
  if(preg_match('/^SELECT SUBSTRING\(CAST\(\x60([^\x60]+)\x60 AS BINARY\),([0-9]+),([0-9]+)\) AS _kiwe_part,OCTET_LENGTH\(CAST\(\x60[^\x60]+\x60 AS BINARY\)\) AS _kiwe_length FROM \x60([^\x60]+)\x60 FORCE INDEX \(PRIMARY\) WHERE (.+) LIMIT 1$/',$sql,$m)){
   $t=$this->tables[$m[4]];$i=array_search($m[1],array_column($t['columns'],0),true);foreach($t['rows']as$row)if($this->selected($row,$t,$m[5]))return [[substr($row[$i],(int)$m[2]-1,(int)$m[3]),(string)strlen($row[$i])]];return [];
  }
  if(preg_match('/ FROM \x60([^\x60]+)\x60 FORCE INDEX \(PRIMARY\)(?: WHERE (.+?))? ORDER BY .+ LIMIT ([1-9][0-9]*)$/',$sql,$m)){
   $t=$this->tables[$m[1]];$primary=array_map(static fn($p)=>array_search($p[0],array_column($t['columns'],0),true),$t['primary']);$rows=$t['rows'];
   usort($rows,static function($a,$b)use($t,$primary){foreach($primary as$i){$cmp=self::compare($a[$i],$b[$i],$t['columns'][$i][1]);if($cmp)return $cmp;}return 0;});$r=[];
   foreach($rows as$row){if(!$this->selected($row,$t,$m[2]??''))continue;$lengths=array_map(static fn($v)=>null===$v?null:(string)strlen($v),$row);$total=array_sum($lengths);$keys=array_map(static fn($i)=>$row[$i],$primary);$r[]=array_merge($total>Capture::INLINE_ROW_BYTES?array_fill(0,count($row),null):$row,$lengths,[(string)$total],$keys);if(count($r)===(int)$m[3])break;}return $r;
  }
  $this->last_error='Synthetic native refusal';return false;
 }
}

