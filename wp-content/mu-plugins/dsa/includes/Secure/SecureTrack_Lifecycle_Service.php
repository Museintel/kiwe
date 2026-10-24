<?php
namespace DSA\Secure;
if (!defined('ABSPATH')) exit;

/** Bounded native history owner. No schema work or drain loops on visitor requests. */
final class SecureTrack_Lifecycle_Service {
    public const SCHEMA = '1';
    public const BATCH = 200;
    public const VISITS_PER_DAY = 20000;
    private const NAMES = ['alerts','ai_queue','events','pages','sessions','profiles','ips','subnets','brain','rate_limits','report_activity'];
    private static bool $running = false;
    private static array $ready = [];
    private static function table(string $name): string { return SecureTrack_Db_Service::table_name($name); }
    public static function enabled(): bool { return get_option('stp_lifecycle_schema') === self::SCHEMA; }
    public static function ready(): bool {
        global $wpdb;
        if (!self::enabled()) return false;
        return self::$ready[$wpdb->prefix] ??= SecureTrack_Db_Service::table_exists('lifecycle_state') && SecureTrack_Report_Service::ready();
    }
    public static function install(): void {
        global $wpdb;
        if (!SecureTrack_Db_Service::schema_write_allowed()) return;
        $cs=$wpdb->get_charset_collate();
        dbDelta('CREATE TABLE '.self::table('lifecycle_state')." (
 id tinyint unsigned NOT NULL,
 timezone varchar(100) NOT NULL,
 cursors text NOT NULL,
 updated_at datetime DEFAULT NULL,
 next_at bigint unsigned NOT NULL DEFAULT 0,
 failures smallint unsigned NOT NULL DEFAULT 0,
 status varchar(30) NOT NULL DEFAULT 'pending',
 scanned_rows bigint unsigned NOT NULL DEFAULT 0,
 deleted_rows bigint unsigned NOT NULL DEFAULT 0,
 refused_rows bigint unsigned NOT NULL DEFAULT 0,
 rounds bigint unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (id)
) ENGINE=InnoDB {$cs};");
        foreach (['events','pages','sessions','alerts','ips','subnets','brain'] as $name) SecureTrack_Db_Service::ensure_column($name,'retention_hold','TINYINT NOT NULL DEFAULT 0');
        foreach (['brain','subnets'] as $name) SecureTrack_Db_Service::ensure_column($name,'site_clock','TINYINT NOT NULL DEFAULT 0');
        foreach ([['alerts','idx_stp_alert_event','event_id'],['alerts','idx_stp_alert_user','user_id'],['rate_limits','idx_stp_bucket_window','bucket_id,window_start']] as [$name,$idx,$cols]) SecureTrack_Db_Service::ensure_index($name,$idx,"INDEX {$idx} ({$cols})");
        if (!SecureTrack_Db_Service::table_exists('lifecycle_state')) throw new \RuntimeException('Lifecycle state unavailable.');
        foreach (['events','pages','sessions','alerts','ips','subnets','brain'] as $name) {
            if (!$wpdb->get_var("SHOW COLUMNS FROM ".self::table($name)." LIKE 'retention_hold'")) throw new \RuntimeException('Lifecycle hold unavailable.');
        }
        foreach (['brain','subnets'] as $name) {
            if (!$wpdb->get_var("SHOW COLUMNS FROM ".self::table($name)." LIKE 'site_clock'")) throw new \RuntimeException('Lifecycle clock unavailable.');
        }
        foreach ([['alerts','idx_stp_alert_event','event_id'],['alerts','idx_stp_alert_user','user_id'],['rate_limits','idx_stp_bucket_window','bucket_id,window_start']] as [$name,$idx,$cols]) {
            if (!SecureTrack_Db_Service::equivalent_index_exists($name,"INDEX {$idx} ({$cols})")) throw new \RuntimeException('Lifecycle index unavailable.');
        }
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.self::table('lifecycle_state').' (id,timezone,cursors) VALUES (1,%s,%s)',wp_timezone_string(),'{}'));
        if ($wpdb->last_error || !$wpdb->get_var('SELECT id FROM '.self::table('lifecycle_state').' WHERE id=1')) throw new \RuntimeException('Lifecycle initialization unavailable.');
        update_option('stp_lifecycle_schema',self::SCHEMA,false);
    }
    /** Existing explicit zero-day choices remain authoritative. */
    public static function policy(): array {
        $c=(array)get_option('stp_settings',[]);
        $defaults=['green_trim_days'=>30,'yellow_trim_days'=>90,'page_trim_days'=>30,'inactive_state_days'=>185,'resolved_alert_days'=>185,'queue_trim_days'=>30,'lifecycle_enabled'=>1];
        foreach ($defaults as $k=>$v) $c[$k]=array_key_exists($k,$c)?max(0,(int)$c[$k]):$v;
        if (!array_key_exists('page_trim_days',(array)get_option('stp_settings',[]))) $c['page_trim_days']=$c['green_trim_days'];
        return $c;
    }
    /** The legacy settings form does not expose these lifecycle-only choices. */
    public static function preserve_settings(array $incoming,array $stored): array {
        return array_merge(array_intersect_key($stored,array_flip(['page_trim_days','inactive_state_days','resolved_alert_days','queue_trim_days','lifecycle_enabled'])),$incoming);
    }
    public static function old_clock(string $clock,int $days,?string $now=null): bool {
        if ($days<=0) return false;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$clock,wp_timezone());
        $wall=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$now??current_time('mysql'),wp_timezone());
        return $date && $wall && $date->format('Y-m-d H:i:s')===$clock && $date<$wall->modify('-'.$days.' days');
    }
    /** Published background health gates optional detail; essential security bypasses it. */
    public static function optional_allowed(string $kind): bool {
        if (!self::enabled()) return true; // Existing installations need explicit maintenance first.
        $b=(array)get_option('stp_lifecycle_budget',[]);
        return !empty($b['allowed'][$kind]) && (int)($b['asOf']??0)>time()-900;
    }
    public static function reserve_visit(): bool {
        if (!self::enabled()) return true;
        if (!self::optional_allowed('visits') || !function_exists('stp_rate_limit')) return false;
        return stp_rate_limit('kiwe-recorded-visits',self::VISITS_PER_DAY,DAY_IN_SECONDS,true);
    }
    public static function coverage_paused(string $from,string $to): bool {
        global $wpdb;
        if (!self::enabled()) return false;
        if (!self::optional_allowed('visits')) return true;
        $a=(new \DateTimeImmutable($from,wp_timezone()))->getTimestamp();$b=(new \DateTimeImmutable($to,wp_timezone()))->getTimestamp();
        $bucket=hash('sha256','kiwe-recorded-visits');
        $sql=$wpdb->prepare('SELECT id FROM '.self::table('rate_limits').' WHERE bucket_id=%s AND window_start > %d AND window_start < %d AND counter>%d LIMIT 1',$bucket,$a-DAY_IN_SECONDS,$b,self::VISITS_PER_DAY);
        if (stripos($wpdb->db_server_info(),'MariaDB')!==false) $sql='SET STATEMENT max_statement_time=0.5 FOR '.$sql;
        else $sql=preg_replace('/^SELECT/','SELECT /*+ MAX_EXECUTION_TIME(500) */',$sql);
        $id=$wpdb->get_var($sql);
        return (bool)$id || (bool)$wpdb->last_error;
    }
    public static function schedule(): void {
        global $wpdb;
        if (!self::enabled()) return;
        // Stable website-specific offsets avoid all tenants starting together.
        $offset=hexdec(substr(hash('sha256',$wpdb->dbname.'|'.$wpdb->prefix),0,4))%55;
        foreach (['stp_cron_reports'=>[60,'stp_1min'],'stp_cron_cleanup'=>[300,'stp_5min']] as $hook=>[$interval,$recurrence]) {
            $event=wp_get_scheduled_event($hook);
            if ($event && (int)$event->interval!==$interval) { wp_clear_scheduled_hook($hook);$event=false; }
            if (!$event) wp_schedule_event(time()+30+$offset+($hook==='stp_cron_cleanup'?90:0),$recurrence,$hook);
        }
    }
    public static function register(): void {
        add_filter('cron_schedules',static function($s){$s['stp_1min']=['interval'=>60,'display'=>'Every Minute'];$s['stp_5min']=['interval'=>300,'display'=>'Every Five Minutes'];return$s;});
        // These callbacks remain registered if the security engine is switched off.
        add_action('stp_cron_reports',[SecureTrack_Report_Service::class,'run']);
        add_action('stp_cron_cleanup',[self::class,'run']);
        add_action('admin_init',[self::class,'verify_schedule']);
    }
    public static function verify_schedule(): void {
        if (!current_user_can('manage_options') || !self::enabled() || get_transient('stp_lifecycle_cron_verified')) return;
        self::schedule();set_transient('stp_lifecycle_cron_verified',1,HOUR_IN_SECONDS);
    }
    /** An indexed PK cursor bounds examined candidates even when every old row is held. */
    public static function run(): array {
        global $wpdb;
        if (self::$running) return ['status'=>'busy','deleted'=>0];
        $policy=self::policy();
        if (empty($policy['lifecycle_enabled'])) return ['status'=>'disabled','deleted'=>0];
        if (!self::ready()) return ['status'=>'reports_unavailable','deleted'=>0];
        self::$running=true;$db=null;$held=false;$tx=false;$deleted=0;$scanned=0;$refused=0;$queries=0;$state=null;
        $lock='kiwe_stp_cleanup_'.substr(hash('sha256',$wpdb->dbname.':'.$wpdb->prefix),0,40);
        try {
            $db=new class(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST) extends \wpdb { public function check_connection($allow_bail=true){return false;} };
            $db->suppress_errors(true);
            if (!$db->ready) throw new \RuntimeException('connection');
            $held=(string)$db->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))==='1';
            if (!$held) return ['status'=>'busy','deleted'=>0];
            // Shared hosting acceptance is MariaDB. Refuse unbounded mutation on other engines.
            if (stripos($db->db_server_info(),'MariaDB')===false) throw new \RuntimeException('unsupported_database');
            if ($db->query('SET SESSION max_statement_time=0.5')===false || $db->query('SET SESSION innodb_lock_wait_timeout=1')===false) throw new \RuntimeException('query');
            $deadline=microtime(true)+2.0;
            $run=static function($sql,$method='query')use($db,&$queries,$deadline){
                if (++$queries>70 || microtime(true)>=$deadline) throw new \RuntimeException('budget');
                $v=in_array($method,['get_results','get_row'],true)?$db->$method($sql,ARRAY_A):$db->$method($sql);
                if ($v===false||$db->last_error) throw new \RuntimeException('query');return$v;
            };
            $slots=implode(',',array_fill(0,count(self::NAMES)+2,'%s'));
            $names=array_merge(self::NAMES,['lifecycle_state','report_state']);
            $engines=$run($wpdb->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$slots})",array_map([self::class,'table'],$names)),'get_results');
            if(count($engines)!==count($names)||array_filter($engines,static fn($r)=>strtoupper($r['ENGINE'])!=='INNODB'))throw new \RuntimeException('engine');
            $run('START TRANSACTION');$tx=true;
            $state=$run('SELECT * FROM '.self::table('lifecycle_state').' WHERE id=1 FOR UPDATE','get_row');
            if(!$state||$state['timezone']!==wp_timezone_string())throw new \RuntimeException('timezone');
            if((int)$state['next_at']>time()){ $run('ROLLBACK');$tx=false;return['status'=>'backoff','deleted'=>0]; }
            // Reporting takes this row first too: serialize raw projection and disposal.
            $reportState=$run('SELECT timezone FROM '.self::table('report_state').' WHERE id=1 FOR UPDATE','get_row');
            if(!$reportState||$reportState['timezone']!==wp_timezone_string())throw new \RuntimeException('timezone');
            $cursors=json_decode($state['cursors'],true);$cursors=is_array($cursors)?$cursors:[];$rounds=0;$more=false;
            $t=static fn($n)=>self::table($n);$users=$wpdb->users;
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$users))throw new \RuntimeException('users_table');
            $columns=[
                'alerts'=>'id,is_resolved,resolved_at,alert_time,retention_hold',
                'ai_queue'=>'id,status,created_at,reviewed_at,event_id',
                'events'=>'id,flag_status,reviewed,risk_score,created_at,report_pending,retention_hold',
                'pages'=>'id,session_id,visited_at,report_pending,retention_hold',
                'sessions'=>'id,last_activity,flag_status,report_pending,retention_hold',
                'profiles'=>'user_id,updated_at','ips'=>'id,status,risk_score,last_seen,ip_address,retention_hold',
                'subnets'=>'id,is_banned,threat_score,threat_level,last_seen,subnet,site_clock,retention_hold',
                'brain'=>'id,last_seen,last_score,risk_count,site_clock,retention_hold',
                'rate_limits'=>'id,expires_at','report_activity'=>'user_id,last_activity'];
            foreach(self::NAMES as$name){
                $key=in_array($name,['profiles','report_activity'],true)?'user_id':'id';$cursor=max(0,(int)($cursors[$name]??0));
                $rows=$run('SELECT '.$columns[$name].' FROM '.$t($name)." WHERE {$key}>{$cursor} ORDER BY {$key} LIMIT ".self::BATCH.' FOR UPDATE','get_results');
                $scanned+=count($rows);$cursors[$name]=$rows?(int)end($rows)[$key]:0;$more=$more||count($rows)===self::BATCH;
                if(count($rows)<self::BATCH){$cursors[$name]=0;$rounds++;}
                $ids=[];
                foreach($rows as$r){
                    if(!empty($r['retention_hold'])){$refused++;continue;}
                    $ok=false;
                    switch($name){
                        case'events':$days=$policy[$r['flag_status'].'_trim_days']??0;$ok=in_array($r['flag_status'],['green','yellow'],true)&&(int)$r['risk_score']<60&&($r['flag_status']==='green'||(int)$r['reviewed']===1)&&!(int)$r['report_pending']&&self::old_clock($r['created_at'],$days);break;
                        case'pages':$ok=!(int)$r['report_pending']&&self::old_clock($r['visited_at'],$policy['page_trim_days']);break;
                        case'sessions':$days=$r['flag_status']==='green'?($policy['page_trim_days']?max(14,$policy['page_trim_days']):0):($r['flag_status']==='yellow'?$policy['yellow_trim_days']:0);$ok=!(int)$r['report_pending']&&self::old_clock($r['last_activity'],$days);break;
                        case'alerts':$ok=(int)$r['is_resolved']===1&&self::old_clock((string)$r['resolved_at'],$policy['resolved_alert_days'])&&self::old_clock($r['alert_time'],$policy['resolved_alert_days']);break;
                        case'ai_queue':$ok=in_array($r['status'],['reviewed','error','local_only'],true)&&self::old_clock($r['created_at'],$policy['queue_trim_days'])&&(!$r['reviewed_at']||self::old_clock($r['reviewed_at'],$policy['queue_trim_days']));break;
                        case'ips':$ok=in_array($r['status'],['unknown','clean'],true)&&(int)$r['risk_score']<25&&self::old_clock($r['last_seen'],$policy['inactive_state_days']);break;
                        case'subnets':$ok=(int)$r['site_clock']===1&&!(int)$r['is_banned']&&(int)$r['threat_score']<25&&in_array($r['threat_level'],['low','safe'],true)&&str_ends_with($r['subnet'],'/24')&&filter_var(substr($r['subnet'],0,-3),FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)&&self::old_clock($r['last_seen'],$policy['inactive_state_days']);break;
                        case'brain':$ok=(int)$r['site_clock']===1&&(int)$r['last_score']<25&&(int)$r['risk_count']===0&&self::old_clock($r['last_seen'],$policy['inactive_state_days']);break;
                        case'rate_limits':$ok=(int)$r['expires_at']<time();break;
                        case'profiles':case'report_activity':$ok=true;break;
                    }
                    if($ok)$ids[]=(int)$r[$key];else$refused++;
                }
                if(!$ids)continue;$list=implode(',',$ids);$guard='';
                // Lock referenced parents before child deletion. Their risk/activity can change concurrently.
                if($name==='pages'){
                    $parents=$run('SELECT id,flag_status,last_activity,retention_hold,report_pending FROM '.$t('sessions')." WHERE id IN (SELECT session_id FROM ".$t('pages')." WHERE id IN ({$list})) FOR UPDATE",'get_results');
                    $blocked=[];foreach($parents as$p)if($p['flag_status']==='red'||(int)$p['retention_hold']||(int)$p['report_pending']||!self::old_clock($p['last_activity'],$policy['page_trim_days']))$blocked[]=(int)$p['id'];
                    if($blocked)$guard.=' AND session_id NOT IN ('.implode(',',$blocked).')';
                    $guard.=' AND report_pending=0 AND retention_hold=0';
                    $guard.=' AND NOT EXISTS (SELECT 1 FROM '.$t('events').' e WHERE e.session_id='.$t('pages').".session_id AND (e.flag_status='red' OR e.retention_hold<>0 OR (e.flag_status='yellow' AND e.reviewed=0)))";
                }
                if($name==='events'){
                    $run('SELECT id FROM '.$t('ai_queue')." WHERE event_id IN ({$list}) AND status IN ('pending','processing') LIMIT 200 FOR UPDATE",'get_col');
                    $run('SELECT id FROM '.$t('alerts')." WHERE event_id IN ({$list}) LIMIT 200 FOR UPDATE",'get_col');
                    $guard.=' AND NOT EXISTS (SELECT 1 FROM '.$t('ai_queue').' q WHERE q.event_id='.$t('events').".id AND q.status IN ('pending','processing')) AND NOT EXISTS (SELECT 1 FROM ".$t('alerts').' a WHERE a.event_id='.$t('events').'.id)';
                }
                if($name==='sessions'){
                    $run('SELECT id FROM '.$t('pages')." WHERE session_id IN ({$list}) LIMIT 200 FOR UPDATE",'get_col');
                    $run('SELECT id FROM '.$t('events')." WHERE session_id IN ({$list}) LIMIT 200 FOR UPDATE",'get_col');
                    $guard.=' AND NOT EXISTS (SELECT 1 FROM '.$t('pages').' p WHERE p.session_id='.$t('sessions').'.id) AND NOT EXISTS (SELECT 1 FROM '.$t('events').' e WHERE e.session_id='.$t('sessions').'.id)';
                }
                if(in_array($name,['profiles','report_activity'],true)){
                    $run("SELECT ID FROM {$users} WHERE ID IN ({$list}) FOR UPDATE",'get_col');
                    $guard.=" AND NOT EXISTS (SELECT 1 FROM {$users} u WHERE u.ID=".$t($name).'.user_id) AND NOT EXISTS (SELECT 1 FROM '.$t('alerts').' a WHERE a.user_id='.$t($name).'.user_id)';
                }
                if($name==='ips'){
                    foreach(['events','sessions']as$child)$run('SELECT id FROM '.$t($child)." WHERE ip_id IN ({$list}) LIMIT 200 FOR UPDATE",'get_col');
                    $run('SELECT id FROM '.$t('alerts').' WHERE ip_address IN (SELECT ip_address FROM '.$t('ips')." WHERE id IN ({$list})) LIMIT 200 FOR UPDATE",'get_col');
                    $guard.=' AND NOT EXISTS (SELECT 1 FROM '.$t('events').' e WHERE e.ip_id='.$t('ips').'.id) AND NOT EXISTS (SELECT 1 FROM '.$t('sessions').' s WHERE s.ip_id='.$t('ips').'.id) AND NOT EXISTS (SELECT 1 FROM '.$t('alerts').' a WHERE a.ip_address='.$t('ips').'.ip_address)';
                    // Legacy JSON trust remains its native owner's authority. Ambiguous data holds.
                    // This SQL is statement-limited; no profile body is loaded into PHP.
                    $guard.=' AND NOT EXISTS (SELECT 1 FROM '.$t('profiles').' p WHERE p.last_ip='.$t('ips').".ip_address OR (p.trusted_ips IS NOT NULL AND p.trusted_ips<>'' AND CASE WHEN JSON_VALID(p.trusted_ips) THEN CASE WHEN JSON_TYPE(p.trusted_ips)='ARRAY' THEN COALESCE(JSON_CONTAINS(p.trusted_ips,JSON_QUOTE(".$t('ips').'.ip_address)),1) ELSE 1 END ELSE 1 END))';
                }
                if($name==='subnets') {
                    $guard.=' AND NOT EXISTS (SELECT 1 FROM '.$t('alerts').' a WHERE a.subnet_24='.$t('subnets').'.subnet)';
                    // IPv4 /24 is this native owner's only subnet type. Preserve every remaining
                    // address in it, including trusted, blocked, held or still referenced records.
                    $guard.=' AND NOT EXISTS (SELECT 1 FROM '.$t('ips').' i WHERE INET_ATON(i.ip_address) IS NOT NULL AND (INET_ATON(i.ip_address) >> 8)=(INET_ATON(SUBSTRING_INDEX('.$t('subnets').".subnet,'/',1)) >> 8))";
                }
                $run('DELETE FROM '.$t($name)." WHERE {$key} IN ({$list}){$guard} LIMIT ".self::BATCH);$removed=(int)$db->rows_affected;$deleted+=$removed;
                if($name==='report_activity'&&$removed)$run('UPDATE '.$t('report_state')." SET activity_rows=GREATEST(0,activity_rows-{$removed}),generation=generation+1 WHERE id=1");
            }
            $allowed=[];
            foreach(['visits'=>['pages',100000],'learning'=>['brain',50000],'subnets'=>['subnets',50000],'reviews'=>['ai_queue',1000]]as$kind=>[$name,$cap]){
                $count=(int)$run('SELECT COUNT(*) FROM (SELECT id FROM '.$t($name).' ORDER BY id LIMIT '.($cap+1).') kiwe_bounded_count','get_var');$allowed[$kind]=$count<$cap;
            }
            $status=array_filter($allowed,static fn($a)=>!$a)?'capacity':($more?'catching_up':'current');
            $priorBudget=(array)get_option('stp_lifecycle_budget',[]);
            if(isset($priorBudget['allowed']['visits']) && (bool)$priorBudget['allowed']['visits']!==$allowed['visits'])
                $run($wpdb->prepare('UPDATE '.$t('report_state').' SET coverage_start=%s,generation=generation+1,status=%s WHERE id=1',current_time('mysql'),'pending'));
            $run($wpdb->prepare('UPDATE '.$t('lifecycle_state').' SET cursors=%s,updated_at=%s,next_at=0,failures=0,status=%s,scanned_rows=scanned_rows+%d,deleted_rows=deleted_rows+%d,refused_rows=refused_rows+%d,rounds=rounds+%d WHERE id=1',wp_json_encode($cursors),gmdate('Y-m-d H:i:s'),$status,$scanned,$deleted,$refused,$rounds));
            $run('COMMIT');$tx=false;
            update_option('stp_lifecycle_budget',['asOf'=>time(),'allowed'=>$allowed,'status'=>$status],false);
            return['status'=>$status,'deleted'=>$deleted,'scanned'=>$scanned,'refused'=>$refused,'queries'=>$queries,'rounds'=>$rounds];
        }catch(\Throwable$e){
            if($tx&&$db)$db->query('ROLLBACK');
            $status=in_array($e->getMessage(),['budget','engine','timezone','unsupported_database','connection'],true)?$e->getMessage():'error';
            if($held&&$db&&$state){$fail=min(8,(int)$state['failures']+1);$until=time()+min(3600,60*(2**$fail));$db->query($wpdb->prepare('UPDATE '.self::table('lifecycle_state').' SET failures=%d,next_at=%d,status=%s WHERE id=1',$fail,$until,$status));}
            if($held)update_option('stp_lifecycle_budget',['asOf'=>time(),'allowed'=>[],'status'=>$status],false);
            $result=['status'=>$status,'deleted'=>0,'scanned'=>$scanned,'queries'=>$queries];
            if($status==='connection')$result['connectionError']=mysqli_connect_errno();
            return$result;
        }finally{
            if($held&&$db)$db->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
            if($db)$db->close();self::$running=false;
        }
    }
}
