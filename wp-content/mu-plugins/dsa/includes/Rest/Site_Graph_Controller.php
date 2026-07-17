<?php

namespace DSA\Rest;

use DSA\AI\Site_Graph_Service;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Graph_Controller {
	public function __construct( private Site_Graph_Service $site_graph ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/site-graph',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'graph' ],
				'permission_callback' => [ $this, 'can_manage_options' ],
				'args'                => [
					'sampleLimit' => [
						'type'              => 'integer',
						'default'           => 8,
						'minimum'           => 0,
						'maximum'           => 24,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	public function graph( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response(
			$this->site_graph->graph(
				[
					'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ),
				]
			),
			200
		);

		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}
}
