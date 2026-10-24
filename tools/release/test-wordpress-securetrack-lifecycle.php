<?php
/** Extends the isolated reporting fixture; uses only its owned native tables and synthetic users. */
function kiwe_test_securetrack_lifecycle(callable $assert,array &$observations): void {
    global $wpdb;
    $life=DSA\Secure\SecureTrack_Lifecycle_Service::class;$reports=DSA\Secure\SecureTrack_Report_Service::class;
    $t=static fn($n)=>stp_t($n);$now=current_time('mysql');$wall=new DateTimeImmutable($now,wp_timezone());
    $old=$wall->modify('-220 days')->format('Y-m-d H:i:s');$recent=$wall->modify('-2 days')->format('Y-m-d H:i:s');$future=$wall->modify('+2 days')->format('Y-m-d H:i:s');
    $users=$wpdb->prefix.'users';$oldUsers=$wpdb->users;$wpdb->users=$users;
    $reset=static function()use($wpdb,$t,$life){
        foreach(['events','pages','sessions','ips','profiles','alerts','subnets','brain','ai_queue','rate_limits','report_activity','report_days','report_sessions']as$n)$wpdb->query('DELETE FROM '.$t($n));
        $wpdb->query("UPDATE ".$t('lifecycle_state')." SET cursors='{}',next_at=0,failures=0,status='pending',timezone='Asia/Kolkata',scanned_rows=0,deleted_rows=0,refused_rows=0 WHERE id=1");
        $wpdb->query("UPDATE ".$t('report_state')." SET timezone='Asia/Kolkata',status='pending',activity_rows=0,day_rows=0,session_rows=0,rejected_rows=0 WHERE id=1");
        delete_option('stp_lifecycle_budget');wp_cache_delete('settings','securetrack_pro');
    };
    $event=static function($name,array$extra=[])use($wpdb,$t,$old){$wpdb->insert($t('events'),array_merge(['ip_id'=>0,'event_type'=>$name,'flag_status'=>'green','risk_score'=>0,'reviewed'=>1,'created_at'=>$old,'report_pending'=>0],$extra));return(int)$wpdb->insert_id;};
    $page=static function($sid=0,array$extra=[])use($wpdb,$t,$old){$wpdb->insert($t('pages'),array_merge(['session_id'=>$sid,'url'=>'https://example.test/synthetic-history','visited_at'=>$old,'report_pending'=>0,'report_counted'=>1],$extra));return(int)$wpdb->insert_id;};
    $session=static function($name,array$extra=[])use($wpdb,$t,$old){$wpdb->insert($t('sessions'),array_merge(['session_token'=>hash('sha256',$name),'ip_id'=>0,'started_at'=>$old,'last_activity'=>$old,'flag_status'=>'green','report_pending'=>0],$extra));return(int)$wpdb->insert_id;};
    $exists=static fn($n,$id,$key='id')=>(bool)$wpdb->get_var('SELECT '.$key.' FROM '.$t($n).' WHERE '.$key.'='.(int)$id);
    $maintain=static function()use($life,&$observations){return kiwe_test_native_worker_resume([$life,'run'],$observations,'lifecycle');};
    $finish=static function($limit=60)use($maintain){$runs=[];for($i=0;$i<$limit;$i++){ $r=$maintain();$runs[]=$r;if(!in_array($r['status'],['current','catching_up','capacity'],true))throw new RuntimeException('Lifecycle worker refused: '.$r['status']);if($r['status']==='current'&&$r['scanned']<200)break;}return$runs;};
    $fault=null;$connection=null;$guard=null;
    try{
        $assert($wpdb->query("CREATE TABLE {$users} LIKE {$oldUsers}")!==false,'Lifecycle creates an owned synthetic native user authority');
        $wpdb->query("INSERT INTO {$users} (ID,user_login,user_email,user_registered) VALUES (2000101,'synthetic-live-user','native-user@example.test','2026-01-01 00:00:00')");
        $reset();$settings=get_option('stp_settings');$settings=array_merge($settings,['lifecycle_enabled'=>1,'green_trim_days'=>30,'yellow_trim_days'=>90,'page_trim_days'=>30,'inactive_state_days'=>185,'resolved_alert_days'=>185,'queue_trim_days'=>30]);update_option('stp_settings',$settings,false);stp_cfg(true);
        $assert($life::ready(),'The explicit lifecycle schema is independently ready');
        $assert(wp_get_scheduled_event('stp_cron_cleanup')->interval===300&&wp_get_scheduled_event('stp_cron_reports')->interval===60,'Native cron stores five-minute cleanup and one-minute reporting');
        $assert($wpdb->query('ALTER TABLE '.$t('alerts').' RENAME INDEX idx_stp_alert_event TO idx_fixture_legacy_event')!==false,'Owned native index is renamed to a legacy alias');
        delete_option('stp_schema_maintenance');
        $assert(DSA\Secure\SecureTrack_Db_Service::repair_database(),'Explicit native lifecycle upgrade accepts an equivalent legacy index');
        $assert(DSA\Secure\SecureTrack_Db_Service::index_exists('alerts','idx_fixture_legacy_event')&&!DSA\Secure\SecureTrack_Db_Service::index_exists('alerts','idx_stp_alert_event'),'Native maintenance preserves the legacy index without a duplicate');
        $stored=get_option('stp_settings');$stored['lifecycle_enabled']=0;$stored['inactive_state_days']=0;
        update_option('stp_settings',$life::preserve_settings(['green_trim_days'=>31],$stored),false);
        $assert($life::policy()['lifecycle_enabled']===0&&$life::policy()['inactive_state_days']===0&&$life::policy()['green_trim_days']===31,'A native legacy-form save preserves disabled/zero lifecycle choices and accepts the visible choice');
        $assert($maintain()['status']==='disabled','A preserved native disabled choice still blocks disposal after a form save');
        update_option('stp_settings',$settings,false);stp_cfg(true);
        // Reproduce both legacy defects in the same owned schema before exercising the replacement.
        require KIWE_SECURE_SOURCE_DIR.'/previous-securetrack-cleanup.php';
        $detached=$page(999999);$young=$event('legacy-cutoff',['created_at'=>$wall->modify('-30 days +1 hour')->format('Y-m-d H:i:s')]);
        $tz=date_default_timezone_get();date_default_timezone_set('Asia/Tokyo');kiwe_legacy_cleanup_rc286();date_default_timezone_set($tz);
        $assert($exists('pages',$detached),'Pre-change cleanup demonstrably leaves detached native pages');
        $assert(!$exists('events',$young),'Pre-change cleanup can prune a nonexpired site-clock event when server time differs');
        $reset();$maintain();$stamp=current_time('mysql');
        stp_brain_observe(0,'page_view',['ip'=>'203.0.113.80','url'=>'https://example.test/observed'],['score'=>0,'flag'=>'green'],null,true);
        $observed=$wpdb->get_results('SELECT site_clock,last_seen FROM '.$t('brain'),ARRAY_A);
        $assert($observed&&!array_filter($observed,static fn($x)=>(int)$x['site_clock']!==1||$x['last_seen']<$stamp||$x['last_seen']>current_time('mysql')),'The actual learning writer records a known website clock');
        $settings['subnet_intel']=1;update_option('stp_settings',$settings,false);stp_cfg(true);stp_update_subnet_intel('203.0.113.80',false);
        $observed=$wpdb->get_row("SELECT site_clock,last_seen FROM ".$t('subnets')." WHERE subnet='203.0.113.0/24'",ARRAY_A);
        $assert($observed&&(int)$observed['site_clock']===1&&$observed['last_seen']>=$stamp&&$observed['last_seen']<=current_time('mysql'),'The actual subnet writer records a known website clock');
        $settings['subnet_intel']=0;update_option('stp_settings',$settings,false);stp_cfg(true);$reset();
        $events=[];foreach(['expired','unreviewed','red','risk','held','pending','future','recent','queue','open','resolved-recent','resolved-old']as$name){
            $extra=match($name){'unreviewed'=>['flag_status'=>'yellow','reviewed'=>0],'red'=>['flag_status'=>'red','risk_score'=>95],'risk'=>['risk_score'=>95],'held'=>['retention_hold'=>1],'pending'=>['report_pending'=>1],'future'=>['created_at'=>$future],'recent'=>['created_at'=>$recent],default=>[]};$events[$name]=$event('synthetic-'.$name,$extra);
        }
        $wpdb->insert($t('ai_queue'),['event_id'=>$events['queue'],'status'=>'pending','created_at'=>$old]);$pendingQueue=(int)$wpdb->insert_id;
        foreach(['open','resolved-recent','resolved-old']as$name)$wpdb->insert($t('alerts'),['event_id'=>$events[$name],'alert_time'=>$old,'is_resolved'=>$name==='open'?0:1,'resolved_at'=>$name==='resolved-recent'?$recent:($name==='open'?null:$old)]);
        $sids=['expired'=>$session('expired'),'active'=>$session('active',['last_activity'=>$recent]),'red'=>$session('red',['flag_status'=>'red']),'held'=>$session('held',['retention_hold'=>1]),'pending'=>$session('pending',['report_pending'=>1]),'referenced'=>$session('referenced')];
        $event('session-red',['session_id'=>$sids['referenced'],'flag_status'=>'red','risk_score'=>90]);
        $pages=['detached'=>$page(999998),'zero'=>$page(0),'direct-held'=>$page(0,['retention_hold'=>2]),'direct-pending'=>$page(0,['report_pending'=>1]),'recent'=>$page(0,['visited_at'=>$recent]),'future'=>$page(0,['visited_at'=>$future])];
        foreach($sids as$name=>$sid)$pages[$name]=$page($sid);
        $wpdb->insert($t('profiles'),['user_id'=>2000101,'trusted_ips'=>'["192.0.2.201"]','last_ip'=>'192.0.2.202','updated_at'=>$old]);$wpdb->insert($t('profiles'),['user_id'=>999998,'trusted_ips'=>'[]','updated_at'=>$old]);
        foreach([2000101,999998]as$id)$wpdb->insert($t('report_activity'),['user_id'=>$id,'last_activity'=>$old]);$wpdb->query('UPDATE '.$t('report_state').' SET activity_rows=2 WHERE id=1');
        $ips=[];foreach(['expired','trusted','blocked','risk','held','profile-trust','profile-last','recent','event']as$i=>$name){$data=['ip_address'=>'192.0.2.'.(190+$i),'first_seen'=>$old,'last_seen'=>$old,'status'=>'unknown'];if($name==='trusted')$data['status']='trusted';if($name==='blocked')$data['status']='blocked';if($name==='risk')$data['risk_score']=80;if($name==='held')$data['retention_hold']=1;if($name==='profile-trust')$data['ip_address']='192.0.2.201';if($name==='profile-last')$data['ip_address']='192.0.2.202';if($name==='recent')$data['last_seen']=$recent;$wpdb->insert($t('ips'),$data);$ips[$name]=(int)$wpdb->insert_id;}
        $event('ip-red',['ip_id'=>$ips['event'],'flag_status'=>'red','risk_score'=>90]);
        $subnets=[];foreach(['expired','banned','risk','held','legacy-clock','address-reference']as$i=>$name){$data=['subnet'=>'198.51.'.$i.'.0/24','first_seen'=>$old,'last_seen'=>$old,'site_clock'=>1];if($name==='banned')$data['is_banned']=1;if($name==='risk')$data['threat_score']=95;if($name==='held')$data['retention_hold']=1;if($name==='legacy-clock')$data['site_clock']=0;$wpdb->insert($t('subnets'),$data);$subnets[$name]=(int)$wpdb->insert_id;}
        $wpdb->insert($t('ips'),['ip_address'=>'198.51.5.90','status'=>'blocked','first_seen'=>$old,'last_seen'=>$old]);
        $brain=[];foreach(['expired','risk','held','legacy-clock','recent']as$name){$data=['feature_key'=>'synthetic-'.$name,'first_seen'=>$old,'last_seen'=>$old,'site_clock'=>1];if($name==='risk')$data['risk_count']=5;if($name==='held')$data['retention_hold']=1;if($name==='legacy-clock')$data['site_clock']=0;if($name==='recent')$data['last_seen']=$recent;$wpdb->insert($t('brain'),$data);$brain[$name]=(int)$wpdb->insert_id;}
        $wpdb->insert($t('rate_limits'),['bucket_hash'=>hash('sha256','expired'),'window_start'=>1,'expires_at'=>time()-1]);$rateExpired=(int)$wpdb->insert_id;$wpdb->insert($t('rate_limits'),['bucket_hash'=>hash('sha256','active'),'window_start'=>time(),'expires_at'=>time()+3600]);$rateActive=(int)$wpdb->insert_id;
        $r=$maintain();$assert(in_array($r['status'],['current','catching_up'],true)&&$r['scanned']<=2200&&$r['queries']<=70,'Native cleanup is bounded by candidate and statement budgets');
        $assert(!$exists('events',$events['expired'])&&!$exists('events',$events['resolved-old']),'Projected expired routine events and old resolved references retire');
        foreach(array_diff(array_keys($events),['expired','resolved-old'])as$name)$assert($exists('events',$events[$name]),'Native event protection: '.$name);
        foreach(['detached','zero','expired']as$name)$assert(!$exists('pages',$pages[$name]),'Native page retirement: '.$name);
        foreach(['direct-held','direct-pending','held','pending','recent','future','active','red','referenced']as$name)$assert($exists('pages',$pages[$name]),'Native page protection: '.$name);
        $assert(!$exists('sessions',$sids['expired']),'An expired parent retires after its final child is removed');
        foreach(['active','red','held','pending','referenced']as$name)$assert($exists('sessions',$sids[$name]),'Native session protection: '.$name);
        $assert($exists('ai_queue',$pendingQueue),'An old pending human review is preserved');
        $assert($exists('profiles',2000101,'user_id')&&!$exists('profiles',999998,'user_id'),'Native existing accounts retain profiles; detached profiles retire');
        $assert($exists('report_activity',2000101,'user_id')&&!$exists('report_activity',999998,'user_id')&&(int)$wpdb->get_var('SELECT activity_rows FROM '.$t('report_state').' WHERE id=1')===1,'Account removal disposes only detached clocks and adjusts summary cardinality');
        $assert(!$exists('ips',$ips['expired']),'Inactive unreferenced low-risk addresses retire');
        foreach(array_diff(array_keys($ips),['expired'])as$name)$assert($exists('ips',$ips[$name]),'Native address protection: '.$name);
        $assert(!$exists('subnets',$subnets['expired'])&&!$exists('brain',$brain['expired']),'Inactive low-risk native network and learning state retire');
        foreach(array_diff(array_keys($subnets),['expired'])as$name)$assert($exists('subnets',$subnets[$name]),'Native subnet protection: '.$name);
        foreach(array_diff(array_keys($brain),['expired'])as$name)$assert($exists('brain',$brain[$name]),'Native learning protection: '.$name);
        $assert(!$exists('rate_limits',$rateExpired)&&$exists('rate_limits',$rateActive),'Only expired integer-clock counters retire');
        foreach(['{"legacy":"192.0.2.200"}','not-json']as$trust){
            $wpdb->update($t('profiles'),['trusted_ips'=>$trust],['user_id'=>2000101]);
            $wpdb->insert($t('ips'),['ip_address'=>'192.0.2.250','status'=>'unknown','first_seen'=>$old,'last_seen'=>$old]);$unknown=(int)$wpdb->insert_id;
            $r=$maintain();$assert(in_array($r['status'],['current','catching_up'],true)&&$exists('ips',$unknown),'Ambiguous native trust JSON holds an address without failing maintenance: '.$trust);
            $wpdb->delete($t('ips'),['id'=>$unknown]);
        }
        $wpdb->update($t('profiles'),['trusted_ips'=>'["192.0.2.201"]'],['user_id'=>2000101]);
        // Exercise actual projection-before-retirement, not manually forged source markers.
        $reset();$date=$wall->modify('-35 days')->format('Y-m-d');$pid=$page(0,['visited_at'=>$date.' 00:01:00','report_pending'=>1,'report_counted'=>0,'time_spent'=>42]);
        $wpdb->query($wpdb->prepare('UPDATE '.$t('report_state').' SET coverage_start=%s WHERE id=1',$date.' 00:00:00'));
        $maintain();$assert($exists('pages',$pid),'Unprojected native page survives a real maintenance pass');
        $reports::run();$before=$reports::period($date.' 00:00:00',(new DateTimeImmutable($date,wp_timezone()))->modify('+1 day')->format('Y-m-d H:i:s'));
        $disposal=$maintain();$after=$reports::period($date.' 00:00:00',(new DateTimeImmutable($date,wp_timezone()))->modify('+1 day')->format('Y-m-d H:i:s'));
        $observations['lifecycleProjection']=['before'=>$before,'after'=>$after,'maintenance'=>$disposal,'sourceRemaining'=>$exists('pages',$pid)];
        $assert(!$exists('pages',$pid)&&$before['views']===1&&$after['views']===1&&(float)$after['engagedSeconds']===42.0,'Actual scheduled disposal preserves accepted page totals and weighted engagement');
        // Thousands of page children, and thousands of legacy detached pages, require cursor progress.
        $reset();$parent=$session('large-parent');$values=[];
        for($i=0;$i<4200;$i++)$values[]=$wpdb->prepare('(%d,%s,%s,0,1)',$i<2400?$parent:999997,'https://example.test/synthetic-large-history',$old);
        foreach(array_chunk($values,300)as$chunk)if($wpdb->query('INSERT INTO '.$t('pages').' (session_id,url,visited_at,report_pending,report_counted) VALUES '.implode(',',$chunk))===false)throw new RuntimeException('Large history fixture failed');
        $began=microtime(true);$runs=$finish();$observations['lifecycleBacklog']=['rows'=>4200,'passes'=>count($runs),'elapsedMs'=>round((microtime(true)-$began)*1000,1),'maxScanned'=>max(array_column($runs,'scanned')),'maxStatements'=>max(array_column($runs,'queries')),'maxDeleted'=>max(array_column($runs,'deleted'))];
        $assert((int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t('pages'))===0&&!$exists('sessions',$parent),'Native bounded passes drain heavy children and detached-page backlog');
        $assert(count($runs)>20&&!array_filter($runs,static fn($x)=>$x['scanned']>2200||$x['queries']>70||$x['deleted']>2200),'Every native backlog invocation retains its declared bounds');
        // Recheck a parent that changes after candidate pages are reached, before its lock is acquired.
        $reset();$sid=$session('changing-parent');$pid=$page($sid);$race=false;
        $fault=static function($q)use($wpdb,$t,$sid,&$race){if(!$race&&str_starts_with($q,'SELECT id,session_id,visited_at')){$race=true;$wpdb->update($t('sessions'),['last_activity'=>current_time('mysql'),'flag_status'=>'red'],['id'=>$sid]);}return$q;};add_filter('query',$fault,PHP_INT_MAX);$maintain();remove_filter('query',$fault,PHP_INT_MAX);$fault=null;
        $assert($race&&$exists('pages',$pid)&&$exists('sessions',$sid),'A newer active/red parent recheck preserves its previously selected children');
        $reset();$eid=$event('newly-referenced');$race=false;
        $fault=static function($q)use($wpdb,$t,$eid,$old,&$race){if(!$race&&str_starts_with($q,'SELECT id FROM '.$t('ai_queue').' WHERE event_id')){$race=true;$wpdb->insert($t('alerts'),['event_id'=>$eid,'alert_time'=>$old,'is_resolved'=>0]);}return$q;};add_filter('query',$fault,PHP_INT_MAX);$maintain();remove_filter('query',$fault,PHP_INT_MAX);$fault=null;
        $assert($race&&$exists('events',$eid),'A new native incident reference is rechecked before an eligible event can retire');
        // Refuse busy ownership, query failure, statement timeout, timezone drift and retry storms.
        $reset();$pid=$page();$lock='kiwe_stp_cleanup_'.substr(hash('sha256',$wpdb->dbname.':'.$wpdb->prefix),0,40);$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));$r=$maintain();$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$assert($r['status']==='busy'&&$exists('pages',$pid),'Busy ownership performs no history mutation');
        $fault=static fn($q)=>str_starts_with($q,'DELETE FROM '.stp_t('pages'))?'DELETE FROM '.stp_t('pages').' WHERE synthetic_missing_column=1':$q;add_filter('query',$fault,PHP_INT_MAX);$r=$maintain();remove_filter('query',$fault,PHP_INT_MAX);$fault=null;
        $assert($r['status']==='error'&&$exists('pages',$pid),'A native write failure rolls back disposal and cursors');$assert($maintain()['status']==='backoff','A failed native pass backs off before scanning history again');
        $wpdb->query('UPDATE '.$t('lifecycle_state').' SET next_at=0 WHERE id=1');$assert($maintain()['status']==='current'&&!$exists('pages',$pid),'A later owner resumes and retires the same source exactly once');
        $reset();$pid=$page();$killed=false;$beforeCursor=$wpdb->get_var('SELECT cursors FROM '.$t('lifecycle_state').' WHERE id=1');
        $fault=static function($q)use($wpdb,$t,$lock,&$killed){if(!$killed&&str_starts_with($q,'UPDATE '.$t('lifecycle_state').' SET cursors=')){$killed=true;$id=(int)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)',$lock));if(!$id||$wpdb->query('KILL CONNECTION '.$id)===false)throw new RuntimeException('Fixture connection loss unavailable');}return$q;};add_filter('query',$fault,PHP_INT_MAX);$r=$maintain();remove_filter('query',$fault,PHP_INT_MAX);$fault=null;
        $assert($killed&&$r['status']==='error'&&$r['deleted']===0&&$exists('pages',$pid)&&$wpdb->get_var('SELECT cursors FROM '.$t('lifecycle_state').' WHERE id=1')===$beforeCursor,'Real connection loss rolls back disposal and never replays a mutation on a fresh connection');
        $r=$maintain();if($r['status']==='busy'){usleep(100000);$r=$maintain();}$observations['lifecycleConnectionRecovery']=$r;
        $assert($r['status']==='current'&&!$exists('pages',$pid),'A fresh owner resumes the rolled-back native cleanup after connection loss');
        $reset();$pid=$page();$fault=static fn($q)=>str_starts_with($q,'SELECT id,session_id,visited_at')?'SELECT SLEEP(2)':$q;add_filter('query',$fault,PHP_INT_MAX);$began=microtime(true);$r=$maintain();$elapsed=microtime(true)-$began;remove_filter('query',$fault,PHP_INT_MAX);$fault=null;$observations['lifecycleTimeoutFixtureMs']=round($elapsed*1000,1);$observations['lifecycleTimeoutMs']=$observations['lastNativeWorkerMs']['lifecycle'];$assert($r['status']==='error'&&$observations['lifecycleTimeoutMs']<1500&&$exists('pages',$pid),'Native maintenance cancels an executing slow statement and preserves history');
        $wpdb->query("UPDATE ".$t('lifecycle_state')." SET timezone='UTC',next_at=0 WHERE id=1");$assert($maintain()['status']==='timezone'&&$exists('pages',$pid),'Website timezone drift holds native history instead of reinterpreting clocks');
        $settings['lifecycle_enabled']=0;update_option('stp_settings',$settings,false);$assert($maintain()['status']==='disabled'&&$exists('pages',$pid),'An explicit disabled maintenance choice performs no disposal');$settings['lifecycle_enabled']=1;update_option('stp_settings',$settings,false);
        // Actual cardinality and saturated daily observation evidence, through the existing counter owner.
        $reset();$maintain();$assert($life::reserve_visit(),'Healthy maintenance admits one optional visit through a native counter');$bucket=hash('sha256','kiwe-recorded-visits');$wpdb->query($wpdb->prepare('UPDATE '.$t('rate_limits').' SET counter=%d WHERE bucket_id=%s',$life::VISITS_PER_DAY,$bucket));
        $assert(!$life::reserve_visit()&&(int)$wpdb->get_var($wpdb->prepare('SELECT counter FROM '.$t('rate_limits').' WHERE bucket_id=%s',$bucket))===$life::VISITS_PER_DAY+1,'The daily native observation budget refuses and saturates at the hard cap');
        $from=$wall->modify('-7 days')->format('Y-m-d').' 00:00:00';$to=$wall->modify('+1 day')->format('Y-m-d').' 00:00:00';$assert($life::coverage_paused($from,$to),'Limited-day counter evidence makes affected reporting incomplete');
        $wpdb->query($wpdb->prepare('UPDATE '.$t('report_state').' SET coverage_start=%s,status=%s,updated_at=%s WHERE id=1',$from,'current',$now));$r=$reports::period($from,$to);$assert(!$r['coverage']['complete']&&$r['coverage']['status']==='observation_limited','Native Analytics never presents a capped observation period as complete');
        for($start=0;$start<50000;$start+=500){$chunk=[];for($i=$start;$i<$start+500;$i++)$chunk[]=$wpdb->prepare('(%s,%s,%s,0)','synthetic-cap-'.$i,$old,$old);if($wpdb->query('INSERT INTO '.$t('brain').' (feature_key,first_seen,last_seen,site_clock) VALUES '.implode(',',$chunk))===false)throw new RuntimeException('Native state capacity fixture failed');}
        $r=$maintain();$assert($r['status']==='capacity'&&!$life::optional_allowed('learning')&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t('brain'))===50000,'An actual 50000-feature store stops optional new learning and preserves uncertain legacy clocks');
        $pending=[];for($i=0;$i<1001;$i++)$pending[]=$wpdb->prepare('(%s,%s)','pending',$old);foreach(array_chunk($pending,250)as$chunk)$wpdb->query('INSERT INTO '.$t('ai_queue').' (status,created_at) VALUES '.implode(',',$chunk));$r=$maintain();
        $assert($r['status']==='capacity'&&!$life::optional_allowed('reviews')&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t('ai_queue'))===1001,'A real review backlog stops optional ingress without deleting any pending human decision');
        $budget=get_option('stp_lifecycle_budget');$budget['allowed']['subnets']=false;update_option('stp_lifecycle_budget',$budget,false);
        $settings['chain_detection']=1;update_option('stp_settings',$settings,false);stp_cfg(true);
        $wpdb->insert($t('ips'),['ip_address'=>'203.0.113.95','status'=>'unknown','first_seen'=>$now,'last_seen'=>$now]);$ip=(int)$wpdb->insert_id;
        for($i=0;$i<3;$i++)$event('login_failed',['ip_id'=>$ip,'created_at'=>current_time('mysql')]);
        $chain=stp_detect_chain('203.0.113.95',0,'login_success',['score'=>0]);
        $assert(($chain['type']??'')==='brute_then_success','Essential attack-chain detection survives a stopped optional network writer');
        $plan=$wpdb->get_results('EXPLAIN SELECT id FROM '.$t('pages').' WHERE id>100 ORDER BY id LIMIT 200',ARRAY_A);$assert(($plan[0]['key']??'')==='PRIMARY','Native retained-history cursor uses the primary index');$observations['lifecycleCursorPlan']=['key'=>$plan[0]['key'],'rows'=>(int)$plan[0]['rows']];
    }finally{
        if($fault)remove_filter('query',$fault,PHP_INT_MAX);
        $wpdb->query('DROP TABLE IF EXISTS '.$users);$wpdb->users=$oldUsers;
    }
}
