<?php
/** Isolated actual WordPress/MariaDB acceptance, never a public route or customer-history drain. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SECURE_REPORT_TEST')||KIWE_SECURE_REPORT_TEST!==true||!defined('ABSPATH'))return['passed'=>false,'cleanup'=>false];
use DSA\Secure\SecureTrack_Db_Service as Schema;
use DSA\Secure\SecureTrack_Report_Service as Reports;
$checks=[];$proofs=[];$sql=[];$fault=null;$async=null;$second=null;$outbound=0;$guard=null;$created=false;$cleanup=false;
$assert=static function($ok,$name)use(&$checks){$checks[]=['name'=>$name,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($name);};
$old=['prefix'=>$wpdb->prefix,'options'=>$wpdb->options,'posts'=>$wpdb->posts,'cache'=>$GLOBALS['wp_object_cache'],'uid'=>get_current_user_id(),'cookies'=>$_COOKIE,'server'=>$_SERVER];
$prefix=KIWE_SECURE_REPORT_PREFIX;$options=$prefix.'options';$posts=$prefix.'posts';$tables=array_map(static fn($n)=>$prefix.'stp_'.$n,Schema::known_tables());$allowed=array_merge($tables,[$options,$posts,$prefix.'users']);
$reset=static function(){foreach(['table_existence'=>[],'readiness'=>[]]as$n=>$v)(new ReflectionProperty(Schema::class,$n))->setValue(null,$v);Reports::reset_readiness();unset($GLOBALS['stp_settings_cache']);};
$http=static function()use(&$outbound){$outbound++;return new WP_Error('synthetic_no_delivery','Blocked');};$mail=static function()use(&$outbound){$outbound++;return false;};
$priorSuppress=$wpdb->suppress_errors(true);
/** Fixture-only next-invocation simulation; production never sleeps or retries a worker. */
function kiwe_test_native_worker_resume(callable $worker,array &$observations,string $owner): array {
 $seen=[];
 foreach([0,100000,250000,750000]as$delay){
  if($delay)usleep($delay);$began=microtime(true);$result=$worker();$elapsed=round((microtime(true)-$began)*1000,1);$observations['lastNativeWorkerMs'][$owner]=$elapsed;$seen[]=array_merge($result,['elapsedMs'=>$elapsed]);
  $connection=($result['status']==='connection'||($result['status']==='unavailable'&&($result['reason']??'')==='connection'))&&($result['connectionError']??0)===2002;
  if(!$connection)break;
  if(($result['processed']??0)||($result['deleted']??0))throw new RuntimeException('Unavailable connection accepted work');
 }
 if(count($seen)>1)$observations['transientConnectionResumes'][]=['owner'=>$owner,'attempts'=>$seen];
 return$result;
}
$work=static function()use(&$proofs){return kiwe_test_native_worker_resume([Reports::class,'run'],$proofs,'reports');};
try{
 $assert(DSA_VERSION==='8.0.0-rc.277'&&KIWE_SECURE_SOURCE_VERSION==='8.0.0-rc.287','Lab stays RC277; the private source under test is RC287');
 $assert(!WP_DEBUG&&!WP_DEBUG_LOG&&!WP_DEBUG_DISPLAY&&!wp_using_ext_object_cache(),'Debug and external cache remain disabled for isolated fixture');
 $assert(!function_exists('stp_cfg'),'Served SecureTrack remains disabled');
 $assert($wpdb->query("CREATE TEMPORARY TABLE {$options} LIKE {$old['options']}")!==false,'Owned temporary native options created');$created=true;
 $assert($wpdb->query("INSERT INTO {$options} SELECT * FROM {$old['options']} WHERE option_name IN ('home','siteurl','dsa_settings')")!==false,'Only required native controls seeded');
 $assert($wpdb->query("CREATE TEMPORARY TABLE {$posts} LIKE {$old['posts']}")!==false,'Owned temporary native posts created');
 $wpdb->prefix=$prefix;$wpdb->options=$options;$wpdb->posts=$posts;$GLOBALS['wp_object_cache']=new WP_Object_Cache();$reset();
 $guard=static function($q)use(&$sql,$allowed){$sql[]=$q;if(preg_match('/^\s*(?:(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO|UPDATE|DELETE\s+FROM|(?:CREATE|ALTER|DROP|TRUNCATE)\s+(?:TEMPORARY\s+)?TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z0-9_]+)/i',$q,$m)&&!in_array($m[1],$allowed,true))throw new RuntimeException('Write outside owned fixture');return$q;};
 add_filter('query',$guard,PHP_INT_MAX-20);add_filter('pre_http_request',$http,PHP_INT_MAX);add_filter('pre_wp_mail',$mail,PHP_INT_MAX);
 update_option('timezone_string','Asia/Kolkata',false);update_option('gmt_offset',5.5,false);
 update_option('stp_settings',['track_visitors'=>1,'track_pages'=>1,'exclude_admin'=>0,'track_admin_activity'=>1,'behavioral_risk'=>0,'adaptive_learning'=>0,'v2_site_brain'=>0,'v2_subnet_intel'=>0,'v2_attack_graph'=>0,'v2_ai_queue'=>0,'geo_enabled'=>0,'ai_enabled'=>0,'honeypot_enabled'=>0,'bruteforce_enabled'=>0,'waf_enabled'=>0,'auto_block'=>0,'v2_local_reflex'=>0],false);
 require KIWE_SECURE_SOURCE_DIR.'/securetrack-core.php';
 $repair=Schema::repair_database();$proofs['schemaMaintenance']=get_option('stp_schema_maintenance');
 $assert($repair&&Schema::tables_ready(true)&&Reports::ready(),'Authorized native maintenance creates fifteen owner tables and source markers');
 $assert(get_option('stp_report_schema')==='1'&&STP_VER==='2.0.18','Reporting schema and explicit SecureTrack schema version are confirmed');
 $assert(DSA\Secure\SecureTrack_Lifecycle_Service::run()['status']==='current','Initial bounded maintenance publishes independent optional-detail health');
 $assert(wp_next_scheduled('stp_cron_reports')&&has_action('stp_cron_reports',[Reports::class,'run'])!==false,'Native owner schedules one reporting job and callback');
 $from=(new DateTimeImmutable('today',wp_timezone()))->modify('-220 days')->format('Y-m-d H:i:s');
 $assert(!Reports::period($from,current_time('mysql')),'Non-calendar reporting input cannot silently round a period');
 $wpdb->query("INSERT INTO {$posts} (ID,post_author,post_date,post_date_gmt,post_title,post_status,post_name,post_type) VALUES (720001,0,'2026-01-01 00:00:00','2025-12-31 18:30:00','Synthetic report one','publish','synthetic-report-one','post'),(720002,0,'2026-01-01 00:00:00','2025-12-31 18:30:00','Synthetic report two','publish','synthetic-report-two','post')");
 $url=(string)get_permalink(720001);$other=(string)get_permalink(720002);$assert($url!==$other&&str_contains($url,'p=720001'),'Real WordPress plain permalinks identify separate synthetic records');
 foreach(['pages','events','sessions','report_days','report_sessions','report_activity']as$n)$wpdb->query('DELETE FROM '.stp_t($n));
 $wpdb->query($wpdb->prepare('UPDATE '.stp_t('report_state')." SET coverage_start=%s,day_rows=0,session_rows=0,activity_rows=0 WHERE id=1",$from));
 $end=new DateTimeImmutable('tomorrow',wp_timezone());$now=current_time('mysql');$pageRows=[];$eventRows=[];
 for($d=0;$d<220;$d++){
  $date=$end->modify('-'.($d+1).' days')->format('Y-m-d');$count=1+($d%5);
  for($j=0;$j<$count;$j++){
   $sid=1+(($d+$j)%17);$u=$j%3===0?$url:($j%3===1?$url.'&utm_source=synthetic':$other);$seconds=$j%4===0?0:1+($d%41);
   $pageRows[]=$wpdb->prepare('(%d,%s,%d,%s)',$sid,$u,$seconds,$date.' 00:01:00');
  }
  foreach(['page_view','login_failed','protection_block']as$type)$eventRows[]=$wpdb->prepare('(%s,%s,%d)',$type,$date.' 00:01:00',$type==='page_view'?2000101:0);
 }
 foreach(array_chunk($pageRows,300)as$rows)$assert($wpdb->query('INSERT INTO '.stp_t('pages').' (session_id,url,time_spent,visited_at) VALUES '.implode(',',$rows))!==false,'Native page fixture chunk inserted');
 foreach(array_chunk($eventRows,300)as$rows)$assert($wpdb->query('INSERT INTO '.stp_t('events').' (ip_id,event_type,created_at,actor_id) VALUES '.implode(',',array_map(static fn($v)=>'(0,'.substr($v,1),$rows)))!==false,'Native event fixture chunk inserted');
 $wpdb->query($wpdb->prepare('INSERT INTO '.stp_t('sessions')." (session_token,ip_id,user_id,started_at,last_activity) VALUES (%s,0,2000102,%s,%s)",str_repeat('a',64),$now,$now));
 $wpdb->query($wpdb->prepare('INSERT INTO '.stp_t('sessions')." (session_token,ip_id,user_id,started_at,last_activity,flag_status) VALUES (%s,0,0,%s,%s,'green')",str_repeat('b',64),$from,$from));
 $sourceCounts=[];foreach(['pages','events','sessions']as$n)$sourceCounts[$n]=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.stp_t($n));stp_run_cleanup();
 $assert(!array_filter($sourceCounts,static fn($count,$n)=>$count!==(int)$GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM '.stp_t($n)),ARRAY_FILTER_USE_BOTH),'Native cleanup preserves unprojected event, page and session evidence');
	$runs=[];for($i=0;$i<20;$i++){ $r=$work();$runs[]=$r;if($r['status']==='current')break;$assert($r['status']==='catching_up','Bounded backfill pass reports remaining work'); }
 $proofs['worker']=$runs;$assert(end($runs)['status']==='current'&&!array_filter($runs,static fn($r)=>$r['processed']>300||$r['queries']>45),'Backfill uses at most 300 rows and 45 budgeted statements per pass');
 $assert((int)$wpdb->get_var('SELECT COUNT(*) FROM '.stp_t('report_sessions'))===(int)$wpdb->get_var('SELECT session_rows FROM '.stp_t('report_state').' WHERE id=1'),'Membership capacity accounting matches actual retained rows');
 $traffic=new ReflectionMethod(DSA\Analytics\Analytics_Report_Service::class,'traffic');$reporter=new DSA\Analytics\Analytics_Report_Service();
 foreach([7,30,90]as$days){foreach(['current','previous']as$period){
  $to=$period==='current'?$end:$end->modify('-'.$days.' days');$start=$to->modify('-'.$days.' days');$a=$start->format('Y-m-d H:i:s');$b=$to->format('Y-m-d H:i:s');
  foreach([0,720001,720002]as$id){
   $where='visited_at >= %s AND visited_at < %s';$params=[$a,$b];if($id){$canonical=(string)get_permalink($id);$where.=' AND (url=%s OR url LIKE %s)';$params[]=$canonical;$params[]=$wpdb->esc_like($canonical).'&%';}
   $raw=$wpdb->get_row($wpdb->prepare('SELECT COUNT(*) views,COUNT(DISTINCT NULLIF(session_id,0)) sessions,AVG(NULLIF(time_spent,0)) engaged FROM '.stp_t('pages').' WHERE '.$where,$params),ARRAY_A);
   $derived=$id?$traffic->invoke($reporter,$a,$b,$id):Reports::period($a,$b);$expected=null===$raw['engaged']?null:round((float)$raw['engaged'],1);
   $assert($derived['views']===(int)$raw['views']&&$derived['sessions']===(int)$raw['sessions']&&$derived['engagedSeconds']===$expected,"{$days}-day {$period} raw/summary parity scope {$id}");
   $assert($derived['coverage']['complete'],"{$days}-day {$period} known recorded coverage scope {$id}");
  }
  $raw=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) events,SUM(event_type='login_failed') login_failures,SUM(event_type IN ('protection_block','waf_block','rest_abuse')) protections FROM ".stp_t('events').' WHERE created_at >= %s AND created_at < %s',$a,$b),ARRAY_A);$r=Reports::period($a,$b,'','security');
  $assert($r['events']===(int)$raw['events']&&$r['login_failures']===(int)$raw['login_failures']&&$r['protections']===(int)$raw['protections'],"{$days}-day {$period} native security parity");
 }}
 $a=$end->modify('-90 days')->format('Y-m-d H:i:s');$b=$end->format('Y-m-d H:i:s');$base=Reports::period($a,$b);$assert($base['sessions']===17&&$base['views']>17*10,'Cross-day session union is seventeen, not the sum of daily distinct counts');
 $daysBefore=$wpdb->get_results('SELECT * FROM '.stp_t('report_days').' ORDER BY day,scope_hash',ARRAY_A);$membersBefore=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.stp_t('report_sessions'));$work();
 $assert($daysBefore===$wpdb->get_results('SELECT * FROM '.stp_t('report_days').' ORDER BY day,scope_hash',ARRAY_A)&&$membersBefore===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.stp_t('report_sessions')),'Restarting a completed job does not count any source row again');
 $page=(int)$wpdb->get_var('SELECT id FROM '.stp_t('pages').' ORDER BY visited_at DESC,id LIMIT 1');$wpdb->query('UPDATE '.stp_t('pages').' SET time_spent=17,report_pending=1 WHERE id='.$page);$dirty=Reports::period($a,$b);$assert(!$dirty['coverage']['complete']&&$dirty['coverage']['status']==='catching_up','Late timing update marks cached reports incomplete immediately');$work();
 $raw=round((float)$wpdb->get_var($wpdb->prepare('SELECT AVG(NULLIF(time_spent,0)) FROM '.stp_t('pages').' WHERE visited_at >= %s AND visited_at < %s',$a,$b)),1);$assert(Reports::period($a,$b)['engagedSeconds']===$raw,'Zero-to-positive late timing updates preserve the weighted denominator');
 $wpdb->query('UPDATE '.stp_t('pages').' SET time_spent=38,report_pending=1 WHERE id='.$page);$work();$raw=round((float)$wpdb->get_var($wpdb->prepare('SELECT AVG(NULLIF(time_spent,0)) FROM '.stp_t('pages').' WHERE visited_at >= %s AND visited_at < %s',$a,$b)),1);$assert(Reports::period($a,$b)['engagedSeconds']===$raw,'Repeated timing adds seconds without adding another timed visit');
 $wpdb->query($wpdb->prepare('INSERT INTO '.stp_t('pages').' (session_id,url,time_spent,visited_at) VALUES (18,%s,25,%s)',$url,$now));$new=(int)$wpdb->insert_id;
 $daysBefore=$wpdb->get_results('SELECT * FROM '.stp_t('report_days').' ORDER BY day,scope_hash',ARRAY_A);
 $fault=static function($q){return str_starts_with($q,'UPDATE '.stp_t('pages').' SET report_pending=0')?'UPDATE '.stp_t('pages').' SET kiwe_intentionally_missing_column=1':$q;};add_filter('query',$fault,PHP_INT_MAX);$r=$work();remove_filter('query',$fault,PHP_INT_MAX);$fault=null;
 $assert($r['status']==='error'&&$r['processed']===0&&$daysBefore===$wpdb->get_results('SELECT * FROM '.stp_t('report_days').' ORDER BY day,scope_hash',ARRAY_A)&&(int)$wpdb->get_var('SELECT report_pending FROM '.stp_t('pages').' WHERE id='.$new)===1,'Failed transaction rolls back totals, membership and source markers together');
 $assert($work()['status']==='current'&&Reports::period($a,$b)['views']===$base['views']+1,'Retry after a failed transaction counts the pending visit exactly once');
 $second=mysqli_init();$assert($second->real_connect(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME),'Independent native connection is available');
 $wpdb->query('UPDATE '.stp_t('pages').' SET report_pending=1 WHERE id='.$new);
 $async=static function($q)use($second,$new,&$async){if(str_starts_with($q,'INSERT INTO '.stp_t('report_days'))){remove_filter('query',$async,PHP_INT_MAX);if(!$second->query('UPDATE '.stp_t('pages').' SET time_spent=time_spent+11,report_pending=1 WHERE id='.$new,MYSQLI_ASYNC))throw new RuntimeException('Asynchronous timing write failed');}return$q;};add_filter('query',$async,PHP_INT_MAX);$r=$work();remove_filter('query',$async,PHP_INT_MAX);$async=null;
 $read=[$second];$errors=[];$reject=[];$assert(mysqli_poll($read,$errors,$reject,2)>0&&$second->reap_async_query()===true,'A concurrent native beacon resumes after the short source transaction');
 $assert($r['status']==='current'&&(int)$wpdb->get_var('SELECT report_pending FROM '.stp_t('pages').' WHERE id='.$new)===1,'Concurrent timing remains pending after the worker snapshot, without being lost');$work();
 $raw=round((float)$wpdb->get_var($wpdb->prepare('SELECT AVG(NULLIF(time_spent,0)) FROM '.stp_t('pages').' WHERE visited_at >= %s AND visited_at < %s',$a,$b)),1);$assert(Reports::period($a,$b)['engagedSeconds']===$raw,'The next worker applies the concurrent timing exactly once');
 $lock='kiwe_stp_report_'.substr(hash('sha256',$wpdb->dbname.':'.$wpdb->prefix),0,40);$assert((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))==='1','Competing reporting lock acquired by fixture');$sql=[];$r=$work();$assert($r['status']==='busy'&&$r['processed']===0&&!array_filter($sql,static fn($q)=>preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/',$q)),'A competing job performs no persistence or waiting');$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
 $wpdb->query('UPDATE '.stp_t('pages').' SET time_spent=time_spent+3,report_pending=1 WHERE id='.$new);$daysBefore=$wpdb->get_results('SELECT * FROM '.stp_t('report_days').' ORDER BY day,scope_hash',ARRAY_A);
 $fault=static function($q)use($lock,$wpdb,&$fault){if(str_starts_with($q,'UPDATE '.stp_t('pages').' SET report_pending=0')){remove_filter('query',$fault,PHP_INT_MAX);$id=(int)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)',$lock));if(!$id||$id===(int)$wpdb->get_var('SELECT CONNECTION_ID()'))throw new RuntimeException('Connection-loss target not owned');$wpdb->query('KILL CONNECTION '.$id);}return$q;};add_filter('query',$fault,PHP_INT_MAX);$r=$work();remove_filter('query',$fault,PHP_INT_MAX);$fault=null;
 $assert($r['status']==='error'&&$daysBefore===$wpdb->get_results('SELECT * FROM '.stp_t('report_days').' ORDER BY day,scope_hash',ARRAY_A)&&(int)$wpdb->get_var('SELECT report_pending FROM '.stp_t('pages').' WHERE id='.$new)===1,'Lost worker connection never replays a write outside its transaction');
 $proofs['connectionRecoverySchema']=['enabled'=>Reports::enabled(),'ready'=>Reports::ready(),'marker'=>get_option('stp_report_schema')];
 $recover=$work();$proofs['connectionRecoveryFirst']=$recover;
 // KILL signals the server thread; its rollback/lock release can finish after the command returns.
 // Only this synthetic fixture waits. Production workers always skip a busy lock without waiting.
 if($recover['status']==='busy'){usleep(100000);$recover=$work();}$proofs['connectionRecoveryFinal']=$recover;
 $assert($recover['status']==='current','A fresh worker recovers after connection loss');
 $last=DSA\Secure\SecureTrack_Event_Service::user_last_activity_map([2000101,2000102]);$assert(isset($last[2000101],$last[2000102]),'Verified actor events and native session activity populate durable account clocks');
 wp_set_current_user(0);$_SERVER['REMOTE_ADDR']='192.0.2.249';$_SERVER['HTTP_USER_AGENT']='Synthetic report probe';unset($_COOKIE['stp_sess']);
 $event=stp_log('login_failed',['user_id'=>2000103,'username'=>'synthetic','url'=>'https://example.test/']);$assert($event>0&&(int)$wpdb->get_var('SELECT actor_id FROM '.stp_t('events').' WHERE id='.(int)$event)===0,'A failed login target is not stored as an authenticated actor');$work();$assert(!Reports::activity([2000103]),'Failed login cannot fabricate account activity');
 $viewsBefore=Reports::period($a,$b);$securityBefore=Reports::period($a,$b,'','security');$wpdb->query('DELETE FROM '.stp_t('pages'));$wpdb->query('DELETE FROM '.stp_t('sessions'));$wpdb->query('DELETE FROM '.stp_t('events'));$work();
 $assert(Reports::period($a,$b)['views']===$viewsBefore['views']&&Reports::period($a,$b)['sessions']===$viewsBefore['sessions']&&Reports::period($a,$b)['engagedSeconds']===$viewsBefore['engagedSeconds'],'Raw-history retirement leaves period totals, distinct sessions and engagement intact');
 $assert(Reports::period($a,$b,'','security')['events']===$securityBefore['events']&&DSA\Secure\SecureTrack_Event_Service::user_last_activity_map([2000101,2000102])===$last,'Raw-history retirement preserves security totals and durable account activity');
 $wpdb->query($wpdb->prepare('UPDATE '.stp_t('report_state').' SET coverage_start=%s,generation=generation+1 WHERE id=1',$now));$r=$reporter->report('post',720001,7);$section=$r['sections'][0];$assert(!$section['coverage']['current']['complete']&&!array_filter($section['metrics'],static fn($m)=>$m['previous']!==null)&&str_contains($section['note'],'Partial periods'),'Native report exposes historical gaps and suppresses misleading growth comparisons');
 $settings=get_option('stp_settings');$settings['track_pages']=0;update_option('stp_settings',$settings,false);$assert($wpdb->get_var('SELECT status FROM '.stp_t('report_state').' WHERE id=1')==='pending','Native tracking-policy edits invalidate coverage');$settings['track_pages']=1;update_option('stp_settings',$settings,false);
 $work();$dsa=get_option('dsa_settings');$changed=$dsa;$changed['secure']['enabled']=!($dsa['secure']['enabled']??false);update_option('dsa_settings',$changed,false);$assert($wpdb->get_var('SELECT status FROM '.stp_t('report_state').' WHERE id=1')==='pending','Native module enable/disable edits invalidate reporting coverage');update_option('dsa_settings',$dsa,false);
 $fault=static function($q){return str_contains($q,'SELECT * FROM '.stp_t('report_state'))?'SET STATEMENT max_statement_time=0.5 FOR SELECT SLEEP(2)':$q;};add_filter('query',$fault,PHP_INT_MAX);$began=microtime(true);$timeout=Reports::period($a,$b);$elapsed=microtime(true)-$began;remove_filter('query',$fault,PHP_INT_MAX);$fault=null;
 $assert($timeout===null&&$elapsed<1.5,'Real MariaDB statement limit cancels a slow reporting read');$proofs['readTimeoutMs']=round($elapsed*1000,1);
 $wpdb->query("UPDATE ".stp_t('report_state')." SET timezone='UTC',generation=generation+1 WHERE id=1");$drift=$work();$proofs['timezoneWorker']=$drift;$proofs['timezoneStored']=$wpdb->get_var('SELECT timezone FROM '.stp_t('report_state').' WHERE id=1');$assert($drift['status']==='timezone'&&Reports::period($a,$b)===null,'Timezone drift refuses reaggregation and interpretation');$wpdb->query("UPDATE ".stp_t('report_state')." SET timezone='Asia/Kolkata' WHERE id=1");
 // Actual capacity, not a forged counter: fill the bounded membership table, then refuse a new key.
 $wpdb->query('DELETE FROM '.stp_t('report_sessions'));for($start=1;$start<=Reports::SESSION_LIMIT;$start+=1000){$values=[];for($i=$start;$i<min($start+1000,Reports::SESSION_LIMIT+1);$i++)$values[]=$wpdb->prepare('(%s,%s,%d)',substr($now,0,10),Reports::site_hash(),$i);if($wpdb->query('INSERT INTO '.stp_t('report_sessions').' (day,scope_hash,session_id) VALUES '.implode(',',$values))===false)throw new RuntimeException('Capacity fixture failed');}
 $wpdb->query('UPDATE '.stp_t('report_state').' SET session_rows='.Reports::SESSION_LIMIT.' WHERE id=1');$wpdb->query($wpdb->prepare('INSERT INTO '.stp_t('pages').' (session_id,url,visited_at) VALUES (%d,%s,%s)',Reports::SESSION_LIMIT+1,$url,$now));$new=(int)$wpdb->insert_id;$r=$work();
 $assert($r['status']==='capacity'&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.stp_t('report_sessions'))===Reports::SESSION_LIMIT&&(int)$wpdb->get_var('SELECT report_pending FROM '.stp_t('pages').' WHERE id='.$new)===1,'Actual 100000-membership cap refuses growth without losing source rows');
 $oldDay=$end->modify('-190 days')->format('Y-m-d');$wpdb->query($wpdb->prepare('UPDATE '.stp_t('report_sessions').' SET day=%s WHERE session_id<=500',$oldDay));$r=$work();$assert($r['status']==='current'&&(int)$wpdb->get_var('SELECT session_rows FROM '.stp_t('report_state').' WHERE id=1')===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.stp_t('report_sessions')),'Bounded expired-membership cleanup releases real capacity and correct counters');
 $plan=$wpdb->get_results('EXPLAIN SELECT id FROM '.stp_t('pages').' WHERE report_pending=1 ORDER BY id LIMIT 100',ARRAY_A);$assert(($plan[0]['key']??'')==='idx_stp_report_pending','Raw dirty work uses the compound pending/id index');
 $plan=$wpdb->get_results($wpdb->prepare('EXPLAIN SELECT COUNT(DISTINCT session_id) FROM '.stp_t('report_sessions').' WHERE scope_hash=%s AND day >= %s AND day < %s',Reports::site_hash(),substr($a,0,10),substr($b,0,10)),ARRAY_A);$assert(in_array($plan[0]['key']??'',['PRIMARY','idx_day'],true),'Period session union uses an actual native index');$proofs['sessionPlan']=['key'=>$plan[0]['key'],'rows'=>(int)$plan[0]['rows']];
 $sql=[];Reports::period($a,$b);Reports::period($a,$b);$assert(!array_filter($sql,static fn($q)=>preg_match('/^\s*(?:INSERT|UPDATE|DELETE|CREATE|ALTER)\b/',$q))&&!array_filter($sql,static fn($q)=>str_contains($q,'COUNT(*)')&&str_contains($q,stp_t('pages'))),'Report reads use cached/indexed summaries with no raw-history aggregate or writes');
 if(defined('KIWE_SECURE_LIFECYCLE_TEST')&&KIWE_SECURE_LIFECYCLE_TEST===true){require KIWE_SECURE_SOURCE_DIR.'/test-wordpress-securetrack-lifecycle.php';kiwe_test_securetrack_lifecycle($assert,$proofs);}
 $assert($outbound===0,'Synthetic reporting performs no provider or delivery work');
}catch(Throwable$e){$checks[]=['name'=>'Exception: '.$e->getMessage(),'passed'=>false];}
finally{
 if($fault)remove_filter('query',$fault,PHP_INT_MAX);if($async)remove_filter('query',$async,PHP_INT_MAX);if($second)$second->close();
 if($created){foreach($tables as$t)$wpdb->query('DROP TABLE IF EXISTS '.$t);$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$options);$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$posts);$remaining=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($prefix).'%'));$cleanup=!$remaining;}
 if($guard)remove_filter('query',$guard,PHP_INT_MAX-20);$wpdb->prefix=$old['prefix'];$wpdb->options=$old['options'];$wpdb->posts=$old['posts'];$GLOBALS['wp_object_cache']=$old['cache'];$_COOKIE=$old['cookies'];$_SERVER=$old['server'];wp_set_current_user($old['uid']);$wpdb->suppress_errors($priorSuppress);$reset();
 remove_filter('pre_http_request',$http,PHP_INT_MAX);remove_filter('pre_wp_mail',$mail,PHP_INT_MAX);
}
return ['schema'=>'kiwe.securetrack-report.native.v1','passed'=>$cleanup&&!in_array(false,array_column($checks,'passed'),true),'cleanup'=>$cleanup,'checks'=>$checks,'observations'=>$proofs];
