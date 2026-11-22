<?php
/** Exercise the file handoff and native draft writers on a disposable WordPress installation. */
function kiwe_mcp_connected_image_checks( callable $check, string $work ): array {
	if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_M10_DISPOSABLE' ) || ! KIWE_M10_DISPOSABLE ||
		home_url() !== 'https://kiwe-m10-commerce.example.invalid' || DB_NAME !== 'kiwe_m10_commerce' || ! str_starts_with( DB_HOST, '127.0.0.1:' ) ) {
		throw new RuntimeException( 'An owned local WooCommerce fixture is required.' );
	}
	$owner = DSA\Access\Site_Owner_Service::owner_id();
	wp_set_current_user( $owner );
	$check( DSA\Apps\Store_App_Service::enabled() && DSA\Apps\MCP_App_Service::enabled() && ! DSA\Apps\AI_App_Service::enabled(),
		'The connected image path starts with WooCommerce and MCP, with the website AI App absent' );
	update_option( 'woocommerce_currency', 'INR' );
	update_option( 'woocommerce_weight_unit', 'kg' );
	update_option( DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE,
		[ 'brand'=>[ 'tone'=>'natural', 'colors'=>[ [ 'role'=>'brand', 'hex'=>'#5d371f' ] ] ] ], false );
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$load_media = static function( string $file, string $title ): int {
		$tmp = wp_tempnam( basename( $file ) );
		try {
			if ( ! $tmp || ! copy( $file, $tmp ) ) throw new RuntimeException( 'Could not stage a fixture photo.' );
			$id = media_handle_sideload( [ 'name'=>basename( $file ), 'tmp_name'=>$tmp, 'error'=>0, 'size'=>filesize( $tmp ) ], 0, null, [ 'post_title'=>$title ] );
			if ( is_wp_error( $id ) || ! wp_attachment_is_image( $id ) ) throw new RuntimeException( 'WordPress did not admit a fixture photo.' );
			return (int) $id;
		} finally { if ( $tmp && is_file( $tmp ) ) wp_delete_file( $tmp ); }
	};
	$inputs = json_decode( file_get_contents( $work . '/mock-inputs.json' ), true, 16, JSON_THROW_ON_ERROR );
	$published = [];
	foreach ( $inputs['style'] as $item ) {
		$id = $load_media( $item['path'], $item['title'] );
		$product = new WC_Product_Simple();
		$product->set_name( $item['title'] );
		$product->set_image_id( $id );
		$product->set_status( 'publish' );
		$published[$product->save()] = $id;
	}
	$source_ids = [];
	foreach ( $inputs['subjects'] as $item ) $source_ids[] = $load_media( $item['path'], $item['title'] );
	$check( count( $published ) === 2 && count( $source_ids ) === 2, 'The mock Media Library contains two phone photos and two catalogue references from the connected store' );

	$registry = new DSA\MCP\Operation_Registry();
	DSA\MCP\Native_Provider::register( $registry );
	do_action( 'kiwe_mcp_register_providers', $registry, DSA\Apps\MCP_Provider_Contract::API );
	$auth = new DSA\MCP\Authorization_Service( $registry );
	$intents = new DSA\MCP\Intent_Service( $registry );
	$connect = static function( string $scope ) use ( $auth, $owner ): string {
		wp_set_current_user( $owner );
		$client = $auth->register_client( [ 'client_name'=>'Connected image mock client', 'redirect_uris'=>[ 'http://127.0.0.1/callback' ] ] )['client_id'];
		$browser = DSA\MCP\Credentials::secret();
		$verifier = DSA\MCP\Credentials::secret();
		$redirect = 'http://127.0.0.1:19655/callback';
		$resource = DSA\MCP\Urls::resource();
		$flow = $auth->begin( [ 'client_id'=>$client, 'redirect_uri'=>$redirect, 'response_type'=>'code',
			'code_challenge_method'=>'S256', 'code_challenge'=>rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
			'state'=>'local-connected-image', 'resource'=>$resource, 'scope'=>$scope ], $browser );
		$review = $auth->review( $flow, $browser, $owner );
		$approved = $auth->approve( $flow, $browser, $owner, true, false, $review['reviewDigest'] );
		return $auth->exchange( [ 'grant_type'=>'authorization_code', 'client_id'=>$client, 'redirect_uri'=>$redirect,
			'code_verifier'=>$verifier, 'code'=>$approved['code'], 'resource'=>$resource ] )['access_token'];
	};
	$scope = 'mcp.read mcp.visual.client.read mcp.visual.client.write store.authoring.read store.authoring.write';
	$token = $connect( $scope );
	$prior = $connect( 'mcp.read mcp.visual.read' );
	$other = $connect( $scope );
	$rest = static function( string $method, string $suffix, string $access, array $headers = [], string $body = '' ): array {
		DSA\MCP\Auth_Context::clear();
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( $method, '/kiwe-mcp/v1/' . $suffix );
		if ( $access !== '' ) $request->set_header( 'Authorization', 'Bearer ' . $access );
		foreach ( $headers as $name=>$value ) $request->set_header( $name, $value );
		if ( $body !== '' ) $request->set_body( $body );
		try { return [ rest_do_request( $request ), $request ]; }
		finally { DSA\MCP\Auth_Context::clear(); wp_set_current_user( 0 ); }
	};
	$rpc = static function( string $method, array $params, string $session = '' ) use ( $rest, $token ): WP_REST_Response {
		$headers = [ 'Accept'=>'application/json, text/event-stream', 'Content-Type'=>'application/json', 'MCP-Protocol-Version'=>'2025-11-25' ];
		if ( $session ) $headers['Mcp-Session-Id'] = $session;
		return $rest( 'POST', 'mcp', $token, $headers, wp_json_encode( [ 'jsonrpc'=>'2.0', 'id'=>1, 'method'=>$method, 'params'=>$params ] ) )[0];
	};
	$init = $rpc( 'initialize', [ 'protocolVersion'=>'2025-11-25', 'clientInfo'=>[ 'name'=>'Connected image mock', 'version'=>'1' ], 'capabilities'=>(object) [] ] );
	$session = $init->get_headers()['Mcp-Session-Id'] ?? '';
	$check( $init->get_status() === 200 && $session !== '', 'The real MCP JSON-RPC adapter opens a scoped client session' );
	$call = static function( string $tool, array $arguments = [], bool $expect_error = false ) use ( $rpc, $session ): array {
		$r = $rpc( 'tools/call', [ 'name'=>'kiwe-mcp-' . $tool, 'arguments'=>(object) $arguments ], $session );
		$packet = json_decode( wp_json_encode( $r->get_data() ), true );
		$result = $packet['result'] ?? [];
		$error = $r->get_status() !== 200 || isset( $packet['error'] ) || ! empty( $result['isError'] );
		if ( $error !== $expect_error ) throw new RuntimeException( 'Unexpected MCP ' . $tool . ' outcome (HTTP ' . $r->get_status() . '). ' .
			substr( (string) ( $packet['error']['message'] ?? $result['content'][0]['text'] ?? '' ), 0, 240 ) );
		return $error ? [] : ( $result['structuredContent'] ?? json_decode( $result['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR ) );
	};
	$prepare = static fn( string $operation, array $args ): array => $call( 'prepare-change', [ 'operation'=>$operation, 'arguments'=>$args, 'requestId'=>bin2hex( random_bytes( 16 ) ) ] );
	$apply = static fn( array $change, bool $error = false ): array => $call( 'apply-change', [ 'intent'=>$change['id'] ], $error );
	$save = static function( string $op, array $fields ) use ( $prepare, $apply, $intents, $auth, $owner ): array {
		$change = $prepare( $op, $fields );
		if ( $change['approvalRequired'] ) {
			wp_set_current_user( $owner );
			try { $intents->approve( $change['id'], $auth, true ); }
			finally { wp_set_current_user( 0 ); }
		}
		$result = $apply( $change );
		if ( $result['state'] !== 'complete' ) throw new RuntimeException( 'The draft writer did not confirm its save.' );
		return json_decode( $result['result']['resultJson'], true, 24, JSON_THROW_ON_ERROR );
	};
	$ops = array_column( $call( 'discover' )['operations'], 'id' );
	$check( in_array( 'mcp.visual.client.sources', $ops, true ) && in_array( 'mcp.visual.client.receive', $ops, true ) &&
		! in_array( 'mcp.visual.generate', $ops, true ) && ! in_array( 'store.product.publish', $ops, true ),
		'The connected image grant has only its own image workflow and draft authority' );

	$title = 'Khajur Dry Fruit Pedha';
	$product = $save( 'store.product.create', [ 'fieldsJson'=>wp_json_encode( [ 'name'=>$title, 'type'=>'simple' ] ) ] );
	$id = (int) $product['id'];
	$save( 'store.product.save', [ 'id'=>$id, 'fieldsJson'=>wp_json_encode( [ 'price'=>'250' ] ) ] );
	$save( 'store.product.delivery', [ 'id'=>$id, 'fieldsJson'=>wp_json_encode( [ 'weight'=>'0.2' ] ) ] );
	$brief = [ 'targetType'=>'product', 'targetId'=>$id, 'sourceIds'=>$source_ids,
		'direction'=>'A clean square catalogue photograph. Keep the closed box and existing print faithful. Match the warm timber, understated brass, and soft natural light of the store references.' ];
	$context = DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE;
	$store_style = get_option( $context );
	wp_set_current_user( $owner );
	foreach ( array_keys( $published ) as $public_id ) wp_update_post( [ 'ID'=>$public_id, 'post_status'=>'draft' ] );
	update_option( $context, [ 'brand'=>[ 'tone'=>'calm', 'colors'=>[ [ 'role'=>'brand', 'hex'=>'#355B6F' ] ] ] ], false );
	try {
		$fallback = json_decode( $call( 'read', [ 'operation'=>'mcp.visual.client.sources', 'arguments'=>array_diff_key( $brief, [ 'direction'=>true ] ) ] )['resultJson'], true, 32, JSON_THROW_ON_ERROR );
		$check( $fallback['styleSource'] === 'brand' && count( $fallback['files'] ) === 2 && str_contains( strtolower( $fallback['prompt'] ), '#355b6f' ),
			'A site without suitable catalogue photos uses its own Design Context and receives no external style images' );
	} finally {
		wp_set_current_user( $owner );
		update_option( $context, $store_style, false );
		foreach ( array_keys( $published ) as $public_id ) wp_update_post( [ 'ID'=>$public_id, 'post_status'=>'publish' ] );
		wp_set_current_user( 0 );
	}
	$handoff = json_decode( $call( 'read', [ 'operation'=>'mcp.visual.client.sources', 'arguments'=>$brief ] )['resultJson'], true, 32, JSON_THROW_ON_ERROR );
	$check( $handoff['ratio'] === '1:1' && $handoff['styleSource'] === 'website' && count( $handoff['files'] ) === 4 &&
		str_contains( $handoff['prompt'], 'keep it closed' ) && str_contains( $handoff['prompt'], '#5d371f' ), 'MCP selected the local catalogue style and sent a source-faithful square image brief' );
	$paths = [];
	foreach ( $handoff['files'] as $index=>$file ) {
		$headers = [ $handoff['proofHeader']=>$file['proof'] ];
		[ $response, $request ] = $rest( 'GET', 'visual/source', $token, $headers );
		$data = $response->get_data();
		$check( $response->get_status() === 200 && is_string( $data ) && strlen( $data ) === $file['bytes'] &&
			hash( 'sha256', $data ) === $file['sha256'] && $response->get_headers()['Content-Type'] === $file['mime'],
			'Authorized source file ' . ( $index + 1 ) . ' matches the brief and its checksum' );
		ob_start();
		try { $served = apply_filters( 'rest_pre_serve_request', false, $response, $request, rest_get_server() ); }
		finally { $wire = ob_get_clean(); }
		$check( $served && $wire === $data, 'The file route serves binary image bytes outside JSON for source ' . ( $index + 1 ) );
		$path = $work . '/mcp-inputs/' . ( $index + 1 ) . '-' . $file['role'] . '.' . ( $file['mime'] === 'image/png' ? 'png' : 'jpg' );
		if ( file_put_contents( $path, $data, LOCK_EX ) !== strlen( $data ) ) throw new RuntimeException( 'Could not deliver a fixture image.' );
		$paths[] = [ 'path'=>$path, 'role'=>$file['role'], 'title'=>$file['title'], 'sha256'=>$file['sha256'] ];
	}
	$proof = $handoff['files'][0]['proof'];
	$headers = [ $handoff['proofHeader']=>$proof ];
	$check( $rest( 'GET', 'visual/source', '', $headers )[0]->get_status() === 401 &&
		$rest( 'GET', 'visual/source', $prior, $headers )[0]->get_status() === 403 &&
		$rest( 'GET', 'visual/source', $other, $headers )[0]->get_status() === 409 &&
		$rest( 'GET', 'visual/source', $token, $headers + [ 'Origin'=>'https://unrelated.example.invalid' ] )[0]->get_status() === 403 &&
		$rest( 'GET', 'visual/source', $token, [ $handoff['proofHeader']=> $proof . '0' ] )[0]->get_status() === 409,
		'The source route rejects missing grants, older permissions, other connections, foreign origins and changed proofs' );

	$signal = $work . '/client-candidate.png';
	$ready = $work . '/client-candidate.ready';
	$published_style = array_column( $inputs['style'], 'title' );
	$file_receipt = [ 'title'=>$title, 'ratio'=>$handoff['ratio'], 'prompt'=>$handoff['prompt'], 'files'=>$paths, 'references'=>$published_style ];
	file_put_contents( $work . '/image-handoff.pending', wp_json_encode( $file_receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
	rename( $work . '/image-handoff.pending', $work . '/image-handoff.json' );
	$deadline = time() + 540;
	while ( ! is_file( $ready ) && time() < $deadline ) usleep( 250000 );
	if ( ! is_file( $ready ) || ! is_file( $signal ) || is_link( $signal ) ) throw new RuntimeException( 'The connected image tool did not return a candidate before the fixture expired.' );
	$bytes = file_get_contents( $signal, false, null, 0, $handoff['maxUploadBytes'] + 1 );
	if ( ! is_string( $bytes ) || strlen( $bytes ) > $handoff['maxUploadBytes'] ) throw new RuntimeException( 'The client candidate exceeds the upload limit.' );
	$fields = [ 'brief'=>$brief, 'briefHash'=>$handoff['briefHash'], 'sha256'=>hash( 'sha256', $bytes ), 'mime'=>'image/png', 'bytes'=>strlen( $bytes ) ];
	$call( 'prepare-change', [ 'operation'=>'mcp.visual.client.receive', 'arguments'=>array_replace( $fields, [ 'briefHash'=>str_repeat( '0', 64 ) ] ),
		'requestId'=>bin2hex( random_bytes( 16 ) ) ], true );
	$change = $prepare( 'mcp.visual.client.receive', $fields );
	$upload_headers = [ 'Content-Type'=>'image/png', $handoff['intentHeader']=>$change['id'] ];
	$upload = static fn( string $body ): WP_REST_Response => $rest( 'POST', 'visual/result', $token, $upload_headers, $body )[0];
	$check( $change['approvalRequired'] && str_contains( $change['after'], $fields['sha256'] ) && $upload( $bytes )->get_status() === 409 &&
		(int) get_post_thumbnail_id( $id ) === 0, 'The exact client result needs native review before WordPress saves it' );
	wp_set_current_user( $owner );
	$intents->approve( $change['id'], $auth, true );
	wp_set_current_user( 0 );
	$apply( $change, true );
	$check( $upload( 'a different file' )->get_status() === 409 && $call( 'change-status', [ 'intent'=>$change['id'] ] )['state'] === 'prepared',
		'An empty tool apply or different upload leaves the approved exact file recoverable' );
	$receipt = $upload( $bytes )->get_data();
	$check( $receipt['state'] === 'complete' && $upload( $bytes )->get_data() === $receipt, 'The authorized upload completes once and an identical retry returns the same receipt' );
	$candidate = json_decode( $receipt['result']['resultJson'], true, 24, JSON_THROW_ON_ERROR );
	$image_id = $candidate['attachmentId'];
	$native = get_post( $image_id );
	$origin = get_post_meta( $image_id, '_kiwe_featured_image_v1', true );
	$check( $native && (int) $native->post_parent === 0 && ! $candidate['assigned'] && $candidate['model'] === 'connected_client' &&
		$origin['fileSha256'] === $fields['sha256'] && $origin['sourceIds'] === $source_ids && (int) get_post_thumbnail_id( $id ) === 0,
		'The exact client file is saved as a separate native candidate with verified lineage' );
	$save( 'store.product.save', [ 'id'=>$id, 'fieldsJson'=>wp_json_encode( [ 'imageId'=>$image_id ] ) ] );
	$verified = wc_get_product( $id );
	$check( $verified->get_status() === 'draft' && (int) $verified->get_image_id() === $image_id &&
		(float) $verified->get_regular_price() === 250.0 && (int) wc_get_weight( $verified->get_weight(), 'g' ) === 200 &&
		get_woocommerce_currency() === 'INR', 'The native Store writer assigns the candidate to the ₹250 and 200 g draft' );
	foreach ( $published as $public_id=>$original_image ) {
		$check( (int) wc_get_product( $public_id )->get_image_id() === $original_image, 'A published reference product keeps its original attachment ' . $original_image );
	}
	$check( ! get_option( 'kiwe_ai_allowance_v1', null ), 'Connected generation does not reserve the website AI allowance' );
	return [
		'product'=>[ 'id'=>$id, 'title'=>$title, 'status'=>$verified->get_status(), 'price'=>250, 'currency'=>'INR', 'weightGrams'=>200 ],
		'image'=>[ 'id'=>$image_id, 'width'=>$candidate['width'], 'height'=>$candidate['height'], 'sha256'=>$fields['sha256'] ],
		'inputs'=>count( $source_ids ), 'styleReferences'=>$published_style, 'provider'=>'connected_client',
	];
}
