<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Independent CLI preparation only. Nothing here connects to SQL or activates captured code. */
final class Portable_Recovery_Stage {
	public const SCHEMA = 'kiwe.private-recovery-stage.v1';
	private const MAGIC = 'KIWEPRS2';
	private const MAX_BYTES = 51539607552;
	private const RESERVE = 67108864;
	private const MEMBERS = [ 'source'=>'source.member', 'database'=>'database.member', 'files'=>'files.member' ];
	private string $directory;
	private array $directories = [];
	private array $owned = [];
	private $current = null;
	private $records = null;
	private ?array $current_file = null;
	private $budget;
	private int $written = 0;
	private int $last_space_check = 0;
	private bool $consuming = false;

	private function __construct( string $parent, ?callable $budget ) {
		$this->budget = $budget;
		$this->directory = $parent.'/kiwe-recovery-'.bin2hex( random_bytes( 16 ) );
	}
	private static function fail( string $message ): never { throw new Archive_Error( $message ); }
	private static function normalize( string $path ): string { return rtrim( 'Windows' === PHP_OS_FAMILY ? str_replace( '\\','/',$path ) : $path,'/' ); }
	private static function keys( array $value, array $keys ): bool { return count( $value ) === count( $keys ) && ! array_diff( $keys,array_keys( $value ) ); }
	private static function object( string $bytes, int $limit = 65536 ): array {
		if ( strlen( $bytes ) > $limit ) self::fail( 'Recovery preparation metadata exceeds its limit.' );
		try { $value = json_decode( $bytes,true,24,JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { self::fail( 'Recovery preparation metadata is damaged.' ); }
		if ( ! is_array( $value ) || array_is_list( $value ) ) self::fail( 'Recovery preparation metadata is invalid.' );
		return $value;
	}
	private static function json( array $value ): string { return json_encode( $value,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); }
	/** Every ancestor is ordinary; chmod alone cannot turn a symlink into a private boundary. */
	private static function ordinary( string $path, bool $directory, bool $private = true ): array {
		if ( '' === $path || strlen( $path ) > 8192 || preg_match( '/[\x00-\x1f\x7f]/',$path ) ) self::fail( 'An ordinary local recovery path is required.' );
		$normalized = self::normalize( $path ); $real = realpath( $path );
		if ( ! $real || self::normalize( $real ) !== $normalized || preg_match( '/^(?:[a-zA-Z]:)?\/?$/D',$normalized ) ) self::fail( 'The recovery path is missing, linked or not dedicated.' );
		$cursor = $normalized;
		while ( '' !== $cursor && '.' !== $cursor ) { if ( is_link( $cursor ) ) self::fail( 'Linked recovery paths are not supported.' ); $next = self::normalize( dirname( $cursor ) ); if ( $next === $cursor ) break; $cursor = $next; }
		clearstatcache( true,$path ); $stat = @lstat( $path );
		if ( ! $stat || ( $stat['mode'] & 0170000 ) !== ( $directory ? 0040000 : 0100000 ) || ! is_readable( $path ) || ( ! $directory && $stat['nlink'] !== 1 ) ) self::fail( 'The recovery path is not an ordinary unlinked readable entry.' );
		if ( $private && 'Windows' !== PHP_OS_FAMILY && ( ( $stat['mode'] & 0077 ) || ( function_exists( 'posix_geteuid' ) && $stat['uid'] !== posix_geteuid() ) ) ) self::fail( 'Recovery staging needs a private directory owned by this operating-system account.' );
		return $stat;
	}
	private static function public_binding( string $root, ?string $original_root = null ): string {
		$stat = self::ordinary( $root,true,false );
		return hash( 'sha256',self::json( [ self::normalize( $original_root ?? $root ),$stat['dev'],$stat['ino'] ] ) );
	}
	private static function outside( string $parent, string $root ): void {
		$a = self::normalize( $parent ); $b = self::normalize( $root );
		if ( 'Windows' === PHP_OS_FAMILY ) { $a = strtolower( $a ); $b = strtolower( $b ); }
		if ( $a === $b || str_starts_with( $a,$b.'/' ) || str_starts_with( $b,$a.'/' ) ) self::fail( 'The private staging parent must be separate from the website public root.' );
	}
	private static function local(): void { if ( 'cli' !== PHP_SAPI || PHP_VERSION_ID < 80200 ) self::fail( 'Recovery preparation is an owner CLI task requiring PHP 8.2 or newer.' ); }
	/** A permanent private lock avoids split leases caused by unlinking a lock another owner opened. */
	private static function parent_lock( string $parent ) {
		self::ordinary( $parent,true ); $path = $parent.'/.kiwe-recovery.lock';
		$stream = @fopen( $path,'x+b' );
		if ( $stream ) { if ( ! @chmod( $path,0600 ) ) { fclose( $stream ); self::fail( 'The private recovery lease permissions could not be set.' ); } }
		else { self::ordinary( $path,false ); $stream = @fopen( $path,'r+b' ); }
		if ( ! $stream ) self::fail( 'The private recovery lease is unavailable.' );
		try {
			$stat = self::ordinary( $path,false ); $opened = fstat( $stream );
			if ( $stat['size'] !== 0 || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev'] || ! flock( $stream,LOCK_EX | LOCK_NB ) ) self::fail( 'Another recovery task owns this private staging parent.' );
			return $stream;
		} catch ( \Throwable $error ) { fclose( $stream ); throw $error; }
	}
	private static function input_hash( $input, array $identity, ?callable $budget ): string {
		$hash = hash_init( 'sha256' ); $read = 0;
		while ( ! feof( $input ) ) {
			if ( $budget ) $budget(); $chunk = fread( $input,1048576 );
			if ( false === $chunk || ( '' === $chunk && ! feof( $input ) ) ) self::fail( 'The recovery input could not be inspected.' );
			$read += strlen( $chunk ); if ( $read > $identity['size'] ) self::fail( 'The recovery input grew during review.' ); hash_update( $hash,$chunk );
		}
		$after = fstat( $input );
		foreach ( [ 'size','dev','ino','mtime' ] as $key ) if ( ! $after || $after[$key] !== $identity[$key] ) self::fail( 'The recovery input changed during review.' );
		if ( $read !== $identity['size'] || ! rewind( $input ) ) self::fail( 'The recovery input is incomplete or cannot be rewound.' );
		return hash_final( $hash );
	}
	private function tick(): void {
		if ( $this->budget ) ( $this->budget )();
		if ( hrtime( true )-$this->last_space_check > 1000000000 ) {
			$free = @disk_free_space( $this->directory );
			if ( ! is_float( $free ) || $free < self::RESERVE ) self::fail( 'Private recovery storage has insufficient verified free space.' );
			$this->last_space_check = hrtime( true );
		}
	}
	private function make_directory( string $path ): void {
		if ( $this->directories ) self::ordinary( dirname( $path ),true );
		if ( ! @mkdir( $path,0700 ) ) self::fail( 'A fresh private recovery directory could not be created.' );
		$this->directories[$path] = @lstat( $path );
		if ( ! @chmod( $path,0700 ) ) self::fail( 'Private recovery directory permissions could not be set.' );
		self::ordinary( $path,true );
	}
	private function open( string $relative ) {
		Portable_File_Inventory::relative( $relative,'Windows' === PHP_OS_FAMILY ? 'portable' : 'posix' );
		$path = $this->directory.'/'.$relative; self::ordinary( dirname( $path ),true );
		$stream = @fopen( $path,'x+b' ); if ( ! $stream ) self::fail( 'A fresh private recovery file could not be created.' );
		$this->owned[$path] = [ 'stream'=>$stream,'identity'=>fstat( $stream ) ];
		if ( ! @chmod( $path,0600 ) ) self::fail( 'Private recovery file permissions could not be set.' );
		$stat = self::ordinary( $path,false );
		if ( $stat['ino'] !== $this->owned[$path]['identity']['ino'] || $stat['dev'] !== $this->owned[$path]['identity']['dev'] ) self::fail( 'An opened recovery file differs from its owned path.' );
		return $stream;
	}
	private function write( $stream, string $bytes ): void {
		$this->tick(); $size = strlen( $bytes );
		if ( $this->written+$size > self::MAX_BYTES ) self::fail( 'The private recovery stage exceeds its byte limit.' );
		$offset = 0;
		while ( $offset < $size ) { $n = @fwrite( $stream,substr( $bytes,$offset,65536 ) ); if ( ! is_int( $n ) || $n < 1 ) self::fail( 'Private recovery storage is full or unavailable.' ); $offset += $n; $this->written += $n; }
	}
	private function close( $stream ): void {
		if ( ! is_resource( $stream ) ) return;
		if ( ! fflush( $stream ) || ( function_exists( 'fsync' ) && ! fsync( $stream ) ) ) self::fail( 'Private recovery data could not be synchronized.' );
		fclose( $stream );
	}
	/** Internal parser entrance. Instances cannot be constructed by an arbitrary consumer. */
	public function file_record( string $plain ): void {
		if ( ! $this->consuming ) self::fail( 'Recovery file staging is not active.' );
		$type = $plain[0]; $body = substr( $plain,1 );
		if ( 'R' === $type ) { $row = self::object( $body ); $this->make_directory( $this->directory.'/site/'.$row['path'] ); }
		elseif ( 'F' === $type ) { $this->current_file = self::object( $body ); $this->current = $this->open( 'site/'.$this->current_file['path'] ); }
		elseif ( 'B' === $type ) { $this->write( $this->current,$body ); }
		elseif ( 'E' === $type ) {
			$this->close( $this->current ); $this->current = null;
			$path = $this->directory.'/site/'.$this->current_file['path'];
			self::descriptor( $path,$this->current_file['bytes'],$this->current_file['sha256'] ); $this->current_file = null;
		}
	}
	/** A bounded inert packet stream, preserving binary cells, NULLs and large-row chunks. */
	public function database_record( string $kind, string $plain ): void {
		if ( ! $this->consuming || ! is_resource( $this->records ) ) self::fail( 'Recovery database staging is not active.' );
		$this->write( $this->records,$kind.pack( 'N',strlen( $plain ) ).$plain );
	}
	private static function descriptor( string $path, ?int $bytes = null, ?string $sha256 = null, ?callable $budget = null ): array {
		$before = self::ordinary( $path,false ); $stream = @fopen( $path,'rb' ); if ( ! $stream ) self::fail( 'A private recovery file could not be reviewed.' );
		$hash = hash_init( 'sha256' ); $read = 0;
		try {
			$opened = fstat( $stream ); if ( $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev'] ) self::fail( 'A recovery file changed before review.' );
			while ( ! feof( $stream ) ) { if ( $budget ) $budget(); $chunk = fread( $stream,1048576 ); if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) ) self::fail( 'A recovery file is unreadable.' ); $read += strlen( $chunk ); if ( $read > $before['size'] ) self::fail( 'A recovery file grew during review.' ); hash_update( $hash,$chunk ); }
		} finally { fclose( $stream ); }
		$after = self::ordinary( $path,false ); $digest = hash_final( $hash );
		if ( $before['ino'] !== $after['ino'] || $before['dev'] !== $after['dev'] || $before['mtime'] !== $after['mtime'] || $read !== $before['size'] || $after['size'] !== $read || ( null !== $bytes && $read !== $bytes ) || ( null !== $sha256 && ! hash_equals( $sha256,$digest ) ) ) self::fail( 'A private recovery file changed or is incomplete.' );
		return [ 'bytes'=>$read,'sha256'=>$digest ];
	}
	private static function observation( array $observed, array $review, string $digest ): void {
		$plan = $review['files']['inventory']; $members = array_column( $review['members'],null,'kind' ); $db = $review['database']; $files = $review['files'];
		if ( $observed['recoveryArchiveId'] !== $review['archiveId'] || ! hash_equals( $observed['recoverySha256'],$digest ) || $observed['fileCount'] !== count( $plan['files'] ) || $observed['fileBytes'] !== $plan['bytes'] || ! hash_equals( $observed['fileInventorySha256'],hash( 'sha256',self::json( $plan ) ) ) ) self::fail( 'The source observation belongs to a different recovery set.' );
		foreach ( [ 'database'=>$db,'files'=>$files ] as $kind=>$member ) {
			$o = $observed[$kind];
			if ( $o['archiveId'] !== $member['archiveId'] || $o['schema'] !== $member['schema'] || $o['frames'] !== $member['frames'] || $o['sha256'] !== $members[$kind]['sha256'] || ( 'files' === $kind ? $o['bytes'] : $o['outputBytes'] ) !== $members[$kind]['bytes'] ) self::fail( 'The observed member does not match the reviewed recovery bytes.' );
		}
		if ( $observed['database']['tables'] !== count( $db['tables'] ) || $observed['database']['rows'] !== $db['rows'] || $observed['database']['bytes'] !== $db['bytes'] ) self::fail( 'The source database observation totals do not match.' );
	}
	/** Complete authentication first; catchable failures remove only this invocation's owned paths. */
	public static function prepare( $input, string $observation, #[\SensitiveParameter] string $phrase, string $parent, string $public_root, ?callable $budget = null ): array {
		self::local(); self::ordinary( $parent,true ); self::outside( $parent,$public_root ); $binding = self::public_binding( $public_root );
		$observed = Portable_Source_Observation::open( $observation,$phrase );
		if ( ! is_resource( $input ) || 'stream' !== get_resource_type( $input ) ) self::fail( 'An ordinary local recovery input is required.' );
		$meta = stream_get_meta_data( $input ); $stat = fstat( $input );
		if ( ! in_array( $meta['wrapper_type'] ?? '',[ 'plainfile','PHP' ],true ) || empty( $meta['seekable'] ) || ftell( $input ) !== 0 || ! $stat || $stat['size'] < 1 || $stat['size']*2+self::RESERVE > self::MAX_BYTES ) self::fail( 'The recovery input exceeds the private preparation limit or is not at its beginning.' );
		$free = @disk_free_space( $parent ); if ( ! is_float( $free ) || $free < $stat['size']*2+self::RESERVE ) self::fail( 'Private destination storage cannot hold this recovery stage.' );
		$digest = self::input_hash( $input,$stat,$budget ); if ( ! hash_equals( $observed['recoverySha256'],$digest ) ) self::fail( 'The recovery input does not match its source observation.' );
		$lease = self::parent_lock( $parent );
		try {
		$count = 0; $seen = 0; foreach ( new \FilesystemIterator( $parent,\FilesystemIterator::SKIP_DOTS ) as $entry ) { if ( ++$seen > 1000 ) self::fail( 'Use a dedicated bounded private recovery parent.' ); if ( preg_match( '/^kiwe-recovery-[a-f0-9]{32}$/D',$entry->getFilename() ) && ++$count >= 4 ) self::fail( 'Retire an existing recovery stage before creating another.' ); }
		$stage = new self( $parent,$budget );
		try {
			$stage->make_directory( $stage->directory );
			$guard = static function() use ( $stage ): void { $stage->tick(); };
			$review = Portable_Recovery_Set::verify( $input,$phrase,$guard,static fn( $kind )=>$stage->open( self::MEMBERS[$kind] ) );
			if ( ! rewind( $input ) || ! hash_equals( $digest,self::input_hash( $input,$stat,$guard ) ) ) self::fail( 'The recovery input changed after complete review.' );
			self::observation( $observed,$review,$digest );
			if ( 'Windows' === PHP_OS_FAMILY && 'posix' === $review['files']['inventory']['namePolicy'] ) self::fail( 'POSIX names need a compatible destination filesystem.' );
			// Compression is not a capacity estimate. Use authenticated expanded file/record totals.
			$expanded = $review['files']['inventory']['bytes']+$review['database']['recordBytes']+strlen( $observation )+65536;
			$member_bytes = array_sum( array_column( $review['members'],'bytes' ) ); $free = @disk_free_space( $stage->directory );
			if ( $member_bytes+$expanded+self::RESERVE > self::MAX_BYTES || ! is_float( $free ) || $free < $expanded+self::RESERVE ) self::fail( 'Private destination storage cannot hold the verified expanded recovery.' );
			foreach ( $review['members'] as $member ) {
				$stage->written += $member['bytes'];
				$sync = fopen( $stage->directory.'/'.self::MEMBERS[$member['kind']],'r+b' );
				try { if ( function_exists( 'fsync' ) && ! fsync( $sync ) ) self::fail( 'Private recovery members could not be synchronized.' ); } finally { if ( is_resource( $sync ) ) fclose( $sync ); }
			}
			$stage->make_directory( $stage->directory.'/site' );
			$stage->records = $stage->open( 'database.records' ); $stage->write( $stage->records,'KIWEDRS1' ); $stage->consuming = true;
			foreach ( [ 'database'=>Portable_Database_Capture::class,'files'=>Portable_Archive_Container::class ] as $kind=>$owner ) {
				$stream = fopen( $stage->directory.'/'.self::MEMBERS[$kind],'rb' );
				try { $owner::stage_private( $stream,$phrase,$stage,$guard ); } finally { if ( is_resource( $stream ) ) fclose( $stream ); }
			}
			$stage->consuming = false; $stage->close( $stage->records ); $stage->records = null;
			$report = $stage->open( 'observation.member' ); $stage->write( $report,$observation ); $stage->close( $report );
			$record = [ 'schema'=>self::SCHEMA,'id'=>substr( basename( $stage->directory ),14 ),'publicRootBinding'=>$binding,'archiveId'=>$review['archiveId'],'recoverySha256'=>$digest,'source'=>self::descriptor( $stage->directory.'/source.member',null,null,$guard ),'database'=>self::descriptor( $stage->directory.'/database.member',null,null,$guard ),'files'=>self::descriptor( $stage->directory.'/files.member',null,null,$guard ),'records'=>self::descriptor( $stage->directory.'/database.records',null,null,$guard ),'observation'=>self::descriptor( $stage->directory.'/observation.member',null,null,$guard ),'fileInventorySha256'=>$observed['fileInventorySha256'],'fileCount'=>$observed['fileCount'],'fileBytes'=>$observed['fileBytes'],'tableCount'=>count( $review['database']['tables'] ),'rowCount'=>$review['database']['rows'],'consistency'=>$review['consistency'],'activated'=>false ];
			$cipher = new Portable_Archive_Cipher( Portable_Archive_Cipher::new_header(),$phrase ); $header = self::json( $cipher->header() );
			$receipt = $stage->open( 'complete.receipt' ); $stage->write( $receipt,self::MAGIC.pack( 'N',strlen( $header ) ).$header.$cipher->seal( self::json( $record ),0,'index' ) ); $stage->close( $receipt );
			self::inspect( $stage->directory,$public_root,$phrase,$guard );
			return [ 'schema'=>self::SCHEMA,'status'=>'staged','directory'=>$stage->directory,'archiveId'=>$review['archiveId'],'files'=>$record['fileCount'],'fileBytes'=>$record['fileBytes'],'tables'=>$record['tableCount'],'rows'=>$record['rowCount'],'consistency'=>$record['consistency'],'fullSiteReady'=>false,'activated'=>false ];
		} catch ( \Throwable $error ) {
			$stage->cleanup(); throw $error;
		}
		} finally { flock( $lease,LOCK_UN ); fclose( $lease ); }
	}
	private function cleanup(): void {
		foreach ( $this->owned as $entry ) if ( is_resource( $entry['stream'] ) ) fclose( $entry['stream'] );
		foreach ( array_reverse( $this->owned,true ) as $path=>$entry ) {
			try { $stat = self::ordinary( $path,false ); if ( $stat['dev'] !== $entry['identity']['dev'] || $stat['ino'] !== $entry['identity']['ino'] ) continue; @unlink( $path ); } catch ( \Throwable $error ) { /* Preserve unknown or replaced paths. */ }
		}
		foreach ( array_reverse( $this->directories,true ) as $path=>$identity ) {
			try { $stat = self::ordinary( $path,true ); if ( $identity && $stat['dev'] === $identity['dev'] && $stat['ino'] === $identity['ino'] ) @rmdir( $path ); } catch ( \Throwable $error ) { /* Never recursively delete an unknown path. */ }
		}
	}
	private static function tree( string $root, array $plan, ?callable $budget ): void {
		$expected = array_fill_keys( $plan['directories'],'directory' ); foreach ( $plan['files'] as $file ) $expected[$file['path']] = 'file';
		$stack = [ '' ]; $seen = 0;
		while ( $stack ) {
			$relative = array_pop( $stack ); $directory = $root.( '' === $relative ? '' : '/'.$relative ); self::ordinary( $directory,true );
			foreach ( new \FilesystemIterator( $directory,\FilesystemIterator::SKIP_DOTS ) as $entry ) {
				if ( $budget ) $budget(); $path = ( '' === $relative ? '' : $relative.'/' ).$entry->getFilename();
				if ( ++$seen > count( $expected ) || ! isset( $expected[$path] ) ) self::fail( 'The private recovery stage contains an unknown path.' );
				self::ordinary( $root.'/'.$path,'directory' === $expected[$path] ); if ( 'directory' === $expected[$path] ) $stack[] = $path;
			}
		}
		if ( $seen !== count( $expected ) ) self::fail( 'The private recovery tree is incomplete.' );
		foreach ( $plan['files'] as $file ) self::descriptor( $root.'/'.$file['path'],$file['bytes'],$file['sha256'],$budget );
	}
	/** Recheck authenticated completion, exact private entries and all staged bytes. */
	public static function inspect( string $directory, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget = null, ?string $original_root = null ): array {
		self::local(); self::ordinary( $directory,true ); self::outside( dirname( $directory ),$public_root );
		if ( ! preg_match( '/^kiwe-recovery-[a-f0-9]{32}$/D',basename( $directory ) ) ) self::fail( 'This is not an owned recovery stage.' );
		$allowed = array_merge( array_values( self::MEMBERS ),[ 'database.records','observation.member','complete.receipt','site' ] ); $seen = [];
		foreach ( new \FilesystemIterator( $directory,\FilesystemIterator::SKIP_DOTS ) as $entry ) { $name = $entry->getFilename(); if ( ! in_array( $name,$allowed,true ) || isset( $seen[$name] ) ) self::fail( 'The recovery stage contains an unknown entry.' ); self::ordinary( $directory.'/'.$name,'site' === $name ); $seen[$name] = true; }
		if ( count( $seen ) !== count( $allowed ) ) self::fail( 'The recovery stage is incomplete.' );
		if ( self::ordinary( $directory.'/complete.receipt',false )['size'] > 65536 ) self::fail( 'The recovery completion receipt exceeds its limit.' );
		$bytes = file_get_contents( $directory.'/complete.receipt' );
		if ( substr( $bytes,0,8 ) !== self::MAGIC || strlen( $bytes ) < 14 ) self::fail( 'The private recovery completion receipt is invalid.' );
		$length = unpack( 'Nsize',substr( $bytes,8,4 ) )['size']; if ( $length < 2 || $length > 2048 ) self::fail( 'The private recovery completion header is invalid.' );
		$cipher = new Portable_Archive_Cipher( self::object( substr( $bytes,12,$length ) ),$phrase ); $record = self::object( $cipher->open( substr( $bytes,12+$length ),0,'index' ) );
		if ( ! self::keys( $record,[ 'schema','id','publicRootBinding','archiveId','recoverySha256','source','database','files','records','observation','fileInventorySha256','fileCount','fileBytes','tableCount','rowCount','consistency','activated' ] ) || $record['schema'] !== self::SCHEMA || $record['id'] !== substr( basename( $directory ),14 ) || $record['publicRootBinding'] !== self::public_binding( $public_root,$original_root ) || $record['activated'] !== false || $record['consistency'] !== 'members-authenticated-only' ) self::fail( 'The recovery receipt does not belong to this prepared destination.' );
		foreach ( [ 'source'=>'source.member','database'=>'database.member','files'=>'files.member','records'=>'database.records','observation'=>'observation.member' ] as $key=>$file ) {
			if ( ! is_array( $record[$key] ) || ! self::keys( $record[$key],[ 'bytes','sha256' ] ) || ! is_int( $record[$key]['bytes'] ) || $record[$key]['bytes'] < 1 || $record[$key]['bytes'] > self::MAX_BYTES || ! is_string( $record[$key]['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$record[$key]['sha256'] ) ) self::fail( 'A private recovery descriptor is invalid.' );
			self::descriptor( $directory.'/'.$file,$record[$key]['bytes'],$record[$key]['sha256'],$budget );
		}
		if ( $record['source']['bytes'] > 8388608 || $record['observation']['bytes'] > 1048576 ) self::fail( 'Recovery preparation metadata exceeds its bound.' );
		$source = self::object( file_get_contents( $directory.'/source.member' ),8388608 );
		$file_stream = fopen( $directory.'/files.member','rb' ); $db_stream = fopen( $directory.'/database.member','rb' );
		try { $files = Portable_Archive_Container::verify( $file_stream,$phrase,$budget ); $database = Portable_Database_Capture::verify( $db_stream,$phrase,$budget ); } finally { if ( is_resource( $file_stream ) ) fclose( $file_stream ); if ( is_resource( $db_stream ) ) fclose( $db_stream ); }
		Portable_Recovery_Set::source( $source,$files['inventory'],$budget );
		$observed = Portable_Source_Observation::open( file_get_contents( $directory.'/observation.member' ),$phrase );
		self::observation( $observed,[ 'archiveId'=>$record['archiveId'],'members'=>[ [ 'kind'=>'database' ]+$record['database'],[ 'kind'=>'files' ]+$record['files'] ],'database'=>$database,'files'=>$files ],$record['recoverySha256'] );
		if ( $record['fileInventorySha256'] !== $observed['fileInventorySha256'] || $record['fileCount'] !== $observed['fileCount'] || $record['fileBytes'] !== $observed['fileBytes'] || $record['tableCount'] !== count( $database['tables'] ) || $record['rowCount'] !== $database['rows'] ) self::fail( 'The prepared recovery totals are inconsistent.' );
		self::tree( $directory.'/site',$files['inventory'],$budget );
		return [ 'receipt'=>$record,'inventory'=>$files['inventory'],'database'=>$database,'source'=>$source ];
	}
	/** Coordinate a trusted file owner with stage preparation/retirement through the existing lease. */
	public static function with_lease( string $directory, callable $operation ): mixed {
		self::local(); self::ordinary( $directory,true ); $lease = self::parent_lock( dirname( $directory ) );
		try { return $operation(); } finally { flock( $lease,LOCK_UN ); fclose( $lease ); }
	}
	/** Owner-directed retirement refuses changed or foreign content before deleting anything. */
	public static function retire( string $directory, string $public_root, #[\SensitiveParameter] string $phrase, ?callable $budget = null ): void {
		self::local(); self::ordinary( $directory,true ); self::outside( dirname( $directory ),$public_root );
		$lease = self::parent_lock( dirname( $directory ) );
		try {
		$review = self::inspect( $directory,$public_root,$phrase,$budget ); $plan = $review['inventory'];
		foreach ( array_reverse( $plan['files'] ) as $file ) { $path = $directory.'/site/'.$file['path']; self::descriptor( $path,$file['bytes'],$file['sha256'],$budget ); if ( ! @unlink( $path ) ) self::fail( 'An owned staged file could not be retired.' ); }
		foreach ( array_reverse( $plan['directories'] ) as $path ) { self::ordinary( $directory.'/site/'.$path,true ); if ( ! @rmdir( $directory.'/site/'.$path ) ) self::fail( 'A staged directory changed during retirement.' ); }
		if ( ! @rmdir( $directory.'/site' ) ) self::fail( 'The staged file root changed during retirement.' );
		foreach ( [ 'source.member','database.member','files.member','database.records','observation.member','complete.receipt' ] as $file ) { self::ordinary( $directory.'/'.$file,false ); if ( ! @unlink( $directory.'/'.$file ) ) self::fail( 'An owned recovery member could not be retired.' ); }
		if ( ! @rmdir( $directory ) ) self::fail( 'The recovery stage contains changed or unknown content.' );
		} finally { flock( $lease,LOCK_UN ); fclose( $lease ); }
	}
}
