<?php
/** Run only in an owned fresh M10 WordPress database. The driver owns setup and teardown. */
function kiwe_mcp_m10_transport_checks( callable $check,bool $woo ): array {
 $host = $woo ? 'https://kiwe-m10-commerce.example.invalid' : 'https://kiwe-m10-plain.example.invalid';
 if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_M10_DISPOSABLE' ) || KIWE_M10_DISPOSABLE !== true || home_url() !== $host || ! str_starts_with( DB_NAME,'kiwe_m10_' ) || ! str_starts_with( DB_HOST,'127.0.0.1:' ) ) {
  throw new RuntimeException( 'An owned local M10 WordPress database is required.' );
 }
 global $wpdb;
 $owner = DSA\Access\Site_Owner_Service::owner_id();
 wp_set_current_user( $owner );
 $check( current_user_can( 'manage_options' ) && DSA\Apps\MCP_App_Service::enabled(),'Native owner can use the installed optional MCP App' );
 $check( function_exists( 'WC' ) === $woo && DSA\Apps\Store_App_Service::enabled() === $woo,'The cohort uses its actual Store and WooCommerce capability' );
 $check( ! DSA\Apps\Package_Layout::bundled( 'mcp' ) && class_exists( DSA\MCP\Vendor\WP\MCP\Core\McpAdapter::class,false ),'The maintained adapter is loaded on the owned MCP route from the optional App' );
 $guidance = new DSA\MCP\Guidance_Service();
 $initial = $guidance->orientation();
 $expect_starter = getenv( 'KIWE_M10_APP_VERSION' ) !== '1.0.0-rc.20';
 if ( $woo ) {
  foreach ( [ 'shop','cart','checkout','myaccount' ] as $route ) {
   $page = get_post( wc_get_page_id( $route ) );
   $check( $page instanceof WP_Post && $page->post_type === 'page' && $page->post_status === 'publish','WooCommerce creates its own native ' . $route . ' page during installation' );
  }
 }
 $check( $initial['state'] === ( $expect_starter && ! $woo ? 'fresh' : 'existing' ) && $initial['inventoryComplete'] && $initial['storeAvailable'] === $woo,'Native onboarding reads the default WordPress installation and the actual Store dependency' );
 if ( ! $woo ) {
  $starter = get_posts( [ 'post_type'=>[ 'post','page' ],'post_status'=>'any','posts_per_page'=>5 ] );
  $starter_slugs = [ 'hello-world','sample-page','privacy-policy' ];
  $check( count( $starter ) > 0 && ! array_diff( array_column( $starter,'post_name' ),$starter_slugs ),'Only the owned WordPress starter records enter the empty-install cohort' );
  if ( $expect_starter ) {
   $privacy = get_post( (int) get_option( 'wp_page_for_privacy_policy' ) );
   $edited = wp_update_post( [ 'ID'=>$privacy->ID,'post_content'=>'A saved privacy draft for the actual business.' ],true );
   $check( ! is_wp_error( $edited ) && (int) get_option( 'fresh_site' ) === 1 && $guidance->orientation()['state'] === 'existing','An authored edit to the native starter draft still counts as existing work' );
  }
  foreach ( $starter as $post ) wp_delete_post( $post->ID,true );
  $empty = $guidance->orientation();
  $check( $empty['state'] === 'fresh' && $empty['inventoryComplete'] && ! $empty['storeAvailable'],'A truly empty native installation receives the fresh non-commerce journey' );
  $new = wp_insert_post( [ 'post_title'=>'M10 native authored draft','post_type'=>'post','post_status'=>'draft' ],true );
  $check( ! is_wp_error( $new ) && $guidance->orientation()['state'] === 'existing','Native authored content changes the orientation without an extra setup flag' );
 } else {
  $product = new WC_Product_Simple();
  $product->set_name( 'M10 native starter product' );$product->set_status( 'draft' );
  $check( $product->save() > 0 && $guidance->orientation()['state'] === 'existing','A real native WooCommerce product changes the new-site orientation' );
 }

 $registry = new DSA\MCP\Operation_Registry();
 DSA\MCP\Native_Provider::register( $registry );
 $auth = new DSA\MCP\Authorization_Service( $registry );
 $secret = static fn(): string => DSA\MCP\Credentials::secret();
 $denied = static function( callable $run ): bool { try { $run(); } catch ( DSA\MCP\OAuth_Failure $e ) { return true; }return false; };
 $connect = static function( string $label,int $actor ) use ( $auth,$secret,$denied,$check ): array {
  wp_set_current_user( $actor );
  $client = $auth->register_client( [ 'client_name'=>$label,'redirect_uris'=>[ 'http://127.0.0.1/callback' ] ] )['client_id'];
  $browser = $secret();$verifier = $secret();
  $challenge = rtrim( strtr( base64_encode( hash( 'sha256',$verifier,true ) ),'+/','-_' ),'=' );
  $params = [ 'client_id'=>$client,'redirect_uri'=>'http://127.0.0.1:19645/callback','response_type'=>'code','code_challenge_method'=>'S256','code_challenge'=>$challenge,'state'=>'native-test-only','resource'=>DSA\MCP\Urls::resource(),'scope'=>'mcp.read mcp.site.read mcp.context.read mcp.apps.read mcp.content.read offline_access' ];
  $flow = $auth->begin( $params,$browser );$review = $auth->review( $flow,$browser,$actor );
  $initial = $auth->approve( $flow,$browser,$actor,true,true,$review['reviewDigest'] );
  $repeat = $auth->approve( $flow,$browser,$actor,true,true,$review['reviewDigest'] );
  $check( $initial === $repeat && DSA\MCP\Records::count( 'code',$actor ) === 1,'MariaDB stores one code after the repeated native consent for ' . $label );
  $check( $denied( static fn() => $auth->approve( $flow,$secret(),$actor,true,true,$review['reviewDigest'] ) ),'A different browser cannot repeat the consent for ' . $label );
  $exchange = [ 'grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>$params['redirect_uri'],'code_verifier'=>$verifier,'code'=>$initial['code'],'resource'=>$params['resource'] ];
  $tokens = $auth->exchange( $exchange );
  $done = $auth->approve( $flow,$browser,$actor,true,true,$review['reviewDigest'] );
  $check( ! empty( $done['alreadySent'] ) && ! isset( $done['code'] ) && $denied( static fn() => $auth->exchange( $exchange ) ),'The redeemed native code remains single-use for ' . $label );
  return [ 'access'=>$tokens['access_token'],'refresh'=>$tokens['refresh_token'],'grant'=>$auth->authenticate( $tokens['access_token'] ) ];
 };
 $editor = wp_insert_user( [ 'user_login'=>'m10_fixture_editor','user_email'=>'editor@example.invalid','user_pass'=>wp_generate_password( 32 ),'role'=>'editor' ] );
 if ( is_wp_error( $editor ) ) throw new RuntimeException( 'Native editor fixture could not be created.' );
 $a = $connect( 'primary',$owner );$b = $connect( 'second client',$owner );$other = $connect( 'other actor',(int) $editor );

 $send = static function( string $access,string $method,?array $packet=null,string $version='',string $session='' ): WP_REST_Response {
  // rest_do_request bypasses WordPress's top-level REST authentication hook.
  DSA\MCP\Auth_Context::clear();wp_set_current_user( 0 );
  $request = new WP_REST_Request( $method,'/kiwe-mcp/v1/mcp' );
  $request->set_body( '' ); // The native HTTP server supplies an empty string even for bodyless requests.
  if ( $access !== '' ) $request->set_header( 'Authorization','Bearer ' . $access );
  if ( $version !== '' ) $request->set_header( 'MCP-Protocol-Version',$version );
  if ( $session !== '' ) $request->set_header( 'Mcp-Session-Id',$session );
  if ( $packet !== null ) {
   $request->set_header( 'Accept','application/json, text/event-stream' );$request->set_header( 'Content-Type','application/json' );
   if ( $version === '2026-07-28' ) {
    $request->set_header( 'Mcp-Method',$packet['method'] );
    if ( isset( $packet['params']['name'] ) ) $request->set_header( 'Mcp-Name',$packet['params']['name'] );
   }
   $request->set_body( wp_json_encode( [ 'jsonrpc'=>'2.0','id'=>1 ] + $packet ) );
  }
  try { return rest_do_request( $request ); }
  finally { DSA\MCP\Auth_Context::clear();wp_set_current_user( 0 ); }
 };
 $request_init = static fn( string $access,string $version = '2025-11-25' ): WP_REST_Response => $send( $access,'POST',[ 'method'=>'initialize','params'=>[ 'protocolVersion'=>$version,'clientInfo'=>[ 'name'=>'Kiwe fixture','version'=>'1' ],'capabilities'=>(object) [] ] ] );
 $new_session = static function( string $access,string $version = '2025-11-25' ) use ( $request_init ): string {
  $response = $request_init( $access,$version );$handle = $response->get_headers()['Mcp-Session-Id'] ?? '';
  // The adapter returns nested schema records. Read the same JSON projection as an HTTP client.
  $data = json_decode( wp_json_encode( $response->get_data() ),true );
  $negotiated = $data['result']['protocolVersion'] ?? '';
  if ( $response->get_status() !== 200 || ! is_string( $handle ) || $handle === '' || $negotiated !== $version ) {
   throw new RuntimeException( sprintf( 'Native legacy initialization expected %s, received HTTP %d, protocol %s, handle %s.',$version,$response->get_status(),$negotiated,$handle === '' ? 'absent' : 'present' ) );
  }
  return $handle;
 };
 $list = static fn( string $access,string $handle,string $version = '2025-11-25' ): WP_REST_Response => $send( $access,'POST',[ 'method'=>'tools/list' ],$version,$handle );
 $native_sessions = static function( int $actor ): array {
  wp_cache_delete( $actor,'user_meta' );return DSA\MCP\Vendor\WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $actor );
 };
 $binding = static fn( string $handle ): ?array => DSA\MCP\Records::get( 'session','s_' . hash( 'sha256',$handle ) );

 wp_set_current_user( $owner );rest_get_server()->check_authentication();
 $check( get_current_user_id() === 0,'Top-level WordPress REST auth discards even an owner cookie on the exact bearer resource' );
 $anonymous = $list( '', '' );$anonymous_data = (array) $anonymous->get_data();
 $check( $anonymous->get_status() === 401,'Anonymous requests receive the native OAuth challenge (HTTP ' . $anonymous->get_status() . ', ' . ( $anonymous_data['error'] ?? $anonymous_data['code'] ?? 'no error code' ) . ')' );
 $other_handle = $new_session( $other['access'],'2025-06-18' );$other_native = $native_sessions( (int) $editor );$other_bound = $binding( $other_handle );
 $handles = [];for ( $index = 0; $index < 32; ++$index ) $handles[] = $new_session( $a['access'] );
 $check( count( array_unique( $handles ) ) === 32 && count( $native_sessions( $owner ) ) === 32 && DSA\MCP\Records::count( 'session',$owner ) === 32,'The real legacy allocator and binding table reach their agreed capacity' );
 $lost = $handles[0];
 $check( DSA\MCP\Vendor\WP\MCP\Transport\Infrastructure\SessionManager::delete_session( $owner,$lost ) && $binding( $lost ) !== null,'The old mismatch is reproduced with an orphaned Kiwe binding' );
 $replacement = $new_session( $a['access'] );
 $check( $binding( $lost ) === null && $binding( $replacement ) !== null && DSA\MCP\Records::count( 'session',$owner ) === 32,'A new client can initialize at the old limit by retiring only missing bindings' );
 $next = $new_session( $b['access'] );
 $owner_map = $native_sessions( $owner );
 $check( count( $owner_map ) === 32 && DSA\MCP\Records::count( 'session',$owner ) === 32 && isset( $owner_map[$next] ),'Native capacity rollover accepts the new handle without mirror drift' );
 $check( $list( $a['access'],$lost )->get_status() === 404 && $list( $a['access'],$next )->get_status() === 404 && $list( $other['access'],$next,'2025-06-18' )->get_status() === 404,'Missing and cross-grant or cross-actor handles are refused' );
 $check( $list( $b['access'],$next )->get_status() === 200 && $native_sessions( (int) $editor ) === $other_native && $binding( $other_handle ) === $other_bound,'The admitted legacy session works and the other actor is preserved' );
 $check( $binding( $next )['expiresAt'] === DSA\MCP\Records::get( 'grant',$b['grant']['id'] )['expiresAt'],'The native handle binding expires with the approved grant' );

 $mutex = 'kiwe-mcp-session-' . substr( hash( 'sha256',DSA\MCP\Records::table() . '|' . $owner ),0,32 );
 $peer = new wpdb( DB_USER,DB_PASSWORD,DB_NAME,DB_HOST );
 $check( (string) $peer->get_var( $peer->prepare( 'SELECT GET_LOCK(%s,0)',$mutex ) ) === '1','A second real database connection holds the actor session lock' );
 try {
  $busy = $request_init( $a['access'] );
  $check( $busy->get_status() === 503 && ( $busy->get_headers()['Retry-After'] ?? '' ) === '1' && $native_sessions( $owner ) === $owner_map,'A concurrent initializer returns bounded retry before it can evict a native handle' );
 } finally { $peer->get_var( $peer->prepare( 'SELECT RELEASE_LOCK(%s)',$mutex ) );$peer->close(); }

 $failed_write = static function( string $sql ): string {
  if ( preg_match( '/^REPLACE INTO .*kiwe_mcp_records.*\x27session\x27/i',$sql ) ) return 'SELECT m10_expected_missing_column FROM m10_expected_missing_table';
  return $sql;
 };
 $suppress = $wpdb->suppress_errors( true );add_filter( 'query',$failed_write,PHP_INT_MAX - 1 );
 try { $failure = $request_init( $a['access'] ); }
 finally { remove_filter( 'query',$failed_write,PHP_INT_MAX - 1 );$wpdb->suppress_errors( $suppress ); }
 $check( $failure->get_status() === 503 && ! isset( $failure->get_headers()['Mcp-Session-Id'] ) && $native_sessions( $owner ) === $owner_map && DSA\MCP\Records::count( 'session',$owner ) === 32,'A failed binding write rolls back native capacity rollover and exposes no handle' );

 $modern = $send( $a['access'],'POST',[ 'method'=>'server/discover','params'=>[ '_meta'=>[
  'io.modelcontextprotocol/protocolVersion'=>'2026-07-28',
  'io.modelcontextprotocol/clientCapabilities'=>(object) [],
  'io.modelcontextprotocol/clientInfo'=>[ 'name'=>'Kiwe stateless fixture','version'=>'1' ],
 ] ] ],'2026-07-28' );
 $check( $modern->get_status() === 200 && ! isset( $modern->get_headers()['Mcp-Session-Id'] ) && $native_sessions( $owner ) === $owner_map && DSA\MCP\Records::count( 'session',$owner ) === 32,'The current stateless revision serves discovery without compatibility allocation' );
 $terminated = $send( $b['access'],'DELETE',null,'2025-11-25',$next );
 $check( $terminated->get_status() === 200,'Standard client termination succeeds (HTTP ' . $terminated->get_status() . ')' );
 $check( $binding( $next ) === null && ! isset( $native_sessions( $owner )[$next] ),'Standard client termination closes both the native handle and its binding' );

 wp_set_current_user( $owner );
 $auth->revoke_own( $a['grant']['id'],$owner );
 $check( $list( $a['access'],$replacement )->get_status() === 401,'A retained legacy session cannot survive native grant revocation' );
 wp_set_current_user( $owner );
 $check( DSA\Apps\MCP_App_Service::change( false,DSA\Apps\MCP_App_Service::expected() ) === true && $list( $b['access'],$replacement )->get_status() === 503,'The native App switch immediately withdraws authenticated MCP service' );
 wp_set_current_user( $owner );
 $check( DSA\Apps\MCP_App_Service::change( true,DSA\Apps\MCP_App_Service::expected() ) === true && $list( $b['access'],$replacement )->get_status() === 401,'App re-enable does not revive a previous connection' );
 return [ 'passed'=>true,'legacySessionCapacity'=>32,'protocols'=>[ '2025-06-18','2025-11-25','2026-07-28' ],'store'=>$woo ];
}
