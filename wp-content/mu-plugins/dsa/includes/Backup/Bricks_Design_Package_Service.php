<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** A closed composition of native settings, template and asset archives. No new design store. */
final class Bricks_Design_Package_Service {
	public const SCHEMA = 'kiwe.bricks-design-package.v1';
	private const STATE = 'kiwe.bricks-design-job.v1';
	public const MAX_BYTES = 234881024;
	private const INDEX_BYTES = 65536;
	private const STATE_BYTES = 4194304;
	private const LIFETIME = 604800;
	private const UNITS = [ 'settings.kiwe'=>Configuration_Snapshot_Service::SCHEMA,'templates.kiwe-set'=>Content_Archive_Job_Service::SCHEMA,'assets.kiwe-media'=>Media_Archive_Job_Service::DESIGN_SCHEMA ];
	private const LIMITS = [ 'manifest.kiwe'=>self::INDEX_BYTES,'settings.kiwe'=>Configuration_Snapshot_Service::MAX_FILE,'templates.kiwe-set'=>Content_Archive_Job_Service::MAX_BYTES,'assets.kiwe-media'=>Media_Archive_Job_Service::MAX_BYTES ];
	public static function allowed(): bool { return Bricks_Design_Snapshot_Service::allowed() && Media_Archive_Service::can_use() && Content_Archive_Service::can_import( 'templates' ) && class_exists( '\ZipArchive' ); }
	private static function directory( string $id, bool $create = false ): string { return Archive_Job_Files::directory( 'design',$id,$create ); }
	private static function info( string $dir ): array {
		$info = json_decode( Archive_Job_Files::read( $dir,'info.json',self::STATE_BYTES ),true,32 );
		if ( ! is_array( $info ) || ( $info['schema'] ?? '' ) !== self::STATE || ! preg_match( '/^[a-f0-9]{32}$/D',$info['id'] ?? '' ) || ! is_int( $info['actor'] ?? null ) || ! is_int( $info['revision'] ?? null ) || ! is_int( $info['expiresAt'] ?? null ) || ! in_array( $info['mode'] ?? '',[ 'export','import' ],true ) || ! is_string( $info['state'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D',$info['stateHash'] ?? '' ) ) throw new Archive_Error( 'The saved design progress is invalid.' );
		return $info;
	}
	private static function authority( array $info, bool $remove = false ): void {
		if ( ! is_user_logged_in() || $info['actor'] !== get_current_user_id() || ( $remove ? ! current_user_can( 'read' ) : ! self::allowed() ) ) throw new Archive_Error( 'This design job is unavailable for your account.' );
		if ( ! $remove && ( $info['expiresAt'] < time() || 'removing' === $info['status'] ) ) throw new Archive_Error( 'This design job expired or is being removed.' );
	}
	private static function persist( string $dir, array $info ): void { $bytes = wp_json_encode( $info,JSON_UNESCAPED_SLASHES ); if ( ! is_string( $bytes ) || strlen( $bytes ) > self::STATE_BYTES ) throw new Archive_Error( 'The design journal exceeds its limit.' ); Archive_Job_Files::write( $dir,'info.json',$bytes ); }
	private static function checkpoint( string $dir, array &$info, array $state, string $phrase ): void {
		$old = $info['state']; $name = 'state-'.bin2hex( random_bytes( 16 ) ).'.kiwe'; $bytes = Archive_Cipher::seal( $state,self::STATE,$phrase,[],self::STATE_BYTES ); Archive_Job_Files::write( $dir,$name,$bytes );
		$info['state'] = $name; $info['stateHash'] = hash( 'sha256',$bytes ); $info['revision']++; $info['updatedAt'] = gmdate( 'c' ); self::persist( $dir,$info );
		if ( $old ) @unlink( Archive_Job_Files::path( $dir,$old ) );
	}
	private static function load( string $dir, array $info, string $phrase ): array { $bytes = Archive_Job_Files::read( $dir,$info['state'],self::STATE_BYTES ); if ( ! hash_equals( $info['stateHash'],hash( 'sha256',$bytes ) ) ) throw new Archive_Error( 'The design checkpoint changed.' ); return Archive_Cipher::open( $bytes,self::STATE,$phrase,self::STATE_BYTES ); }
	private static function locked( string $id, callable $action, bool $remove = false ): array {
		$dir = self::directory( $id ); $path = Archive_Job_Files::path( $dir,'lock' ); $handle = @fopen( $path,'c+b' ); if ( ! $handle ) throw new Archive_Error( 'Design progress could not be locked.' ); @chmod( $path,0600 );
		if ( ! flock( $handle,LOCK_EX|LOCK_NB ) ) { fclose( $handle ); throw new Archive_Error( 'A design step is still running. Check saved progress before retrying.' ); }
		try { $info = self::info( $dir ); self::authority( $info,$remove ); return $action( $dir,$info ); } finally { flock( $handle,LOCK_UN ); fclose( $handle ); }
	}
	private static function projection( array $info ): array {
		$out = array_intersect_key( $info,array_flip( [ 'id','mode','label','status','revision','counts','createdAt','updatedAt','expiresAt','bytes','error','review','result','progress' ] ) );
		$out['kind'] = 'design'; $out['expired'] = $info['expiresAt'] < time(); $out['templatesUrl'] = \DSA\Access\Template_Workspace_Service::url(); return $out;
	}
	public function inventory(): array {
		$root = Configuration_Snapshot_Service::private_directory(); $out = [];
		foreach ( $root ? glob( $root.'/design-job-*' ) ?: [] : [] as $dir ) {
			if ( is_link( $dir ) || ! is_dir( $dir ) || ! preg_match( '/^design-job-[a-f0-9]{32}$/D',basename( $dir ) ) ) continue;
			try { $info = self::info( $dir ); self::authority( $info,true ); $out[] = self::projection( $info ); } catch ( \Throwable $error ) { continue; }
		}
		usort( $out,static fn( $a,$b )=>strcmp( $b['createdAt'],$a['createdAt'] ) ); return $out;
	}
	private static function template_ids(): array {
		$query = new \WP_Query( [ 'post_type'=>BRICKS_DB_TEMPLATE_SLUG,'post_status'=>[ 'publish','future','draft','pending','private' ],'posts_per_page'=>Content_Archive_Job_Service::MAX_PRIMARY+1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>false ] );
		if ( $query->found_posts > Content_Archive_Job_Service::MAX_PRIMARY || count( $query->posts ) > Content_Archive_Job_Service::MAX_PRIMARY ) throw new Archive_Error( 'This design exceeds 1,000 templates. Use separate reviewed template archives.' );
		return array_map( 'intval',$query->posts );
	}
	/** Native dependency proof includes unpublished templates selected for capture, never auto-publication. */
	private static function capture_proof( array $capture ): array {
		$ids = self::template_ids(); $proof = Bricks_Design_Snapshot_Service::validate( $capture['options'],[],$ids,true ); $guards = [];
		if ( $proof['ids']['templates'] !== $ids ) throw new Archive_Error( 'A referenced template is outside the eligible native template collection. Review it in Bricks before capture.' );
		foreach ( $ids as $id ) {
			if ( ! Content_Archive_Service::can_export( 'template',$id ) ) throw new Archive_Error( 'A template is unavailable for your account.' );
			$guards[$id] = hash( 'sha256',(string) wp_json_encode( [ get_post( $id ),get_post_meta( $id ),wp_get_object_terms( $id,get_object_taxonomies( BRICKS_DB_TEMPLATE_SLUG ),[ 'fields'=>'all' ] ) ] ) );
		}
		$asset = $proof['ids']['media'] || $proof['ids']['fonts'] ? Bricks_Font_Archive_Service::design_plan( $proof['ids']['media'],$proof['ids']['fonts'] ) : null;
		$counts = [ 'settings'=>count( $capture['options'] ),'templates'=>count( $ids ),'images'=>count( $proof['ids']['media'] ),'fonts'=>count( $proof['ids']['fonts'] ),'attachments'=>$asset ? count( $asset['records'] ) : 0,'files'=>$asset['fileCount'] ?? 0,'rawBytes'=>$asset['rawBytes'] ?? 0 ];
		return [ 'hash'=>hash( 'sha256',(string) wp_json_encode( [ $capture['hash'],$proof,$guards,$asset ] ) ),'ids'=>$proof['ids'],'templateIds'=>$ids,'counts'=>$counts ];
	}
	private static function create( string $mode, string $phrase, string $key, string $fingerprint, array $counts, callable $initialize ): array {
		if ( ! self::allowed() || ! preg_match( '/^[a-zA-Z0-9-]{24,64}$/D',$key ) ) throw new Archive_Error( 'Native design access and a valid request reference are required.' );
		$root = Configuration_Snapshot_Service::private_directory( true ); $lock = $root.'/archive-jobs.lock'; if ( is_link( $lock ) ) throw new Archive_Error( 'Unsafe backup storage.' );
		$handle = @fopen( $lock,'c+b' ); if ( ! $handle ) throw new Archive_Error( 'Backup storage is unavailable.' ); @chmod( $lock,0600 );
		if ( ! flock( $handle,LOCK_EX|LOCK_NB ) ) { fclose( $handle ); throw new Archive_Error( 'Another backup is starting. Retry shortly.' ); }
		$dir = '';
		try {
			$actor = get_current_user_id(); $keyHash = hash( 'sha256',$key );
			foreach ( glob( $root.'/design-job-*' ) ?: [] as $candidate ) {
				if ( is_link( $candidate ) || ! is_dir( $candidate ) || ! preg_match( '/^design-job-[a-f0-9]{32}$/D',basename( $candidate ) ) ) continue;
				$info = self::info( $candidate );
				if ( $info['actor'] !== $actor || ! hash_equals( $info['key'],$keyHash ) ) continue;
				self::authority( $info ); self::load( $candidate,$info,$phrase );
				if ( $info['mode'] !== $mode || ! hash_equals( $info['fingerprint'],$fingerprint ) ) throw new Archive_Error( 'This request already belongs to a different design. Check saved progress.' );
				return self::projection( $info );
			}
			Archive_Job_Files::quota( $actor ); $free = disk_free_space( $root ); if ( false === $free || $free < self::MAX_BYTES*4+16777216 ) throw new Archive_Error( 'There is insufficient private storage for this design package.' );
			$id = bin2hex( random_bytes( 16 ) ); $dir = self::directory( $id,true );
			$info = [ 'schema'=>self::STATE,'id'=>$id,'actor'=>$actor,'key'=>$keyHash,'fingerprint'=>$fingerprint,'mode'=>$mode,'label'=>'Bricks design','status'=>'export' === $mode ? 'capturing' : 'review','revision'=>0,'state'=>'','stateHash'=>'','counts'=>$counts,'createdAt'=>gmdate( 'c' ),'updatedAt'=>gmdate( 'c' ),'expiresAt'=>time()+self::LIFETIME,'bytes'=>0,'error'=>'','children'=>[] ];
			$state = $initialize( $dir,$info ); self::checkpoint( $dir,$info,$state,$phrase ); return self::projection( $info );
		} catch ( \Throwable $error ) { if ( $dir ) Archive_Job_Files::retire( $dir ); throw $error; } finally { flock( $handle,LOCK_UN ); fclose( $handle ); }
	}
	public function start_export( string $phrase, string $key ): array {
		$capture = Configuration_Snapshot_Service::capture_design( $phrase ); $proof = self::capture_proof( $capture );
		return self::create( 'export',$phrase,$key,$proof['hash'],$proof['counts'],static function( $dir ) use ( $capture,$proof ) { Archive_Job_Files::write( $dir,'settings.kiwe',$capture['bytes'] ); return [ 'capture'=>$proof,'phase'=>'assets' ]; } );
	}
	/** Strict index grammar is shared by capture and import; fixed units cannot register callbacks. */
	public static function validate_index( array $index, array $names ): void {
		if ( array_diff( array_keys( $index ),[ 'schema','siteHash','createdAt','bricksVersion','units','counts' ] ) || ( $index['schema'] ?? '' ) !== self::SCHEMA || ( $index['bricksVersion'] ?? '' ) !== BRICKS_VERSION || ! is_string( $index['createdAt'] ?? null ) || strlen( $index['createdAt'] ) > 40 || ! is_array( $index['units'] ?? null ) || ! array_is_list( $index['units'] ) || count( $index['units'] ) < 1 || count( $index['units'] ) > 3 || ! is_array( $index['counts'] ?? null ) || array_keys( $index['counts'] ) !== [ 'settings','templates','images','fonts','attachments','files','rawBytes' ] ) throw new Archive_Error( 'The design package index is unsupported.' );
		$limits = [ 'settings'=>13,'templates'=>1000,'images'=>200,'fonts'=>100,'attachments'=>200,'files'=>2000,'rawBytes'=>67108864 ];
		foreach ( $limits as $key=>$max ) if ( ! is_int( $index['counts'][$key] ) || $index['counts'][$key] < 0 || $index['counts'][$key] > $max ) throw new Archive_Error( 'A design package count is invalid.' );
		if ( 13 !== $index['counts']['settings'] || $index['counts']['images'] > $index['counts']['attachments'] || ( $index['counts']['fonts'] && ! $index['counts']['attachments'] ) ) throw new Archive_Error( 'The design inventory is incomplete.' );
		if ( ! $index['counts']['attachments'] && ( $index['counts']['files'] || $index['counts']['rawBytes'] ) ) throw new Archive_Error( 'An empty asset set cannot declare files.' );
		$seen = [ 'manifest.kiwe'=>true ];
		foreach ( $index['units'] as $unit ) {
			if ( ! is_array( $unit ) || array_keys( $unit ) !== [ 'name','schema','bytes','hash' ] || ! isset( self::UNITS[$unit['name'] ?? ''] ) || isset( $seen[$unit['name']] ) || $unit['schema'] !== self::UNITS[$unit['name']] || ! is_int( $unit['bytes'] ) || $unit['bytes'] < 1 || $unit['bytes'] > self::LIMITS[$unit['name']] || ( $names[$unit['name']] ?? 0 ) !== $unit['bytes'] || ! is_string( $unit['hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$unit['hash'] ) ) throw new Archive_Error( 'A design package unit is invalid or repeated.' );
			$seen[$unit['name']] = true;
		}
		if ( ! isset( $seen['settings.kiwe'] ) || isset( $seen['templates.kiwe-set'] ) !== ( $index['counts']['templates'] > 0 ) || isset( $seen['assets.kiwe-media'] ) !== ( $index['counts']['attachments'] > 0 ) || array_diff_key( $names,$seen ) || count( $names ) !== count( $seen ) ) throw new Archive_Error( 'The design package is missing a declared unit or contains unexpected files.' );
	}
	private static function zip( string $path ): \ZipArchive { if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES ) throw new Archive_Error( 'Choose a bounded Kiwe design package.' ); $zip = new \ZipArchive(); if ( true !== $zip->open( $path,\ZipArchive::RDONLY|\ZipArchive::CHECKCONS ) ) throw new Archive_Error( 'The design package is damaged.' ); return $zip; }
	private static function index( \ZipArchive $zip, string $phrase ): array {
		if ( $zip->numFiles < 2 || $zip->numFiles > 4 ) throw new Archive_Error( 'The design package has an invalid entry count.' ); $names = [];
		for ( $i=0;$i<$zip->numFiles;$i++ ) {
			$stat = $zip->statIndex( $i ); $name = $stat['name'] ?? ''; $os = 0; $attr = 0; $zip->getExternalAttributesIndex( $i,$os,$attr );
			if ( ! is_string( $name ) || ! isset( self::LIMITS[$name] ) || isset( $names[$name] ) || ( $stat['comp_method'] ?? -1 ) !== \ZipArchive::CM_STORE || ( $stat['encryption_method'] ?? 0 ) !== 0 || ( $stat['size'] ?? 0 ) < 1 || $stat['size'] > self::LIMITS[$name] || $stat['comp_size'] !== $stat['size'] || ( ( $attr >> 16 ) & 0170000 ) === 0120000 ) throw new Archive_Error( 'The design package contains an unsafe or oversized entry.' );
			$names[$name] = (int) $stat['size'];
		}
		if ( ! isset( $names['manifest.kiwe'] ) ) throw new Archive_Error( 'The design package index is missing.' ); $bytes = $zip->getFromName( 'manifest.kiwe' ); if ( ! is_string( $bytes ) ) throw new Archive_Error( 'The design index could not be read.' );
		$index = Archive_Cipher::open( $bytes,self::SCHEMA,$phrase,self::INDEX_BYTES ); self::validate_index( $index,$names ); return $index;
	}
	/** Stream fixed entries to private files; never extract paths supplied by a ZIP. */
	private static function copy_stream( $input, string $dir, string $name, int $bytes, string $hash ): void {
		if ( ! is_resource( $input ) ) throw new Archive_Error( 'The design unit could not be read.' ); $tmp = Archive_Job_Files::path( $dir,'tmp-'.bin2hex( random_bytes( 16 ) ) ); $output = @fopen( $tmp,'x+b' );
		if ( ! $output ) { fclose( $input ); throw new Archive_Error( 'Private design storage could not be written.' ); } @chmod( $tmp,0600 ); $digest = hash_init( 'sha256' ); $count = 0;
		try {
			while ( ! feof( $input ) ) { $chunk = fread( $input,65536 ); if ( ! is_string( $chunk ) || ( '' === $chunk && ! feof( $input ) ) ) throw new Archive_Error( 'A design unit could not be read.' ); $count += strlen( $chunk ); if ( $count > $bytes ) throw new Archive_Error( 'A design unit exceeds its declared size.' ); hash_update( $digest,$chunk ); for ( $offset=0;$offset<strlen( $chunk );$offset+=$written ) { $written = fwrite( $output,substr( $chunk,$offset ) ); if ( ! $written ) throw new Archive_Error( 'Private design storage is full.' ); } }
			if ( $count !== $bytes || ! hash_equals( $hash,hash_final( $digest ) ) || ! fflush( $output ) || ( function_exists( 'fsync' ) && ! fsync( $output ) ) ) throw new Archive_Error( 'A design unit failed its integrity check.' );
		} catch ( \Throwable $error ) { fclose( $input ); fclose( $output ); @unlink( $tmp ); throw $error; }
		fclose( $input ); fclose( $output ); if ( ! @rename( $tmp,Archive_Job_Files::path( $dir,$name ) ) ) { @unlink( $tmp ); throw new Archive_Error( 'The private design unit could not be committed.' ); }
	}
	public function start_import( string $path, string $phrase, string $key ): array {
		if ( ! self::allowed() ) throw new Archive_Error( 'Native design recovery access is required.' ); $zip = self::zip( $path );
		try {
			$index = self::index( $zip,$phrase ); $fingerprint = hash_file( 'sha256',$path );
			return self::create( 'import',$phrase,$key,$fingerprint,$index['counts'],static function( $dir,&$info ) use ( $zip,$path,$index,$phrase,$fingerprint ) {
				self::copy_stream( fopen( $path,'rb' ),$dir,'source.kiwe-design',filesize( $path ),$fingerprint );
				foreach ( $index['units'] as $unit ) self::copy_stream( $zip->getStream( $unit['name'] ),$dir,$unit['name'],$unit['bytes'],$unit['hash'] );
				$options = Configuration_Snapshot_Service::inspect_design( Archive_Job_Files::read( $dir,'settings.kiwe',Configuration_Snapshot_Service::MAX_FILE ),$phrase ); if ( count( $options ) !== $index['counts']['settings'] ) throw new Archive_Error( 'The settings inventory differs from the design index.' );
				if ( $index['counts']['templates'] ) { $templates = Content_Archive_Job_Service::inspect_file( Archive_Job_Files::path( $dir,'templates.kiwe-set' ),$phrase,true ); if ( 'templates' !== $templates['scope'] || 0 !== $templates['sourceId'] || count( $templates['ids'] ) !== $index['counts']['templates'] || $templates['recordCount'] !== count( $templates['ids'] ) ) throw new Archive_Error( 'The template inventory differs from the design index.' ); }
				if ( $index['counts']['attachments'] ) { $assets = Media_Archive_Job_Service::inspect_file( Archive_Job_Files::path( $dir,'assets.kiwe-media' ),$phrase ); if ( 'bricks_design_assets' !== $assets['scope'] || 0 !== $assets['sourceId'] || count( $assets['records'] ) !== $index['counts']['attachments'] || count( $assets['designMedia'] ) !== $index['counts']['images'] || count( $assets['fonts'] ) !== $index['counts']['fonts'] || $assets['fileCount'] !== $index['counts']['files'] || $assets['rawBytes'] !== $index['counts']['rawBytes'] ) throw new Archive_Error( 'The asset inventory differs from the design index.' ); }
				$info['review'] = [ 'counts'=>$index['counts'],'createdAt'=>$index['createdAt'],'policy'=>'copies_then_settings' ]; return [ 'index'=>$index,'phase'=>'assets','approvedCopies'=>false ];
			} );
		} finally { $zip->close(); }
	}
	/** Deterministic child keys recover a start whose HTTP response or parent checkpoint was lost. */
	private static function child( array &$info, string $kind, callable $start ): array {
		$service = 'assets' === $kind ? new Media_Archive_Job_Service() : new Content_Archive_Job_Service();
		if ( ! isset( $info['children'][$kind] ) ) { $job = $start( $service,$info['id'].'-'.$kind ); $info['children'][$kind] = $job['id']; return $job; }
		foreach ( $service->inventory() as $job ) if ( $job['id'] === $info['children'][$kind] ) return $job;
		throw new Archive_Error( 'A required design recovery job expired or was removed. Completed copies are preserved.' );
	}
	private function capture_step( string $dir, array &$info, array &$state, string $phrase ): void {
		$current = self::capture_proof( Configuration_Snapshot_Service::read_design() ); if ( ! hash_equals( $state['capture']['hash'],$current['hash'] ) ) throw new Archive_Error( 'The source design changed during capture. Start a new package; this package cannot mix versions.' );
		$phase = $state['phase']; $ids = $state['capture']['ids'];
		if ( 'assets' === $phase && $info['counts']['attachments'] ) {
			$job = self::child( $info,'assets',static fn( $service,$key )=>$service->start_design_assets( $phrase,$key,$ids['media'],$ids['fonts'] ) );
			if ( 'ready' !== $job['status'] ) $job = ( new Media_Archive_Job_Service() )->step( $job['id'],$phrase,$job['revision'] ); $info['progress'] = [ 'area'=>'assets','count'=>$job['processedBytes'],'total'=>$job['rawBytes'] ];
			if ( 'ready' === $job['status'] ) $state['phase'] = 'templates';
		} elseif ( 'assets' === $phase ) $state['phase'] = 'templates';
		elseif ( 'templates' === $phase && $info['counts']['templates'] ) {
			$job = self::child( $info,'templates',static fn( $service,$key )=>$service->start_export( 'templates',0,$phrase,[],$key ) );
			if ( 'ready' !== $job['status'] ) $job = ( new Content_Archive_Job_Service() )->step( $job['id'],$phrase,$job['revision'] ); $info['progress'] = [ 'area'=>'templates','count'=>$job['count'],'total'=>$job['total'] ];
			if ( 'ready' === $job['status'] ) $state['phase'] = 'package';
		} elseif ( 'templates' === $phase ) $state['phase'] = 'package';
		elseif ( 'package' === $phase ) $this->seal_package( $dir,$info,$phrase );
		else throw new Archive_Error( 'The saved capture phase is invalid.' );
		if ( ! hash_equals( $state['capture']['hash'],self::capture_proof( Configuration_Snapshot_Service::read_design() )['hash'] ) ) throw new Archive_Error( 'The source design changed while this step ran. Start a new package.' );
	}
	private function seal_package( string $dir, array &$info, string $phrase ): void {
		$paths = [ 'settings.kiwe'=>Archive_Job_Files::path( $dir,'settings.kiwe' ) ];
		if ( $info['counts']['templates'] ) $paths['templates.kiwe-set'] = ( new Content_Archive_Job_Service() )->download_path( $info['children']['templates'] );
		if ( $info['counts']['attachments'] ) $paths['assets.kiwe-media'] = ( new Media_Archive_Job_Service() )->download_path( $info['children']['assets'] );
		$units = []; $names = [ 'manifest.kiwe'=>1 ]; foreach ( $paths as $name=>$path ) { $bytes = filesize( $path ); $names[$name] = $bytes; $units[] = [ 'name'=>$name,'schema'=>self::UNITS[$name],'bytes'=>$bytes,'hash'=>hash_file( 'sha256',$path ) ]; }
		$index = [ 'schema'=>self::SCHEMA,'createdAt'=>$info['createdAt'],'bricksVersion'=>BRICKS_VERSION,'units'=>$units,'counts'=>$info['counts'] ]; self::validate_index( $index,$names );
		Archive_Job_Files::write( $dir,'manifest.kiwe',Archive_Cipher::seal( $index,self::SCHEMA,$phrase,[],self::INDEX_BYTES ) );
		$tmp = Archive_Job_Files::path( $dir,'tmp-'.bin2hex( random_bytes( 16 ) ) ); $zip = new \ZipArchive(); if ( true !== $zip->open( $tmp,\ZipArchive::CREATE|\ZipArchive::OVERWRITE ) ) throw new Archive_Error( 'The design package could not be created.' );
		try { foreach ( [ 'manifest.kiwe'=>Archive_Job_Files::path( $dir,'manifest.kiwe' ),...$paths ] as $name=>$path ) if ( ! $zip->addFile( $path,$name ) || ! $zip->setCompressionName( $name,\ZipArchive::CM_STORE ) ) throw new Archive_Error( 'A native design unit could not be packaged.' ); }
		catch ( \Throwable $error ) { $zip->close(); @unlink( $tmp ); throw $error; }
		if ( ! $zip->close() || filesize( $tmp ) > self::MAX_BYTES ) { @unlink( $tmp ); throw new Archive_Error( 'The design package exceeded its limit or could not be saved.' ); }
		$check = self::zip( $tmp ); try { self::index( $check,$phrase ); } finally { $check->close(); }
		if ( ! @rename( $tmp,Archive_Job_Files::path( $dir,'archive.kiwe-design' ) ) ) { @unlink( $tmp ); throw new Archive_Error( 'The design package could not be committed.' ); }
		$info['bytes'] = filesize( Archive_Job_Files::path( $dir,'archive.kiwe-design' ) ); $info['downloadHash'] = hash_file( 'sha256',Archive_Job_Files::path( $dir,'archive.kiwe-design' ) ); $info['status'] = 'ready';
	}
	private function recover_step( string $dir, array &$info, array &$state, string $phrase ): void {
		foreach ( $state['index']['units'] as $unit ) { $path = Archive_Job_Files::path( $dir,$unit['name'] ); if ( ! is_file( $path ) || filesize( $path ) !== $unit['bytes'] || ! hash_equals( $unit['hash'],hash_file( 'sha256',$path ) ) ) throw new Archive_Error( 'A reviewed design unit changed. Completed copies are preserved.' ); }
		if ( empty( $state['approvedCopies'] ) ) throw new Archive_Error( 'Review and confirm the design copies first.' ); $phase = $state['phase'];
		if ( 'assets' === $phase && $info['counts']['attachments'] ) {
			$job = self::child( $info,'assets',static fn( $service,$key )=>$service->start_import( Archive_Job_Files::path( $dir,'assets.kiwe-media' ),$phrase,$key ) );
			if ( 'complete' !== $job['status'] ) $job = ( new Media_Archive_Job_Service() )->step( $job['id'],$phrase,$job['revision'],'review' === $job['status'] ); $info['progress'] = [ 'area'=>'assets','count'=>$job['processedBytes'],'total'=>$job['rawBytes'] ]; if ( 'complete' === $job['status'] ) $state['phase'] = 'templates';
		} elseif ( 'assets' === $phase ) $state['phase'] = 'templates';
		elseif ( 'templates' === $phase && $info['counts']['templates'] ) {
			$assets = $info['children']['assets'] ?? ''; $job = self::child( $info,'templates',static fn( $service,$key )=>$service->start_import( Archive_Job_Files::path( $dir,'templates.kiwe-set' ),$phrase,'templates',$key,$assets ) );
			if ( 'complete' !== $job['status'] ) $job = ( new Content_Archive_Job_Service() )->step( $job['id'],$phrase,$job['revision'],'review' === $job['status'] ); $info['progress'] = [ 'area'=>'templates','count'=>$job['count'],'total'=>$job['total'] ]; if ( 'complete' === $job['status'] ) $state['phase'] = 'settings';
		} elseif ( 'templates' === $phase ) $state['phase'] = 'settings';
		elseif ( 'settings' === $phase ) { $info['status'] = 'copies_ready'; unset( $info['progress'] ); }
		else throw new Archive_Error( 'The saved design recovery phase is invalid.' );
	}
	public function step( string $id, string $phrase, int $revision, bool $confirm = false ): array {
		return self::locked( $id,function( $dir,$info ) use ( $phrase,$revision,$confirm ) {
			$state = self::load( $dir,$info,$phrase ); if ( $info['revision'] !== $revision || in_array( $info['status'],[ 'ready','complete','copies_ready','settings_review','settings_applying' ],true ) ) return self::projection( $info );
			if ( 'review' === $info['status'] ) { if ( ! $confirm ) return self::projection( $info ); $state['approvedCopies'] = true; $info['status'] = 'recovering'; unset( $info['review'] ); self::checkpoint( $dir,$info,$state,$phrase ); return self::projection( $info ); }
			try { if ( 'capturing' === $info['status'] ) $this->capture_step( $dir,$info,$state,$phrase ); elseif ( 'recovering' === $info['status'] ) $this->recover_step( $dir,$info,$state,$phrase ); else throw new Archive_Error( 'This design job cannot continue.' ); $info['error'] = ''; self::checkpoint( $dir,$info,$state,$phrase ); return self::projection( $info ); }
			catch ( \Throwable $error ) { $latest = self::info( $dir ); $latest['error'] = $error instanceof Archive_Error ? $error->getMessage() : 'This design step did not finish. Check saved progress; completed copies are kept.'; self::persist( $dir,$latest ); throw new Archive_Error( $latest['error'],0,$error ); }
		} );
	}
	public function review_settings( string $id, string $phrase, int $revision ): array {
		return self::locked( $id,static function( $dir,$info ) use ( $phrase,$revision ) {
			$state = self::load( $dir,$info,$phrase ); if ( $info['revision'] !== $revision || 'complete' === $info['status'] ) return self::projection( $info );
			if ( 'import' !== $info['mode'] || ! in_array( $info['status'],[ 'copies_ready','settings_review','settings_applying' ],true ) ) throw new Archive_Error( 'Complete the design copies before reviewing live settings.' );
			$assets = $info['children']['assets'] ?? ''; $templates = $info['children']['templates'] ?? ''; $review = ( new Configuration_Snapshot_Service() )->preview_upload( Archive_Job_Files::read( $dir,'settings.kiwe',Configuration_Snapshot_Service::MAX_FILE ),$phrase,$info['counts']['fonts'] ? $assets : '',$assets,$templates );
			if ( ! empty( $state['settingsToken'] ) ) ( new Configuration_Snapshot_Service() )->retire_review( $state['settingsToken'] );
			$state['settingsToken'] = $review['token']; $info['settingsToken'] = $review['token']; $info['review'] = array_diff_key( $review,[ 'token'=>true ] ); $info['status'] = 'settings_review'; $info['error'] = ''; self::checkpoint( $dir,$info,$state,$phrase ); return self::projection( $info );
		} );
	}
	public function apply_settings( string $id, string $phrase, int $revision ): array {
		return self::locked( $id,static function( $dir,$info ) use ( $phrase,$revision ) {
			$state = self::load( $dir,$info,$phrase ); if ( $info['revision'] !== $revision || 'complete' === $info['status'] ) return self::projection( $info );
			if ( 'settings_review' !== $info['status'] || empty( $state['settingsToken'] ) ) throw new Archive_Error( 'Review the current design settings before applying them.' );
			try {
				( new Configuration_Snapshot_Service() )->apply( $state['settingsToken'],$phrase,static function( $event,$result ) use ( $dir,&$info ): void {
					$info['status'] = 'complete' === $event ? 'complete' : 'settings_applying'; $info['result'] = $result; $info['revision']++; $info['updatedAt'] = gmdate( 'c' ); self::persist( $dir,$info );
				} ); return self::projection( $info );
			} catch ( \Throwable $error ) { $latest = self::info( $dir ); if ( 'complete' !== $latest['status'] ) { $latest['error'] = 'The settings restore was not confirmed. Review again; the encrypted before-copy is retained when preparation finished.'; self::persist( $dir,$latest ); } throw new Archive_Error( $latest['error'] ?? 'Check saved progress before retrying.',0,$error ); }
		} );
	}
	public function download_path( string $id ): string {
		return self::locked( $id,static function( $dir,$info ) {
			if ( 'export' !== $info['mode'] || 'ready' !== $info['status'] ) throw new Archive_Error( 'The design package is not ready.' );
			foreach ( $info['children'] as $kind=>$child ) ( 'assets' === $kind ? new Media_Archive_Job_Service() : new Content_Archive_Job_Service() )->download_path( $child );
			$path = Archive_Job_Files::path( $dir,'archive.kiwe-design' ); if ( ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES || ! hash_equals( $info['downloadHash'],hash_file( 'sha256',$path ) ) ) throw new Archive_Error( 'The design package changed.' ); return [ 'path'=>$path ];
		} )['path'];
	}
	private static function child_ids( array $info ): array {
		$out = $info['children']; $root = Configuration_Snapshot_Service::private_directory();
		foreach ( [ 'assets'=>'media','templates'=>'content' ] as $kind=>$type ) {
			$keyHash = hash( 'sha256',$info['id'].'-'.$kind );
			foreach ( glob( $root.'/'.$type.'-job-*' ) ?: [] as $dir ) {
				if ( is_link( $dir ) || ! is_dir( $dir ) || ! preg_match( '/^'.$type.'-job-[a-f0-9]{32}$/D',basename( $dir ) ) ) continue;
				$child = json_decode( Archive_Job_Files::read( $dir,'info.json',self::STATE_BYTES ),true,32 );
				if ( ( $child['actor'] ?? null ) === $info['actor'] && ( $child['key'] ?? '' ) === $keyHash ) { if ( isset( $out[$kind] ) && $out[$kind] !== $child['id'] ) throw new Archive_Error( 'The design child inventory is inconsistent. Existing copies are preserved.' ); $out[$kind] = $child['id']; }
			}
		}
		return $out;
	}
	public function remove( string $id ): array {
		return self::locked( $id,static function( $dir,$info ) {
			$info['children'] = self::child_ids( $info ); $info['status'] = 'removing'; self::persist( $dir,$info );
			foreach ( $info['children'] as $kind=>$child ) { $type = 'assets' === $kind ? 'media' : 'content'; $path = Configuration_Snapshot_Service::private_directory().'/'.$type.'-job-'.$child; if ( ! preg_match( '/^[a-f0-9]{32}$/D',$child ) || is_link( $path ) ) throw new Archive_Error( 'Unsafe child recovery job.' ); if ( is_dir( $path ) ) ( 'assets' === $kind ? new Media_Archive_Job_Service() : new Content_Archive_Job_Service() )->remove( $child ); }
			if ( ! empty( $info['settingsToken'] ) ) ( new Configuration_Snapshot_Service() )->retire_review( $info['settingsToken'] );
			Archive_Job_Files::retire( $dir ); return [ 'removed'=>true,'completedCopiesKept'=>true ];
		},true );
	}
}
