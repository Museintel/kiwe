<?php
/** The runner owns a fresh native WordPress database and intercepts every HTTP request. */
function kiwe_mcp_featured_image_checks( callable $check, string $references, string $source_dir ): array {
	if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_M10_DISPOSABLE' ) || ! KIWE_M10_DISPOSABLE ||
		home_url() !== 'https://kiwe-m10-commerce.example.invalid' || DB_NAME !== 'kiwe_m10_commerce' || ! str_starts_with( DB_HOST, '127.0.0.1:' ) ) {
		throw new RuntimeException( 'An owned local WooCommerce fixture is required.' );
	}
	$fixture = json_decode( file_get_contents( dirname( $references ) . '/fixture.json' ), true, 16, JSON_THROW_ON_ERROR );
	$owner = DSA\Access\Site_Owner_Service::owner_id();
	wp_set_current_user( $owner );
	$check( DSA\Apps\Store_App_Service::enabled() && DSA\Apps\AI_App_Service::enabled() && DSA\Apps\MCP_App_Service::enabled(), 'The three native Apps are active in the disposable fixture' );
	update_option( 'woocommerce_currency', 'INR' );
	update_option( 'woocommerce_weight_unit', 'kg' );
	update_option( DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE, [ 'brand'=>[ 'tone'=>'natural', 'colors'=>[ [ 'role'=>'brand', 'hex'=>'#5d371f' ] ] ] ], false );
	$settings = get_option( DSA_OPTION_SETTINGS, [] );
	$ai_key = 'mock-key-not-for-network';
	$settings['ai'] = array_replace( $settings['ai'] ?? [], [
		'native_provider'=>'gemini', 'native_api_key'=>DSA\Security\Secret_Store::encrypt( $ai_key ),
		'allow_native_generation'=>true, 'request_allowance'=>[ 'minute'=>12, 'day'=>30, 'month'=>100 ],
	] );
	update_option( DSA_OPTION_SETTINGS, $settings, false );
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$upload = static function ( string $path, string $title ): int {
		if ( ! is_file( $path ) ) throw new RuntimeException( 'A mock input image is missing.' );
		$tmp = wp_tempnam( basename( $path ) );
		try {
			if ( ! $tmp || ! copy( $path, $tmp ) ) throw new RuntimeException( 'Could not stage the input photo.' );
			$id = media_handle_sideload( [ 'name'=>basename( $path ), 'tmp_name'=>$tmp, 'error'=>0, 'size'=>filesize( $tmp ) ], 0, null, [ 'post_title'=>$title ] );
			if ( is_wp_error( $id ) || ! wp_attachment_is_image( $id ) ) throw new RuntimeException( 'WordPress refused a fixture photo.' );
			return (int) $id;
		} finally { if ( $tmp && is_file( $tmp ) ) wp_delete_file( $tmp ); }
	};
	$category = wp_insert_term( $fixture['category'], 'product_cat' );
	$category_id = (int) ( $category['term_id'] ?? 0 );
	$check( $category_id > 0, 'One verified existing product category is available for the fixture' );
	$style_ids = [];
	$existing_product_ids = [];
	foreach ( $fixture['styleProducts'] as $index => $item ) {
		$style_ids[] = $id = $upload( $references . '/' . $item['file'], $item['title'] );
		$product = new WC_Product_Simple();
		$product->set_name( $item['title'] );
		$product->set_status( 'publish' );
		$product->set_image_id( $id );
		if ( $index === 0 ) $product->set_category_ids( [ $category_id ] );
		$existing_product_ids[] = $product->save();
	}
	$example_post = wp_insert_post( [ 'post_type'=>'post', 'post_status'=>'publish', 'post_title'=>'Existing editorial style' ], true );
	$check( ! is_wp_error( $example_post ) && (bool) set_post_thumbnail( $example_post, $style_ids[1] ), 'Native published post and product style examples are available' );

	$registry = new DSA\MCP\Operation_Registry();
	DSA\MCP\Native_Provider::register( $registry );
	DSA\Commerce\MCP_Store_Provider::register( $registry, DSA\Apps\MCP_Provider_Contract::API );
	do_action( 'kiwe_mcp_register_providers', $registry, DSA\Apps\MCP_Provider_Contract::API );
	$auth = new DSA\MCP\Authorization_Service( $registry );
	$intents = new DSA\MCP\Intent_Service( $registry );
	$connect = static function( string $scopes ) use ( $auth,$owner ): string {
		wp_set_current_user( $owner );
		$client = $auth->register_client( [ 'client_name'=>'Kiwe featured-image fixture', 'redirect_uris'=>[ 'http://127.0.0.1/callback' ] ] )['client_id'];
		$browser = DSA\MCP\Credentials::secret();
		$verifier = DSA\MCP\Credentials::secret();
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$params = [ 'client_id'=>$client, 'redirect_uri'=>'http://127.0.0.1:19645/callback', 'response_type'=>'code', 'code_challenge_method'=>'S256',
			'code_challenge'=>$challenge, 'state'=>'featured-image-fixture', 'resource'=>DSA\MCP\Urls::resource(), 'scope'=>$scopes ];
		$flow = $auth->begin( $params, $browser );
		$review = $auth->review( $flow, $browser, $owner );
		$approved = $auth->approve( $flow, $browser, $owner, true, false, $review['reviewDigest'] );
		return $auth->exchange( [ 'grant_type'=>'authorization_code', 'client_id'=>$client, 'redirect_uri'=>$params['redirect_uri'],
			'code_verifier'=>$verifier, 'code'=>$approved['code'], 'resource'=>$params['resource'] ] )['access_token'];
	};
	$old = $connect( 'mcp.read mcp.authoring.read mcp.authoring.write' );
	$token = $connect( 'mcp.read mcp.visual.read mcp.visual.generate mcp.authoring.read mcp.authoring.write store.authoring.read store.authoring.write' );
	$send = static function ( string $access, string $method, array $params, string $session = '' ): WP_REST_Response {
		DSA\MCP\Auth_Context::clear();
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/kiwe-mcp/v1/mcp' );
		foreach ( [ 'Authorization'=>'Bearer ' . $access, 'Accept'=>'application/json, text/event-stream', 'Content-Type'=>'application/json',
			'MCP-Protocol-Version'=>'2025-11-25', 'Mcp-Session-Id'=>$session ] as $name => $value ) {
			if ( '' !== $value ) $request->set_header( $name, $value );
		}
		$request->set_body( wp_json_encode( [ 'jsonrpc'=>'2.0', 'id'=>1, 'method'=>$method, 'params'=>$params ] ) );
		try { return rest_do_request( $request ); }
		finally { DSA\MCP\Auth_Context::clear(); wp_set_current_user( 0 ); }
	};
	$session = static function( string $access ) use ( $send ): string {
		$response = $send( $access, 'initialize', [ 'protocolVersion'=>'2025-11-25', 'clientInfo'=>[ 'name'=>'Kiwe photo fixture', 'version'=>'1' ], 'capabilities'=>(object) [] ] );
		$handle = $response->get_headers()['Mcp-Session-Id'] ?? '';
		if ( $response->get_status() !== 200 || '' === $handle ) throw new RuntimeException( 'The fixture could not initialize its MCP session (HTTP ' . $response->get_status() . ').' );
		return $handle;
	};
	$read_rpc = static function( string $access, string $handle, string $tool, array $arguments = [], bool $expect_error = false ) use ( $send ): array {
		$response = $send( $access, 'tools/call', [ 'name'=>'kiwe-mcp-' . $tool, 'arguments'=>(object) $arguments ], $handle );
		$rpc = json_decode( wp_json_encode( $response->get_data() ), true );
		$result = $rpc['result'] ?? [];
		$failed = $response->get_status() !== 200 || isset( $rpc['error'] ) || ! empty( $result['isError'] );
		if ( $failed !== $expect_error ) {
			$message = (string) ( $rpc['error']['message'] ?? $result['content'][0]['text'] ?? $rpc['message'] ?? '' );
			throw new RuntimeException( 'Unexpected MCP ' . $tool . ' outcome (HTTP ' . $response->get_status() . ') ' . substr( $message, 0, 400 ) );
		}
		if ( $failed ) return [];
		return $result['structuredContent'] ?? json_decode( $result['content'][0]['text'] ?? '', true, 32, JSON_THROW_ON_ERROR );
	};
	$handle = $session( $token );
	$old_handle = $session( $old );
	$call = static fn( string $tool, array $args = [], bool $error = false ): array => $read_rpc( $token, $handle, $tool, $args, $error );
	$old_ops = array_column( $read_rpc( $old, $old_handle, 'discover' )['operations'], 'id' );
	$ops = array_column( $call( 'discover' )['operations'], 'id' );
	$check( ! in_array( 'mcp.visual.inspect', $old_ops, true ) && in_array( 'mcp.visual.inspect', $ops, true ) && in_array( 'mcp.visual.generate', $ops, true ) &&
		! in_array( 'mcp.content.publish', $ops, true ) && ! in_array( 'store.product.publish', $ops, true ), 'Fresh visual consent grants the new operations while prior grants and publication remain scoped' );
	$prepare = static fn( string $op, array $a ): array => $call( 'prepare-change', [ 'operation'=>$op, 'arguments'=>$a, 'requestId'=>bin2hex( random_bytes( 16 ) ) ] );
	$apply = static fn( array $change, bool $error = false ): array => $call( 'apply-change', [ 'intent'=>$change['id'] ], $error );
	$approve = static function( array $change ) use ( $owner,$intents,$auth ): void {
		wp_set_current_user( $owner );
		try { $intents->approve( $change['id'], $auth, true ); }
		finally { wp_set_current_user( 0 ); }
	};
	if ( version_compare( DSA_VERSION, '8.0.0-rc.490', '>=' ) ) {
		require_once __DIR__ . '/test-wordpress-mcp-ai-configuration.php';
		kiwe_mcp_ai_configuration_checks( $check, $old_ops, $connect, $session, $read_rpc, $approve );
	}
	$write = static function( string $op, array $a, string $label ) use ( $prepare,$apply,$approve,$check ): array {
		$change = $prepare( $op, $a );
		if ( $change['approvalRequired'] ) $approve( $change );
		$done = $apply( $change );
		$check( $done['state'] === 'complete', $label );
		return json_decode( $done['result']['resultJson'], true, 32, JSON_THROW_ON_ERROR );
	};
	$inspect = static fn( array $a ): array => json_decode( $call( 'read', [ 'operation'=>'mcp.visual.inspect', 'arguments'=>$a ] )['resultJson'], true, 32, JSON_THROW_ON_ERROR );

	$calls = [];
	$bad_output = false;
	$deny_billing = false;
	$expected_hashes = [];
	add_filter( 'pre_http_request', static function( $prior, array $request, string $url ) use ( &$calls,&$bad_output,&$deny_billing,&$expected_hashes,$ai_key ): mixed {
		if ( ! str_starts_with( $url, 'https://generativelanguage.googleapis.com/' ) ) return $prior;
		$payload = json_decode( $request['body'], true, 24, JSON_THROW_ON_ERROR );
		$parts = $payload['contents'][0]['parts'];
		$images = array_values( array_column( $parts, 'inlineData' ) );
		$ratio = $payload['generationConfig']['responseFormat']['image']['aspectRatio'] ?? '';
		$pin_match = [] === array_diff( $expected_hashes, array_map( static fn( $part ) => hash( 'sha256', base64_decode( $part['data'], true ) ), $images ) );
		$calls[] = [ 'ratio'=>$ratio, 'prompt'=>$parts[0]['text'], 'imageCount'=>count( $images ), 'originalsMatch'=>$pin_match,
			'providerShape'=>$url === 'https://generativelanguage.googleapis.com/v1/models/gemini-3.1-flash-image:generateContent' &&
				$request['headers']['x-goog-api-key'] === $ai_key && $request['redirection'] === 0 &&
				$payload['generationConfig']['responseModalities'] === [ 'TEXT', 'IMAGE' ] && $payload['generationConfig']['responseFormat']['image']['imageSize'] === '1K' ];
		if ( $deny_billing ) return [ 'response'=>[ 'code'=>403 ], 'body'=>wp_json_encode( [ 'error'=>[
			'status'=>'PERMISSION_DENIED', 'message'=>'private provider error fixture',
			'details'=>[ [ 'reason'=>'BILLING_DISABLED', 'metadata'=>[ 'consumer'=>'private project fixture' ] ] ],
		] ] ) ];
		$image = imagecreatetruecolor( 1024, $ratio === '16:9' ? 576 : 1024 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 93, 55, 31 ) );
		imagestring( $image, 5, 30, 30, 'SIMULATED OUTPUT', imagecolorallocate( $image, 254, 248, 222 ) );
		ob_start(); imagepng( $image ); $bytes = ob_get_clean();
		return [ 'response'=>[ 'code'=>200 ], 'body'=>wp_json_encode( [ 'candidates'=>[ [ 'finishReason'=>'STOP', 'content'=>[ 'parts'=>[
			[ 'text'=>'Simulated visual observations from the site reference and the submitted photo.' ],
			[ 'inlineData'=>[ 'mimeType'=>'image/png', 'data'=>base64_encode( $bad_output ? 'unusable bytes' : $bytes ) ] ],
		] ] ] ], 'usageMetadata'=>[ 'promptTokenCount'=>120, 'candidatesTokenCount'=>300, 'totalTokenCount'=>420 ] ] ) ];
	}, PHP_INT_MAX, 3 );
	$generate = static function( array $input, string $label ) use ( $prepare,$apply,$approve,$check,&$calls ): array {
		$change = $prepare( 'mcp.visual.generate', $input );
		$before_calls = count( $calls );
		$check( $change['approvalRequired'] && '' !== $change['approvalUrl'] && (int) get_post_thumbnail_id( $input['targetId'] ) === 0, 'An exact native photo review exists for ' . $label );
		$apply( $change, true );
		$check( count( $calls ) === $before_calls, 'No image request before native approval for ' . $label );
		$approve( $change );
		$done = $apply( $change );
		$check( $done['state'] === 'complete', 'Kiwe confirmed a Media Library candidate for ' . $label );
		$check( $apply( $change ) === $done && count( $calls ) === $before_calls + 1, 'A repeated receipt makes no duplicate image request for ' . $label );
		$result = json_decode( $done['result']['resultJson'], true, 24, JSON_THROW_ON_ERROR );
		$check( ! $result['assigned'] && (int) get_post_thumbnail_id( $input['targetId'] ) === 0 && get_post( $result['attachmentId'] )->post_parent === 0 &&
			str_contains( $result['observations'], 'Simulated' ) && wp_attachment_is_image( $result['attachmentId'] ) &&
			get_post_meta( $result['attachmentId'], '_kiwe_featured_image_v1', true )['sourceIds'] === $input['sourceIds'], 'Candidate remains separate from the draft, with native media and lineage for ' . $label );
		return $result;
	};

	$receipts = [];
	foreach ( $fixture['products'] as $item ) {
		$label = $item['title'];
		$draft = $write( 'store.product.create', [ 'fieldsJson'=>wp_json_encode( [ 'name'=>$label, 'type'=>'simple' ] ) ], 'Native simple draft created for ' . $label );
		$id = (int) $draft['id'];
		$fields = [ 'price'=>$fixture['price'] ];
		if ( ! empty( $item['knownCategory'] ) ) $fields['categories'] = [ $category_id ];
		$write( 'store.product.save', [ 'id'=>$id, 'fieldsJson'=>wp_json_encode( $fields ) ], 'Price saved through the existing Store writer for ' . $label );
		$write( 'store.product.delivery', [ 'id'=>$id, 'fieldsJson'=>wp_json_encode( [ 'weight'=>$fixture['weightKg'] ] ) ], 'Confirmed weight saved using the store’s kilogram unit for ' . $label );
		$input = [ 'targetType'=>'product', 'targetId'=>$id ];
		$empty = $inspect( $input );
		$check( ! empty( $empty['needs'] ) && empty( $empty['subjects'] ) && $empty['providerReady'], 'Missing photo yields one next step for ' . $label );
		wp_set_current_user( $owner );
		$source_ids = [];
		$expected_hashes = [];
		foreach ( $item['files'] as $index => $file ) {
			$path = $source_dir . '/' . $file;
			$source_ids[] = $upload( $path, $label . ' phone view ' . ( $index + 1 ) );
			$expected_hashes[] = hash_file( 'sha256', $path );
		}
		$input['sourceIds'] = $source_ids;
		$brief = $inspect( $input );
		$check( $brief['subjects'] && count( $brief['subjects'] ) === count( $item['files'] ) && $brief['ratio'] === '1:1' && $brief['styleSource'] === 'website' &&
			count( $brief['style'] ) === 2 && ( empty( $item['knownCategory'] ) || $brief['style'][0]['id'] === $style_ids[0] ), 'Product brief selects native site references and prioritizes matching category for ' . $label );
		$check( $brief['brandColors'] === [ '#5d371f' ] && $brief['brandTone'] === 'natural', 'Photo brief uses the existing Design Context palette and tone for ' . $label );
		$result = $generate( $input, $label );
		$sent = end( $calls );
		$check( $sent['providerShape'] && $sent['originalsMatch'] && $sent['imageCount'] === count( $source_ids ) + 2 &&
			str_contains( $sent['prompt'], 'keep it closed' ) && str_contains( $sent['prompt'], 'Never substitute their products' ), 'Provider received the original phone files and explicit packaging/style constraints for ' . $label );
		$write( 'store.product.save', [ 'id'=>$id, 'fieldsJson'=>wp_json_encode( [ 'imageId'=>$result['attachmentId'] ] ) ], 'Native Store writer assigns the reviewed candidate for ' . $label );
		$product = wc_get_product( $id );
		$details = sprintf( ' (status %s, type %s, weight %s, price %s, image %s / %s)', $product->get_status(), $product->get_type(), $product->get_weight(), $product->get_regular_price(), $product->get_image_id(), $result['attachmentId'] );
		$check( 'draft' === $product->get_status() && 'simple' === $product->get_type() && (float) $product->get_weight() === (float) $fixture['weightKg'] &&
			(float) $product->get_regular_price() === (float) $fixture['price'] && (int) $product->get_image_id() === $result['attachmentId'], 'Native draft keeps the correct price, weight and image for ' . $label . $details );
		$receipts[] = [ 'title'=>$label, 'status'=>$product->get_status(), 'currency'=>get_woocommerce_currency(), 'price'=>$product->get_regular_price(),
			'weightGrams'=>(int) wc_get_weight( $product->get_weight(), 'g' ), 'photos'=>count( $source_ids ), 'references'=>array_column( $brief['style'], 'title' ),
			'candidateAttachmentId'=>$result['attachmentId'], 'provider'=>'simulated' ];
	}
	$check( (int) wc_get_product( $existing_product_ids[0] )->get_image_id() === $style_ids[0], 'Existing published reference product is unchanged' );
	$post = $write( 'mcp.content.save', [ 'id'=>0, 'type'=>'post', 'fields'=>[ 'title'=>'Editorial featured-image fixture', 'status'=>'draft' ] ], 'Native MCP created a separate editorial draft' );
	$expected_hashes = [ $expected_hashes[0] ];
	$input = [ 'targetType'=>'post', 'targetId'=>(int) $post['id'], 'sourceIds'=>[ $source_ids[0] ] ];
	$brief = $inspect( $input );
	$check( $brief['ratio'] === '16:9' && $brief['style'][0]['id'] === $style_ids[1], 'Editorial brief chooses the existing post’s style and wide crop' );
	$reports_failures = class_exists( DSA\MCP\Image_Request_Failure::class, false ) &&
		! empty( ( new DSA\AI\AI_Provider_Service( new DSA\Settings() ) )->image_status()['requiresPaidProject'] );
	$unreported_units = 0;
	$bad_output = true;
	$invalid = $prepare( 'mcp.visual.generate', $input );
	$approve( $invalid );
	$before = count( $calls );
	$failed = $apply( $invalid );
	$invalid_receipt = $reports_failures ? json_decode( $failed['result']['resultJson'] ?? '{}', true ) : null;
	$check( $failed['state'] === 'needs-review' && ( $reports_failures ? ( $invalid_receipt['reason'] ?? '' ) === 'kiwe_image_invalid_response' : $failed['result'] === null ) &&
		$apply( $invalid ) === $failed && count( $calls ) === $before + 1 &&
		! get_post_thumbnail_id( $post['id'] ), 'An invalid image result is not saved or automatically retried' );
	$bad_output = false;
	if ( $reports_failures ) {
		$check( $brief['providerReady'] && $brief['providerRequiresPaidProject'] && ! $brief['providerQuotaVerified'] &&
			str_contains( $invalid['after'], 'paid Gemini API project' ), 'Local provider setup does not claim a verified quota and the review shows the paid-project requirement' );
		$blocked = $prepare( 'mcp.visual.generate', $input );
		$approve( $blocked );
		$before_billing = count( $calls );
		$deny_billing = true;
		$failed = $apply( $blocked );
		$deny_billing = false;
		$rejection = json_decode( $failed['result']['resultJson'] ?? '{}', true );
		$check( $failed['state'] === 'needs-review' && ( $rejection['reason'] ?? '' ) === 'kiwe_image_billing' &&
			true === $rejection['attempted'] && 403 === $rejection['httpStatus'] && ! str_contains( wp_json_encode( $failed ), 'private ' ) &&
			$apply( $blocked ) === $failed && count( $calls ) === $before_billing + 1 && ! get_post_thumbnail_id( $post['id'] ),
			'A paid-project refusal is actionable, private and never retried on the same review' );
		$unreported_units = 12000 + 1500 * $calls[$before_billing]['imageCount'];
	}
	$result = $generate( $input, 'editorial draft' );
	$write( 'mcp.content.save', [ 'id'=>$post['id'], 'type'=>'post', 'fields'=>[ 'featuredImageId'=>$result['attachmentId'] ] ], 'Native WordPress authoring assigns the reviewed editorial image' );
	$check( get_post_status( $post['id'] ) === 'draft' && (int) get_post_thumbnail_id( $post['id'] ) === $result['attachmentId'] && $result['width'] === 1024 && $result['height'] === 576,
		'Editorial candidate has the intended crop and the post remains unpublished' );
	if ( $reports_failures ) {
		$partial = $prepare( 'mcp.visual.generate', $input );
		$approve( $partial );
		$before_partial = count( $calls );
		$block_lineage = static fn( $value, $id, $key ) => '_kiwe_featured_image_v1' === $key ? false : $value;
		add_filter( 'update_post_metadata', $block_lineage, 10, 3 );
		try { $partial_result = $apply( $partial ); }
		finally { remove_filter( 'update_post_metadata', $block_lineage, 10 ); }
		$partial_media = json_decode( $partial_result['result']['resultJson'] ?? '{}', true );
		$check( $partial_result['state'] === 'needs-review' && ( $partial_media['type'] ?? '' ) === 'attachment' &&
			! $partial_media['confirmed'] && wp_attachment_is_image( $partial_media['id'] ) &&
			(int) get_post( $partial_media['id'] )->post_parent === 0 && $apply( $partial ) === $partial_result &&
			count( $calls ) === $before_partial + 1 && (int) get_post_thumbnail_id( $post['id'] ) === $result['attachmentId'],
			'A saved image with unconfirmed lineage keeps its native attachment ID without replacing or regenerating media' );
	}
	$stale = $prepare( 'mcp.visual.generate', $input );
	$approve( $stale );
	wp_set_current_user( $owner );
	$changed_settings = $settings;
	$changed_settings['ai']['native_api_key'] = DSA\Security\Secret_Store::encrypt( 'different-fixture-key' );
	update_option( DSA_OPTION_SETTINGS, $changed_settings, false );
	$before = count( $calls );
	$apply( $stale, true );
	$check( count( $calls ) === $before, 'Changing the configured provider key invalidates prior exact review before contacting the provider' );
	wp_set_current_user( $owner );
	$settings['ai']['allow_native_generation'] = false;
	update_option( DSA_OPTION_SETTINGS, $settings, false );
	$disabled = $inspect( $input );
	$check( ! $disabled['providerReady'] && 'native_generation_disabled' === $disabled['providerReason'] && count( $calls ) === $before, 'Disabling native generation leaves brief inspection available with no outbound request' );
	$usage_owner = ( new ReflectionClass( DSA\AI\AI_Usage_Service::class ) )->getFileName();
	$check( realpath( DSA_DIR . 'includes/AI/AI_Usage_Service.php' ) === realpath( $usage_owner ), 'The image path uses Core’s existing shared usage owner' );
	$allowance = ( new DSA\AI\AI_Allowance_Service( new DSA\Settings() ) )->status();
	$expected_usage = 420 * ( count( $calls ) - ( $unreported_units > 0 ? 1 : 0 ) ) + $unreported_units;
	$check( $allowance['used']['day'] === count( $calls ) && $allowance['tokenBudget']['used']['day'] === $expected_usage, 'Native allowance records each provider attempt, using reported usage or retaining its estimate' );
	return [ 'products'=>$receipts, 'editorialDraft'=>get_post_status( $post['id'] ), 'simulatedProviderCalls'=>count( $calls ), 'externalRequests'=>0 ];
}
