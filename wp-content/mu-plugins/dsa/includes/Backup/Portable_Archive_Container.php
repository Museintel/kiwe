<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Draft file container only: no database, consistency lease, actor, job or extraction authority. */
final class Portable_Archive_Container {
	public const SCHEMA = 'kiwe.portable-file-container.v2';
	private const MAGIC = "KIWEPFC2";
	private const MAX_FRAMES = 650000;
	private const MAX_METADATA = 67108864;
	private const MAX_HEADER = 2048;
	private const DATA_BYTES = Portable_Archive_Cipher::CHUNK_BYTES - 1;

	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function keys( array $value, array $keys ): bool { return count( $value ) === count( $keys ) && ! array_diff( $keys,array_keys( $value ) ); }
	private static function json( array $value ): string {
		try { return json_encode( $value,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); } catch ( \JsonException $e ) { self::fail( 'Portable backup metadata could not be encoded.' ); }
	}
	private static function object( string $value ): array {
		if ( strlen( $value ) > 65536 ) self::fail( 'Portable backup metadata exceeds its supported size.' );
		try { $row = json_decode( $value,true,16,JSON_THROW_ON_ERROR ); } catch ( \JsonException $e ) { self::fail( 'Portable backup metadata is damaged.' ); }
		if ( ! is_array( $row ) || array_is_list( $row ) ) self::fail( 'Portable backup metadata is invalid.' );
		return $row;
	}
	private static function stream( $stream, bool $writing ): void {
		if ( ! is_resource( $stream ) || 'stream' !== get_resource_type( $stream ) ) self::fail( 'An ordinary local backup stream is required.' );
		$meta = stream_get_meta_data( $stream );
		if ( ! in_array( $meta['wrapper_type'] ?? '',[ 'plainfile','PHP' ],true ) || empty( $meta['seekable'] ) || ftell( $stream ) !== 0 ) self::fail( 'An ordinary local backup stream at its beginning is required.' );
		$mode = $meta['mode'] ?? '';
		if ( $writing && ( ! preg_match( '/[waxc+]/',$mode ) || ( fstat( $stream )['size'] ?? -1 ) !== 0 ) ) self::fail( 'An empty writable backup stream is required.' );
		if ( ! $writing && ! str_contains( $mode,'r' ) && ! str_contains( $mode,'+' ) ) self::fail( 'A readable backup stream is required.' );
	}
	private static function write( $stream, string $bytes ): void {
		$offset = 0; $size = strlen( $bytes );
		while ( $offset < $size ) { $written = @fwrite( $stream,substr( $bytes,$offset ) ); if ( ! is_int( $written ) || $written < 1 ) self::fail( 'The portable backup could not be written completely.' ); $offset += $written; }
	}
	private static function read( $stream, int $size ): string {
		$out = '';
		while ( strlen( $out ) < $size ) { $part = @fread( $stream,$size-strlen( $out ) ); if ( ! is_string( $part ) || '' === $part ) self::fail( 'The portable backup is incomplete or unreadable.' ); $out .= $part; }
		return $out;
	}
	private static function summary( array $value ): void {
		if ( ! self::keys( $value,[ 'schema','files','directories','exclusions','bytes','namePolicy' ] ) || $value['schema'] !== self::SCHEMA || ! is_string( $value['namePolicy'] ) ) self::fail( 'The portable backup index is invalid.' );
		Portable_File_Inventory::name_policy( $value['namePolicy'] );
		foreach ( [ 'files','directories','exclusions','bytes' ] as $name ) if ( ! is_int( $value[$name] ) || $value[$name] < 0 ) self::fail( 'The portable backup totals are invalid.' );
		if ( $value['files'] > Portable_File_Inventory::MAX_FILES || $value['directories'] > 20000 || $value['exclusions'] > 100 || $value['bytes'] > Portable_File_Inventory::MAX_BYTES ) self::fail( 'The portable backup exceeds its supported totals.' );
	}
	private static function paths( array $plan, ?callable $budget = null ): array {
		$policy = Portable_File_Inventory::validate_plan( $plan,$budget );
		$summary = [ 'schema'=>self::SCHEMA,'files'=>count( $plan['files'] ),'directories'=>count( $plan['directories'] ),'exclusions'=>count( $plan['exclusions'] ),'bytes'=>$plan['bytes'],'namePolicy'=>$policy ]; self::summary( $summary );
		return $summary;
	}
	/** Caller owns a fresh private sink and the consistency/authority boundary. Interrupted output is unusable. */
	public static function capture( string $root, array $plan, $stream, Portable_Archive_Cipher $cipher, ?callable $budget = null ): array {
		self::stream( $stream,true ); $summary = self::paths( $plan,$budget );
		$real = realpath( $root ); if ( ! $real || ! is_dir( $real ) || is_link( $root ) ) self::fail( 'An ordinary source directory is required.' );
		$uri = stream_get_meta_data( $stream )['uri'] ?? ''; $sink = realpath( $uri );
		$normalized = rtrim( 'Windows' === PHP_OS_FAMILY ? str_replace( '\\','/',$real ) : $real,'/' );
		$sink = $sink && 'Windows' === PHP_OS_FAMILY ? str_replace( '\\','/',$sink ) : $sink;
		if ( 'Windows' === PHP_OS_FAMILY && 'posix' === $plan['namePolicy'] ) self::fail( 'POSIX source names cannot be captured on this filesystem.' );
		if ( $sink && ( $sink === $normalized || str_starts_with( $sink,$normalized.'/' ) ) ) self::fail( 'The private backup sink must be outside its source directory.' );
		$header = self::json( $cipher->header() ); $transport = hash_init( 'sha256' ); $transcript = hash_init( 'sha256' ); $bytes = 0; $sequence = 0;
		$emit = static function( string $plain, string $purpose ) use ( $stream,$cipher,$budget,&$sequence,&$bytes,$transport,$transcript ): void {
			if ( $budget ) $budget(); if ( $sequence >= self::MAX_FRAMES ) self::fail( 'The portable backup has too many frames.' );
			$frame = $cipher->seal( $plain,$sequence,$purpose ); $packet = ( 'index' === $purpose ? 'I' : ( 'manifest' === $purpose ? 'M' : 'D' ) ).pack( 'N',strlen( $frame ) ).$frame;
			self::write( $stream,$packet ); hash_update( $transport,$packet ); if ( 'manifest' !== $purpose ) hash_update( $transcript,$packet ); $bytes += strlen( $packet ); ++$sequence;
		};
		$prefix = self::MAGIC.pack( 'N',strlen( $header ) ).$header; self::write( $stream,$prefix ); hash_update( $transport,$prefix ); $bytes += strlen( $prefix );
		$emit( self::json( $summary ),'index' );
		foreach ( $plan['directories'] as $path ) $emit( 'R'.self::json( [ 'path'=>$path ] ),'data' );
		foreach ( $plan['exclusions'] as $row ) $emit( 'X'.self::json( $row ),'data' );
		foreach ( $plan['files'] as $row ) {
			$path = Portable_File_Inventory::unchanged( $root,$row,$budget,$plan['namePolicy'] ); $before = lstat( $path ); $file = @fopen( $path,'rb' ); if ( ! $file ) self::fail( 'A reviewed source file could not be opened.' );
			try {
				$opened = fstat( $file ); if ( ! $before || ! $opened || $before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev'] || $opened['size'] !== $row['bytes'] || ( $opened['mode'] & 0170000 ) !== 0100000 ) self::fail( 'A reviewed source file changed before capture.' );
				$emit( 'F'.self::json( $row ),'data' ); $hash = hash_init( 'sha256' ); $read = 0;
				while ( ! feof( $file ) ) {
					if ( $budget ) $budget(); $chunk = fread( $file,self::DATA_BYTES ); if ( false === $chunk || ( '' === $chunk && ! feof( $file ) ) ) self::fail( 'A source file could not be captured.' );
					if ( '' === $chunk ) break; $read += strlen( $chunk ); if ( $read > $row['bytes'] ) self::fail( 'A source file grew during capture.' ); hash_update( $hash,$chunk ); $emit( 'B'.$chunk,'data' );
				}
				$sha = hash_final( $hash ); if ( $read !== $row['bytes'] || ! hash_equals( $row['sha256'],$sha ) ) self::fail( 'A source file changed during capture.' );
				Portable_File_Inventory::unchanged( $root,$row,$budget,$plan['namePolicy'] ); $emit( 'E'.self::json( [ 'bytes'=>$read,'sha256'=>$sha ] ),'data' );
			} finally { fclose( $file ); }
		}
		Portable_File_Inventory::unchanged_tree( $root,$plan,$budget );
		$emit( self::json( $summary + [ 'frames'=>$sequence,'transcript'=>hash_final( $transcript ) ] ),'manifest' );
		if ( ! fflush( $stream ) ) self::fail( 'The portable backup could not be flushed.' );
		return [ 'schema'=>self::SCHEMA,'archiveId'=>$cipher->header()['archiveId'],'frames'=>$sequence,'bytes'=>$bytes,'sha256'=>hash_final( $transport ) ];
	}
	/** No extraction or callbacks receive plaintext: all metadata is returned only after final acceptance. */
	public static function verify( $stream, string $phrase, ?callable $budget = null ): array {
		return self::parse( $stream,$phrase,$budget );
	}
	/** Only the private recovery owner can consume a second, completely validated pass. */
	public static function stage_private( $stream, #[\SensitiveParameter] string $phrase, Portable_Recovery_Stage $stage, ?callable $budget = null ): array {
		$review = self::verify( $stream,$phrase,$budget );
		if ( ! rewind( $stream ) ) self::fail( 'The reviewed file member could not be rewound.' );
		$written = self::parse( $stream,$phrase,$budget,$stage );
		if ( $written !== $review ) self::fail( 'The file member changed after complete review.' );
		return $written;
	}
	private static function parse( $stream, string $phrase, ?callable $budget, ?Portable_Recovery_Stage $stage = null ): array {
		self::stream( $stream,false ); if ( self::read( $stream,8 ) !== self::MAGIC ) self::fail( 'This is not a portable file container.' );
		$size = unpack( 'Nsize',self::read( $stream,4 ) )['size']; if ( $size < 1 || $size > self::MAX_HEADER ) self::fail( 'The portable backup header exceeds its supported size.' );
		$cipher = new Portable_Archive_Cipher( self::object( self::read( $stream,$size ) ),$phrase );
		$sequence = 0; $transcript = hash_init( 'sha256' ); $summary = null; $file = null; $hash = null; $read = 0; $phase = 0; $metadata = 0;
		$plan = [ 'files'=>[],'directories'=>[],'exclusions'=>[],'bytes'=>0,'namePolicy'=>null ];
		while ( true ) {
			if ( $budget ) $budget(); if ( $sequence >= self::MAX_FRAMES ) self::fail( 'The portable backup has too many frames.' );
			$envelope = self::read( $stream,5 ); $kind = $envelope[0]; $length = unpack( 'Nsize',substr( $envelope,1 ) )['size'];
			if ( ! in_array( $kind,[ 'I','D','M' ],true ) || ( 0 === $sequence ) !== ( 'I' === $kind ) || $length < 45 || $length > Portable_Archive_Cipher::CHUNK_BYTES+45 ) self::fail( 'The portable backup frame envelope is invalid.' );
			$frame = self::read( $stream,$length ); $plain = $cipher->open( $frame,$sequence,'I' === $kind ? 'index' : ( 'M' === $kind ? 'manifest' : 'data' ) );
			if ( 'I' === $kind ) { $summary = self::object( $plain ); self::summary( $summary ); $plan['namePolicy'] = $summary['namePolicy']; }
			elseif ( 'M' === $kind ) {
				if ( null !== $file ) self::fail( 'The portable backup ends within a file.' );
				$final = self::object( $plain );
				if ( ! self::keys( $final,[ 'schema','files','directories','exclusions','bytes','namePolicy','frames','transcript' ] ) || ! is_int( $final['frames'] ) || $final['frames'] !== $sequence || ! is_string( $final['transcript'] ) || ! hash_equals( hash_final( $transcript ),$final['transcript'] ) ) self::fail( 'The portable backup completeness check failed.' );
				unset( $final['frames'],$final['transcript'] ); if ( $final !== $summary || self::paths( $plan,$budget ) !== $summary ) self::fail( 'The portable backup totals or paths are inconsistent.' );
				$tail = @fread( $stream,1 ); if ( false === $tail || '' !== $tail || ! feof( $stream ) ) self::fail( 'The portable backup contains unexpected trailing content.' );
				return [ 'schema'=>self::SCHEMA,'archiveId'=>$cipher->header()['archiveId'],'frames'=>$sequence+1,'inventory'=>$plan ];
			} else {
				if ( '' === $plain ) self::fail( 'A portable backup record is empty.' ); $type = $plain[0]; $body = substr( $plain,1 );
				if ( 'B' === $type ) {
					if ( null === $file || '' === $body ) self::fail( 'A portable file body is misplaced or empty.' ); $read += strlen( $body ); if ( $read > $file['bytes'] ) self::fail( 'A portable file body exceeds its reviewed size.' ); hash_update( $hash,$body );
				} elseif ( 'E' === $type ) {
					$end = self::object( $body ); if ( null === $file || ! self::keys( $end,[ 'bytes','sha256' ] ) || $read !== $file['bytes'] || $end !== [ 'bytes'=>$read,'sha256'=>$file['sha256'] ] || ! hash_equals( $file['sha256'],hash_final( $hash ) ) ) self::fail( 'A portable file is incomplete or damaged.' );
					$plan['files'][] = $file; $plan['bytes'] += $read; $file = null; $hash = null;
				} else {
					if ( null !== $file ) self::fail( 'Portable backup metadata interrupts a file.' ); $metadata += strlen( $body ); if ( $metadata > self::MAX_METADATA ) self::fail( 'Portable backup metadata exceeds its limit.' ); $row = self::object( $body );
					if ( 'R' === $type ) { if ( $phase > 0 || ! self::keys( $row,[ 'path' ] ) || ! is_string( $row['path'] ) || count( $plan['directories'] ) >= $summary['directories'] ) self::fail( 'A portable directory record is invalid.' ); Portable_File_Inventory::relative( $row['path'],$plan['namePolicy'] ); $plan['directories'][] = $row['path']; }
					elseif ( 'X' === $type ) { if ( $phase > 1 || count( $plan['exclusions'] ) >= $summary['exclusions'] ) self::fail( 'A portable exclusion record is invalid.' ); $phase = 1; $plan['exclusions'][] = $row; }
					elseif ( 'F' === $type ) {
						if ( count( $plan['files'] ) >= $summary['files'] || ! self::keys( $row,[ 'path','bytes','sha256' ] ) || ! is_string( $row['path'] ) || ! is_int( $row['bytes'] ) || $row['bytes'] < 0 || $row['bytes'] > 2147483648 || ! is_string( $row['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$row['sha256'] ) || $plan['bytes']+$row['bytes'] > $summary['bytes'] ) self::fail( 'A portable file record is invalid.' );
						Portable_File_Inventory::relative( $row['path'],$plan['namePolicy'] ); $phase = 2; $file = $row; $hash = hash_init( 'sha256' ); $read = 0;
					} else self::fail( 'A portable backup record is unsupported.' );
				}
			}
			if ( $stage && 'D' === $kind ) $stage->file_record( $plain );
			hash_update( $transcript,$envelope.$frame ); ++$sequence;
		}
	}
}
