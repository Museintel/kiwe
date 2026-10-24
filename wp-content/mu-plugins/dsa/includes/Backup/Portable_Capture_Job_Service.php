<?php
namespace DSA\Backup;

use DSA\Access\Backup_Workspace_Service;
use DSA\Security\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private native capture attempts, one-use CLI handoff and review transport; no restore acceptance. */
final class Portable_Capture_Job_Service {
	public const SCHEMA = 'kiwe.portable-capture-job.v1';
	public const MAX_RESERVED_BYTES = 68719476736;
	private const SITE_RESERVED_BYTES = 137438953472;
	private const MIN_FREE_BYTES = 67108864;
	private const MAX_SECONDS = 3600;
	private const LIFETIME = 604800;
	private const DOWNLOAD_CHUNK_BYTES = 65536;
	private const FILES = [ 'database.kiwe','files.kiwe','recovery.kiwe','observation.kiwe','review-source.kiwe','review-database.kiwe','review-files.kiwe','handoff.kiwe' ];
	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function actor( bool $remove = false ): int {
		$actor = get_current_user_id();
		if ( $actor < 1 || ( $remove ? ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) : ! Backup_Workspace_Service::allowed() ) ) self::fail( 'This capture is unavailable for your account.' );
		return $actor;
	}
	private static function binding(): string {
		global $wpdb; $root = realpath( ABSPATH );
		if ( ! $root || is_link( rtrim( ABSPATH,'/\\' ) ) || ! isset( $wpdb->prefix ) || ! is_string( $wpdb->prefix ) || ! preg_match( '/^[a-zA-Z0-9_]{1,48}$/D',$wpdb->prefix ) || PHP_INT_SIZE < 8 ) self::fail( 'The native capture source is unavailable.' );
		return hash( 'sha256',$root.'|'.$wpdb->prefix.'|'.get_current_blog_id().'|'.( defined( 'DB_NAME' ) ? DB_NAME : '' ) );
	}
	private static function info( string $dir ): array {
		try { $info = json_decode( Archive_Job_Files::read( $dir,'info.json',16384 ),true,8,JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { self::fail( 'The private capture journal is invalid.' ); }
		$keys = [ 'schema','id','actor','binding','status','attempt','reservedBytes','bytes','archiveSha256','observationSha256','createdAt','expiresAt','error','fullSiteReady' ];
		if ( ! is_array( $info ) || count( $info ) !== count( $keys ) || array_diff( $keys,array_keys( $info ) ) || $info['schema'] !== self::SCHEMA || ! is_string( $info['id'] ) || ! preg_match( '/^[a-f0-9]{32}$/D',$info['id'] ) || basename( $dir ) !== 'portable-job-'.$info['id'] || ! is_int( $info['actor'] ) || $info['actor'] < 1 || ! is_string( $info['binding'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$info['binding'] ) || ! in_array( $info['status'],[ 'pending','queued','running','captured','failed','interrupted','removing' ],true ) || $info['fullSiteReady'] !== false ) self::fail( 'The private capture journal is invalid.' );
		foreach ( [ 'attempt','reservedBytes','bytes','createdAt','expiresAt' ] as $key ) if ( ! is_int( $info[$key] ) || $info[$key] < 0 ) self::fail( 'The private capture journal is invalid.' );
		if ( $info['attempt'] > 100 || $info['reservedBytes'] > self::MAX_RESERVED_BYTES || $info['bytes'] > $info['reservedBytes'] || $info['expiresAt'] !== $info['createdAt']+self::LIFETIME || ! in_array( $info['error'],[ '','capture-failed','cleanup-required','interrupted','handoff-refused' ],true ) ) self::fail( 'The private capture journal is invalid.' );
		foreach ( [ 'archiveSha256','observationSha256' ] as $key ) if ( ! is_string( $info[$key] ) || ( '' !== $info[$key] && ! preg_match( '/^[a-f0-9]{64}$/D',$info[$key] ) ) ) self::fail( 'The private capture journal is invalid.' );
		if ( ( 'captured' === $info['status'] ) !== ( $info['bytes'] > 0 && '' !== $info['archiveSha256'] && '' !== $info['observationSha256'] ) ) self::fail( 'The private capture completion record is invalid.' );
		return $info;
	}
	private static function persist( string $dir, array $info ): void {
		Archive_Job_Files::write( $dir,'info.json',json_encode( $info,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
	}
	private static function authority( array $info, bool $remove = false ): void {
		if ( self::actor( $remove ) !== $info['actor'] ) self::fail( 'This capture is unavailable for your account.' );
		if ( ! $remove && ( 'removing' === $info['status'] || $info['expiresAt'] <= time() || ! hash_equals( $info['binding'],self::binding() ) ) ) self::fail( 'This capture expired, is being removed or its native source changed.' );
	}
	private static function projection( array $info ): array {
		return array_intersect_key( $info,array_flip( [ 'id','status','attempt','bytes','createdAt','expiresAt','error','fullSiteReady' ] ) ) + [ 'kind'=>'portable','restartRequired'=>'interrupted' === $info['status'],'writerFreezeVerified'=>false ];
	}
	private static function lock( string $path, bool $required = true ) {
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) self::fail( 'The private capture lock is unsafe.' );
		$stream = @fopen( $path,'c+b' ); if ( ! $stream ) self::fail( 'Private capture storage is unavailable.' ); @chmod( $path,0600 );
		$acquired = flock( $stream,LOCK_EX | LOCK_NB,$would_block );
		$opened = fstat( $stream ); clearstatcache( true,$path ); $current = @lstat( $path );
		if ( ! $opened || ! $current || is_link( $path ) || ( $opened['mode'] & 0170000 ) !== 0100000 || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino'] ) { flock( $stream,LOCK_UN ); fclose( $stream ); self::fail( 'The private capture lock changed.' ); }
		if ( ! $acquired ) { fclose( $stream ); if ( ! $required && $would_block ) return null; self::fail( 'Another capture operation is running. Try again after it finishes.' ); }
		return $stream;
	}
	private static function quota( int $actor, string $own_id = '', int $reservation = 0 ): void {
		$root = Configuration_Snapshot_Service::private_directory( true ); $site = 0; $own = 0; $reserved = 0; $outstanding = 0;
		foreach ( glob( $root.DIRECTORY_SEPARATOR.'portable-job-*' ) ?: [] as $dir ) {
			if ( ! preg_match( '/^portable-job-[a-f0-9]{32}$/D',basename( $dir ) ) || is_link( $dir ) || ! is_dir( $dir ) ) self::fail( 'Private capture storage contains an unreviewed entry.' );
			$info = self::info( $dir ); if ( $info['id'] === $own_id ) continue; ++$site; if ( $info['actor'] === $actor ) ++$own; $reserved += $info['reservedBytes']; $occupied = 0;
			foreach ( self::FILES as $name ) { $path = Archive_Job_Files::path( $dir,$name ); clearstatcache( true,$path ); if ( is_file( $path ) ) { $size = filesize( $path ); if ( ! is_int( $size ) ) self::fail( 'Reserved capture storage could not be inspected.' ); $occupied += $size; } }
			$outstanding += max( 0,$info['reservedBytes']-$occupied );
		}
		if ( $own >= 2 || $site >= 4 || $reserved+$reservation > self::SITE_RESERVED_BYTES ) self::fail( 'Remove an unused capture first. Two captures per account and four per website are retained.' );
		self::capacity( $root,$outstanding+$reservation );
	}
	private static function control( callable $action ): mixed {
		$root = Configuration_Snapshot_Service::private_directory( true ); $stream = self::lock( $root.DIRECTORY_SEPARATOR.'portable-control.lock' );
		try { return $action(); } finally { flock( $stream,LOCK_UN ); fclose( $stream ); }
	}
	private static function locked( string $id, callable $action, bool $remove = false ): mixed {
		self::actor( $remove ); $dir = Archive_Job_Files::directory( 'portable',$id ); $stream = self::lock( Archive_Job_Files::path( $dir,'lock' ) );
		try { $info = self::info( $dir ); self::authority( $info,$remove ); return $action( $dir,$info ); } finally { flock( $stream,LOCK_UN ); fclose( $stream ); }
	}
	private static function cleanup( string $dir, array $keep = [] ): void {
		$names = array_values( array_diff( scandir( $dir ) ?: [],[ '.','..','info.json','lock' ] ) );
		foreach ( $names as $name ) if ( ! in_array( $name,self::FILES,true ) && ! preg_match( '/^tmp-[a-f0-9]{32}$/D',$name ) ) self::fail( 'An unfinished capture contains an unreviewed file. It is preserved.' );
		foreach ( $names as $name ) { if ( in_array( $name,$keep,true ) ) continue; $path = Archive_Job_Files::path( $dir,$name ); if ( is_file( $path ) && ! @unlink( $path ) ) self::fail( 'An unfinished private capture could not be retired.' ); }
	}
	private static function open( string $dir, string $name ) {
		$stream = @fopen( Archive_Job_Files::path( $dir,$name ),'x+b' ); if ( ! $stream ) self::fail( 'A fresh private capture file could not be created.' );
		if ( ! @chmod( Archive_Job_Files::path( $dir,$name ),0600 ) ) { fclose( $stream ); self::fail( 'The capture file could not be made private.' ); } return $stream;
	}
	private static function sync( $stream ): void {
		if ( ! fflush( $stream ) || ( function_exists( 'fsync' ) && ! fsync( $stream ) ) ) self::fail( 'The private capture could not be synchronized.' );
	}
	private static function capacity( string $dir, int $reservation ): void {
		$free = @disk_free_space( $dir ); if ( ! is_float( $free ) && ! is_int( $free ) ) self::fail( 'Available private capture storage could not be inspected.' );
		if ( $free < $reservation+self::MIN_FREE_BYTES ) self::fail( 'Private storage cannot safely hold this capture and its verification copies.' );
	}
	public function create(): array {
		$actor = self::actor(); $binding = self::binding();
		return self::control( static function() use ( $actor,$binding ): array {
			Archive_Job_Files::quota( $actor ); self::quota( $actor ); $id = bin2hex( random_bytes( 16 ) ); $dir = Archive_Job_Files::directory( 'portable',$id,true ); $now = time();
			$info = [ 'schema'=>self::SCHEMA,'id'=>$id,'actor'=>$actor,'binding'=>$binding,'status'=>'pending','attempt'=>0,'reservedBytes'=>0,'bytes'=>0,'archiveSha256'=>'','observationSha256'=>'','createdAt'=>$now,'expiresAt'=>$now+self::LIFETIME,'error'=>'','fullSiteReady'=>false ];
			try { self::persist( $dir,$info ); } catch ( \Throwable $error ) { Archive_Job_Files::retire( $dir ); throw $error; } return self::projection( $info );
		} );
	}
	private static function refresh_authority( array $info ): void {
		if ( get_current_user_id() !== $info['actor'] ) self::fail( 'The capture account context changed.' );
		// After the SQL read view closes, use fresh native user/role data before
		// publication. No credential, role, consent or session record is changed.
		clean_user_cache( $info['actor'] ); wp_set_current_user( 0 ); wp_set_current_user( $info['actor'] ); self::authority( $info );
	}
	public function enqueue( string $id, #[\SensitiveParameter] string $phrase ): array {
		Portable_Archive_Cipher::validate_passphrase( $phrase );
		if ( ! Portable_Capture_Worker_Service::readiness()['ready'] ) self::fail( 'The website needs a recently observed native CLI cron worker before queuing a capture.' );
		return self::locked( $id,static function( string $dir,array $info ) use ( $phrase ): array {
			if ( ! in_array( $info['status'],[ 'pending','failed','interrupted' ],true ) || $info['attempt'] >= 100 || 'cleanup-required' === $info['error'] ) self::fail( 'This attempt cannot be queued. Review or remove it first.' );
			$now = time();
			$seal = Secret_Store::encrypt( json_encode( [ 'schema'=>'kiwe.portable-handoff.v1','id'=>$info['id'],'actor'=>$info['actor'],'binding'=>$info['binding'],'attempt'=>$info['attempt']+1,'createdAt'=>$now,'expiresAt'=>$now+Portable_Capture_Worker_Service::HANDOFF_SECONDS,'passphrase'=>$phrase ],JSON_THROW_ON_ERROR ) );
			if ( '' === $seal ) self::fail( 'The native secret owner could not protect the worker handoff.' );
			Archive_Job_Files::write( $dir,'handoff.kiwe',$seal );
			$info['status'] = 'queued'; $info['error'] = '';
			try { self::persist( $dir,$info ); }
			catch ( \Throwable $error ) { @unlink( Archive_Job_Files::path( $dir,'handoff.kiwe' ) ); throw $error; }
			return self::projection( $info );
		} );
	}
	/** Trusted CLI adapter restores an existing principal, then checks native access. */
	public function run_queued( string $id, ?callable $cancel = null ): bool {
		if ( ! Portable_Capture_Worker_Service::native() ) self::fail( 'Only the native CLI cron worker can claim a queued capture.' );
		global $wpdb; $previous_actor = get_current_user_id(); $dir = Archive_Job_Files::directory( 'portable',$id ); $lock = self::lock( Archive_Job_Files::path( $dir,'lock' ) );
		try {
			$info = self::info( $dir ); if ( 'queued' !== $info['status'] ) return false;
			try {
				$seal = Archive_Job_Files::read( $dir,'handoff.kiwe',16384 );
				// Claim once before decrypting or changing user context. A process
				// lost here leaves a refused missing handoff, never an auto-resume.
				if ( ! @unlink( Archive_Job_Files::path( $dir,'handoff.kiwe' ) ) ) self::fail( 'The private worker handoff could not be consumed.' );
				$opened = Secret_Store::decrypt_with_status( $seal ); if ( 'ok' !== $opened['status'] ) self::fail( 'The private worker handoff cannot be opened.' );
				$handoff = json_decode( $opened['value'],true,8,JSON_THROW_ON_ERROR );
				$keys = [ 'schema','id','actor','binding','attempt','createdAt','expiresAt','passphrase' ];
				if ( ! is_array( $handoff ) || count( $handoff ) !== count( $keys ) || array_diff( $keys,array_keys( $handoff ) ) || $handoff['schema'] !== 'kiwe.portable-handoff.v1' || $handoff['id'] !== $id || $handoff['actor'] !== $info['actor'] || $handoff['binding'] !== $info['binding'] || $handoff['attempt'] !== $info['attempt']+1 || ! is_int( $handoff['createdAt'] ) || ! is_int( $handoff['expiresAt'] ) || $handoff['createdAt'] > time() || $handoff['expiresAt'] !== $handoff['createdAt']+Portable_Capture_Worker_Service::HANDOFF_SECONDS || $handoff['expiresAt'] <= time() || ! is_string( $handoff['passphrase'] ) ) self::fail( 'The private worker handoff expired or changed.' );
				Portable_Archive_Cipher::validate_passphrase( $handoff['passphrase'] );
				clean_user_cache( $info['actor'] ); wp_set_current_user( 0 ); wp_set_current_user( $info['actor'] ); self::authority( $info );
				$info['status'] = 'pending';
				self::capture_locked( $dir,$info,$handoff['passphrase'],$wpdb,$cancel );
				return true;
			} catch ( \Throwable $error ) {
				$latest = self::info( $dir );
				if ( 'queued' === $latest['status'] ) {
					$latest['status'] = 'failed'; $latest['error'] = 'handoff-refused';
					try { self::cleanup( $dir ); $latest['reservedBytes'] = 0; } catch ( \Throwable $cleanup_error ) { $latest['error'] = 'cleanup-required'; }
					self::persist( $dir,$latest );
				}
				throw new Archive_Error( 'The queued capture did not complete. Review its private status before a fresh attempt.' );
			} finally { unset( $opened,$handoff,$seal ); }
		} finally { wp_set_current_user( 0 ); wp_set_current_user( $previous_actor ); flock( $lock,LOCK_UN ); fclose( $lock ); }
	}
	/** Direct native capture remains available without persisting a handoff. */
	public function run( string $id, #[\SensitiveParameter] string $phrase, \wpdb $wpdb, ?callable $cancel = null ): array {
		if ( 'cli' !== PHP_SAPI ) self::fail( 'Portable capture requires a native background worker.' );
		if ( ( $GLOBALS['wpdb'] ?? null ) !== $wpdb ) self::fail( 'Capture must use the current native WordPress database owner.' );
		Portable_Archive_Cipher::validate_passphrase( $phrase ); Portable_Archive_Cipher::new_header();
		return self::locked( $id,static fn( string $dir,array $info ): array=>self::capture_locked( $dir,$info,$phrase,$wpdb,$cancel ) );
	}
	private static function capture_locked( string $dir,array $info, #[\SensitiveParameter] string $phrase, \wpdb $wpdb, ?callable $cancel ): array {
		if ( ! in_array( $info['status'],[ 'pending','failed','interrupted','running' ],true ) || $info['attempt'] >= 100 ) self::fail( 'Create a new capture instead of replacing a completed one.' );
		self::cleanup( $dir ); $info['status'] = 'running'; ++$info['attempt']; $info['bytes'] = 0; $info['archiveSha256'] = ''; $info['observationSha256'] = ''; $info['error'] = ''; self::persist( $dir,$info );
		$streams = []; $start = hrtime( true ); $last_space = 0; $last_connection = 0; $connection_guard = null; $reservation = self::MIN_FREE_BYTES;
		$budget = static function() use ( $dir,$wpdb,$cancel,$start,&$last_space,&$last_connection,&$connection_guard,&$reservation ): void {
			if ( $cancel ) $cancel(); $now = hrtime( true ); if ( ( $now-$start )/1000000000 > self::MAX_SECONDS ) self::fail( 'This capture attempt reached its bounded worker time. Start a fresh attempt.' );
			if ( $now-$last_connection >= 2000000000 ) {
				$connection_guard ??= Portable_Database_Capture::connection_guard( $wpdb ); $connection_guard(); $last_connection = $now;
			}
			if ( $now-$last_space < 250000000 ) return; $last_space = $now; self::capacity( $dir,0 ); $bytes = 0;
			foreach ( self::FILES as $name ) { $path = Archive_Job_Files::path( $dir,$name ); clearstatcache( true,$path ); if ( is_file( $path ) ) { $size = filesize( $path ); if ( ! is_int( $size ) ) self::fail( 'Capture storage size could not be inspected.' ); $bytes += $size; } }
			if ( $bytes > $reservation ) self::fail( 'The capture exceeded its private storage reservation. Start a new reviewed attempt.' );
		};
		try {
			try {
				// Resolve lazy native dependencies before taking the loaded-file inventory.
				foreach ( [ Portable_Source_Observation::class,Portable_Database_Capture::class,Portable_Archive_Container::class,Portable_Recovery_Set::class,Archive_Cipher::class ] as $class ) if ( ! class_exists( $class ) ) self::fail( 'A native capture dependency is unavailable.' );
				$budget(); $root = realpath( ABSPATH ); $plan = Portable_File_Inventory::scan( $root,Portable_File_Inventory::MAX_FILES,Portable_File_Inventory::MAX_BYTES,$budget );
				$source = Portable_Source_Compatibility::inspect( $root,$plan,$budget );
				$previous_errors = $wpdb->suppress_errors( true );
				try {
					$wpdb->last_error = ''; $estimate = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND BINARY LEFT(TABLE_NAME,%d) = BINARY %s',strlen( $wpdb->prefix ),$wpdb->prefix ) );
					if ( '' !== (string)$wpdb->last_error || ! is_string( $estimate ) || ! preg_match( '/^(0|[1-9][0-9]{0,10})$/D',$estimate ) || (float)$estimate > Portable_Database_Capture::MAX_BYTES ) self::fail( 'Native database storage requirements are unsupported or unavailable.' );
				} finally { $wpdb->suppress_errors( $previous_errors ); }
				// Data lengths are estimates, never a promise; hard checks remain active while writing.
				$reservation = 3*( $plan['bytes'] + 2*(int)$estimate + 33554432 ) + self::MIN_FREE_BYTES;
				if ( $reservation > self::MAX_RESERVED_BYTES ) self::fail( 'This capture exceeds its private storage limit.' ); self::capacity( $dir,$reservation );
				self::control( static function() use ( $dir,&$info,$reservation ): void { self::quota( $info['actor'],$info['id'],$reservation ); $info['reservedBytes'] = $reservation; self::persist( $dir,$info ); } );
				foreach ( [ 'database.kiwe','files.kiwe','recovery.kiwe' ] as $name ) $streams[$name] = self::open( $dir,$name );
				$observation = Portable_Source_Observation::capture( $root,$plan,$wpdb,$streams['database.kiwe'],$streams['files.kiwe'],$phrase,$budget );
				if ( Portable_Source_Compatibility::inspect( $root,$plan,$budget ) !== $source ) self::fail( 'The native dependency layout changed during capture.' );
				self::refresh_authority( $info ); foreach ( [ 'database.kiwe','files.kiwe' ] as $name ) { self::sync( $streams[$name] ); rewind( $streams[$name] ); }
				$receipt = Portable_Recovery_Set::create( $streams['database.kiwe'],$streams['files.kiwe'],$source,$streams['recovery.kiwe'],$phrase,$budget ); self::sync( $streams['recovery.kiwe'] ); rewind( $streams['recovery.kiwe'] );
				$review = Portable_Recovery_Set::verify( $streams['recovery.kiwe'],$phrase,$budget,static fn( string $kind )=>self::open( $dir,'review-'.$kind.'.kiwe' ) );
				if ( $review['files']['inventory'] !== $plan || $receipt['archiveId'] !== $review['archiveId'] || $receipt['memberCount'] !== 3 ) self::fail( 'Independent capture verification did not match its reviewed source.' );
				$observation['recoveryArchiveId'] = $receipt['archiveId']; $observation['recoverySha256'] = $receipt['sha256'];
				$sealed = Portable_Source_Observation::seal( $observation,$phrase ); Archive_Job_Files::write( $dir,'observation.kiwe',$sealed );
				self::refresh_authority( $info ); $info['bytes'] = $receipt['outputBytes']; $info['archiveSha256'] = $receipt['sha256']; $info['observationSha256'] = hash( 'sha256',$sealed );
			} finally { foreach ( $streams as $stream ) if ( is_resource( $stream ) ) fclose( $stream ); }
			self::cleanup( $dir,[ 'recovery.kiwe','observation.kiwe' ] ); $info['reservedBytes'] = $info['bytes']+strlen( $sealed ); $info['status'] = 'captured'; self::persist( $dir,$info ); return self::projection( $info );
		} catch ( \Throwable $error ) {
			$info['status'] = 'failed'; $info['bytes'] = 0; $info['archiveSha256'] = ''; $info['observationSha256'] = ''; $info['error'] = 'capture-failed';
			try { self::cleanup( $dir ); $info['reservedBytes'] = 0; } catch ( \Throwable $cleanup_error ) { $info['error'] = 'cleanup-required'; }
			self::persist( $dir,$info );
			// A trusted operator may inspect the refused library boundary. Never chain
			// the original message, SQL, callback exception or absolute source path.
			$diagnostic = null; $file = $error->getFile(); $line = $error->getLine();
			if ( dirname( $file ) === __DIR__ ) {
				// A library's fail() helper is shared; report its immediate call site.
				$frame = $error->getTrace()[0] ?? []; if ( isset( $frame['file'],$frame['line'] ) && dirname( $frame['file'] ) === __DIR__ ) { $file = $frame['file']; $line = $frame['line']; }
				$name = basename( $file ); if ( preg_match( '/^[A-Za-z0-9_-]{1,128}\\.php$/D',$name ) ) $diagnostic = new Archive_Error( 'Capture boundary '.$name.':'.$line );
			}
			throw new Archive_Error( 'The capture attempt did not complete. Its private journal is retained for a fresh attempt or removal.',0,$diagnostic );
		}
	}
	public function status( string $id ): array {
		self::actor(); $dir = Archive_Job_Files::directory( 'portable',$id ); $stream = self::lock( Archive_Job_Files::path( $dir,'lock' ),false );
		try {
			$info = self::info( $dir ); self::authority( $info );
			// Atomic journal replacement permits bounded polling while an operation
			// owns the lock. A busy poll must never classify a live worker as lost.
			if ( ! $stream ) return self::projection( $info ) + [ 'busy'=>true ];
			// Acquiring the exclusive OS lock proves no worker still owns this journal.
			if ( 'running' === $info['status'] ) { $info['status'] = 'interrupted'; $info['bytes'] = 0; $info['archiveSha256'] = ''; $info['observationSha256'] = ''; $info['error'] = 'interrupted'; self::persist( $dir,$info ); }
			return self::projection( $info ) + [ 'busy'=>false ];
		} finally { if ( is_resource( $stream ) ) { flock( $stream,LOCK_UN ); fclose( $stream ); } }
	}
	/** Bounded account-owned listing, including cleanup after expiry or access loss. */
	public function inventory(): array {
		$actor = self::actor( true ); $root = Configuration_Snapshot_Service::private_directory();
		if ( ! $root ) return [];
		$allowed = Backup_Workspace_Service::allowed(); $binding = $allowed ? self::binding() : ''; $jobs = []; $scanned = 0; $site = 0;
		foreach ( new \DirectoryIterator( $root ) as $entry ) {
			if ( $entry->isDot() ) continue;
			if ( ++$scanned > 1024 ) self::fail( 'Private capture storage exceeds its inspection limit.' );
			$name = $entry->getFilename(); if ( ! str_starts_with( $name,'portable-job-' ) ) continue;
			if ( ++$site > 4 || ! preg_match( '/^portable-job-([a-f0-9]{32})$/D',$name,$match ) ) self::fail( 'Private capture storage contains an unreviewed entry.' );
			$dir = Archive_Job_Files::directory( 'portable',$match[1] ); $info = self::info( $dir );
			if ( $info['actor'] !== $actor ) continue;
			$lock = self::lock( Archive_Job_Files::path( $dir,'lock' ),false );
			try {
				$info = self::info( $dir ); self::authority( $info,true );
				$expired = $info['expiresAt'] <= time(); $changed = $allowed && ! hash_equals( $info['binding'],$binding );
				if ( $lock && $allowed && ! $expired && ! $changed && 'running' === $info['status'] ) {
					$info['status'] = 'interrupted'; $info['bytes'] = 0; $info['archiveSha256'] = ''; $info['observationSha256'] = ''; $info['error'] = 'interrupted'; self::persist( $dir,$info );
				}
				$available = $allowed && ! $expired && ! $changed && (bool)$lock;
				$jobs[] = self::projection( $info ) + [ 'busy'=>! $lock,'expired'=>$expired,'sourceChanged'=>$changed,'canReview'=>$available && 'captured' === $info['status'],'canQueue'=>$available && in_array( $info['status'],[ 'pending','failed','interrupted' ],true ) && $info['attempt'] < 100 && 'cleanup-required' !== $info['error'] ];
			} finally { if ( is_resource( $lock ) ) { flock( $lock,LOCK_UN ); fclose( $lock ); } }
		}
		usort( $jobs,static fn( array $a,array $b ): int => ( $b['createdAt'] <=> $a['createdAt'] ) ?: strcmp( $a['id'],$b['id'] ) );
		return $jobs;
	}
	private static function download_identity( $stream, string $path, int $bytes ): array {
		$opened = fstat( $stream ); clearstatcache( true,$path ); $current = @lstat( $path );
		if ( ! $opened || ! $current || is_link( $path ) || ( $opened['mode'] & 0170000 ) !== 0100000 || ( $current['mode'] & 0170000 ) !== 0100000 || $opened['nlink'] !== 1 || $opened['size'] !== $bytes || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino'] || $current['size'] !== $bytes || ( PHP_OS_FAMILY !== 'Windows' && ( $current['mode'] & 0077 ) !== 0 ) ) self::fail( 'The private capture file is missing, unsafe or changed.' );
		return $opened;
	}
	/**
	 * Trusted transport adapter for independently verified capture review only.
	 * No path, secret, extraction, Range support or full-site readiness is exposed.
	 * begin receives public metadata; emit consumes bounded encrypted byte strings.
	 */
	public function stream_review_capture( string $id, string $member, callable $begin, callable $emit, ?callable $cancel = null ): array {
		if ( ! in_array( $member,[ 'recovery','observation' ],true ) ) self::fail( 'This capture component is unavailable.' );
		return self::locked( $id,static function( string $dir,array $info ) use ( $member,$begin,$emit,$cancel ): array {
			if ( 'captured' !== $info['status'] ) self::fail( 'Only an independently verified capture can be reviewed offline.' );
			self::refresh_authority( $info );
			$bytes = 'recovery' === $member ? $info['bytes'] : $info['reservedBytes']-$info['bytes'];
			$sha = $info[ 'recovery' === $member ? 'archiveSha256' : 'observationSha256' ];
			if ( $bytes < 1 || ( 'observation' === $member && $bytes > 1048576 ) ) self::fail( 'The capture component size is invalid.' );
			$path = Archive_Job_Files::path( $dir,$member.'.kiwe' ); $stream = @fopen( $path,'rb' );
			if ( ! $stream ) self::fail( 'The private capture file is unavailable.' );
			try {
				$identity = self::download_identity( $stream,$path,$bytes );
				$metadata = [ 'id'=>$info['id'],'component'=>$member,'bytes'=>$bytes,'sha256'=>$sha,'filename'=>'kiwe-capture-'.gmdate( 'Ymd',$info['createdAt'] ).'-'.substr( $info['id'],0,8 ).'-'.$member.'.kiwe','scope'=>'capture-review','fullSiteReady'=>false ];
				if ( $cancel ) $cancel(); if ( false === $begin( $metadata ) ) self::fail( 'The capture download was cancelled.' );
				$hash = hash_init( 'sha256' ); $remaining = $bytes; $tail = ''; $sent = 0;
				while ( $remaining > 0 ) {
					if ( $cancel ) $cancel(); self::authority( $info );
					$chunk = fread( $stream,min( self::DOWNLOAD_CHUNK_BYTES,$remaining ) );
					if ( ! is_string( $chunk ) || '' === $chunk ) self::fail( 'The capture download ended before its verified length.' );
					$remaining -= strlen( $chunk ); hash_update( $hash,$chunk ); $buffer = $tail.$chunk;
					// One read/hash pass. Withhold completion bytes until the entire
					// pinned file, journal and fresh native authority agree.
					$length = max( 0,strlen( $buffer )-self::DOWNLOAD_CHUNK_BYTES );
					$tail = substr( $buffer,$length );
					if ( $length ) { if ( false === $emit( substr( $buffer,0,$length ) ) ) self::fail( 'The capture download was interrupted.' ); $sent += $length; }
				}
				if ( fread( $stream,1 ) !== '' || ! feof( $stream ) || ! hash_equals( $sha,hash_final( $hash ) ) ) self::fail( 'The capture download failed its complete-file checksum.' );
				$after = self::download_identity( $stream,$path,$bytes );
				if ( $after['mtime'] !== $identity['mtime'] || $after['ctime'] !== $identity['ctime'] || self::info( $dir ) !== $info ) self::fail( 'The capture changed during its download.' );
				if ( $cancel ) $cancel(); self::refresh_authority( $info );
				if ( false === $emit( $tail ) ) self::fail( 'The capture download was interrupted.' ); $sent += strlen( $tail );
				return $metadata + [ 'complete'=>$sent === $bytes ];
			} finally { fclose( $stream ); }
		} );
	}
	/** This retained transport is not exposed as a completed full-site download. */
	public function download_path( string $id ): string {
		return self::locked( $id,static function(): string { self::fail( 'Full-site download remains unavailable until complete source and destination recovery acceptance.' ); } );
	}
	public function remove( string $id ): array {
		return self::control( static function() use ( $id ): array {
			$dir = self::locked( $id,static function( string $dir,array $info ): string {
				self::cleanup( $dir ); $info['status'] = 'removing'; $info['bytes'] = 0; $info['archiveSha256'] = ''; $info['observationSha256'] = ''; $info['reservedBytes'] = 0; self::persist( $dir,$info ); return $dir;
			},true );
			// Windows retains an unlinked open lock until fclose. Release the job handle
			// before removing its folder; the durable terminal state refuses new work.
			Archive_Job_Files::retire( $dir ); return [ 'removed'=>true ];
		} );
	}
}
