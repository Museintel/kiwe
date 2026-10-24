<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Authenticated transport for related native members. No live freeze, job, authority or restore writer. */
final class Portable_Recovery_Set {
	public const SCHEMA = 'kiwe.portable-recovery-set.v1';
	private const MAGIC = 'KIWEPRS1';
	private const ORDER = [ 'source','database','files' ];
	private const LIMITS = [ 'source'=>8388608,'database'=>8589934592,'files'=>19327352832 ];
	private const MAX_FRAMES = 56000;
	private const CHUNK = Portable_Archive_Cipher::CHUNK_BYTES - 1;
	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function keys( array $value, array $keys ): bool { return count( $value ) === count( $keys ) && ! array_diff( $keys,array_keys( $value ) ); }
	private static function tick( ?callable $budget ): void { if ( $budget ) $budget(); }
	private static function json( array $value ): string {
		try { return json_encode( $value,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); } catch ( \JsonException $error ) { self::fail( 'The recovery set metadata could not be encoded.' ); }
	}
	private static function object( string $bytes, int $max = 65536 ): array {
		if ( strlen( $bytes ) > $max ) self::fail( 'The recovery set metadata exceeds its limit.' );
		try { $value = json_decode( $bytes,true,24,JSON_THROW_ON_ERROR ); } catch ( \JsonException $error ) { self::fail( 'The recovery set metadata is damaged.' ); }
		if ( ! is_array( $value ) || array_is_list( $value ) ) self::fail( 'The recovery set metadata is invalid.' ); return $value;
	}
	private static function stream( $stream, bool $writing = false ): void {
		if ( ! is_resource( $stream ) || 'stream' !== get_resource_type( $stream ) ) self::fail( 'An ordinary local recovery stream is required.' );
		$meta = stream_get_meta_data( $stream ); $stat = fstat( $stream ); $mode = $meta['mode'] ?? '';
		if ( ! in_array( $meta['wrapper_type'] ?? '',[ 'plainfile','PHP' ],true ) || empty( $meta['seekable'] ) || ftell( $stream ) !== 0 || ! $stat ) self::fail( 'A local recovery stream at its beginning is required.' );
		if ( $writing && ( ! preg_match( '/[waxc+]/',$mode ) || $stat['size'] !== 0 ) ) self::fail( 'An empty writable recovery stream is required.' );
		if ( ! $writing && ! str_contains( $mode,'r' ) && ! str_contains( $mode,'+' ) ) self::fail( 'A readable recovery stream is required.' );
	}
	private static function read( $stream, int $length ): string {
		$out = ''; while ( strlen( $out ) < $length ) { $bytes = @fread( $stream,$length-strlen( $out ) ); if ( ! is_string( $bytes ) || '' === $bytes ) self::fail( 'The recovery set is incomplete or unreadable.' ); $out .= $bytes; } return $out;
	}
	private static function write( $stream, string $bytes ): void {
		$offset = 0; while ( $offset < strlen( $bytes ) ) { $count = @fwrite( $stream,substr( $bytes,$offset,65536 ) ); if ( ! is_int( $count ) || $count < 1 ) self::fail( 'Private recovery storage is full or unavailable.' ); $offset += $count; }
	}
	private static function rewind( $stream ): void { if ( ! rewind( $stream ) ) self::fail( 'A private recovery member could not be rewound.' ); }
	private static function temp() {
		$stream = @fopen( 'php://temp/maxmemory:2097152','w+b' ); if ( ! $stream ) self::fail( 'Private recovery review storage is unavailable.' ); return $stream;
	}
	/** Validate the serialized native layout against the authenticated file member, without trusting paths. */
	public static function source( array $source, array $inventory, ?callable $budget = null ): void {
		$policy = Portable_File_Inventory::validate_plan( $inventory,$budget );
		if ( ! self::keys( $source,[ 'schema','namePolicy','scope','platform','layout','dependencies','exclusions','limits' ] ) || $source['schema'] !== Portable_Source_Compatibility::SCHEMA || $source['scope'] !== 'ordinary-single-site-native-layout' || $source['namePolicy'] !== $policy || $source['exclusions'] !== $inventory['exclusions'] ) self::fail( 'The native source layout does not match its file member.' );
		foreach ( [ 'platform','layout','dependencies','limits' ] as $key ) if ( ! is_array( $source[$key] ) || array_is_list( $source[$key] ) ) self::fail( 'The native source layout is invalid.' );
		$platform = $source['platform'];
		if ( ! self::keys( $platform,[ 'php','phpMinimum','wordpress','wordpressDatabaseVersion','kiwe','operatingSystem' ] ) || $platform['phpMinimum'] !== '8.2' || ! is_int( $platform['wordpressDatabaseVersion'] ) || $platform['wordpressDatabaseVersion'] < 1 || ! in_array( $platform['operatingSystem'],[ 'Windows','Linux','Darwin','BSD','Solaris' ],true ) || ( 'Windows' === $platform['operatingSystem'] && 'posix' === $policy ) ) self::fail( 'The native source platform metadata is invalid.' );
		foreach ( [ 'php','wordpress','kiwe' ] as $key ) if ( ! is_string( $platform[$key] ) || strlen( $platform[$key] ) > 64 || ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:[-+.][a-zA-Z0-9.-]+)?$/D',$platform[$key] ) ) self::fail( 'A native source version is invalid.' );
		if ( version_compare( $platform['php'],'8.2','<' ) || version_compare( $platform['wordpress'],'6.0','<' ) ) self::fail( 'The native source platform is unsupported.' );
		if ( ! self::keys( $source['limits'],[ 'databaseCaptureVerified','databaseFileConsistencyVerified','externalServicesCaptured','destinationCompatibilityVerified' ] ) || array_filter( $source['limits'],static fn($value)=>false !== $value ) ) self::fail( 'A source layout cannot claim unverified capture, provider or destination acceptance.' );
		$files = array_fill_keys( array_column( $inventory['files'],'path' ),true ); $dirs = array_fill_keys( $inventory['directories'],true );
		$path = static function( $value, bool $directory ) use ( $policy,$files,$dirs,$budget ): string {
			self::tick( $budget ); if ( ! is_string( $value ) ) self::fail( 'A source dependency path is invalid.' ); Portable_File_Inventory::relative( $value,$policy );
			if ( ! isset( ( $directory ? $dirs : $files )[$value] ) ) self::fail( 'A required source dependency is absent from its file member.' ); return $value;
		};
		$list = static function( $values, bool $directory, int $limit ) use ( $path ): array {
			if ( ! is_array( $values ) || ! array_is_list( $values ) || count( $values ) > $limit ) self::fail( 'The source dependency list is invalid or too large.' ); $previous = null;
			foreach ( $values as $value ) { $path( $value,$directory ); if ( null !== $previous && strcmp( $previous,$value ) >= 0 ) self::fail( 'Source dependencies must be ordered and unique.' ); $previous = $value; } return $values;
		};
		$layout = $source['layout']; $deps = $source['dependencies'];
		if ( ! self::keys( $layout,[ 'core','content','plugins','mustUsePlugins','themeRoots','uploads' ] ) || ! self::keys( $deps,[ 'activePlugins','mustUseEntries','themes','kiwe','loadedFiles' ] ) ) self::fail( 'The native source dependency shape is invalid.' );
		foreach ( [ 'content','plugins','mustUsePlugins','uploads' ] as $key ) $path( $layout[$key],true ); $roots = $list( $layout['themeRoots'],true,100 );
		$core = $layout['core']; if ( ! is_array( $core ) || ! array_is_list( $core ) || count( $core ) !== 12 ) self::fail( 'The native source core dependencies are incomplete.' ); foreach ( $core as $file ) $path( $file,false ); if ( count( array_unique( $core ) ) !== 12 ) self::fail( 'The native source core dependencies are incomplete.' );
		foreach ( [ 'index.php','wp-blog-header.php','wp-load.php','wp-settings.php','wp-config.php','wp-login.php','wp-cron.php','wp-admin/admin.php','wp-admin/includes/admin.php' ] as $required ) if ( ! in_array( $required,$core,true ) ) self::fail( 'The native source core dependencies are incomplete.' );
		$inc = array_values( array_filter( $core,static fn($value)=>! in_array( $value,[ 'index.php','wp-blog-header.php','wp-load.php','wp-settings.php','wp-config.php','wp-login.php','wp-cron.php','wp-admin/admin.php','wp-admin/includes/admin.php' ],true ) ) );
		if ( count( $inc ) !== 3 || ! str_ends_with( $inc[0],'/version.php' ) || $inc[1] !== dirname( $inc[0] ).'/load.php' || $inc[2] !== dirname( $inc[0] ).'/functions.php' || str_contains( dirname( $inc[0] ),'/' ) ) self::fail( 'The native source core include layout is unsupported.' );
		foreach ( $list( $deps['activePlugins'],false,1000 ) as $file ) if ( ! str_starts_with( $file,$layout['plugins'].'/' ) || ! str_ends_with( $file,'.php' ) ) self::fail( 'An active plugin is outside the native plugin root.' );
		foreach ( $list( $deps['mustUseEntries'],false,1000 ) as $file ) if ( dirname( $file ) !== $layout['mustUsePlugins'] || ! str_ends_with( $file,'.php' ) ) self::fail( 'An automatic MU entry is outside its native root.' );
		$list( $deps['loadedFiles'],false,20000 );
		if ( ! is_array( $deps['themes'] ) || ! self::keys( $deps['themes'],[ 'template','stylesheet' ] ) || ! is_array( $deps['kiwe'] ) || ! self::keys( $deps['kiwe'],[ 'loader','package','entry' ] ) ) self::fail( 'The native theme or Kiwe dependency shape is invalid.' );
		foreach ( $deps['themes'] as $theme ) {
			if ( ! is_array( $theme ) || ! self::keys( $theme,[ 'directory','root' ] ) ) self::fail( 'A native theme dependency is invalid.' ); $path( $theme['root'],true ); $path( $theme['directory'],true ); $path( $theme['directory'].'/style.css',false );
			if ( ! in_array( $theme['root'],$roots,true ) || dirname( $theme['directory'] ) !== $theme['root'] ) self::fail( 'An active theme is outside its declared root.' );
		}
		$kiwe = $deps['kiwe']; $path( $kiwe['loader'],false ); $path( $kiwe['package'],true ); $path( $kiwe['entry'],false );
		if ( dirname( $kiwe['loader'] ) !== $layout['mustUsePlugins'] || ! in_array( $kiwe['loader'],$deps['mustUseEntries'],true ) || ! str_starts_with( $kiwe['package'],$layout['mustUsePlugins'].'/' ) || ! str_starts_with( $kiwe['entry'],$kiwe['package'].'/' ) ) self::fail( 'The native Kiwe package dependencies are inconsistent.' );
	}
	private static function descriptor( $stream, string $kind, ?callable $budget ): array {
		self::stream( $stream ); $stat = fstat( $stream ); if ( $stat['size'] < 1 || $stat['size'] > self::LIMITS[$kind] ) self::fail( 'A recovery member exceeds its supported size.' ); $hash = hash_init( 'sha256' ); $read = 0;
		while ( ! feof( $stream ) ) { self::tick( $budget ); $bytes = @fread( $stream,self::CHUNK ); if ( false === $bytes || ( '' === $bytes && ! feof( $stream ) ) ) self::fail( 'A recovery member could not be read.' ); $read += strlen( $bytes ); if ( $read > $stat['size'] ) self::fail( 'A recovery member changed during review.' ); hash_update( $hash,$bytes ); }
		if ( $read !== $stat['size'] ) self::fail( 'A recovery member changed during review.' ); self::rewind( $stream ); return [ 'kind'=>$kind,'bytes'=>$read,'sha256'=>hash_final( $hash ) ];
	}
	/** Input members are fully verified before copying. This authenticates membership, not source quiescence. */
	public static function create( $database, $files, array $source, $output, string $phrase, ?callable $budget = null ): array {
		self::stream( $database ); self::stream( $files ); self::stream( $output,true );
		if ( $database === $files || $database === $output || $files === $output ) self::fail( 'Recovery members and output must be distinct streams.' );
		$db_descriptor = self::descriptor( $database,'database',$budget ); $file_descriptor = self::descriptor( $files,'files',$budget );
		$db_review = Portable_Database_Capture::verify( $database,$phrase,$budget ); $file_review = Portable_Archive_Container::verify( $files,$phrase,$budget );
		if ( $db_review['archiveId'] === $file_review['archiveId'] ) self::fail( 'Recovery members must have independent encryption contexts.' );
		self::source( $source,$file_review['inventory'],$budget ); self::rewind( $database ); self::rewind( $files );
		$source_stream = self::temp();
		try {
			self::write( $source_stream,self::json( $source ) ); self::rewind( $source_stream ); $source_descriptor = self::descriptor( $source_stream,'source',$budget );
			$members = [ 'source'=>$source_stream,'database'=>$database,'files'=>$files ]; $descriptors = [ $source_descriptor,$db_descriptor,$file_descriptor ];
			$cipher = new Portable_Archive_Cipher( Portable_Archive_Cipher::new_header(),$phrase ); $header = self::json( $cipher->header() ); $sequence = 0; $bytes = 0; $transport = hash_init( 'sha256' ); $transcript = hash_init( 'sha256' );
			$emit = static function( string $plain, string $purpose ) use ( $output,$cipher,$budget,&$sequence,&$bytes,$transport,$transcript ): void {
				self::tick( $budget ); if ( $sequence >= self::MAX_FRAMES ) self::fail( 'The recovery set exceeds its supported frame count.' ); $frame = $cipher->seal( $plain,$sequence,$purpose ); $packet = ( 'index' === $purpose ? 'I' : ( 'manifest' === $purpose ? 'M' : 'D' ) ).pack( 'N',strlen( $frame ) ).$frame; self::write( $output,$packet ); hash_update( $transport,$packet ); if ( 'manifest' !== $purpose ) hash_update( $transcript,$packet ); $bytes += strlen( $packet ); ++$sequence;
			};
			$prefix = self::MAGIC.pack( 'N',strlen( $header ) ).$header; self::write( $output,$prefix ); hash_update( $transport,$prefix ); $bytes += strlen( $prefix );
			$index = [ 'schema'=>self::SCHEMA,'memberCount'=>3,'consistency'=>'members-authenticated-only' ]; $emit( self::json( $index ),'index' );
			foreach ( $descriptors as $descriptor ) {
				$stream = $members[$descriptor['kind']]; $emit( 'P'.self::json( $descriptor ),'data' ); $hash = hash_init( 'sha256' ); $read = 0;
				while ( ! feof( $stream ) ) { self::tick( $budget ); $chunk = @fread( $stream,self::CHUNK ); if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) ) self::fail( 'A reviewed recovery member could not be copied.' ); if ( '' === $chunk ) break; $read += strlen( $chunk ); if ( $read > $descriptor['bytes'] ) self::fail( 'A recovery member grew after review.' ); hash_update( $hash,$chunk ); $emit( 'B'.$chunk,'data' ); }
				if ( $read !== $descriptor['bytes'] || ! hash_equals( $descriptor['sha256'],hash_final( $hash ) ) ) self::fail( 'A recovery member changed after review.' ); $emit( 'E'.self::json( $descriptor ),'data' );
			}
			$emit( self::json( $index + [ 'members'=>$descriptors,'frames'=>$sequence,'transcript'=>hash_final( $transcript ) ] ),'manifest' );
			if ( ! fflush( $output ) ) self::fail( 'The recovery set could not be flushed.' );
			return [ 'schema'=>self::SCHEMA,'archiveId'=>$cipher->header()['archiveId'],'outputBytes'=>$bytes,'sha256'=>hash_final( $transport ),'frames'=>$sequence,'memberCount'=>3,'consistency'=>'members-authenticated-only' ];
		} finally { fclose( $source_stream ); }
	}
	/** No extraction or row consumers; ciphertext members spill privately and are retired on all exits. */
	public static function verify( $input, string $phrase, ?callable $budget = null, ?callable $temporary = null ): array {
		self::stream( $input ); if ( self::read( $input,8 ) !== self::MAGIC ) self::fail( 'This is not a portable recovery set.' ); $length = unpack( 'Nsize',self::read( $input,4 ) )['size']; if ( $length < 1 || $length > 2048 ) self::fail( 'The recovery set header exceeds its limit.' );
		$cipher = new Portable_Archive_Cipher( self::object( self::read( $input,$length ) ),$phrase ); $sequence = 0; $index = null; $current = null; $streams = []; $descriptors = []; $read = 0; $hash = null; $transcript = hash_init( 'sha256' );
		try {
			while ( true ) {
				self::tick( $budget ); if ( $sequence >= self::MAX_FRAMES ) self::fail( 'The recovery set has too many frames.' ); $envelope = self::read( $input,5 ); $kind = $envelope[0]; $size = unpack( 'Nsize',substr( $envelope,1 ) )['size'];
				if ( ! in_array( $kind,[ 'I','D','M' ],true ) || ( 0 === $sequence ) !== ( 'I' === $kind ) || $size < 45 || $size > Portable_Archive_Cipher::CHUNK_BYTES+45 ) self::fail( 'The recovery set envelope is invalid.' );
				$frame = self::read( $input,$size ); $plain = $cipher->open( $frame,$sequence,'I' === $kind ? 'index' : ( 'M' === $kind ? 'manifest' : 'data' ) );
				if ( 'I' === $kind ) {
					$index = self::object( $plain ); if ( $index !== [ 'schema'=>self::SCHEMA,'memberCount'=>3,'consistency'=>'members-authenticated-only' ] ) self::fail( 'The recovery set index is unsupported.' );
				} elseif ( 'M' === $kind ) {
					$final = self::object( $plain ); if ( null !== $current || count( $descriptors ) !== 3 || ! self::keys( $final,[ 'schema','memberCount','consistency','members','frames','transcript' ] ) || $final['members'] !== $descriptors || $final['frames'] !== $sequence || ! is_string( $final['transcript'] ) || ! hash_equals( hash_final( $transcript ),$final['transcript'] ) ) self::fail( 'The recovery set completeness check failed.' ); unset( $final['members'],$final['frames'],$final['transcript'] ); if ( $final !== $index ) self::fail( 'The recovery set summary changed.' );
					$tail = @fread( $input,1 ); if ( false === $tail || '' !== $tail || ! feof( $input ) ) self::fail( 'The recovery set contains unexpected trailing content.' );
					foreach ( $streams as $stream ) self::rewind( $stream );
					$source = self::object( self::read( $streams['source'],$descriptors[0]['bytes'] ),self::LIMITS['source'] );
					$db = Portable_Database_Capture::verify( $streams['database'],$phrase,$budget ); $files = Portable_Archive_Container::verify( $streams['files'],$phrase,$budget );
					if ( $db['archiveId'] === $files['archiveId'] || in_array( $cipher->header()['archiveId'],[ $db['archiveId'],$files['archiveId'] ],true ) ) self::fail( 'Recovery encryption contexts are not independent.' );
					self::source( $source,$files['inventory'],$budget );
					return [ 'schema'=>self::SCHEMA,'archiveId'=>$cipher->header()['archiveId'],'frames'=>$sequence+1,'members'=>$descriptors,'source'=>$source,'database'=>$db,'files'=>$files,'consistency'=>'members-authenticated-only','databaseFileConsistencyVerified'=>false,'destinationCompatibilityVerified'=>false ];
				} else {
					if ( '' === $plain ) self::fail( 'A recovery set record is empty.' ); $type = $plain[0]; $body = substr( $plain,1 );
					if ( 'P' === $type ) {
						if ( null !== $current || count( $descriptors ) >= 3 ) self::fail( 'A recovery member is misplaced.' ); $current = self::object( $body ); $expected = self::ORDER[count( $descriptors )];
						if ( ! self::keys( $current,[ 'kind','bytes','sha256' ] ) || $current['kind'] !== $expected || ! is_int( $current['bytes'] ) || $current['bytes'] < 1 || $current['bytes'] > self::LIMITS[$expected] || ! is_string( $current['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$current['sha256'] ) ) self::fail( 'A recovery member descriptor is invalid.' );
						$member_stream = $temporary ? $temporary( $expected ) : self::temp();
						if ( in_array( $member_stream,$streams,true ) || $member_stream === $input ) self::fail( 'Recovery review requires distinct temporary streams.' );
						try { self::stream( $member_stream,true ); } catch ( \Throwable $error ) { if ( is_resource( $member_stream ) && $member_stream !== $input ) fclose( $member_stream ); throw $error; }
						$streams[$expected] = $member_stream; $read = 0; $hash = hash_init( 'sha256' );
					} elseif ( 'B' === $type ) {
						if ( null === $current || '' === $body || $read+strlen( $body ) > $current['bytes'] ) self::fail( 'A recovery member body is misplaced or too large.' ); $read += strlen( $body ); hash_update( $hash,$body ); self::write( $streams[$current['kind']],$body );
					} elseif ( 'E' === $type ) {
						$end = self::object( $body ); if ( null === $current || $end !== $current || $read !== $current['bytes'] || ! hash_equals( $current['sha256'],hash_final( $hash ) ) ) self::fail( 'A recovery member is incomplete or damaged.' ); $descriptors[] = $current; $current = null; $hash = null;
					} else self::fail( 'A recovery set record is unsupported.' );
				}
				hash_update( $transcript,$envelope.$frame ); ++$sequence;
			}
		} finally { foreach ( $streams as $stream ) if ( is_resource( $stream ) ) fclose( $stream ); }
	}
}
