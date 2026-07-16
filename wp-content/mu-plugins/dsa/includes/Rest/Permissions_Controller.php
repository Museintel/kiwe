<?php

namespace DSA\Rest;

use DSA\Permissions\Permission_Journey_Service;
use DSA\Utilities\Origin_Checker;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Permissions_Controller {
	private $journey;

	public function __construct( Permission_Journey_Service $journey ) {
		$this->journey = $journey;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/permissions/decision',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'decision' ],
				'permission_callback' => [ $this, 'can_record' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/permissions/outcome',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'outcome' ],
				'permission_callback' => [ $this, 'can_record' ],
			]
		);
	}

	public function can_record( WP_REST_Request $request ) {
		$allowed = Origin_Checker::mutation_allowed( $request );
		return true !== $allowed ? $allowed : Origin_Checker::transient_rate_limit( 'dsa_permission_rate', 60 );
	}

	public function decision( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];

		return new WP_REST_Response( $this->journey->decision( $params ), 200 );
	}

	public function outcome( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];

		return new WP_REST_Response( $this->journey->record( $params ), 200 );
	}

}
