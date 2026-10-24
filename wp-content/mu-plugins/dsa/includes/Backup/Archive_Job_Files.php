<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Bounded private file operations shared by native archive jobs; never a public path API. */
final class Archive_Job_Files {
	public static function quota( int $actor ): void {
		$root = Configuration_Snapshot_Service::private_directory( true ); $site = 0; $own = 0;
		foreach ( glob( $root . DIRECTORY_SEPARATOR . '*-job-*' ) ?: [] as $dir ) {
			if ( ! preg_match( '/^(?:content|media|design|portable|compiler)-job-[a-f0-9]{32}$/D', basename( $dir ) ) || is_link( $dir ) || ! is_dir( $dir ) ) continue;
			$site++;
			try { $info = json_decode( self::read( $dir, 'info.json', 8388608 ), true, 32 ); if ( is_array( $info ) && ( $info['actor'] ?? null ) === $actor ) $own++; } catch ( \Throwable $error ) { continue; }
		}
		if ( $own >= 8 || $site >= 40 ) throw new Archive_Error( 'Remove a completed or unused job before starting another. Up to eight jobs per account are retained.' );
	}
	public static function directory( string $kind, string $id, bool $create = false ): string {
		if ( ! in_array( $kind, [ 'content','media','design','portable','compiler' ], true ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) throw new Archive_Error( 'Invalid backup job.' );
		$root = Configuration_Snapshot_Service::private_directory( $create );
		if ( ! $root ) throw new Archive_Error( 'Private backup storage is unavailable.' );
		$path = $root . DIRECTORY_SEPARATOR . $kind . '-job-' . $id;
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_dir( $path ) ) ) throw new Archive_Error( 'Unsafe private backup storage.' );
		if ( $create && ! is_dir( $path ) && ! @mkdir( $path, 0700 ) ) throw new Archive_Error( 'The backup job could not be saved.' );
		if ( ! is_dir( $path ) ) throw new Archive_Error( 'This backup job expired or was removed.' );
		$real = realpath( $path );
		if ( ! $real || dirname( $real ) !== $root || is_link( $path ) ) throw new Archive_Error( 'The private backup folder moved.' );
		return $real;
	}
	public static function path( string $dir, string $name ): string {
		if ( ! preg_match( '/^(?:info\.json|lock|worker\.lock|source\.seam\.zip|source\.kiwe-(?:set|media|design)|archive\.kiwe-(?:set|media|design)|settings\.kiwe|templates\.kiwe-set|assets\.kiwe-media|manifest\.kiwe|(?:database|files|recovery|observation|review-source|review-database|review-files|handoff|worker)\.kiwe|state-[a-f0-9]{32}\.kiwe|part-[0-9]{4}\.kiwe|chunk-[0-9]{5}\.kiwe|blob-[0-9]{4}\.bin|tmp-[a-f0-9]{32})$/D', $name ) ) throw new Archive_Error( 'Invalid private backup file.' );
		$path = $dir . DIRECTORY_SEPARATOR . $name;
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) throw new Archive_Error( 'Unsafe private backup file.' );
		return $path;
	}
	public static function write( string $dir, string $name, string $bytes ): void {
		$path = self::path( $dir, $name ); $tmp = self::path( $dir, 'tmp-' . bin2hex( random_bytes( 16 ) ) );
		$stream = @fopen( $tmp, 'x+b' ); if ( ! $stream ) throw new Archive_Error( 'Private backup storage could not be written.' );
		$committed = false;
		try {
			if ( ! @chmod( $tmp, 0600 ) ) throw new Archive_Error( 'The backup checkpoint could not be made private.' );
			for ( $offset = 0; $offset < strlen( $bytes ); $offset += $written ) {
				$written = fwrite( $stream, substr( $bytes, $offset, 65536 ) );
				if ( ! $written ) throw new Archive_Error( 'Private backup storage is full or unavailable.' );
			}
			if ( ! fflush( $stream ) || ( function_exists( 'fsync' ) && ! fsync( $stream ) ) ) throw new Archive_Error( 'The backup checkpoint could not be synchronized.' );
			fclose( $stream ); $stream = null;
			if ( ! @rename( $tmp, $path ) ) throw new Archive_Error( 'The backup checkpoint could not be committed.' );
			$committed = true;
		} finally { if ( is_resource( $stream ) ) fclose( $stream ); if ( ! $committed && is_file( $tmp ) && ! is_link( $tmp ) ) @unlink( $tmp ); }
	}
	public static function read( string $dir, string $name, int $limit ): string {
		$path = self::path( $dir, $name );
		if ( ! is_file( $path ) || filesize( $path ) > $limit ) throw new Archive_Error( 'A backup checkpoint is missing or too large.' );
		$bytes = file_get_contents( $path ); if ( ! is_string( $bytes ) ) throw new Archive_Error( 'The private backup could not be read.' );
		return $bytes;
	}
	public static function retire( string $dir ): void {
		$names = array_map( 'basename', glob( $dir . DIRECTORY_SEPARATOR . '*' ) ?: [] );
		usort( $names, static fn( $a,$b )=>(int) in_array( $a, [ 'info.json','lock' ], true ) <=> (int) in_array( $b, [ 'info.json','lock' ], true ) );
		foreach ( $names as $name ) { $path = self::path( $dir, $name ); if ( is_file( $path ) && ! @unlink( $path ) ) throw new Archive_Error( 'A private backup file could not be removed.' ); }
		if ( ! @rmdir( $dir ) ) throw new Archive_Error( 'The private backup folder could not be removed.' );
	}
}
