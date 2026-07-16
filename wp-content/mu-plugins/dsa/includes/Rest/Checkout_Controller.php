<?php

namespace DSA\Rest;

use DSA\Commerce\Checkout_Service;
use DSA\Utilities\Origin_Checker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Checkout_Controller {
	private $checkout;

	public function __construct( Checkout_Service $checkout ) {
		$this->checkout = $checkout;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/checkout',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_checkout' ],
					'permission_callback' => [ $this, 'same_site' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'save_checkout' ],
					'permission_callback' => [ $this, 'can_mutate' ],
				],
			]
		);
	}

	public function same_site(): bool {
		return Origin_Checker::is_same_site_request();
	}

	public function can_mutate( WP_REST_Request $request ) {
		return Origin_Checker::mutation_allowed( $request );
	}

	public function get_checkout( WP_REST_Request $request ): WP_REST_Response {
		$consume = rest_sanitize_boolean( $request->get_param( 'consumeErrors' ) );

		return new WP_REST_Response(
			[
				'ok'       => true,
				'checkout' => $this->checkout->contract( $consume ),
			],
			200
		);
	}

	public function save_checkout( WP_REST_Request $request ) {
		if ( ! Origin_Checker::transient_rate_limit( 'dsa_checkout', 90 ) ) {
			return new WP_Error( 'dsa_checkout_rate_limited', __( 'Please wait a moment before updating checkout again.', 'dsa' ), [ 'status' => 429 ] );
		}

		$fields = $request->get_param( 'fields' );
		$fields = is_array( $fields ) ? $fields : [];
		$result = $this->checkout->save_draft( $fields, rest_sanitize_boolean( $request->get_param( 'validate' ) ) );

		return new WP_REST_Response( $result, 200 );
	}
}
