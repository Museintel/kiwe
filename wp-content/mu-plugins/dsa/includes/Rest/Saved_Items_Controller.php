<?php

namespace DSA\Rest;

use DSA\Saved\Saved_Items_Service;
use DSA\Utilities\Origin_Checker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Saved_Items_Controller {
	public function __construct( private Saved_Items_Service $saved_items ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route( 'dsa/v1', '/saved-items', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'can_access' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'mutate' ],
				'permission_callback' => [ $this, 'can_mutate' ],
			],
		] );
	}

	public function can_access(): bool {
		return Origin_Checker::is_same_site_request();
	}

	public function can_mutate( WP_REST_Request $request ) {
		$allowed = Origin_Checker::mutation_allowed( $request );
		return true !== $allowed ? $allowed : Origin_Checker::transient_rate_limit( 'dsa_saved_items', 60 );
	}

	public function get_items(): WP_REST_Response {
		return new WP_REST_Response( [ 'ok' => true, 'items' => $this->saved_items->current_items(), 'userId' => get_current_user_id() ], 200 );
	}

	public function mutate( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];
		$result = $this->saved_items->mutate( sanitize_key( (string) ( $params['action'] ?? 'add' ) ), is_array( $params['item'] ?? null ) ? $params['item'] : [] );
		if ( empty( $result['ok'] ) ) {
			return new WP_Error( 'dsa_saved_item_invalid', (string) ( $result['message'] ?? __( 'This item could not be saved.', 'dsa' ) ), [ 'status' => 400 ] );
		}

		return new WP_REST_Response( $result, 200 );
	}
}
