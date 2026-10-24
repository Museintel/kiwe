<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Read-only file planning; native source/actor authority and consistency belong to capture. */
final class Portable_File_Inventory {
	public const MAX_FILES = 150000;
	public const MAX_BYTES = 17179869184;
	private const MAX_METADATA = 33554432;
	private const EXCLUDED = [ '.git'=>'Development history', '.maintenance'=>'Temporary maintenance lease', 'wp-content/cache'=>'Rebuildable cache', 'wp-content/upgrade'=>'Temporary updates', 'wp-content/updraft'=>'Existing backup archives', 'wp-content/ai1wm-backups'=>'Existing backup archives', 'wp-content/debug.log'=>'Rebuildable PHP diagnostics', 'wp-content/uploads.zip'=>'Existing upload backup archive', 'wp-content/uploads/backwpup'=>'Existing backup archives and job logs', 'wp-content/uploads/backwpup-restore'=>'Temporary backup restore files', 'wp-content/uploads/wp-file-manager-pro/fm_backup'=>'Existing file manager backups', 'wp-content/uploads/rank-math'=>'Rebuildable Rank Math sitemap cache', 'wp-content/uploads/wc-logs'=>'Operational WooCommerce diagnostic logs; not part of consistent site recovery', 'wp-content/compressx/log'=>'Rebuildable CompressX diagnostics' ];
	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function normalize( string $path ): string { return rtrim( 'Windows' === PHP_OS_FAMILY ? str_replace( '\\','/',$path ) : $path, '/' ); }
	/** POSIX backslashes are literal filename bytes, never directory separators. */
	public static function name_policy( ?string $policy = null ): string {
		$policy ??= 'Windows' === PHP_OS_FAMILY ? 'portable' : 'posix';
		if ( ! in_array( $policy,[ 'portable','posix' ],true ) ) self::fail( 'The source filename policy is unsupported.' );
		return $policy;
	}
	public static function collision_key( string $path, string $policy ): string {
		self::relative( $path,$policy );
		if ( 'posix' === $policy ) return $path;
		if ( ! function_exists( 'mb_strtolower' ) && preg_match( '/[^\x00-\x7f]/',$path ) ) self::fail( 'Unicode filename compatibility requires multibyte support.' );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $path,'UTF-8' ) : strtolower( $path );
	}
	private static function root( string $path ): string {
		$real = realpath( $path );
		if ( ! $real || ! is_dir( $real ) || is_link( $path ) || preg_match( '/^(?:[a-zA-Z]:)?\/?$/D', self::normalize( $real ) ) ) self::fail( 'An ordinary, dedicated website directory is required.' );
		return self::normalize( $real );
	}
	public static function relative( string $path, string $policy = 'portable' ): void {
		self::name_policy( $policy );
		if ( '' === $path || strlen( $path ) > 2048 || ! preg_match( '//u',$path ) || preg_match( '/[\x00-\x1f\x7f]/u',$path ) ) self::fail( 'A website filename is not supported by portable recovery.' );
		if ( 'portable' === $policy && ( str_contains( $path,'\\' ) || preg_match( '/[:<>"|?*]/u',$path ) ) ) self::fail( 'A website filename is incompatible with the destination filename policy.' );
		foreach ( explode( '/',$path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) self::fail( 'A website path is unsafe for portable recovery.' );
			if ( 'portable' === $policy && ( preg_match( '/[. ]$/D',$part ) || preg_match( '/^(?:con|prn|aux|nul|com[1-9\x{00b9}\x{00b2}\x{00b3}]|lpt[1-9\x{00b9}\x{00b2}\x{00b3}])(?:\.|$)/iuD',$part ) ) ) self::fail( 'A website path is incompatible with the destination filename policy.' );
		}
	}
	private static function resolve( string $root, string $relative, string $policy ): string {
		self::relative( $relative,$policy ); $path = $root; $segments = explode( '/',$relative );
		foreach ( $segments as $segment ) { $path .= '/'.$segment; if ( is_link( $path ) ) self::fail( 'A linked website dependency needs a separate recovery adapter.' ); }
		$real = realpath( $path );
		if ( ! $real || ! str_starts_with( self::normalize( $real ),$root.'/' ) || self::normalize( $real ) !== $path ) self::fail( 'A website path moved or escaped its source directory.' );
		return $path;
	}
	private static function file( string $root, string $relative, ?callable $budget = null, int $remaining_bytes = 2147483648, string $policy = 'portable' ): array {
		$path = self::resolve( $root,$relative,$policy ); clearstatcache( true,$path ); $before = lstat( $path );
		if ( ! $before || ( $before['mode'] & 0170000 ) !== 0100000 || ! is_readable( $path ) || $before['size'] > min(2147483648,$remaining_bytes) ) self::fail( 'A source file is unreadable, unsafe or exceeds the supported size.' );
		$stream = @fopen( $path,'rb' ); if ( ! $stream ) self::fail( 'A source file could not be opened.' );
		$hash = hash_init( 'sha256' ); $read = 0;
		try {
			$opened = fstat( $stream );
			if ( ! $opened || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev'] ) self::fail( 'The opened source file differs from its reviewed path.' );
			while ( ! feof( $stream ) ) {
				if ( $budget ) $budget(); $chunk = fread( $stream,1048576 );
				if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) ) self::fail( 'A source file could not be read.' );
				$read += strlen( $chunk ); if ( $read > $before['size'] ) self::fail( 'A source file grew during inventory.' ); hash_update( $hash,$chunk );
			}
		} finally { fclose( $stream ); }
		$hash = hash_final( $hash ); clearstatcache( true,$path ); $after = lstat( $path );
		if ( $read !== $before['size'] ) self::fail( 'A source file was truncated during inventory.' );
		if ( ! is_string( $hash ) || ! $after || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] || $before['ino'] !== $after['ino'] || self::resolve( $root,$relative,$policy ) !== $path ) self::fail( 'A source file changed while its inventory was being read.' );
		return [ 'path'=>$relative, 'bytes'=>$after['size'], 'sha256'=>$hash ];
	}
	public static function scan( string $path, int $max_files = self::MAX_FILES, int $max_bytes = self::MAX_BYTES, ?callable $budget = null, ?string $policy = null, bool $include_excluded = false ): array {
		$policy = self::name_policy( $policy );
		if ( 'Windows' === PHP_OS_FAMILY && 'posix' === $policy ) self::fail( 'POSIX source names cannot be resolved on this filesystem.' );
		if ( $max_files < 1 || $max_files > self::MAX_FILES || $max_bytes < 1 || $max_bytes > self::MAX_BYTES ) self::fail( 'Invalid portable file inventory limits.' );
		$root = self::root( $path ); $stack = [ '' ]; $files = []; $directories = []; $excluded = []; $seen = []; $bytes = 0; $visited = 0; $path_bytes = 0;
		while ( $stack ) {
			$parent = array_pop( $stack ); if ( $budget ) $budget();
			if ( ++$visited > 20000 || substr_count( $parent,'/' ) > 64 ) self::fail( 'The website directory inventory exceeds its supported depth or count.' );
			$dir = '' === $parent ? $root : self::resolve( $root,$parent,$policy );
			try { $iterator = new \FilesystemIterator( $dir, \FilesystemIterator::SKIP_DOTS ); } catch ( \UnexpectedValueException $e ) { self::fail( 'A source directory could not be read.' ); }
			foreach ( $iterator as $entry ) {
				if ( $budget ) $budget(); $relative = ( '' === $parent ? '' : $parent.'/' ).$entry->getFilename(); self::relative( $relative,$policy );
				if ( ! $include_excluded && isset( self::EXCLUDED[$relative] ) ) { $excluded[] = [ 'path'=>$relative,'reason'=>self::EXCLUDED[$relative] ]; continue; }
				$folded = self::collision_key( $relative,$policy );
				if ( isset( $seen[$folded] ) ) self::fail( 'Two source paths collide on a case-insensitive destination.' ); $path_bytes += strlen( $relative ); if ( $path_bytes > self::MAX_METADATA ) self::fail( 'The source path metadata exceeds its supported size.' ); $seen[$folded] = true;
				$resolved = self::resolve( $root,$relative,$policy );
				if ( count( $seen ) > $max_files + 20000 ) self::fail( 'The source path inventory exceeds its limit.' );
				if ( is_dir( $resolved ) ) { if ( count( $directories ) >= 20000 ) self::fail( 'The source has too many directories.' ); $directories[] = $relative; $stack[] = $relative; continue; }
				if ( count( $files ) >= $max_files ) self::fail( 'The website has more files than this capture supports.' );
				$file = self::file( $root,$relative,$budget,$max_bytes-$bytes,$policy ); $bytes += $file['bytes']; if ( $bytes > $max_bytes ) self::fail( 'The website files exceed the supported capture size.' ); $files[] = $file;
			}
		}
		usort( $files,static fn($a,$b)=>strcmp($a['path'],$b['path']) ); sort( $directories,SORT_STRING ); usort( $excluded,static fn($a,$b)=>strcmp($a['path'],$b['path']) );
		return [ 'files'=>$files,'directories'=>$directories,'exclusions'=>$excluded,'bytes'=>$bytes,'namePolicy'=>$policy ];
	}
	public static function unchanged( string $path, array $entry, ?callable $budget = null, ?string $policy = null ): string {
		$policy = self::name_policy( $policy );
		if ( 'Windows' === PHP_OS_FAMILY && 'posix' === $policy ) self::fail( 'POSIX source names cannot be resolved on this filesystem.' );
		if ( array_keys( $entry ) !== [ 'path','bytes','sha256' ] || ! is_string( $entry['path'] ) || ! is_int( $entry['bytes'] ) || $entry['bytes'] < 0 || ! is_string( $entry['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$entry['sha256'] ) ) self::fail( 'The reviewed source file entry is invalid.' );
		$root = self::root( $path ); $now = self::file( $root,$entry['path'],$budget,$entry['bytes'],$policy );
		if ( $now !== $entry ) self::fail( 'A source file changed after capture planning. Start a fresh review.' );
		return self::resolve( $root,$entry['path'],$policy );
	}
	/** Validate authenticated metadata without touching a source or destination filesystem. */
	public static function validate_plan( array $plan, ?callable $budget = null ): string {
		if ( count( $plan ) !== 5 || array_diff( [ 'files','directories','exclusions','bytes','namePolicy' ],array_keys( $plan ) ) || ! is_string( $plan['namePolicy'] ) || ! is_int( $plan['bytes'] ) || $plan['bytes'] < 0 || $plan['bytes'] > self::MAX_BYTES ) self::fail( 'The reviewed source tree is invalid.' );
		$policy = self::name_policy( $plan['namePolicy'] );
		foreach ( [ 'files','directories','exclusions' ] as $kind ) if ( ! is_array( $plan[$kind] ) || ! array_is_list( $plan[$kind] ) ) self::fail( 'The reviewed source tree is invalid.' );
		if ( count( $plan['files'] ) > self::MAX_FILES || count( $plan['directories'] ) > 20000 || count( $plan['exclusions'] ) > 100 ) self::fail( 'The reviewed source tree exceeds its supported limits.' );
		$seen = []; $dirs = []; $excluded = []; $total = 0; $metadata = 0;
		foreach ( [ 'directories','exclusions','files' ] as $kind ) {
			$previous = null;
			foreach ( $plan[$kind] as $row ) {
				if ( $budget ) $budget();
				if ( 'directories' === $kind ) { if ( ! is_string( $row ) ) self::fail( 'A reviewed directory is invalid.' ); $path = $row; }
				else {
					$keys = 'files' === $kind ? [ 'path','bytes','sha256' ] : [ 'path','reason' ];
					if ( ! is_array( $row ) || count( $row ) !== count( $keys ) || array_diff( $keys,array_keys( $row ) ) || ! is_string( $row['path'] ) ) self::fail( 'A reviewed file or exclusion is invalid.' ); $path = $row['path'];
					if ( 'files' === $kind ) { if ( ! is_int( $row['bytes'] ) || $row['bytes'] < 0 || $row['bytes'] > 2147483648 || ! is_string( $row['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$row['sha256'] ) ) self::fail( 'A reviewed file is invalid.' ); $total += $row['bytes']; }
					elseif ( ! is_string( $row['reason'] ) || '' === $row['reason'] || strlen( $row['reason'] ) > 512 || ! preg_match( '//u',$row['reason'] ) || preg_match( '/[\x00-\x1f\x7f]/',$row['reason'] ) ) self::fail( 'A reviewed exclusion is invalid.' );
				}
				self::relative( $path,$policy );
				if ( null !== $previous && strcmp( $previous,$path ) >= 0 ) self::fail( 'Reviewed source paths must be ordered and unique.' ); $previous = $path;
				$key = self::collision_key( $path,$policy ); if ( isset( $seen[$key] ) ) self::fail( 'Reviewed source paths collide.' ); $seen[$key] = true;
				$metadata += strlen( json_encode( [ 'entry'=>$row ],JSON_UNESCAPED_SLASHES ) ); if ( $metadata > self::MAX_METADATA ) self::fail( 'The reviewed source path metadata exceeds its limit.' );
				$slash = strrpos( $path,'/' ); if ( false !== $slash && ! isset( $dirs[substr( $path,0,$slash )] ) ) self::fail( 'A reviewed path is missing its parent directory.' );
				if ( 'directories' === $kind ) $dirs[$path] = true;
				elseif ( 'exclusions' === $kind ) $excluded[$path] = true;
			}
		}
		if ( $total !== $plan['bytes'] ) self::fail( 'The reviewed source byte total is inconsistent.' );
		// Walk ancestors instead of comparing every pair of paths in a large inventory.
		foreach ( array_merge( $plan['directories'],array_column( $plan['files'],'path' ),array_keys( $excluded ) ) as $path ) {
			while ( false !== ( $slash = strrpos( $path,'/' ) ) ) { $path = substr( $path,0,$slash ); if ( isset( $excluded[$path] ) ) self::fail( 'An excluded source path contains inventoried dependencies.' ); }
		}
		return $policy;
	}
	/** Necessary name preflight only; a restore owner must also probe its actual volume and stage writes. */
	public static function assert_destination_paths( array $verified_plan, string $destination_policy, ?callable $budget = null ): void {
		self::validate_plan( $verified_plan,$budget ); self::name_policy( $destination_policy ); $seen = [];
		foreach ( [ 'directories','exclusions','files' ] as $kind ) foreach ( $verified_plan[$kind] as $row ) {
			if ( $budget ) $budget(); $path = 'directories' === $kind ? $row : $row['path']; $key = self::collision_key( $path,$destination_policy );
			if ( isset( $seen[$key] ) ) self::fail( 'Source paths collide under the destination filename policy.' ); $seen[$key] = true;
		}
	}
	/** Recheck membership as well as bytes. This detects drift; a capture owner must still provide quiescence. */
	public static function unchanged_tree( string $path, array $plan, ?callable $budget = null ): void {
		$policy = self::validate_plan( $plan,$budget );
		// An exhaustive reviewed tree declares no exclusions. Reapply that exact
		// coverage instead of silently dropping its included diagnostics.
		$now = self::scan( $path,self::MAX_FILES,self::MAX_BYTES,$budget,$policy,$plan['exclusions'] === [] );
		foreach ( [ 'files','directories','exclusions','bytes','namePolicy' ] as $kind ) {
			if ( $now[$kind] !== $plan[$kind] ) self::fail( 'The website file set changed during capture. Start a fresh review.' );
		}
	}
}
