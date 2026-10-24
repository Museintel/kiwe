<?php
/** Native SQL acceptance; run only through the private, CLI-only disposable-lab wrapper. */
if (PHP_SAPI !== 'cli' || !defined('KIWE_SCHEMA_TEST_SITE') || KIWE_SCHEMA_TEST_SITE !== true || !defined('ABSPATH')) exit(2);
use DSA\Secure\SecureTrack_Db_Service as Schema;
$proof=['schema'=>'kiwe-native-securetrack-schema/v1','kiwe'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'database'=>$wpdb->db_version(),'checks'=>[],'cleanup'=>false];
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$originalPrefix=$wpdb->prefix;$originalOptions=$wpdb->options;$originalCache=$GLOBALS['wp_object_cache'];
$prefix='kiwe69qa_'.bin2hex(random_bytes(6)).'_';$options=$prefix.'options';$createdOptions=false;$second=null;$capture=null;$ddl=[];
$tables=array_map(static fn($n)=>$prefix.'stp_'.$n,Schema::known_tables());
$reset=static function(){delete_option('stp_schema_maintenance');delete_transient('stp_tables_ready_v'.STP_VER);delete_transient('stp_tables_unavailable_v'.STP_VER);};
try {
 $assert(DSA_VERSION===KIWE_SCHEMA_EXPECTED_VERSION,'expected staged package loaded');
 $inventory=DSA\Runtime\Package_Manifest::verify(true);$assert(!empty($inventory['valid']) && !empty($inventory['complete']),'staged package integrity');
 $assert(!wp_using_ext_object_cache() && str_ends_with(str_replace('\\','/',(new ReflectionClass('WP_Object_Cache'))->getFileName()),'/wp-includes/class-wp-object-cache.php'),'isolated core request-local cache');
 $assert($wpdb->query("CREATE TEMPORARY TABLE {$options} LIKE {$originalOptions}")!==false,'temporary native options table created');$createdOptions=true;
 $wpdb->prefix=$prefix;$wpdb->options=$options;$GLOBALS['wp_object_cache']=new WP_Object_Cache();
 add_option('stp_settings',['track_visitors'=>false,'adaptive_learning'=>false,'exclude_admin'=>true],'',false);
 $capture=static function($sql)use(&$ddl,$tables){if(preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\s+(?:TEMPORARY\s+)?TABLE\s+`?([A-Za-z0-9_]+)/i',$sql,$m)){if(!in_array($m[2],$tables,true))throw new RuntimeException('DDL outside owned fixture tables');$ddl[]=['operation'=>strtoupper($m[1]),'table'=>substr($m[2],strpos($m[2],'stp_'))];}return$sql;};
 add_filter('query',$capture,PHP_INT_MAX-10);
 if(!function_exists('stp_create_tables'))require_once DSA_DIR.'includes/Secure/securetrack-core.php';
 Schema::maybe_install();stp_create_tables();stp_migrate_schema();Schema::ensure_column('events','fixture','int(11)');
 $assert($ddl===[],'ordinary bootstrap and direct schema helpers execute no DDL');
 $assert(Schema::tables_ready(true)===false,'missing schema returns unavailable');
 $assert(stp_log('page_view',[])===null && $ddl===[],'logging with missing tables skips repair');
 $reset();$ddl=[];
 $success=Schema::repair_database();
 $proof['freshAttempt']=['returned'=>$success,'status'=>get_option('stp_schema_maintenance'),'ddl'=>$ddl];
 $assert($success,'explicit maintenance creates fresh schema');
 $assert(Schema::tables_ready(true) && get_option('stp_db_version')===STP_VER,'all native required columns and schema version confirmed');
 $assert(count(array_filter($ddl,static fn($q)=>$q['operation']==='CREATE'))===10,'exactly ten owned tables created');
 $assert($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$options} WHERE option_name=%s",'stp_schema_maintenance'))==='off' || $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$options} WHERE option_name=%s",'stp_schema_maintenance'))==='no','maintenance state does not autoload');
 $ddl=[];$assert(!Schema::repair_database() && !$ddl,'native cooldown prevents immediate repeated maintenance');
 $reset();$ddl=[];$assert(Schema::repair_database(),'matching native schema maintenance succeeds');
 $assert(!$ddl,'matching native schema produces zero DDL');
 $lock='kiwe-stp-'.substr(hash('sha256',DB_NAME.'|'.$prefix),0,48);
 $assert((string)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$lock))==='1','maintenance releases real connection lock');
 $second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$second->suppress_errors(true);
 $assert((string)$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$lock))==='1','independent SQL connection acquires same lock');
 $reset();$ddl=[];$assert(!Schema::repair_database() && !$ddl,'competing SQL connection denies maintenance immediately');
 $second->close();$second=null;
 $released=false;for($i=0;$i<20;++$i){if((string)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$lock))==='1'){$released=true;break;}usleep(25000);}
 $assert($released,'closing owner connection releases lock within bounded observation');
 $proof['passed']=true;
}catch(Throwable $e){$proof['passed']=false;$proof['failure']=['class'=>get_class($e),'label'=>$e->getMessage()];}
finally{
 if($capture)remove_filter('query',$capture,PHP_INT_MAX-10);
 if($second)$second->close();
 $wpdb->suppress_errors(true);$ok=true;
 foreach($tables as$table){if(!preg_match('/^kiwe69qa_[a-f0-9]{12}_stp_[a-z_]+$/D',$table)){$ok=false;continue;}$ok=($wpdb->query("DROP TABLE IF EXISTS {$table}")!==false)&&$ok;}
 if($createdOptions)$ok=($wpdb->query("DROP TEMPORARY TABLE {$options}")!==false)&&$ok;
 $left=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($prefix).'%'));
 $wpdb->prefix=$originalPrefix;$wpdb->options=$originalOptions;$GLOBALS['wp_object_cache']=$originalCache;
 $proof['cleanup']=$ok && $left===[];$proof['passed']=!empty($proof['passed'])&&$proof['cleanup'];
}
return $proof;
