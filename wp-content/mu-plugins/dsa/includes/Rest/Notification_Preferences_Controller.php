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
		register_rest_route( 'dsa/v1', '/app/activity', [ 'methods'=>'POST', 'callback'=>[ $this,'app_activity' ], 'permission_callback'=>[ $this,'can_record_app' ] ] );
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
		return $this->private_response( [ 'ok' => true, 'preferences' => $this->preferences->preferences( (string) $request->get_param( 'visitorId' ), (bool) $request->get_param( 'standalone' ) ), 'config' => $this->preferences->private_config() ] );
	}

	public function save( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		return $this->private_response( $this->preferences->save( is_array( $params ) ? $params : [] ) );
	}

	public function app_activity( WP_REST_Request $request ): WP_REST_Response {
		if ( 'POST' !== $request->get_method() || true !== Origin_Checker::mutation_allowed($request) ) { $denied=$this->private_response(['ok'=>false,'message'=>'App activity is unavailable.']);$denied->set_status(403);return $denied; }
		$params=$request->get_json_params();$result=$this->preferences->record_app_activity(is_array($params)?$params:[]);
		$response=$this->private_response($result);if(empty($result['ok']))$response->set_status((int)($result['status']??400));return $response;
	}
	public function can_record_app( WP_REST_Request $request ) {
		$allowed=Origin_Checker::mutation_allowed($request);
		return true!==$allowed?$allowed:Origin_Checker::transient_rate_limit('kiwe_app_lifecycle',120);
	}

	private function private_response( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}
}
