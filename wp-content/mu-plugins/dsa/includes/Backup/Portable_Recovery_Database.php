<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Independent, inactive native database recovery. No WordPress bootstrap or live prefix switch. */
final class Portable_Recovery_Database {
	public const SCHEMA = 'kiwe.private-recovery-database.v1';
	private const MAGIC = 'KIWEPRD1';
	private const MAX_TABLES = 128;
	private \mysqli $db;
	private string $connection;
	private string $binding;
	private string $directory;
	private string $public_root;
	private array $state = [];
	private array $owned = [];
	private Portable_Archive_Cipher $cipher;
	private $budget;
	private ?\mysqli_stmt $insert_statement = null;
	private ?string $insert_table = null;
	private int $inserted = 0;
	private ?Portable_Recovery_Migration $migration = null;
	private static function fail( string $message = 'Private native database recovery could not be verified.' ): never { throw new Archive_Error( $message ); }
	private static function json( array $value ): string { return json_encode( $value,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); }
	private static function normalize( string $path ): string { return rtrim( 'Windows' === PHP_OS_FAMILY ? str_replace( '\\','/',$path ) : $path,'/' ); }
	private static function ordinary( string $path, bool $directory, bool $private = true ): array {
		if ( '' === $path || strlen( $path ) > 8192 || preg_match( '/[\x00-\x1f\x7f]/',$path ) || ! ( $real = realpath( $path ) ) || self::normalize( $real ) !== self::normalize( $path ) ) self::fail();
		$cursor = $path; while ( true ) { if ( is_link( $cursor ) ) self::fail(); $next = dirname( $cursor ); if ( $next === $cursor ) break; $cursor = $next; }
		clearstatcache( true,$path ); $s = @lstat( $path );
		if ( ! $s || ( $s['mode'] & 0170000 ) !== ( $directory ? 0040000 : 0100000 ) || ! is_readable( $path ) || ( ! $directory && $s['nlink'] !== 1 ) ) self::fail();
		if ( $private && 'Windows' !== PHP_OS_FAMILY && ( ( $s['mode'] & 0077 ) || ( function_exists( 'posix_geteuid' ) && $s['uid'] !== posix_geteuid() ) ) ) self::fail( 'A private owner directory is required for database recovery.' );
		return $s;
	}
	private static function separate( string $parent, string $public_root ): string {
		self::ordinary( $parent,true ); $stat = self::ordinary( $public_root,true,false ); $a = self::normalize( $parent ); $b = self::normalize( $public_root );
		$compare_a = 'Windows' === PHP_OS_FAMILY ? strtolower( $a ) : $a; $compare_b = 'Windows' === PHP_OS_FAMILY ? strtolower( $b ) : $b;
		if ( $compare_a === $compare_b || str_starts_with( $compare_a,$compare_b.'/' ) || str_starts_with( $compare_b,$compare_a.'/' ) ) self::fail();
		return hash( 'sha256',self::json( [ $b,$stat['dev'],$stat['ino'] ] ) );
	}
	private static function config_hash( string $root ): string { self::ordinary( $root.'/wp-config.php',false,false ); return hash_file( 'sha256',$root.'/wp-config.php' ); }
	/** A literal prepared prefix is required. Do not execute the destination or captured configuration. */
	private static function live_prefix( string $root ): string {
		$s = self::ordinary( $root.'/wp-config.php',false,false ); if ( $s['size'] > 1048576 ) self::fail();
		$tokens = array_values( array_filter( token_get_all( file_get_contents( $root.'/wp-config.php' ) ),static fn( $t )=>! is_array( $t ) || ! in_array( $t[0],[ T_WHITESPACE,T_COMMENT,T_DOC_COMMENT ],true ) ) ); $prefix = null; $depth = 0;
		foreach ( $tokens as $i=>$t ) {
			if ( $t === '{' ) ++$depth; elseif ( $t === '}' ) --$depth;
			if ( ! is_array( $t ) || $t[0] !== T_VARIABLE || $t[1] !== '$table_prefix' ) continue;
			$value = $tokens[$i+2] ?? null;
			if ( $prefix !== null || $depth !== 0 || ( $tokens[$i+1] ?? null ) !== '=' || ! is_array( $value ) || $value[0] !== T_CONSTANT_ENCAPSED_STRING || ( $tokens[$i+3] ?? null ) !== ';' || ! preg_match( '/^([\x27\x22])([a-zA-Z0-9_]{1,48})\1$/D',$value[1],$m ) ) self::fail( 'Recovery requires one ordinary literal destination table prefix.' );
			$prefix = $m[2];
		}
		if ( ! $prefix ) self::fail( 'The prepared destination table prefix cannot be verified.' ); return $prefix;
	}
	private static function local(): void { if ( PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80200 ) self::fail(); }
	/** MySQL identifiers are at most 64 bytes. The encrypted receipt, not this namespace, grants ownership. */
	private static function recovery_prefix( string $id, array $database ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D',$id ) || empty( $database['tables'] ) || empty( $database['prefix'] ) ) self::fail();
		$longest = 0;
		foreach ( $database['tables'] as $table ) {
			if ( ! is_string( $table['name'] ?? null ) || ! str_starts_with( $table['name'],$database['prefix'] ) ) self::fail();
			$longest = max( $longest,strlen( $table['name'] )-strlen( $database['prefix'] ) );
		}
		$digits = min( 24,59-$longest );
		if ( $digits < 12 ) self::fail( 'A captured table suffix is too long for an isolated recovery namespace.' );
		return 'krd_'.substr( $id,0,$digits ).'_';
	}
	private static function valid_prefix( string $prefix, string $id ): bool {
		return preg_match( '/^krd_([a-f0-9]{12,24})_$/D',$prefix,$m ) === 1 && $m[1] === substr( $id,0,strlen( $m[1] ) );
	}
	/** Private operator configuration, never taken from captured PHP or options. */
	public static function connection_file( string $path ): array {
		self::local(); $s = self::ordinary( $path,false ); if ( $s['size'] > 16384 ) self::fail();
		try { $value = json_decode( file_get_contents( $path ),true,8,JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { self::fail(); }
		if ( ! is_array( $value ) ) self::fail(); self::configuration( $value ); return $value;
	}
	private static function configuration( #[\SensitiveParameter] array $value ): void {
		if ( count( $value ) !== 5 || array_diff( [ 'host','port','database','username','password' ],array_keys( $value ) ) || ! in_array( $value['host'],[ 'localhost','127.0.0.1','::1' ],true ) || ! is_int( $value['port'] ) || $value['port'] < 1 || $value['port'] > 65535 ) self::fail( 'Recovery requires a separate local native database connection.' );
		foreach ( [ 'database','username','password' ] as $key ) if ( ! is_string( $value[$key] ) || '' === $value[$key] || strlen( $value[$key] ) > 4096 || str_contains( $value[$key],"\0" ) ) self::fail();
	}
	private function __construct( #[\SensitiveParameter] array $config, #[\SensitiveParameter] string $phrase, ?callable $budget ) {
		self::configuration( $config ); if ( ! extension_loaded( 'mysqli' ) ) self::fail( 'The native mysqli extension is required for database recovery.' ); $this->budget = $budget;
		try { $this->db = new \mysqli( $config['host'],$config['username'],$config['password'],$config['database'],$config['port'] ); if ( $this->db->connect_errno || ! $this->db->set_charset( 'utf8mb4' ) ) self::fail(); }
		catch ( \Throwable $error ) { self::fail( 'The private native database connection is unavailable.' ); }
		try {
			$r = $this->raw( 'SELECT CONNECTION_ID(),DATABASE(),@@server_id,@@hostname,VERSION(),@@version_comment,@@max_allowed_packet,@@SESSION.autocommit' );
			if ( count( $r ) !== 1 || count( $r[0] ) !== 8 || ! preg_match( '/^[1-9][0-9]*$/D',(string)$r[0][0] ) || $r[0][1] !== $config['database'] || (int)$r[0][6] < Portable_Database_Capture::MAX_ROW_BYTES+65536 || (string)$r[0][7] !== '1' ) self::fail();
			$version = (string)$r[0][4]; $maria = str_contains( $version.' '.$r[0][5],'MariaDB' );
			if ( ! preg_match( '/^(?:5\.5\.5-)?([0-9]+\.[0-9]+\.[0-9]+)/',$version,$m ) || version_compare( $m[1],$maria ? '10.6.0' : '8.0.0','<' ) || ( ! $maria && stripos( (string)$r[0][5],'mysql' ) === false ) ) self::fail( 'This native server is outside the supported recovery scope.' );
			$this->connection = (string)$r[0][0]; $this->binding = hash( 'sha256',self::json( [ $config['host'],$config['port'],$config['database'],$config['username'],$r[0][2],$r[0][3] ] ) );
			if ( ( $this->raw( 'SELECT @@innodb_table_locks' )[0][0] ?? null ) !== '1' ) self::fail();
			if ( $maria && $this->raw( "SHOW VARIABLES LIKE 'wsrep_on'" ) && ( $this->raw( "SHOW VARIABLES LIKE 'wsrep_on'" )[0][1] ?? '' ) !== 'OFF' ) self::fail( 'Clustered native recovery is unsupported.' );
			$this->grants( $config['database'] ); $this->run( "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO'" ); $this->run( "SET SESSION time_zone='+00:00'" ); $this->run( 'SET SESSION lock_wait_timeout=5' ); $this->run( 'SET SESSION auto_increment_increment=1,auto_increment_offset=1' );
			$this->cipher = new Portable_Archive_Cipher( Portable_Archive_Cipher::new_header(),$phrase );
		} catch ( \Throwable $error ) { $this->db->close(); throw $error; }
	}
	private function raw( string $sql ): array {
		try { $result = $this->db->query( $sql ); if ( false === $result ) self::fail(); if ( $result === true ) return []; $rows = $result->fetch_all( MYSQLI_NUM ); $result->free(); return $rows; } catch ( \Throwable $error ) { self::fail(); }
	}
	private function same(): void { if ( ( $this->raw( 'SELECT CONNECTION_ID()' )[0][0] ?? null ) !== $this->connection ) self::fail( 'The native recovery connection changed; retain the private receipt for inspection.' ); }
	private function run( string $sql ): array {
		$this->same(); $value = $this->raw( $sql ); $warnings = $this->raw( 'SHOW WARNINGS' ); $this->same(); if ( $warnings ) self::fail( 'The server reported an unqualified recovery operation.' ); return $value;
	}
	private function tick(): void { if ( $this->budget ) ( $this->budget )(); }
	/** Keep the same leased native session alive while an independent file owner verifies a large stage. */
	private function heartbeat(): callable {
		$last = hrtime( true );
		return function( bool $force = false ) use ( &$last ): void {
			$this->tick();
			if ( $force || hrtime( true )-$last > 5000000000 ) { $this->same(); $last = hrtime( true ); }
		};
	}
	private function grants( string $database ): void {
		foreach ( $this->raw( 'SHOW GRANTS' ) as $row ) {
			if ( count( $row ) !== 1 || ! preg_match( '/^GRANT (.+?) ON (\*|\x60(?:[^\x60]|\x60\x60)+\x60)\.\* TO /i',(string)$row[0],$m ) ) continue;
			$scope = '*' === $m[2] ? '*' : str_replace( [ '``','\\_','\\%' ],[ '`','_','%' ],substr( $m[2],1,-1 ) ); $rights = array_map( 'trim',explode( ',',strtoupper( $m[1] ) ) );
			if ( ( '*' === $scope || $database === $scope ) && ( in_array( 'ALL PRIVILEGES',$rights,true ) || ! array_diff( [ 'SELECT','CREATE','DROP','INSERT','TRIGGER','REFERENCES','LOCK TABLES' ],$rights ) ) ) return;
		} self::fail( 'Schema-wide native recovery rights and dependency visibility are required.' );
	}
	private function quoted( string $value ): string { return "X'".bin2hex( $value )."'"; }
	private function catalog( string $prefix ): array {
		$rows = $this->run( 'SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND BINARY LEFT(TABLE_NAME,'.strlen( $prefix ).')=BINARY '.$this->quoted( $prefix ).' ORDER BY BINARY TABLE_NAME' );
		if ( count( $rows ) > self::MAX_TABLES ) self::fail(); $out = [];
		foreach ( $rows as $row ) { if ( count( $row ) !== 3 || $row[1] !== 'BASE TABLE' || $row[2] !== 'InnoDB' ) self::fail(); $out[] = $row[0]; } return $out;
	}
	private function lease( string $parent ): array {
		self::ordinary( $parent,true ); $path = $parent.'/.kiwe-database.lock'; $file = @fopen( $path,'x+b' );
		if ( $file ) { if ( ! @chmod( $path,0600 ) ) { fclose( $file ); self::fail(); } } else { self::ordinary( $path,false ); $file = @fopen( $path,'r+b' ); }
		if ( ! $file ) self::fail(); $s = self::ordinary( $path,false ); $opened = fstat( $file );
		if ( $s['size'] !== 0 || $s['dev'] !== $opened['dev'] || $s['ino'] !== $opened['ino'] || ! flock( $file,LOCK_EX | LOCK_NB ) ) { fclose( $file ); self::fail( 'Another private database recovery task owns this parent.' ); }
		$key = 'kiwe:recovery-db:'.substr( $this->binding,0,40 );
		try { if ( ( $this->run( 'SELECT GET_LOCK('.$this->quoted( $key ).',0)' )[0][0] ?? null ) !== '1' ) self::fail( 'Another database recovery task owns this native connection scope.' ); return [ $file,$key ]; }
		catch ( \Throwable $error ) { flock( $file,LOCK_UN ); fclose( $file ); throw $error; }
	}
	private function release( array $lease ): void { try { $this->raw( 'SELECT RELEASE_LOCK('.$this->quoted( $lease[1] ).')' ); } catch ( \Throwable $ignored ) {} flock( $lease[0],LOCK_UN ); fclose( $lease[0] ); }
	private function save(): void {
		$plain = self::json( $this->state ); if ( strlen( $plain ) > Portable_Archive_Cipher::CHUNK_BYTES ) self::fail(); $header = self::json( $this->cipher->header() ); $bytes = self::MAGIC.pack( 'N',strlen( $header ) ).$header.$this->cipher->seal( $plain,0,'index' );
		self::ordinary( $this->directory,true ); $path = $this->directory.'/database.next'; $stream = @fopen( $path,'x+b' ); if ( ! $stream ) self::fail();
		try { if ( ! chmod( $path,0600 ) ) self::fail(); $at = 0; while ( $at < strlen( $bytes ) ) { $n = fwrite( $stream,substr( $bytes,$at,65536 ) ); if ( ! is_int( $n ) || $n < 1 ) self::fail(); $at += $n; } if ( ! fflush( $stream ) || ( function_exists( 'fsync' ) && ! fsync( $stream ) ) ) self::fail(); }
		finally { fclose( $stream ); }
		if ( ! @rename( $path,$this->directory.'/database.receipt' ) ) self::fail();
	}
	private static function load( string $directory, #[\SensitiveParameter] string $phrase ): array {
		self::ordinary( $directory,true ); if ( ! preg_match( '/^kiwe-database-[a-f0-9]{32}$/D',basename( $directory ) ) ) self::fail();
		$entries = scandir( $directory ); if ( $entries !== [ '.','..','database.receipt' ] ) self::fail( 'The private database receipt has incomplete or unknown data.' );
		$s = self::ordinary( $directory.'/database.receipt',false ); if ( $s['size'] < 14 || $s['size'] > Portable_Archive_Cipher::CHUNK_BYTES+4096 ) self::fail(); $bytes = file_get_contents( $directory.'/database.receipt' );
		if ( substr( $bytes,0,8 ) !== self::MAGIC ) self::fail(); $n = unpack( 'Nsize',substr( $bytes,8,4 ) )['size']; if ( $n < 2 || $n > 2048 ) self::fail();
		try { $header = json_decode( substr( $bytes,12,$n ),true,8,JSON_THROW_ON_ERROR ); $cipher = new Portable_Archive_Cipher( $header,$phrase ); $state = json_decode( $cipher->open( substr( $bytes,12+$n ),0,'index' ),true,20,JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { self::fail(); }
		if ( ! is_array( $state ) || $state['schema'] !== self::SCHEMA || $state['id'] !== substr( basename( $directory ),14 ) || ! is_string( $state['prefix'] ?? null ) || ! self::valid_prefix( $state['prefix'],$state['id'] ) || $state['activated'] !== false || ! in_array( $state['status'],[ 'ready','preparing','retiring' ],true ) || ! is_array( $state['tables'] ) || count( $state['tables'] ) > self::MAX_TABLES ) self::fail();
		$migration = $state['migration'] ?? null;
		if ( $migration !== null && ( ! is_array( $migration ) || count( $migration ) !== 3 || ! is_array( $migration['policy'] ?? null ) || ( $migration['policy']['schema'] ?? null ) !== Portable_Recovery_Migration::SCHEMA || ( $migration['policy']['recoverySha256'] ?? null ) !== $state['recoverySha256'] || ! is_int( $migration['changedCells'] ?? null ) || $migration['changedCells'] < 0 || ( $migration['sha256'] ?? null ) !== hash( 'sha256',self::json( $migration['policy'] ) ) ) ) self::fail();
		$ownership = $state['ownership'] ?? null;
		if ( $ownership !== null ) {
			if ( ! is_array( $ownership ) || $migration === null || ( $ownership['schema'] ?? null ) !== Portable_Recovery_Ownership::SCHEMA || ! is_int( $ownership['ownerId'] ?? null ) || $ownership['ownerId'] < 1 || ! preg_match( '/^[a-f0-9]{64}$/D',$ownership['destinationOwnerSha256'] ?? '' ) ) self::fail();
			Portable_Recovery_Ownership::policy( $ownership['policy'] ?? [],$state['recoverySha256'] );
		}
		return $state;
	}
	private static function plan( array $database, string $prefix ): array {
		if ( count( $database['tables'] ) > self::MAX_TABLES ) self::fail( 'This recovery unit supports at most 128 native tables.' ); $plan = [];
		foreach ( $database['tables'] as $table ) {
			$name = $prefix.substr( $table['name'],strlen( $database['prefix'] ) ); $sql = Portable_Table_Definition::compile( $table['create'],$table['name'],$name ); $ast = Portable_Table_Definition::parse( $table['create'],$table['name'] );
			if ( count( $ast['columns'] ) !== count( $table['columns'] ) ) self::fail();
			foreach ( $table['columns'] as $i=>$col ) if ( $col['name'] !== $ast['columns'][$i]['name'] || $col['nullable'] !== $ast['columns'][$i]['nullable'] || Portable_Table_Definition::column_type( $col['type'] ) !== $ast['columns'][$i]['type'] || str_contains( strtolower( $col['extra'] ),'auto_increment' ) !== $ast['columns'][$i]['autoIncrement'] ) self::fail( 'Captured columns disagree with the validated table definition.' );
			$primary = array_values( array_filter( $ast['indexes'],static fn( $i )=>$i['kind'] === 'primary' ) )[0]; if ( array_column( $primary['columns'],'name' ) !== $table['primary'] ) self::fail();
			$plan[] = [ 'source'=>$table,'name'=>$name,'sql'=>$sql,'schemaHash'=>Portable_Table_Definition::fingerprint( $sql,$name ) ];
		}
		return $plan;
	}
	private function definition( string $name ): string { $r = $this->run( 'SHOW CREATE TABLE '.Portable_Table_Definition::identifier( $name ) ); if ( count( $r ) !== 1 || count( $r[0] ) !== 2 || $r[0][0] !== $name || ! is_string( $r[0][1] ) ) self::fail(); return $r[0][1]; }
	private static function row_hash( $hash, array $cells ): int {
		hash_update( $hash,pack( 'N',count( $cells ) ) ); $size = 0;
		foreach ( $cells as $cell ) { if ( null === $cell ) hash_update( $hash,"\0" ); else { if ( ! is_string( $cell ) || strlen( $cell ) > Portable_Database_Capture::MAX_ROW_BYTES ) self::fail(); $size += strlen( $cell ); hash_update( $hash,"\1".pack( 'N',strlen( $cell ) ).$cell ); } }
		if ( $size > Portable_Database_Capture::MAX_ROW_BYTES ) self::fail(); return $size;
	}
	private function descriptor( array $table, bool $tick = true ): array {
		$name = $table['name']; $sql = $this->definition( $name ); $schema = Portable_Table_Definition::fingerprint( $sql,$name );
		if ( $schema !== $table['schemaHash'] ) self::fail( 'A recovered native table has changed schema.' );
		$columns = implode( ',',array_map( static fn( $n )=>'CAST('.Portable_Table_Definition::identifier( $n ).' AS BINARY)',$table['columns'] ) ); $primary = implode( ',',array_map( [ Portable_Table_Definition::class,'identifier' ],$table['primary'] ) );
		$query = 'SELECT '.$columns.' FROM '.Portable_Table_Definition::identifier( $name ).' FORCE INDEX(PRIMARY) ORDER BY '.$primary;
		$hash = hash_init( 'sha256' ); $rows = 0; $bytes = 0; $this->same();
		try {
			$result = $this->db->query( $query,MYSQLI_USE_RESULT ); if ( ! $result instanceof \mysqli_result ) self::fail();
			try { while ( null !== ( $cells = $result->fetch_row() ) ) { if ( ! is_array( $cells ) || ++$rows > Portable_Database_Capture::MAX_TABLE_ROWS ) self::fail(); if ( $tick ) $this->tick(); $bytes += self::row_hash( $hash,$cells ); if ( $bytes > Portable_Database_Capture::MAX_BYTES ) self::fail(); } }
			finally { $result->free(); }
		} catch ( \Throwable $error ) { if ( $error instanceof Archive_Error ) throw $error; self::fail(); }
		if ( $this->raw( 'SHOW WARNINGS' ) ) self::fail(); $this->same();
		return [ 'name'=>$name,'columns'=>$table['columns'],'primary'=>$table['primary'],'schemaHash'=>$schema,'rows'=>$rows,'bytes'=>$bytes,'contentSha256'=>hash_final( $hash ),'sequence'=>Portable_Table_Definition::parse( $sql,$name )['autoIncrement'] ];
	}
	private static function next_sequence( ?string $current, ?string $value ): ?string {
		if ( null === $current || null === $value || ! preg_match( '/^[0-9]{1,20}$/D',$value ) ) return $current;
		$value = ltrim( $value,'0' ) ?: '0'; $base = ltrim( $current,'0' ) ?: '0'; if ( strlen( $value ) < strlen( $base ) || ( strlen( $value ) === strlen( $base ) && strcmp( $value,$base ) < 0 ) ) return $current;
		for ( $i = strlen( $value )-1; $i >= 0; --$i ) { if ( $value[$i] !== '9' ) { $value[$i] = (string)( (int)$value[$i]+1 ); return $value; } $value[$i] = '0'; } return '1'.$value;
	}
	private function insert( array $table, array $cells ): void {
		if ( count( $cells ) !== count( $table['columns'] ) ) self::fail();
		$names = array_column( $table['columns'],'name' ); $sql = 'INSERT INTO '.Portable_Table_Definition::identifier( $table['name'] ).' ('.implode( ',',array_map( [ Portable_Table_Definition::class,'identifier' ],$names ) ).') VALUES ('.implode( ',',array_fill( 0,count( $cells ),'?' ) ).')';
		if ( $this->inserted % 16 === 0 ) $this->same(); $stmt = null;
		try {
			if ( $this->insert_table !== $table['name'] ) { if ( $this->insert_statement ) $this->insert_statement->close(); $this->insert_statement = $this->db->prepare( $sql ); $this->insert_table = $table['name']; }
			$stmt = $this->insert_statement; if ( ! $stmt || ! $stmt->bind_param( str_repeat( 'b',count( $cells ) ),...$cells ) ) self::fail();
			foreach ( $cells as $i=>$cell ) if ( null !== $cell ) { if ( '' === $cell && ! $stmt->send_long_data( $i,'' ) ) self::fail(); for ( $at = 0; $at < strlen( $cell ); $at += 65536 ) if ( ! $stmt->send_long_data( $i,substr( $cell,$at,65536 ) ) ) self::fail(); }
			if ( ! $stmt->execute() || $stmt->affected_rows !== 1 || $this->db->warning_count !== 0 ) self::fail();
		} catch ( \Throwable $error ) { self::fail( 'A recovered row could not be stored exactly; retain uncertain private data.' ); }
		if ( ++$this->inserted % 16 === 0 ) $this->same();
	}
	private static function read( $stream, int $length ): string { $bytes = ''; while ( strlen( $bytes ) < $length ) { $p = fread( $stream,$length-strlen( $bytes ) ); if ( ! is_string( $p ) || '' === $p ) self::fail(); $bytes .= $p; } return $bytes; }
	private static function object( string $bytes ): array { try { $v = json_decode( $bytes,true,20,JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { self::fail(); } if ( ! is_array( $v ) ) self::fail(); return $v; }
	private function consume( string $path, array $plan, array $database, array $receipt ): void {
		$stream = fopen( $path,'rb' ); $digest = hash_init( 'sha256' ); $ordinal = 0; $current = null; $hash = null; $rows = 0; $bytes = 0; $source_bytes = 0; $large = null; $complete = false; $record_bytes = 8;
		try {
			$magic = self::read( $stream,8 ); if ( $magic !== 'KIWEDRS1' ) self::fail(); hash_update( $digest,$magic );
			while ( ! feof( $stream ) ) {
				$this->tick(); $head = fread( $stream,5 ); if ( '' === $head && feof( $stream ) ) break; if ( strlen( $head ) !== 5 || $complete ) self::fail(); $n = unpack( 'Nsize',substr( $head,1 ) )['size'];
				if ( $n < 1 || $n > Portable_Archive_Cipher::CHUNK_BYTES ) self::fail(); $plain = self::read( $stream,$n ); hash_update( $digest,$head.$plain ); $record_bytes += 5+$n;
				if ( $record_bytes > $receipt['bytes'] ) self::fail();
				if ( $head[0] === 'I' ) { if ( $ordinal || $current || $record_bytes !== 13+$n || self::object( $plain )['prefix'] !== $database['prefix'] ) self::fail(); continue; }
				if ( $head[0] === 'M' ) { if ( $current || $ordinal !== count( $plan ) ) self::fail(); $complete = true; continue; }
				if ( $head[0] !== 'D' || ( $large && ! in_array( $plain[0],[ 'C','Z' ],true ) ) ) self::fail(); $type = $plain[0]; $body = substr( $plain,1 ); $r = $type === 'C' ? null : self::object( $body );
				if ( $type === 'T' ) {
					if ( $current || ! isset( $plan[$ordinal] ) || $r !== $plan[$ordinal]['source'] ) self::fail(); $p = $plan[$ordinal];
					$this->state['pending'] = $p['name']; $this->save(); $this->run( $p['sql'] );
					$current = [ 'name'=>$p['name'],'columns'=>array_column( $r['columns'],'name' ),'primary'=>$r['primary'],'schemaHash'=>$p['schemaHash'],'rows'=>0,'bytes'=>0,'contentSha256'=>hash( 'sha256','' ) ];
					$this->owned[$current['name']] = $current; $current = $this->descriptor( $current ); $this->owned[$current['name']] = $current; $hash = hash_init( 'sha256' ); $rows = 0; $bytes = 0; $source_bytes = 0; continue;
				} elseif ( $type === 'R' || $type === 'L' ) {
					if ( ! $current || ( $r['position'] ?? null ) !== $rows || ! is_array( $r['cells'] ?? null ) || count( $r['cells'] ) !== count( $current['columns'] ) ) self::fail();
					if ( $type === 'L' ) { $lengths = $r['cells']; $total = 0; foreach ( $lengths as $len ) if ( null !== $len ) { if ( ! is_int( $len ) || $len < 0 || $len > Portable_Database_Capture::MAX_ROW_BYTES ) self::fail(); $total += $len; } if ( $total > Portable_Database_Capture::MAX_ROW_BYTES ) self::fail(); $large = [ 'cells'=>$lengths,'data'=>'','bytes'=>$total ]; continue; }
					$cells = []; foreach ( $r['cells'] as $c ) { if ( $c === [ 'n' ] ) $cells[] = null; elseif ( is_array( $c ) && count( $c ) === 2 && $c[0] === 'b' && is_string( $c[1] ) && false !== ( $v = base64_decode( $c[1],true ) ) && base64_encode( $v ) === $c[1] ) $cells[] = $v; else self::fail(); }
				} elseif ( $type === 'C' ) {
					if ( ! $large || strlen( $body ) < 8 ) self::fail(); $chunk = unpack( 'Noffset/Nbytes',substr( $body,0,8 ) ); if ( $chunk['offset'] !== strlen( $large['data'] ) || $chunk['bytes'] !== strlen( $body )-8 || strlen( $large['data'] )+$chunk['bytes'] > $large['bytes'] ) self::fail(); $large['data'] .= substr( $body,8 ); continue;
				} elseif ( $type === 'Z' ) {
					if ( ! $large || ( $r['position'] ?? null ) !== $rows || strlen( $large['data'] ) !== $large['bytes'] ) self::fail(); $cells = []; $at = 0; foreach ( $large['cells'] as $len ) { $cells[] = null === $len ? null : substr( $large['data'],$at,$len ); $at += $len ?? 0; } $large = null;
				} elseif ( $type === 'E' ) {
					if ( ! $current || $rows !== $plan[$ordinal]['source']['rows'] || $r['rows'] !== $rows || $r['bytes'] !== $source_bytes ) self::fail();
					$expected = $current; $expected['rows'] = $rows; $expected['bytes'] = $bytes; $expected['contentSha256'] = hash_final( $hash );
					$actual = $this->descriptor( $expected ); if ( $actual !== $expected ) self::fail( 'Recovered native cells differ from the reviewed data.' ); $this->owned[$actual['name']] = $actual; $this->state['tables'][] = $actual; $this->state['pending'] = null; $this->save(); $current = null; ++$ordinal; continue;
				} else self::fail();
				if ( ! isset( $cells ) || ! $current || $rows >= $plan[$ordinal]['source']['rows'] ) self::fail();
				// Track only acknowledged writes. A lost acknowledgement leaves uncertain data for owner inspection.
				$source = $plan[$ordinal]['source']; $source_bytes += self::row_hash( hash_copy( $hash ),$cells ); if ( $this->migration ) $cells = $this->migration->row( $source,$cells,$this->state['prefix'] ); $source['name'] = $current['name']; self::row_hash( hash_copy( $hash ),$cells ); $this->insert( $source,$cells ); $bytes += self::row_hash( $hash,$cells ); ++$rows;
				$ack = $current; $ack['rows'] = $rows; $ack['bytes'] = $bytes; $ack['contentSha256'] = hash_final( hash_copy( $hash ) );
				foreach ( $source['columns'] as $i=>$c ) if ( str_contains( strtolower( $c['extra'] ),'auto_increment' ) ) $ack['sequence'] = self::next_sequence( $ack['sequence'],$cells[$i] );
				unset( $cells ); $this->owned[$current['name']] = $ack; $current = $ack;
			}
			if ( ! $complete || $record_bytes !== $receipt['bytes'] || ! hash_equals( $receipt['sha256'],hash_final( $digest ) ) ) self::fail(); if ( $this->migration ) $this->migration->complete();
		} finally { fclose( $stream ); }
	}
	private function exact( array $tables, bool $tick = true ): void {
		$names = array_column( $tables,'name' ); sort( $names,SORT_STRING ); if ( $this->catalog( $this->state['prefix'] ) !== $names ) self::fail( 'The inactive recovery namespace has unknown or missing tables.' );
		foreach ( $tables as $table ) {
			if ( ! str_starts_with( $table['name'],$this->state['prefix'] ) || $this->descriptor( $table,$tick ) !== $table ) self::fail( 'Recovered tables contain changed or newer data.' );
		}
		$prefix = $this->quoted( $this->state['prefix'] ); $length = strlen( $this->state['prefix'] );
		if ( $this->run( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND BINARY LEFT(EVENT_OBJECT_TABLE,'.$length.')=BINARY '.$prefix.' LIMIT 1' ) || $this->run( 'SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND BINARY LEFT(REFERENCED_TABLE_NAME,'.$length.')=BINARY '.$prefix.' LIMIT 1' ) ) self::fail( 'Inactive recovered tables have new trigger or reference dependencies.' );
	}
	private function remove( array $tables ): void {
		if ( scandir( $this->directory ) !== [ '.','..','database.receipt' ] || self::config_hash( $this->public_root ) !== $this->state['configSha256'] || self::live_prefix( $this->public_root ) !== $this->state['livePrefix'] ) self::fail();
		$locked = false;
		try {
			if ( $this->insert_statement ) { $this->insert_statement->close(); $this->insert_statement = null; $this->insert_table = null; }
			if ( $tables ) { $this->run( 'SET SESSION autocommit=0' ); $this->run( 'LOCK TABLES '.implode( ',',array_map( static fn( $t )=>Portable_Table_Definition::identifier( $t['name'] ).' WRITE',$tables ) ) ); $locked = true; }
			$this->exact( $tables,false );
			if ( self::config_hash( $this->public_root ) !== $this->state['configSha256'] || scandir( $this->directory ) !== [ '.','..','database.receipt' ] ) self::fail();
			$this->state['status'] = 'retiring'; $this->save();
			if ( $tables ) $this->run( 'DROP TABLE '.implode( ',',array_map( static fn( $t )=>Portable_Table_Definition::identifier( $t['name'] ),$tables ) ) ); if ( $this->catalog( $this->state['prefix'] ) ) self::fail();
		} finally { if ( $locked ) $this->raw( 'UNLOCK TABLES' ); $this->raw( 'ROLLBACK' ); $this->raw( 'SET SESSION autocommit=1' ); }
		self::ordinary( $this->directory.'/database.receipt',false ); if ( ! unlink( $this->directory.'/database.receipt' ) || ! rmdir( $this->directory ) ) self::fail();
	}
	public static function prepare( #[\SensitiveParameter] array $connection, string $stage, string $public_root, #[\SensitiveParameter] string $phrase, string $parent, ?callable $budget = null, ?array $migration = null, ?array $ownership = null, #[\SensitiveParameter] ?string $owner_password = null ): array {
		self::local(); $binding = self::separate( $parent,$public_root ); self::separate( $parent,$stage ); $config_hash = self::config_hash( $public_root ); $live_prefix = self::live_prefix( $public_root ); $review = Portable_Recovery_Stage::inspect( $stage,$public_root,$phrase,$budget );
		$transform = $migration === null ? null : Portable_Recovery_Migration::review( $migration,$review,$public_root );
		if ( $ownership !== null ) { Portable_Recovery_Ownership::policy( $ownership,$review['receipt']['recoverySha256'] ); if ( ! $transform || $owner_password === null ) self::fail(); } elseif ( $owner_password !== null ) self::fail();
		$id = bin2hex( random_bytes( 16 ) ); $prefix = self::recovery_prefix( $id,$review['database'] ); $plan = self::plan( $review['database'],$prefix );
		if ( str_starts_with( $prefix,$live_prefix ) || str_starts_with( $live_prefix,$prefix ) ) self::fail();
		$owner = new self( $connection,$phrase,$budget ); $owner->public_root = $public_root; $owner->migration = $transform; $lease = null; $allocated = false;
		try {
			$lease = $owner->lease( $parent );
			$query = static function( string $sql ) use ( $owner ): array { $owner->tick(); return $owner->run( $sql ); };
			$destination = $ownership === null ? null : Portable_Recovery_Ownership::destination( $query,$live_prefix,$ownership,$owner_password );
			$count = 0; $seen = 0; foreach ( new \FilesystemIterator( $parent,\FilesystemIterator::SKIP_DOTS ) as $entry ) { if ( ++$seen > 1000 ) self::fail(); if ( preg_match( '/^kiwe-database-[a-f0-9]{32}$/D',$entry->getFilename() ) && ++$count >= 4 ) self::fail( 'Retire an inactive database attempt before preparing another.' ); }
			if ( $owner->catalog( $prefix ) ) self::fail(); $free = disk_free_space( $parent ); if ( ! is_float( $free ) || $free < 67108864 ) self::fail();
			$zone = $review['database']['source']['timeZone']; $timestamp = false; foreach ( $plan as $p ) foreach ( $p['source']['columns'] as $c ) if ( preg_match( '/^timestamp\b/i',$c['type'] ) ) $timestamp = true;
			if ( preg_match( '/^[+-](?:0[0-9]|1[0-3]):[0-5][0-9]$/D',$zone ) ) $owner->run( "SET SESSION time_zone='".$zone."'" ); elseif ( $timestamp ) self::fail( 'Timestamp recovery requires an explicit captured timezone offset.' );
			$owner->directory = self::normalize( $parent ).'/kiwe-database-'.$id; if ( ! mkdir( $owner->directory,0700 ) ) self::fail(); $allocated = true; if ( ! chmod( $owner->directory,0700 ) ) self::fail();
			$owner->state = [ 'schema'=>self::SCHEMA,'id'=>$id,'prefix'=>$prefix,'livePrefix'=>$live_prefix,'databaseBinding'=>$owner->binding,'publicRootBinding'=>$binding,'configSha256'=>$config_hash,'recoverySha256'=>$review['receipt']['recoverySha256'],'recordsSha256'=>$review['receipt']['records']['sha256'],'timeZone'=>preg_match( '/^[+-]/',$zone ) ? $zone : '+00:00','status'=>'preparing','pending'=>null,'tables'=>[],'activated'=>false,'migration'=>$transform ? [ 'policy'=>$transform->policy(),'sha256'=>$transform->sha256(),'changedCells'=>0 ] : null ]; $owner->save();
			$owner->consume( $stage.'/database.records',$plan,$review['database'],$review['receipt']['records'] );
			if ( $ownership !== null ) {
				// Quarantine is transactional. A lost commit acknowledgement never authorizes uncertain cleanup.
				$owner->state['status'] = 'preparing'; $owner->save(); $owner->run( 'SET SESSION autocommit=0' );
				$locks = array_map( static fn( $t )=>Portable_Table_Definition::identifier( $t['name'] ).' WRITE',$owner->state['tables'] );
				$suffixes = array_map( static fn( $t )=>substr( $t['name'],strlen( $prefix ) ),$owner->state['tables'] );
				$locks = array_merge( $locks,Portable_Store_Recovery::lock_aliases( $prefix,$suffixes ) );
				foreach ( [ 'users','usermeta','options' ] as $suffix ) $locks[] = Portable_Table_Definition::identifier( $live_prefix.$suffix ).' READ';
				$owner->run( 'LOCK TABLES '.implode( ',',$locks ) );
				try {
					$owner->exact( $owner->state['tables'] );
					if ( Portable_Recovery_Ownership::destination( $query,$live_prefix,$ownership )['sha256'] !== $destination['sha256'] ) self::fail();
					$quarantine = Portable_Recovery_Ownership::apply( $query,$owner->state['tables'],$prefix,$live_prefix,$ownership,$destination );
					$tables = []; foreach ( $owner->state['tables'] as $table ) $tables[] = $owner->descriptor( $table );
					if ( self::config_hash( $public_root ) !== $config_hash || Portable_Recovery_Ownership::destination( $query,$live_prefix,$ownership )['sha256'] !== $destination['sha256'] ) self::fail();
					$owner->run( 'COMMIT' ); $owner->owned = array_column( $tables,null,'name' ); $owner->state['tables'] = $tables; $owner->state['ownership'] = $quarantine; $owner->save();
				} catch ( \Throwable $error ) { $owner->raw( 'ROLLBACK' ); throw $error; }
				finally { $owner->raw( 'UNLOCK TABLES' ); $owner->raw( 'SET SESSION autocommit=1' ); }
			}
			$again = Portable_Recovery_Stage::inspect( $stage,$public_root,$phrase,$owner->heartbeat() ); if ( $again['receipt'] !== $review['receipt'] || self::config_hash( $public_root ) !== $config_hash ) self::fail();
			$owner->exact( $owner->state['tables'] );
			if ( $ownership !== null && Portable_Recovery_Ownership::destination( $query,$live_prefix,$ownership )['sha256'] !== $destination['sha256'] ) self::fail();
			if ( $transform ) $owner->state['migration']['changedCells'] = $transform->changed(); $owner->state['status'] = 'ready'; $owner->save();
			return [ 'schema'=>self::SCHEMA,'status'=>'inactive-database-staged','directory'=>$owner->directory,'tablePrefix'=>$prefix,'tables'=>count( $plan ),'rows'=>array_sum( array_column( $owner->state['tables'],'rows' ) ),'activated'=>false,'fullSiteReady'=>false,'migrationSha256'=>$transform?->sha256(),'changedCells'=>$transform?->changed() ?? 0,'ownershipVerified'=>$ownership !== null,'ownerId'=>$owner->state['ownership']['ownerId'] ?? null ];
		} catch ( \Throwable $error ) {
			if ( $allocated ) { try { $owner->remove( array_values( $owner->owned ) ); } catch ( \Throwable $ignored ) {} }
			throw $error;
		} finally { if ( $lease ) $owner->release( $lease ); $owner->db->close(); }
	}
	private static function open( #[\SensitiveParameter] array $connection, string $directory, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget ): self {
		self::local(); $binding = self::separate( dirname( $directory ),$public_root ); $state = self::load( $directory,$phrase );
		if ( $state['status'] !== 'ready' || $state['pending'] !== null || $state['publicRootBinding'] !== $binding || $state['configSha256'] !== self::config_hash( $public_root ) || $state['livePrefix'] !== self::live_prefix( $public_root ) || str_starts_with( $state['prefix'],$state['livePrefix'] ) || str_starts_with( $state['livePrefix'],$state['prefix'] ) || ! preg_match( '/^[+-](?:0[0-9]|1[0-3]):[0-5][0-9]$/D',$state['timeZone'] ) ) self::fail( 'This incomplete or differently bound recovery needs owner inspection.' );
		$owner = new self( $connection,$phrase,$budget ); $owner->directory = $directory; $owner->public_root = $public_root; $owner->state = $state;
		try { if ( $state['databaseBinding'] !== $owner->binding ) self::fail(); $owner->run( "SET SESSION time_zone='".$state['timeZone']."'" ); return $owner; } catch ( \Throwable $error ) { $owner->db->close(); throw $error; }
	}
	public static function inspect( #[\SensitiveParameter] array $connection, string $directory, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget = null ): array {
		$owner = self::open( $connection,$directory,$public_root,$phrase,$budget ); $lease = null;
		try {
			$lease = $owner->lease( dirname( $directory ) ); $owner->exact( $owner->state['tables'] );
			$proof = $owner->state['ownership'] ?? null;
			if ( $proof !== null && Portable_Recovery_Ownership::destination( static fn( $sql )=>$owner->run( $sql ),$owner->state['livePrefix'],$proof['policy'] )['sha256'] !== $proof['destinationOwnerSha256'] ) self::fail();
			return [ 'schema'=>self::SCHEMA,'status'=>'verified-inactive-database','tablePrefix'=>$owner->state['prefix'],'tables'=>count( $owner->state['tables'] ),'rows'=>array_sum( array_column( $owner->state['tables'],'rows' ) ),'activated'=>false,'fullSiteReady'=>false,'migrationSha256'=>$owner->state['migration']['sha256'] ?? null,'changedCells'=>$owner->state['migration']['changedCells'] ?? 0,'ownershipVerified'=>$proof !== null,'ownerId'=>$proof['ownerId'] ?? null ];
		}
		finally { if ( $lease ) $owner->release( $lease ); $owner->db->close(); }
	}
	/** The existing native lease/receipt owns activation; the file owner runs only after exact locked proof. */
	private function prefix_snapshot( string $prefix ): array {
		$tables=[];
		foreach ( $this->catalog( $prefix ) as $name ) {
			$sql=$this->definition( $name ); $ast=Portable_Table_Definition::parse( $sql,$name ); $primary=array_values( array_filter( $ast['indexes'],static fn( $i )=>$i['kind']==='primary' ) ); if ( count( $primary )!==1 ) self::fail();
			$table=[ 'name'=>$name,'columns'=>array_column( $ast['columns'],'name' ),'primary'=>array_column( $primary[0]['columns'],'name' ),'schemaHash'=>Portable_Table_Definition::fingerprint( $sql,$name ) ]; $tables[]=$this->descriptor( $table );
		}
		return $tables;
	}
	private function original_exact( array $tables, string $prefix ): void {
		$names=array_column( $tables,'name' ); sort( $names,SORT_STRING ); if ( ! $tables || $this->catalog( $prefix )!==$names ) self::fail(); foreach ( $tables as $table ) if ( ! str_starts_with( $table['name'],$prefix ) || $this->descriptor( $table )!==$table ) self::fail( 'Retained original native data changed after activation review.' );
		$quoted=implode( ',',array_map( fn( $name )=>$this->quoted( $name ),$names ) );
		if ( $this->run( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('.$quoted.')' ) || $this->run( 'SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN ('.$quoted.')' ) ) self::fail( 'Retained original dependencies need a separate review.' );
	}
	public static function activation_guard( #[\SensitiveParameter] array $connection, string $directory, string $public_root, #[\SensitiveParameter] string $phrase, callable $operation, ?callable $budget = null ): mixed {
		$owner = self::open( $connection,$directory,$public_root,$phrase,$budget ); $lease = null; $locked = false;
		try {
			$lease = $owner->lease( dirname( $directory ) ); $proof = $owner->state['ownership'] ?? null;
			if ( $proof === null || $owner->state['migration'] === null ) self::fail( 'Activation requires reviewed migration and verified ownership.' );
			$owner->run( 'SET SESSION autocommit=0' ); $locks = array_map( static fn( $t )=>Portable_Table_Definition::identifier( $t['name'] ).' WRITE',$owner->state['tables'] );
			foreach ( $owner->catalog( $owner->state['livePrefix'] ) as $name ) $locks[] = Portable_Table_Definition::identifier( $name ).' READ';
			$owner->run( 'LOCK TABLES '.implode( ',',$locks ) ); $locked = true; $owner->exact( $owner->state['tables'] );
			if ( Portable_Recovery_Ownership::destination( static fn( $sql )=>$owner->run( $sql ),$owner->state['livePrefix'],$proof['policy'] )['sha256'] !== $proof['destinationOwnerSha256'] || self::config_hash( $public_root ) !== $owner->state['configSha256'] ) self::fail();
			$heartbeat = $owner->heartbeat();
			$original=$owner->prefix_snapshot( $owner->state['livePrefix'] ); $owner->original_exact( $original,$owner->state['livePrefix'] );
			if ( ! empty( $proof['store']['connected'] ) ) {
				$suffixes = array_map( static fn( $t )=>substr( $t['name'],strlen( $owner->state['livePrefix'] ) ),$original );
				Portable_Store_Recovery::assert_empty_destination( static fn( $sql )=>$owner->run( $sql ),$owner->state['livePrefix'],$suffixes );
			}
			$result = $operation( $owner->state,$heartbeat,$original ); $heartbeat( true ); return $result;
		} finally { if ( $locked ) { $owner->raw( 'ROLLBACK' ); $owner->raw( 'UNLOCK TABLES' ); $owner->raw( 'SET SESSION autocommit=1' ); } if ( $lease ) $owner->release( $lease ); $owner->db->close(); }
	}
	/** Verify a retained database against its original root inode relocated by an authenticated file owner. */
	public static function activation_audit( #[\SensitiveParameter] array $connection, string $directory, string $public_root, string $before_root, #[\SensitiveParameter] string $phrase, ?callable $budget = null, ?callable $operation = null, array $original_tables = [] ): mixed {
		self::local(); self::ordinary( $before_root,true ); self::separate( dirname( $directory ),$public_root ); $state = self::load( $directory,$phrase ); $s = self::ordinary( $before_root,true );
		$binding = hash( 'sha256',self::json( [ self::normalize( $public_root ),$s['dev'],$s['ino'] ] ) );
		if ( $state['status'] !== 'ready' || $state['pending'] !== null || $state['publicRootBinding'] !== $binding || $state['configSha256'] !== self::config_hash( $before_root ) || $state['livePrefix'] !== self::live_prefix( $before_root ) || ! isset( $state['ownership'] ) || self::live_prefix( $public_root ) !== $state['prefix'] ) self::fail();
		$owner = new self( $connection,$phrase,$budget ); $owner->state = $state; $owner->directory = $directory; $owner->public_root = $public_root; $lease = null; $locked = false;
		try {
			if ( $owner->binding !== $state['databaseBinding'] ) self::fail(); $owner->run( "SET SESSION time_zone='".$state['timeZone']."'" ); $lease = $owner->lease( dirname( $directory ) );
			if ( ! $original_tables ) self::fail(); $owner->original_exact( $original_tables,$state['livePrefix'] ); $owner->run( 'SET SESSION autocommit=0' ); $locks = array_map( static fn( $t )=>Portable_Table_Definition::identifier( $t['name'] ).' WRITE',$state['tables'] ); foreach ( $original_tables as $t ) $locks[] = Portable_Table_Definition::identifier( $t['name'] ).' READ'; $owner->run( 'LOCK TABLES '.implode( ',',$locks ) ); $locked = true; $owner->exact( $state['tables'] ); $owner->original_exact( $original_tables,$state['livePrefix'] );
			if ( Portable_Recovery_Ownership::destination( static fn( $sql )=>$owner->run( $sql ),$state['livePrefix'],$state['ownership']['policy'] )['sha256'] !== $state['ownership']['destinationOwnerSha256'] ) self::fail();
			return $operation ? $operation( $state ) : $state;
		} finally { if ( $locked ) { $owner->raw( 'ROLLBACK' ); $owner->raw( 'UNLOCK TABLES' ); $owner->raw( 'SET SESSION autocommit=1' ); } if ( $lease ) $owner->release( $lease ); $owner->db->close(); }
	}
	/** Reacquire existing native authority when the logical public root may be between directory renames. */
	public static function activation_checkpoint_guard( #[\SensitiveParameter] array $connection, string $directory, string $public_root, string $original_root, ?string $recovered_root, #[\SensitiveParameter] string $phrase, array $original_tables, callable $operation, ?callable $budget = null ): mixed {
		self::local(); self::ordinary( dirname( $public_root ),true,false ); $s=self::ordinary( $original_root,true,false ); self::ordinary( dirname( $directory ),true );
		foreach ( [ $public_root,$original_root ] as $root ) if ( $root===dirname( $directory ) || str_starts_with( $root,dirname( $directory ).'/' ) || str_starts_with( dirname( $directory ),$root.'/' ) ) self::fail();
		$state=self::load( $directory,$phrase ); $binding=hash( 'sha256',self::json( [ self::normalize( $public_root ),$s['dev'],$s['ino'] ] ) );
		if ( $state['status']!=='ready' || $state['pending']!==null || $state['publicRootBinding']!==$binding || $state['configSha256']!==self::config_hash( $original_root ) || $state['livePrefix']!==self::live_prefix( $original_root ) || ! isset( $state['ownership'] ) || $state['migration']===null ) self::fail();
		if ( $recovered_root!==null && self::live_prefix( $recovered_root )!==$state['prefix'] ) self::fail();
		$owner=new self( $connection,$phrase,$budget ); $owner->state=$state; $owner->directory=$directory; $owner->public_root=$public_root; $lease=null; $locked=false;
		try {
			if ( $owner->binding!==$state['databaseBinding'] ) self::fail(); $owner->run( "SET SESSION time_zone='".$state['timeZone']."'" ); $lease=$owner->lease( dirname( $directory ) ); $owner->original_exact( $original_tables,$state['livePrefix'] );
			$owner->run( 'SET SESSION autocommit=0' ); $locks=array_map( static fn( $table )=>Portable_Table_Definition::identifier( $table['name'] ).' WRITE',$state['tables'] ); foreach ( $original_tables as $table ) $locks[]=Portable_Table_Definition::identifier( $table['name'] ).' READ';
			$owner->run( 'LOCK TABLES '.implode( ',',$locks ) ); $locked=true; $owner->exact( $state['tables'] ); $owner->original_exact( $original_tables,$state['livePrefix'] );
			if ( Portable_Recovery_Ownership::destination( static fn( $sql )=>$owner->run( $sql ),$state['livePrefix'],$state['ownership']['policy'] )['sha256']!==$state['ownership']['destinationOwnerSha256'] ) self::fail();
			$heartbeat=$owner->heartbeat();
			$result=$operation( $state,$heartbeat ); $heartbeat( true ); return $result;
		} finally { if ( $locked ) { $owner->raw( 'ROLLBACK' ); $owner->raw( 'UNLOCK TABLES' ); $owner->raw( 'SET SESSION autocommit=1' ); } if ( $lease ) $owner->release( $lease ); $owner->db->close(); }
	}
	public static function retire( #[\SensitiveParameter] array $connection, string $directory, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget = null ): void {
		$owner = self::open( $connection,$directory,$public_root,$phrase,$budget ); $lease = null;
		try { $lease = $owner->lease( dirname( $directory ) ); $owner->exact( $owner->state['tables'] ); $owner->remove( $owner->state['tables'] ); }
		finally { if ( $lease ) $owner->release( $lease ); $owner->db->close(); }
	}
}
