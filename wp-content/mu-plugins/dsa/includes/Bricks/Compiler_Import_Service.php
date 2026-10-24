<?php
namespace DSA\Bricks;
use DSA\Backup\Archive_Job_Files as Files;
use DSA\Backup\Media_Archive_Service as Media;
use DSA\Security\Secret_Store;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Resumable, explicitly requested authoring. No visitor hook, remote fetch or background job. */
final class Compiler_Import_Service {
	public const MARKER = '_kiwe_compiler_import_v1';
	public const ASSET_HASH = '_kiwe_compiler_asset_sha256';
	private \DSA\Settings $settings;
	public function __construct( ?\DSA\Settings $settings = null ) { $this->settings = $settings ?? new \DSA\Settings(); }
	private function require_access(): void { if ( ! Compiler_Import_Plan::allowed() ) throw new Compiler_Package_Error( 'Compiler import is unavailable for this account or Bricks installation.' ); }
	public function stage( string $source ): array {
		$this->require_access();
		if ( ! Secret_Store::supports_native_encryption() ) throw new Compiler_Package_Error( 'This server needs native Sodium or OpenSSL AES-256-GCM for large design imports. Existing saved work is retained.' );
		Files::quota( get_current_user_id() );
		$id = bin2hex( random_bytes( 16 ) ); $dir = Files::directory( 'compiler',$id,true ); $ready = false;
		try {
			$target = Files::path( $dir,'source.seam.zip' );
			if ( is_link( $source ) || ! is_file( $source ) || filesize( $source ) > Compiler_Package_Reader::MAX_BYTES || ! copy( $source,$target ) || ! chmod( $target,0600 ) ) throw new Compiler_Package_Error( 'The package could not be kept in private storage.' );
			$reader = new Compiler_Package_Reader( $target ); $inspection = $reader->inspect();
			foreach ( $inspection['assets'] as $asset ) if ( $asset['file'] && ( $asset['mime'] ?? '' ) === 'image/svg+xml' && ( $asset['policy'] ?? '' ) === 'review' ) Compiler_Import_Plan::validate_embedded_svg( $reader->bytes( $asset['file'],262144 ) );
			$plan = Compiler_Import_Plan::prepare( $inspection,$this->settings );
			unset( $inspection['documents'] );
			$state = [ 'schema'=>2,'id'=>$id,'actor'=>get_current_user_id(),'site'=>hash( 'sha256',home_url( '/' ) ),'revision'=>1,'created'=>time(),'expires'=>time()+DAY_IN_SECONDS,'status'=>'review','zipHash'=>hash_file( 'sha256',$target ),'inventory'=>$reader->inventory(),'inspection'=>$inspection,'plan'=>$plan,'media'=>[],'mediaIntents'=>[],'drafts'=>[],'journal'=>[],'draftCursor'=>0,'messages'=>[] ];
			$this->save( $dir,$state ); $ready = true; return $this->summary( $state );
		} finally { if ( ! $ready ) { unset( $reader ); Files::retire( $dir ); } }
	}
	private function encode( array $state ): string {
		try { $json = json_encode( $state,JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ); $decoded = json_decode( $json,true,64,JSON_THROW_ON_ERROR ); } catch ( \JsonException $error ) { throw new Compiler_Package_Error( 'The native recovery values cannot be encoded exactly.' ); }
		if ( ! is_string( $json ) || strlen( $json ) > 33554432 ) throw new Compiler_Package_Error( 'The import checkpoint exceeds its private storage limit.' );
		if ( $decoded !== $state ) throw new Compiler_Package_Error( 'This native value needs a lossless recovery adapter before import.' );
		return $json;
	}
	private function seal( string $dir,string $name,array $value ): string {
		$json = $this->encode( $value );
		$sealed = Secret_Store::encrypt( $json ); if ( '' === $sealed ) throw new Compiler_Package_Error( 'Private import recovery could not be encrypted.' );
		Files::write( $dir,$name,$sealed ); return hash( 'sha256',$json );
	}
	private function unseal( string $dir,string $name ): array {
		$status = Secret_Store::decrypt_with_status( Files::read( $dir,$name,50331648 ) );
		try { $value = $status['status'] === 'ok' ? json_decode( $status['value'],true,64,JSON_THROW_ON_ERROR ) : null; } catch ( \JsonException $error ) { $value = null; }
		if ( ! is_array( $value ) ) throw new Compiler_Package_Error( 'Private import recovery cannot be decrypted.' );
		return [ 'value'=>$value,'hash'=>hash( 'sha256',$status['value'] ) ];
	}
	private function save( string $dir,array &$state ): void {
		// Large immutable native values are sealed once, not rewritten for every asset/cursor.
		$review_keys = [ 'inventory','inspection','plan' ];
		if ( ! isset( $state['reviewHash'] ) ) {
			$review = [ 'id'=>$state['id'],'actor'=>$state['actor'],'site'=>$state['site'] ];
			foreach ( $review_keys as $key ) $review[$key] = $state[$key];
			$state['reviewHash'] = $this->seal( $dir,'review-source.kiwe',$review );
		}
		$recovery_keys = [ 'journal','applyPlan','tokenJournal' ];
		if ( ! isset( $state['recoveryHash'] ) && isset( $state['applyPlan'] ) && in_array( $state['status'],[ 'templates','complete','undone','recovery-review' ],true ) ) {
			$recovery = [ 'id'=>$state['id'],'actor'=>$state['actor'],'site'=>$state['site'] ];
			foreach ( $recovery_keys as $key ) if ( isset( $state[$key] ) ) $recovery[$key] = $state[$key];
			$state['recoveryHash'] = $this->seal( $dir,'recovery.kiwe',$recovery );
		}
		$small = $state; foreach ( $review_keys as $key ) unset( $small[$key] );
		if ( isset( $state['recoveryHash'] ) ) foreach ( $recovery_keys as $key ) unset( $small[$key] );
		$this->seal( $dir,'state-' . $state['id'] . '.kiwe',$small );
		Files::write( $dir,'info.json',wp_json_encode( [ 'actor'=>$state['actor'],'kind'=>'compiler','created'=>$state['created'] ] ) );
	}
	private function load( string $dir,string $id ): array {
		$state = $this->unseal( $dir,'state-' . $id . '.kiwe' )['value'];
		if ( ( $state['schema'] ?? 0 ) !== 2 || ( $state['id'] ?? '' ) !== $id || ( $state['actor'] ?? 0 ) !== get_current_user_id() || ( $state['site'] ?? '' ) !== hash( 'sha256',home_url( '/' ) ) ) throw new Compiler_Package_Error( 'This private import belongs to another account/site or cannot be decrypted.' );
		foreach ( [ 'reviewHash'=>'review-source.kiwe','recoveryHash'=>'recovery.kiwe' ] as $field=>$file ) if ( isset( $state[$field] ) ) {
			$sealed = $this->unseal( $dir,$file ); $part = $sealed['value'];
			if ( ! hash_equals( $state[$field],$sealed['hash'] ) || ( $part['id'] ?? '' ) !== $id || ( $part['actor'] ?? 0 ) !== $state['actor'] || ( $part['site'] ?? '' ) !== $state['site'] ) throw new Compiler_Package_Error( 'The native import review/recovery checkpoint changed.' );
			foreach ( $part as $key=>$value ) if ( ! in_array( $key,[ 'id','actor','site' ],true ) ) $state[$key] = $value;
		}
		return $state;
	}
	public function status( string $id ): array { $this->require_access(); $dir = Files::directory( 'compiler',$id ); return $this->summary( $this->load( $dir,$id ) ); }
	public function listing(): array {
		$this->require_access(); $jobs = [];
		try { $root = \DSA\Backup\Configuration_Snapshot_Service::private_directory( false ); } catch ( \Throwable $error ) { return []; }
		if ( ! $root ) return [];
		foreach ( array_slice( glob( $root . DIRECTORY_SEPARATOR . 'compiler-job-*' ) ?: [],0,40 ) as $path ) {
			$id = substr( basename( $path ),strlen( 'compiler-job-' ) );
			try { $dir = Files::directory( 'compiler',$id ); $info = json_decode( Files::read( $dir,'info.json',1024 ),true ); if ( ( $info['actor'] ?? 0 ) !== get_current_user_id() ) continue; $state = $this->load( $dir,$id ); $jobs[] = [ 'id'=>$id,'created'=>$state['created'],'status'=>$state['status'] ]; }
			catch ( \Throwable $error ) { continue; }
		}
		return $jobs;
	}
	public function advance( string $id,int $revision,string $action ): array {
		$this->require_access(); $dir = Files::directory( 'compiler',$id ); $lock = fopen( Files::path( $dir,'lock' ),'c+b' );
		if ( ! $lock || ! flock( $lock,LOCK_EX | LOCK_NB ) ) { if ( is_resource( $lock ) ) fclose( $lock ); throw new Compiler_Package_Error( 'This import is already being processed. Resume in a moment.' ); }
		try {
			$state = $this->load( $dir,$id );
			if ( $state['revision'] !== $revision ) throw new Compiler_Package_Error( 'Saved progress changed. Refresh this import before continuing.' );
			if ( $action === 'remove' ) {
				if ( ! in_array( $state['status'],[ 'review','complete','undone' ],true ) ) throw new Compiler_Package_Error( 'Finish or undo this partial import before removing its recovery.' );
				flock( $lock,LOCK_UN ); fclose( $lock ); $lock = null; Files::retire( $dir ); return [ 'removed'=>true ];
			}
			if ( $action === 'undo' ) {
				$this->undo( $dir,$state ); $state['revision']++; $this->save( $dir,$state ); return $this->summary( $state );
			}
			if ( $state['expires'] < time() ) throw new Compiler_Package_Error( 'This import review expired. Undo or retain completed work, then review a new export.' );
			if ( $action === 'approve' ) {
				if ( $state['status'] !== 'review' || $state['plan']['blockers'] ) throw new Compiler_Package_Error( 'Resolve the listed dependencies before importing.' );
				if ( Framework_Style_Service::fingerprint( Framework_Style_Service::snapshot() ) !== $state['plan']['styles']['expected'] || hash( 'sha256',serialize( $this->settings->get( 'tokens',[] ) ) ) !== $state['plan']['expectedTokens'] || ! hash_equals( $state['zipHash'],hash_file( 'sha256',Files::path( $dir,'source.seam.zip' ) ) ) ) throw new Compiler_Package_Error( 'The package or target styles changed. Start a fresh review before importing.' );
				$state['status'] = 'media'; $state['revision']++; $this->save( $dir,$state ); return $this->summary( $state );
			}
			if ( $action !== 'step' || ! in_array( $state['status'],[ 'media','styles','templates' ],true ) ) throw new Compiler_Package_Error( 'This import action is unavailable.' );
			$reader = new Compiler_Package_Reader( Files::path( $dir,'source.seam.zip' ),$state['inventory'] );
			try {
				if ( $state['status'] === 'media' ) {
					$pending = array_filter( $state['inspection']['assets'],static fn( $asset ) => $asset['file'] && $asset['policy'] === 'import' && ! isset( $state['media'][$asset['id']] ) );
					if ( $pending ) { $asset = reset( $pending ); $state['media'][$asset['id']] = $this->import_media( $reader,$asset,$dir,$state ); }
					else $state['status'] = 'styles';
				} elseif ( $state['status'] === 'styles' ) $this->install_styles( $reader,$dir,$state );
				else $this->import_template( $reader,$dir,$state );
				$state['revision']++; $state['error'] = ''; $this->save( $dir,$state );
			} catch ( \Throwable $error ) { $state['revision']++; $state['error'] = $error instanceof Compiler_Package_Error ? $error->getMessage() : 'Native import stopped. Saved progress and recovery are retained; review before resuming.'; $this->save( $dir,$state ); throw new Compiler_Package_Error( $state['error'] ); }
			return $this->summary( $state );
		} finally { if ( is_resource( $lock ) ) { flock( $lock,LOCK_UN ); fclose( $lock ); } }
	}
	private function native_asset( int $id,string $hash ): ?array {
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== 'attachment' || ! in_array( $post->post_status,[ 'inherit','private' ],true ) || ! current_user_can( 'edit_post',$id ) ) return null;
		$path = wp_get_original_image_path( $id ) ?: get_attached_file( $id ); $uploads = Media::uploads();
		if ( ! is_string( $path ) || ! str_starts_with( wp_normalize_path( $path ),$uploads['root'] . '/' ) ) return null;
		try { $real = Media::source_path( substr( wp_normalize_path( $path ),strlen( $uploads['root'] )+1 ) ); } catch ( \Throwable $error ) { return null; }
		if ( ! hash_equals( $hash,hash_file( 'sha256',$real ) ) ) return null;
		$url = wp_get_attachment_url( $id ); return is_string( $url ) && $url ? [ 'id'=>$id,'url'=>$url ] : null;
	}
	private function import_media( Compiler_Package_Reader $reader,array $asset,string $dir,array &$state ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
		$hash = substr( $asset['contentHash'],7 ); $marker = $state['id'] . ':' . $hash;
		$owned = get_posts( [ 'post_type'=>'attachment','post_status'=>[ 'inherit','private' ],'numberposts'=>2,'fields'=>'ids','meta_key'=>self::MARKER,'meta_value'=>$marker,'suppress_filters'=>false ] );
		if ( count( $owned ) > 1 ) throw new Compiler_Package_Error( 'A partial media import has duplicate native records. Review it before resuming.' );
		$id = $owned ? (int) $owned[0] : 0;
		if ( ! $id ) {
			$matches = get_posts( [ 'post_type'=>'attachment','post_status'=>[ 'inherit','private' ],'numberposts'=>3,'fields'=>'ids','meta_key'=>self::ASSET_HASH,'meta_value'=>$hash,'suppress_filters'=>false ] );
			foreach ( $matches as $candidate ) if ( $native = $this->native_asset( (int) $candidate,$hash ) ) return $native + [ 'created'=>false ];
			$bytes = $reader->bytes( $asset['file'],Compiler_Package_Reader::MAX_ASSET );
			$extension = pathinfo( $asset['file'],PATHINFO_EXTENSION ); $filename = 'kiwe-' . $state['id'] . '-' . $hash . '.' . $extension;
			$temp = Files::path( $dir,'blob-0000.bin' ); Files::write( $dir,'blob-0000.bin',$bytes );
			if ( Media::file_type( $temp,$filename ) !== $asset['mime'] ) throw new Compiler_Package_Error( 'WordPress did not recognize the supplied media type.' );
			if ( str_starts_with( $asset['mime'],'image/' ) ) {
				$size = wp_getimagesize( $temp );
				if ( ! is_array( $size ) || $size[0] > 16000 || $size[1] > 16000 || $size[0] * $size[1] > 40000000 ) throw new Compiler_Package_Error( 'This design image is invalid or too large to process safely. Supply an optimized original.' );
			} elseif ( ! class_exists( '\finfo' ) || ( new \finfo( FILEINFO_MIME_TYPE ) )->buffer( $bytes ) !== $asset['mime'] ) throw new Compiler_Package_Error( 'The supplied audio/video bytes do not match their native media type.' );
			$uploads = wp_upload_dir(); $relative = ltrim( $uploads['subdir'] . '/' . $filename,'/' );
			if ( ! empty( $uploads['error'] ) ) throw new Compiler_Package_Error( 'Native WordPress uploads are unavailable.' );
			if ( isset( $state['mediaIntents'][$asset['id']] ) && $state['mediaIntents'][$asset['id']]['file'] !== $relative ) throw new Compiler_Package_Error( 'The upload destination changed. Review the retained partial media before continuing.' );
			$state['mediaIntents'][$asset['id']] = [ 'file'=>$relative,'marker'=>$marker ]; $this->save( $dir,$state );
			$existing = trailingslashit( $uploads['path'] ) . $filename;
			if ( file_exists( $existing ) ) { $safe = Media::source_path( $relative ); if ( ! hash_equals( $hash,hash_file( 'sha256',$safe ) ) ) throw new Compiler_Package_Error( 'A different file occupies the import destination.' ); $uploaded = [ 'file'=>$safe,'url'=>trailingslashit( $uploads['url'] ) . $filename,'type'=>$asset['mime'] ]; }
			else {
				// WordPress takes the upload descriptor by reference and may update it.
				$sideload = [ 'name'=>$filename,'tmp_name'=>$temp,'type'=>$asset['mime'],'error'=>0,'size'=>strlen( $bytes ) ];
				$uploaded = wp_handle_sideload( $sideload,[ 'test_form'=>false,'unique_filename_callback'=>static fn()=> $filename ] );
			}
			if ( ! empty( $uploaded['error'] ) || ( $uploaded['type'] ?? '' ) !== $asset['mime'] || wp_normalize_path( $uploaded['file'] ?? '' ) !== wp_normalize_path( $existing ) ) throw new Compiler_Package_Error( 'Native media upload did not match the reviewed destination.' );
			$id = wp_insert_attachment( [ 'post_title'=>'Design image ' . substr( $hash,0,8 ),'post_mime_type'=>$asset['mime'],'post_status'=>'inherit','post_author'=>get_current_user_id(),'meta_input'=>[ self::MARKER=>$marker,self::ASSET_HASH=>$hash ] ],$uploaded['file'],0,true );
			if ( is_wp_error( $id ) || ! $id ) throw new Compiler_Package_Error( 'WordPress could not register this design asset. Its private progress is retained.' );
		}
		if ( ! $native = $this->native_asset( (int) $id,$hash ) ) throw new Compiler_Package_Error( 'The native design asset changed or could not be verified.' );
		if ( ! wp_get_attachment_metadata( $id ) ) { $metadata = wp_generate_attachment_metadata( $id,get_attached_file( $id ) ); if ( is_array( $metadata ) ) wp_update_attachment_metadata( $id,$metadata ); }
		$native = $this->native_asset( (int) $id,$hash );
		if ( ! $native ) throw new Compiler_Package_Error( 'Native media processing did not retain the supplied original.' );
		$temp = Files::path( $dir,'blob-0000.bin' ); if ( is_file( $temp ) ) unlink( $temp );
		return $native + [ 'created'=>true ];
	}
	private function install_styles( Compiler_Package_Reader $reader,string $dir,array &$state ): void {
		$inspection = $state['inspection']; $file = $inspection['profileFile']; $inspection['documents'] = [ $file=>$reader->json( $file ) ];
		$inspection['documents'] = Compiler_Import_Plan::remap( $inspection,$state['media'] );
		// Keep the reviewed template decisions; only proven native media pointers can change here.
		$inspection['templateFiles'] = [];
		$plan = Compiler_Import_Plan::prepare( $inspection,$this->settings );
		$old = $state['plan']['styles'];
		if ( ! $state['journal'] && $plan['styles']['expected'] !== $old['expected'] ) throw new Compiler_Package_Error( 'Native styles changed after review. Undo this staged import and review it again.' );
		if ( ! $state['journal'] ) { $state['applyPlan'] = $plan; $this->save( $dir,$state ); }
		$apply = $state['applyPlan'];
		// An interrupted write can be before or after its exact journalled value, never a later editor's value.
		$current = Framework_Style_Service::snapshot(); $resume = $apply['styles'];
		foreach ( $state['journal'] as $kind=>$entry ) {
			if ( $current[$kind] !== $entry['before'] && $current[$kind] !== $entry['after'] ) throw new Compiler_Package_Error( 'A newer native style edit needs review before resuming.' );
			$resume['before'][$kind] = $current[$kind];
		}
		$resume['expected'] = Framework_Style_Service::fingerprint( $resume['before'] );
		Framework_Style_Service::apply( $resume,function( string $kind,array $before,array $after ) use ( $dir,&$state ): void { if ( ! isset( $state['journal'][$kind] ) ) $state['journal'][$kind] = [ 'before'=>$before,'after'=>$after ]; $this->save( $dir,$state ); } );
		$raw = get_option( DSA_OPTION_SETTINGS,null );
		if ( ! is_array( $raw ) ) throw new Compiler_Package_Error( 'The native Kiwe settings owner is unavailable.' );
		if ( ! isset( $state['tokenJournal'] ) ) {
			if ( hash( 'sha256',serialize( $this->settings->get( 'tokens',[] ) ) ) !== $state['plan']['expectedTokens'] ) throw new Compiler_Package_Error( 'The active Framework changed after review.' );
			$state['tokenJournal'] = [ 'before'=>$raw['tokens'] ?? null,'after'=>$apply['tokensAfter'] ]; $this->save( $dir,$state );
		}
		$journal = $state['tokenJournal'];
		if ( ( $raw['tokens'] ?? null ) !== $journal['after'] && ( ( $raw['tokens'] ?? null ) !== $journal['before'] || ! $this->settings->compare_and_replace_framework_tokens( $raw,$journal['after'] ) ) ) throw new Compiler_Package_Error( 'The active Framework changed. Its recovery is retained for review.' );
		$state['status'] = 'templates';
	}
	private function draft_snapshot( int $id ): array {
		$post = get_post( $id ); if ( ! $post ) return [];
		$meta = get_post_meta( $id ); ksort( $meta ); unset( $meta['_edit_lock'],$meta['_edit_last'] );
		return [ 'post'=>[ $post->post_type,$post->post_status,(int) $post->post_author,$post->post_title,$post->post_content,$post->post_excerpt,$post->post_modified_gmt ],'meta'=>$meta ];
	}
	private function import_template( Compiler_Package_Reader $reader,string $dir,array &$state ): void {
		$files = $state['inspection']['templateFiles'];
		if ( $state['draftCursor'] >= count( $files ) ) { $state['status'] = 'complete'; return; }
		$file = $files[$state['draftCursor']]; $inspection = $state['inspection']; $inspection['documents'] = [ $file=>$reader->json( $file ) ];
		$document = Compiler_Import_Plan::remap( $inspection,$state['media'] )[$file]; Compiler_Import_Plan::validate_document( $document );
		$marker = $state['id'] . ':template:' . hash( 'sha256',$file );
		$existing = get_posts( [ 'post_type'=>BRICKS_DB_TEMPLATE_SLUG,'post_status'=>[ 'draft','pending','publish','private','trash' ],'numberposts'=>2,'fields'=>'ids','meta_key'=>self::MARKER,'meta_value'=>$marker,'suppress_filters'=>false ] );
		if ( count( $existing ) > 1 ) throw new Compiler_Package_Error( 'A staged template has duplicate native records.' );
		$id = $state['drafts'][$file]['id'] ?? ( $existing ? (int) $existing[0] : 0 );
		$created = false;
		if ( ! $id ) {
			$state['templateIntent'] = $marker; $this->save( $dir,$state );
			$id = wp_insert_post( [ 'post_type'=>BRICKS_DB_TEMPLATE_SLUG,'post_title'=>$document['title'],'post_status'=>'draft','post_author'=>get_current_user_id(),'meta_input'=>[ self::MARKER=>$marker ] ],true );
			if ( is_wp_error( $id ) || ! $id ) throw new Compiler_Package_Error( 'WordPress could not create the draft template.' );
			$created = true;
		}
		if ( ! current_user_can( 'edit_post',$id ) || ! current_user_can( 'delete_post',$id ) || get_post_status( $id ) !== 'draft' || get_post_meta( $id,self::MARKER,true ) !== $marker ) throw new Compiler_Package_Error( 'The staged template changed ownership or native editing/recovery permissions.' );
		if ( ! $created && ! isset( $state['drafts'][$file] ) ) {
			$state['drafts'][$file] = [ 'id'=>(int) $id,'allowed'=>[] ]; $this->save( $dir,$state );
			throw new Compiler_Package_Error( 'A draft was created before its checkpoint was saved. Review that preserved draft in Templates; it will not be overwritten or automatically discarded.' );
		}
		$current = $this->draft_snapshot( $id ); $entry = $state['drafts'][$file] ?? [ 'id'=>(int) $id,'allowed'=>[ hash( 'sha256',serialize( $current ) ) ] ];
		if ( ! in_array( hash( 'sha256',serialize( $current ) ),$entry['allowed'],true ) ) throw new Compiler_Package_Error( 'A staged template was edited after import. Its newer work is preserved.' );
		$state['drafts'][$file] = $entry; $this->save( $dir,$state );
		$meta = [ BRICKS_DB_TEMPLATE_TYPE=>$document['templateType'] ];
		foreach ( [ 'content'=>BRICKS_DB_PAGE_CONTENT,'header'=>BRICKS_DB_PAGE_HEADER,'footer'=>BRICKS_DB_PAGE_FOOTER ] as $area=>$key ) if ( isset( $document[$area] ) ) {
			$elements = \Bricks\Helpers::security_check_elements_before_save( wp_slash( $document[$area] ),$id,$area );
			$elements = wp_unslash( $elements );
			if ( $elements !== $document[$area] ) throw new Compiler_Package_Error( 'Native Bricks security would change the imported elements. Review the source rather than silently saving a different design.' );
			$meta[$key] = $elements;
		}
		foreach ( [ 'pageSettings'=>BRICKS_DB_PAGE_SETTINGS,'templateSettings'=>BRICKS_DB_TEMPLATE_SETTINGS ] as $key=>$native ) if ( isset( $document[$key] ) ) $meta[$native] = $document[$key];
		foreach ( $meta as $key=>$value ) {
			$current = $this->draft_snapshot( $id ); if ( get_post_meta( $id,$key,true ) === $value ) continue;
			$after = $current; $after['meta'][$key] = [ maybe_serialize( $value ) ]; ksort( $after['meta'] );
			$state['drafts'][$file]['allowed'] = [ hash( 'sha256',serialize( $current ) ),hash( 'sha256',serialize( $after ) ) ]; $this->save( $dir,$state );
			update_post_meta( $id,$key,wp_slash( $value ) );
			if ( get_post_meta( $id,$key,true ) !== $value ) throw new Compiler_Package_Error( 'Bricks did not retain the exact reviewed native content.' );
			$state['drafts'][$file]['allowed'] = [ hash( 'sha256',serialize( $this->draft_snapshot( $id ) ) ) ]; $this->save( $dir,$state );
		}
		if ( \Bricks\Database::get_setting( 'cssLoading' ) === 'file' ) foreach ( [ 'content','header','footer' ] as $area ) if ( ! empty( $document[$area] ) ) \Bricks\Assets_Files::generate_post_css_file( $id,$area,$document[$area] );
		$state['draftCursor']++;
	}
	private function undo( string $dir,array &$state ): void {
		$messages = []; $conflict = false;
		if ( ! empty( $state['templateIntent'] ) ) {
			$found = get_posts( [ 'post_type'=>BRICKS_DB_TEMPLATE_SLUG,'post_status'=>[ 'draft','pending','publish','private' ],'numberposts'=>2,'fields'=>'ids','meta_key'=>self::MARKER,'meta_value'=>$state['templateIntent'],'suppress_filters'=>false ] );
			foreach ( $found as $id ) if ( ! in_array( (int) $id,array_column( $state['drafts'],'id' ),true ) ) { $conflict = true; $messages[] = 'A draft was created before its checkpoint was saved. Review it in Templates: ' . get_the_title( $id ); }
		}
		foreach ( $state['drafts'] as $file=>$entry ) {
			$id = $entry['id']; $post = get_post( $id ); if ( ! $post || $post->post_status === 'trash' ) continue;
			if ( $post->post_status !== 'draft' || ! current_user_can( 'delete_post',$id ) || ! in_array( hash( 'sha256',serialize( $this->draft_snapshot( $id ) ) ),$entry['allowed'],true ) ) { $conflict = true; $messages[] = 'Retained a template with newer edits: ' . $post->post_title; continue; }
			if ( ! wp_trash_post( $id ) ) { $conflict = true; $messages[] = 'Native Trash could not retain a staged template.'; }
		}
		// If newer drafts use these styles, do not detach their dependencies.
		if ( ! $conflict ) {
			foreach ( array_reverse( $state['journal'],true ) as $kind=>$entry ) if ( ! Framework_Style_Service::restore( $kind,$entry['before'],$entry['after'] ) ) { $conflict = true; $messages[] = 'Retained newer shared styles: ' . $kind; }
			if ( ! $conflict && isset( $state['tokenJournal'] ) ) {
				$raw = get_option( DSA_OPTION_SETTINGS,null ); $entry = $state['tokenJournal'];
				if ( ! is_array( $raw ) || ( ( $raw['tokens'] ?? null ) !== $entry['before'] && ( ( $raw['tokens'] ?? null ) !== $entry['after'] || ! $this->settings->compare_and_replace_framework_tokens( $raw,$entry['before'] ) ) ) ) { $conflict = true; $messages[] = 'Retained a newer active Framework choice.'; }
			}
			Framework_Style_Service::refresh();
		}
		$state['status'] = $conflict ? 'recovery-review' : 'undone';
		$state['messages'] = array_merge( $messages,[ 'Imported media remains in the native Media library for reuse. Undo never removes a file that another page may now use.' ] );
	}
	private function summary( array $state ): array {
		$drafts = [];
		foreach ( $state['drafts'] as $file=>$entry ) $drafts[] = [ 'id'=>$entry['id'],'title'=>$state['plan']['templates'][$file]['title'],'builderUrl'=>\DSA\Access\Template_Workspace_Service::builder_url( $entry['id'] ) ];
		return [ 'id'=>$state['id'],'revision'=>$state['revision'],'status'=>$state['status'],'created'=>$state['created'],'expires'=>$state['expires'],'sourceHash'=>$state['inspection']['sourceHash'],'mediaTotal'=>count( array_filter( $state['inspection']['assets'],static fn( $asset ) => $asset['file'] && $asset['policy'] === 'import' ) ),'mediaDone'=>count( $state['media'] ),'templateTotal'=>count( $state['inspection']['templateFiles'] ),'templatesDone'=>$state['draftCursor'],'styles'=>$state['plan']['styles']['changes'],'stylesReused'=>$state['plan']['styles']['reused'],'warnings'=>$state['plan']['warnings'],'blockers'=>$state['plan']['blockers'],'drafts'=>$drafts,'messages'=>$state['messages'],'error'=>$state['error'] ?? '' ];
	}
}
