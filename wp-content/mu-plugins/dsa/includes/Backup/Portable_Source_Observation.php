<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** File drift detection bracketing one native read view; this is not a writer freeze. */
final class Portable_Source_Observation {
	public const SCHEMA = 'kiwe.portable-source-observation.v1';
	private const MAGIC = 'KIWEPSO1';
	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function validate( array $value ): void {
		$keys = [ 'schema','strategy','phases','fileInventorySha256','fileCount','fileBytes','database','files','observedStable','writerFreezeVerified','databaseFileConsistencyVerified','recoveryArchiveId','recoverySha256' ];
		if ( count( $value ) !== count( $keys ) || array_diff( $keys,array_keys( $value ) ) || $value['schema'] !== self::SCHEMA || $value['strategy'] !== 'native-read-view-with-file-drift-checks' || $value['phases'] !== [ 'opened','closing' ] || $value['observedStable'] !== true || $value['writerFreezeVerified'] !== false || $value['databaseFileConsistencyVerified'] !== false ) self::fail( 'The source observation is invalid or claims unverified consistency.' );
		foreach ( [ 'fileInventorySha256','recoverySha256' ] as $key ) if ( ! is_string( $value[$key] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$value[$key] ) ) self::fail( 'The source observation binding is invalid.' );
		if ( ! is_string( $value['recoveryArchiveId'] ) || ! preg_match( '/^[a-f0-9]{32}$/D',$value['recoveryArchiveId'] ) || ! is_int( $value['fileCount'] ) || $value['fileCount'] < 0 || $value['fileCount'] > Portable_File_Inventory::MAX_FILES || ! is_int( $value['fileBytes'] ) || $value['fileBytes'] < 0 || $value['fileBytes'] > Portable_File_Inventory::MAX_BYTES ) self::fail( 'The source observation totals or recovery binding are invalid.' );
		foreach ( [ 'database','files' ] as $kind ) {
			$receipt = $value[$kind]; $keys = 'database' === $kind ? [ 'schema','archiveId','tables','rows','bytes','frames','outputBytes','sha256','transactionEnded' ] : [ 'schema','archiveId','frames','bytes','sha256' ];
			if ( ! is_array( $receipt ) || count( $receipt ) !== count( $keys ) || array_diff( $keys,array_keys( $receipt ) ) || ! in_array( $receipt['schema'],'database' === $kind ? [ 'kiwe.portable-database.v1',Portable_Database_Capture::SCHEMA ] : [ Portable_Archive_Container::SCHEMA ],true ) || ! is_string( $receipt['archiveId'] ) || ! preg_match( '/^[a-f0-9]{32}$/D',$receipt['archiveId'] ) || $receipt['archiveId'] === $value['recoveryArchiveId'] || ! is_string( $receipt['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$receipt['sha256'] ) ) self::fail( 'The observed source member receipt is invalid.' );
			foreach ( array_diff( $keys,[ 'schema','archiveId','sha256','transactionEnded' ] ) as $key ) if ( ! is_int( $receipt[$key] ) || $receipt[$key] < 0 ) self::fail( 'The observed source member totals are invalid.' );
			if ( $receipt['frames'] < 1 || $receipt['frames'] > Portable_Database_Capture::MAX_FRAMES || $receipt['bytes'] > 19327352832 || ( 'database' === $kind && ( $receipt['transactionEnded'] !== true || $receipt['tables'] < 1 || $receipt['tables'] > Portable_Database_Capture::MAX_TABLES || $receipt['rows'] > Portable_Database_Capture::MAX_ROWS || $receipt['bytes'] > Portable_Database_Capture::MAX_BYTES || $receipt['outputBytes'] < 1 || $receipt['outputBytes'] > 8589934592 ) ) ) self::fail( 'The observed source member exceeds its supported totals.' );
		}
		if ( $value['database']['archiveId'] === $value['files']['archiveId'] ) self::fail( 'Observed source members require independent encryption contexts.' );
	}
	/** Recovery observation uses the existing portable cipher, never the selective same-site seal. */
	public static function seal( array $value, #[\SensitiveParameter] string $phrase ): string {
		self::validate( $value ); $cipher = new Portable_Archive_Cipher( Portable_Archive_Cipher::new_header(),$phrase );
		$header = json_encode( $cipher->header(),JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		return self::MAGIC.pack( 'N',strlen( $header ) ).$header.$cipher->seal( json_encode( $value,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),0,'index' );
	}
	/** No site identity is assumed before authentication; restoration still has its separate acceptance gate. */
	public static function open( string $bytes, #[\SensitiveParameter] string $phrase ): array {
		if ( strlen( $bytes ) > 1048576 || strlen( $bytes ) < 14 || substr( $bytes,0,8 ) !== self::MAGIC ) self::fail( 'The portable source observation is invalid or too large.' );
		$length = unpack( 'Nsize',substr( $bytes,8,4 ) )['size'];
		if ( $length < 2 || $length > 8192 || strlen( $bytes ) < 12+$length+45 ) self::fail( 'The portable source observation header is invalid.' );
		try { $header = json_decode( substr( $bytes,12,$length ),true,8,JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { self::fail( 'The portable source observation header is damaged.' ); }
		if ( ! is_array( $header ) ) self::fail( 'The portable source observation header is invalid.' ); $cipher = new Portable_Archive_Cipher( $header,$phrase );
		try { $value = json_decode( $cipher->open( substr( $bytes,12+$length ),0,'index' ),true,16,JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { self::fail( 'The passphrase is wrong or the portable source observation is damaged.' ); }
		if ( ! is_array( $value ) ) self::fail( 'The portable source observation is invalid.' ); self::validate( $value ); return $value;
	}
	private static function stamps( string $root, array $plan, ?callable $budget ): array {
		$out = []; $real = realpath( $root );
		if ( ! $real || ! is_dir( $real ) || is_link( $root ) ) self::fail( 'The source directory changed during capture.' );
		foreach ( $plan['files'] as $entry ) {
			if ( $budget ) $budget(); Portable_File_Inventory::relative( $entry['path'],$plan['namePolicy'] );
			$path = $real;
			foreach ( explode( '/',$entry['path'] ) as $part ) { $path .= DIRECTORY_SEPARATOR.$part; if ( is_link( $path ) ) self::fail( 'A source file became linked during capture.' ); }
			clearstatcache( true,$path ); $stat = @lstat( $path );
			if ( ! $stat || ( $stat['mode'] & 0170000 ) !== 0100000 || $stat['size'] !== $entry['bytes'] ) self::fail( 'A source file changed during capture.' );
			// Keep the exact six integer fields without a six-entry PHP hash table per file.
			$out[$entry['path']] = implode( ':',[ $stat['dev'],$stat['ino'],$stat['mode'],$stat['size'],$stat['mtime'],$stat['ctime'] ] );
		}
		return $out;
	}
	private static function same( string $root, array $plan, array $stamps, ?callable $budget ): void {
		Portable_File_Inventory::unchanged_tree( $root,$plan,$budget );
		if ( self::stamps( $root,$plan,$budget ) !== $stamps ) self::fail( 'Source file identity or metadata changed during capture. Start a fresh attempt.' );
	}
	/** Both sinks are fresh and private. No completed members are returned after any failed observation. */
	public static function capture( string $root, array $plan, \wpdb $wpdb, $database, $files, string $phrase, ?callable $budget = null ): array {
		return self::observe( $root,$plan,$wpdb,$database,$files,$phrase,$budget,false );
	}
	/** Explicit private retry: authenticate retained ciphertext and reobserve all current source files. */
	public static function capture_existing_files( string $root, array $plan, \wpdb $wpdb, $database, $files, string $phrase, ?callable $budget = null ): array {
		return self::observe( $root,$plan,$wpdb,$database,$files,$phrase,$budget,true );
	}
	private static function existing_files( $files, string $phrase, ?callable $budget ): array {
		if ( ! rewind( $files ) ) self::fail( 'The retained encrypted files could not be rewound.' );
		$review = Portable_Archive_Container::verify( $files,$phrase,$budget );
		$bytes = ftell( $files ); $hash = hash_init( 'sha256' );
		if ( ! is_int( $bytes ) || ! rewind( $files ) ) self::fail( 'The retained encrypted file extent is unavailable.' );
		while ( ! feof( $files ) ) { if ( $budget ) $budget(); $chunk = fread( $files,1048576 ); if ( ! is_string( $chunk ) || ( '' === $chunk && ! feof( $files ) ) ) self::fail( 'The retained encrypted files could not be fingerprinted.' ); hash_update( $hash,$chunk ); }
		if ( ftell( $files ) !== $bytes || ! rewind( $files ) ) self::fail( 'The retained encrypted files changed extent.' );
		return $review + [ 'bytes'=>$bytes,'sha256'=>hash_final( $hash ) ];
	}
	private static function observe( string $root, array $plan, \wpdb $wpdb, $database, $files, string $phrase, ?callable $budget, bool $reuse ): array {
		if ( $database === $files ) self::fail( 'Database and file capture require distinct private streams.' );
		Portable_File_Inventory::validate_plan( $plan,$budget );
		$existing = null;
		if ( $reuse ) {
			$existing = self::existing_files( $files,$phrase,$budget );
			if ( $existing['inventory'] !== $plan || ! rewind( $files ) ) self::fail( 'The retained encrypted files do not match the current reviewed source.' );
			// The caller's reviewed plan is already retained. Keep the authenticated descriptor,
			// then authenticate and compare the complete inventory again after the read view.
			unset( $existing['inventory'] );
		}
		Portable_File_Inventory::unchanged_tree( $root,$plan,$budget ); $stamps = self::stamps( $root,$plan,$budget );
		$database_cipher = new Portable_Archive_Cipher( Portable_Archive_Cipher::new_header(),$phrase );
		$file_cipher = $reuse ? null : new Portable_Archive_Cipher( Portable_Archive_Cipher::new_header(),$phrase ); $file_receipt = $existing ? array_intersect_key( $existing,array_flip( [ 'schema','archiveId','frames','bytes','sha256' ] ) ) : null; $phases = [];
		$checkpoint = static function( string $phase, callable $read_view ) use ( $root,$plan,$stamps,$files,$file_cipher,$budget,&$file_receipt,&$phases ): void {
			if ( ( ! $phases && 'opened' !== $phase ) || ( $phases && ( $phases !== [ 'opened' ] || 'closing' !== $phase ) ) ) self::fail( 'The native capture observation order is invalid.' );
			$last_read_view = 0;
			$observed_budget = static function() use ( $budget,$read_view,&$last_read_view ): void {
				if ( $budget ) $budget(); $now = hrtime( true );
				if ( $now-$last_read_view >= 2000000000 ) { $read_view(); $last_read_view = $now; }
			};
			self::same( $root,$plan,$stamps,$observed_budget );
			if ( 'opened' === $phase && null !== $file_cipher ) {
				$file_receipt = Portable_Archive_Container::capture( $root,$plan,$files,$file_cipher,$observed_budget );
				self::same( $root,$plan,$stamps,$observed_budget );
			}
			$phases[] = $phase;
		};
		$database_receipt = Portable_Database_Capture::capture( $wpdb,$database,$database_cipher,$budget,$checkpoint );
		if ( $reuse ) {
			$closing = self::existing_files( $files,$phrase,$budget );
			if ( $closing['inventory'] !== $plan ) self::fail( 'The retained encrypted inventory changed during the native read view.' );
			unset( $closing['inventory'] );
			if ( $closing !== $existing ) self::fail( 'The retained encrypted files changed during the native read view.' );
		}
		if ( $phases !== [ 'opened','closing' ] || ! is_array( $file_receipt ) || ! $database_receipt['transactionEnded'] ) self::fail( 'The native capture observation did not complete.' );
		return [ 'schema'=>self::SCHEMA,'strategy'=>'native-read-view-with-file-drift-checks','phases'=>$phases,
			'fileInventorySha256'=>hash( 'sha256',json_encode( $plan,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ),
			'fileCount'=>count( $plan['files'] ),'fileBytes'=>$plan['bytes'],'database'=>$database_receipt,'files'=>$file_receipt,
			'observedStable'=>true,'writerFreezeVerified'=>false,'databaseFileConsistencyVerified'=>false ];
	}
}
