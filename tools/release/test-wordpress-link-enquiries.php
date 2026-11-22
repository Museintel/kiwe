<?php
/** CLI-only caller supplies a private immutable ledger writer and an isolated native lab. */
function kiwe_links_lk2_native_tests( callable $check, callable $ledger, string $run, string $mode, bool $installed = false ): array {
	global $wpdb;
	$users = []; $keys = []; $notice_count = 0; $cleanup = false; $primary_error = null;
	$native_worker = null;
	if ( $installed ) foreach ( ( $GLOBALS['wp_filter']['kiwe_link_enquiry_saved']->callbacks ?? [] ) as $callbacks ) foreach ( $callbacks as $callback ) if ( is_array( $callback['function'] ) && ( $callback['function'][0] ?? null ) instanceof \DSA\Notifications\Admin_Event_Notification_Service ) $native_worker = $callback['function'][0];
	$prefix = 'kiwe_lk2_' . $run . '_' . $mode;
	$protected = static function () use ( $wpdb ): array {
		$names = [ DSA_OPTION_SETTINGS, \DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE, \DSA\Site\Site_Identity_Service::OPTION_STORE_PHONE, \DSA\Site\Site_Identity_Service::OPTION_STORE_EMAIL, \DSA\Access\Site_Owner_Service::OPTION, 'active_plugins','template','stylesheet','kiwe_store_app_enabled_v1','kiwe_seo_app_enabled_v1' ];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ') ORDER BY option_name', $names ), ARRAY_A );
		if ( $wpdb->last_error ) throw new RuntimeException( 'Protected control capture failed' );
		return [ 'controls'=>hash( 'sha256', serialize( $rows ) ), 'config'=>hash_file( 'sha256', ABSPATH . 'wp-config.php' ), 'phonekey'=>hash_file( 'sha256', DSA_DIR . '/includes/PhoneKey/phonekey-core.php' ), 'core'=>hash_file( 'sha256', ABSPATH . 'wp-content/mu-plugins/dsa.php' ) ];
	};
	$before = $protected();
	$check( '03ebbf97a5135a5f66ead3ea8aaafa54848edcfb0af2b92bd8c4687f493d46d0' === $before['phonekey'], 'Frozen native PhoneKey engine' );
	// No production/fixture notification delivery and no provider requests in this process.
	$network = 0; $block_http = static function () use ( &$network ) { $network++; return new WP_Error( 'kiwe_qa_no_network', 'Native QA does not contact providers.' ); };
	add_filter( 'pre_http_request', $block_http, PHP_INT_MAX ); add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	remove_all_actions( 'user_register' );
	if ( $installed ) remove_all_actions( 'kiwe_link_enquiry_saved' ); // Worker qualification is separately fixture-scoped.
	$spy = static function ( $id ) use ( &$notice_count ) { $notice_count++; };
	add_action( 'kiwe_link_enquiry_saved', $spy );
	$rate_hashes = []; $ip = 'normal' === $mode ? '192.0.2.91' : '192.0.2.92'; $_SERVER['REMOTE_ADDR'] = $ip;
	$check( \DSA\Utilities\Origin_Checker::client_ip() === $ip, 'Synthetic documentation IP scopes native rate limits' );
	foreach ( [ 'prepare','submit' ] as $kind ) {
		$hash = hash_hmac( 'sha256', 'link-enquiry|' . get_current_blog_id() . '|' . $kind . '|' . $ip, wp_salt( 'nonce' ) );
		$check( ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}dsa_rate_limits WHERE bucket_hash=%s", $hash ) ), 'No pre-existing QA rate bucket' );
		$found = false; wp_cache_get( 'bucket_' . $hash, 'dsa_rate_limits', false, $found ); $check( ! $found, 'No pre-existing QA object-cache bucket' );
		$rate_hashes[] = $hash;
	}
	try {
		$check( ( $installed ? '8.0.0-rc.470' : '8.0.0-rc.467' ) === DSA_VERSION, $installed ? 'Exact installed Links candidate' : 'Accepted installed Core retained; private source model only' );
		if ( 'plain' === $mode ) $check( ! function_exists( 'WC' ) && ! class_exists( 'Bricks\\Elements', false ), 'Actual WordPress without Woo or Bricks' );
		$check( post_type_exists( \DSA\Link_Hub\Enquiry_Store::TYPE ) === $installed, $installed ? 'Installed native private type is registered by Core' : 'Reserved enquiry type not installed before this process' );
		\DSA\Link_Hub\Enquiry_Store::register();
		$type = get_post_type_object( \DSA\Link_Hub\Enquiry_Store::TYPE );
		$check( ! $type->public && ! $type->publicly_queryable && ! $type->show_in_rest && ! $type->can_export && ! post_type_supports( $type->name, 'editor' ), 'Native private/unexportable type has no generic editor' );
		foreach ( [ 'admin'=>'administrator','subscriber'=>'subscriber' ] as $kind=>$role ) {
			$login = $prefix . '_' . $kind;
			$check( ! username_exists( $login ), 'Fixture account name is unused' );
			$ledger( 'user-' . $mode . '-' . $kind, [ 'login'=>$login ] );
			$id = wp_insert_user( [ 'user_login'=>$login, 'user_email'=>$login . '@example.invalid', 'user_pass'=>wp_generate_password( 48, true, true ), 'role'=>$role ] );
			if ( is_wp_error( $id ) ) throw new RuntimeException( 'Native fixture account refused' ); $users[$kind] = (int) $id;
		}
		$request = static function ( string $method, array $body = [], array $params = [] ): WP_REST_Request {
			$r = new WP_REST_Request( $method, '/dsa/v1/links/enquiry' );
			$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); $r->set_header( 'X-Kiwe-Mutation', '1' );
			if ( 'GET' === $method ) $r->set_query_params( $body ); else { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
			if ( $params ) $r->set_url_params( $params ); return $r;
		};
		$controller = new \DSA\Link_Hub\Enquiry_Controller(); wp_set_current_user( 0 );
		$prepared = $controller->prepare( $request( 'GET', [ 'channel'=>'email' ] ) ); $config = $prepared->get_data();
		$check( 200 === $prepared->get_status() && true === $config['ok'] && str_contains( $prepared->get_headers()['Cache-Control'], 'no-store' ), 'Actual guest preflight is private and uncacheable' );
		$check( [ 'name'=>'','contact'=>'' ] === $config['prefill'], 'Actual guest has no account prefill' );
		$body = [ 'channel'=>'email', 'ticket'=>$config['ticket'], 'name'=>'Synthetic & Enquirer', 'contact'=>$prefix . '@example.invalid', 'message'=>'Synthetic native enquiry ' . $run . "\nURL https://example.invalid/a%20b; literal <script> & नमस्ते", 'subjectId'=>0, 'website'=>'' ];
		$input = \DSA\Link_Hub\Enquiry_Service::input( $body ); $check( ! is_wp_error( $input ), 'Native plain-text/Unicode input accepted' );
		$keys[] = $input['key']; $ledger( 'intent-' . $mode . '-guest', [ 'key'=>$input['key'] ] );
		$result = $controller->submit( $request( 'POST', $body ) ); $value = $result->get_data();
		$check( 200 === $result->get_status() && true === $value['saved'] && false === $value['handoff']['sent'], 'Actual guest saves before a non-sending handoff plan' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_name=%s", $type->name, 'kwe-' . $input['key'] ) );
		$row = get_post( $id ); $record = \DSA\Link_Hub\Enquiry_Store::record( $id );
		$check( $row && 0 === (int) $row->post_author && 'private' === $row->post_status && $record['value']['message'] === $body['message'], 'Native insert roundtrips literal/Unicode message with unowned private storage' );
		$again = $controller->submit( $request( 'POST', $body ) );
		$check( 200 === $again->get_status() && 1 === $notice_count && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_name=%s", $type->name, 'kwe-' . $input['key'] ) ), 'Native duplicate retry is one record and one arrival signal' );
		$forms = new \DSA\Access\Form_Workspace_Service();
		$check( 403 === $forms->enquiry_detail( $request( 'GET', [], [ 'id'=>$id ] ) )->get_status(), 'Guest cannot read even its guessed private record ID' );
		wp_set_current_user( $users['subscriber'] );
		$check( 403 === $forms->enquiry_detail( $request( 'GET', [], [ 'id'=>$id ] ) )->get_status(), 'Native subscriber cannot read enquiries' );
		wp_set_current_user( $users['admin'] );
		$check( \DSA\Access\Form_Workspace_Service::allowed(), 'Native client administrator keeps Forms authority' );
		$check( ! current_user_can( 'read_post', $id ) && ! \DSA\Utilities\Content_Visibility::is_public( $row ), 'Administrator/session authority never makes enquiry public or generically readable' );
		$list = $forms->listing( $request( 'GET', [ 'view'=>'all','form'=>\DSA\Link_Hub\Enquiry_Service::FORM ] ) ); $listing = $list->get_data();
		$check( 200 === $list->get_status() && count( $listing['items'] ) >= 1 && in_array( $id, array_column( $listing['items'], 'id' ), true ), 'Actual SQL joins Core enquiries into existing Forms inbox' );
		$detail = $forms->enquiry_detail( $request( 'GET', [], [ 'id'=>$id ] ) );
		$check( 200 === $detail->get_status() && 'links' === $detail->get_data()['item']['source'] && count( $detail->get_data()['item']['fields'] ) === 5, 'Authorized source-qualified detail retains contact and message' );
		$snapshot = \DSA\Access\Workspace_Activity_Service::snapshots()['forms'] ?? [];
		$check( ( $snapshot['coreCeiling'] ?? 0 ) >= $id && ( $snapshot['count'] ?? 0 ) > 0, 'Actual unread snapshot has independent Core ceiling' );
		$visit = ( new \DSA\Access\Workspace_Activity_Service() )->visit( $request( 'POST', $snapshot ) );
		$check( 200 === $visit->get_status(), 'Actual native signed Forms visit acknowledged' );
		$own = $controller->prepare( $request( 'GET', [ 'channel'=>'email' ] ) )->get_data();
		$check( get_userdata( get_current_user_id() )->user_email === $own['prefill']['contact'], 'Unverified native session may reuse its own contact without proof promotion' );
		if ( $installed ) {
			$fixture_email = get_userdata( $users['admin'] )->user_email;
			update_user_meta( $users['admin'], 'first_name', 'Synthetic' ); update_user_meta( $users['admin'], 'last_name', 'Verified' );
			pk_upsert_factor( $users['admin'], 'email', 'verified', pk_hmac( $fixture_email ), [], $fixture_email );
			$prefill = $controller->prepare( $request( 'GET', [ 'channel'=>'email' ] ) )->get_data();
			$check( [ 'name'=>'Synthetic Verified','contact'=>$fixture_email ] === $prefill['prefill'], 'Actual native verified own factor prefills only this session' );
			$typed = \DSA\Link_Hub\Enquiry_Service::input( [ ...$body, 'ticket'=>$prefill['ticket'] ] );
			$check( ! is_wp_error( $typed ) && false === $typed['verifiedContact'], 'Entering another contact never borrows the native session verification' );
			$public = \DSA\Link_Hub\Share_Service::document( 0 );
			$check( $public['subject']['id'] === 0 && str_starts_with( $public['links']['whatsapp'], 'https://wa.me/?text=' ) && str_starts_with( $public['links']['email'], 'mailto:?subject=' ) && ! $public['sent'], 'Installed share owner separates public recipient chooser from business enquiries' );
			$discovery = \DSA\Link_Hub\Channel_Service::discovery();
			$check( $discovery['attributes']['enquiry']['available'] && $discovery['attributes']['share']['available'] && in_array( 'email', $discovery['configuredEnquiries'], true ) && ! str_contains( wp_json_encode( $discovery ), $fixture_email ), 'Installed read-only authoring discovery has capabilities without fixture identity' );
		}
		$admin_body = [ ...$body, 'ticket'=>$own['ticket'], 'message'=>'Synthetic administrator enquiry ' . $run ];
		$admin_input = \DSA\Link_Hub\Enquiry_Service::input( $admin_body ); $keys[] = $admin_input['key']; $ledger( 'intent-' . $mode . '-admin', [ 'key'=>$admin_input['key'] ] );
		$saved = \DSA\Link_Hub\Enquiry_Store::save( $admin_input );
		$check( ! is_wp_error( $saved ) && 0 === (int) get_post( $saved['id'] )->post_author, 'Actual logged-in insert overrides WordPress zero-author substitution' );
		$check( ( \DSA\Access\Workspace_Activity_Service::snapshots()['forms']['count'] ?? 0 ) > 0, 'New enquiry after visit remains unread' );
		if ( $installed ) {
			$check( null !== $native_worker, 'Actual installed new-enquiry source listener exists' );
			$reflection = new ReflectionClass( $native_worker ); $worker = $reflection->newInstanceWithoutConstructor();
			$real_preferences = $reflection->getProperty( 'preferences' )->getValue( $native_worker );
			$scoped_preferences = new class( $real_preferences, array_values( $users ) ) {
				public function __construct( public object $native, public array $fixtures ) {}
				public function enabled() { return $this->native->enabled(); }
				public function personal_topic( $topic ) { return $this->native->personal_topic( $topic ); }
				public function inbox_user_ids_for_topic( $topic, $explicit = [] ) { return $this->native->inbox_user_ids_for_topic( $topic, $this->fixtures ); }
				public function audience_user_ids_for_topic( ...$args ) { return []; } // Deliberate external-delivery I/O boundary.
			};
			$reflection->getProperty( 'preferences' )->setValue( $worker, $scoped_preferences );
			$worker->dispatch( 'link_enquiry', $id ); $worker->dispatch( 'link_enquiry', $id );
			$admin_inbox = get_user_meta( $users['admin'], 'dsa_admin_notification_inbox', true );
			$subscriber_inbox = get_user_meta( $users['subscriber'], 'dsa_admin_notification_inbox', true );
			$matching = array_values( array_filter( is_array( $admin_inbox ) ? $admin_inbox : [], static fn( $item ) => ( $item['id'] ?? '' ) === 'link-enquiry-' . $id ) );
			$subscriber_matching = array_values( array_filter( is_array( $subscriber_inbox ) ? $subscriber_inbox : [], static fn( $item ) => ( $item['id'] ?? '' ) === 'link-enquiry-' . $id ) );
			$ledger( 'worker-scope-' . $mode,[ 'adminMatches'=>count( $matching ),'subscriberMatches'=>count( $subscriber_matching ),'recordExists'=>null !== \DSA\Link_Hub\Enquiry_Store::record( $id ),'nativeRecipients'=>$real_preferences->inbox_user_ids_for_topic( 'admin_form_submission',array_values( $users ) ),'adminAllowed'=>\DSA\Access\Form_Workspace_Service::allowed_for( $users['admin'] ) ] );
			$check( 1 === count( $matching ) && ! $subscriber_matching, 'Actual dispatch and native topic authority deduplicate the fixture administrator inbox and deny subscriber' );
			$notice = $matching[0];
			$check( str_contains( $notice['actionUrl'], 'submission_source=links' ) && str_contains( $notice['actionUrl'], 'submission=' . $id ) && ! str_contains( wp_json_encode( $notice ), $body['contact'] ) && ! str_contains( wp_json_encode( $notice ), 'Synthetic & Enquirer' ), 'Native backend/frontend inbox notice uses typed focus and no private answers' );
		}
		$export = \DSA\Link_Hub\Enquiry_Privacy::export( $body['contact'] );
		$check( ! is_wp_error( $export ) && count( $export['data'] ) === 2 && ! str_contains( wp_json_encode( $export ), '<script>' ), 'Actual native JSON privacy query exports only matching bounded plain-text enquiries' );
		$erased = \DSA\Link_Hub\Enquiry_Privacy::erase( $body['contact'] );
		$check( ! is_wp_error( $erased ) && $erased['done'] && ! get_post( $id ) && ! get_post( $saved['id'] ), 'Actual native privacy erasure confirms removal' );
		$check( 0 === $network, 'Enquiry and inbox models contact no provider' );
	} catch ( Throwable $error ) { $primary_error = $error; }
	finally {
		wp_set_current_user( 0 );
		foreach ( $keys as $key ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_name=%s AND post_author=0", \DSA\Link_Hub\Enquiry_Store::TYPE, 'kwe-' . $key ) );
			foreach ( $ids as $id ) if ( is_wp_error( \DSA\Link_Hub\Enquiry_Store::remove( (int) $id ) ) ) throw new RuntimeException( 'Fixture enquiry retirement refused' );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $users as $id ) { $user = get_userdata( $id ); if ( ! $user || ! str_starts_with( $user->user_login, $prefix . '_' ) || ! str_ends_with( $user->user_email, '@example.invalid' ) ) throw new RuntimeException( 'Fixture account ownership mismatch' ); if ( $installed ) $wpdb->delete( pk_t( 'factors' ), [ 'user_id'=>$id ] ); if ( ! wp_delete_user( $id ) || get_userdata( $id ) ) throw new RuntimeException( 'Fixture account retirement failed' ); }
		foreach ( $rate_hashes as $hash ) { $wpdb->delete( $wpdb->prefix . 'dsa_rate_limits', [ 'bucket_hash'=>$hash ] ); wp_cache_delete( 'bucket_' . $hash, 'dsa_rate_limits' ); }
		remove_action( 'kiwe_link_enquiry_saved', $spy );
		$cleanup = true; $check( $protected() === $before, 'Original settings, apps, WordPress configuration, Core and PhoneKey bytes unchanged' );
		$ledger( 'fixture-cleanup-' . $mode, [ 'passed'=>true ] );
	}
	if ( $primary_error ) throw $primary_error;
	return [ 'cleanup'=>$cleanup, 'php'=>PHP_VERSION, 'wordpress'=>$GLOBALS['wp_version'], 'wooLoaded'=>function_exists( 'WC' ), 'bricksLoaded'=>class_exists( 'Bricks\\Elements', false ) ];
}
