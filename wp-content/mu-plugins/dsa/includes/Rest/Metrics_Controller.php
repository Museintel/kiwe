<?php

namespace DSA\Rest;

use DSA\Metrics\Metrics_Service;
use DSA\Utilities\Origin_Checker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Metrics_Controller {
	public function __construct( private Metrics_Service $metrics ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/metrics/event',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'record_event' ],
				'permission_callback' => [ $this, 'can_record' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/metrics/summary',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'summary' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/metrics/reset',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
	}

	public function can_record( WP_REST_Request $request ) {
		$allowed = Origin_Checker::mutation_allowed( $request );
		return true !== $allowed ? $allowed : Origin_Checker::transient_rate_limit( 'dsa_metrics_rate', 120 );
	}

	public function can_manage( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return 'GET' === $request->get_method() ? true : Origin_Checker::mutation_allowed( $request );
	}

	public function record_event( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];

		return new WP_REST_Response( $this->metrics->record( $params ), 200 );
	}

	public function summary(): WP_REST_Response {
		return new WP_REST_Response( $this->metrics->summary(), 200 );
	}

	public function reset( WP_REST_Request $request ) {
		$confirm = sanitize_text_field( (string) $request->get_param( 'confirm' ) );

		if ( 'reset' !== $confirm ) {
			return new WP_Error( 'dsa_metrics_reset_confirm', __( 'Confirm reset before clearing metrics.', 'dsa' ), [ 'status' => 400 ] );
		}

		$this->metrics->reset();

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

}
