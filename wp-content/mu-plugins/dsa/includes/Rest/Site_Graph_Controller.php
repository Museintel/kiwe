<?php

namespace DSA\Rest;

use DSA\AI\Site_Graph_Service;
use DSA\Site_Graph\Data_Query_Service;
use DSA\Site_Graph\Design_Context_Service;
use DSA\Site_Graph\Query_Service;
use DSA\Site_Graph\Calibration_Pairing_Service;
use DSA\Utilities\Origin_Checker;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Graph_Controller {
	private Data_Query_Service $data_query;
	private Query_Service $query;
	private Calibration_Pairing_Service $pairing;

	public function __construct( private Site_Graph_Service $site_graph, ?Query_Service $query = null, ?Data_Query_Service $data_query = null ) {
		$this->query      = $query ?: new Query_Service();
		$this->data_query = $data_query ?: new Data_Query_Service();
		$this->pairing    = new Calibration_Pairing_Service( $site_graph );
	}

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

		register_rest_route(
			'dsa/v1',
			'/site-graph/summary',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'summary' ],
				'permission_callback' => [ $this, 'can_manage_options' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/site-graph/calibration',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'calibration' ],
				'permission_callback' => [ $this, 'can_manage_options' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/site-graph/calibration/pair/(?P<pair_id>[a-f0-9]{24})',
			[
				'methods'             => [ 'GET', 'OPTIONS' ],
				'callback'            => [ $this, 'paired_calibration' ],
				'permission_callback' => '__return_true',
			],
		);

		register_rest_route(
			'dsa/v1',
			'/site-graph/query',
			[
				'methods'             => [ 'GET', 'POST' ],
				'callback'            => [ $this, 'query' ],
				'permission_callback' => [ $this, 'can_manage_options' ],
				'args'                => [
					'select' => [
						'type' => [ 'string', 'array' ],
					],
					'path' => [
						'type' => [ 'string', 'array' ],
					],
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

		register_rest_route(
			'dsa/v1',
			'/site-graph/data/schema',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'data_schema' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'dsa/v1',
			'/site-graph/data',
			[
				'methods'             => [ 'GET', 'POST' ],
				'callback'            => [ $this, 'data' ],
				'permission_callback' => '__return_true',
			]
		);

	}

	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	public function graph( WP_REST_Request $request ): WP_REST_Response {
		$args = $request->get_params();
		unset( $args['rest_route'] );
		$response = new WP_REST_Response( ( new Design_Context_Service( $this->site_graph, $this->data_query ) )->context( $args, true ), 200 );

		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}

	public function summary(): WP_REST_Response {
		$response = new WP_REST_Response( $this->site_graph->summary(), 200 );
		$this->no_store( $response );

		return $response;
	}

	public function calibration(): WP_REST_Response {
		$response = new WP_REST_Response( $this->site_graph->calibration_profile(), 200 );
		$this->no_store( $response );
		$response->header( 'Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox" );
		return $response;
	}

	public function paired_calibration( WP_REST_Request $request ) {
		$origin = untrailingslashit( esc_url_raw( (string) $request->get_header( 'origin' ) ) );
		$allowed = $this->pairing->allowed_origin();
		if ( 'OPTIONS' === $request->get_method() ) {
			if ( ! hash_equals( $allowed, $origin ) ) {
				return new \WP_Error( 'dsa_calibration_origin_denied', __( 'This origin cannot pair with Kiwe.', 'dsa' ), [ 'status' => 403 ] );
			}
			$response = new WP_REST_Response( null, 204 );
			$this->pairing_headers( $response, $allowed );
			return $response;
		}
		if ( ! Origin_Checker::transient_rate_limit( 'dsa_sitegraph_calibration_pair', 20 ) ) {
			return new \WP_Error( 'dsa_calibration_rate_limited', __( 'Too many calibration attempts. Wait one minute.', 'dsa' ), [ 'status' => 429 ] );
		}
		$authorization = trim( (string) $request->get_header( 'authorization' ) );
		$secret        = preg_match( '/^Bearer\s+([a-f0-9]{64})$/i', $authorization, $matches ) ? strtolower( $matches[1] ) : '';
		$payload       = $this->pairing->consume( sanitize_key( (string) $request['pair_id'] ), $secret, $origin );
		if ( is_wp_error( $payload ) ) {
			$data = $payload->get_error_data();
			$response = new WP_REST_Response(
				[ 'code' => $payload->get_error_code(), 'message' => $payload->get_error_message() ],
				is_array( $data ) ? (int) ( $data['status'] ?? 403 ) : 403
			);
			$this->pairing_headers( $response, $allowed );
			return $response;
		}
		$response = new WP_REST_Response( $payload, 200 );
		$this->pairing_headers( $response, $allowed );
		return $response;
	}

	private function pairing_headers( WP_REST_Response $response, string $origin ): void {
		$this->no_store( $response );
		$response->header( 'Access-Control-Allow-Origin', $origin );
		$response->header( 'Access-Control-Allow-Methods', 'GET, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type' );
		$response->header( 'Vary', 'Origin' );
		$response->header( 'Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox" );
	}

	public function query( WP_REST_Request $request ): WP_REST_Response {
		$graph = $this->site_graph->graph(
			[
				'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ),
			]
		);
		$args  = [
			'select' => $request->get_param( 'select' ),
			'path'   => $request->get_param( 'path' ),
		];

		$response = new WP_REST_Response( $this->query->query( $graph, $args ), 200 );
		$this->no_store( $response );

		return $response;
	}

	public function data_schema(): WP_REST_Response {
		$response = new WP_REST_Response( $this->data_query->schema(), 200 );
		$this->public_cache( $response );

		return $response;
	}

	public function data( WP_REST_Request $request ): WP_REST_Response {
		$args  = $request->get_query_params();
		$params = $request->get_params();
		if ( is_array( $params ) ) {
			$args = array_replace_recursive( $args, $params );
		}

		$body = $request->get_json_params();
		if ( is_array( $body ) ) {
			$args = array_replace_recursive( $args, $body );
		}

		unset( $args['rest_route'] );

		$private  = current_user_can( 'manage_options' );
		$response = new WP_REST_Response( $this->data_query->query( $args, $private ), 200 );

		if ( $private ) {
			$this->no_store( $response );
		} else {
			$this->public_cache( $response );
		}

		return $response;
	}

	private function no_store( WP_REST_Response $response ): void {
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
	}

	private function public_cache( WP_REST_Response $response ): void {
		$response->header( 'Cache-Control', 'public, max-age=60' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
	}
}
