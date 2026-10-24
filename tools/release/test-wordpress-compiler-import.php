<?php
/** Actual WordPress/Bricks/private-checkpoint acceptance. Exact disposable lab only. */
use DSA\Bricks\Compiler_Import_Service as Imports;
use DSA\Bricks\Compiler_Import_Plan as Plan;
use DSA\Bricks\Framework_Style_Service as Styles;
use DSA\Bricks\Compiler_Package_Error as PackageError;
use DSA\Backup\Archive_Job_Files as JobFiles;
use DSA\Access\Site_Owner_Service as Owner;
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || ! KIWE_WORKSPACE_TEST_SITE || home_url() !== 'https://plum-giraffe-539524.hostingersite.com' || ! function_exists( 'kiwe_test_compiler_package' ) ) throw new RuntimeException( 'Named lab and embedded synthetic package factory required' );
$test_started=microtime(true);
$out = [ 'schema'=>'kiwe.compiler-import-native.v1','passed'=>false,'cleanup'=>false,'runtime'=>DSA_VERSION,'checks'=>[] ];
$check = static function( $ok,string $label ) use ( &$out,$private ): void { $out['checks'][] = [ 'passed'=>(bool) $ok,'label'=>$label ]; file_put_contents($private.'/compiler-test-progress.json',json_encode(['checks'=>count($out['checks']),'label'=>$label,'passed'=>(bool)$ok,'memory'=>memory_get_peak_usage(true)]),LOCK_EX); if ( ! $ok ) throw new RuntimeException( $label ); };
$reject = static function( callable $call,string $label ) use ( $check ): void { $failed = false; try { $call(); } catch ( PackageError $error ) { $failed = true; } $check( $failed,$label ); };
$actor = get_current_user_id(); $job_ids = []; $drafts = []; $created_media = []; $filters = [];
$source = $private . '/compiler-proof.zip'; $styles_before = null; $styles_original = null; $settings_before = null; $roles_before = get_option( $GLOBALS['wpdb']->prefix . 'user_roles' );
$posts_before = []; $fixture_edit = null; $proof = null;
$signature = static function( int $id ): string { $p = get_post( $id ); $meta = []; foreach ( [ BRICKS_DB_TEMPLATE_TYPE,BRICKS_DB_PAGE_CONTENT,BRICKS_DB_PAGE_HEADER,BRICKS_DB_PAGE_FOOTER,BRICKS_DB_PAGE_SETTINGS,BRICKS_DB_TEMPLATE_SETTINGS ] as $key ) $meta[$key] = get_post_meta( $id,$key ); return hash( 'sha256',serialize( [ $p->post_type,$p->post_status,$p->post_title,$p->post_name,$p->post_content,$p->post_excerpt,$meta ] ) ); };
$jobs = new Imports();
$remember = static function( array $job ) use ( &$job_ids,&$drafts ): array { $job_ids[$job['id']] = true; foreach ( $job['drafts'] as $draft ) $drafts[$draft['id']] = true; return $job; };
$finish = static function( array $job,string $status = 'complete' ) use ( &$jobs,$remember,$check ): array { for ( $n=0; $job['status'] !== $status && $n<30; $n++ ) $job = $remember( $jobs->advance( $job['id'],$job['revision'],'step' ) ); $check( $job['status'] === $status,'Bounded native import reaches ' . $status ); return $job; };
try {
	wp_set_current_user( Owner::owner_id() );
	if ( ! get_current_user_id() ) foreach ( get_users( [ 'role'=>'administrator','fields'=>'ID','number'=>5 ] ) as $candidate ) { wp_set_current_user( $candidate ); if ( Plan::allowed() ) break; }
	$test_actor = get_current_user_id();
	$check( Plan::allowed(),'Existing designated owner has actual native import/style/template recovery authority' );
	$check( current_user_can( 'delete_kiwe_templates' ) && current_user_can( 'delete_others_kiwe_templates' ),'Native template-delete primitives are effective for the controller' );
	$styles_original = Styles::snapshot(); $settings_before = get_option( DSA_OPTION_SETTINGS );
	$classes = $styles_original['classes']['value'];
	$check( ! in_array( 'seamfloating1',array_column( $classes,'id' ),true ),'Synthetic typed-native fixture has no existing ID collision' );
	$classes[] = [ 'id'=>'seamfloating1','name'=>'sf-native-typed-proof','settings'=>[ '_opacity'=>1.0 ],'source'=>'kiwe-project','project'=>'sf-native-typed-proof' ];
	Bricks\Helpers::save_global_classes_in_db( $classes ); $styles_before = Styles::snapshot();
	$originals = get_posts( [ 'post_type'=>[ 'page',BRICKS_DB_TEMPLATE_SLUG ],'post_status'=>[ 'publish','draft','pending','private','trash' ],'numberposts'=>1001,'fields'=>'ids','suppress_filters'=>false ] );
	$check( count( $originals ) <= 1000,'Existing authored record preservation is bounded' );
	// Prime the bounded native inventory once instead of issuing a pair of
	// database reads for every unchanged authored record in this acceptance run.
	_prime_post_caches( $originals,false,true );
	foreach ( $originals as $id ) $posts_before[(int) $id] = $signature( (int) $id );
	$tokens = ( new DSA\Settings() )->get( 'tokens',[] ); $tokens['bricks_theme_style'] = [ 'enabled'=>true,'id'=>'seam-native-import-proof','label'=>'Import proof' ];
	$proof = kiwe_test_compiler_package( $source,$tokens );
	$first = $remember( $jobs->stage( $source ) );
	$check( $first['status'] === 'review' && ! $first['blockers'],'Native package review succeeds without importing' );
	$check( Styles::snapshot() === $styles_before && get_option( DSA_OPTION_SETTINGS ) === $settings_before,'Review leaves native globals and all Kiwe settings exact' );
	$dir = JobFiles::directory( 'compiler',$first['id'] ); $sealed = JobFiles::read( $dir,'state-' . $first['id'] . '.kiwe',50331648 );
	$check( ! str_contains( $sealed,'seam-import-proof' ) && ! str_starts_with( realpath( $dir ),realpath( ABSPATH ) . DIRECTORY_SEPARATOR ),'Actual checkpoint is encrypted outside the document root' );
	$review_hash = hash_file( 'sha256',JobFiles::path( $dir,'review-source.kiwe' ) );
	$check( in_array( $first['id'],array_column( $jobs->listing(),'id' ),true ),'Saved review is discoverable without a browser response' );
	$owner = get_current_user_id(); wp_set_current_user( 0 ); $reject( static fn()=>$jobs->status( $first['id'] ),'Anonymous account cannot read a native private job' ); wp_set_current_user( $owner );
	$reject( static fn()=>$jobs->advance( $first['id'],0,'approve' ),'Stale native revision cannot approve or create media' );
	$first = $remember( $jobs->advance( $first['id'],$first['revision'],'approve' ) );
	$first = $remember( $jobs->advance( $first['id'],$first['revision'],'step' ) );
	$check( $first['mediaDone'] === 1 && $first['templatesDone'] === 0,'One requested step imports at most one native asset' );
	$check( hash_file( 'sha256',JobFiles::path( $dir,'review-source.kiwe' ) ) === $review_hash && filesize( JobFiles::path( $dir,'state-' . $first['id'] . '.kiwe' ) ) < 65536,'Media progress keeps the large sealed review unchanged and writes a small checkpoint' );
	$matches = get_posts( [ 'post_type'=>'attachment','post_status'=>[ 'inherit','private' ],'numberposts'=>3,'fields'=>'ids','meta_key'=>Imports::MARKER,'meta_value'=>$first['id'] . ':' . $proof['assetHash'] ] );
	$check( count( $matches ) === 1,'Native media registration commits its source/job identity' ); $media_id = (int) $matches[0]; $created_media[$media_id] = true;
	$check( is_file( get_attached_file( $media_id ) ) && wp_get_attachment_metadata( $media_id ),'Native WordPress creates the real attachment and metadata' );
	$jobs = new Imports(); $first = $remember( $jobs->status( $first['id'] ) );
	$check( $first['mediaDone'] === 1,'A fresh service resumes the sealed native checkpoint' );
	$first = $remember( $jobs->advance( $first['id'],$first['revision'],'step' ) );
	$check( $first['status'] === 'styles','Media completion moves to the shared native style owner' );
	$interrupted = false;
	$interrupt = static function( $name ) use ( &$interrupted ): void { if ( ! $interrupted && $name === BRICKS_DB_GLOBAL_VARIABLES ) { $interrupted = true; throw new RuntimeException( 'Synthetic stop after native variables commit' ); } };
	add_action( 'updated_option',$interrupt,PHP_INT_MAX,1 ); $filters[] = [ 'updated_option',$interrupt ];
	$reject( static fn()=>$jobs->advance( $first['id'],$first['revision'],'step' ),'Native post-write interruption retains a recoverable checkpoint' );
	remove_action( 'updated_option',$interrupt,PHP_INT_MAX ); array_pop( $filters );
	$check( $interrupted,'The interruption follows an actual native option commit' );
	$first = $remember( ( new Imports() )->status( $first['id'] ) );
	$first = $finish( $first );
	$draft = $first['drafts'][0]['id']; $drafts[$draft] = true;
	$check( get_post_status( $draft ) === 'draft' && current_user_can( 'delete_post',$draft ),'Native template copy is a recoverable draft, never a published route' );
	$content = get_post_meta( $draft,BRICKS_DB_PAGE_CONTENT,true );
	$check( $content[0]['settings']['image']['id'] === $media_id && $content[0]['settings']['image']['url'] === wp_get_attachment_url( $media_id ),'Image control uses the real target attachment ID and URL' );
	$check( str_contains( $content[1]['settings']['_cssCustom'],wp_get_attachment_url( $media_id ) ) && ! str_contains( $content[1]['settings']['_cssCustom'],'data:image' ),'Only the guarded CSS media reference is remapped' );
	$check( count( array_filter( Styles::snapshot()['classes']['value'],static fn( $class ) => ( $class['id'] ?? '' ) === 'seamproof1' ) ) === 1,'Actual native Style Manager contains the supplied class once' );
	$check( (int) get_option( BRICKS_DB_GLOBAL_CLASSES_USER ) === $test_actor,'Native last-editor bookkeeping records the actual authorized style writer' );
	$second = $remember( $jobs->stage( $source ) ); $second = $remember( $jobs->advance( $second['id'],$second['revision'],'approve' ) ); $second = $finish( $second );
	$check( count( get_posts( [ 'post_type'=>'attachment','post_status'=>[ 'inherit','private' ],'numberposts'=>3,'fields'=>'ids','meta_key'=>Imports::ASSET_HASH,'meta_value'=>$proof['assetHash'] ] ) ) === 1,'Repeated import reuses actual verified media instead of duplicating it' );
	$copy = $second['drafts'][0]['id']; $drafts[$copy] = true; $before_edit = get_post_meta( $copy,BRICKS_DB_PAGE_CONTENT,true ); $after_edit = $before_edit; $after_edit[0]['settings']['altText'] = 'Owned native review edit';
	update_post_meta( $copy,BRICKS_DB_PAGE_CONTENT,wp_slash( $after_edit ) ); $fixture_edit = [ $copy,$before_edit,$after_edit ];
	$second = $remember( $jobs->advance( $second['id'],$second['revision'],'undo' ) );
	$check( $second['status'] === 'recovery-review' && get_post_meta( $copy,BRICKS_DB_PAGE_CONTENT,true ) === $after_edit && get_post_status( $copy ) === 'draft','Undo preserves a newer native draft edit and its shared dependencies' );
	update_post_meta( $copy,BRICKS_DB_PAGE_CONTENT,wp_slash( $before_edit ) ); $fixture_edit = null;
	$second = $remember( $jobs->advance( $second['id'],$second['revision'],'undo' ) );
	$check( $second['status'] === 'undone','Selective recovery resumes after the owned fixture edit is reconciled' );
	$first = $remember( $jobs->status( $first['id'] ) ); $first = $remember( $jobs->advance( $first['id'],$first['revision'],'undo' ) );
	$check( $first['status'] === 'undone' && get_post_status( $draft ) === 'trash','Native undo moves only unchanged imported copies to Trash' );
	$check( Styles::snapshot() === $styles_before && get_option( DSA_OPTION_SETTINGS ) === $settings_before,'Native style and exact raw token recovery preserves all other choices' );
	$check( is_float( Styles::snapshot()['classes']['value'][count( $styles_before['classes']['value'] )-1]['settings']['_opacity'] ),'Interrupted native recovery preserves integral floating-point values exactly' );
	$check( get_post( $media_id ) && is_file( get_attached_file( $media_id ) ),'Undo preserves imported media for native reuse and later content' );
	foreach ( $posts_before as $id=>$hash ) $check( $signature( $id ) === $hash,'Original authored native record remains exact: ' . $id );
	$check( get_option( $GLOBALS['wpdb']->prefix . 'user_roles' ) === $roles_before,'No role option or account capability is persistently granted' );
	$out['passed'] = true;
} catch ( Throwable $error ) { $out['error'] = $error->getMessage(); }
finally {
	foreach ( $filters as [ $hook,$callback ] ) remove_action( $hook,$callback,PHP_INT_MAX );
	try {
		wp_set_current_user( $test_actor ?? Owner::owner_id() );
		if ( $fixture_edit ) { [ $id,$before,$after ] = $fixture_edit; if ( get_post_meta( $id,BRICKS_DB_PAGE_CONTENT,true ) === $after ) update_post_meta( $id,BRICKS_DB_PAGE_CONTENT,wp_slash( $before ) ); }
		foreach ( array_keys( $job_ids ) as $id ) {
			$job = $jobs->status( $id ); foreach ( $job['drafts'] as $draft ) $drafts[$draft['id']] = true;
			if ( ! in_array( $job['status'],[ 'review','undone' ],true ) ) $job = $jobs->advance( $id,$job['revision'],'undo' );
			if ( ! in_array( $job['status'],[ 'review','undone' ],true ) ) throw new RuntimeException( 'Native selective recovery requires review' );
			$jobs->advance( $id,$job['revision'],'remove' );
		}
		foreach ( array_keys( $drafts ) as $id ) { $marker = (string) get_post_meta( $id,Imports::MARKER,true ); if ( isset( $job_ids[substr( $marker,0,32 )] ) && get_post_status( $id ) === 'trash' && current_user_can( 'delete_post',$id ) ) wp_delete_post( $id,true ); if ( get_post( $id ) ) throw new RuntimeException( 'Owned native draft cleanup requires review' ); }
		foreach ( array_keys( $created_media ) as $id ) { $marker = (string) get_post_meta( $id,Imports::MARKER,true ); if ( isset( $job_ids[substr( $marker,0,32 )] ) && current_user_can( 'delete_post',$id ) ) wp_delete_attachment( $id,true ); if ( get_post( $id ) ) throw new RuntimeException( 'Owned native media cleanup requires review' ); }
		if ( is_file( $source ) ) unlink( $source );
		if ( $styles_original && $styles_before ) { $check( Styles::restore( 'classes',$styles_original['classes'],$styles_before['classes'] ),'Retire only the unchanged synthetic typed-native class' ); Styles::refresh(); }
		$check( ! is_file( $source ) && ( null === $styles_original || Styles::snapshot() === $styles_original ) && ( null === $settings_before || get_option( DSA_OPTION_SETTINGS ) === $settings_before ),'Final native source/job/content/style retirement is exact' );
		$out['cleanup'] = true;
	} catch ( Throwable $error ) { $out['cleanupError'] = $error->getMessage(); $out['passed'] = false; }
	wp_set_current_user( $actor );
}
$out['elapsedSeconds']=microtime(true)-$test_started;$out['peakMemoryBytes']=memory_get_peak_usage(true);
echo json_encode( $out,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
