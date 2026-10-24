<?php
/** Native SQL acceptance; every fixture table is connection-local and disposable. */
if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('ABSPATH'))return ['passed'=>false,'cleanup'=>false];
$checks=[];$created=[];$override=null;$race=null;$counts=[];$phase='options';
$record=static function($name,$ok)use(&$checks){$checks[]=['name'=>$name,'passed'=>(bool)$ok];};
$originalPrefix=$wpdb->prefix;$originalOptions=$wpdb->options;$originalCache=$GLOBALS['wp_object_cache'];
$original=[];foreach(['stp_settings','dsa_settings','pk_settings_v3']as$key)$original[$key]=hash('sha256',serialize(get_option($key,[])));
$prefix='kiwe82qa_'.bin2hex(random_bytes(6)).'_';$options=$prefix.'options';
$sql=static function($statement)use($wpdb){$result=$wpdb->query($statement);if($result===false)throw new RuntimeException('Synthetic SQL failed');return $result;};
$count=static function($table,$where='1=1')use($wpdb){$result=$wpdb->get_var('SELECT COUNT(*) FROM '.stp_t($table).' WHERE '.$where);if($wpdb->last_error)throw new RuntimeException('Synthetic read failed');return (int)$result;};
$seed=static function($table,$columns,$rows)use($sql){foreach(array_chunk($rows,250)as$chunk)$sql('INSERT INTO '.stp_t($table).' ('.$columns.') VALUES '.implode(',',$chunk));};
try{
    if(wp_using_ext_object_cache()||!str_ends_with(str_replace('\\','/',(new ReflectionClass('WP_Object_Cache'))->getFileName()),'/wp-includes/class-wp-object-cache.php'))throw new RuntimeException('Request-local cache required');
    $sql("CREATE TEMPORARY TABLE {$options} LIKE {$originalOptions}");$created[]=$options;
    $sql("INSERT INTO {$options} SELECT * FROM {$originalOptions} WHERE option_name IN ('home','siteurl','stp_settings','dsa_settings')");
    $wpdb->options=$options;$GLOBALS['wp_object_cache']=new WP_Object_Cache();
    if(!function_exists('stp_cfg'))require_once DSA_DIR.'includes/Secure/securetrack-core.php';
    // Read the installed candidate's exact schema; the lab may not provision a disabled module.
    $source=file_get_contents(DSA_DIR.'includes/Secure/securetrack-core.php');
    foreach(['events','sessions','pages','ai_queue','rate_limits']as$name){
        $phase='temporary-'.$name;$table=$prefix.'stp_'.$name;
        $marker='dbDelta( "CREATE TABLE " . stp_t( \''.$name.'\' ) . " (';
        $from=strpos($source,$marker);$to=$from===false?false:strpos($source,') $cs;" );',$from);
        if($from===false||$to===false)throw new RuntimeException('Candidate schema unavailable');
        $definition=substr($source,$from+strlen($marker),$to-$from-strlen($marker));
        $sql("CREATE TEMPORARY TABLE {$table} ({$definition}) ".$wpdb->get_charset_collate());$created[]=$table;
    }
    $wpdb->prefix=$prefix;
    $flags=['green_trim_days'=>30,'yellow_trim_days'=>60];
    $override=static function($value)use(&$flags){return array_replace((array)$value,$flags);};add_filter('option_stp_settings',$override,PHP_INT_MAX);stp_cfg(true);
    $record('Expected immutable candidate installed',DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION);
    $record('PHP floor and debug policy retained',PHP_VERSION_ID>=80200&&!WP_DEBUG&&!WP_DEBUG_LOG&&!WP_DEBUG_DISPLAY);
    $record('Key/passkey/IP trust handlers remain available',function_exists('pk_rest_webauthn_login_verify')&&function_exists('stp_ip_status_is_trusted'));
    $phase='seed';$old=date('Y-m-d H:i:s',strtotime('-90 days'));$now=date('Y-m-d H:i:s');
    $rows=[];foreach(['green','yellow','red']as$flag)for($i=0;$i<650;$i++)$rows[]="(0,'synthetic','{$flag}','{$old}')";
    $rows[]="(0,'synthetic','green','{$now}')";$seed('events','ip_id,event_type,flag_status,created_at',$rows);
    $seed('sessions','id,session_token,ip_id,flag_status,started_at,last_activity',[
        "(1,'heavy',0,'green','{$old}','{$old}')","(2,'empty',0,'green','{$old}','{$old}')","(3,'red',0,'red','{$old}','{$old}')","(4,'active',0,'green','{$now}','{$now}')","(5,'yellow',0,'yellow','{$old}','{$old}')","(6,'promoted',0,'green','{$old}','{$old}')","(7,'refreshed',0,'green','{$old}','{$old}')"
    ]);
    $rows=[];for($i=0;$i<1501;$i++)$rows[]="(1,'https://example.invalid/fixture','{$old}')";
    foreach([3,4,5,6,7]as$id)$rows[]="({$id},'https://example.invalid/fixture','{$old}')";$seed('pages','session_id,url,visited_at',$rows);
    $rows=[];for($i=1;$i<=1600;$i++)$rows[]="({$i},'pending','{$old}')";for($i=1601;$i<=2250;$i++)$rows[]="({$i},'reviewed','{$old}')";$rows[]="(2251,'reviewed','{$now}')";$rows[]="(2252,'processing','{$old}')";$seed('ai_queue','id,status,created_at',$rows);
    $rows=[];for($i=0;$i<1200;$i++)$rows[]="('expired_{$i}',1)";$rows[]="('active',".(time()+3600).")";$seed('rate_limits','bucket_hash,expires_at',$rows);
    // Simulate promotion and activity after selection, before child deletion.
    $changed=false;$race=static function($statement)use(&$changed,$wpdb,$now){
        if(!$changed&&str_starts_with($statement,'DELETE FROM '.stp_t('pages'))){$changed=true;$wpdb->query("UPDATE ".stp_t('sessions')." SET flag_status='red' WHERE id=6");$wpdb->query($wpdb->prepare('UPDATE '.stp_t('sessions').' SET last_activity=%s WHERE id=7',$now));}return $statement;
    };add_filter('query',$race,PHP_INT_MAX);
    $phase='cleanup';$first=stp_run_cleanup();remove_filter('query',$race,PHP_INT_MAX);$race=null;
    $record('First bounded pass completed',($first['status']??'')==='complete'&&$first['deleted']<=5200);
    $record('Event caps retain backlog, recent content and red evidence',$count('events',"flag_status='green'")===151&&$count('events',"flag_status='yellow'")===150&&$count('events',"flag_status='red'")===650);
    $record('Page-heavy session retains its remaining children',$count('sessions','id=1')===1&&$count('pages','session_id=1')===501);
    $record('Empty old session and fully cleared yellow session retire',$count('sessions','id IN (2,5)')===0);
    $record('Red, recent, newly promoted and refreshed sessions/pages survive',$count('sessions','id IN (3,4,6,7)')===4&&$count('pages','session_id IN (3,4,6,7)')===4);
    $record('Pending queue trims only a bounded older portion',$count('ai_queue',"status='pending'")===1100&&$count('ai_queue','id BETWEEN 601 AND 1600')===1000);
    $record('Completed queue respects age and protects processing rows',$count('ai_queue',"status='reviewed'")===151&&$count('ai_queue','id=2252')===1);
    $record('Rate cleanup is capped and active counter survives',$count('rate_limits')===201&&$count('rate_limits',"bucket_hash='active'")===1);
    $second=stp_run_cleanup();
    $record('Later pass drains remainder and retires cleared parent',($second['status']??'')==='complete'&&$count('pages','session_id=1')===0&&$count('sessions','id=1')===0);
    $record('Newest 1000 pending entries remain after another pass',$count('ai_queue',"status='pending'")===1000&&$count('ai_queue','id BETWEEN 601 AND 1600')===1000);
    $record('Only recent and red event records remain',$count('events')===651);
    $record('No page is orphaned by cleanup',$count('pages','NOT EXISTS (SELECT 1 FROM '.stp_t('sessions').' WHERE '.stp_t('sessions').'.id='.stp_t('pages').'.session_id)')===0);
    $flags=['green_trim_days'=>0,'yellow_trim_days'=>0];stp_cfg(true);
    $seed('events','ip_id,event_type,flag_status,created_at',["(0,'synthetic','green','{$old}')","(0,'synthetic','yellow','{$old}')"]);
    $seed('sessions','id,session_token,ip_id,flag_status,started_at,last_activity',["(8,'keep-yellow',0,'yellow','{$old}','{$old}')"]);
    $third=stp_run_cleanup();$record('Disabled event/yellow retention is respected',$third['status']==='complete'&&$count('events')===653&&$count('sessions','id=8')===1);
    $lock='kiwe_stp_cleanup_'.substr(hash('sha256',(string)$wpdb->dbname.':'.$wpdb->prefix),0,40);
    $record('Database lock released after all passes',(string)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$lock))==='1');
    $counts=['firstDeleted'=>$first['deleted'],'secondDeleted'=>$second['deleted'],'thirdDeleted'=>$third['deleted']];
}catch(Throwable $e){$record('Native fixture completed without errors',false);$failure=['class'=>get_class($e),'line'=>$e->getLine(),'phase'=>$phase];}
finally{
    if($race)remove_filter('query',$race,PHP_INT_MAX);if($override)remove_filter('option_stp_settings',$override,PHP_INT_MAX);
    $wpdb->prefix=$originalPrefix;$wpdb->options=$originalOptions;$GLOBALS['wp_object_cache']=$originalCache;
    $removed=true;foreach(array_reverse($created)as$table){if(!preg_match('/^kiwe82qa_[a-f0-9]{12}_(?:options|stp_(?:events|sessions|pages|ai_queue|rate_limits))$/',$table)||$wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$table}")===false)$removed=false;}
    $preserved=true;foreach($original as$key=>$digest)if(hash('sha256',serialize(get_option($key,[])))!==$digest)$preserved=false;
    $record('All temporary tables removed and database/cache context restored',$removed&&$wpdb->prefix===$originalPrefix&&$GLOBALS['wp_object_cache']===$originalCache);
    $record('Original security and identity settings preserved',$preserved);
}
return ['passed'=>!in_array(false,array_column($checks,'passed'),true),'cleanup'=>$removed&&$preserved,'checks'=>$checks,'counts'=>$counts,'failure'=>$failure??null,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'scope'=>'Real cleanup SQL on connection-local fixture tables; not production retention, query-plan cost at scale or concurrent visitor capacity.'];
