<?php
/** Native M08 qualification. Requires a named lab, exact compiler artifact and disposable schemas. */
function kiwe_mcp_bricks_native_tests( callable $check,callable $ledger,string $run,string $mode ): array {
	if ( PHP_SAPI !== 'cli' || home_url() !== 'https://plum-giraffe-539524.hostingersite.com' || ! preg_match( '/^[a-f0-9]{16}$/D',$run ) || $mode !== 'normal' || ! defined( 'KIWE_M08_PACKAGE' ) || ! defined( 'KIWE_M08_PACKAGE_SHA256' ) ) throw new RuntimeException( 'Exact lab compiler qualification required.' );
	$owner = DSA\Access\Site_Owner_Service::owner_id(); $actor = get_current_user_id();
	$sandbox = new Kiwe_MCP_Native_Table_Sandbox( $run ); $active = false; $jobs = []; $filters = [];
	$files = DSA\Backup\Archive_Job_Files::class; $native = new DSA\Bricks\Compiler_Import_Service();
	$mediaRoot = KIWE_M02['private'] . '/media-' . $run;
	$styleSnapshot = static fn() => DSA\Bricks\Framework_Style_Service::snapshot();
	$originalSettings = get_option( DSA_OPTION_SETTINGS ); $originalStyles = $styleSnapshot();
	$signature = static function (): string {
		global $wpdb; $hash = hash_init( 'sha256' );
		foreach ( [ 'posts'=>'ID','postmeta'=>'meta_id','users'=>'ID','usermeta'=>'umeta_id','comments'=>'comment_ID','commentmeta'=>'meta_id','terms'=>'term_id','term_taxonomy'=>'term_taxonomy_id' ] as $kind=>$key ) {
			$table = $wpdb->$kind; if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D',$table ) ) throw new RuntimeException( 'Native table binding refused.' );
			$count = 0;
			do { $rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY `{$key}` LIMIT 250 OFFSET {$count}",ARRAY_A ); if ( ! is_array( $rows ) || $wpdb->last_error ) throw new RuntimeException( 'Native preservation read failed.' ); foreach ( $rows as $row ) hash_update( $hash,serialize( $row ) ); $count += count( $rows ); if ( $count > 250000 ) throw new RuntimeException( 'Native preservation budget exceeded.' ); } while ( count( $rows ) === 250 );
		}
		return hash_final( $hash );
	};
	$originalRecords = $signature();
	$deny = static function ( callable $work,string $why ) use ( $check ): void { $denied = false; try { $value = $work(); $denied = is_wp_error( $value ); } catch ( Throwable ) { $denied = true; } $check( $denied,$why ); };
	$connect = static function ( DSA\MCP\Operation_Registry $registry,array $scopes ) use ( $owner ): array {
		wp_set_current_user( $owner ); $auth = new DSA\MCP\Authorization_Service( $registry );
		$client = $auth->register_client( [ 'client_name'=>'M08 isolated qualification','redirect_uris'=>[ 'http://127.0.0.1:19385/callback' ],'grant_types'=>[ 'authorization_code','refresh_token' ],'response_types'=>[ 'code' ],'token_endpoint_auth_method'=>'none' ] );
		$verifier = DSA\MCP\Credentials::secret(); $secret = DSA\MCP\Credentials::secret();
		$id = $auth->begin( [ 'client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uris'][0],'response_type'=>'code','code_challenge_method'=>'S256','code_challenge'=>rtrim( strtr( base64_encode( hash( 'sha256',$verifier,true ) ),'+/','-_' ),'=' ),'state'=>'m08-native-fixture','scope'=>implode( ' ',$scopes ),'resource'=>DSA\MCP\Urls::resource() ],$secret );
		$auth->review( $id,$secret,$owner ); $code = $auth->approve( $id,$secret,$owner,true,false );
		$token = $auth->exchange( [ 'client_id'=>$client['client_id'],'grant_type'=>'authorization_code','code'=>$code['code'],'redirect_uri'=>$client['redirect_uris'][0],'code_verifier'=>$verifier,'resource'=>DSA\MCP\Urls::resource() ] );
		DSA\MCP\Auth_Context::establish( $auth,$token['access_token'] ); return [ $auth,$token['access_token'] ];
	};
	try {
		$check( is_file( KIWE_M08_PACKAGE ) && ! is_link( KIWE_M08_PACKAGE ) && hash_file( 'sha256',KIWE_M08_PACKAGE ) === KIWE_M08_PACKAGE_SHA256,'The fixture uses the exact real compiler ZIP, not handwritten Bricks data' );
		$info = $sandbox->start( $owner ); $active = true; $ledger( 'sandbox',$info ); wp_set_current_user( 0 ); wp_set_current_user( $owner );
		$check( ! $info['customersCopied'] && $info['tables'] > 20,'Only disposable native schemas receive test writes' );
		if ( ! is_dir( $mediaRoot ) && ! mkdir( $mediaRoot,0700 ) ) throw new RuntimeException( 'Private fixture media directory unavailable.' );
		$upload = static fn( $u ) => array_replace( $u,[ 'path'=>$mediaRoot,'basedir'=>$mediaRoot,'subdir'=>'','url'=>'https://example.invalid/m08-private-media','baseurl'=>'https://example.invalid/m08-private-media','error'=>false ] );
		add_filter( 'upload_dir',$upload,PHP_INT_MAX ); $filters[] = [ 'upload_dir',$upload ];
		// Keep the original theme/global choices but make this package's own style additions observable in the sandbox.
		$reader = new DSA\Bricks\Compiler_Package_Reader( KIWE_M08_PACKAGE ); $inspection = $reader->inspect();
		$profile = $inspection['documents'][$inspection['profileFile']]['settings']['tokens']['project'];
		foreach ( [ 'variables'=>BRICKS_DB_GLOBAL_VARIABLES,'classes'=>BRICKS_DB_GLOBAL_CLASSES ] as $kind=>$option ) {
			$names = array_map( static fn( $item )=>ltrim( $item['name'],'-' ),$profile[$kind] );
			update_option( $option,array_values( array_filter( get_option( $option,[] ),static fn( $item )=>! in_array( ltrim( $item['name'] ?? '','-' ),$names,true ) ) ),false );
		}
		$before = $styleSnapshot(); $settings = get_option( DSA_OPTION_SETTINGS );
		$job = $native->stage( KIWE_M08_PACKAGE ); $jobs[$job['id']] = true;
		$check( $job['status'] === 'review' && $job['archiveSha256'] === KIWE_M08_PACKAGE_SHA256 && $job['templateTotal'] === 2 && ! $job['blockers'],'Actual native package review retains its exact fingerprint and two drafts' );
		$check( $styleSnapshot() === $before && get_option( DSA_OPTION_SETTINGS ) === $settings,'Staging changes no Bricks styles or Kiwe setting' );
		$check( array_unique( array_column( $job['styles'],'action' ) ) === [ 'add' ],'The isolated importer proposes only native additions' );
		$r = new DSA\MCP\Operation_Registry(); DSA\MCP\Native_Provider::register( $r ); do_action( 'kiwe_mcp_register_providers',$r,DSA\Apps\MCP_Provider_Contract::API );
		[ $auth,$old ] = $connect( $r,[ 'mcp.read' ] );
		$args = [ 'job'=>$job['id'],'archiveSha256'=>KIWE_M08_PACKAGE_SHA256 ];
		$oldGrant = DSA\MCP\Auth_Context::current();
		$deny( static fn()=>$r->read( 'mcp.bricks.import.status',$args,$oldGrant['scopes'],$oldGrant['pins'] ),'A real old scope cannot inspect private import jobs' );
		[ $auth,$bearer ] = $connect( $r,[ 'mcp.read','mcp.bricks.import' ] ); $grant = DSA\MCP\Auth_Context::current();
		$check( count( $r->describe( $grant['scopes'],$grant['pins'] ) ) === 5,'The new scope offers one connection read and exactly four import operations' );
		$deny( static fn()=>$native->delegated_review( $job['id'],str_repeat( '0',64 ) ),'A different ZIP fingerprint is refused at the native owner' );
		$dir = $files::directory( 'compiler',$job['id'] ); $sealed = $files::read( $dir,'state-' . $job['id'] . '.kiwe',50331648 );
		$check( ! str_contains( $sealed,'Single Product' ) && ! str_starts_with( $dir,ABSPATH ),'The job stays encrypted and outside the website root' );
		$intent = new DSA\MCP\Intent_Service( $r );
		$begin = $intent->prepare( 'mcp.bricks.import.begin',$args + [ 'revision'=>$job['revision'] ],'m08-begin-' . $run );
		$deny( static fn()=>$intent->apply( $begin['id'] ),'Even a real import scope cannot begin without native exact approval' );
		DSA\MCP\Auth_Context::clear();
		$check( DSA\MCP\Auth_Context::current() === null,'Native approval has the owner session but no AI bearer' );
		$intent->approve( $begin['id'],$auth,true );
		DSA\MCP\Auth_Context::establish( $auth,$bearer ); $admitted = $intent->apply( $begin['id'] );
		$check( $admitted['state'] === 'complete' && json_decode( $admitted['result']['resultJson'],true )['status'] === 'media','Same-site exact approval starts the existing native job' );
		$native->status( $job['id'] ); $job = $native->status( $job['id'] );
		$deny( static fn()=>$native->advance( $job['id'],$job['revision'] - 1,'step' ),'An outdated cursor cannot advance or duplicate a native unit' );
		for ( $n = 0;$job['status'] !== 'complete' && $n < 16;$n++ ) {
			$view = $intent->prepare( 'mcp.bricks.import.next',$args + [ 'revision'=>$job['revision'],'beginIntent'=>$begin['id'] ],'m08-next-' . $run . '-' . $n );
			$check( ! $view['approvalRequired'],'The exact origin approval authorizes bounded continuation ' . $n );
			$reply = $intent->apply( $view['id'] ); $check( $reply['state'] === 'complete','Native continuation confirms ' . $n );
			$next = $native->status( $job['id'] );
			$check( $next['revision'] === $job['revision'] + 1 && $next['templatesDone'] - $job['templatesDone'] <= 1 && $next['mediaDone'] - $job['mediaDone'] <= 1,'One native unit or phase advances ' . $n );
			$job = $next;
		}
		$check( $job['status'] === 'complete' && count( $job['drafts'] ) === 2,'The compiled fixture creates exactly two native drafts' );
		$check( get_option( DSA_OPTION_SETTINGS ) === $settings && $styleSnapshot()['themeStyles'] === $before['themeStyles'],'Complete import never changes active Framework choices or Theme Styles' );
		foreach ( $job['drafts'] as $draft ) {
			$check( get_post_status( $draft['id'] ) === 'draft' && get_post_type( $draft['id'] ) === BRICKS_DB_TEMPLATE_SLUG,'Native draft, not route publication: ' . $draft['title'] );
			$content = get_post_meta( $draft['id'],BRICKS_DB_PAGE_CONTENT,true );
			$check( is_array( $content ) && count( $content ) > 50 && ! str_contains( wp_json_encode( $content ),'preview.js' ),'Real native elements and no preview interceptor: ' . $draft['title'] );
		}
		// A fresh grant with the same human and scope still cannot borrow the first connection's intent.
		$firstBearer = $bearer; [ $otherAuth ] = $connect( $r,[ 'mcp.read','mcp.bricks.import' ] );
		$deny( static fn()=>( new DSA\MCP\Intent_Service( $r ) )->bricks_origin( $begin['id'],$job['id'],KIWE_M08_PACKAGE_SHA256 ),'A different real connection cannot borrow the approved import origin' );
		DSA\MCP\Auth_Context::establish( $auth,$firstBearer );
		$undo = $intent->prepare( 'mcp.bricks.import.undo',$args + [ 'revision'=>$job['revision'],'beginIntent'=>$begin['id'] ],'m08-undo-' . $run );
		$check( $undo['approvalRequired'],'Conditional recovery needs a separate exact review' );
		// Simulate a real editor after the review: the old review must no longer apply.
		$changed = $job['drafts'][0]['id']; $originalTitle = get_the_title( $changed );
		wp_update_post( [ 'ID'=>$changed,'post_title'=>$originalTitle . ' — newer designer edit' ] );
		DSA\MCP\Auth_Context::clear();
		$deny( static fn()=>$intent->approve( $undo['id'],$auth,true ),'A designer edit invalidates an earlier exact Undo proposal' );
		$check( DSA\MCP\Auth_Context::current() === null,'A rejected native Undo never creates bearer authority' );
		DSA\MCP\Auth_Context::establish( $auth,$firstBearer );
		$undo2 = $intent->prepare( 'mcp.bricks.import.undo',$args + [ 'revision'=>$job['revision'],'beginIntent'=>$begin['id'] ],'m08-undo-new-' . $run );
		DSA\MCP\Auth_Context::clear();
		$view = $intent->native_review( $undo2['id'],$auth );
		$check( $view['review']['id'] === $undo2['id'],'Actual native Undo review resolves the approved origin without an AI bearer' );
		$intent->approve( $undo2['id'],$auth,true );
		$check( DSA\MCP\Auth_Context::current() === null,'Native Undo approval does not leave delegated authentication behind' );
		DSA\MCP\Auth_Context::establish( $auth,$firstBearer ); $recovered = $intent->apply( $undo2['id'] );
		$state = $native->status( $job['id'] );
		$check( $recovered['state'] === 'complete' && $state['status'] === 'recovery-review' && get_post_status( $changed ) === 'draft' && get_the_title( $changed ) === $originalTitle . ' — newer designer edit','Native recovery keeps the newer draft and all of its style dependencies' );
		$check( get_post_status( $job['drafts'][1]['id'] ) === 'trash','The unchanged sibling draft enters native Trash' );
		$check( $styleSnapshot() !== $before && get_option( DSA_OPTION_SETTINGS ) === $settings,'Retained newer draft keeps its added styles while active settings stay exact' );
		$ledger( 'result',[ 'schema'=>'kiwe.mcp-bricks-native.v1','job'=>$job['id'],'status'=>$state['status'],'drafts'=>array_column( $job['drafts'],'id' ) ] );
	} finally {
		foreach ( $jobs as $id=>$_ ) { $dir = $files::directory( 'compiler',$id ); $files::retire( $dir ); }
		foreach ( array_reverse( $filters ) as [ $hook,$filter ] ) remove_filter( $hook,$filter,PHP_INT_MAX );
		if ( is_dir( $mediaRoot ) && realpath( dirname( $mediaRoot ) ) === KIWE_M02['private'] && ! is_link( $mediaRoot ) ) {
			foreach ( new DirectoryIterator( $mediaRoot ) as $file ) if ( ! $file->isDot() ) { if ( ! $file->isFile() || $file->isLink() ) throw new RuntimeException( 'Unexpected fixture media path preserved.' ); unlink( $file->getPathname() ); }
			rmdir( $mediaRoot );
		}
		DSA\MCP\Auth_Context::clear(); if ( $active ) { $closed = $sandbox->close(); $check( $closed['remaining'] === 0,'Every owned native fixture schema is retired' ); }
		wp_set_current_user( 0 ); wp_set_current_user( $actor );
		$check( get_option( DSA_OPTION_SETTINGS ) === $originalSettings && $styleSnapshot() === $originalStyles && $signature() === $originalRecords,'Native fixture cleanup preserves all real settings, styles, authors and content exactly' );
	}
	return [ 'passed'=>true,'cleanup'=>true,'source'=>'exact compiler ZIP','customerDataCopied'=>false ];
}
