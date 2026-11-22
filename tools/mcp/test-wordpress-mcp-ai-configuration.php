<?php
/** Called only by the guarded disposable native WordPress runner. */
function kiwe_mcp_ai_configuration_checks( callable $check, array $old_ops, callable $connect, callable $session, callable $read_rpc, callable $approve ): void {
	if ( ! defined( 'KIWE_M10_DISPOSABLE' ) || ! KIWE_M10_DISPOSABLE ) throw new RuntimeException( 'Use an owned native fixture.' );
	$original = get_option( DSA_OPTION_SETTINGS );
	$http = 0;
	$block = static function () use ( &$http ) {
		++$http;
		return new WP_Error( 'test_ai_offline', 'Native configuration makes no provider request.' );
	};
	add_filter( 'pre_http_request', $block, PHP_INT_MAX, 3 );
	try {
		$token = $connect( 'mcp.read ai.configuration.read ai.configuration.write' );
		$handle = $session( $token );
		$call = static fn( string $tool, array $args = [], bool $error = false ): array => $read_rpc( $token, $handle, $tool, $args, $error );
		$operations = array_column( $call( 'discover' )['operations'], 'id' );
		$check( ! in_array( 'ai.configuration.read', $old_ops, true ) && ! in_array( 'ai.configuration.change', $old_ops, true ) &&
			in_array( 'ai.configuration.read', $operations, true ) && in_array( 'ai.configuration.change', $operations, true ) &&
			! in_array( 'mcp.visual.generate', $operations, true ), 'AI setup requires its own consent and does not grant image generation' );
		$read = static fn(): array => json_decode( $call( 'read', [ 'operation'=>'ai.configuration.read', 'arguments'=>[] ] )['resultJson'], true, 32, JSON_THROW_ON_ERROR );
		$view = $read();
		$sealed = $original['ai']['native_api_key'];
		$unsealed = DSA\Security\Secret_Store::decrypt( $sealed );
		$check( $view['provider'] === 'gemini' && $view['credentialStored'] && $view['featuredImageReady'] &&
			str_ends_with( $view['protectedSetupUrl'], 'page=kiwe-ai-features' ) && ! str_contains( wp_json_encode( $view ), $unsealed ) &&
			! str_contains( wp_json_encode( $view ), $sealed ) && ! array_intersect( [ 'configuration', 'native_base_url' ], array_keys( $view ) ), 'MCP reads readiness without protected settings or key material' );
		$prepare = static fn( array $input, bool $error = false ): array => $call( 'prepare-change', [ 'operation'=>'ai.configuration.change', 'arguments'=>$input, 'requestId'=>bin2hex( random_bytes( 16 ) ) ], $error );
		$apply = static fn( array $change, bool $error = false ): array => $call( 'apply-change', [ 'intent'=>$change['id'] ], $error );
		$prepare( [ 'native_api_key'=>'synthetic-not-a-real-key' ], true );
		$prepare( [ 'native_base_url'=>'https://elsewhere.example.invalid' ], true );
		$prepare( [ 'model'=>$unsealed ], true );
		$prepare( [ 'provider'=>'groq', 'allowNativeGeneration'=>false ], true );
		$check( $read() === $view, 'MCP refuses secret input, custom endpoints, secrets as models, and switching a keyed provider' );
		$limits = [ 'minute'=>3, 'day'=>15, 'month'=>100 ];
		$change = $prepare( [ 'allowNativeGeneration'=>false, 'requestLimits'=>$limits ] );
		$apply( $change, true );
		$check( $change['approvalRequired'] && $read() === $view, 'A prepared AI change cannot apply before exact site approval' );
		$approve( $change );
		$done = $apply( $change );
		$now = $read();
		$check( $done['state'] === 'complete' && $apply( $change ) === $done && $now['requestLimits'] === $limits && ! $now['allowNativeGeneration'] &&
			! $now['featuredImageReady'] && $now['credentialStored'], 'Reviewed AI changes use the native writer and return a repeatable receipt' );
		$pending = $prepare( [ 'model'=>'gemini-2.5-flash' ] );
		$approve( $pending );

		$native = static function ( string $route, ?array $body = null, bool $proof = true ): WP_REST_Response {
			$request = new WP_REST_Request( null === $body ? 'GET' : 'POST', '/dsa/v1/workspace/ai' . $route );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			if ( null !== $body ) {
				if ( $proof ) $request->set_header( 'X-Kiwe-Mutation', '1' );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( wp_json_encode( $body ) );
			}
			return rest_do_request( $request );
		};
		$client = wp_insert_user( [ 'user_login'=>'mcp_ai_test_manager', 'user_email'=>'mcp-ai-test-manager@example.invalid', 'user_pass'=>wp_generate_password( 36 ), 'role'=>'administrator' ] );
		$author = wp_insert_user( [ 'user_login'=>'mcp_ai_test_author', 'user_email'=>'mcp-ai-test-author@example.invalid', 'user_pass'=>wp_generate_password( 36 ), 'role'=>'author' ] );
		$check( is_int( $client ) && is_int( $author ), 'Native fixture has separate client administrator and author roles' );
		wp_set_current_user( $client );
		$check( current_user_can( 'kiwe_manage_ai_preferences' ) && current_user_can( 'kiwe_manage_context' ) &&
			! current_user_can( 'manage_options' ), 'Native client administrator has AI authority without technical WordPress access' );
		$owner = $native( '' )->get_data()['owner'];
		$replacement = 'replacement-fixture-key';
		$body = [ 'key'=>'configuration', 'value'=>[ 'native_api_key'=>$replacement ], 'expected'=>$owner['expected'] ];
		$check( 403 === $native( '/owner', $body, false )->get_status(), 'Native role admission still requires mutation proof' );
		wp_set_current_user( $author );
		$check( 403 === $native( '/owner', $body )->get_status(), 'An author cannot change provider credentials' );
		wp_set_current_user( $client );
		$save = $native( '/owner', $body );
		$next = get_option( DSA_OPTION_SETTINGS )['ai']['native_api_key'];
		$check( 200 === $save->get_status() && $next !== $replacement && DSA\Security\Secret_Store::decrypt( $next ) === $replacement &&
			! str_contains( wp_json_encode( $save->get_data() ), $replacement ) && ! str_contains( wp_json_encode( $save->get_data() ), $next ), 'Protected native REST saves the manager key encrypted and never returns it' );
		$check( 409 === $native( '/owner', $body )->get_status(), 'The old native form cannot overwrite the replacement' );
		$check( 400 === $native( '/provider-check', [ 'confirmed'=>false ] )->get_status(), 'Native provider check route still requires explicit confirmation' );
		$apply( $pending, true );
		$check( $http === 0 && $read()['model'] === $view['model'], 'Key rotation invalidates an approved MCP plan, and setup does not send AI requests' );
	} finally {
		remove_filter( 'pre_http_request', $block, PHP_INT_MAX );
		update_option( DSA_OPTION_SETTINGS, $original, false );
		wp_set_current_user( 0 );
	}
}
