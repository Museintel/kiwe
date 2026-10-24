<?php
/** Real schema coordinator/event service, synthetic WordPress and SQL boundary. */
if(PHP_SAPI!=='cli')exit(2);
define('ABSPATH',__DIR__.'/');define('STP_VER','2.0.16');define('DB_NAME','synthetic');define('HOUR_IN_SECONDS',3600);
if(($argv[1]??'')==='cli'){define('WP_CLI',true);define('KIWE_SECURE_SCHEMA_MAINTENANCE',true);}
$options=[];$transients=[];$filters=[];$admin=false;$cap=false;$ajax=false;$validNonce=true;$schemaCalls=0;$severityCalls=0;$scheduled=0;$scenario='ok';$sessionCalls=0;
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function update_option($k,$v,$autoload=null){global$options;$options[$k]=$v;return true;}
function add_option($k,$v){return update_option($k,$v);}
function get_transient($k){global$transients,$wpdb;return $transients[$wpdb->prefix.$k]??false;}
function set_transient($k,$v,$ttl){global$transients,$wpdb;$transients[$wpdb->prefix.$k]=$v;return true;}
function delete_transient($k){global$transients,$wpdb;unset($transients[$wpdb->prefix.$k]);}
function is_admin(){global$admin;return$admin;}function wp_doing_ajax(){global$ajax;return$ajax;}
function current_user_can($capability){global$cap;return$cap;}
function wp_verify_nonce($v,$action){global$validNonce;return$validNonce&&$v==='valid';}function wp_unslash($v){return$v;}
function add_filter($name,$fn,$priority){global$filters;$filters[$name][]=$fn;}
function remove_filter($name,$fn,$priority){global$filters;$filters[$name]=array_values(array_filter($filters[$name]??[],fn($item)=>$item!==$fn));}
function stp_create_tables(){global$schemaCalls,$scenario,$wpdb;$schemaCalls++;if(!DSA\Secure\SecureTrack_Db_Service::schema_write_allowed())throw new RuntimeException('Unguarded schema');
 if(DSA\Secure\SecureTrack_Db_Service::repair_database())throw new RuntimeException('Reentrant maintenance ran');
 if($scenario==='throw')throw new RuntimeException('Synthetic error');
 if($scenario==='lost-lock')$wpdb->owns=false;
 if($scenario==='missing-metadata'){$wpdb->query('DESCRIBE wp_stp_ips');$wpdb->last_error='Expected nonexistent table probe';}
 $wpdb->query('ALTER TABLE wp_stp_events ADD COLUMN synthetic int(11)');
 $wpdb->get_var("SHOW TABLES LIKE 'wp_stp_events'");
}
function stp_schedule_crons(){global$scheduled;$scheduled++;}
function stp_upgrade_alert_severity(){global$severityCalls;$severityCalls++;}
function stp_cfg(){return['exclude_admin'=>false,'track_visitors'=>true,'adaptive_learning'=>false];}
function current_time($type){return'2026-09-08 00:00:00';}function wp_json_encode($v){return json_encode($v);}
function stp_tables_ready(){return DSA\Secure\SecureTrack_Db_Service::tables_ready();}
function stp_diag($key,$value){return$value;}function get_current_user_id(){return 1;}
function stp_get_ip(){return'192.0.2.1';}function stp_upsert_ip($ip){return(object)['id'=>1,'status'=>'unknown','is_proxy'=>0,'risk_score'=>0];}
function stp_should_create_session_for_event($type){return true;}
function stp_upsert_session($ip){global$sessionCalls;$sessionCalls++;return null;}
class EventBoundaryReached extends RuntimeException{}
function stp_ip_has_nearby_threat($ip){throw new EventBoundaryReached();}
class SchemaDb {
 public string $prefix='wp_',$last_error='';public array$queries=[];public bool$available=true,$lockAvailable=true,$owns=false,$failDdl=false;public array$indexes=[];
 function esc_like($s){return addcslashes($s,'_%\\');}
 function prepare($sql,...$args){foreach($args as$a)$sql=preg_replace('/%s/',"'".str_replace("'","''",(string)$a)."'",$sql,1);return$sql;}
 function query($sql){global$filters;foreach($filters['query']??[]as$f)$sql=$f($sql);$this->last_error='';$this->queries[]=$sql;if($this->failDdl&&str_starts_with($sql,'ALTER TABLE')){$this->last_error='synthetic SQL failure';return false;}return 1;}
 function get_var($sql){$this->query($sql);if(str_contains($sql,'GET_LOCK')){$this->owns=$this->lockAvailable;return$this->owns?'1':'0';}if(str_contains($sql,'RELEASE_LOCK')){$this->owns=false;return'1';}if(str_contains($sql,'IS_USED_LOCK'))return$this->owns?'1':'0';if(!$this->available)return null;if(preg_match('/SHOW (?:TABLES|COLUMNS.*) LIKE \'([^\']+)\'/',$sql,$m))return stripslashes($m[1]);return null;}
 function get_results($sql){$this->query($sql);return$this->indexes;}
}
$wpdb=new SchemaDb();
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Db_Service.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Event_Service.php';
use DSA\Secure\SecureTrack_Db_Service as Db;
$checks=[];$assert=function($ok,$name)use(&$checks){$checks[]=['label'=>$name,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($name);};
$reset=function()use(&$options,&$transients,&$wpdb,&$schemaCalls,&$scenario,&$filters){$options=[];$transients=[];$wpdb=new SchemaDb();$schemaCalls=0;$scenario='ok';$filters=[];foreach(['table_existence'=>[],'readiness'=>[],'maintenance'=>false]as$p=>$v){$r=new ReflectionProperty(Db::class,$p);$r->setValue(null,$v);}};
try{
 if(($argv[1]??'')==='cli'){$assert(Db::repair_database()&&$schemaCalls===1,'explicit CLI flag permits one verified maintenance attempt');}
 else{
  $wpdb->available=false;for($i=0;$i<10;$i++){Db::maybe_install();Db::install();Db::repair_database();}
  $assert($schemaCalls===0&&!$wpdb->queries&&$severityCalls===0,'ordinary boot and direct repair requests never run schema or data migration');
  Db::ensure_column('events','synthetic','int(11)');Db::ensure_index('events','idx_x','INDEX idx_x (user_id)');
  $assert(!$wpdb->queries,'direct column/index helpers cannot bypass the maintenance boundary');
  $assert(DSA\Secure\SecureTrack_Event_Service::log('publish')===null&&$schemaCalls===0,'unavailable event storage skips logging without schema repair');
  $count=count($wpdb->queries);Db::tables_ready();$assert(count($wpdb->queries)===$count,'negative readiness is memoized');
  $wpdb->available=true;$assert(Db::tables_ready(true),'explicit refreshed metadata clears stale negative table results');
  $wpdb->prefix='wp_2_';$wpdb->available=false;$assert(!Db::tables_ready(),'readiness memoization respects the native table prefix');
  $reset();try{DSA\Secure\SecureTrack_Event_Service::log('publish');}catch(EventBoundaryReached$e){}
  $assert($sessionCalls===1&&$schemaCalls===0,'failed session insert is attempted once and never triggers schema repair');
  $admin=$cap=true;$_SERVER['REQUEST_METHOD']='GET';$_POST=['stp_do'=>'repairdb','_wpnonce'=>'valid'];
  $assert(!Db::repair_database(),'GET cannot run maintenance');$_SERVER['REQUEST_METHOD']='POST';$validNonce=false;
  $assert(!Db::repair_database(),'invalid nonce cannot run maintenance');$validNonce=true;$cap=false;
  $assert(!Db::repair_database(),'unauthorized actor cannot run maintenance');$cap=true;$ajax=true;
  $assert(!Db::repair_database(),'AJAX cannot become maintenance');$ajax=false;
  $wpdb->lockAvailable=false;$assert(!Db::repair_database()&&$schemaCalls===0,'another lock holder prevents concurrent schema work');
  $wpdb->lockAvailable=true;$assert(Db::repair_database()&&$schemaCalls===1&&get_option('stp_db_version')===STP_VER,'authorized maintenance verifies and records schema version');
  $assert(!$wpdb->owns&&!Db::schema_write_allowed()&&!($filters['query']??[]),'completed maintenance releases its connection lock and query guard');
  $assert(!Db::repair_database()&&$schemaCalls===1,'persistent 15-minute cooldown prevents repeated submissions');
  $reset();$wpdb->failDdl=true;$assert(!Db::repair_database()&&get_option('stp_schema_maintenance')['status']==='failed','SQL failure records a failed attempt');
  $assert(!get_option('stp_db_version')&&!$wpdb->owns&&!($filters['query']??[]),'failed SQL never advances version and releases lock');
  $assert(!Db::repair_database()&&$schemaCalls===1,'failed maintenance does not retry immediately');
  $reset();$scenario='missing-metadata';$assert(Db::repair_database(),'expected missing-table metadata probe permits initial creation');
  $reset();$scenario='throw';$assert(!Db::repair_database()&&!$wpdb->owns&&!Db::schema_write_allowed(),'exception releases ownership and closes schema entry points');
  $reset();$scenario='lost-lock';$assert(!Db::repair_database()&&!array_filter($wpdb->queries,fn($q)=>str_starts_with($q,'ALTER TABLE')),'lost connection lock denies DDL before execution');
  $reset();$wpdb->available=false;$assert(!Db::repair_database()&&!get_option('stp_db_version'),'missing native table readback prevents schema success');
  $reset();$equivalent=new ReflectionMethod(Db::class,'equivalent_index_exists');
  $wpdb->indexes=[(object)['Key_name'=>'idx_user_created','Column_name'=>'user_id','Seq_in_index'=>1,'Sub_part'=>null,'Non_unique'=>1,'Index_type'=>'BTREE'],(object)['Key_name'=>'idx_user_created','Column_name'=>'created_at','Seq_in_index'=>2,'Sub_part'=>null,'Non_unique'=>1,'Index_type'=>'BTREE']];
  $assert($equivalent->invoke(null,'events','INDEX idx_stp_user_created (user_id,created_at)'),'existing equivalent canonical index satisfies legacy migration alias');
  $assert(!$equivalent->invoke(null,'events','UNIQUE KEY different (user_id,created_at)'),'nonunique index does not satisfy a unique definition');
  $wpdb->indexes[1]->Sub_part=5;$assert(!$equivalent->invoke(null,'events','INDEX different (user_id)'),'partial composite index cannot masquerade as an exact index');
  $queries=count($wpdb->queries);$assert(!Db::equivalent_index_exists('events;DROP TABLE x','INDEX valid (user_id)')&&count($wpdb->queries)===$queries,'public index verification rejects unknown table input before SQL');
  $assert(!Db::equivalent_index_exists('events','INDEX valid (user_id);DROP TABLE x')&&count($wpdb->queries)===$queries,'public index verification rejects unsafe definition input before SQL');
 }
 foreach($checks as$c)echo'PASS '.$c['label']."\n";echo count($checks)." SecureTrack schema checks passed.\n";
}catch(Throwable$e){fwrite(STDERR,'FAIL '.$e->getMessage()."\n");exit(1);}
