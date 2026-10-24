<?php
namespace DSA\Backup;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Draft encrypted database member: no route, restore, job or whole-site consistency authority. */
final class Portable_Database_Capture {
	public const SCHEMA = 'kiwe.portable-database.v2';
	public const MAX_TABLES = 1000;
	public const MAX_ROWS = 4000000;
	public const MAX_TABLE_ROWS = 1000000;
	public const MAX_BYTES = 4294967296;
	public const MAX_ROW_BYTES = 16777216;
	public const INLINE_ROW_BYTES = 196608;
	public const ROW_CHUNK_BYTES = 262144;
	public const MAX_COLUMNS = 64;
	private const MAGIC = "KIWEPDB2";
	private const LEGACY_SCHEMA = 'kiwe.portable-database.v1';
	private const MAX_KEY_BYTES = 4096;
	private const MAX_DDL = 65536;
	private const MAX_METADATA = 8388608;
	public const MAX_FRAMES = 4100000;
	// At most 12 MiB of inline row bytes per page; larger rows still use bounded chunks.
	private const BATCH = 64;
	private const CORE = [ 'comments','commentmeta','links','options','postmeta','posts','term_relationships','term_taxonomy','termmeta','terms','usermeta','users' ];
	private \wpdb $db;
	private string $connection;
	private string $family;
	private string $version;
	private string $isolation_var;
	private string $readonly_var;
	private array $old = [];
	private bool $started = false;
	private bool $settings_changed = false;
	private array $key_specs = [];

	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function keys( array $value, array $keys ): bool { return count( $value ) === count( $keys ) && ! array_diff( $keys,array_keys( $value ) ); }
	private static function json( array $value ): string {
		try { return json_encode( $value,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); }
		catch ( \JsonException $e ) { self::fail( 'Portable database metadata could not be encoded.' ); }
	}
	private static function object( string $plain ): array {
		try { $value = json_decode( $plain,true,20,JSON_THROW_ON_ERROR ); }
		catch ( \JsonException $e ) { self::fail( 'The portable database metadata is damaged.' ); }
		if ( ! is_array( $value ) || array_is_list( $value ) ) self::fail( 'The portable database metadata is invalid.' );
		return $value;
	}
	private static function identifier( string $name ): string {
		if ( ! preg_match( '/^[a-zA-Z0-9_]{1,64}$/D',$name ) ) self::fail( 'This database uses unsupported table or column names.' );
		return '`'.$name.'`';
	}
	private static function number( $value, int $max, string $message ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/^(0|[1-9][0-9]*)$/D',$value ) ) ) self::fail( $message );
		if ( strlen( (string)$value ) > strlen( (string)$max ) || ( strlen( (string)$value ) === strlen( (string)$max ) && strcmp( (string)$value,(string)$max ) > 0 ) ) self::fail( $message );
		return (int)$value;
	}
	private static function stream( $stream, bool $write ): void {
		if ( ! is_resource( $stream ) || 'stream' !== get_resource_type( $stream ) ) self::fail( 'An ordinary local database backup stream is required.' );
		$meta = stream_get_meta_data( $stream ); $mode = $meta['mode'] ?? '';
		if ( ! in_array( $meta['wrapper_type'] ?? '',[ 'plainfile','PHP' ],true ) || empty( $meta['seekable'] ) || ftell( $stream ) !== 0 ) self::fail( 'A local database backup stream at its beginning is required.' );
		if ( $write && ( ! preg_match( '/[waxc+]/',$mode ) || ( fstat( $stream )['size'] ?? -1 ) !== 0 ) ) self::fail( 'An empty writable database backup stream is required.' );
		if ( ! $write && ! str_contains( $mode,'r' ) && ! str_contains( $mode,'+' ) ) self::fail( 'A readable database backup stream is required.' );
	}
	private static function write( $stream, string $bytes ): void {
		$at = 0;
		while ( $at < strlen( $bytes ) ) { $n = @fwrite( $stream,substr( $bytes,$at ) ); if ( ! is_int( $n ) || $n < 1 ) self::fail( 'The portable database backup could not be written completely.' ); $at += $n; }
	}
	private static function read( $stream, int $bytes ): string {
		$out = '';
		while ( strlen( $out ) < $bytes ) { $part = @fread( $stream,$bytes-strlen( $out ) ); if ( ! is_string( $part ) || '' === $part ) self::fail( 'The portable database backup is incomplete or unreadable.' ); $out .= $part; }
		return $out;
	}
	private function __construct( \wpdb $db ) { $this->db = $db; }
	private function raw( string $sql, string $kind = 'rows' ): mixed {
		$this->db->last_error = '';
		try { $value = 'query' === $kind ? $this->db->query( $sql ) : ( 'value' === $kind ? $this->db->get_var( $sql ) : $this->db->get_results( $sql,'plan' === $kind ? ARRAY_A : ARRAY_N ) ); }
		catch ( \Throwable $e ) { self::fail( 'The native database operation could not be completed.' ); }
		if ( false === $value || '' !== (string)$this->db->last_error || ( in_array( $kind,[ 'rows','plan' ],true ) && ! is_array( $value ) ) || ( 'value' === $kind && null === $value ) ) self::fail( 'The native database operation could not be completed.' );
		return $value;
	}
	private function same_connection(): void {
		$current = (string)$this->raw( 'SELECT CONNECTION_ID()','value' );
		if ( ! preg_match( '/^[1-9][0-9]*$/D',$current ) || $current !== $this->connection ) self::fail( 'The database connection changed during capture. Discard this incomplete copy.' );
	}
	/** Supervise the same native connection during non-SQL archive work too. */
	public static function connection_guard( \wpdb $wpdb ): \Closure {
		$owner = new self( $wpdb ); $owner->connection = (string)$owner->raw( 'SELECT CONNECTION_ID()','value' );
		if ( ! preg_match( '/^[1-9][0-9]*$/D',$owner->connection ) ) self::fail( 'The native database connection cannot be supervised.' );
		return static function() use ( $owner ): void { $owner->same_connection(); };
	}
	private function run( string $sql, string $kind = 'rows' ): mixed {
		$this->same_connection(); $result = $this->raw( $sql,$kind ); $this->same_connection(); return $result;
	}
	/** Successful SQL alone does not prove transaction monitoring is active. */
	private function transaction_state(): array {
		if ( 'MariaDB' === $this->family ) {
			$rows = $this->run( 'SELECT @@SESSION.in_transaction, @@SESSION.'.$this->isolation_var.', @@SESSION.'.$this->readonly_var );
			if ( count( $rows ) !== 1 || count( $rows[0] ) !== 3 || ! in_array( (string)$rows[0][0],[ '0','1' ],true ) || ! in_array( (string)$rows[0][2],[ '0','1' ],true ) ) self::fail( 'This server cannot verify its transaction state.' );
			return [ 'active'=>'1' === (string)$rows[0][0],'isolation'=>str_replace( '-',' ',strtoupper( (string)$rows[0][1] ) ),'readonly'=>'1' === (string)$rows[0][2] ];
		}
		$instrument = $this->run( "SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME = 'transaction'" );
		$consumers = $this->run( "SELECT NAME, ENABLED FROM performance_schema.setup_consumers WHERE NAME IN ('global_instrumentation','thread_instrumentation','events_transactions_current') ORDER BY NAME" );
		$thread = $this->run( 'SELECT THREAD_ID, INSTRUMENTED FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID()' );
		if ( $instrument !== [ [ 'YES' ] ] || count( $consumers ) !== 3 || count( $thread ) !== 1 || count( $thread[0] ) !== 2 || 'YES' !== $thread[0][1] ) self::fail( 'MySQL transaction monitoring must already be enabled and readable for safe capture.' );
		$required = [ 'events_transactions_current','global_instrumentation','thread_instrumentation' ];
		foreach ( $consumers as $i=>$row ) if ( $row !== [ $required[$i],'YES' ] ) self::fail( 'MySQL transaction monitoring must already be enabled and readable for safe capture.' );
		$id = self::number( $thread[0][0],PHP_INT_MAX,'The native transaction thread is invalid.' );
		$rows = $this->run( "SELECT STATE, ACCESS_MODE, ISOLATION_LEVEL, XA_STATE FROM performance_schema.events_transactions_current WHERE THREAD_ID = ".$id );
		if ( count( $rows ) > 1 || ( $rows && count( $rows[0] ) !== 4 ) ) self::fail( 'This server cannot verify its transaction state.' );
		if ( ! $rows ) return [ 'active'=>false,'isolation'=>'','readonly'=>false ];
		$row = $rows[0];
		if ( null !== $row[3] || ! in_array( $row[0],[ 'ACTIVE','COMMITTED','ROLLED BACK' ],true ) ) self::fail( 'An unsupported native transaction is present.' );
		return [ 'active'=>'ACTIVE' === $row[0],'isolation'=>$row[2],'readonly'=>'READ ONLY' === $row[1] ];
	}
	private function assert_transaction(): void {
		$state = $this->transaction_state();
		if ( ! $state['active'] || ! $state['readonly'] || 'REPEATABLE READ' !== $state['isolation'] ) self::fail( 'The consistent read-only database transaction was lost.' );
		if ( '+00:00' !== $this->run( 'SELECT @@SESSION.time_zone','value' ) ) self::fail( 'The UTC database capture session was changed. Discard this incomplete copy.' );
	}
	private function begin(): array {
		$info = $this->raw( 'SELECT CONNECTION_ID(), VERSION(), @@version_comment, @@SESSION.autocommit, DATABASE(), @@character_set_connection, @@collation_connection, @@SESSION.sql_mode, @@SESSION.time_zone' );
		if ( count( $info ) !== 1 || count( $info[0] ) !== 9 ) self::fail( 'The native database capabilities could not be inspected.' );
		$r = $info[0]; $this->connection = (string)$r[0];
		if ( ! preg_match( '/^[1-9][0-9]*$/D',$this->connection ) || '1' !== (string)$r[3] ) self::fail( 'Database capture requires an idle connection with autocommit enabled.' );
		$native = (string)$r[1]; $comment = (string)$r[2]; $this->family = str_contains( $native.' '.$comment,'MariaDB' ) ? 'MariaDB' : 'MySQL';
		if ( 'MariaDB' === $this->family ) {
			$native = preg_replace( '/^5\.5\.5-/','',$native );
			if ( ! preg_match( '/^([0-9]+\.[0-9]+\.[0-9]+)/',$native,$m ) || version_compare( $m[1],'10.3.0','<' ) ) self::fail( 'This MariaDB version is outside the supported capture scope.' );
			$this->isolation_var = 'tx_isolation'; $this->readonly_var = 'tx_read_only';
		} else {
			if ( ! preg_match( '/^([0-9]+\.[0-9]+\.[0-9]+)$/D',$native,$m ) || version_compare( $m[1],'8.0.0','<' ) || stripos( $comment,'mysql' ) === false ) self::fail( 'This database family or version is outside the supported capture scope.' );
			$this->isolation_var = 'transaction_isolation'; $this->readonly_var = 'transaction_read_only';
		}
		$this->version = $m[1];
		// START implicitly commits an earlier transaction; inspect before any SET or START.
		if ( $this->transaction_state()['active'] ) self::fail( 'A native database transaction is already active. Capture needs its own idle connection.' );
		$old = $this->run( 'SELECT @@SESSION.'.$this->isolation_var.', @@SESSION.'.$this->readonly_var );
		if ( count( $old ) !== 1 || count( $old[0] ) !== 2 || ! in_array( str_replace( '-',' ',strtoupper( (string)$old[0][0] ) ),[ 'READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE' ],true ) || ! in_array( (string)$old[0][1],[ '0','1' ],true ) ) self::fail( 'The native transaction defaults are unsupported.' );
		if ( ! is_string( $r[8] ) || '' === $r[8] || strlen( $r[8] ) > 64 || ! preg_match( '/^[a-zA-Z0-9_+\/:.-]+$/D',$r[8] ) ) self::fail( 'The native session timezone is unsupported.' );
		$this->old = [ 'isolation'=>str_replace( '-',' ',strtoupper( (string)$old[0][0] ) ),'readonly'=>(int)$old[0][1],'timezone'=>$r[8] ]; $this->settings_changed = true;
		// TIMESTAMP reads depend on the session zone, including historical DST rules.
		// Capture in UTC rather than guessing today's offset for SYSTEM/named zones.
		$this->run( "SET SESSION time_zone='+00:00'",'query' );
		if ( '+00:00' !== $this->run( 'SELECT @@SESSION.time_zone','value' ) ) self::fail( 'The native database did not establish a UTC capture session.' );
		$this->run( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ','query' );
		$this->run( 'SET SESSION TRANSACTION READ ONLY','query' );
		$this->started = true; // Clean up if transport fails after the server executes START.
		$this->same_connection(); $this->raw( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY','query' );
		// Read START's diagnostics before an identity query can replace them.
		$warnings = $this->raw( 'SHOW WARNINGS' ); $this->same_connection();
		if ( $warnings ) self::fail( 'The database server did not establish an unqualified consistent snapshot.' );
		$this->assert_transaction();
		foreach ( [ 4,5,6,7,8 ] as $i ) if ( ! is_string( $r[$i] ) || strlen( $r[$i] ) > 4096 || ! preg_match( '//u',$r[$i] ) ) self::fail( 'The database connection metadata is unsupported.' );
		return [ 'family'=>$this->family,'version'=>$this->version,'charset'=>$r[5],'collation'=>$r[6],'sqlMode'=>$r[7],'timeZone'=>'+00:00' ];
	}
	private function end(): void {
		$error = false;
		if ( $this->started ) {
			try { $this->raw( 'ROLLBACK','query' ); $this->same_connection(); if ( $this->transaction_state()['active'] ) $error = true; }
			catch ( \Throwable $e ) { $error = true; }
			$this->started = false;
		}
		if ( $this->settings_changed ) {
			try {
				$this->same_connection(); $this->run( 'SET SESSION TRANSACTION ISOLATION LEVEL '.$this->old['isolation'],'query' );
				$this->run( 'SET SESSION TRANSACTION READ '.( $this->old['readonly'] ? 'ONLY' : 'WRITE' ),'query' );
				$this->run( $this->db->prepare( 'SET SESSION time_zone=%s',$this->old['timezone'] ),'query' );
				$after = $this->run( 'SELECT @@SESSION.'.$this->isolation_var.', @@SESSION.'.$this->readonly_var );
				if ( count( $after ) !== 1 || count( $after[0] ) !== 2 || str_replace( '-',' ',strtoupper( (string)$after[0][0] ) ) !== $this->old['isolation'] || (string)$after[0][1] !== (string)$this->old['readonly'] ) $error = true;
				if ( $this->run( 'SELECT @@SESSION.time_zone','value' ) !== $this->old['timezone'] ) $error = true;
			} catch ( \Throwable $e ) { $error = true; }
			$this->settings_changed = false;
		}
		if ( $error ) self::fail( 'Database capture could not prove transaction cleanup. Discard this incomplete copy and retire its connection.' );
	}
	private function catalog( string $prefix ): array {
		$rows = $this->run( $this->db->prepare( 'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND BINARY LEFT(TABLE_NAME, %d) = BINARY %s ORDER BY BINARY TABLE_NAME',strlen( $prefix ),$prefix ) ); $out = [];
		if ( ! $rows || count( $rows ) > self::MAX_TABLES ) self::fail( 'This database table count is outside the supported capture scope.' );
		foreach ( $rows as $row ) {
			if ( count( $row ) !== 4 || ! is_string( $row[0] ) || ! str_starts_with( $row[0],$prefix ) || 'BASE TABLE' !== $row[1] || 'InnoDB' !== $row[2] || ! is_string( $row[3] ) || isset( $out[$row[0]] ) ) self::fail( 'Portable capture supports only unique native InnoDB base tables, without views or other engines.' );
			self::identifier( $row[0] ); $out[$row[0]] = $row;
		}
		foreach ( self::CORE as $suffix ) if ( ! isset( $out[$prefix.$suffix] ) ) self::fail( 'The native single-site WordPress table set is incomplete.' );
		return $out;
	}
	private function triggers_absent( string $prefix, array $catalog ): void {
		// Trigger rows are hidden without TRIGGER privilege: prove visibility before absence.
		$database = (string)$this->run( 'SELECT DATABASE()','value' ); $schema_visible = false;
		foreach ( $this->run( 'SHOW GRANTS' ) as $grant ) {
			if ( count( $grant ) !== 1 || ! is_string( $grant[0] ) ) self::fail( 'Native trigger visibility could not be verified.' );
			if ( ! preg_match( '/^GRANT (.+?) ON (\*|\x60(?:[^\x60]|\x60\x60)+\x60)\.(\*|\x60(?:[^\x60]|\x60\x60)+\x60) TO /i',$grant[0],$m ) ) continue;
			$rights = array_map( 'trim',explode( ',',strtoupper( $m[1] ) ) );
			if ( ! in_array( 'ALL PRIVILEGES',$rights,true ) && array_diff( [ 'SELECT','TRIGGER','REFERENCES' ],$rights ) ) continue;
			$scope = '*' === $m[2] ? '*' : str_replace( '``','`',substr( $m[2],1,-1 ) ); $table = '*' === $m[3] ? '*' : str_replace( '``','`',substr( $m[3],1,-1 ) );
			// GRANT may escape literal database underscores/percent characters.
			$scope = str_replace( [ '\\_','\\%' ],[ '_','%' ],$scope );
			if ( ( '*' === $scope || $scope === $database ) && '*' === $table ) $schema_visible = true;
		}
		if ( ! $schema_visible ) self::fail( 'Schema-wide native SELECT, TRIGGER and REFERENCES visibility is required; role-only or partial table grants are unsupported.' );
		if ( $this->run( $this->db->prepare( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND BINARY LEFT(EVENT_OBJECT_TABLE, %d) = BINARY %s LIMIT 1',strlen( $prefix ),$prefix ) ) ) self::fail( 'This database uses triggers, which this portable capture does not support.' );
		if ( $this->run( $this->db->prepare( 'SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND BINARY LEFT(REFERENCED_TABLE_NAME, %d) = BINARY %s AND (TABLE_SCHEMA <> DATABASE() OR BINARY LEFT(TABLE_NAME, %d) <> BINARY %s) LIMIT 1',strlen( $prefix ),$prefix,strlen( $prefix ),$prefix ) ) ) self::fail( 'This database has dependencies outside its WordPress table prefix.' );
		if ( $this->run( $this->db->prepare( 'SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND BINARY LEFT(TABLE_NAME, %d) = BINARY %s AND REFERENCED_TABLE_NAME IS NOT NULL AND (REFERENCED_TABLE_SCHEMA <> DATABASE() OR BINARY LEFT(REFERENCED_TABLE_NAME, %d) <> BINARY %s) LIMIT 1',strlen( $prefix ),$prefix,strlen( $prefix ),$prefix ) ) ) self::fail( 'A native table refers outside the captured WordPress table set.' );
	}
	/** Definitions are encrypted data, never executable SQL in this class. */
	private static function ddl( string $name, string $ddl, array $names ): string {
		if ( '' === $ddl || strlen( $ddl ) > self::MAX_DDL || ! preg_match( '//u',$ddl ) || ! str_starts_with( $ddl,'CREATE TABLE '.self::identifier( $name ).' (' ) ) self::fail( 'A native table definition is unsupported or too large.' );
		$tokens = preg_replace( "/'(?:[^'\\\\]|\\\\.|'')*'/s","''",$ddl );
		if ( ! is_string( $tokens ) || preg_match( '/\b(?:DATA|INDEX)\s+DIRECTORY\b|\bTABLESPACE\b|\bSYSTEM\s+VERSIONING\b|\bSYSTEM_TIME\b|\bCONNECTION\s*=/i',$tokens ) ) self::fail( 'A native table has unsupported external storage or versioning.' );
		preg_match_all( '/\bREFERENCES\s+(\x60[^\x60]+\x60|[a-zA-Z0-9_]+)(\s*\.\s*(\x60[^\x60]+\x60|[a-zA-Z0-9_]+))?/i',$tokens,$refs,PREG_SET_ORDER );
		foreach ( $refs as $ref ) if ( ! empty( $ref[2] ) || ! in_array( trim( $ref[1],'`' ),$names,true ) ) self::fail( 'A native table refers outside the captured WordPress table set.' );
		// Native DML advances AUTO_INCREMENT; preserve it but exclude it from schema-change detection.
		return preg_replace( '/\bAUTO_INCREMENT=[0-9]+\b/','AUTO_INCREMENT=0',$ddl );
	}
	private function describe( string $name, array $names ): array {
		$created = $this->run( 'SHOW CREATE TABLE '.self::identifier( $name ) );
		if ( count( $created ) !== 1 || count( $created[0] ) !== 2 || $created[0][0] !== $name || ! is_string( $created[0][1] ) ) self::fail( 'A native table definition could not be inspected.' );
		$ddl = $created[0][1]; $stable = self::ddl( $name,$ddl,$names );
		$native = $this->run( $this->db->prepare( 'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',$name ) );
		if ( ! $native || count( $native ) > self::MAX_COLUMNS ) self::fail( 'A native table has too many columns for bounded capture.' ); $columns = [];
		foreach ( $native as $col ) {
			if ( count( $col ) !== 5 || ! is_string( $col[0] ) || ! is_string( $col[1] ) || strlen( $col[1] ) > 16384 || ! in_array( $col[2],[ 'YES','NO' ],true ) || ! is_string( $col[3] ) || strlen( $col[3] ) > 128 || ( null !== $col[4] && ! is_string( $col[4] ) ) ) self::fail( 'A native column definition is unsupported.' );
			self::identifier( $col[0] ); if ( preg_match( '/\b(?:GENERATED|INVISIBLE|ROW START|ROW END)\b/i',$col[3] ) ) self::fail( 'Generated, invisible or versioned native columns are outside this capture scope.' );
			$columns[] = [ 'name'=>$col[0],'type'=>$col[1],'nullable'=>'YES' === $col[2],'extra'=>$col[3],'collation'=>$col[4] ];
		}
		$native = $this->run( $this->db->prepare( "SELECT COLUMN_NAME, SEQ_IN_INDEX, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX",$name ) ); $primary = [];
		if ( ! $native ) self::fail( 'Portable capture needs a primary key on every table; unordered tables are unsupported.' );
		foreach ( $native as $i=>$row ) {
			if ( count( $row ) !== 3 || ! is_string( $row[0] ) || (string)( $i+1 ) !== (string)$row[1] || null !== $row[2] || ! in_array( $row[0],array_column( $columns,'name' ),true ) ) self::fail( 'A native primary key is unsupported.' ); $primary[] = $row[0];
		}
		$count = self::number( $this->run( 'SELECT COUNT(*) FROM '.self::identifier( $name ),'value' ),self::MAX_TABLE_ROWS,'A native table exceeds the supported row limit.' );
		return [ 'name'=>$name,'create'=>$ddl,'columns'=>$columns,'primary'=>$primary,'rows'=>$count,'schemaSha256'=>hash( 'sha256',self::json( [ 'create'=>$stable,'columns'=>$columns,'primary'=>$primary ] ) ) ];
	}
	/** Keep cursor comparisons in the primary key's native type, without PHP numeric conversion. */
	private function prepare_keys( array $table ): void {
		$specs = []; $columns = array_column( $table['columns'],null,'name' );
		foreach ( $table['primary'] as $name ) {
			$col = $columns[$name]; $type = strtolower( $col['type'] );
			if ( $col['nullable'] ) self::fail( 'A native primary key must be non-nullable.' );
			if ( preg_match( '/^(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\([0-9]+\))?(?: unsigned)?(?: zerofill)?$/D',$type ) || preg_match( '/^year(?:\(4\))?$/D',$type ) ) $spec = [ 'kind'=>'integer','cast'=>'DECIMAL(65,0)' ];
			elseif ( preg_match( '/^decimal\(([0-9]+),([0-9]+)\)(?: unsigned)?(?: zerofill)?$/D',$type,$m ) && (int)$m[1] >= 1 && (int)$m[1] <= 65 && (int)$m[2] <= 30 && (int)$m[2] <= (int)$m[1] ) $spec = [ 'kind'=>'decimal','cast'=>'DECIMAL('.(int)$m[1].','.(int)$m[2].')' ];
			elseif ( preg_match( '/^(?:varbinary|binary)\([0-9]+\)$/D',$type ) ) $spec = [ 'kind'=>'binary' ];
			elseif ( preg_match( '/^(?:varchar|char)\([0-9]+\)$/D',$type ) ) {
				$collation = $col['collation']; if ( ! is_string( $collation ) || ! preg_match( '/^[a-zA-Z0-9_]{1,64}$/D',$collation ) ) self::fail( 'A native primary key collation is unsupported.' );
				$native = $this->run( $this->db->prepare( 'SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME = %s',$collation ) );
				if ( count( $native ) !== 1 || count( $native[0] ) !== 1 || ! is_string( $native[0][0] ) || ! preg_match( '/^[a-zA-Z0-9_]{1,64}$/D',$native[0][0] ) ) self::fail( 'A native primary key character set could not be resolved.' );
				$spec = [ 'kind'=>'text','charset'=>$native[0][0],'collation'=>$collation ];
			} elseif ( preg_match( '/^(date|datetime|timestamp|time)(?:\(([0-6])\))?$/D',$type,$m ) ) $spec = [ 'kind'=>'temporal','cast'=>strtoupper( 'timestamp' === $m[1] ? 'datetime' : $m[1] ).( isset( $m[2] ) ? '('.$m[2].')' : '' ) ];
			else self::fail( 'A native primary key type is outside the supported range traversal scope.' );
			$specs[$name] = $spec;
		}
		$this->key_specs[$table['name']] = $specs;
	}
	private function key_literal( array $table, string $name, string $value ): string {
		$spec = $this->key_specs[$table['name']][$name]; $hex = "X'".bin2hex( $value )."'";
		if ( 'binary' === $spec['kind'] ) return $hex;
		if ( 'text' === $spec['kind'] ) return 'CONVERT('.$hex.' USING '.$spec['charset'].') COLLATE '.$spec['collation'];
		if ( 'integer' === $spec['kind'] && ! preg_match( '/^-?(?:0|[1-9][0-9]*)$/D',$value ) ) self::fail( 'A native integer cursor is invalid.' );
		if ( 'decimal' === $spec['kind'] && ! preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value ) ) self::fail( 'A native decimal cursor is invalid.' );
		if ( 'temporal' === $spec['kind'] && ! preg_match( '/^[0-9: .-]{1,32}$/D',$value ) ) self::fail( 'A native temporal cursor is invalid.' );
		return 'CAST('.$hex.' AS '.$spec['cast'].')';
	}
	private function predicate( array $table, array $cursor, bool $equal = false ): string {
		$parts = []; $terms = [];
		foreach ( $table['primary'] as $i=>$name ) {
			$column = self::identifier( $name ); $literal = $this->key_literal( $table,$name,$cursor[$i] );
			if ( ! $equal ) $terms[] = '('.implode( ' AND ',array_merge( $parts,[ $column.' > '.$literal ] ) ).')';
			$parts[] = $column.' = '.$literal;
		}
		return $equal ? implode( ' AND ',$parts ) : '('.implode( ' OR ',$terms ).')';
	}
	private function row_sql( array $table, ?array $cursor ): string {
		$sum = []; foreach ( $table['columns'] as $col ) $sum[] = 'COALESCE(OCTET_LENGTH(CAST('.self::identifier( $col['name'] ).' AS BINARY)),0)';
		$total = '('.implode( '+',$sum ).')'; $select = [];
		// wpdb first fetches objects: duplicate/truncated expression names lose cells before ARRAY_N.
		foreach ( $table['columns'] as $i=>$col ) $select[] = 'CASE WHEN '.$total.' <= '.self::INLINE_ROW_BYTES.' THEN CAST('.self::identifier( $col['name'] ).' AS BINARY) ELSE NULL END AS _kiwe_value_'.$i;
		foreach ( $table['columns'] as $i=>$col ) $select[] = 'OCTET_LENGTH(CAST('.self::identifier( $col['name'] ).' AS BINARY)) AS _kiwe_length_'.$i;
		$select[] = $total.' AS _kiwe_total'; foreach ( $table['primary'] as $i=>$name ) $select[] = 'CAST('.self::identifier( $name ).' AS BINARY) AS _kiwe_key_'.$i;
		$sql = 'SELECT '.implode( ',',$select ).' FROM '.self::identifier( $table['name'] ).' FORCE INDEX (PRIMARY)'.( null === $cursor ? '' : ' WHERE '.$this->predicate( $table,$cursor ) ).' ORDER BY '.implode( ',',array_map( [ self::class,'identifier' ],$table['primary'] ) ).' LIMIT '.self::BATCH;
		if ( strlen( $sql ) > 262144 ) self::fail( 'A native row query exceeds its bounded size.' ); return $sql;
	}
	private function range_plan( string $sql, bool $first ): void {
		$plan = $this->run( 'EXPLAIN '.$sql,'plan' );
		if ( count( $plan ) !== 1 || ! is_array( $plan[0] ) || 'PRIMARY' !== ( $plan[0]['key'] ?? null ) || ! in_array( strtolower( (string)( $plan[0]['type'] ?? '' ) ),$first ? [ 'index','range','ref','const' ] : [ 'range','ref','const' ],true ) || preg_match( '/filesort|temporary/i',(string)( $plan[0]['Extra'] ?? '' ) ) ) self::fail( 'Native primary-key range traversal could not be verified.' );
	}
	private static function row( array $native, array $table ): array {
		$columns = count( $table['columns'] ); $primary = count( $table['primary'] );
		if ( count( $native ) !== 2*$columns+1+$primary ) self::fail( 'A native row has an unexpected shape.' );
		$total = self::number( $native[2*$columns],self::MAX_ROW_BYTES,'A native row exceeds the supported byte limit.' ); $bytes = 0; $cells = []; $lengths = []; $cursor = []; $key_bytes = 0;
		foreach ( $table['primary'] as $i=>$name ) { $value = $native[2*$columns+1+$i]; if ( ! is_string( $value ) ) self::fail( 'A native primary key value is invalid.' ); $key_bytes += strlen( $value ); if ( $key_bytes > self::MAX_KEY_BYTES ) self::fail( 'A native primary key exceeds the cursor byte limit.' ); $cursor[] = $value; }
		for ( $i=0;$i<$columns;++$i ) {
			$value = $native[$i]; $length = $native[$columns+$i];
			if ( null === $length ) { if ( null !== $value || ! $table['columns'][$i]['nullable'] ) self::fail( 'A native NULL cell is inconsistent.' ); $cells[] = [ 'n' ]; $lengths[] = null; continue; }
			$size = self::number( $length,self::MAX_ROW_BYTES,'A native cell exceeds the supported byte limit.' );
			if ( $total > self::INLINE_ROW_BYTES ) { if ( null !== $value ) self::fail( 'A native large-row projection is inconsistent.' ); }
			else { if ( ! is_string( $value ) || strlen( $value ) !== $size ) self::fail( 'A native binary cell is inconsistent.' ); $cells[] = [ 'b',base64_encode( $value ) ]; }
			$bytes += $size; $lengths[] = $size;
			$key = array_search( $table['columns'][$i]['name'],$table['primary'],true ); if ( false !== $key && ( strlen( $cursor[$key] ) !== $size || ( $total <= self::INLINE_ROW_BYTES && $cursor[$key] !== $value ) ) ) self::fail( 'A native primary key projection is inconsistent.' );
		}
		if ( $bytes !== $total ) self::fail( 'A native row byte total is inconsistent.' ); return [ 'cells'=>$cells,'lengths'=>$lengths,'bytes'=>$bytes,'cursor'=>$cursor ];
	}
	/** Retrieve at most one frame of binary cell data from the held native read view. */
	private function large_row( array $table, array $row, int $position, callable $emit, $table_hash, ?callable $budget ): void {
		$row_hash = hash_init( 'sha256' ); $record = static function( string $plain ) use ( $emit,$table_hash,$row_hash ): void { $packet = pack( 'N',strlen( $plain ) ).$plain; hash_update( $table_hash,$packet ); hash_update( $row_hash,$packet ); $emit( $plain,'data' ); };
		$record( 'L'.self::json( [ 'position'=>$position,'cells'=>$row['lengths'] ] ) ); $buffer = ''; $offset = 0; $where = $this->predicate( $table,$row['cursor'],true );
		foreach ( $row['lengths'] as $i=>$length ) {
			for ( $at=0;$at<(int)$length; ) {
				if ( $budget ) $budget(); $this->assert_transaction(); $want = min( self::ROW_CHUNK_BYTES-strlen( $buffer ),$length-$at ); $column = self::identifier( $table['columns'][$i]['name'] );
				$native = $this->run( 'SELECT SUBSTRING(CAST('.$column.' AS BINARY),'.( $at+1 ).','.$want.') AS _kiwe_part,OCTET_LENGTH(CAST('.$column.' AS BINARY)) AS _kiwe_length FROM '.self::identifier( $table['name'] ).' FORCE INDEX (PRIMARY) WHERE '.$where.' LIMIT 1' );
				if ( count( $native ) !== 1 || count( $native[0] ) !== 2 || ! is_string( $native[0][0] ) || strlen( $native[0][0] ) !== $want || self::number( $native[0][1],self::MAX_ROW_BYTES,'A native large cell is invalid.' ) !== $length ) self::fail( 'A native large cell changed or could not be captured completely.' );
				$buffer .= $native[0][0]; $at += $want;
				if ( strlen( $buffer ) === self::ROW_CHUNK_BYTES ) { $record( 'C'.pack( 'N2',$offset,strlen( $buffer ) ).$buffer ); $offset += strlen( $buffer ); $buffer = ''; }
			}
		}
		if ( '' !== $buffer ) { $record( 'C'.pack( 'N2',$offset,strlen( $buffer ) ).$buffer ); $offset += strlen( $buffer ); }
		if ( $offset !== $row['bytes'] ) self::fail( 'A native large row is incomplete.' );
		$plain = 'Z'.self::json( [ 'position'=>$position,'bytes'=>$offset,'sha256'=>hash_final( $row_hash ) ] ); hash_update( $table_hash,pack( 'N',strlen( $plain ) ).$plain ); $emit( $plain,'data' );
	}
	/** Caller owns the private empty sink and application consistency lease. Footer follows cleanup. */
	public static function capture( \wpdb $wpdb, $stream, Portable_Archive_Cipher $cipher, ?callable $budget = null, ?callable $checkpoint = null ): array {
		self::stream( $stream,true );
		if ( ( function_exists( 'is_multisite' ) && is_multisite() ) || ! is_string( $wpdb->prefix ) || ! preg_match( '/^[a-zA-Z0-9_]{1,48}$/D',$wpdb->prefix ) || $wpdb->base_prefix !== $wpdb->prefix ) self::fail( 'Portable database capture currently supports one native WordPress site only.' );
		$owner = new self( $wpdb ); $previous = $wpdb->suppress_errors( true ); $sequence = 0; $output = 0; $transcript = hash_init( 'sha256' ); $transport = hash_init( 'sha256' ); $tables = []; $totals = []; $all_rows = 0; $all_bytes = 0; $metadata = 0;
		$emit = static function( string $plain, string $purpose ) use ( $stream,$cipher,$budget,&$sequence,&$output,$transcript,$transport ): void {
			if ( $budget ) $budget(); if ( $sequence >= self::MAX_FRAMES ) self::fail( 'The portable database backup has too many frames.' );
			$frame = $cipher->seal( $plain,$sequence,$purpose ); $packet = ( 'index' === $purpose ? 'I' : ( 'manifest' === $purpose ? 'M' : 'D' ) ).pack( 'N',strlen( $frame ) ).$frame;
			self::write( $stream,$packet ); hash_update( $transport,$packet ); if ( 'manifest' !== $purpose ) hash_update( $transcript,$packet ); $output += strlen( $packet ); ++$sequence;
		};
		try {
			try {
				if ( $budget ) $budget(); $source = $owner->begin(); $catalog = $owner->catalog( $wpdb->prefix ); $names = array_keys( $catalog );
				// SELECT acquires transactional metadata locks before definitions or rows are emitted.
				foreach ( $names as $name ) $owner->run( 'SELECT * FROM '.self::identifier( $name ).' LIMIT 0' );
				if ( $owner->catalog( $wpdb->prefix ) !== $catalog ) self::fail( 'The native table catalog changed before capture.' ); $owner->triggers_absent( $wpdb->prefix,$catalog );
				foreach ( $names as $name ) {
					if ( $budget ) $budget(); $table = $owner->describe( $name,$names ); $owner->prepare_keys( $table ); $metadata += strlen( self::json( $table ) );
					if ( $metadata > self::MAX_METADATA || $all_rows+$table['rows'] > self::MAX_ROWS ) self::fail( 'The native database exceeds supported metadata or row limits.' ); $all_rows += $table['rows']; $tables[] = $table;
				}
				// The capture owner may review files inside this read view. It cannot replace
				// transaction inspection or authorize a completion footer after losing it.
				// File work may outlast a hosting connection's idle timeout. Give the
				// observation owner only a check of this exact read view, not raw SQL.
				$read_view = static function() use ( $owner ): void { $owner->assert_transaction(); };
				$owner->assert_transaction(); if ( $checkpoint ) $checkpoint( 'opened',$read_view ); $owner->assert_transaction();
				$header = self::json( $cipher->header() ); $prefix = self::MAGIC.pack( 'N',strlen( $header ) ).$header; self::write( $stream,$prefix ); hash_update( $transport,$prefix ); $output += strlen( $prefix );
				$emit( self::json( [ 'schema'=>self::SCHEMA,'prefix'=>$wpdb->prefix,'source'=>$source,'tables'=>count( $tables ),'rows'=>$all_rows ] ),'index' );
				foreach ( $tables as $table ) {
					if ( $budget ) $budget(); $owner->assert_transaction(); $emit( 'T'.self::json( $table ),'data' ); $rows = 0; $bytes = 0; $hash = hash_init( 'sha256' ); $cursor = null;
					while ( $rows < $table['rows'] ) {
						if ( $budget ) $budget(); $owner->assert_transaction(); $sql = $owner->row_sql( $table,$cursor ); $owner->range_plan( $sql,null === $cursor ); $batch = $owner->run( $sql );
						if ( ! $batch || count( $batch ) > self::BATCH || $rows+count( $batch ) > $table['rows'] ) self::fail( 'The native database snapshot row count changed.' );
						foreach ( $batch as $native ) {
							$row = self::row( $native,$table ); if ( $row['cursor'] === $cursor ) self::fail( 'Native primary-key traversal did not advance.' );
							if ( $all_bytes+$row['bytes'] > self::MAX_BYTES ) self::fail( 'The native database exceeds the supported byte limit.' );
							if ( $row['bytes'] > self::INLINE_ROW_BYTES ) $owner->large_row( $table,$row,$rows,$emit,$hash,$budget );
							else { $plain = 'R'.self::json( [ 'position'=>$rows,'cells'=>$row['cells'] ] ); hash_update( $hash,pack( 'N',strlen( $plain ) ).$plain ); $emit( $plain,'data' ); }
							$cursor = $row['cursor']; $bytes += $row['bytes']; $all_bytes += $row['bytes']; ++$rows;
						}
					}
					if ( $owner->run( $owner->row_sql( $table,$cursor ) ) ) self::fail( 'The native database snapshot contains unexpected extra rows.' );
					$current = $owner->describe( $table['name'],$names );
					if ( $current['rows'] !== $table['rows'] || ! hash_equals( $table['schemaSha256'],$current['schemaSha256'] ) ) self::fail( 'The native database schema changed during capture.' );
					$total = [ 'name'=>$table['name'],'rows'=>$rows,'bytes'=>$bytes,'sha256'=>hash_final( $hash ) ]; $totals[] = $total; $emit( 'E'.self::json( $total ),'data' );
				}
				if ( $owner->catalog( $wpdb->prefix ) !== $catalog ) self::fail( 'The native table catalog changed during capture.' ); $owner->triggers_absent( $wpdb->prefix,$catalog ); $owner->assert_transaction();
				if ( $checkpoint ) $checkpoint( 'closing',$read_view ); $owner->assert_transaction();
			} finally { $owner->end(); }
			$emit( self::json( [ 'schema'=>self::SCHEMA,'tables'=>count( $totals ),'rows'=>$all_rows,'bytes'=>$all_bytes,'frames'=>$sequence,'transcript'=>hash_final( $transcript ) ] ),'manifest' );
			if ( ! fflush( $stream ) ) self::fail( 'The portable database backup could not be flushed.' );
			return [ 'schema'=>self::SCHEMA,'archiveId'=>$cipher->header()['archiveId'],'tables'=>count( $totals ),'rows'=>$all_rows,'bytes'=>$all_bytes,'frames'=>$sequence,'outputBytes'=>$output,'sha256'=>hash_final( $transport ),'transactionEnded'=>true ];
		} finally { $wpdb->suppress_errors( $previous ); }
	}
	private static function table( array $table, string $prefix, array $known ): void {
		if ( ! self::keys( $table,[ 'name','create','columns','primary','rows','schemaSha256' ] ) || ! is_string( $table['name'] ) || ! str_starts_with( $table['name'],$prefix ) || ! is_string( $table['create'] ) || ! is_array( $table['columns'] ) || ! array_is_list( $table['columns'] ) || ! $table['columns'] || count( $table['columns'] ) > self::MAX_COLUMNS || ! is_array( $table['primary'] ) || ! array_is_list( $table['primary'] ) || ! $table['primary'] || ! is_int( $table['rows'] ) || $table['rows'] < 0 || $table['rows'] > self::MAX_TABLE_ROWS || ! is_string( $table['schemaSha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$table['schemaSha256'] ) ) self::fail( 'A portable database table is invalid.' ); self::identifier( $table['name'] ); $seen = [];
		foreach ( $table['columns'] as $col ) {
			if ( ! is_array( $col ) || ! self::keys( $col,[ 'name','type','nullable','extra','collation' ] ) || ! is_string( $col['name'] ) || ! is_string( $col['type'] ) || strlen( $col['type'] ) > 16384 || ! is_bool( $col['nullable'] ) || ! is_string( $col['extra'] ) || strlen( $col['extra'] ) > 128 || ( null !== $col['collation'] && ! is_string( $col['collation'] ) ) || isset( $seen[$col['name']] ) || preg_match( '/\b(?:GENERATED|INVISIBLE|ROW START|ROW END)\b/i',$col['extra'] ) ) self::fail( 'A portable database column is invalid.' ); self::identifier( $col['name'] ); $seen[$col['name']] = true;
		}
		$seen_primary = [];
		foreach ( $table['primary'] as $col ) { if ( ! is_string( $col ) || ! isset( $seen[$col] ) || isset( $seen_primary[$col] ) ) self::fail( 'A portable database primary key is invalid.' ); $seen_primary[$col] = true; }
		$stable = self::ddl( $table['name'],$table['create'],$known );
		if ( ! hash_equals( $table['schemaSha256'],hash( 'sha256',self::json( [ 'create'=>$stable,'columns'=>$table['columns'],'primary'=>$table['primary'] ] ) ) ) ) self::fail( 'A portable database schema fingerprint is inconsistent.' );
	}
	/** Verifies all content before returning metadata. No row consumer or restore writes. */
	public static function verify( $stream, string $phrase, ?callable $budget = null ): array {
		return self::parse( $stream,$phrase,$budget );
	}
	/** Plain records go only to the private staging owner, never to an SQL executor. */
	public static function stage_private( $stream, #[\SensitiveParameter] string $phrase, Portable_Recovery_Stage $stage, ?callable $budget = null ): array {
		$review = self::verify( $stream,$phrase,$budget );
		if ( ! rewind( $stream ) ) self::fail( 'The reviewed database member could not be rewound.' );
		$written = self::parse( $stream,$phrase,$budget,$stage );
		if ( $written !== $review ) self::fail( 'The database member changed after complete review.' );
		return $written;
	}
	private static function parse( $stream, string $phrase, ?callable $budget, ?Portable_Recovery_Stage $stage = null ): array {
		self::stream( $stream,false ); $magic = self::read( $stream,8 ); if ( ! in_array( $magic,[ self::MAGIC,'KIWEPDB1' ],true ) ) self::fail( 'This is not a portable database member.' ); $schema = self::MAGIC === $magic ? self::SCHEMA : self::LEGACY_SCHEMA;
		$length = unpack( 'Nsize',self::read( $stream,4 ) )['size']; if ( $length < 1 || $length > 2048 ) self::fail( 'The portable database header is too large.' );
		$cipher = new Portable_Archive_Cipher( self::object( self::read( $stream,$length ) ),$phrase ); $sequence = 0; $transcript = hash_init( 'sha256' ); $index = null; $tables = []; $totals = []; $table = null; $rows = 0; $bytes = 0; $all_rows = 0; $all_bytes = 0; $hash = null; $metadata = 0; $large = null; $record_bytes = 8;
		while ( true ) {
			if ( $budget ) $budget(); if ( $sequence >= self::MAX_FRAMES ) self::fail( 'The portable database backup has too many frames.' ); $envelope = self::read( $stream,5 ); $kind = $envelope[0]; $length = unpack( 'Nsize',substr( $envelope,1 ) )['size'];
			if ( ! in_array( $kind,[ 'I','D','M' ],true ) || ( 0 === $sequence ) !== ( 'I' === $kind ) || $length < 45 || $length > Portable_Archive_Cipher::CHUNK_BYTES+45 ) self::fail( 'The portable database envelope is invalid.' );
			$frame = self::read( $stream,$length ); $plain = $cipher->open( $frame,$sequence,'I' === $kind ? 'index' : ( 'M' === $kind ? 'manifest' : 'data' ) ); $record_bytes += 5+strlen( $plain );
			if ( 'I' === $kind ) {
				$index = self::object( $plain );
				if ( ! self::keys( $index,[ 'schema','prefix','source','tables','rows' ] ) || $schema !== $index['schema'] || ! is_string( $index['prefix'] ) || ! preg_match( '/^[a-zA-Z0-9_]{1,48}$/D',$index['prefix'] ) || ! is_array( $index['source'] ) || ! self::keys( $index['source'],[ 'family','version','charset','collation','sqlMode','timeZone' ] ) || ! in_array( $index['source']['family'],[ 'MySQL','MariaDB' ],true ) || ! is_int( $index['tables'] ) || $index['tables'] < count( self::CORE ) || $index['tables'] > self::MAX_TABLES || ! is_int( $index['rows'] ) || $index['rows'] < 0 || $index['rows'] > self::MAX_ROWS ) self::fail( 'The portable database index is invalid.' );
				foreach ( $index['source'] as $value ) if ( ! is_string( $value ) || strlen( $value ) > 4096 ) self::fail( 'The portable database source metadata is invalid.' );
			} elseif ( 'M' === $kind ) {
				if ( null !== $table ) self::fail( 'The portable database ends within a table.' ); $final = self::object( $plain );
				if ( ! self::keys( $final,[ 'schema','tables','rows','bytes','frames','transcript' ] ) || $final['schema'] !== $schema || $final['tables'] !== count( $tables ) || $final['tables'] !== $index['tables'] || $final['rows'] !== $all_rows || $final['rows'] !== $index['rows'] || $final['bytes'] !== $all_bytes || $final['frames'] !== $sequence || ! is_string( $final['transcript'] ) || ! hash_equals( hash_final( $transcript ),$final['transcript'] ) ) self::fail( 'The portable database completeness check failed.' );
				$names = array_column( $tables,'name' ); foreach ( self::CORE as $suffix ) if ( ! in_array( $index['prefix'].$suffix,$names,true ) ) self::fail( 'The portable WordPress table set is incomplete.' ); foreach ( $tables as $reviewed ) self::table( $reviewed,$index['prefix'],$names );
				$tail = @fread( $stream,1 ); if ( false === $tail || '' !== $tail || ! feof( $stream ) ) self::fail( 'The portable database contains trailing content.' );
				if ( $stage ) $stage->database_record( $kind,$plain );
				return [ 'schema'=>$schema,'archiveId'=>$cipher->header()['archiveId'],'source'=>$index['source'],'prefix'=>$index['prefix'],'frames'=>$sequence+1,'tables'=>$tables,'totals'=>$totals,'rows'=>$all_rows,'bytes'=>$all_bytes,'recordBytes'=>$record_bytes ];
			} else {
				if ( '' === $plain ) self::fail( 'A portable database record is empty.' ); $type = $plain[0]; $body = substr( $plain,1 );
				if ( null !== $large && ! in_array( $type,[ 'C','Z' ],true ) ) self::fail( 'A portable large row is incomplete or interrupted.' );
				if ( 'C' !== $type ) $record = self::object( $body );
				if ( 'T' === $type ) {
					if ( null !== $table || count( $tables ) >= $index['tables'] ) self::fail( 'A portable table is misplaced.' ); $metadata += strlen( $body );
					if ( $metadata > self::MAX_METADATA || ! is_string( $record['name'] ?? null ) || ( $tables && strcmp( $tables[count( $tables )-1]['name'],$record['name'] ) >= 0 ) ) self::fail( 'Portable table metadata is too large or out of order.' );
					$foreign = []; preg_match_all( '/\bREFERENCES\s+\x60([^\x60]+)\x60/i',(string)( $record['create'] ?? '' ),$foreign ); self::table( $record,$index['prefix'],array_merge( [ $record['name'] ],$foreign[1] ?? [] ) ); $table = $record; $rows = 0; $bytes = 0; $hash = hash_init( 'sha256' );
				} elseif ( 'R' === $type ) {
					if ( null === $table || ! self::keys( $record,[ 'position','cells' ] ) || $record['position'] !== $rows || $rows >= $table['rows'] || ! is_array( $record['cells'] ) || ! array_is_list( $record['cells'] ) || count( $record['cells'] ) !== count( $table['columns'] ) ) self::fail( 'A portable database row is misplaced or invalid.' ); $row_bytes = 0;
					foreach ( $record['cells'] as $i=>$cell ) {
						if ( ! is_array( $cell ) || ! array_is_list( $cell ) ) self::fail( 'A portable database cell is invalid.' );
						if ( [ 'n' ] === $cell ) { if ( ! $table['columns'][$i]['nullable'] ) self::fail( 'A portable database NULL violates its column definition.' ); continue; }
						if ( count( $cell ) !== 2 || 'b' !== $cell[0] || ! is_string( $cell[1] ) || strlen( $cell[1] ) > (int)ceil( self::INLINE_ROW_BYTES/3 )*4 ) self::fail( 'A portable database cell is invalid.' );
						$raw = base64_decode( $cell[1],true ); if ( ! is_string( $raw ) || base64_encode( $raw ) !== $cell[1] ) self::fail( 'A portable database binary cell is damaged.' ); $row_bytes += strlen( $raw );
					}
					if ( $row_bytes > self::INLINE_ROW_BYTES || $all_bytes+$row_bytes > self::MAX_BYTES || $all_rows >= self::MAX_ROWS ) self::fail( 'Portable database rows exceed their supported byte or row limit.' ); $bytes += $row_bytes; $all_bytes += $row_bytes; ++$rows; ++$all_rows; hash_update( $hash,pack( 'N',strlen( $plain ) ).$plain );
				} elseif ( 'L' === $type ) {
					if ( self::SCHEMA !== $schema || null === $table || ! self::keys( $record,[ 'position','cells' ] ) || $record['position'] !== $rows || $rows >= $table['rows'] || ! is_array( $record['cells'] ) || ! array_is_list( $record['cells'] ) || count( $record['cells'] ) !== count( $table['columns'] ) ) self::fail( 'A portable large row is misplaced or invalid.' ); $size = 0;
					foreach ( $record['cells'] as $i=>$length ) {
						if ( null === $length ) { if ( ! $table['columns'][$i]['nullable'] ) self::fail( 'A portable database NULL violates its column definition.' ); }
						elseif ( ! is_int( $length ) || $length < 0 || $length > self::MAX_ROW_BYTES ) self::fail( 'A portable large cell length is invalid.' ); else $size += $length;
					}
					if ( $size <= self::INLINE_ROW_BYTES || $size > self::MAX_ROW_BYTES || $all_bytes+$size > self::MAX_BYTES || $all_rows >= self::MAX_ROWS ) self::fail( 'A portable large row exceeds its supported byte or row limit.' );
					$large = [ 'bytes'=>$size,'offset'=>0,'hash'=>hash_init( 'sha256' ) ]; $packet = pack( 'N',strlen( $plain ) ).$plain; hash_update( $large['hash'],$packet ); hash_update( $hash,$packet );
				} elseif ( 'C' === $type ) {
					if ( self::SCHEMA !== $schema || null === $large || strlen( $body ) < 8 ) self::fail( 'A portable large-row chunk is misplaced.' ); $chunk = unpack( 'Noffset/Nbytes',substr( $body,0,8 ) ); $expected = min( self::ROW_CHUNK_BYTES,$large['bytes']-$large['offset'] );
					if ( $expected < 1 || $chunk['offset'] !== $large['offset'] || $chunk['bytes'] !== $expected || strlen( $body ) !== 8+$expected ) self::fail( 'A portable large-row chunk is incomplete or out of order.' ); $large['offset'] += $expected; $packet = pack( 'N',strlen( $plain ) ).$plain; hash_update( $large['hash'],$packet ); hash_update( $hash,$packet );
				} elseif ( 'Z' === $type ) {
					if ( self::SCHEMA !== $schema || null === $large || ! self::keys( $record,[ 'position','bytes','sha256' ] ) || $record['position'] !== $rows || $record['bytes'] !== $large['bytes'] || $large['offset'] !== $large['bytes'] || ! is_string( $record['sha256'] ) || ! hash_equals( hash_final( $large['hash'] ),$record['sha256'] ) ) self::fail( 'A portable large row is incomplete or damaged.' ); $bytes += $large['bytes']; $all_bytes += $large['bytes']; ++$rows; ++$all_rows; $large = null; hash_update( $hash,pack( 'N',strlen( $plain ) ).$plain );
				} elseif ( 'E' === $type ) {
					if ( null === $table || ! self::keys( $record,[ 'name','rows','bytes','sha256' ] ) || $record['name'] !== $table['name'] || $record['rows'] !== $rows || $rows !== $table['rows'] || $record['bytes'] !== $bytes || ! is_string( $record['sha256'] ) || ! hash_equals( hash_final( $hash ),$record['sha256'] ) ) self::fail( 'A portable database table is incomplete or damaged.' ); $tables[] = $table; $totals[] = $record; $table = null; $hash = null;
				} else self::fail( 'A portable database record is unsupported.' );
			}
			if ( $stage ) $stage->database_record( $kind,$plain );
			hash_update( $transcript,$envelope.$frame ); ++$sequence;
		}
	}
}
