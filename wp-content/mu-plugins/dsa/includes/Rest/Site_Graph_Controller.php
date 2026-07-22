<?php

namespace DSA\Rest;

use DSA\AI\Site_Graph_Service;
use DSA\Site_Graph\Data_Query_Service;
use DSA\Site_Graph\Query_Service;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Graph_Controller {
	private Data_Query_Service $data_query;
	private Query_Service $query;

	public function __construct( private Site_Graph_Service $site_graph, ?Query_Service $query = null, ?Data_Query_Service $data_query = null ) {
		$this->query      = $query ?: new Query_Service();
		$this->data_query = $data_query ?: new Data_Query_Service();
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
		$response = new WP_REST_Response(
			$this->site_graph->graph(
				[
					'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ),
				]
			),
			200
		);

		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}

	public function summary(): WP_REST_Response {
		$response = new WP_REST_Response( $this->site_graph->summary(), 200 );
		$this->no_store( $response );

		return $response;
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
