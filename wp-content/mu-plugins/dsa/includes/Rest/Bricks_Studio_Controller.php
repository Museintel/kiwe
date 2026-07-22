<?php

namespace DSA\Rest;

use DSA\AI\AI_Companion_Memory_Service;
use DSA\AI\AI_Companion_Service;
use DSA\AI\AI_Provider_Service;
use DSA\AI\Bricks_AI_Intelligence_Service;
use DSA\AI\Site_Graph_Service;
use DSA\AI\Studio_AI_Service;
use DSA\Settings;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bricks_Studio_Controller {
	public function __construct(
		private Settings $settings,
		private Site_Graph_Service $site_graph
	) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/bricks/studio/context',
			[
				'methods'             => [ 'GET', 'POST' ],
				'callback'            => fn( WP_REST_Request $request ) => $this->response( ( new Bricks_AI_Intelligence_Service( $this->settings ) )->context( $this->args( $request ) ) ),
				'permission_callback' => [ $this, 'can_use_builder_ai' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/bricks/studio/start',
			[
				'methods'             => 'POST',
				'callback'            => fn( WP_REST_Request $request ) => $this->response( $this->studio()->start_project( $this->args( $request ), $this->auth_record( false ) ) ),
				'permission_callback' => [ $this, 'can_use_builder_ai' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/bricks/studio/draft',
			[
				'methods'             => 'POST',
				'callback'            => fn( WP_REST_Request $request ) => $this->draft_response( $request ),
				'permission_callback' => [ $this, 'can_use_builder_ai' ],
			]
		);
	}

	public function can_use_builder_ai(): bool {
		return is_user_logged_in() && ( current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' ) );
	}

	private function studio(): Studio_AI_Service {
		return new Studio_AI_Service(
			$this->settings,
			$this->site_graph,
			new AI_Companion_Service( $this->settings, $this->site_graph, new AI_Companion_Memory_Service() ),
			new AI_Provider_Service( $this->settings )
		);
	}

	private function draft_response( WP_REST_Request $request ): WP_REST_Response {
		$args = $this->args( $request );
		$native = ! empty( $args['callNative'] ) && current_user_can( 'manage_options' );

		return $this->response( $this->studio()->draft( $args, $this->auth_record( $native ) ) );
	}

	private function auth_record( bool $native ): array {
		return [
			'record' => [
				'id'     => 'wp-admin-editor',
				'label'  => 'WordPress admin Bricks editor',
				'scopes' => $native ? [ 'admin', 'studio_ai', 'native_ai' ] : [ 'admin', 'studio_ai' ],
			],
		];
	}

	private function args( WP_REST_Request $request ): array {
		$args = $request->get_params();
		$body = $request->get_json_params();
		if ( is_array( $body ) ) {
			$args = array_replace_recursive( $args, $body );
		}
		unset( $args['rest_route'] );

		return $args;
	}

	private function response( array $data ): WP_REST_Response {
		$status = isset( $data['httpStatus'] ) ? max( 100, min( 599, (int) $data['httpStatus'] ) ) : 200;
		unset( $data['httpStatus'] );

		return new WP_REST_Response( $data, $status );
	}
}
