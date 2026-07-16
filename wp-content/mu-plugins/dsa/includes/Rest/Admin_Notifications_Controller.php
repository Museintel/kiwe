<?php

namespace DSA\Rest;

use DSA\Notifications\Admin_Event_Notification_Service;
use DSA\Utilities\Origin_Checker;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Notifications_Controller {
	private $notifications;

	public function __construct( Admin_Event_Notification_Service $notifications ) {
		$this->notifications = $notifications;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route( 'dsa/v1', '/admin-notifications', [
			[
				'methods' => 'GET',
				'callback' => [ $this, 'read' ],
				'permission_callback' => [ $this, 'can_read' ],
			],
			[
				'methods' => 'POST',
				'callback' => [ $this, 'acknowledge' ],
				'permission_callback' => [ $this, 'can_write' ],
			],
		] );
	}

	public function can_read(): bool {
		return is_user_logged_in()
			&& ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'moderate_comments' ) )
			&& Origin_Checker::is_same_site_request()
			&& Origin_Checker::transient_rate_limit( 'dsa_admin_notifications_read', 30 );
	}

	public function can_write( WP_REST_Request $request ) {
		$authorized = is_user_logged_in()
			&& ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'moderate_comments' ) )
			&& Origin_Checker::transient_rate_limit( 'dsa_admin_notifications_write', 60 );

		return $authorized ? Origin_Checker::mutation_allowed( $request ) : false;
	}

	public function read(): WP_REST_Response {
		return new WP_REST_Response( [ 'ok' => true, 'notifications' => $this->notifications->pull_current_user() ], 200 );
	}

	public function acknowledge( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$ok = $this->notifications->acknowledge_current_user( is_array( $params ) ? (string) ( $params['id'] ?? '' ) : '' );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
	}
}
