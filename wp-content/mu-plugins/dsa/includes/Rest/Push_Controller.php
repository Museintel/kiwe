<?php

namespace DSA\Rest;

use DSA\Notifications\Push_Service;
use DSA\Utilities\Origin_Checker;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Push_Controller {
	private $push;

	public function __construct( Push_Service $push ) {
		$this->push = $push;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route( 'dsa/v1', '/push/subscription', [
			[
				'methods' => 'POST',
				'callback' => [ $this, 'save' ],
				'permission_callback' => [ $this, 'can_write' ],
			],
			[
				'methods' => 'DELETE',
				'callback' => [ $this, 'remove' ],
				'permission_callback' => [ $this, 'can_write' ],
			],
		] );
	}

	public function can_write( WP_REST_Request $request ) {
		$allowed = Origin_Checker::mutation_allowed( $request );
		return true !== $allowed ? $allowed : Origin_Checker::transient_rate_limit( 'dsa_push_subscription_write', 30 );
	}

	public function save( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$result = $this->push->save_subscription( is_array( $params ) ? $params : [] );
		$status = ! empty( $result['ok'] ) ? 200 : max( 400, min( 499, absint( $result['status'] ?? 400 ) ) );
		return new WP_REST_Response( $result, $status );
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$result = $this->push->remove_subscription( is_array( $params ) ? $params : [] );
		$status = ! empty( $result['ok'] ) ? 200 : max( 400, min( 499, absint( $result['status'] ?? 400 ) ) );
		return new WP_REST_Response( $result, $status );
	}
}
