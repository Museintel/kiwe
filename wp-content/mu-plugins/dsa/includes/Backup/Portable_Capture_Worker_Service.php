<?php
namespace DSA\Backup;

use DSA\Security\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private capture queue driven only by the site's existing native CLI cron. */
final class Portable_Capture_Worker_Service {
	private const SCHEMA = 'kiwe.portable-worker.v1';
	public const HANDOFF_SECONDS = 900;
	public static function native(): bool { return 'cli' === PHP_SAPI && defined( 'DOING_CRON' ) && true === DOING_CRON && ! is_multisite(); }
	public function register(): void {
		// An HTTP wp-cron request must never claim work, decrypt a handoff or
		// consume a scheduled event intended for the native worker.
		if ( self::native() ) add_action( 'shutdown',[ self::class,'tick' ],PHP_INT_MAX );
	}
	private static function binding(): string {
		global $wpdb; $root = realpath( ABSPATH );
		if ( ! $root || is_link( rtrim( ABSPATH,'/\\' ) ) || ! isset( $wpdb->prefix ) || ! is_string( $wpdb->prefix ) || ! preg_match( '/^[a-zA-Z0-9_]{1,48}$/D',$wpdb->prefix ) ) throw new Archive_Error( 'The native capture source is unavailable.' );
		return hash( 'sha256',$root.'|'.$wpdb->prefix.'|'.get_current_blog_id().'|'.( defined( 'DB_NAME' ) ? DB_NAME : '' ) );
	}
	public static function readiness(): array {
		$result = [ 'ready'=>false,'mode'=>'native-cli-cron','handoffSeconds'=>self::HANDOFF_SECONDS ];
		try {
			$root = Configuration_Snapshot_Service::private_directory(); if ( ! $root ) return $result;
			$opened = Secret_Store::decrypt_with_status( Archive_Job_Files::read( $root,'worker.kiwe',4096 ) );
			if ( 'ok' !== $opened['status'] ) return $result;
			$state = json_decode( $opened['value'],true,8,JSON_THROW_ON_ERROR );
			if ( ! is_array( $state ) || count( $state ) !== 3 || array_diff( [ 'schema','binding','observedAt' ],array_keys( $state ) ) || $state['schema'] !== self::SCHEMA || ! is_string( $state['binding'] ) || ! hash_equals( self::binding(),$state['binding'] ) || ! is_int( $state['observedAt'] ) || $state['observedAt'] > time() || $state['observedAt'] < time()-self::HANDOFF_SECONDS ) return $result;
			Portable_Archive_Cipher::new_header(); $result['ready'] = true;
		} catch ( \Throwable $error ) { /* Readiness is a denial, never secret diagnostics. */ }
		return $result;
	}
	/** No public request, network call, deliberate wait or wp-cron option queue. */
	public static function tick(): array {
		$result = [ 'claimed'=>0,'failed'=>0,'busy'=>false ];
		if ( ! self::native() ) return $result;
		$lock = null;
		try {
			$root = Configuration_Snapshot_Service::private_directory( true ); $path = Archive_Job_Files::path( $root,'worker.lock' );
			$lock = @fopen( $path,'c+b' ); if ( ! $lock || ! @chmod( $path,0600 ) ) throw new Archive_Error( 'The private worker lock is unavailable.' );
			if ( ! flock( $lock,LOCK_EX | LOCK_NB ) ) { $result['busy'] = true; return $result; }
			$opened = fstat( $lock ); clearstatcache( true,$path ); $current = @lstat( $path );
			if ( ! $opened || ! $current || is_link( $path ) || ( $opened['mode'] & 0170000 ) !== 0100000 || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev'] ) throw new Archive_Error( 'The private worker lock changed.' );
			$seal = Secret_Store::encrypt( json_encode( [ 'schema'=>self::SCHEMA,'binding'=>self::binding(),'observedAt'=>time() ],JSON_THROW_ON_ERROR ) );
			if ( '' === $seal ) throw new Archive_Error( 'The native secret owner is unavailable.' );
			Archive_Job_Files::write( $root,'worker.kiwe',$seal );
			$jobs = glob( $root.DIRECTORY_SEPARATOR.'portable-job-*' ) ?: [];
			if ( count( $jobs ) > 4 ) throw new Archive_Error( 'The private capture inventory needs review.' );
			foreach ( $jobs as $dir ) {
				if ( is_link( $dir ) || ! is_dir( $dir ) || ! preg_match( '/^portable-job-([a-f0-9]{32})$/D',basename( $dir ),$match ) ) continue;
				try {
					$info = json_decode( Archive_Job_Files::read( $dir,'info.json',16384 ),true,8,JSON_THROW_ON_ERROR );
					if ( ! is_array( $info ) || 'queued' !== ( $info['status'] ?? null ) ) continue;
					$result['claimed'] = 1;
					( new Portable_Capture_Job_Service() )->run_queued( $match[1] );
				} catch ( \Throwable $error ) { $result['failed'] = 1; }
				// One attempt per cron invocation keeps work bounded and avoids
				// four consecutive eight-minute captures on shared hosting.
				break;
			}
		} catch ( \Throwable $error ) { $result['failed'] = 1; }
		finally { if ( is_resource( $lock ) ) { flock( $lock,LOCK_UN ); fclose( $lock ); } }
		return $result;
	}
}
