<?php

namespace DSA\Rest;

use DSA\Diagnostics\Apex_Acceptance_Service;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Apex_Profile_Controller {
	public function __construct( private Apex_Acceptance_Service $service ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/apex-profile',
			[
				'methods' => 'GET',
				'callback' => [ $this, 'profile' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function profile(): WP_REST_Response {
		$response = new WP_REST_Response( $this->service->public_profile(), 200 );
		$response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=3600' );
		$response->header( 'X-Kiwe-Runtime-Profile', 'apex-v1' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		return $response;
	}
}
