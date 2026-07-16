<?php

namespace DSA\Rest;

use DSA\Notifications\Notification_Preference_Service;
use DSA\Utilities\Origin_Checker;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notification_Preferences_Controller {
	private $preferences;

	public function __construct( Notification_Preference_Service $preferences ) {
		$this->preferences = $preferences;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/notification-preferences',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'read' ],
					'permission_callback' => [ $this, 'can_read' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'can_write' ],
				],
			]
		);
	}

	public function can_read(): bool {
		return Origin_Checker::is_same_site_request() && Origin_Checker::transient_rate_limit( 'dsa_notification_preferences_read', 120 );
	}

	public function can_write( WP_REST_Request $request ) {
		$allowed = Origin_Checker::mutation_allowed( $request );
		return true !== $allowed ? $allowed : Origin_Checker::transient_rate_limit( 'dsa_notification_preferences_write', 40 );
	}

	public function read( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			[ 'ok' => true, 'preferences' => $this->preferences->preferences( (string) $request->get_param( 'visitorId' ), (bool) $request->get_param( 'standalone' ) ) ],
			200
		);
	}

	public function save( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		return new WP_REST_Response( $this->preferences->save( is_array( $params ) ? $params : [] ), 200 );
	}
}
