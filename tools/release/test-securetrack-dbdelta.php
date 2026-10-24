<?php
/** Optional native WordPress dbDelta parser proof. The supplied core file stays outside the repository. */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !is_file($argv[1])) exit(2);
define('ABSPATH', __DIR__ . '/');
function apply_filters($name, $value) { return $value; }
function wp_should_upgrade_global_tables() { return false; }
require $argv[1];
if (!function_exists('dbDelta')) throw new RuntimeException('Supply the actual WordPress dbDelta function.');
$source=file_get_contents($argv[2]??__DIR__.'/../../wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
preg_match_all('/dbDelta\(\s*"CREATE TABLE " \. stp_t\( \'(\w+)\' \) \. " \((.*?)\) \$cs;" \);/s',$source,$matches,PREG_SET_ORDER);
if(count($matches)!==10)throw new RuntimeException('Expected all ten real SecureTrack definitions.');
class SchemaMetadata {
 public array $fields=[], $indexes=[], $writes=[];
 public string $server='10.11.8-MariaDB';
 function tables($scope){return [];}
 function db_version(){return str_contains($this->server,'MariaDB')?'10.11.8':'8.0.36';}
 function db_server_info(){return $this->server;}
 function suppress_errors($v=true){return false;}
 function get_results($sql){if(str_starts_with($sql,'DESCRIBE'))return $this->fields;if(str_starts_with($sql,'SHOW INDEX'))return $this->indexes;throw new RuntimeException('Unexpected metadata query');}
 function query($sql){$this->writes[]=$sql;return 1;}
}
$wpdb=new SchemaMetadata();$report=[];
foreach(['10.11.8-MariaDB','8.0.36'] as $server){
 $wpdb->server=$server;
 foreach($matches as $m){
  $wpdb->fields=[];$wpdb->indexes=[];$wpdb->writes=[];
  foreach(explode("\n",$m[2]) as $line){
   $line=trim($line," \t\r\n,");if(!$line)continue;
   if(preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY)\s+(?:(\w+)\s+)?\(([^)]+)\)$/',$line,$i)){
    foreach(explode(',',$i[3])as$seq=>$column)$wpdb->indexes[]=(object)['Key_name'=>$i[1]==='PRIMARY KEY'?'PRIMARY':$i[2],'Column_name'=>trim($column),'Sub_part'=>null,'Non_unique'=>$i[1]==='KEY'?1:0,'Index_type'=>'BTREE','Seq_in_index'=>$seq+1];continue;
   }
   if(!preg_match('/^(\w+)\s+([a-z]+(?:\(\d+\))?(?:\s+unsigned)?)\s*(.*)$/i',$line,$c))throw new RuntimeException('Unparsed field: '.$line);
   $type=strtolower(preg_replace('/\s+/',' ',$c[2]));
   if(str_contains($server,'MariaDB')){$type=preg_replace('/^bigint(?!\()/','bigint(20)',$type);$type=preg_replace('/^int(?!\()/','int(11)',$type);$type=preg_replace('/^smallint(?!\()/','smallint(6)',$type);}
   else{$type=preg_replace('/^(bigint|int|smallint)\(\d+\)/','$1',$type);}
   $default=preg_match('/DEFAULT\s+\x27([^\x27]*)\x27/i',$c[3],$d)?$d[1]:(preg_match('/DEFAULT\s+(\d+)/i',$c[3],$d)?$d[1]:null);
   $wpdb->fields[]=(object)['Field'=>$c[1],'Type'=>$type,'Default'=>$default];
  }
  dbDelta('CREATE TABLE wp_stp_'.$m[1]." (".$m[2].") DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",true);
  $report[]=['server'=>$server,'table'=>'stp_'.$m[1],'schemaWrites'=>count($wpdb->writes),'example'=>$wpdb->writes[0]??null];
 }
}
$passed=!array_sum(array_column($report,'schemaWrites'));
echo json_encode(['coreSha256'=>hash_file('sha256',$argv[1]),'sourceSha256'=>hash('sha256',$source),'passed'=>$passed,'checks'=>$report],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($passed?0:1);
