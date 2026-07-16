<?php

namespace DSA\Rest;

use DSA\Search\Search_Service;
use DSA\Utilities\Origin_Checker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Search_Controller {
	public function __construct( private Search_Service $search ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/search',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'results' ],
				'permission_callback' => [ $this, 'can_search' ],
				'args'                => [
					'q'     => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'limit' => [ 'type' => 'integer', 'default' => 6, 'minimum' => 1, 'maximum' => 12, 'sanitize_callback' => 'absint' ],
					'prefix' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'scope' => [
						'type'              => 'string',
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ): bool => in_array( $value, [ 'all', 'products', 'posts', 'authors', 'categories' ], true ),
					],
				],
			]
		);
	}

	public function can_search(): bool {
		return Origin_Checker::is_same_site_request();
	}

	public function results( WP_REST_Request $request ) {
		if ( ! Origin_Checker::transient_rate_limit( 'dsa_search', 90 ) ) {
			return new WP_Error( 'dsa_search_rate_limited', __( 'Please wait a moment before searching again.', 'dsa' ), [ 'status' => 429 ] );
		}

		$response = new WP_REST_Response(
			$this->search->results(
				(string) $request->get_param( 'q' ),
				(int) $request->get_param( 'limit' ),
				(string) $request->get_param( 'scope' ),
				(string) $request->get_param( 'prefix' )
			),
			200
		);
		$response->header( 'Cache-Control', 'private, max-age=60, stale-while-revalidate=240' );

		return $response;
	}
}
