<?php

namespace DSA\Rest;

use DSA\Rewards\Reward_Service;
use DSA\Utilities\Origin_Checker;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rewards_Controller {
	public function __construct( private Reward_Service $rewards ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/rewards/session',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'start_session' ],
				'permission_callback' => [ $this, 'can_use_rewards' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/rewards/attempt',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'complete_attempt' ],
				'permission_callback' => [ $this, 'can_use_rewards' ],
			]
		);
	}

	public function can_use_rewards( WP_REST_Request $request ) {
		$allowed = Origin_Checker::mutation_allowed( $request );
		return true !== $allowed ? $allowed : Origin_Checker::transient_rate_limit( 'dsa_rewards', 60 );
	}

	public function start_session( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];
		$result = $this->rewards->start_attempt( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	public function complete_attempt( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : [];
		$result = $this->rewards->complete_attempt( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}
}
