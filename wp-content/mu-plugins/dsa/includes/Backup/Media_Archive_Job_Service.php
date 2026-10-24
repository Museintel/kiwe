<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Browser-driven bounded media work in the existing private vault; WordPress owns final copies. */
final class Media_Archive_Job_Service {
	public const SCHEMA = 'kiwe.media-set.v1';
	public const FONT_SCHEMA = 'kiwe.bricks-font-set.v1';
	public const DESIGN_SCHEMA = 'kiwe.bricks-design-assets.v1';
	private static function font_scope(string $scope): bool { return in_array($scope,['bricks_fonts','bricks_design_assets'],true); }
	public const MAX_BYTES = 134217728;
	public const CHUNK_BYTES = 524288;
	private const STATE = 'kiwe.media-job.v1';
	private const FRAME = 'kiwe.media-chunk.v1';
	private const STATE_BYTES = 8388608;
	private const INDEX_BYTES = 4194304;
	private const FRAME_BYTES = 2097152;
	private const MAX_CHUNKS = 5000;
	private const LIFETIME = 604800;
	private static function directory( string $id, bool $create = false ): string { return Archive_Job_Files::directory( 'media', $id, $create ); }
	private static function info( string $dir ): array {
		$info = json_decode( Archive_Job_Files::read( $dir, 'info.json', self::STATE_BYTES ), true, 32 );
		if ( ! is_array( $info ) || ( $info['schema'] ?? '' ) !== self::STATE || ! is_int( $info['actor'] ?? null ) || ! is_int( $info['revision'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $info['id'] ?? '' ) || ! in_array( $info['mode'] ?? '', [ 'export','import' ], true ) || ! in_array( $info['scope'] ?? '', [ 'media','attachment','bricks_fonts','bricks_design_assets' ], true ) || ! is_string( $info['state'] ?? null ) || ! is_string( $info['stateHash'] ?? null ) ) throw new Archive_Error( 'The saved media progress is invalid.' );
		return $info;
	}
	private static function authority( array $info, bool $remove = false ): void {
		if ( $info['actor'] !== get_current_user_id() || ! is_user_logged_in() || ! current_user_can( 'read' ) || ( ! $remove && ( ! Media_Archive_Service::can_use() || ( 'export' === $info['mode'] && ! Media_Archive_Service::can_export( $info['sourceId'] ) ) ) ) ) throw new Archive_Error( 'This media job is unavailable for your account.' );
		if ( ! $remove && self::font_scope($info['scope']) && ( ! Bricks_Design_Snapshot_Service::allowed() || (!empty($info['fontCount']) && !Bricks_Font_Archive_Service::allowed()) ) ) throw new Archive_Error( 'Native font access changed.' );
		if ( ! $remove && ( $info['expiresAt'] < time() || 'removing' === $info['status'] ) ) throw new Archive_Error( 'This media job expired or is being removed.' );
		if ( ( ! $remove || $info['pending'] ) && ( $info['uploads'] ?? null ) !== Media_Archive_Service::uploads() ) throw new Archive_Error( 'The WordPress upload location changed. Existing copies are preserved; review a new media job.' );
	}
	private static function persist( string $dir, array $info ): void {
		$bytes = wp_json_encode( $info, JSON_UNESCAPED_SLASHES ); if ( ! is_string( $bytes ) || strlen( $bytes ) > self::STATE_BYTES ) throw new Archive_Error( 'The media journal exceeds its limit.' ); Archive_Job_Files::write( $dir, 'info.json', $bytes );
	}
	private static function checkpoint( string $dir, array &$info, array $state, string $phrase ): void {
		$name = 'state-' . bin2hex( random_bytes( 16 ) ) . '.kiwe'; $previous = $info['state'];
		$bytes = Archive_Cipher::seal( $state, self::STATE, $phrase, [], self::STATE_BYTES ); Archive_Job_Files::write( $dir, $name, $bytes );
		$info['state'] = $name; $info['stateHash'] = hash( 'sha256', $bytes ); $info['revision']++; $info['updatedAt'] = gmdate( 'c' ); self::persist( $dir, $info );
		if ( $previous ) @unlink( Archive_Job_Files::path( $dir, $previous ) );
	}
	private static function load( string $dir, array $info, string $phrase ): array {
		$bytes = Archive_Job_Files::read( $dir, $info['state'], self::STATE_BYTES ); if ( ! hash_equals( $info['stateHash'], hash( 'sha256', $bytes ) ) ) throw new Archive_Error( 'The saved media progress changed.' );
		return Archive_Cipher::open( $bytes, self::STATE, $phrase, self::STATE_BYTES );
	}
	private static function locked( string $id, callable $action, bool $remove = false ): array {
		$dir = self::directory( $id ); $path = Archive_Job_Files::path( $dir, 'lock' ); $handle = @fopen( $path, 'c+b' ); if ( ! $handle ) throw new Archive_Error( 'The media job could not be locked.' ); @chmod( $path, 0600 );
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) { fclose( $handle ); throw new Archive_Error( 'A step is still running. Check saved progress before retrying.' ); }
		try { $info = self::info( $dir ); self::authority( $info, $remove ); return $action( $dir, $info ); } finally { flock( $handle, LOCK_UN ); fclose( $handle ); }
	}
	private static function projection( array $info ): array {
		$out = array_intersect_key( $info, array_flip( [ 'id','mode','scope','sourceId','label','status','revision','count','total','bytes','rawBytes','processedBytes','fileCount','createdAt','updatedAt','expiresAt','selection','error','review','result','fontCount' ] ) );
		$out['kind'] = 'media'; $out['expiresIn'] = max( 0, $info['expiresAt']-time() ); $out['expired'] = $info['expiresAt'] < time(); return $out;
	}
	public function inventory(): array {
		$root = Configuration_Snapshot_Service::private_directory(); if ( ! $root ) return []; $out = [];
		foreach ( glob( $root . DIRECTORY_SEPARATOR . 'media-job-*' ) ?: [] as $dir ) {
			if ( is_link( $dir ) || ! is_dir( $dir ) || ! preg_match( '/^media-job-[a-f0-9]{32}$/D', basename( $dir ) ) ) continue;
			try { $info = self::info( $dir ); self::authority( $info, true ); $out[] = self::projection( $info ); } catch ( \Throwable $error ) { continue; }
		}
		usort( $out, static fn( $a,$b )=>strcmp( $b['createdAt'], $a['createdAt'] ) ); return $out;
	}
	private static function flat_files( array $records ): array {
		$out = []; foreach ( $records as $index=>$record ) foreach ( $record['files'] as $offset=>$file ) $out[] = [ 'record'=>$index,'file'=>$offset,...$file ]; return $out;
	}
	private static function create( array $plan, string $mode, string $phrase, string $key, ?string $source = null ): array {
		if ( ! Media_Archive_Service::can_use() || ! class_exists( '\ZipArchive' ) || ! preg_match( '/^[a-zA-Z0-9-]{24,64}$/D', $key ) ) throw new Archive_Error( 'Choose an available media backup and a valid request reference.' );
		$root = Configuration_Snapshot_Service::private_directory( true ); $path = $root . DIRECTORY_SEPARATOR . 'archive-jobs.lock'; if ( is_link( $path ) ) throw new Archive_Error( 'Unsafe backup storage.' );
		$handle = @fopen( $path, 'c+b' ); if ( ! $handle ) throw new Archive_Error( 'Private backup storage is unavailable.' ); @chmod( $path, 0600 );
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) { fclose( $handle ); throw new Archive_Error( 'Another backup is starting. Retry shortly.' ); } $dir = '';
		try {
			$plan_hash = hash( 'sha256', (string) wp_json_encode( $plan ) ); $key_hash = hash( 'sha256', $key ); $actor = get_current_user_id();
			foreach ( glob( $root . DIRECTORY_SEPARATOR . 'media-job-*' ) ?: [] as $existing ) {
				if ( is_link( $existing ) || ! is_dir( $existing ) || ! preg_match( '/^media-job-[a-f0-9]{32}$/D', basename( $existing ) ) ) continue;
				try { $info = self::info( $existing ); } catch ( \Throwable $error ) { continue; }
				if ( $info['actor'] === $actor && $info['key'] === $key_hash ) { self::authority( $info ); if ( $info['mode'] !== $mode || ! hash_equals( $info['planHash'], $plan_hash ) ) throw new Archive_Error( 'This request reference belongs to a different media selection.' ); self::load( $existing, $info, $phrase ); return self::projection( $info ); }
			}
			Archive_Job_Files::quota( $actor ); $free = disk_free_space( $root ); if ( false === $free || $free < self::MAX_BYTES * 3 + 16777216 ) throw new Archive_Error( 'There is insufficient server space for a media backup or import.' );
			$id = bin2hex( random_bytes( 16 ) ); $dir = self::directory( $id, true );
			$info = [ 'schema'=>self::STATE,'id'=>$id,'actor'=>$actor,'key'=>$key_hash,'planHash'=>$plan_hash,'mode'=>$mode,'scope'=>$plan['scope'],'sourceId'=>$plan['sourceId'],'sourceIds'=>array_column( $plan['records'], 'id' ),'label'=>$plan['label'],'selection'=>$plan['selection'],'status'=>'export' === $mode ? 'capturing' : 'reviewing','revision'=>0,'state'=>'','stateHash'=>'','count'=>0,'total'=>count( $plan['records'] ),'bytes'=>0,'rawBytes'=>$plan['rawBytes'],'processedBytes'=>0,'fileCount'=>$plan['fileCount'],'createdAt'=>gmdate( 'c' ),'updatedAt'=>gmdate( 'c' ),'expiresAt'=>time()+self::LIFETIME,'pending'=>null,'owned'=>[],'mediaMap'=>[],'error'=>'' ];
			$state = [ 'plan'=>$plan,'fileIndex'=>0,'offset'=>0,'digest'=>null,'chunks'=>[],'copyRecord'=>0,'copyFile'=>0,'copyOffset'=>0 ];
			$info['uploads'] = Media_Archive_Service::uploads();
			if ( self::font_scope($plan['scope']) ) { Bricks_Font_Archive_Service::validate_plan( $plan ); $info['fontCount'] = count( $plan['fonts'] ); $info['fontMap'] = []; $info['fontOwned'] = []; $info['fontPending'] = null; }
			if ( $source ) { $destination = Archive_Job_Files::path( $dir, 'source.kiwe-media' ); if ( ! @copy( $source, $destination ) ) throw new Archive_Error( 'The media upload could not be staged privately.' ); @chmod( $destination, 0600 ); if ( ! hash_equals( $plan['inputHash'], hash_file( 'sha256', $destination ) ) ) throw new Archive_Error( 'The media upload changed while being staged.' ); $info['inputHash'] = $plan['inputHash']; $info['bytes'] = filesize( $destination ); }
			self::checkpoint( $dir, $info, $state, $phrase ); return self::projection( $info );
		} catch ( \Throwable $error ) { if ( $dir ) Archive_Job_Files::retire( $dir ); throw $error; } finally { flock( $handle, LOCK_UN ); fclose( $handle ); }
	}
	public function start_export( int $id, string $phrase, array $selection, string $key ): array { return self::create( Media_Archive_Service::plan( $id, $selection ), 'export', $phrase, $key ); }
	public function start_design_assets(string $phrase,string $key,array $media,array $fonts): array { return self::create(Bricks_Font_Archive_Service::design_plan($media,$fonts),'export',$phrase,$key); }
	public function start_fonts( string $phrase, string $key ): array { return self::create( Bricks_Font_Archive_Service::plan(), 'export', $phrase,$key ); }
	private static function chunk_name( int $index ): string { if ( $index < 0 || $index >= self::MAX_CHUNKS ) throw new Archive_Error( 'The media backup has too many chunks.' ); return sprintf( 'chunk-%05d.kiwe', $index ); }
	private static function zip( string $path ): \ZipArchive { $zip = new \ZipArchive(); if ( true !== $zip->open( $path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS ) ) throw new Archive_Error( 'The media backup is damaged or unsupported.' ); return $zip; }
	private static function manifest( \ZipArchive $zip, string $phrase ): array {
		if ( $zip->numFiles < 2 || $zip->numFiles > self::MAX_CHUNKS+1 ) throw new Archive_Error( 'The media backup has an invalid chunk count.' ); $names = [];
		for ( $i=0; $i<$zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i ); $name = $stat['name'] ?? ''; $limit = 'manifest.kiwe' === $name ? self::INDEX_BYTES : self::FRAME_BYTES; $system = 0; $attributes = 0;
			if ( ! $zip->getExternalAttributesIndex( $i, $system, $attributes ) || ! is_string( $name ) || ( 'manifest.kiwe' !== $name && ! preg_match( '#^chunks/chunk-[0-9]{5}\.kiwe$#D', $name ) ) || isset( $names[$name] ) || ( $stat['comp_method'] ?? -1 ) !== \ZipArchive::CM_STORE || ( $stat['encryption_method'] ?? 0 ) !== 0 || ( $stat['size'] ?? 0 ) < 1 || $stat['size'] > $limit || $stat['size'] !== $stat['comp_size'] || ( ( $attributes >> 16 ) & 0170000 ) === 0120000 ) throw new Archive_Error( 'The media backup contains an unsafe or oversized entry.' );
			$names[$name] = $stat['size'];
		}
		if ( ! isset( $names['manifest.kiwe'] ) ) throw new Archive_Error( 'The media backup index is missing.' );
		$bytes = $zip->getFromName( 'manifest.kiwe' ); if ( ! is_string( $bytes ) ) throw new Archive_Error( 'The media backup index could not be read.' ); $header = json_decode( $bytes,true,32 ); $schema = in_array($header['schema']??'',[self::FONT_SCHEMA,self::DESIGN_SCHEMA],true) ? $header['schema'] : self::SCHEMA;
		$plan = Archive_Cipher::open( $bytes, $schema, $phrase, self::INDEX_BYTES );
		if ( ( ['bricks_fonts'=>self::FONT_SCHEMA,'bricks_design_assets'=>self::DESIGN_SCHEMA,'media'=>self::SCHEMA,'attachment'=>self::SCHEMA][$plan['scope']??'']??'' ) !== $schema ) throw new Archive_Error( 'Font and media archive scopes disagree.' );
		$allowed = [ 'schema','siteHash','scope','sourceId','label','selection','records','rawBytes','fileCount','uploadsUrl','chunks','fonts','designMedia' ];
		if ( array_diff( array_keys( $plan ), $allowed ) || ! in_array( $plan['scope'] ?? '', [ 'media','attachment','bricks_fonts','bricks_design_assets' ], true ) || ! is_int( $plan['sourceId'] ?? null ) || $plan['sourceId'] < 0 || ! is_string( $plan['label'] ?? null ) || strlen( $plan['label'] ) > 800 || ! is_array( $plan['records'] ?? null ) || ! $plan['records'] || count( $plan['records'] ) > Media_Archive_Service::MAX_ATTACHMENTS || ! is_array( $plan['chunks'] ?? null ) || count( $plan['chunks'] ) !== count( $names )-1 || ! is_string( $plan['uploadsUrl'] ?? null ) || ! wp_http_validate_url( $plan['uploadsUrl'] ) || ! is_array( $plan['selection'] ?? null ) || ! is_int( $plan['rawBytes'] ?? null ) || ! is_int( $plan['fileCount'] ?? null ) ) throw new Archive_Error( 'The media backup manifest is invalid.' );
		if ( self::font_scope($plan['scope']) ) Bricks_Font_Archive_Service::validate_plan( $plan ); elseif ( isset( $plan['fonts'] ) || isset($plan['designMedia']) ) throw new Archive_Error( 'Ordinary media archives cannot create font records.' );
		$selection = $plan['selection']; if ( array_keys( $selection ) !== [ 'from','to','timezone' ] || ! is_string( $selection['timezone'] ) || strlen( $selection['timezone'] ) > 100 ) throw new Archive_Error( 'The media selection is invalid.' ); $dates = Content_Archive_Service::selection( [ 'from'=>$selection['from'],'to'=>$selection['to'] ] ); if ( $dates['from'] !== $selection['from'] || $dates['to'] !== $selection['to'] ) throw new Archive_Error( 'The media selection is invalid.' );
		$ids = []; $raw = 0; $file_count = 0; foreach ( $plan['records'] as $record ) { if ( ! is_array( $record ) ) throw new Archive_Error( 'A media record is invalid.' ); Media_Archive_Service::validate_record( $record ); if ( isset( $ids[$record['id']] ) ) throw new Archive_Error( 'The media backup repeats an attachment.' ); $ids[$record['id']] = true; foreach ( $record['files'] as $file ) $raw += $file['bytes']; $file_count += count( $record['files'] ); }
		if ( $raw !== $plan['rawBytes'] || $raw > Media_Archive_Service::MAX_RAW_BYTES || $file_count !== $plan['fileCount'] || $file_count > Media_Archive_Service::MAX_FILES || ( 'attachment' === $plan['scope'] ? count( $ids ) !== 1 || ! isset( $ids[$plan['sourceId']] ) : $plan['sourceId'] !== 0 ) ) throw new Archive_Error( 'The media backup selection is incomplete.' );
		$files = self::flat_files( $plan['records'] ); $expected = []; foreach ( $files as $index=>$file ) for ( $offset=0; $offset<$file['bytes']; $offset+=self::CHUNK_BYTES ) $expected[] = [ $index,$offset,min( self::CHUNK_BYTES, $file['bytes']-$offset ) ];
		if ( count( $expected ) !== count( $plan['chunks'] ) ) throw new Archive_Error( 'The media backup has missing file chunks.' );
		foreach ( $plan['chunks'] as $index=>$chunk ) {
			$name = self::chunk_name( $index ); if ( ! is_array( $chunk ) || array_keys( $chunk ) !== [ 'name','file','offset','length','bytes','hash' ] || $chunk['name'] !== $name || [ $chunk['file'],$chunk['offset'],$chunk['length'] ] !== $expected[$index] || ( $names['chunks/'.$name] ?? 0 ) !== $chunk['bytes'] || ! is_string( $chunk['hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $chunk['hash'] ) ) throw new Archive_Error( 'The media chunk manifest is invalid.' );
		}
		return $plan;
	}
	public static function inspect_file(string $path,string $phrase): array { $zip=self::zip($path);try{return self::manifest($zip,$phrase);}finally{$zip->close();} }
	public function start_import( string $path, string $phrase, string $key ): array {
		if ( ! Media_Archive_Service::can_use() || ! class_exists( '\ZipArchive' ) || is_link( $path ) || ! is_file( $path ) || filesize( $path ) < 1 || filesize( $path ) > self::MAX_BYTES ) throw new Archive_Error( 'Choose an available Kiwe media file up to 128 MB.' );
		$zip = self::zip( $path ); try { $plan = self::manifest( $zip, $phrase ); } finally { $zip->close(); } $plan['inputHash'] = hash_file( 'sha256', $path ); return self::create( $plan, 'import', $phrase, $key, $path );
	}
	/** Native incremental SHA-256 context only; no PHP object deserialization or secret persistence. */
	private static function digest( ?array $saved, string $bytes, bool $finish ): array {
		$context = hash_init( 'sha256' ); $shape = $context->__serialize();
		if ( $saved ) {
			if ( ( $saved['runtime'] ?? null ) !== PHP_VERSION_ID || ! is_array( $saved['data'] ?? null ) ) throw new Archive_Error( 'The server hash runtime changed. Start a fresh media job.' );
			$data = $saved['data'];
			if ( array_keys( $data ) !== array_keys( $shape ) || ( $data[0] ?? null ) !== 'sha256' || ( $data[1] ?? null ) !== 0 || ( $data[3] ?? null ) !== $shape[3] || ( $data[4] ?? null ) !== [] || ! is_array( $data[2] ?? null ) || array_keys( $data[2] ) !== array_keys( $shape[2] ) ) throw new Archive_Error( 'The media checksum checkpoint is invalid.' );
			foreach ( $shape[2] as $key=>$native ) {
				if ( is_string( $native ) ) { $value = is_string( $data[2][$key] ) ? base64_decode( $data[2][$key], true ) : false; if ( ! is_string( $value ) || strlen( $value ) !== strlen( $native ) ) throw new Archive_Error( 'The media checksum buffer is invalid.' ); $data[2][$key] = $value; }
				elseif ( ! is_int( $data[2][$key] ) ) throw new Archive_Error( 'The media checksum state is invalid.' );
			}
			// Finalization releases the fresh native context before restoring its checked SHA-256 state.
			hash_final( $context ); $context->__unserialize( $data );
		}
		hash_update( $context, $bytes ); if ( $finish ) return [ 'hash'=>hash_final( $context ) ];
		$data = $context->__serialize(); foreach ( $data[2] as &$value ) if ( is_string( $value ) ) $value = base64_encode( $value ); unset( $value ); return [ 'runtime'=>PHP_VERSION_ID,'data'=>$data ];
	}
	private function capture_step( string $dir, array &$info, array &$state, string $phrase ): void {
		$plan = $state['plan']; $files = self::flat_files( $plan['records'] );
		if ( self::font_scope($info['scope']) ) ($plan['fonts'] ? Bricks_Font_Archive_Service::assert_sources( $plan['fonts'] ) : null);
		if ( $state['fileIndex'] >= count( $files ) ) {
			foreach ( $plan['records'] as $record ) Media_Archive_Service::assert_source( $record, true );
			$manifest = [ ...$plan,'chunks'=>$state['chunks'] ]; $bytes = Archive_Cipher::seal( $manifest, ('bricks_design_assets' === $info['scope'] ? self::DESIGN_SCHEMA : (self::font_scope($info['scope']) ? self::FONT_SCHEMA : self::SCHEMA)), $phrase, [], self::INDEX_BYTES ); Archive_Job_Files::write( $dir, 'manifest.kiwe', $bytes );
			$path = Archive_Job_Files::path( $dir, 'archive.kiwe-media' ); $zip = new \ZipArchive(); if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) throw new Archive_Error( 'The media download could not be assembled.' );
			try { if ( ! $zip->addFile( Archive_Job_Files::path( $dir, 'manifest.kiwe' ), 'manifest.kiwe' ) || ! $zip->setCompressionName( 'manifest.kiwe', \ZipArchive::CM_STORE ) ) throw new Archive_Error( 'The media index could not be saved.' ); foreach ( $state['chunks'] as $chunk ) { $name = 'chunks/'.$chunk['name']; $part = Archive_Job_Files::path( $dir, $chunk['name'] ); if ( ! hash_equals( $chunk['hash'], hash_file( 'sha256', $part ) ) || ! $zip->addFile( $part, $name ) || ! $zip->setCompressionName( $name, \ZipArchive::CM_STORE ) ) throw new Archive_Error( 'A captured media chunk changed.' ); } } finally { if ( ! $zip->close() ) throw new Archive_Error( 'The media download could not be closed.' ); }
			@chmod( $path, 0600 ); if ( filesize( $path ) > self::MAX_BYTES ) throw new Archive_Error( 'The encrypted media set exceeds 128 MB. Choose a smaller selection.' );
			$info['downloadHash'] = hash_file( 'sha256', $path ); $info['bytes'] = filesize( $path ); $info['status'] = 'ready'; return;
		}
		$file = $files[$state['fileIndex']]; Media_Archive_Service::assert_source( $plan['records'][$file['record']] ); $path = Media_Archive_Service::source_path( $file['relative'] );
		$stream = fopen( $path, 'rb' ); if ( ! $stream ) throw new Archive_Error( 'A media file could not be read.' );
		try { if ( fseek( $stream, $state['offset'] ) !== 0 ) throw new Archive_Error( 'The media file offset is invalid.' ); $length = min( self::CHUNK_BYTES, $file['bytes']-$state['offset'] ); $raw = fread( $stream, $length ); if ( ! is_string( $raw ) || strlen( $raw ) !== $length ) throw new Archive_Error( 'The media file changed while being captured.' ); } finally { fclose( $stream ); }
		$last = $state['offset']+$length === $file['bytes']; $digest = self::digest( $state['digest'], $raw, $last ); if ( $last && ! hash_equals( $file['hash'], $digest['hash'] ) ) throw new Archive_Error( 'Captured media bytes differ from the frozen original.' );
		$name = self::chunk_name( count( $state['chunks'] ) ); $bytes = Archive_Cipher::seal( [ 'file'=>$state['fileIndex'],'offset'=>$state['offset'],'length'=>$length,'data'=>base64_encode( $raw ) ], self::FRAME, $phrase, [], self::FRAME_BYTES ); Archive_Job_Files::write( $dir, $name, $bytes );
		$state['chunks'][] = [ 'name'=>$name,'file'=>$state['fileIndex'],'offset'=>$state['offset'],'length'=>$length,'bytes'=>strlen( $bytes ),'hash'=>hash( 'sha256', $bytes ) ]; $info['bytes'] += strlen( $bytes ); $info['processedBytes'] += $length; $state['offset'] += $length;
		if ( $last ) { $state['fileIndex']++; $state['offset'] = 0; $state['digest'] = null; if ( $file['file']+1 === count( $plan['records'][$file['record']]['files'] ) ) $info['count']++; } else $state['digest'] = $digest;
	}
	private static function append_blob( string $dir, int $file, int $offset, string $bytes ): void {
		$path = Archive_Job_Files::path( $dir, sprintf( 'blob-%04d.bin', $file ) ); $stream = @fopen( $path, 0 === $offset && ! file_exists( $path ) ? 'x+b' : 'c+b' ); if ( ! $stream ) throw new Archive_Error( 'A private media file could not be assembled.' ); @chmod( $path, 0600 );
		try { $stat = fstat( $stream ); if ( $stat['size'] < $offset || ! ftruncate( $stream, $offset ) || fseek( $stream, $offset ) !== 0 ) throw new Archive_Error( 'The private media checkpoint is incomplete.' ); for ( $written=0; $written<strlen( $bytes ); $written+=$length ) { $length = fwrite( $stream, substr( $bytes, $written, 65536 ) ); if ( ! $length ) throw new Archive_Error( 'Private media storage is full.' ); } if ( ! fflush( $stream ) || ( function_exists( 'fsync' ) && ! fsync( $stream ) ) ) throw new Archive_Error( 'The private media checkpoint could not be synchronized.' ); } finally { fclose( $stream ); }
	}
	private function review_step( string $dir, array &$info, array &$state, string $phrase ): void {
		$plan = $state['plan']; $files = self::flat_files( $plan['records'] ); $index = count( $state['chunks'] );
		if ( $index === count( $plan['chunks'] ) ) {
			$info['status'] = 'review'; $info['review'] = [ 'count'=>count( $plan['records'] ),'items'=>array_map( static fn( $record )=>[ 'title'=>$record['title'],'type'=>$record['mime'],'author'=>'Your account' ], array_slice( $plan['records'], 0, 40 ) ),'warnings'=>[ 'New media copies are created; existing attachments and content are kept.','Content imports can explicitly use this completed media recovery mapping.' ] ];
			if ( self::font_scope($info['scope']) ) { $info['review']['fonts'] = array_map( static fn($font)=>$font['title'],$plan['fonts'] ); $info['review']['warnings'][] = 'Native font copies are created. Existing fonts stay unchanged. Choose this completed recovery explicitly when reviewing Bricks design settings.'; } return;
		}
		$chunk = $plan['chunks'][$index]; $zip = self::zip( Archive_Job_Files::path( $dir, 'source.kiwe-media' ) ); try { $bytes = $zip->getFromName( 'chunks/'.$chunk['name'] ); } finally { $zip->close(); }
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $chunk['bytes'] || ! hash_equals( $chunk['hash'], hash( 'sha256', $bytes ) ) ) throw new Archive_Error( 'A media chunk changed or is missing.' );
		$frame = Archive_Cipher::open( $bytes, self::FRAME, $phrase, self::FRAME_BYTES ); if ( array_diff( array_keys( $frame ), [ 'schema','siteHash','file','offset','length','data' ] ) || ( $frame['file'] ?? null ) !== $chunk['file'] || ( $frame['offset'] ?? null ) !== $chunk['offset'] || ( $frame['length'] ?? null ) !== $chunk['length'] || ! is_string( $frame['data'] ?? null ) ) throw new Archive_Error( 'The media chunk coordinates are invalid.' );
		$raw = base64_decode( $frame['data'], true ); if ( ! is_string( $raw ) || strlen( $raw ) !== $chunk['length'] ) throw new Archive_Error( 'The media chunk bytes are damaged.' );
		$file = $files[$chunk['file']]; $last = $chunk['offset']+$chunk['length'] === $file['bytes']; $digest = self::digest( $state['digest'], $raw, $last ); if ( $last && ! hash_equals( $file['hash'], $digest['hash'] ) ) throw new Archive_Error( 'A restored media file failed its complete checksum.' );
		self::append_blob( $dir, $chunk['file'], $chunk['offset'], $raw );
		if ( $last ) { $path = Archive_Job_Files::path( $dir, sprintf( 'blob-%04d.bin', $chunk['file'] ) ); if ( Media_Archive_Service::file_type( $path, $file['name'] ) !== $file['mime'] ) throw new Archive_Error( 'The media file type differs from its manifest.' ); $state['digest'] = null; if ( $file['file']+1 === count( $plan['records'][$file['record']]['files'] ) ) $info['count']++; } else $state['digest'] = $digest;
		$state['chunks'][] = $chunk; $info['processedBytes'] += strlen( $raw );
	}
	private static function owned_path( array $pending, string $name ): string {
		Media_Archive_Service::filename( $name ); $root = Media_Archive_Service::uploads()['root']; $relative = Media_Archive_Service::relative( $pending['relative'] ); $path = $root; foreach ( explode( '/', $relative ) as $component ) { $path .= '/'.$component; if ( is_link( $path ) ) throw new Archive_Error( 'Linked recovery folders are unavailable.' ); }
		if ( ! is_dir( $path ) || wp_normalize_path( realpath( $path ) ) !== $root.'/'.$relative ) throw new Archive_Error( 'The owned media folder changed.' ); $file = $path.'/'.$name; if ( is_link( $file ) || ( file_exists( $file ) && ! is_file( $file ) ) ) throw new Archive_Error( 'An owned media file changed.' ); return $file;
	}
	private static function verify_owned( array $info, bool $files = false ): void {
		if ( self::font_scope($info['scope']) ) Bricks_Font_Archive_Service::verify_owned( $info );
		foreach ( $info['owned'] as $owned ) {
			$id = $owned['id']; if ( ! Media_Archive_Service::can_export( $id ) || get_post_meta( $id, Media_Archive_Service::MARKER, true ) !== $info['id'] || ! hash_equals( $owned['fingerprint'], Media_Archive_Service::fingerprint( $id ) ) ) throw new Archive_Error( 'An imported media copy changed. It is preserved; review the changed copy before continuing.' );
			foreach ( $owned['files'] as $file ) { $path = Media_Archive_Service::source_path( $file['relative'] ); clearstatcache( true, $path ); if ( filesize( $path ) !== $file['bytes'] || filemtime( $path ) !== $file['modified'] || ( $files && ! hash_equals( $file['hash'], hash_file( 'sha256', $path ) ) ) ) throw new Archive_Error( 'An imported media file changed. It is preserved.' ); }
		}
	}
	/** A retry or cleanup may touch only bytes still matching the reviewed private file. */
	private static function partial_copy( string $destination, string $blob, int $limit ): void {
		if ( ! file_exists( $destination ) ) return;
		if ( is_link( $destination ) || ! is_file( $destination ) || ! is_file( $blob ) || is_link( $blob ) || filesize( $destination ) > $limit ) throw new Archive_Error( 'An unfinished media file changed. It is preserved.' );
		$left = fopen( $destination, 'rb' ); $right = fopen( $blob, 'rb' ); if ( ! $left || ! $right ) { if ( $left ) fclose( $left ); if ( $right ) fclose( $right ); throw new Archive_Error( 'The unfinished media file could not be checked.' ); }
		try { while ( ! feof( $left ) ) { $bytes = fread( $left, 65536 ); if ( ! is_string( $bytes ) || ( '' !== $bytes && $bytes !== fread( $right, strlen( $bytes ) ) ) ) throw new Archive_Error( 'An unfinished media file changed. It is preserved.' ); } } finally { fclose( $left ); fclose( $right ); }
	}
	private static function pending_preflight( string $dir, array $pending ): ?string {
		$root = Media_Archive_Service::uploads()['root']; $folder = $root; foreach ( explode( '/', Media_Archive_Service::relative( $pending['relative'] ) ) as $part ) { $folder .= '/'.$part; if ( is_link( $folder ) ) throw new Archive_Error( 'The unfinished media folder changed. It is preserved.' ); }
		if ( ! file_exists( $folder ) ) return null;
		if ( ! is_dir( $folder ) || wp_normalize_path( realpath( $folder ) ) !== $root.'/'.$pending['relative'] ) throw new Archive_Error( 'The unfinished media folder changed. It is preserved.' );
		$names = array_values( array_diff( scandir( $folder ) ?: [], [ '.','..' ] ) ); if ( array_diff( $names, $pending['names'] ) ) throw new Archive_Error( 'The unfinished media folder contains unexpected files. It is preserved.' );
		foreach ( $pending['files'] as $file ) self::partial_copy( self::owned_path( $pending, $file['name'] ), Archive_Job_Files::path( $dir, $file['blob'] ), $file['bytes'] );
		global $wpdb; $like = '%'.$wpdb->esc_like( basename( $pending['relative'] ) ).'%';
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID <> %d AND post_content LIKE %s LIMIT 1", $pending['createdId'], $like ) ) || $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN ('_bricks_page_content_2','_bricks_page_header_2','_bricks_page_footer_2','_bricks_page_settings') AND meta_value LIKE %s LIMIT 1", $pending['createdId'], $like ) ) ) throw new Archive_Error( 'The unfinished media files are referenced by content. They are preserved.' );
		return $folder;
	}
	private static function recover_pending( string $dir, array &$info ): bool {
		$pending = $info['pending']; if ( ! $pending || empty( $pending['committing'] ) ) return false; $folder = self::pending_preflight( $dir, $pending );
		if ( empty( $pending['createdId'] ) ) {
			global $wpdb; $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_author = %d AND guid = %s LIMIT 2", $info['actor'], $pending['guid'] ) );
			if ( count( $ids ) > 1 ) throw new Archive_Error( 'The interrupted attachment identity is ambiguous. Copies are preserved.' );
			if ( $ids ) { $pending['createdId'] = (int) $ids[0]; $pending['guard'] = Media_Archive_Service::fingerprint( $pending['createdId'] ); }
		}
		if ( ! empty( $pending['createdId'] ) ) {
			$id = $pending['createdId']; $post = get_post( $id );
			$marker = get_post_meta( $id, Media_Archive_Service::MARKER, true );
			if ( $post ) foreach ( $pending['native'] as $key=>$value ) if ( ( is_int( $value ) ? (int) $post->$key : $post->$key ) !== $value ) throw new Archive_Error( 'An interrupted attachment changed from the reviewed copy. It is preserved.' );
			if ( $post && ( ! Media_Archive_Service::can_export( $id ) || ( $marker && $marker !== $info['id'] ) || $post->guid !== $pending['guid'] || (int) $post->post_parent !== 0 || ! hash_equals( $pending['guard'], Media_Archive_Service::fingerprint( $id ) ) ) ) throw new Archive_Error( 'An interrupted media copy changed. It is preserved for review.' );
			if ( $post ) {
				global $wpdb; $folder_name = basename( $pending['relative'] ); $like = '%'.$wpdb->esc_like( $folder_name ).'%';
				$thumbnail = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1", (string) $id ) );
				$body = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID <> %d AND post_content LIKE %s LIMIT 1", $id, $like ) );
				$builder = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN ('_bricks_page_content_2','_bricks_page_header_2','_bricks_page_footer_2','_bricks_page_settings') AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s) LIMIT 1", $id, $like, '%'.$wpdb->esc_like( 's:2:"id";i:'.$id.';' ).'%', '%'.$wpdb->esc_like( 's:2:"id";s:'.strlen( (string) $id ).':"'.$id.'";' ).'%' ) );
				$identity = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID <> %d AND post_content REGEXP %s LIMIT 1", $id, '(^|[^0-9])'.$id.'([^0-9]|$)' ) ); $gallery = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value REGEXP %s LIMIT 1", '(^|,)'.$id.'(,|$)' ) );
				if ( $thumbnail || $body || $builder || $identity || $gallery ) throw new Archive_Error( 'The interrupted media is referenced by content. It is preserved for review.' );
				$attached = get_post_meta( $id, '_wp_attached_file', true ); $metadata = wp_get_attachment_metadata( $id, true ) ?: []; $backups = get_post_meta( $id, '_wp_attachment_backup_sizes', true ) ?: [];
				if ( ( $attached && $attached !== $pending['relative'].'/'.$pending['names'][0] ) || ( isset( $metadata['file'] ) && $metadata['file'] !== $attached ) || ! is_array( $metadata ) || ! is_array( $backups ) ) throw new Archive_Error( 'An interrupted attachment points outside its owned recovery files. It is preserved.' );
				$names = []; if ( isset( $metadata['original_image'] ) ) $names[] = $metadata['original_image']; foreach ( [ $metadata['sizes'] ?? [],$backups ] as $sizes ) { if ( ! is_array( $sizes ) ) throw new Archive_Error( 'An interrupted derivative list changed.' ); foreach ( $sizes as $size ) { if ( ! is_array( $size ) || ! is_string( $size['file'] ?? null ) ) throw new Archive_Error( 'An interrupted derivative changed.' ); $names[] = $size['file']; } }
				foreach ( $names as $name ) if ( ! in_array( Media_Archive_Service::filename( $name ), $pending['names'], true ) ) throw new Archive_Error( 'An interrupted derivative points outside owned media. It is preserved.' );
				if ( ! wp_delete_attachment( $id, true ) || get_post( $id ) ) throw new Archive_Error( 'An interrupted media copy could not be recovered safely.' );
			}
		}
		if ( $folder ) foreach ( $pending['names'] as $name ) { $path = self::owned_path( $pending, $name ); if ( is_file( $path ) && ! @unlink( $path ) ) throw new Archive_Error( 'An owned media file could not be recovered.' ); }
		if ( $folder && ! @rmdir( $folder ) ) throw new Archive_Error( 'The owned recovery folder contains an unexpected file. It is preserved.' );
		$info['pending'] = null; self::persist( $dir, $info ); return true;
	}
	private function import_step( string $dir, array &$info, array &$state ): void {
		self::verify_owned( $info ); $plan = $state['plan'];
		if ( $state['copyRecord'] === count( $plan['records'] ) ) {
			self::verify_owned( $info,true );
			if ( self::font_scope($info['scope']) && count( $info['fontOwned'] ) < count( $plan['fonts'] ) ) {
				$font = $plan['fonts'][count( $info['fontOwned'] )];
				if ( ! $info['fontPending'] ) { $info['fontPending'] = [ 'source'=>$font['id'],'id'=>0 ]; self::persist( $dir,$info ); }
				$id = Bricks_Font_Archive_Service::copy( $font,$info['mediaMap'],$info['id'],static function( int $id ) use ($dir,&$info): void { $info['fontPending']['id'] = $id; self::persist( $dir,$info ); },(int)($info['fontPending']['id'] ?? 0) );
				$info['fontOwned'][] = [ 'source'=>$font['id'],'id'=>$id,'fingerprint'=>Bricks_Font_Archive_Service::fingerprint($id) ];
				$info['fontMap'][$font['id']] = $id; $info['fontPending'] = null; return;
			}
			self::verify_owned( $info, true ); $info['status'] = 'complete'; $info['result'] = [ 'count'=>count( $info['owned'] ),'mediaRecovery'=>$info['id'],...(self::font_scope($info['scope']) ? ['fonts'=>count($info['fontOwned'])] : []) ]; return; }
		$record = $plan['records'][$state['copyRecord']];
		if ( ! $info['pending'] ) {
			$uploads = wp_upload_dir(); if ( ! empty( $uploads['error'] ) ) throw new Archive_Error( 'WordPress uploads are unavailable.' ); $relative = ltrim( (string) $uploads['subdir'], '/' ) . ( $uploads['subdir'] ? '/' : '' ) . 'kiwe-restore-'.$info['id'].'-'.$state['copyRecord']; Media_Archive_Service::relative( $relative );
			$root = Media_Archive_Service::uploads()['root']; $folder = $root.'/'.$relative; $parent = dirname( $folder ); if ( ! is_dir( $parent ) || is_link( $parent ) || ( wp_normalize_path( realpath( $parent ) ) !== $root && ! str_starts_with( wp_normalize_path( realpath( $parent ) ), $root.'/' ) ) || file_exists( $folder ) ) throw new Archive_Error( 'A unique WordPress media recovery folder could not be created.' );
			
			$private_files = []; foreach ( self::flat_files( $plan['records'] ) as $flat_index=>$flat_file ) if ( $flat_file['record'] === $state['copyRecord'] ) $private_files[] = [ 'name'=>$flat_file['name'],'bytes'=>$flat_file['bytes'],'blob'=>sprintf( 'blob-%04d.bin', $flat_index ) ];
			$info['pending'] = [ 'relative'=>$relative,'native'=>[ 'post_title'=>$record['title'],'post_content'=>wp_kses_post( $record['description'] ),'post_excerpt'=>wp_kses_post( $record['caption'] ),'post_mime_type'=>$record['mime'],'post_status'=>'inherit','post_author'=>$info['actor'],'post_parent'=>0 ],'files'=>$private_files,'names'=>array_column( $record['files'], 'name' ),'guid'=>Media_Archive_Service::uploads()['url'].'/'.$relative.'/'.$record['files'][0]['name'],'createdId'=>0,'guard'=>'','committing'=>false ]; self::persist( $dir, $info );
		}
		$pending = $info['pending']; $folder = Media_Archive_Service::uploads()['root'].'/'.$pending['relative'];
		if ( ! is_dir( $folder ) && ( file_exists( $folder ) || is_link( $folder ) || ! @mkdir( $folder, 0755 ) ) ) throw new Archive_Error( 'The owned recovery folder could not be created.' );
		if ( $state['copyFile'] < count( $record['files'] ) ) {
			$file = $record['files'][$state['copyFile']]; $flat = self::flat_files( $plan['records'] ); $index = 0; while ( $flat[$index]['record'] !== $state['copyRecord'] || $flat[$index]['file'] !== $state['copyFile'] ) $index++;
			$blob = Archive_Job_Files::path( $dir, sprintf( 'blob-%04d.bin', $index ) ); if ( ! is_file( $blob ) || filesize( $blob ) !== $file['bytes'] ) throw new Archive_Error( 'A reviewed private media file is missing.' );
			$source = fopen( $blob, 'rb' ); if ( ! $source ) throw new Archive_Error( 'The private media copy could not be read.' ); try { if ( fseek( $source, $state['copyOffset'] ) !== 0 ) throw new Archive_Error( 'The media copy offset is invalid.' ); $length = min( self::CHUNK_BYTES, $file['bytes']-$state['copyOffset'] ); $bytes = fread( $source, $length ); if ( ! is_string( $bytes ) || strlen( $bytes ) !== $length ) throw new Archive_Error( 'The private media copy is incomplete.' ); } finally { fclose( $source ); }
			$destination = self::owned_path( $pending, $file['name'] ); self::partial_copy( $destination, $blob, $file['bytes'] ); $stream = @fopen( $destination, 0 === $state['copyOffset'] && ! file_exists( $destination ) ? 'x+b' : 'c+b' ); if ( ! $stream ) throw new Archive_Error( 'The media copy could not be written.' ); @chmod( $destination, 0644 );
			try { $stat = fstat( $stream ); if ( $stat['size'] < $state['copyOffset'] || ! ftruncate( $stream, $state['copyOffset'] ) || fseek( $stream, $state['copyOffset'] ) !== 0 ) throw new Archive_Error( 'An owned media copy checkpoint changed.' ); for ( $offset=0; $offset<strlen( $bytes ); $offset+=$written ) { $written = fwrite( $stream, substr( $bytes, $offset, 65536 ) ); if ( ! $written ) throw new Archive_Error( 'WordPress media storage is full.' ); } if ( ! fflush( $stream ) || ( function_exists( 'fsync' ) && ! fsync( $stream ) ) ) throw new Archive_Error( 'The media copy could not be synchronized.' ); } finally { fclose( $stream ); }
			$info['processedBytes'] += strlen( $bytes ); $state['copyOffset'] += strlen( $bytes );
			if ( $state['copyOffset'] === $file['bytes'] ) { if ( ! hash_equals( $file['hash'], hash_file( 'sha256', $destination ) ) || Media_Archive_Service::file_type( $destination, $file['name'] ) !== $file['mime'] ) throw new Archive_Error( 'The media copy failed its final file proof.' ); $state['copyFile']++; $state['copyOffset'] = 0; } return;
		}
		$info['pending']['committing'] = true; self::persist( $dir, $info );
		$track = static function( int $id ) use ( $dir,&$info ): void { if ( get_post_meta( $id, Media_Archive_Service::MARKER, true ) !== $info['id'] ) return; $info['pending']['createdId'] = $id; $info['pending']['guard'] = Media_Archive_Service::fingerprint( $id ); self::persist( $dir, $info ); };
		add_action( 'add_attachment', $track, PHP_INT_MIN ); add_action( 'updated_post_meta', $meta_track = static function( $meta_id,$id,$key ) use ( $track,&$info ): void { if ( $id === ( $info['pending']['createdId'] ?? 0 ) ) $track( (int) $id ); }, PHP_INT_MIN, 3 ); add_action( 'added_post_meta', $meta_track, PHP_INT_MIN, 3 );
		$guard_guid = static function( $data,$args,$raw,$update ) use ( $pending ) { if ( ! $update && ( $raw['guid'] ?? '' ) === $pending['guid'] && ( $data['guid'] ?? '' ) !== $pending['guid'] ) throw new Archive_Error( 'A native save filter changed the attachment journal identity.' ); return $data; }; add_filter( 'wp_insert_post_data', $guard_guid, PHP_INT_MAX, 4 );
		try {
			$relative = $pending['relative'].'/'.$record['files'][0]['name']; $id = Media_Archive_Service::save_attachment( $record, self::owned_path( $pending, $record['files'][0]['name'] ), $relative, $info['id'] );
			$files = []; $urls = []; foreach ( $record['files'] as $file ) { $path = self::owned_path( $pending, $file['name'] ); clearstatcache( true, $path ); $target = $pending['relative'].'/'.$file['name']; $files[] = [ 'relative'=>$target,'bytes'=>$file['bytes'],'modified'=>filemtime( $path ),'hash'=>$file['hash'] ]; $urls[$plan['uploadsUrl'].'/'.$file['relative']] = Media_Archive_Service::uploads()['url'].'/'.$target; }
			$info['owned'][] = [ 'id'=>$id,'fingerprint'=>Media_Archive_Service::fingerprint( $id ),'files'=>$files ]; $info['mediaMap'][$record['id']] = [ 'id'=>$id,'urls'=>$urls ]; $info['pending'] = null; $info['count']++; $state['copyRecord']++; $state['copyFile'] = 0; $state['copyOffset'] = 0;
		} finally { remove_filter( 'wp_insert_post_data', $guard_guid, PHP_INT_MAX ); remove_action( 'add_attachment', $track, PHP_INT_MIN ); remove_action( 'updated_post_meta', $meta_track, PHP_INT_MIN ); remove_action( 'added_post_meta', $meta_track, PHP_INT_MIN ); }
	}
	public function step( string $id, string $phrase, int $revision, bool $confirm = false ): array {
		return self::locked( $id, function( $dir,$info ) use ( $phrase,$revision,$confirm ) {
			$state = self::load( $dir, $info, $phrase ); if ( $info['revision'] !== $revision ) return self::projection( $info ); if ( in_array( $info['status'], [ 'ready','complete' ], true ) ) return self::projection( $info );
			if ( self::recover_pending( $dir, $info ) ) { $info['processedBytes'] -= array_sum( array_column( $state['plan']['records'][$state['copyRecord']]['files'], 'bytes' ) ); $state['copyFile'] = 0; $state['copyOffset'] = 0; }
			if ( 'review' === $info['status'] ) { if ( ! $confirm ) return self::projection( $info ); $info['status'] = 'importing'; $info['count'] = 0; $info['processedBytes'] = 0; unset( $info['review'] ); }
			try { if ( 'capturing' === $info['status'] ) $this->capture_step( $dir, $info, $state, $phrase ); elseif ( 'reviewing' === $info['status'] ) $this->review_step( $dir, $info, $state, $phrase ); elseif ( 'importing' === $info['status'] ) $this->import_step( $dir, $info, $state ); else throw new Archive_Error( 'This media job cannot continue.' ); $info['error'] = ''; self::checkpoint( $dir, $info, $state, $phrase ); return self::projection( $info ); }
			catch ( \Throwable $error ) { $latest = self::info( $dir ); $latest['error'] = 'This step did not finish. Check saved progress before retrying; existing media is preserved.'; self::persist( $dir, $latest ); throw new Archive_Error( $latest['error'], 0, $error ); }
		} );
	}
	public function download_path( string $id ): string {
		return self::locked( $id, static function( $dir,$info ) { if ( 'export' !== $info['mode'] || 'ready' !== $info['status'] ) throw new Archive_Error( 'The media backup is not ready.' ); foreach ( $info['sourceIds'] as $source ) if ( ! Media_Archive_Service::can_export( $source ) ) throw new Archive_Error( 'Your permission to an archived attachment changed.' ); $path = Archive_Job_Files::path( $dir, 'archive.kiwe-media' ); if ( ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES || ! hash_equals( $info['downloadHash'], hash_file( 'sha256', $path ) ) ) throw new Archive_Error( 'The media download changed.' ); return [ 'path'=>$path ]; } )['path'];
	}
	/** Explicit actor-owned map for reviewed content imports; never a global URL replacement. */
	public function recovery_map( string $id, bool $files = false ): array {
		return self::locked( $id, static function( $dir,$info ) use ( $files ) { if ( 'import' !== $info['mode'] || 'complete' !== $info['status'] ) throw new Archive_Error( 'Complete the selected media import first.' ); self::verify_owned( $info, $files ); return [ 'id'=>$info['id'],'scope'=>$info['scope'],'map'=>$info['mediaMap'],'fontMap'=>$info['fontMap'] ?? [],'proof'=>hash( 'sha256', (string) wp_json_encode( [ $info['mediaMap'],$info['owned'],$info['fontMap'] ?? [],$info['fontOwned'] ?? [] ] ) ) ]; } );
	}
	public function remove( string $id ): array {
		return self::locked( $id, static function( $dir,$info ) {
			self::recover_pending( $dir, $info );
			if ( ! empty( $info['fontPending'] ) ) throw new Archive_Error( 'A font copy needs review or a successful retry before this job can be removed. Existing copies are preserved.' );
			if ( $info['pending'] ) {
				$pending = $info['pending']; $folder = self::pending_preflight( $dir, $pending );
				if ( $folder ) { foreach ( $pending['names'] as $name ) { $path = self::owned_path( $pending, $name ); if ( is_file( $path ) && ! @unlink( $path ) ) throw new Archive_Error( 'An unfinished media file could not be removed.' ); } if ( ! @rmdir( $folder ) ) throw new Archive_Error( 'The unfinished media folder has unexpected files. It is preserved.' ); }
			}
			$info['status'] = 'removing'; self::persist( $dir, $info ); Archive_Job_Files::retire( $dir ); return [ 'removed'=>true,'completedMediaKept'=>true ];
		}, true );
	}
}
