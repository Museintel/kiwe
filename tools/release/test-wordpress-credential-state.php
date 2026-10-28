<?php
/** CLI-only actual WordPress/SQL/crypto fixture; the established temporary-table owner is mandatory. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_SEO_NATIVE_TEST' ) || true !== KIWE_SEO_NATIVE_TEST || empty( $GLOBALS['kiwe_seo_test_tables'] ) || ! function_exists( 'kiwe_pt2_authenticator' ) ) throw new RuntimeException( 'Owned native credential fixture required' );

final class Kiwe_PT2_Die extends RuntimeException {}

function kiwe_pt2_native_run(): array {
	global $wpdb;
	$proof = [ 'passed' => false, 'cleanup' => false, 'checks' => [] ];
	$check = static function ( $ok, string $label ) use ( &$proof ): void { $proof['checks'][$label] = (bool) $ok; if ( ! $ok ) throw new RuntimeException( $label ); };
	$request = static function ( array $params = [], ?string $token = null ): WP_REST_Request {
		$r = new WP_REST_Request( 'POST' ); foreach ( $params as $key => $value ) $r->set_param( $key, $value );
		if ( null !== $token ) $r->set_header( 'authorization', 'Bearer ' . $token );
		return $r;
	};
	$filters = []; $savedPost = $_POST; $savedRequest = $_REQUEST; $savedSuppress = $wpdb->suppress_errors;
	$barrier = static function ( callable $operation, string $needle ) use ( &$filters, $check ): array {
		$pause = true;
		$filter = static function ( $sql ) use ( &$pause, $needle ) {
			if ( $pause && Fiber::getCurrent() && str_starts_with( ltrim( $sql ), 'UPDATE ' ) && str_contains( $sql, $needle ) ) { $pause = false; Fiber::suspend( 'native_credential_claim' ); }
			return $sql;
		};
		add_filter( 'query', $filter, 50 ); $filters[] = [ 'query', $filter, 50 ];
		$fiber = new Fiber( $operation ); $check( $fiber->start() === 'native_credential_claim', 'Native SQL claim interleaving reached ' . count( $filters ) );
		return [ $fiber, $filter ];
	};
	try {
		$check( home_url() === 'https://plum-giraffe-539524.hostingersite.com' && class_exists( DSA\Utilities\Credential_Records::class ) && function_exists( 'pk_claim_challenge' ), 'Actual credential owners are loaded only on the authorized lab' );
		$settings = get_option( DSA_OPTION_SETTINGS ); $context = get_option( DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE );
		$proof['existingCredentialShapes'] = [];
		foreach ( [ 'dsa_ai_access_keys', 'dsa_sitegraph_task_capsules_v1' ] as $option ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $option ), ARRAY_A );
			$raw = $row['option_value'] ?? null; $records = null === $raw ? [] : @unserialize( $raw, [ 'allowed_classes' => false ] );
			$valid = ! $wpdb->last_error && ( null === $raw || ( is_string( $raw ) && strlen( $raw ) <= 1048576 ) ) && is_array( $records ) && count( $records ) <= 24;
			$proof['existingCredentialShapes'][$option] = [ 'present' => null !== $raw, 'bytes' => null === $raw ? 0 : strlen( $raw ), 'records' => is_array( $records ) ? count( $records ) : null, 'accepted' => $valid ];
			$check( $valid, 'Existing bounded native credential format accepted for ' . $option );
		}
		// Observe native factor/session events without invoking unrelated external notification consumers.
		$originalPolicy = get_option( DSA\Access\Site_Owner_Service::OPTION );
		update_option( DSA\Access\Site_Owner_Service::OPTION, [ 'enabled' => false, 'ownerId' => 0 ], false );
		remove_all_actions( 'kiwe_identity_factor_verified' ); remove_all_actions( 'dsa_phonekey_authenticated' );
		add_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		$users = [];
		foreach ( [ 'subscriber', 'administrator' ] as $role ) {
			$login = 'pt2_' . $role . '_' . bin2hex( random_bytes( 5 ) );
			$check( 1 === $wpdb->insert( $wpdb->users, [ 'user_login' => $login, 'user_pass' => wp_hash_password( 'synthetic-only-password' ), 'user_email' => $login . '@example.invalid', 'user_nicename' => $login, 'user_registered' => current_time( 'mysql', true ), 'display_name' => 'Synthetic PT2 ' . $role ] ), 'Owned native ' . $role . ' seeded' );
			$id = (int) $wpdb->insert_id; update_user_meta( $id, $wpdb->prefix . 'capabilities', [ $role => true ] ); clean_user_cache( $id ); $users[$role] = $id;
		}
		update_option( DSA\Access\Site_Owner_Service::OPTION, array_replace( is_array( $originalPolicy ) ? $originalPolicy : [], [ 'enabled' => true, 'ownerId' => $users['administrator'] ] ), false );
		wp_set_current_user( 0 ); wp_set_current_user( $users['administrator'] );
		$check( DSA\Access\Site_Owner_Service::is_owner() && current_user_can( 'manage_options' ) && ! user_can( $users['subscriber'], 'manage_options' ), 'Actual native technical owner and customer authority remain distinct' );
		delete_option( 'dsa_ai_access_keys' ); delete_option( 'dsa_sitegraph_task_capsules_v1' );
		$keys = new DSA\AI\Access_Key_Service(); $capsules = new DSA\AI\Task_Capsule_Service();
		$key = $keys->create( 'Synthetic native key', [ 'site_graph' ] ); $capsule = $capsules->issue( 'Synthetic one use', 'content_read', [ 'maxUses' => 1 ] );
		$check( str_starts_with( $key['key'], 'kiwe_ai_' ) && str_starts_with( $capsule['token'], 'kiwe_task_' ), 'Native credential formats remain unchanged' );
		$check( ! str_contains( serialize( get_option( 'dsa_ai_access_keys' ) ), $key['key'] ) && ! str_contains( serialize( get_option( 'dsa_sitegraph_task_capsules_v1' ) ), $capsule['token'] ), 'Actual native options retain hashes rather than plaintext credentials' );
		[ $old, $hook ] = $barrier( fn() => $keys->authenticate_request( $request( [], $key['key'] ), 'site_graph' ), 'dsa_ai_access_keys' );
		$check( $keys->revoke( $key['record']['id'], $users['administrator'] ), 'Native revocation wins during an authentication claim' ); $old->resume(); remove_filter( 'query', $hook, 50 );
		$check( ! $old->getReturn()['ok'] && ! $keys->authenticate_request( $request( [], $key['key'] ), 'site_graph' )['ok'] && ! empty( get_option( 'dsa_ai_access_keys' )[0]['revokedAt'] ), 'Actual SQL accounting cannot restore or reuse a revoked key' );
		[ $old, $hook ] = $barrier( fn() => $capsules->authenticate_request( $request( [], $capsule['token'] ), 'site_graph' ), 'dsa_sitegraph_task_capsules_v1' );
		$winner = $capsules->authenticate_request( $request( [], $capsule['token'] ), 'site_graph' ); $old->resume(); remove_filter( 'query', $hook, 50 );
		$check( $winner['ok'] && ! $old->getReturn()['ok'] && get_option( 'dsa_sitegraph_task_capsules_v1' )[0]['uses'] === 1, 'Native SQL one-use capsule has exactly one successful claimant' );
		$first = $keys->create( 'Native parallel A', [ 'site_graph' ] );
		[ $old, $hook ] = $barrier( fn() => $keys->authenticate_request( $request( [], $first['key'] ), 'site_graph' ), 'dsa_ai_access_keys' );
		$second = $keys->create( 'Native parallel B', [ 'site_graph' ] ); $old->resume(); remove_filter( 'query', $hook, 50 );
		$check( $old->getReturn()['ok'] && $keys->authenticate_request( $request( [], $second['key'] ), 'site_graph' )['ok'] && count( $keys->public_records() ) === 3, 'Native issuance is preserved by concurrent accounting' );
		$stale = get_option( 'dsa_ai_access_keys' ); $keys->revoke( $second['record']['id'], $users['administrator'] ); wp_cache_set( 'dsa_ai_access_keys', $stale, 'options' );
		$check( ! $keys->authenticate_request( $request( [], $second['key'] ), 'site_graph' )['ok'], 'Native stale object-cache data never grants revoked authority' ); wp_cache_delete( 'dsa_ai_access_keys', 'options' );
		$records = get_option( 'dsa_ai_access_keys' ); update_option( 'dsa_ai_access_keys', $records + [ 9 => [ 'id' => 'legacy-placeholder' ] ], true ); wp_load_alloptions( true );
		$check( $keys->revoke( $first['record']['id'], $users['administrator'] ) && ! empty( array_values( array_filter( get_option( 'dsa_ai_access_keys' ), static fn( $r ) => ( $r['id'] ?? '' ) === $first['record']['id'] ) )[0]['revokedAt'] ), 'Actual legacy autoload option cache is coherent after conditional mutation' );
		$failureKey = $keys->create( 'Native write failure', [ 'site_graph' ] ); $beforeFailure = get_option( 'dsa_ai_access_keys' );
		$badWrite = static function ( $sql ) use ( $wpdb ) { return str_starts_with( $sql, 'UPDATE ' . $wpdb->options . ' ' ) && str_contains( $sql, 'dsa_ai_access_keys' ) ? "UPDATE {$wpdb->options} SET kiwe_pt2_missing_column=1 WHERE option_name='synthetic-pt2-never-written'" : $sql; };
		$oldSuppress = $wpdb->suppress_errors( true ); add_filter( 'query', $badWrite, 100 ); $filters[] = [ 'query', $badWrite, 100 ];
		$failed = $keys->authenticate_request( $request( [], $failureKey['key'] ), 'site_graph' );
		$check( ! $failed['ok'] && $failed['status'] === 503 && get_option( 'dsa_ai_access_keys' ) === $beforeFailure, 'Actual native failed SQL write denies authentication and retains the row' ); remove_filter( 'query', $badWrite, 100 ); $wpdb->suppress_errors( $oldSuppress );

		$modules = new DSA\Modules\Module_Registry(); $modules->register_defaults(); $graph = new DSA\AI\Site_Graph_Service( new DSA\Settings(), $modules ); $api = new DSA\Rest\AI_Access_Controller( $graph );
		$apiKey = $keys->create( 'Native external client', [ 'site_graph' ] ); $apiReply = $api->guarded( $request( [], $apiKey['key'] ), 'status', 'status' );
		$check( $apiReply->get_status() === 200 && ! empty( $apiReply->get_data()['ok'] ), 'Actual external-client status accepts the existing bearer-key protocol' );
		$client = $capsules->issue( 'Native client capsule', 'content_read', [ 'maxUses' => 10, 'maxRows' => 2, 'resources' => [ 'pages' ], 'fields' => [ 'id', 'title' ] ] );
		$clientAuth = $capsules->authenticate_request( $request( [], $client['token'] ), 'site_graph_data' ); $bounded = $capsules->authorize_data_args( [ 'resource' => 'pages', 'status' => [ 'private' ], 'limit' => 100, 'metaKeys' => [ '_private' ] ], $clientAuth );
		$check( $clientAuth['ok'] && $bounded['ok'] && $bounded['args']['publicOnly'] === 1 && $bounded['args']['limit'] === 2 && ! isset( $bounded['args']['status'], $bounded['args']['metaKeys'] ), 'Native capsule retains public-only row field and metadata boundaries' );
		$check( ! $capsules->authenticate_request( $request( [], $client['token'] ), 'controlled_mutation' )['ok'], 'Native task capsule never gains write authority' );
		$admin = null;
		foreach ( $GLOBALS['wp_filter']['admin_post_dsa_create_ai_access_key']->callbacks ?? [] as $entries ) foreach ( $entries as $entry ) {
			$callback = $entry['function']; if ( is_array( $callback ) && $callback[0] instanceof DSA\Admin\Admin ) $admin = $callback[0];
		}
		$check( $admin instanceof DSA\Admin\Admin, 'Actual native owner issuance handlers are registered' );
		$die = static fn() => static function ( $message, $title, $args ) { throw new Kiwe_PT2_Die( 'Synthetic native form termination', (int) ( $args['response'] ?? 500 ) ); };
		add_filter( 'wp_die_handler', $die, PHP_INT_MAX ); $filters[] = [ 'wp_die_handler', $die, PHP_INT_MAX ];
		$badIssuance = static function ( $sql ) use ( $wpdb ) { return ( str_starts_with( $sql, 'UPDATE ' . $wpdb->options . ' ' ) || str_starts_with( $sql, 'INSERT IGNORE INTO ' . $wpdb->options . ' ' ) ) && ( str_contains( $sql, 'dsa_ai_access_keys' ) || str_contains( $sql, 'dsa_sitegraph_task_capsules_v1' ) ) ? "UPDATE {$wpdb->options} SET kiwe_pt2_missing_column=1 WHERE option_name='synthetic-pt2-never-written'" : $sql; };
		foreach ( [ 'create_ai_access_key' => 'dsa_create_ai_access_key', 'create_sitegraph_client_package' => 'dsa_create_sitegraph_client_package' ] as $method => $nonceAction ) {
			wp_set_current_user( $users['subscriber'] ); $status = 0;
			try { $admin->{$method}(); } catch ( Kiwe_PT2_Die $error ) { $status = $error->getCode(); }
			$check( $status === 403, 'Actual customer cannot invoke native owner ' . $method );
			wp_set_current_user( $users['administrator'] ); $_POST = [ 'label' => 'Synthetic failed issuance' ]; $_REQUEST = [ '_wpnonce' => wp_create_nonce( $nonceAction ) ]; $status = 0;
			$originalKeys = get_option( 'dsa_ai_access_keys' ); $originalCapsules = get_option( 'dsa_sitegraph_task_capsules_v1' );
			$wpdb->suppress_errors( true ); add_filter( 'query', $badIssuance, 100 ); $filters[] = [ 'query', $badIssuance, 100 ];
			try { $admin->{$method}(); } catch ( Kiwe_PT2_Die $error ) { $status = $error->getCode(); }
			remove_filter( 'query', $badIssuance, 100 ); $wpdb->suppress_errors( $savedSuppress );
			$check( $status === 503 && get_option( 'dsa_ai_access_keys' ) === $originalKeys && get_option( 'dsa_sitegraph_task_capsules_v1' ) === $originalCapsules, 'Actual native ' . $method . ' fails truthfully before exposing a credential or download' );
		}
		remove_filter( 'wp_die_handler', $die, PHP_INT_MAX ); $_POST = $savedPost; $_REQUEST = $savedRequest;

		$customer = $users['subscriber']; $administrator = $users['administrator'];
		foreach ( [ 'email_magic', 'webauthn_register', 'webauthn_login', 'stepup' ] as $purpose ) {
			$token = pk_issue_token( $purpose, $customer, 300 );
			[ $old, $hook ] = $barrier( fn() => pk_token_row( $purpose, $token, true ), pk_t( 'challenges' ) );
			$winner = pk_token_row( $purpose, $token, true ); $old->resume(); remove_filter( 'query', $hook, 50 );
			$check( null !== $winner && null === $old->getReturn(), 'Native single-use ' . $purpose . ' challenge admits one claimant' );
		}
		foreach ( [ 'email', 'phone' ] as $type ) {
			$flow = pk_issue_token( 'flow', $customer, 900, [ 'anchor_type' => $type ] ); $code = '123456';
			pk_issue_token( $type . '_otp', $customer, 300, [ 'flow_token' => $flow, 'otp_hash' => pk_hmac( $code ) ] );
			$handler = 'pk_rest_verify_' . $type; $r = $request( [ 'token' => $flow, 'code' => $code ] );
			[ $old, $hook ] = $barrier( fn() => $handler( $r ), pk_t( 'challenges' ) );
			$winner = $handler( $r ); $old->resume(); remove_filter( 'query', $hook, 50 );
			$check( ! empty( $winner->get_data()['ok'] ) && empty( $old->getReturn()->get_data()['ok'] ) && pk_factor_verified( $customer, $type ), 'Actual native ' . $type . ' OTP verifies only for the claim winner' );
			$check( empty( $handler( $r )->get_data()['ok'] ), 'Actual native ' . $type . ' OTP replay is denied' );
		}
		$flow = pk_issue_token( 'flow', $administrator, 900, [ 'mode' => 'privileged_password' ] ); wp_set_current_user( 0 );
		$blocked = pk_rest_webauthn_register_options( $request( [ 'token' => $flow ] ) );
		$check( $blocked->get_status() === 403 && pk_credential_count( $administrator ) === 0, 'Actual administrator enrolment still requires native password confirmation' );
		$wrong = pk_rest_admin_password_verify( $request( [ 'token' => $flow, 'password' => 'incorrect', 'deferOtpDelivery' => true ] ) );
		$check( $wrong->get_status() === 403 && ! get_user_meta( $administrator, 'pk_admin_password_bound_at', true ), 'Actual WordPress password rejection does not grant assurance' );
		$password = pk_rest_admin_password_verify( $request( [ 'token' => $flow, 'password' => 'synthetic-only-password', 'deferOtpDelivery' => true ] ) )->get_data();
		$check( ! empty( $password['ok'] ) && ! empty( $password['deliveryPending'] ) && $password['next'] === 'verify_email', 'Actual administrator password confirmation keeps the existing recovery-verification step' );
		$adminFlow = $password['token']; pk_issue_token( 'email_otp', $administrator, 300, [ 'flow_token' => $adminFlow, 'otp_hash' => pk_hmac( '123456' ) ] );
		$check( ! empty( pk_rest_verify_email( $request( [ 'token' => $adminFlow, 'code' => '123456' ] ) )->get_data()['ok'] ) && pk_account_verified( $administrator ), 'Actual administrator email factor verifies through its bound OTP' );

		$authenticators = [];
		foreach ( [ $customer => pk_issue_token( 'flow', $customer, 900 ), $administrator => $adminFlow ] as $user => $nativeFlow ) {
			$options = pk_rest_webauthn_register_options( $request( [ 'token' => $nativeFlow ] ) )->get_data();
			$check( ! empty( $options['ok'] ) && ! empty( $options['publicKey']['challenge'] ), 'Actual native WebAuthn registration options for actor ' . $user );
			$authenticators[$user] = kiwe_pt2_authenticator(); $registration = kiwe_pt2_registration( $options, $authenticators[$user] );
			$registered = pk_rest_webauthn_register_verify( $request( $registration ) );
			$check( ! empty( $registered->get_data()['ok'] ) && pk_credential_count( $user ) === 1, 'Actual EC and CBOR native registration succeeds for actor ' . $user );
			$check( pk_rest_webauthn_register_verify( $request( $registration ) )->get_status() === 403 && pk_credential_count( $user ) === 1, 'Actual native WebAuthn registration challenge cannot be replayed for actor ' . $user );
			if ( $user === $administrator ) $adminPhoneFlow = $registered->get_data()['token'] ?? '';
		}
		$check( ! empty( $adminPhoneFlow ) && ! pk_admin_enrollment_complete( $administrator ), 'Actual privileged registration still requires phone binding' );
		$policy = pk_settings(); $policy['role_matrix']['administrator']['step_up'] = 'totp_required'; update_option( 'pk_settings_v3', $policy, false );
		$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; pk_upsert_factor( $administrator, 'totp', 'verified', pk_hmac( 'synthetic-totp' ), [], $secret );
		pk_upsert_factor( $administrator, 'phone', 'pending', pk_hmac( '+15550100017' ), [], '+15550100017' );
		pk_issue_token( 'phone_otp', $administrator, 300, [ 'flow_token' => $adminPhoneFlow, 'otp_hash' => pk_hmac( '123456' ) ] ); wp_set_current_user( 0 );
		$phone = pk_rest_verify_phone( $request( [ 'token' => $adminPhoneFlow, 'code' => '123456' ] ) )->get_data();
		$check( ! empty( $phone['requiresTotp'] ) && get_current_user_id() === 0 && pk_admin_enrollment_complete( $administrator ), 'Actual administrator phone proof retains mandatory configured TOTP before login' );
		$badCode = '000000';
		for ( $candidate = 0; $candidate < 4 && pk_totp_verify( $secret, $badCode ); ++$candidate ) $badCode = str_pad( (string) ( $candidate + 1 ), 6, '0', STR_PAD_LEFT );
		$check( ! pk_totp_verify( $secret, $badCode ), 'Synthetic invalid TOTP is outside all accepted time slices' );
		$badTotp = pk_rest_totp_login_verify( $request( [ 'token' => $phone['loginToken'], 'code' => $badCode ] ) );
		$check( $badTotp->get_status() === 403 && get_current_user_id() === 0, 'Actual invalid administrator TOTP remains denied' );
		$check( pk_rest_totp_login_verify( $request( [ 'token' => $phone['loginToken'], 'code' => pk_totp_code( $secret ) ] ) )->get_status() === 403, 'Invalid step-up deliberately consumes its challenge as before' );
		$freshStep = pk_issue_token( 'stepup', $administrator, 300 ); $totp = pk_rest_totp_login_verify( $request( [ 'token' => $freshStep, 'code' => pk_totp_code( $secret ) ] ) )->get_data();
		$check( ! empty( $totp['ok'] ) && get_current_user_id() === $administrator && current_user_can( 'manage_options' ), 'Actual correct TOTP completes the native administrator WordPress session' );
		$check( count( WP_Session_Tokens::get_instance( $administrator )->get_all() ) > 0, 'Native WordPress owns the synthetic staff session tokens' );
		foreach ( [ $customer, $administrator ] as $user ) {
			wp_set_current_user( 0 ); $nativeFlow = pk_issue_token( 'flow', $user, 900 ); $options = pk_rest_webauthn_login_options( $request( [ 'token' => $nativeFlow ] ) )->get_data();
			$assertion = kiwe_pt2_assertion( $options, $authenticators[$user], 1 ); $result = pk_rest_webauthn_login_verify( $request( $assertion ) );
			$check( ! empty( $result->get_data()['ok'] ) && ( $user !== $administrator || ! empty( $result->get_data()['requiresTotp'] ) ), 'Actual native signed passkey assertion keeps actor policy for ' . $user );
			$check( pk_rest_webauthn_login_verify( $request( $assertion ) )->get_status() === 403, 'Actual native assertion challenge replay is denied for ' . $user );
			$options = pk_rest_webauthn_login_options( $request( [ 'token' => $nativeFlow ] ) )->get_data(); $wrongOrigin = kiwe_pt2_assertion( $options, $authenticators[$user], 2, 'https://unrelated.example.invalid' );
			$check( pk_rest_webauthn_login_verify( $request( $wrongOrigin ) )->get_status() === 403, 'Actual native passkey origin validation remains for ' . $user );
		}
		$customerSessions = WP_Session_Tokens::get_instance( $customer )->get_all();
		$check( count( $customerSessions ) > 0, 'Actual customer assertion retains a native WordPress session' );
		$keys->revoke( $apiKey['record']['id'], $administrator );
		$check( WP_Session_Tokens::get_instance( $customer )->get_all() === $customerSessions, 'API credential revocation does not destroy the separate customer WordPress session' );
		$check( get_option( DSA_OPTION_SETTINGS ) === $settings && get_option( DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE ) === $context, 'Native credential and identity work preserves Core settings and complete Design Context' );
		$check( ( $GLOBALS['kiwe_seo_test_http_calls'] ?? 0 ) === 0 && ( $GLOBALS['kiwe_seo_test_mail_calls'] ?? 0 ) === 0, 'No real provider request or message occurs in native qualification' );
		$proof['runtime'] = DSA_VERSION; $proof['passed'] = true;
	} catch ( Throwable $error ) {
		$proof['error'] = $error->getMessage();
		$proof['frames'] = array_map( static fn( $f ) => [ 'file' => basename( $f['file'] ?? '' ), 'line' => $f['line'] ?? 0, 'function' => $f['function'] ?? '' ], array_slice( $error->getTrace(), 0, 5 ) );
	} finally {
		foreach ( $filters as [ $tag, $filter, $priority ] ) remove_filter( $tag, $filter, $priority );
		$wpdb->suppress_errors( $savedSuppress );
		$_POST = $savedPost; $_REQUEST = $savedRequest;
		try { wp_set_current_user( 0 ); do_action( 'shutdown' ); remove_all_actions( 'shutdown' ); } catch ( Throwable $error ) { $proof['passed'] = false; $proof['shutdownError'] = $error->getMessage(); }
		kiwe_seo_test_cleanup( $proof );
	}
	$proof['passed'] = $proof['passed'] && $proof['cleanup'] && ! in_array( false, $proof['checks'], true );
	return $proof;
}
