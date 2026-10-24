<?php
namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! class_exists( \DSA\Analytics\Restored_Reporting_State::class, false ) ) require_once dirname(__DIR__).'/Analytics/Restored_Reporting_State.php';

/** Derived SecureTrack reports. Raw evidence, enforcement and identity remain native owners. */
final class SecureTrack_Report_Service {
	public const SCHEMA = '1';
	public const TABLES = [ 'report_days', 'report_sessions', 'report_activity', 'report_state' ];
	public const DAY_LIMIT = 50000;
	public const SESSION_LIMIT = 100000;
	public const ACTIVITY_LIMIT = 50000;
	public const HISTORY_DAYS = 185;
	private const BATCH = 100;
	private static array $ready = [];

	public static function runtime_loaded(): bool { return function_exists('stp_cfg'); }
	public static function runtime_config(): array { return self::runtime_loaded() ? (array) stp_cfg() : []; }
	public static function start_visit_recording(): bool {
		if ( ! self::runtime_loaded() || ! \DSA\Access\Analytics_Reporting_Setup_Service::maintenance_authorized() ) return false;
		$config=stp_cfg();$config['track_visitors']=1;$config['track_pages']=1;
		update_option('stp_settings',SecureTrack_Settings_Policy::normalize_runtime_config($config),false);
		wp_cache_delete('settings','securetrack_pro');stp_cfg(true);
		return !empty(stp_cfg()['track_visitors']) && !empty(stp_cfg()['track_pages']);
	}

	/** Cheap marker only; never probes metadata or creates schema on an ingestion request. */
	public static function enabled(): bool { return self::SCHEMA === get_option( 'stp_report_schema', '' ) && ! \DSA\Analytics\Restored_Reporting_State::blocks( 'secure' ); }
	public static function ready(): bool {
		global $wpdb;
		if ( ! self::enabled() ) return false;
		if ( isset( self::$ready[ $wpdb->prefix ] ) ) return self::$ready[ $wpdb->prefix ];
		foreach ( self::TABLES as $name ) if ( ! SecureTrack_Db_Service::table_exists( $name ) ) return self::$ready[ $wpdb->prefix ] = false;
		return self::$ready[ $wpdb->prefix ] = true;
	}
	public static function reset_readiness(): void { self::$ready = []; }
	private static function table( string $name ): string { return SecureTrack_Db_Service::table_name( $name ); }

	/** Called only inside the existing authorized, connection-locked schema maintenance. */
	public static function install(): void {
		global $wpdb;
		if ( ! SecureTrack_Db_Service::schema_write_allowed() ) return;
		if ( \DSA\Analytics\Restored_Reporting_State::blocks( 'secure' ) ) return;
		$cs = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table( 'report_days' ) . " (
 day date NOT NULL,
 scope_hash char(64) NOT NULL,
 views bigint unsigned NOT NULL DEFAULT 0,
 seconds bigint NOT NULL DEFAULT 0,
 timed_views bigint NOT NULL DEFAULT 0,
 events bigint unsigned NOT NULL DEFAULT 0,
 login_failures bigint unsigned NOT NULL DEFAULT 0,
 protections bigint unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (scope_hash,day),
 KEY idx_day (day)
) ENGINE=InnoDB {$cs};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'report_sessions' ) . " (
 day date NOT NULL,
 scope_hash char(64) NOT NULL,
 session_id bigint unsigned NOT NULL,
 PRIMARY KEY  (scope_hash,day,session_id),
 KEY idx_day (day)
) ENGINE=InnoDB {$cs};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'report_activity' ) . " (
 user_id bigint unsigned NOT NULL,
 last_activity datetime NOT NULL,
 PRIMARY KEY  (user_id)
) ENGINE=InnoDB {$cs};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'report_state' ) . " (
 id tinyint unsigned NOT NULL,
 timezone varchar(100) NOT NULL,
 coverage_start datetime NOT NULL,
 updated_at datetime DEFAULT NULL,
 generation bigint unsigned NOT NULL DEFAULT 0,
 day_rows int unsigned NOT NULL DEFAULT 0,
 session_rows int unsigned NOT NULL DEFAULT 0,
 activity_rows int unsigned NOT NULL DEFAULT 0,
 pages_pending tinyint NOT NULL DEFAULT 1,
 events_pending tinyint NOT NULL DEFAULT 1,
 sessions_pending tinyint NOT NULL DEFAULT 1,
 rejected_rows bigint unsigned NOT NULL DEFAULT 0,
 status varchar(24) NOT NULL DEFAULT 'pending',
 PRIMARY KEY  (id)
) ENGINE=InnoDB {$cs};" );
		foreach ( [ 'pages','events','sessions' ] as $name ) {
			SecureTrack_Db_Service::ensure_column( $name, 'report_pending', 'TINYINT NOT NULL DEFAULT 1' );
			SecureTrack_Db_Service::ensure_index( $name, 'idx_stp_report_pending', 'INDEX idx_stp_report_pending (report_pending,id)' );
		}
		SecureTrack_Db_Service::ensure_column( 'pages', 'report_counted', 'TINYINT NOT NULL DEFAULT 0' );
		SecureTrack_Db_Service::ensure_column( 'pages', 'report_seconds', 'INT NOT NULL DEFAULT 0' );
		SecureTrack_Db_Service::ensure_column( 'events', 'actor_id', 'BIGINT UNSIGNED NOT NULL DEFAULT 0' );
		self::reset_readiness();
		foreach ( self::TABLES as $name ) if ( ! SecureTrack_Db_Service::table_exists( $name ) ) throw new \RuntimeException( 'Report schema unavailable.' );
		foreach ( [ 'pages' => [ 'report_pending','report_counted','report_seconds' ], 'events' => [ 'report_pending','actor_id' ], 'sessions' => [ 'report_pending' ] ] as $name => $columns ) {
			foreach ( $columns as $column ) if ( ! SecureTrack_Db_Service::column_exists( $name, $column ) ) throw new \RuntimeException( 'Report source columns unavailable.' );
		}
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table( 'report_state' ) . ' (id,timezone,coverage_start) VALUES (1,%s,%s)', wp_timezone_string(), current_time( 'mysql' ) ) );
		if ( $wpdb->last_error || ! $wpdb->get_var( 'SELECT id FROM ' . self::table( 'report_state' ) . ' WHERE id=1' ) ) throw new \RuntimeException( 'Report state unavailable.' );
		update_option( 'stp_report_schema', self::SCHEMA, false );
	}

	/** The same canonical/query-variant boundary as the former raw report, without storing URLs. */
	public static function scope_hash( string $url ): string {
		$parts=explode('?',substr($url,0,1000),2);$query=[];
		if (isset($parts[1])) parse_str($parts[1],$query);
		// Plain WordPress/Woo permalinks use query variables to identify the record.
		$route=array_intersect_key($query,array_flip(['p','page_id','attachment_id','post_type','name','pagename','product']));
		$route=array_filter($route,static fn($v)=>is_string($v));ksort($route);
		return hash('sha256',$parts[0].($route?'?'.http_build_query($route,'','&',PHP_QUERY_RFC3986):''));
	}
	public static function site_hash(): string { return str_repeat( '0', 64 ); }
	private static function valid_clock( string $clock ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $clock, wp_timezone() );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $clock;
	}
	/** MariaDB bounds all statements; MySQL bounds reporting SELECTs and short InnoDB waits. */
	private static function bounded_sql( string $sql ): string {
		global $wpdb;
		$info = $wpdb->db_server_info();
		if ( stripos( $info, 'MariaDB' ) !== false && preg_match('/^SELECT\b/', $sql) ) return 'SET STATEMENT max_statement_time=0.5 FOR ' . $sql;
		return preg_replace( '/^SELECT\b/', 'SELECT /*+ MAX_EXECUTION_TIME(500) */', $sql );
	}
	private static function read( string $sql, string $method = 'get_results' ) {
		global $wpdb;
		$value = $method === 'get_results' || $method === 'get_row' ? $wpdb->$method( self::bounded_sql( $sql ), ARRAY_A ) : $wpdb->$method( self::bounded_sql( $sql ) );
		if ( $wpdb->last_error ) throw new \RuntimeException( 'Report query unavailable.' );
		return $value;
	}

	/** One short transaction: source markers and derived totals commit or roll back together. */
	public static function run(): array {
		global $wpdb;
		if ( ! self::ready() ) return [ 'status'=>'unavailable','reason'=>'schema','processed'=>0 ];
		// WordPress retries a query after a lost connection. A transaction must instead
		// fail closed: never replay one of its writes outside the lost transaction.
		$db = new class(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST) extends \wpdb {
			public function check_connection($allow_bail=true) { return false; }
		};
		$db->suppress_errors(true);
		if (!$db->ready) return ['status'=>'unavailable','reason'=>'connection','connectionError'=>mysqli_connect_errno(),'processed'=>0];
		$lock = 'kiwe_stp_report_' . substr( hash( 'sha256', $wpdb->dbname . ':' . $wpdb->prefix ), 0, 40 );
		$held = false; $transaction = false; $wait = null; $statementTime = null; $queries = 0; $processed = 0;
		$deadline = microtime( true ) + 2.0;
		$run = static function ( string $sql, string $method = 'query' ) use ( $db, &$queries, $deadline ) {
			if ( ++$queries > 45 || microtime( true ) >= $deadline ) throw new \RuntimeException( 'budget' );
			$sql = self::bounded_sql( $sql );
			$value = in_array( $method, [ 'get_results','get_row' ], true ) ? $db->$method( $sql, ARRAY_A ) : $db->$method( $sql );
			if ( $value === false || $db->last_error ) throw new \RuntimeException( 'query' );
			return $value;
		};
		try {
			$held = (string) $db->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) === '1';
			if ( ! $held ) return [ 'status'=>'busy','processed'=>0 ];
			// Refuse nontransactional storage. A marker must never commit independently of its total.
			$names = array_merge( self::TABLES, [ 'pages','events','sessions' ] );
			$slots = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			$engines = $run( $wpdb->prepare( "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$slots})", array_map( [ self::class, 'table' ], $names ) ), 'get_results' );
			if ( count( $engines ) !== count( $names ) || array_filter( $engines, static fn( $r ) => strtoupper( $r['ENGINE'] ) !== 'INNODB' ) ) throw new \RuntimeException( 'engine' );
			$wait = $db->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
			if ( $db->query( 'SET SESSION innodb_lock_wait_timeout=1' ) === false ) throw new \RuntimeException( 'query' );
			if ( stripos($wpdb->db_server_info(),'MariaDB') !== false ) {
				$statementTime=$db->get_var('SELECT @@SESSION.max_statement_time');
				if ($db->query('SET SESSION max_statement_time=0.5')===false) throw new \RuntimeException('query');
			}
			if ( $db->query( 'START TRANSACTION' ) === false ) throw new \RuntimeException( 'query' );
			$transaction = true;
			$state = $run( 'SELECT * FROM ' . self::table( 'report_state' ) . ' WHERE id=1 FOR UPDATE', 'get_row' );
			if ( ! $state || $state['timezone'] !== wp_timezone_string() ) throw new \RuntimeException( 'timezone' );
			$cut = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . self::HISTORY_DAYS . ' days' )->format( 'Y-m-d' );
			foreach ( [ 'report_days'=>'day_rows','report_sessions'=>'session_rows' ] as $name=>$counter ) {
				$run( $wpdb->prepare( 'DELETE FROM ' . self::table( $name ) . ' WHERE day < %s ORDER BY day LIMIT 500', $cut ) );
				$state[ $counter ] = max( 0, (int) $state[ $counter ] - (int) $db->rows_affected );
			}
			$days = []; $members = []; $activity = []; $sources = [];
			$day = static function ( $date, $scope ) use ( &$days ) {
				$key = $scope . '|' . $date;
				if ( ! isset( $days[ $key ] ) ) $days[ $key ] = [ 'day'=>$date,'scope'=>$scope,'views'=>0,'seconds'=>0,'timed_views'=>0,'events'=>0,'login_failures'=>0,'protections'=>0 ];
				return $key;
			};
			$touch = static function ( $id, $clock ) use ( &$activity ) { if ( $id > 0 && self::valid_clock( $clock ) ) $activity[ $id ] = max( $activity[ $id ] ?? '', $clock ); };
			foreach ( [ 'pages','events','sessions' ] as $name ) {
				$columns = [ 'pages'=>'id,session_id,url,time_spent,visited_at,report_seconds,report_counted', 'events'=>'id,actor_id,event_type,created_at', 'sessions'=>'id,user_id,last_activity' ];
				$sources[ $name ] = $run( 'SELECT ' . $columns[$name] . ' FROM ' . self::table( $name ) . ' WHERE report_pending=1 ORDER BY id LIMIT ' . self::BATCH . ' FOR UPDATE', 'get_results' );
				foreach ( $sources[ $name ] as $row ) {
					$clock = (string) $row[ $name === 'pages' ? 'visited_at' : ( $name === 'events' ? 'created_at' : 'last_activity' ) ];
					if ( ! self::valid_clock( $clock ) || $clock > current_time( 'mysql' ) ) { $state['rejected_rows']++; continue; }
					if ( $name === 'sessions' ) { $touch( (int) $row['user_id'], $clock ); continue; }
					if ( $name === 'events' ) $touch( (int) $row['actor_id'], $clock );
					$date = substr( $clock, 0, 10 );
					if ( $date < $cut ) continue;
					if ( $name === 'events' ) {
						$key = $day( $date, self::site_hash() ); $days[ $key ]['events']++;
						$days[ $key ]['login_failures'] += (int) ( $row['event_type'] === 'login_failed' );
						$days[ $key ]['protections'] += (int) in_array( $row['event_type'], [ 'protection_block','waf_block','rest_abuse' ], true );
						continue;
					}
					$seconds = (int) $row['time_spent']; $prior = (int) $row['report_seconds']; $counted = (int) $row['report_counted'];
					foreach ( array_unique( [ self::site_hash(), self::scope_hash( (string) $row['url'] ) ] ) as $scope ) {
						$key = $day( $date, $scope );
						$days[ $key ]['views'] += $counted ? 0 : 1;
						$days[ $key ]['seconds'] += max( 0, $seconds ) - max( 0, $prior );
						$days[ $key ]['timed_views'] += (int) ( $seconds > 0 ) - (int) ( $prior > 0 );
						if ( ! $counted && (int) $row['session_id'] > 0 ) $members[ $key . '|' . $row['session_id'] ] = [ $date, $scope, (int) $row['session_id'] ];
					}
				}
			}
			// At most 300 source rows, 300 daily keys, 200 memberships and 200 account keys per pass.
			$rows = $run( 'SELECT day,scope_hash FROM ' . self::table( 'report_days' ) . ' WHERE ' . self::key_conditions( $days, 'days' ), 'get_results' );
			$existing = []; foreach ( $rows as $r ) $existing[ $r['scope_hash'] . '|' . $r['day'] ] = true;
			$newDays = count( array_diff_key( $days, $existing ) );
			$rows = $run( 'SELECT day,scope_hash,session_id FROM ' . self::table( 'report_sessions' ) . ' WHERE ' . self::key_conditions( $members, 'members' ), 'get_results' );
			$existing = []; foreach ( $rows as $r ) $existing[ $r['scope_hash'] . '|' . $r['day'] . '|' . $r['session_id'] ] = true;
			$newMembers = count( array_diff_key( $members, $existing ) );
			$ids = $activity ? implode( ',', array_map( 'intval', array_keys( $activity ) ) ) : '0';
			$existingUsers = $run( 'SELECT user_id FROM ' . self::table( 'report_activity' ) . " WHERE user_id IN ({$ids})", 'get_col' );
			$newUsers = count( array_diff( array_keys( $activity ), array_map( 'intval', $existingUsers ) ) );
			if ( (int) $state['day_rows'] + $newDays > self::DAY_LIMIT || (int) $state['session_rows'] + $newMembers > self::SESSION_LIMIT || (int) $state['activity_rows'] + $newUsers > self::ACTIVITY_LIMIT ) throw new \RuntimeException( 'capacity' );
			if ( $days ) {
				$values = []; foreach ( $days as $r ) $values[] = $wpdb->prepare( '(%s,%s,%d,%d,%d,%d,%d,%d)', $r['day'],$r['scope'],$r['views'],$r['seconds'],$r['timed_views'],$r['events'],$r['login_failures'],$r['protections'] );
				$run( 'INSERT INTO ' . self::table( 'report_days' ) . ' (day,scope_hash,views,seconds,timed_views,events,login_failures,protections) VALUES ' . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE views=views+VALUES(views),seconds=seconds+VALUES(seconds),timed_views=timed_views+VALUES(timed_views),events=events+VALUES(events),login_failures=login_failures+VALUES(login_failures),protections=protections+VALUES(protections)' );
			}
			if ( $members ) {
				$values = []; foreach ( $members as $r ) $values[] = $wpdb->prepare( '(%s,%s,%d)', ...$r );
				$run( 'INSERT IGNORE INTO ' . self::table( 'report_sessions' ) . ' (day,scope_hash,session_id) VALUES ' . implode( ',', $values ) );
			}
			if ( $activity ) {
				$values = []; foreach ( $activity as $id=>$clock ) $values[] = $wpdb->prepare( '(%d,%s)', $id, $clock );
				$run( 'INSERT INTO ' . self::table( 'report_activity' ) . ' (user_id,last_activity) VALUES ' . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE last_activity=GREATEST(last_activity,VALUES(last_activity))' );
			}
			foreach ( $sources as $name=>$rows ) {
				if ( ! $rows ) continue;
				$ids = implode( ',', array_map( static fn( $r ) => (int) $r['id'], $rows ) );
				$assign = $name === 'pages' ? ',report_counted=1,report_seconds=time_spent' : '';
				$run( 'UPDATE ' . self::table( $name ) . " SET report_pending=0{$assign} WHERE id IN ({$ids})" );
				$processed += count( $rows );
			}
			foreach ( [ 'pages','events','sessions' ] as $name ) $state[ $name . '_pending' ] = (int) (bool) $run( 'SELECT id FROM ' . self::table( $name ) . ' WHERE report_pending=1 ORDER BY id LIMIT 1', 'get_var' );
			$status = $state['pages_pending'] || $state['events_pending'] || $state['sessions_pending'] ? 'catching_up' : 'current';
			$run( $wpdb->prepare( 'UPDATE ' . self::table( 'report_state' ) . ' SET generation=generation+1,updated_at=%s,day_rows=%d,session_rows=%d,activity_rows=%d,pages_pending=%d,events_pending=%d,sessions_pending=%d,rejected_rows=%d,status=%s WHERE id=1', current_time('mysql'), (int)$state['day_rows']+$newDays, (int)$state['session_rows']+$newMembers, (int)$state['activity_rows']+$newUsers, $state['pages_pending'],$state['events_pending'],$state['sessions_pending'],$state['rejected_rows'],$status ) );
			$run( 'COMMIT' ); $transaction = false;
			return [ 'status'=>$status,'processed'=>$processed,'queries'=>$queries ];
		} catch ( \Throwable $e ) {
			if ( $transaction ) { $db->query( 'ROLLBACK' ); $transaction = false; }
			$status = in_array( $e->getMessage(), [ 'capacity','timezone','engine','budget' ], true ) ? $e->getMessage() : 'error';
			// This bounded health marker cannot turn a failed transaction into accepted totals.
			if ( $held ) $db->query( self::bounded_sql( $wpdb->prepare( 'UPDATE ' . self::table( 'report_state' ) . ' SET status=%s,generation=generation+1 WHERE id=1', $status ) ) );
			return [ 'status'=>$status,'processed'=>0,'queries'=>$queries ];
		} finally {
			if ( $transaction ) $db->query( 'ROLLBACK' );
			if ( $wait !== null ) $db->query( 'SET SESSION innodb_lock_wait_timeout=' . max( 1, (int) $wait ) );
			if ( $statementTime !== null ) $db->query( 'SET SESSION max_statement_time=' . max(0,(float)$statementTime) );
			if ( $held ) $db->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$db->close();
		}
	}
	private static function key_conditions( array $rows, string $kind ): string {
		global $wpdb;
		$conditions = [];
		foreach ( $rows as $r ) $conditions[] = $kind === 'days' ? $wpdb->prepare( '(scope_hash=%s AND day=%s)', $r['scope'], $r['day'] ) : $wpdb->prepare( '(scope_hash=%s AND day=%s AND session_id=%d)', $r[1],$r[0],$r[2] );
		return $conditions ? implode( ' OR ', $conditions ) : '1=0';
	}

	/** Read indexed, capped summaries; never drain history or invoke AI/provider work here. */
	public static function period( string $from, string $to, string $url = '', string $kind = 'traffic' ): ?array {
		global $wpdb;
		if ( ! self::ready() || ! self::valid_clock( $from ) || ! self::valid_clock( $to ) || $from >= $to || substr($from,11) !== '00:00:00' || substr($to,11) !== '00:00:00' ) return null;
		try {
			$state = self::read( 'SELECT * FROM ' . self::table( 'report_state' ) . ' WHERE id=1', 'get_row' );
			if ( ! $state || $state['timezone'] !== wp_timezone_string() ) return null;
			$pending = (bool) self::read('SELECT id FROM '.self::table($kind==='traffic'?'pages':'events').' WHERE report_pending=1 ORDER BY id LIMIT 1','get_var');
			$paused=$kind==='traffic' && class_exists(SecureTrack_Lifecycle_Service::class) && SecureTrack_Lifecycle_Service::coverage_paused($from,$to);
			$scope = $url ? self::scope_hash( $url ) : self::site_hash();
			$key = hash( 'sha256', $wpdb->prefix . '|' . $state['generation'] . '|' . $from . '|' . $to . '|' . $scope . '|' . $kind . '|' . (int)$pending . '|' . (int)$paused );
			$cached = wp_cache_get( $key, 'kiwe_stp_reports' ); if ( is_array( $cached ) ) return $cached;
			$row = self::read( $wpdb->prepare( 'SELECT COALESCE(SUM(views),0) views,COALESCE(SUM(seconds),0) seconds,COALESCE(SUM(timed_views),0) timed_views,COALESCE(SUM(events),0) events,COALESCE(SUM(login_failures),0) login_failures,COALESCE(SUM(protections),0) protections FROM ' . self::table('report_days') . ' WHERE scope_hash=%s AND day >= %s AND day < %s', $scope, substr($from,0,10),substr($to,0,10) ), 'get_row' );
			if ( ! is_array( $row ) ) return null;
			$sessions = $kind === 'traffic' ? (int) self::read( $wpdb->prepare( 'SELECT COUNT(DISTINCT session_id) FROM ' . self::table('report_sessions') . ' WHERE scope_hash=%s AND day >= %s AND day < %s', $scope, substr($from,0,10),substr($to,0,10) ), 'get_var' ) : 0;
			$cut = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify('-'.self::HISTORY_DAYS.' days')->format('Y-m-d H:i:s');
			$fresh = $state['updated_at'] && strtotime($state['updated_at'].' UTC') >= strtotime(current_time('mysql').' UTC')-600;
			$complete = $from >= max( $state['coverage_start'], $cut ) && !$pending && !$paused && !$state['rejected_rows'] && $state['status']==='current' && $fresh;
			$status = $paused?'observation_limited':($pending?'catching_up':(!$fresh?'stale':($complete?'recorded':($state['status']==='current'?'partial_history':$state['status']))));
			$out = [ 'views'=>(int)$row['views'],'sessions'=>$sessions,'engagedSeconds'=>$row['timed_views']>0?round((float)$row['seconds']/(int)$row['timed_views'],1):null, 'events'=>(int)$row['events'],'login_failures'=>(int)$row['login_failures'],'protections'=>(int)$row['protections'], 'coverage'=>[ 'complete'=>$complete,'status'=>$status, 'from'=>$state['coverage_start'],'updatedAt'=>$state['updated_at'],'pending'=>$pending,'retainedDays'=>self::HISTORY_DAYS ] ];
			wp_cache_set( $key, $out, 'kiwe_stp_reports', 60 );
			return $out;
		} catch ( \Throwable $e ) { return null; }
	}
	public static function activity( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( !$ids || count($ids)>100 || !self::ready() ) return [];
		try {
			$rows = self::read( 'SELECT user_id,last_activity FROM '.self::table('report_activity').' WHERE user_id IN ('.implode(',',$ids).')' );
			$out=[]; foreach ( $rows as $row ) $out[(int)$row['user_id']]=get_gmt_from_date($row['last_activity'],'Y-m-d\TH:i:s\Z');
			return $out;
		} catch ( \Throwable $e ) { return []; }
	}
	/** A native tracking-policy edit starts a new known coverage interval, without erasing totals. */
	public static function settings_changed($option,$old,$new): void {
		if (!in_array($option,['stp_settings','dsa_settings'],true) || !is_array($old) || !is_array($new)) return;
		if ($option==='dsa_settings') {
			if ((bool)($old['secure']['enabled']??false)===(bool)($new['secure']['enabled']??false)) return;
		} else {
			$keys=array_flip(['track_visitors','track_pages','exclude_admin','track_admin_activity']);
			if (array_intersect_key($old,$keys)===array_intersect_key($new,$keys)) return;
		}
		if (!self::ready()) return;
		global$wpdb;
		$wpdb->query(self::bounded_sql($wpdb->prepare('UPDATE '.self::table('report_state').' SET coverage_start=%s,generation=generation+1,status=%s WHERE id=1',current_time('mysql'),'pending')));
	}
}
