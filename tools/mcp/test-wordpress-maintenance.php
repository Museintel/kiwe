<?php
/** M09 actual SQL/intent qualification in disposable schemas, never a production cleanup tool. */
function kiwe_mcp_maintenance_native_tests( callable $check,callable $ledger,string $run,string $mode ): array {
 if ( PHP_SAPI !== 'cli' || home_url() !== 'https://plum-giraffe-539524.hostingersite.com' || ! preg_match( '/^[a-f0-9]{16}$/D',$run ) || ! in_array( $mode,[ 'normal','plain' ],true ) ) throw new RuntimeException( 'Named isolated lab qualification required.' );
 $owner = DSA\Access\Site_Owner_Service::owner_id();$actor = get_current_user_id();$sandbox = new Kiwe_MCP_Native_Table_Sandbox( $run );$active = false;
 $style_state = static function() use ( $mode ): array {
  if ( $mode === 'normal' ) return DSA\Bricks\Framework_Style_Service::snapshot();
  // Plain WordPress still retains dormant builder options, without loading Bricks.
  global $wpdb;
  $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",$wpdb->esc_like( 'bricks_' ) . '%' ),ARRAY_A );
  if ( ! is_array( $rows ) || $wpdb->last_error ) throw new RuntimeException( 'Native dormant style read failed.' );
  return $rows;
 };
 $settings = get_option( DSA_OPTION_SETTINGS );$style = $style_state();
 $signature = static function(): string {
  global $wpdb;$hash = hash_init( 'sha256' );
  foreach ( [ 'posts'=>'ID','postmeta'=>'meta_id','users'=>'ID','usermeta'=>'umeta_id','comments'=>'comment_ID','commentmeta'=>'meta_id','terms'=>'term_id','term_taxonomy'=>'term_taxonomy_id','term_relationships'=>'object_id,term_taxonomy_id' ] as $kind=>$key ) {
   $table = $wpdb->$kind;if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D',$table ) ) throw new RuntimeException( 'Native table binding refused.' );$count = 0;
   do { $rows = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY ' . $key . ' LIMIT 250 OFFSET ' . $count,ARRAY_A );if ( ! is_array( $rows ) || $wpdb->last_error ) throw new RuntimeException( 'Native preservation read failed.' );foreach ( $rows as $row ) hash_update( $hash,serialize( $row ) );$count += count( $rows );if ( $count > 250000 ) throw new RuntimeException( 'Native preservation budget exceeded.' ); } while ( count( $rows ) === 250 );
  }return hash_final( $hash );
 };
 $original = $signature();global $wpdb;$nextFixtureId = (int) $wpdb->get_var( 'SELECT MAX(ID) FROM ' . $wpdb->posts ) + 1000;
 // Native settings are seeded while authored records are not. Give synthetic
 // records fresh IDs so they cannot masquerade as a copied site's old targets.
 $deny = static function( callable $work,string $why ) use ( $check ): void { $denied = false;try { $v = $work();$denied = is_wp_error( $v ); } catch ( Throwable ) { $denied = true; }$check( $denied,$why ); };
 $connect = static function( DSA\MCP\Operation_Registry $registry,array $scopes ) use ( $owner ): array {
  wp_set_current_user( $owner );$auth = new DSA\MCP\Authorization_Service( $registry );
  $client = $auth->register_client( [ 'client_name'=>'M09 isolated qualification','redirect_uris'=>[ 'http://127.0.0.1:19389/callback' ],'grant_types'=>[ 'authorization_code','refresh_token' ],'response_types'=>[ 'code' ],'token_endpoint_auth_method'=>'none' ] );
  $verifier = DSA\MCP\Credentials::secret();$secret = DSA\MCP\Credentials::secret();
  $id = $auth->begin( [ 'client_id'=>$client['client_id'],'redirect_uri'=>$client['redirect_uris'][0],'response_type'=>'code','code_challenge_method'=>'S256','code_challenge'=>rtrim( strtr( base64_encode( hash( 'sha256',$verifier,true ) ),'+/','-_' ),'=' ),'state'=>'m09-native-fixture','scope'=>implode( ' ',$scopes ),'resource'=>DSA\MCP\Urls::resource() ],$secret );
  $auth->review( $id,$secret,$owner );$code = $auth->approve( $id,$secret,$owner,true,false );
  $token = $auth->exchange( [ 'client_id'=>$client['client_id'],'grant_type'=>'authorization_code','code'=>$code['code'],'redirect_uri'=>$client['redirect_uris'][0],'code_verifier'=>$verifier,'resource'=>DSA\MCP\Urls::resource() ] );
  DSA\MCP\Auth_Context::establish( $auth,$token['access_token'] );return [ $auth,$token['access_token'] ];
 };
 $hook = null;$queryFailure = null;$litespeed = null;$nativeHooksSaved = false;
 try {
  $info = $sandbox->start( $owner );$active = true;$ledger( 'sandbox',$info );wp_set_current_user( 0 );wp_set_current_user( $owner );
  $check( ! $info['customersCopied'] && $info['tables'] > 20,'Only disposable native schemas receive fixture writes' );
  $registry = new DSA\MCP\Operation_Registry();DSA\MCP\Native_Provider::register( $registry );do_action( 'kiwe_mcp_register_providers',$registry,DSA\Apps\MCP_Provider_Contract::API );
  $scope = [ 'mcp.read','mcp.audit.read','mcp.cleanup.write','mcp.maintenance.write' ];$ops = $registry->describe( $scope );
  $check( count( $ops ) === ( $mode === 'normal' ? 4 : 3 ) && count( array_filter( $ops,static fn( $o ) => $o['effect'] === 'write' ) ) === ( $mode === 'normal' ? 2 : 1 ),'Only applicable reviewed native audit/cleanup/cache operations are advertised' );
  [ $oldAuth,$oldBearer ] = $connect( $registry,[ 'mcp.read' ] );$old = DSA\MCP\Auth_Context::current();
  $deny( static fn() => $registry->read( 'mcp.audit.read',[ 'area'=>'overview' ],$old['scopes'],$old['pins'] ),'A real older connection cannot acquire the new audit scope' );
  [ $auth,$bearer ] = $connect( $registry,$scope );$intent = new DSA\MCP\Intent_Service( $registry );$audit = new DSA\MCP\Audit_Service();
  $new = static function( string $label,string $type = 'page',string $status = 'draft' ) use ( $run,&$nextFixtureId ): int {
   $id = wp_insert_post( [ 'import_id'=>$nextFixtureId++,'post_type'=>$type,'post_status'=>$status,'post_title'=>'M09 ' . $label . ' ' . $run,'post_content'=>'','post_name'=>'m09-' . sanitize_title( $label ) . '-' . $run ],true );if ( is_wp_error( $id ) ) throw new RuntimeException( 'Native fixture record could not be created.' );return (int) $id;
  };
  $id = $new( 'eligible' );$source = $new( 'source' );$listIds = [ $id,$source ];for ( $n = 0;$n < 8;$n++ ) $listIds[] = $new( 'list-' . $n );rsort( $listIds,SORT_NUMERIC );
  $one = json_decode( $audit->read( [ 'area'=>'records','type'=>'page','page'=>1 ] )['resultJson'],true )['data'];$two = json_decode( $audit->read( [ 'area'=>'records','type'=>'page','page'=>2 ] )['resultJson'],true )['data'];
  $check( array_column( array_merge( $one['items'],$two['items'] ),'id' ) === $listIds && $one['hasMore'] && ! $two['hasMore'],'Actual native audit pages retain every selected record without gaps' );
  $view = $audit->inspect( 'page',$id );$ledger('initial-audit',['eligible'=>$view['cleanupEligible'],'complete'=>$view['complete'],'referenceMatches'=>$view['referenceMatches'],'blockers'=>$view['blockers']]);
  $check( $view['cleanupEligible'] && $view['complete'] && $view['referenceMatches'] === [ 'content'=>0,'metadata'=>0,'settings'=>0 ],'Actual MariaDB identifies an exact draft without declared native references' );
  $args = [ 'type'=>'page','id'=>$id,'action'=>'trash','expected'=>$view['expected'] ];
  $review = $intent->prepare( 'mcp.cleanup.change',$args,'m09-initial-' . $run );$check( $review['approvalRequired'],'The native intent requires an exact cleanup review' );
  $deny( static fn() => $intent->apply( $review['id'] ),'No content changes before native approval' );
  add_post_meta( $source,'_bricks_page_content_2',[ [ 'id'=>'m9ref1','name'=>'div','parent'=>0,'children'=>[],'settings'=>[ 'query'=>[ 'post_type'=>'page','post__in'=>[ $id ] ] ] ] ] );
  $changed = $audit->inspect( 'page',$id );$check( ! $changed['cleanupEligible'] && $changed['referenceMatches']['metadata'] === 1,'An actual stored metadata reference protects its target' );
  DSA\MCP\Auth_Context::clear();$deny( static fn() => $intent->approve( $review['id'],$auth,true ),'An added reference invalidates native browser approval without requiring an AI bearer' );
  $check( DSA\MCP\Auth_Context::current() === null && get_post_status( $id ) === 'draft','Refused browser review creates no delegated authority and leaves the draft intact' );
  DSA\MCP\Auth_Context::establish( $auth,$bearer );delete_post_meta( $source,'_bricks_page_content_2' );
  $option = 'bricks_global_settings';$oldOption=get_option($option,null);update_option( $option,[ 'pageId'=>$id,'private'=>'SYNTHETIC-DO-NOT-EXPORT' ],false );$v = $audit->inspect( 'page',$id );
  $check( ! $v['cleanupEligible'] && $v['referenceMatches']['settings'] === 1 && ! str_contains( wp_json_encode( $v ),'SYNTHETIC-DO-NOT-EXPORT' ),'Native setting references protect content without exporting stored option values' );if($oldOption===null)delete_option($option);else update_option($option,$oldOption,false);
  wp_update_post( [ 'ID'=>$source,'post_content'=>'<a href="' . esc_url( get_permalink( $id ) ) . '">Fixture reference</a>' ] );$v = $audit->inspect( 'page',$id );
  $check( ! $v['cleanupEligible'] && $v['referenceMatches']['content'] > 0,'Native URL/content references protect the target' );
  wp_update_post( [ 'ID'=>$source,'post_content'=>'' ] );foreach ( wp_get_post_revisions( $source ) as $revision ) wp_delete_post( $revision->ID,true );
  $parent = $new( 'child' );wp_update_post( [ 'ID'=>$parent,'post_parent'=>$id ] );$check( ! $audit->inspect( 'page',$id )['cleanupEligible'],'Parent/child recovery relationships are protected' );wp_delete_post( $parent,true );
  $make_revision = static function( int $parent,string $name ) use ( $id ): int {
   $revision = wp_insert_post( [ 'post_type'=>'revision','post_status'=>'inherit','post_parent'=>$parent,'post_name'=>$parent . '-revision-' . $name,'post_title'=>'Synthetic native history','post_content'=>'{"pageId":' . $id . '}' ],true );
   if ( is_wp_error( $revision ) || (int) wp_is_post_revision( $revision ) !== $parent ) throw new RuntimeException( 'Native fixture revision could not be created.' );
   add_metadata( 'post',$revision,'_bricks_page_content_2',[ 'pageId'=>$id ] );
   return (int) $revision;
  };
  $ownRevision = $make_revision( $id,'own' );$foreignRevision = $make_revision( $source,'foreign' );
  $v = $audit->inspect( 'page',$id );$check( ! $v['cleanupEligible'] && $v['referenceMatches']['content'] === 1 && $v['referenceMatches']['metadata'] === 1,'References in a different record’s recovery revision still protect the target' );
  wp_delete_post_revision( $foreignRevision );
  $v = $audit->inspect( 'page',$id );$check( $v['cleanupEligible'] && $v['referenceMatches'] === [ 'content'=>0,'metadata'=>0,'settings'=>0 ],'The target’s own retained revision cannot masquerade as a dependency' );
  $checkout = get_option( 'woocommerce_checkout_page_id',null );update_option( 'woocommerce_checkout_page_id',$id,false );$check( ! $audit->inspect( 'page',$id )['cleanupEligible'],'Woo route protection remains native in this Woo/non-Woo cohort' );if ( $checkout === null ) delete_option( 'woocommerce_checkout_page_id' );else update_option( 'woocommerce_checkout_page_id',$checkout,false );
  $comment = wp_insert_comment( [ 'comment_post_ID'=>$id,'comment_author'=>'Synthetic native fixture','comment_content'=>'Synthetic feedback history','comment_approved'=>'1' ] );$check( ! $audit->inspect( 'page',$id )['cleanupEligible'],'Visitor feedback protects the content record' );wp_delete_comment( $comment,true );
  update_post_meta( $id,'_edit_lock',time() . ':999999' );$check( ! $audit->inspect( 'page',$id )['cleanupEligible'],'An actual native edit lock protects newer editing' );delete_post_meta( $id,'_edit_lock' );
  $meta = [];for ( $n = 0;$n < 26;$n++ ) $meta[] = add_post_meta( $source,'_bricks_page_content_2',[ 'postId'=>$id ] );$v = $audit->inspect( 'page',$id );
  $check( ! $v['complete'] && ! $v['cleanupEligible'],'Actual SQL match overflow cannot authorize cleanup' );foreach ( $meta as $mid ) delete_metadata_by_mid( 'post',$mid );
  if ( DSA\Access\Template_Workspace_Service::available() ) {
   $templateKey = defined( 'BRICKS_DB_TEMPLATE_SETTINGS' ) ? BRICKS_DB_TEMPLATE_SETTINGS : '_bricks_template_settings';$template = $new( 'template','bricks_template' );update_post_meta( $template,$templateKey,[ 'templateConditions'=>[ [ 'main'=>'any' ] ] ] );$check( ! $audit->inspect( 'bricks_template',$template )['cleanupEligible'],'Real Bricks conditions protect a draft template' );delete_post_meta( $template,$templateKey );
   wp_update_post( [ 'ID'=>$template,'post_status'=>'publish' ] );$check( ! $audit->inspect( 'bricks_template',$template )['cleanupEligible'],'Native published-template fallbacks stay protected' );
  }
  $queryFailure = static fn( $sql ) => str_contains( $sql,'SHA2(CONCAT_WS' ) ? 'SELECT missing_column FROM kiwe_m09_missing_' . $run : $sql;
  global $wpdb;$suppress = $wpdb->suppress_errors( true );add_filter( 'query',$queryFailure,PHP_INT_MAX - 1 );
  try { $deny( static fn() => $audit->inspect( 'page',$id ),'An actual SQL error refuses unused-content proof' ); } finally { remove_filter( 'query',$queryFailure,PHP_INT_MAX - 1 );$wpdb->suppress_errors( $suppress );$queryFailure = null; }
  $v = $audit->inspect( 'page',$id );$check( $v['cleanupEligible'],'Only the exact removed fixture references restore eligibility' );$args['expected'] = $v['expected'];
  $review = $intent->prepare( 'mcp.cleanup.change',$args,'m09-approved-' . $run );DSA\MCP\Auth_Context::clear();$intent->approve( $review['id'],$auth,true );DSA\MCP\Auth_Context::establish( $auth,$bearer );
  // All delegated content writers use this same mutex; no second cleanup-specific lock.
  $other = new wpdb( DB_USER,DB_PASSWORD,DB_NAME,DB_HOST );$lock = 'kiwe-mcp-author-' . substr( hash( 'sha256',$wpdb->prefix . '|content:page:' . $id ),0,36 );
  $check( (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)',$lock ) ) === '1','A second actual SQL connection holds the native authoring mutex' );
  try { $deny( static fn() => $audit->apply( $args,$v['expected'] ),'Reviewed cleanup cannot race another delegated content edit' ); } finally { $other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)',$lock ) );$other->close(); }
  add_post_meta( $source,'_bricks_page_header_2',[ 'pageId'=>$id ] );$deny( static fn() => $intent->apply( $review['id'] ),'A new reference after approval refuses the actual writer' );delete_post_meta( $source,'_bricks_page_header_2' );
  $saved = $intent->apply( $review['id'] );$check( $saved['state'] === 'complete' && get_post_status( $id ) === 'trash','The exact approved native owner moves only the selected fixture to recoverable Trash' );
  $fingerprint = DSA\Access\Trash_Workspace_Service::fingerprint( get_post( $id ),'page' );$again = $intent->apply( $review['id'] );$check( $again === $saved && DSA\Access\Trash_Workspace_Service::fingerprint( get_post( $id ),'page' ) === $fingerprint,'Completed repeats never repeat the native Trash mutation' );
  $v = $audit->inspect( 'page',$id );$restore = [ 'type'=>'page','id'=>$id,'action'=>'restore','expected'=>$v['expected'] ];$review = $intent->prepare( 'mcp.cleanup.change',$restore,'m09-restore-' . $run );DSA\MCP\Auth_Context::clear();$intent->approve( $review['id'],$auth,true );DSA\MCP\Auth_Context::establish( $auth,$bearer );
  $wouldPublish = static fn() => 'publish';add_filter( 'wp_untrash_post_status',$wouldPublish,100,2 );try { $out = $intent->apply( $review['id'] ); } finally { remove_filter( 'wp_untrash_post_status',$wouldPublish,100 ); }
  $check( $out['state'] === 'complete' && get_post_status( $id ) === 'draft','Native restore preserves the WordPress restored status without publication' );
  $check( (int) wp_is_post_revision( $ownRevision ) === $id && $audit->inspect( 'page',$id )['cleanupEligible'],'The native Trash and restore journey retains its own revision without making the draft ineligible' );
  $caps = DSA\MCP\Maintenance_Provider::capabilities();$check( ! $caps['objectCacheReset'] && ! $caps['runtimeOrSecurityStateCleanup'] && ! $caps['arbitraryDatabaseOrFileDeletion'],'Cache capabilities exclude private-state, database and authored-file cleanup' );
  $cache = [ 'layer'=>'page_cache','expected'=>$caps['expected'] ];
  if ( $mode === 'plain' ) {
   $check( ! function_exists( 'WC' ) && $caps['pageCache']['adapters'] === [],'Non-Woo/no-cache installation retains content audit without importing a commerce or cache dependency' );
   $deny( static fn() => $intent->prepare( 'mcp.maintenance.cache',$cache,'m09-no-cache-' . $run ),'No missing native cache adapter is invented' );
  } else {
  $review = $intent->prepare( 'mcp.maintenance.cache',$cache,'m09-cache-' . $run );$deny( static fn() => $intent->apply( $review['id'] ),'Native page-cache maintenance has a separate exact approval' );
  $adapters = array_column( $caps['pageCache']['adapters'],'id' );$check( $adapters === [ 'litespeed' ],'The isolated purge fixture is limited to the installed hook-based LiteSpeed adapter' );
  global $wp_filter;$litespeed = $wp_filter['litespeed_purge_all'] ?? null;$nativeHooksSaved = true;remove_all_actions( 'litespeed_purge_all' );$purges = 0;$hook = static function() use ( &$purges ): void { $purges++; };add_action( 'litespeed_purge_all',$hook );
  DSA\MCP\Auth_Context::clear();$intent->approve( $review['id'],$auth,true );DSA\MCP\Auth_Context::establish( $auth,$bearer );$out = $intent->apply( $review['id'] );
  $check( $out['state'] === 'complete' && $purges === 1 && json_decode( $out['result']['resultJson'],true )['confirmed'],'The exact native adapter callback confirms once, without real fixture I/O' );$intent->apply( $review['id'] );$check( $purges === 1,'Repeated cache apply consumes its receipt instead of re-purging' );
  remove_action( 'litespeed_purge_all',$hook );$hook = static function(): void { throw new RuntimeException( 'SYNTHETIC-PRIVATE-PROVIDER-ERROR' ); };add_action( 'litespeed_purge_all',$hook );
  $failed = $intent->prepare( 'mcp.maintenance.cache',$cache,'m09-cache-fail-' . $run );DSA\MCP\Auth_Context::clear();$intent->approve( $failed['id'],$auth,true );DSA\MCP\Auth_Context::establish( $auth,$bearer );$out = $intent->apply( $failed['id'] );
  $check( $out['state'] === 'needs-review' && ! str_contains( wp_json_encode( $out ),'SYNTHETIC-PRIVATE-PROVIDER-ERROR' ),'A native adapter failure needs readback and never exports its private error body' );
  }
  $hookDeny = static function( $caps,$cap,$user,$args ) use ( $id ) { return $cap === 'delete_post' && (int) ( $args[0] ?? 0 ) === $id ? [ 'do_not_allow' ] : $caps; };add_filter( 'map_meta_cap',$hookDeny,PHP_INT_MAX,4 );
  try { $deny( static fn() => $audit->inspect( 'page',$id ),'Current native object permission overrides an existing audit grant' ); } finally { remove_filter( 'map_meta_cap',$hookDeny,PHP_INT_MAX ); }
  $ledger( 'result',[ 'schema'=>'kiwe.mcp-maintenance-native.v1','mode'=>$mode,'recoverableTrash'=>true,'restoredStatus'=>get_post_status( $id ),'externalDelivery'=>false,'realCacheFixtureIo'=>false,'customerDataCopied'=>false ] );
 } finally {
  if ( $queryFailure ) remove_filter( 'query',$queryFailure,PHP_INT_MAX - 1 );
  if ( $nativeHooksSaved ) { remove_all_actions( 'litespeed_purge_all' );if ( $litespeed ) $GLOBALS['wp_filter']['litespeed_purge_all'] = $litespeed; }
  DSA\MCP\Auth_Context::clear();if ( $active ) { $closed = $sandbox->close();$check( $closed['remaining'] === 0,'Every owned native fixture schema is retired' ); }
  wp_set_current_user( 0 );wp_set_current_user( $actor );
  $check( get_option( DSA_OPTION_SETTINGS ) === $settings && $style_state() === $style && $signature() === $original,'All real content, metadata, roles, comments, settings and styles remain exact after fixture recovery' );
 }
 return [ 'passed'=>true,'mode'=>$mode,'nativeSql'=>true,'customersCopied'=>false ];
}
