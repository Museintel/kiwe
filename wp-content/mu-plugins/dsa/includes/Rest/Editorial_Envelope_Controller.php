<?php

namespace DSA\Rest;

use DSA\Runtime\Editorial_Fragment_Service;
use DSA\Utilities\Origin_Checker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Editorial_Envelope_Controller {
	public function __construct( private Editorial_Fragment_Service $service ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/editorial-envelope',
			[
				'methods' => 'GET',
				'callback' => [ $this, 'envelope' ],
				'permission_callback' => static fn(): bool => Origin_Checker::is_same_site_request(),
				'args' => [
					'url' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' ],
				],
			]
		);
		register_rest_route(
			'dsa/v1',
			'/offline-editorial',
			[
				'methods' => 'GET',
				'callback' => [ $this, 'offline_editorial' ],
				'permission_callback' => static fn(): bool => Origin_Checker::is_same_site_request(),
				'args' => [
					'url' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' ],
				],
			]
		);
	}

	public function envelope( WP_REST_Request $request ) {
		if ( ! Origin_Checker::transient_rate_limit( 'dsa_editorial_envelope', 30 ) ) {
			return new WP_Error( 'dsa_envelope_rate_limited', __( 'Please wait before requesting another editorial envelope.', 'dsa' ), [ 'status' => 429 ] );
		}

		$data = $this->service->envelope( (string) $request->get_param( 'url' ) );
		$response = new WP_REST_Response( $data, ! empty( $data['ok'] ) ? 200 : 422 );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'Vary', 'Cookie' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		$response->header( 'X-Kiwe-Envelope', 'editorial-v1-observe-only' );
		return $response;
	}

	public function offline_editorial( WP_REST_Request $request ) {
		if ( ! Origin_Checker::transient_rate_limit( 'dsa_offline_editorial', 60 ) ) {
			return new WP_Error( 'dsa_offline_editorial_rate_limited', __( 'Please wait before caching more editorial content.', 'dsa' ), [ 'status' => 429 ] );
		}

		$data = $this->service->offline_document( (string) $request->get_param( 'url' ) );
		$response = new WP_REST_Response( $data, ! empty( $data['ok'] ) ? 200 : 422 );
		if ( ! empty( $data['offlineReady'] ) ) {
			$response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=86400' );
			$response->header( 'X-Kiwe-Offline-Policy', 'public-editorial-v1' );
		} else {
			$response->header( 'Cache-Control', 'private, no-store' );
		}
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		return $response;
	}
}
