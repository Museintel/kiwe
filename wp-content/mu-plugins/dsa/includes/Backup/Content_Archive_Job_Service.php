<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Bounded, actor-owned work around the existing cipher and native content adapters.
 * Each request owns an OS file lock. Progress survives a lost response or process; keys do not.
 * A set is a STORE-only ZIP of authenticated v1 frames and an encrypted, hashed index.
 */
final class Content_Archive_Job_Service {
	public const SCHEMA = 'kiwe.content-set.v1';
	private const STATE = 'kiwe.content-job.v1';
	public const MAX_BYTES = 67108864;
	public const MAX_PRIMARY = 1000;
	public const MAX_RECORDS = 5000;
	private const STATE_BYTES = 4194304;
	private const INDEX_BYTES = 2097152;
	private const LIFETIME = 604800;
	private const CHUNK = 10;
	private static function directory( string $id, bool $create = false ): string { return Archive_Job_Files::directory( 'content', $id, $create ); }
	private static function path( string $dir, string $name ): string {
		if ( ! preg_match( '/^(?:info\.json|lock|source\.kiwe-set|archive\.kiwe-set|manifest\.kiwe|state-[a-f0-9]{32}\.kiwe|part-[0-9]{4}\.kiwe|tmp-[a-f0-9]{32})$/D', $name ) ) throw new \RuntimeException( 'Invalid private backup file.' );
		return Archive_Job_Files::path( $dir, $name );
	}
	private static function write( string $dir, string $name, string $bytes ): void { self::path( $dir, $name ); Archive_Job_Files::write( $dir, $name, $bytes ); }
	private static function read( string $dir, string $name, int $limit ): string { self::path( $dir, $name ); return Archive_Job_Files::read( $dir, $name, $limit ); }
	private static function info( string $dir ): array {
		$info = json_decode( self::read( $dir, 'info.json', self::STATE_BYTES ), true, 32 );
		if ( ! is_array( $info ) || ( $info['schema'] ?? '' ) !== self::STATE || ! is_int( $info['actor'] ?? null ) || ! is_int( $info['revision'] ?? null ) || ! in_array( $info['mode'] ?? '', [ 'export','import' ], true ) || ! is_string( $info['scope'] ?? null ) || ! is_int( $info['sourceId'] ?? null ) || ! is_string( $info['state'] ?? null ) || ! is_string( $info['stateHash'] ?? null ) ) throw new \RuntimeException( 'The saved backup progress is invalid.' );
		return $info;
	}
	private static function authority( array $info, bool $remove = false ): void {
		if ( $remove ) { if ( $info['actor'] !== get_current_user_id() || ! is_user_logged_in() || ! current_user_can( 'read' ) ) throw new \RuntimeException( 'This backup job is unavailable for your account.' ); return; }
		if ( $info['actor'] !== get_current_user_id() || ! is_user_logged_in() || ( 'export' === $info['mode'] ? ! Content_Archive_Service::can_export( $info['scope'], $info['sourceId'] ) : ! Content_Archive_Service::can_import( $info['scope'] ) ) ) throw new \RuntimeException( 'This backup job is unavailable for your account.' );
		if ( Content_Archive_Service::service_scope( $info['scope'] ) && ( $info['serviceDefinition'] ?? null ) !== Content_Archive_Service::service_definition() ) throw new \RuntimeException( 'The Service connection or registered fields changed. Review a new backup or import.' );
		if ( $info['expiresAt'] < time() ) throw new \RuntimeException( 'This job expired. Start a new backup or import.' );
		if ( ! empty( $info['mediaRecovery'] ) ) { $recovery = ( new Media_Archive_Job_Service() )->recovery_map( $info['mediaRecovery'] ); if ( ! hash_equals( $info['mediaProof'], $recovery['proof'] ) ) throw new Archive_Error( 'The selected media recovery changed. Review a new import.' ); }
		if ( 'removing' === $info['status'] ) throw new \RuntimeException( 'This backup job is being removed.' );
	}
	private static function persist_info( string $dir, array $info ): void {
		$value = wp_json_encode( $info, JSON_UNESCAPED_SLASHES ); if ( ! is_string( $value ) || strlen( $value ) > self::STATE_BYTES ) throw new \RuntimeException( 'The backup journal exceeds its limit.' ); self::write( $dir, 'info.json', $value );
	}
	private static function checkpoint( string $dir, array &$info, array $state, string $phrase ): void {
		$name = 'state-' . bin2hex( random_bytes( 16 ) ) . '.kiwe'; $previous = $info['state'];
		$bytes = Archive_Cipher::seal( $state, self::STATE, $phrase, [], self::STATE_BYTES ); self::write( $dir, $name, $bytes );
		$info['state'] = $name; $info['stateHash'] = hash( 'sha256', $bytes ); $info['revision']++; $info['updatedAt'] = gmdate( 'c' ); self::persist_info( $dir, $info );
		if ( $previous ) @unlink( self::path( $dir, $previous ) );
	}
	private static function unlockable( string $id, callable $action, bool $remove = false ): array {
		$dir = self::directory( $id ); $handle = @fopen( self::path( $dir, 'lock' ), 'c+b' );
		if ( ! $handle ) throw new \RuntimeException( 'The backup job could not be locked.' ); @chmod( self::path( $dir, 'lock' ), 0600 );
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) { fclose( $handle ); throw new \RuntimeException( 'A step is still running. Check saved progress before retrying.' ); }
		try { $info = self::info( $dir ); self::authority( $info, $remove ); return $action( $dir, $info ); }
		finally { flock( $handle, LOCK_UN ); fclose( $handle ); }
	}
	private static function projection( array $info ): array {
		$out = array_intersect_key( $info, array_flip( [ 'id','mode','scope','sourceId','label','status','revision','count','total','variations','bytes','createdAt','updatedAt','expiresAt','selection','error','review','result' ] ) );
		$out['expiresIn'] = max( 0, $info['expiresAt'] - time() ); $out['expired'] = $info['expiresAt'] < time(); $out['templateRecoveryAvailable'] = 'import' === $info['mode'] && 'complete' === $info['status'] && ! empty( $info['templateRecovery'] ); return $out;
	}
	private static function retire( string $dir ): void { Archive_Job_Files::retire( $dir ); }
	/** Inventory contains progress only; it never contains the encrypted payload, key or native journal. */
	public function inventory(): array {
		$root = Configuration_Snapshot_Service::private_directory(); if ( ! $root ) return []; $out = [];
		foreach ( glob( $root . DIRECTORY_SEPARATOR . 'content-job-*' ) ?: [] as $dir ) {
			if ( ! preg_match( '/^content-job-[a-f0-9]{32}$/D', basename( $dir ) ) || is_link( $dir ) || ! is_dir( $dir ) ) continue;
			try { $info = self::info( $dir ); self::authority( $info, true ); $out[] = self::projection( $info ); } catch ( \Throwable $error ) { continue; }
		}
		usort( $out, static fn( $a,$b )=>strcmp( $b['createdAt'], $a['createdAt'] ) ); return $out;
	}
	private static function create( array $plan, string $mode, string $phrase, string $key, callable $initialize ): array {
		if ( ! class_exists( '\ZipArchive' ) ) throw new \RuntimeException( 'This server needs the PHP ZIP extension for resumable content backups.' );
		if ( ! preg_match( '/^[a-zA-Z0-9-]{24,64}$/D', $key ) ) throw new \RuntimeException( 'A valid backup request reference is required.' );
		$root = Configuration_Snapshot_Service::private_directory( true ); $lock_path = $root . DIRECTORY_SEPARATOR . 'archive-jobs.lock';
		if ( is_link( $lock_path ) ) throw new \RuntimeException( 'Unsafe backup storage.' ); $handle = @fopen( $lock_path, 'c+b' ); if ( ! $handle ) throw new \RuntimeException( 'Backup storage is unavailable.' ); @chmod( $lock_path, 0600 );
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) { fclose( $handle ); throw new \RuntimeException( 'Another backup is starting. Retry shortly.' ); }
		$dir = '';
		try {
			$actor = get_current_user_id(); $jobs = 0; $site_jobs = 0; $key_hash = hash( 'sha256', $key );
			foreach ( glob( $root . DIRECTORY_SEPARATOR . 'content-job-*' ) ?: [] as $existing ) {
				if ( is_link( $existing ) || ! is_dir( $existing ) || ! preg_match( '/^content-job-[a-f0-9]{32}$/D', basename( $existing ) ) ) continue;
				$site_jobs++;
				try { $info = self::info( $existing ); } catch ( \Throwable $error ) { continue; }
				if ( $info['actor'] !== $actor ) continue; $jobs++;
				if ( ( $info['key'] ?? '' ) === $key_hash ) { self::authority( $info ); if ( $info['mode'] !== $mode || $info['scope'] !== $plan['scope'] || $info['sourceId'] !== $plan['sourceId'] || $info['selection'] !== ( $plan['selection'] ?? null ) || ( 'import' === $mode && ( ! hash_equals( $info['fileHash'], $plan['inputHash'] ) || ( $info['mediaRecovery'] ?? '' ) !== ( $plan['mediaRecovery'] ?? '' ) ) ) ) throw new \RuntimeException( 'This request reference already belongs to another backup.' ); $bytes = self::read( $existing, $info['state'], self::STATE_BYTES ); Archive_Cipher::open( $bytes, self::STATE, $phrase, self::STATE_BYTES ); return self::projection( $info ); }
			}
			Archive_Job_Files::quota( $actor );
			$free = disk_free_space( $root ); if ( false === $free || $free < self::MAX_BYTES * 3 + 16777216 ) throw new \RuntimeException( 'There is insufficient free server space for a resumable backup.' );
			$id = bin2hex( random_bytes( 16 ) ); $dir = self::directory( $id, true );
			$info = [ 'schema'=>self::STATE,'id'=>$id,'key'=>$key_hash,'actor'=>$actor,'mode'=>$mode,'scope'=>$plan['scope'],'sourceId'=>$plan['sourceId'],'label'=>$plan['label'],'selection'=>$plan['selection'] ?? null,'createdAt'=>gmdate( 'c' ),'updatedAt'=>gmdate( 'c' ),'expiresAt'=>time()+self::LIFETIME,'status'=>'export' === $mode ? 'capturing' : 'reviewing','revision'=>0,'state'=>'','stateHash'=>'','count'=>0,'total'=>count( $plan['ids'] ),'variations'=>0,'bytes'=>0,'error'=>'','pending'=>null ];
			if ( isset( $plan['serviceDefinition'] ) ) $info['serviceDefinition'] = $plan['serviceDefinition'];
			$state = $initialize( $dir, $info ); self::checkpoint( $dir, $info, $state, $phrase ); return self::projection( $info );
		} catch ( \Throwable $error ) { if ( $dir ) self::retire( $dir ); throw $error; }
		finally { flock( $handle, LOCK_UN ); fclose( $handle ); }
	}
	public function start_export( string $scope, int $id, string $phrase, array $selection, string $key ): array {
		$plan = ( new Content_Archive_Service() )->plan( $scope, $id, $selection, self::MAX_PRIMARY );
		return self::create( $plan, 'export', $phrase, $key, static fn()=>[ 'plan'=>$plan,'parts'=>[],'termHashes'=>[],'offset'=>0,'recordCount'=>0 ] );
	}
	private static function part_name( int $index ): string { if ( $index < 0 || $index >= self::MAX_PRIMARY ) throw new \RuntimeException( 'The backup set has too many parts.' ); return sprintf( 'part-%04d.kiwe', $index ); }
	private static function zip( string $path ): \ZipArchive {
		$zip = new \ZipArchive(); if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) throw new \RuntimeException( 'The backup set is damaged or unsupported.' ); return $zip;
	}
	private static function manifest( \ZipArchive $zip, string $phrase ): array {
		if ( $zip->numFiles < 2 || $zip->numFiles > self::MAX_PRIMARY + 1 ) throw new \RuntimeException( 'The backup set has an invalid part count.' ); $names = [];
		for ( $index=0; $index<$zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index ); $name = $stat['name'] ?? ''; $limit = 'manifest.kiwe' === $name ? self::INDEX_BYTES : Content_Archive_Service::MAX_BYTES;
			$opsys = 0; $attr = 0; $zip->getExternalAttributesIndex( $index, $opsys, $attr );
			if ( ! is_string( $name ) || ( 'manifest.kiwe' !== $name && ! preg_match( '/^parts\/part-[0-9]{4}\.kiwe$/D', $name ) ) || isset( $names[$name] ) || ( $stat['comp_method'] ?? -1 ) !== \ZipArchive::CM_STORE || ( $stat['encryption_method'] ?? 0 ) !== 0 || ( $stat['size'] ?? 0 ) < 1 || $stat['size'] > $limit || $stat['comp_size'] !== $stat['size'] || ( ( $attr >> 16 ) & 0170000 ) === 0120000 ) throw new \RuntimeException( 'The backup set contains an unsafe or oversized entry.' );
			$names[$name] = (int) $stat['size'];
		}
		if ( ! isset( $names['manifest.kiwe'] ) ) throw new \RuntimeException( 'The backup set index is missing.' );
		$bytes = $zip->getFromName( 'manifest.kiwe' ); if ( ! is_string( $bytes ) ) throw new \RuntimeException( 'The backup set index could not be read.' );
		$manifest = Archive_Cipher::open( $bytes, self::SCHEMA, $phrase, self::INDEX_BYTES );
		if ( ! is_string( $manifest['scope'] ?? null ) || ! Content_Archive_Service::type( $manifest['scope'] ) || ! is_int( $manifest['sourceId'] ?? null ) || ! is_string( $manifest['label'] ?? null ) || strlen( $manifest['label'] ) > 800 || ! is_array( $manifest['parts'] ?? null ) || ! is_array( $manifest['ids'] ?? null ) || count( $manifest['ids'] ) > self::MAX_PRIMARY || count( array_unique( $manifest['ids'], SORT_REGULAR ) ) !== count( $manifest['ids'] ) || count( $manifest['parts'] ) !== count( $names )-1 || ! is_int( $manifest['recordCount'] ?? null ) || $manifest['recordCount'] > self::MAX_RECORDS ) throw new \RuntimeException( 'The backup set manifest is invalid.' );
		$primary = []; $records = [];
		if ( Content_Archive_Service::service_scope( $manifest['scope'] ) ? ( $manifest['serviceDefinition'] ?? null ) !== Content_Archive_Service::service_definition() : isset( $manifest['serviceDefinition'] ) ) throw new \RuntimeException( 'The backup set Service definition does not match this website.' );
		foreach ( $manifest['parts'] as $index=>$part ) {
			$name = 'parts/' . self::part_name( $index );
			if ( ! is_array( $part ) || ( $part['name'] ?? '' ) !== self::part_name( $index ) || ! is_int( $part['bytes'] ?? null ) || ( $names[$name] ?? 0 ) !== $part['bytes'] || ! is_string( $part['hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $part['hash'] ) || ! is_array( $part['ids'] ?? null ) || count( $part['ids'] ) > self::CHUNK || ! is_array( $part['recordIds'] ?? null ) || count( $part['recordIds'] ) > Content_Archive_Service::MAX_RECORDS ) throw new \RuntimeException( 'A backup part manifest is invalid.' );
			foreach ( $part['recordIds'] as $id ) { if ( ! is_int( $id ) || $id < 1 || isset( $records[$id] ) ) throw new \RuntimeException( 'A backup set repeats or misidentifies content.' ); $records[$id] = true; }
			foreach ( $part['ids'] as $id ) { if ( ! is_int( $id ) || ! isset( $records[$id] ) ) throw new \RuntimeException( 'A backup part is missing its primary record.' ); $primary[] = $id; }
		}
		if ( $primary !== $manifest['ids'] || count( $records ) !== $manifest['recordCount'] ) throw new \RuntimeException( 'The backup set is incomplete.' );
		return $manifest;
	}
	public static function inspect_file( string $path, string $phrase, bool $validate_frames = false ): array {
		$zip = self::zip( $path );
		try {
			$manifest = self::manifest( $zip,$phrase );
			if ( $validate_frames ) foreach ( $manifest['parts'] as $part ) {
				$bytes = $zip->getFromName( 'parts/'.$part['name'] );
				if ( ! is_string( $bytes ) || strlen( $bytes ) !== $part['bytes'] || ! hash_equals( $part['hash'],hash( 'sha256',$bytes ) ) ) throw new Archive_Error( 'A native content frame changed.' );
				$payload = Archive_Cipher::open( $bytes,Content_Archive_Service::SCHEMA,$phrase,Content_Archive_Service::MAX_BYTES );
				if ( array_column( $payload['records'] ?? [],'id' ) !== $part['recordIds'] || $payload['scope'] !== $manifest['scope'] || $payload['sourceId'] !== $manifest['sourceId'] ) throw new Archive_Error( 'The native content frame differs from its index.' );
				( new Content_Archive_Service() )->validate( $payload,true,$manifest['ids'],true );
			}
			return $manifest;
		} finally { $zip->close(); }
	}
	public function start_import( string $path, string $phrase, string $scope, string $key, string $media_recovery = '' ): array {
		if ( ! Content_Archive_Service::can_import( $scope ) || ! is_file( $path ) || is_link( $path ) || filesize( $path ) > self::MAX_BYTES || ! class_exists( '\ZipArchive' ) ) throw new \RuntimeException( 'Choose an allowed Kiwe backup set up to 64 MB.' );
		$zip = self::zip( $path ); try { $manifest = self::manifest( $zip, $phrase ); } finally { $zip->close(); }
		if ( Content_Archive_Service::type( $scope ) !== Content_Archive_Service::type( $manifest['scope'] ) || Content_Archive_Service::taxonomy( $scope ) !== Content_Archive_Service::taxonomy( $manifest['scope'] ) ) throw new \RuntimeException( 'Choose a backup set for this content area.' );
		$hash = hash_file( 'sha256', $path ); $recovery = $media_recovery ? ( new Media_Archive_Job_Service() )->recovery_map( $media_recovery, true ) : null;
		return self::create( [ ...$manifest,'inputHash'=>$hash,'mediaRecovery'=>$media_recovery ], 'import', $phrase, $key, static function( $dir, &$info ) use ( $path,$manifest,$hash,$media_recovery,$recovery ) {
			if ( $recovery ) { $info['mediaRecovery'] = $media_recovery; $info['mediaProof'] = $recovery['proof']; }
			$destination = self::path( $dir, 'source.kiwe-set' ); if ( ! @copy( $path, $destination ) ) throw new \RuntimeException( 'The uploaded backup could not be staged privately.' ); @chmod( $destination, 0600 ); if ( ! hash_equals( $hash, hash_file( 'sha256', $destination ) ) ) throw new \RuntimeException( 'The uploaded backup changed while being staged.' );
			$info['fileHash'] = $hash; $info['total'] = count( $manifest['ids'] ); $info['bytes'] = filesize( $destination );
			return [ 'manifest'=>$manifest,'offset'=>0,'dependencies'=>[],'items'=>[],'warnings'=>[],'termHashes'=>[],'map'=>[],'draftGuards'=>[],'ownedTerms'=>[],'linkOffset'=>0 ];
		} );
	}
	private static function load_state( string $dir, array $info, string $phrase ): array {
		$bytes = self::read( $dir, $info['state'], self::STATE_BYTES ); if ( ! hash_equals( $info['stateHash'], hash( 'sha256', $bytes ) ) ) throw new \RuntimeException( 'The saved backup progress changed.' ); return Archive_Cipher::open( $bytes, self::STATE, $phrase, self::STATE_BYTES );
	}
	private static function terms_consistent( array $payload, array &$hashes ): void {
		foreach ( $payload['terms'] as $term ) { $key = $term['taxonomy'] . ':' . $term['id']; $hash = hash( 'sha256', (string) wp_json_encode( $term ) ); if ( isset( $hashes[$key] ) && ! hash_equals( $hashes[$key], $hash ) ) throw new \RuntimeException( 'A category or tag changed between backup parts. Start a fresh selection.' ); $hashes[$key] = $hash; }
		if ( count( $hashes ) > 2000 ) throw new \RuntimeException( 'This selection has too many categories and tags. Choose a smaller selection.' );
	}
	private static function payload( string $dir, array $state, int $offset, string $phrase ): array {
		$manifest = $state['manifest']; $part = $manifest['parts'][$offset]; $zip = self::zip( self::path( $dir, 'source.kiwe-set' ) );
		try { $bytes = $zip->getFromName( 'parts/' . $part['name'] ); } finally { $zip->close(); }
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $part['bytes'] || ! hash_equals( $part['hash'], hash( 'sha256', $bytes ) ) ) throw new \RuntimeException( 'A backup part changed or is missing.' );
		$payload = Archive_Cipher::open( $bytes, Content_Archive_Service::SCHEMA, $phrase, Content_Archive_Service::MAX_BYTES );
		$ids = []; $record_ids = []; foreach ( $payload['records'] ?? [] as $record ) { $record_ids[] = $record['id'] ?? null; if ( 'product_variation' !== ( $record['type'] ?? '' ) ) $ids[] = $record['id'] ?? null; }
		if ( ( $payload['scope'] ?? null ) !== $manifest['scope'] || ( $payload['sourceId'] ?? null ) !== $manifest['sourceId'] || ( $payload['label'] ?? null ) !== $manifest['label'] || ( $payload['selection'] ?? null ) !== ( $manifest['selection'] ?? null ) || ( $payload['serviceDefinition'] ?? null ) !== ( $manifest['serviceDefinition'] ?? null ) || $ids !== $part['ids'] || $record_ids !== $part['recordIds'] ) throw new \RuntimeException( 'A backup part does not match the reviewed selection.' ); return $payload;
	}
	private static function fingerprint( int $id ): string {
		$post = get_post( $id ); if ( ! $post ) return ''; $terms = [];
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) { $ids = wp_get_object_terms( $id, $tax, [ 'fields'=>'ids' ] ); if ( is_wp_error( $ids ) ) throw new \RuntimeException( 'An imported draft could not be checked.' ); sort( $ids ); $terms[$tax] = $ids; }
		$value = wp_json_encode( [ (array) $post,get_post_meta( $id ),$terms ] ); if ( ! is_string( $value ) ) throw new \RuntimeException( 'The imported draft journal could not be encoded.' ); return hash( 'sha256', $value );
	}
	private static function term_fingerprint( int $id, string $tax ): string { $term = get_term( $id, $tax ); return $term && ! is_wp_error( $term ) ? hash( 'sha256', (string) wp_json_encode( [ (array) $term,get_term_meta( $id ) ] ) ) : ''; }
	private static function recover( string $dir, array &$info ): ?array {
		$pending = $info['pending'] ?? null; if ( ! $pending ) return null;
		$review = get_transient( 'kiwe_content_review_' . $pending['token'] ); $complete = $pending['completed'] ?? ( is_array( $review ) ? ( $review['completed'] ?? null ) : null );
		foreach ( $pending['posts'] as $id=>$guard ) if ( get_post( $id ) && ( ! hash_equals( $guard['hash'], self::fingerprint( (int) $id ) ) || ! Content_Archive_Service::can_edit_imported( (int) $id ) ) ) throw new \RuntimeException( 'A partially imported draft was changed elsewhere. Ask the Super Admin to inspect it; Kiwe will not delete or duplicate it.' );
		foreach ( $pending['terms'] as $key=>$guard ) if ( get_term( $guard['id'], $guard['tax'] ) && ! hash_equals( $guard['hash'], self::term_fingerprint( $guard['id'], $guard['tax'] ) ) ) throw new \RuntimeException( 'A partially imported category was changed elsewhere. Inspect it before retrying.' );
		if ( is_array( $complete ) && count( $complete['ids'] ) === count( $pending['posts'] ) && ! array_diff( $complete['ids'], array_keys( $pending['posts'] ) ) && count( array_filter( $complete['ids'], static fn( $id )=>(bool) get_post( $id ) ) ) === count( $complete['ids'] ) ) return $complete;
		foreach ( array_reverse( array_keys( $pending['posts'] ) ) as $id ) if ( get_post( $id ) ) { wp_delete_post( $id, true ); if ( get_post( $id ) ) throw new \RuntimeException( 'A partial imported draft could not be removed. Inspect this import before retrying.' ); }
		foreach ( array_reverse( $pending['terms'] ) as $guard ) if ( get_term( $guard['id'], $guard['tax'] ) ) { $objects = get_objects_in_term( $guard['id'], $guard['tax'] ); if ( is_wp_error( $objects ) || $objects ) throw new \RuntimeException( 'A new category is in use elsewhere. Inspect this import before retrying.' ); wp_delete_term( $guard['id'], $guard['tax'] ); if ( get_term( $guard['id'], $guard['tax'] ) ) throw new \RuntimeException( 'A partial imported category could not be removed.' ); }
		delete_transient( 'kiwe_content_review_' . $pending['token'] ); delete_option( 'kiwe_content_import_' . $pending['token'] );
		$vault = Configuration_Snapshot_Service::private_directory(); $file = $vault . DIRECTORY_SEPARATOR . 'content-review-' . $pending['token'] . '.kiwe'; if ( ! is_link( $file ) && is_file( $file ) ) @unlink( $file );
		$info['pending'] = null; self::persist_info( $dir, $info ); return null;
	}
	private static function import_frame( string $dir, array &$info, array &$state, array $payload, string $phrase ): array {
		$service = new Content_Archive_Service(); $review = $service->preview( Archive_Cipher::seal( $payload, Content_Archive_Service::SCHEMA, $phrase, [], Content_Archive_Service::MAX_BYTES ), $phrase, $info['scope'], $state['manifest']['ids'], $info['mediaRecovery'] ?? '' );
		$info['pending'] = [ 'token'=>$review['token'],'offset'=>$state['offset'],'posts'=>[],'terms'=>[] ]; self::persist_info( $dir, $info );
		$inside = false;
		$refresh = static function( int $id, bool $term = false ) use ( $dir,&$info,&$inside ) {
			if ( $inside ) return; $inside = true;
			try { $changed = false; if ( $term ) { foreach ( $info['pending']['terms'] as &$guard ) if ( $guard['id'] === $id ) { $guard['hash'] = self::term_fingerprint( $id, $guard['tax'] ); $changed = true; } unset( $guard ); } elseif ( isset( $info['pending']['posts'][$id] ) ) { $info['pending']['posts'][$id]['hash'] = self::fingerprint( $id ); $changed = true; } if ( $changed ) self::persist_info( $dir, $info ); }
			finally { $inside = false; }
		};
		$observer = static function( $event, $id, $data ) use ( $dir,&$info,$refresh ) {
			if ( 'post' === $event ) { $info['pending']['posts'][$id] = [ 'hash'=>self::fingerprint( $id ) ]; self::persist_info( $dir, $info ); }
			elseif ( 'term' === $event ) { $key = $data['taxonomy'] . ':' . $id; $info['pending']['terms'][$key] = [ 'id'=>$id,'tax'=>$data['taxonomy'],'hash'=>self::term_fingerprint( $id, $data['taxonomy'] ) ]; self::persist_info( $dir, $info ); }
			elseif ( 'complete' === $event ) { foreach ( array_keys( $info['pending']['posts'] ) as $created ) $refresh( (int) $created ); foreach ( $info['pending']['terms'] as $guard ) $refresh( $guard['id'], true ); $info['pending']['completed'] = $data; self::persist_info( $dir, $info ); }
		};
		$changed = static function( $new,$old,$post ) use ( $refresh ) { $refresh( (int) $post->ID ); };
		$meta = static function( $meta_id,$id ) use ( $refresh ) { $refresh( (int) $id ); };
		$term_meta = static function( $meta_id,$id ) use ( $refresh ) { $refresh( (int) $id, true ); };
		$assignment = static function( $id ) use ( $refresh ) { $refresh( (int) $id ); };
		$hooks = [ [ 'transition_post_status',$changed,3 ],[ 'added_post_meta',$meta,2 ],[ 'updated_post_meta',$meta,2 ],[ 'deleted_post_meta',$meta,2 ],[ 'set_object_terms',$assignment,1 ],[ 'added_term_meta',$term_meta,2 ],[ 'updated_term_meta',$term_meta,2 ] ];
		foreach ( $hooks as [ $hook,$callback,$args ] ) add_action( $hook, $callback, PHP_INT_MIN + 1, $args );
		try { return $service->apply( $review['token'], $phrase, $observer, $state['map'] ); }
		finally { foreach ( $hooks as [ $hook,$callback ] ) remove_action( $hook, $callback, PHP_INT_MIN + 1 ); }
	}
	private static function import_receipt( array &$info, array &$state, array $result ): void {
		$state['map'] += $result['sourceMap']; $info['count'] += $result['count']; $info['pending'] = null; $state['offset']++;
		foreach ( $result['ids'] as $id ) $state['draftGuards'][$id] = self::fingerprint( $id );
		foreach ( $result['createdTerms'] ?? [] as [ $id,$tax ] ) { $term = get_term( $id, $tax ); if ( $term && ! is_wp_error( $term ) ) $state['ownedTerms'][$tax . ':' . $id] = hash( 'sha256', (string) wp_json_encode( [ (int) $term->term_id,$term->name,(int) $term->parent,$term->description ] ) ); }
	}
	public function step( string $id, string $phrase, int $revision, bool $confirm = false ): array {
		return self::unlockable( $id, function( $dir,$info ) use ( $phrase,$revision,$confirm ) {
			$state = self::load_state( $dir, $info, $phrase ); if ( $revision !== $info['revision'] || in_array( $info['status'], [ 'ready','complete' ], true ) ) return self::projection( $info ); $info['error'] = '';
			try {
				if ( 'export' === $info['mode'] ) $this->capture_step( $dir, $info, $state, $phrase );
				elseif ( 'reviewing' === $info['status'] ) $this->review_step( $dir, $info, $state, $phrase );
				elseif ( 'review' === $info['status'] ) { if ( ! $confirm ) return self::projection( $info ); $info['status'] = 'importing'; $state['offset'] = 0; $info['count'] = 0; }
				elseif ( 'importing' === $info['status'] ) {
					$recovered = self::recover( $dir, $info );
					if ( $recovered ) self::import_receipt( $info, $state, $recovered );
					else { $payload = self::payload( $dir, $state, $state['offset'], $phrase ); $checked = ( new Content_Archive_Service() )->reviewed_payload( $payload, $state['manifest']['ids'], $info['mediaRecovery'] ?? '', $info['mediaProof'] ?? '' ); if ( ! hash_equals( $state['dependencies'][$state['offset']], Content_Archive_Service::dependency_proof( $checked['payload'], $state['ownedTerms'] ) ) ) throw new \RuntimeException( 'An author, category or product dependency changed since review. Stop and review a new import.' ); $result = self::import_frame( $dir, $info, $state, $payload, $phrase ); self::import_receipt( $info, $state, $result ); }
					if ( $state['offset'] === count( $state['manifest']['parts'] ) ) { $info['status'] = 'finishing'; $state['linkOffset'] = 0; }
				} elseif ( 'finishing' === $info['status'] ) {
					$payload = self::payload( $dir, $state, $state['linkOffset'], $phrase ); self::finish_links( $dir, $info, $state, $payload ); $state['linkOffset']++;
					if ( $state['linkOffset'] === count( $state['manifest']['parts'] ) ) {
						if ( in_array( $info['scope'], [ 'template','templates' ], true ) ) {
							$info['templateRecovery'] = [];
							foreach ( $state['map'] as $source=>$copy ) {
								if ( ! hash_equals( $state['draftGuards'][$copy] ?? '', self::fingerprint( $copy ) ) || ! Content_Archive_Service::can_edit_imported( $copy ) ) throw new Archive_Error( 'A recovered template changed before completion. Inspect this import.' );
								$info['templateRecovery'][$source] = [ 'id'=>$copy,'hash'=>Content_Archive_Service::template_fingerprint( $copy ) ];
							}
						}
						$info['status'] = 'complete'; $info['result'] = [ 'count'=>$info['count'],'ids'=>array_values( $state['map'] ),'state'=>'drafts' ];
					}
				} else throw new \RuntimeException( 'The backup job has an unsupported state.' );
				self::checkpoint( $dir, $info, $state, $phrase );
			} catch ( \Throwable $error ) { $saved = self::info( $dir ); $saved['error'] = $error->getMessage(); self::persist_info( $dir, $saved ); throw $error; }
			return self::projection( $info );
		} );
	}
	private function capture_step( string $dir, array &$info, array &$state, string $phrase ): void {
		$plan = $state['plan']; $chunk = 'product' === Content_Archive_Service::type( $plan['scope'] ) ? 1 : self::CHUNK;
		while ( true ) {
			$ids = array_slice( $plan['ids'], $state['offset'], $chunk ); $payload = ( new Content_Archive_Service() )->capture( $plan, $ids );
			try { $bytes = Archive_Cipher::seal( $payload, Content_Archive_Service::SCHEMA, $phrase, [], Content_Archive_Service::MAX_BYTES ); break; }
			catch ( \RuntimeException $error ) { if ( count( $ids ) < 2 || ! str_contains( $error->getMessage(), 'exceeds the small-archive limit' ) ) throw $error; $chunk = max( 1, (int) floor( count( $ids ) / 2 ) ); }
		}
		self::terms_consistent( $payload, $state['termHashes'] ); $name = self::part_name( count( $state['parts'] ) );
		if ( $info['bytes'] + strlen( $bytes ) > ( self::MAX_BYTES - self::INDEX_BYTES - 1048576 ) || $state['recordCount'] + count( $payload['records'] ) > self::MAX_RECORDS ) throw new \RuntimeException( 'This selection exceeds the 64 MB or 5,000 total-record limit. Choose a smaller date range.' );
		self::write( $dir, $name, $bytes ); $state['parts'][] = [ 'name'=>$name,'bytes'=>strlen( $bytes ),'hash'=>hash( 'sha256', $bytes ),'ids'=>$ids,'recordIds'=>array_column( $payload['records'], 'id' ) ];
		$state['offset'] += count( $ids ); $state['recordCount'] += count( $payload['records'] ); $info['count'] = $state['offset']; $info['variations'] = $state['recordCount'] - $info['count']; $info['bytes'] += strlen( $bytes );
		if ( $state['offset'] === count( $plan['ids'] ) ) {
			$manifest = [ ...$plan,'parts'=>$state['parts'],'recordCount'=>$state['recordCount'],'capturedAt'=>gmdate( 'c' ) ];
			self::write( $dir, 'manifest.kiwe', Archive_Cipher::seal( $manifest, self::SCHEMA, $phrase, [], self::INDEX_BYTES ) );
			$path = self::path( $dir, 'archive.kiwe-set' ); if ( is_file( $path ) ) @unlink( $path ); $zip = new \ZipArchive(); if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::EXCL ) ) throw new \RuntimeException( 'The backup set could not be assembled.' );
			try { $files = [ 'manifest.kiwe'=>'manifest.kiwe' ]; foreach ( $state['parts'] as $part ) $files[$part['name']] = 'parts/' . $part['name']; foreach ( $files as $file=>$entry ) if ( ! $zip->addFile( self::path( $dir, $file ), $entry ) || ! $zip->setCompressionName( $entry, \ZipArchive::CM_STORE ) ) throw new \RuntimeException( 'A backup part could not be assembled.' ); }
			finally { if ( ! $zip->close() ) throw new \RuntimeException( 'The backup set could not be closed.' ); }
			@chmod( $path, 0600 ); if ( filesize( $path ) > self::MAX_BYTES ) throw new \RuntimeException( 'The assembled backup exceeds 64 MB.' );
			$check = self::zip( $path ); try { self::manifest( $check, $phrase ); } finally { $check->close(); }
			$info['bytes'] = filesize( $path ); $info['fileHash'] = hash_file( 'sha256', $path ); $info['sourceIds'] = $plan['ids']; $info['status'] = 'ready';
		}
	}
	/** A durable guard around idempotent native link writes also survives interruption of finalization. */
	private static function finish_links( string $dir, array &$info, array &$state, array $payload ): void {
		// Always derive references from the authenticated source, never remap an already mapped ID.
		$payload = ( new Content_Archive_Service() )->reviewed_payload( $payload, $state['manifest']['ids'], $info['mediaRecovery'] ?? '', $info['mediaProof'] ?? '' )['payload'];
		$guards = [];
		foreach ( $payload['records'] as $record ) { $id = $state['map'][$record['id']]; $wanted = $info['links'][$id] ?? ( $state['draftGuards'][$id] ?? '' ); if ( ! $wanted || ! hash_equals( $wanted, self::fingerprint( $id ) ) ) throw new \RuntimeException( 'An imported draft changed outside this job. Inspect it before completing this import.' ); $guards[$id] = $wanted; }
		$info['links'] = $guards; self::persist_info( $dir, $info );
		$refresh = static function( $id ) use ( $dir,&$info ) { $id = (int) $id; if ( isset( $info['links'][$id] ) ) { $info['links'][$id] = self::fingerprint( $id ); self::persist_info( $dir, $info ); } };
		$changed = static function( $new,$old,$post ) use ( $refresh ) { $refresh( $post->ID ); }; $meta = static function( $meta_id,$id ) use ( $refresh ) { $refresh( $id ); };
		$hooks = [ [ 'transition_post_status',$changed,3 ],[ 'added_post_meta',$meta,2 ],[ 'updated_post_meta',$meta,2 ],[ 'deleted_post_meta',$meta,2 ],[ 'set_object_terms',$refresh,1 ] ];
		foreach ( $hooks as [ $hook,$callback,$args ] ) add_action( $hook, $callback, PHP_INT_MIN + 1, $args );
		try { ( new Content_Archive_Service() )->reconcile( $payload['records'], $state['map'] ); foreach ( array_keys( $guards ) as $id ) $state['draftGuards'][$id] = self::fingerprint( (int) $id ); $info['links'] = null; }
		finally { foreach ( $hooks as [ $hook,$callback ] ) remove_action( $hook, $callback, PHP_INT_MIN + 1 ); }
	}
	private function review_step( string $dir, array &$info, array &$state, string $phrase ): void {
		$payload = self::payload( $dir, $state, $state['offset'], $phrase ); $checked = ( new Content_Archive_Service() )->reviewed_payload( $payload, $state['manifest']['ids'], $info['mediaRecovery'] ?? '', $info['mediaProof'] ?? '' ); $payload = $checked['payload']; $validation = $checked['validation']; self::terms_consistent( $payload, $state['termHashes'] );
		$state['dependencies'][] = Content_Archive_Service::dependency_proof( $payload );
		foreach ( $payload['records'] as $record ) if ( 'product_variation' !== $record['type'] ) { if ( count( $state['items'] ) < 30 ) { $author = get_userdata( $record['author'] ); $state['items'][] = [ 'title'=>$record['title'],'type'=>$record['type'],'author'=>$author ? $author->display_name : 'Your account','sourceExists'=>(bool) get_post( $record['id'] ) ]; } $info['count']++; } else $info['variations']++;
		$state['warnings'] = array_slice( array_values( array_unique( [ ...$state['warnings'],...$validation['warnings'] ] ) ), 0, 30 ); $state['offset']++;
		if ( $state['offset'] === count( $state['manifest']['parts'] ) ) { $info['status'] = 'review'; $info['review'] = [ 'count'=>$info['total'],'items'=>$state['items'],'warnings'=>$state['warnings'],'variations'=>$info['variations'],'selection'=>$info['selection'],'mode'=>'new-drafts','recoveredMedia'=>! empty( $info['mediaRecovery'] ) ]; }
	}
	public function download_path( string $id ): string {
		return self::unlockable( $id, static function( $dir,$info ) {
			if ( 'export' !== $info['mode'] || 'ready' !== $info['status'] ) throw new \RuntimeException( 'The complete download is not ready.' );
			foreach ( $info['sourceIds'] ?? [] as $source ) if ( ! Content_Archive_Service::can_export( Content_Archive_Service::single_scope( Content_Archive_Service::type( $info['scope'] ) ), $source ) ) throw new \RuntimeException( 'Your permission to download a selected record changed.' );
			$path = self::path( $dir, 'archive.kiwe-set' ); if ( ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES || ! hash_equals( $info['fileHash'], hash_file( 'sha256', $path ) ) ) throw new \RuntimeException( 'The complete download failed its integrity check.' ); return [ 'path'=>$path ];
		} )['path'];
	}
	/** Completed private journals own the map; user-supplied source/copy IDs are never accepted. */
	public function recovery_map( string $id ): array {
		return self::unlockable( $id, static function( $dir,$info ): array {
			$journal = $info['templateRecovery'] ?? [];
			if ( 'import' !== $info['mode'] || 'complete' !== $info['status'] || ! in_array( $info['scope'], [ 'template','templates' ], true ) || ! is_array( $journal ) || ! $journal || count( $journal ) > self::MAX_PRIMARY ) throw new Archive_Error( 'Choose a completed template recovery created with this version of Kiwe.' );
			$map = []; $seen = [];
			foreach ( $journal as $source=>$copy ) {
				$native = is_int( $copy['id'] ?? null ) ? get_post( $copy['id'] ) : null;
				if ( ! preg_match( '/^[1-9][0-9]*$/D', (string) $source ) || ! $native || $native->ID === (int) $source || isset( $seen[$native->ID] ) || ! in_array( $native->post_status, [ 'draft','publish' ], true ) || ! current_user_can( 'edit_post', $native->ID ) || ! is_string( $copy['hash'] ?? null ) || ! hash_equals( $copy['hash'], Content_Archive_Service::template_fingerprint( $native->ID ) ) ) throw new Archive_Error( 'A recovered template changed or is no longer available. Review a new template recovery.' );
				$map[(int) $source] = [ 'id'=>(int) $native->ID,'status'=>$native->post_status ]; $seen[$native->ID] = true;
			}
			return [ 'id'=>$info['id'],'map'=>$map,'proof'=>hash( 'sha256', (string) wp_json_encode( [ $journal,$map ] ) ) ];
		} );
	}
	public function remove( string $id ): array {
		$result = self::unlockable( $id, static function( $dir,$info ) { $extra = 0; if ( $info['pending'] ) { $completed = self::recover( $dir, $info ); if ( $completed ) { $extra = $completed['count']; $info['pending'] = null; } } $count = 'import' === $info['mode'] ? $info['count'] + $extra : 0; $info['status'] = 'removing'; self::persist_info( $dir, $info ); return [ 'directory'=>$dir,'removed'=>true,'draftsKept'=>$count ]; }, true );
		self::retire( $result['directory'] ); unset( $result['directory'] ); return $result;
	}
}
