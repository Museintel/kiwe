<?php

namespace DSA\Rest;

use DSA\AI\Access_Key_Service;
use DSA\AI\Accessibility_Validator;
use DSA\AI\Apply_Plan_Preparer;
use DSA\AI\Binding_Plan_Validator;
use DSA\AI\Bricks_Conversion_Validator;
use DSA\AI\External_Client_Adapter_Service;
use DSA\AI\External_Client_OpenAPI_Service;
use DSA\AI\Site_Graph_Service;
use DSA\AI\Task_Capsule_Service;
use DSA\Site_Graph\Data_Query_Service;
use DSA\Site_Graph\Design_Context_Service;
use DSA\Utilities\Atomic_Rate_Limiter;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Narrow external-tool boundary for SiteGraph and deterministic validators.
 *
 * SEAM owns generation and conversion. This controller intentionally has no
 * generic content-save, runtime, theme-install or AI-studio route.
 */
final class AI_Access_Controller {
	private Access_Key_Service $keys;
	private Task_Capsule_Service $capsules;

	public function __construct( private Site_Graph_Service $site_graph, ?Access_Key_Service $keys = null, ?Task_Capsule_Service $capsules = null ) {
		$this->keys     = $keys ?: new Access_Key_Service();
		$this->capsules = $capsules ?: new Task_Capsule_Service();
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$routes = [
			[ 'GET', '/ai/status', 'status', 'status' ],
			[ 'GET', '/ai/site-graph', 'site_graph', 'site_graph' ],
			[ 'GET', '/ai/site-graph-data/schema', 'site_graph_data_schema', 'site_graph_data' ],
			[ [ 'GET', 'POST' ], '/ai/site-graph-data', 'site_graph_data', 'site_graph_data' ],
			[ 'POST', '/ai/validate-bindings', 'validate_bindings', 'validate_bindings' ],
			[ 'POST', '/ai/validate-bricks-conversion', 'validate_bricks_conversion', 'validate_bricks_conversion' ],
			[ 'POST', '/ai/validate-accessibility', 'validate_accessibility', 'validate_accessibility' ],
			[ 'POST', '/ai/prepare-apply-plan', 'prepare_apply_plan', 'prepare_apply_plan' ],
		];

		$discovery = new External_Client_OpenAPI_Service( $routes );
		$adapters  = new External_Client_Adapter_Service();
		foreach ( [
			'/ai/openapi.json'      => fn() => $discovery->specification(),
			'/ai/openapi.task.json' => fn() => $discovery->specification( true ),
			'/ai/client-manifest'   => fn() => $discovery->client_manifest(),
			'/ai/client-adapters'   => fn() => $adapters->catalog(),
		] as $route => $payload ) {
			register_rest_route( 'dsa/v1', $route, [
				'methods'             => 'GET',
				'callback'            => fn() => $this->discovery_response( $payload() ),
				'permission_callback' => '__return_true',
			] );
		}

		foreach ( $routes as [ $method, $route, $callback, $scope ] ) {
			register_rest_route( 'dsa/v1', $route, [
				'methods'             => $method,
				'callback'            => fn( WP_REST_Request $request ) => $this->guarded( $request, $scope, $callback ),
				'permission_callback' => '__return_true',
			] );
		}
	}

	public function guarded( WP_REST_Request $request, string $scope, string $callback ): WP_REST_Response {
		$ip = $this->client_ip();
		if ( ! Atomic_Rate_Limiter::allow( 'kiwe-ai-auth:' . $ip, 180, MINUTE_IN_SECONDS ) ) {
			return $this->rate_limited( 'origin_rate_limited', 'This client sent too many Kiwe authentication requests.' );
		}

		$auth = 'task_capsule' === $this->credential_kind( $request )
			? $this->capsules->authenticate_request( $request, $scope )
			: $this->keys->authenticate_request( $request, $scope );
		if ( empty( $auth['ok'] ) ) {
			return $this->response( [ 'ok' => false, 'error' => $auth ], (int) ( $auth['status'] ?? 401 ) );
		}

		$client_id = sanitize_key( (string) ( $auth['record']['id'] ?? 'unknown' ) );
		if ( ! Atomic_Rate_Limiter::allow( 'kiwe-ai-client:' . $client_id . ':' . $scope, $this->scope_rate_limit( $scope ), MINUTE_IN_SECONDS ) ) {
			return $this->rate_limited( 'credential_rate_limited', 'This Kiwe credential exceeded its operation budget.' );
		}

		$result = $this->{$callback}( $request, $auth );
		$status = isset( $result['httpStatus'] ) ? max( 100, min( 599, (int) $result['httpStatus'] ) ) : 200;
		unset( $result['httpStatus'] );

		return $this->response( $result, $status );
	}

	private function status( WP_REST_Request $request, array $auth ): array {
		return [
			'ok'         => true,
			'schema'     => 'kiwe.ai-access-status.v2',
			'accessKind' => (string) ( $auth['kind'] ?? 'api_key' ),
			'key'        => $auth['record'],
			'policy'     => is_array( $auth['policy'] ?? null ) ? $auth['policy'] : null,
			'capability' => [
				'siteGraph'                => true,
				'designContext'            => 'embedded-in-siteGraph',
				'siteGraphData'            => true,
				'validateBindings'         => true,
				'validateBricksConversion' => true,
				'validateAccessibility'    => true,
				'prepareApplyPlan'         => 'dry-run-only',
				'mutatesContent'           => false,
			],
		];
	}

	private function site_graph( WP_REST_Request $request, array $auth ): array {
		$args = $this->merged_request_args( $request );
		if ( 'task_capsule' === (string) ( $auth['kind'] ?? '' ) ) {
			$policy    = is_array( $auth['policy'] ?? null ) ? $auth['policy'] : [];
			$resources = array_values( array_intersect( Task_Capsule_Service::RESOURCES, array_map( 'sanitize_key', (array) ( $policy['resources'] ?? Task_Capsule_Service::RESOURCES ) ) ) );
			$max_rows  = max( 1, min( 100, absint( $policy['maxRows'] ?? 25 ) ) );
			$args['resources']          = $resources;
			$args['productLimit']       = in_array( 'products', $resources, true ) ? min( $max_rows, absint( $args['productLimit'] ?? $max_rows ) ) : 0;
			$args['mediaLimit']         = in_array( 'media', $resources, true ) ? min( $max_rows, absint( $args['mediaLimit'] ?? $max_rows ) ) : 0;
			$args['contentLimit']       = array_intersect( [ 'posts', 'pages' ], $resources ) ? min( $max_rows, absint( $args['contentLimit'] ?? 12 ) ) : 0;
			$args['customContentLimit'] = in_array( 'customcontent', $resources, true ) ? min( $max_rows, absint( $args['customContentLimit'] ?? 24 ) ) : 0;
			$args['termLimit']          = array_intersect( [ 'taxonomies', 'terms' ], $resources ) ? min( $max_rows, absint( $args['termLimit'] ?? $max_rows ) ) : 0;
		}

		return ( new Design_Context_Service( $this->site_graph ) )->context( $args, 'task_capsule' !== (string) ( $auth['kind'] ?? '' ) );
	}

	private function site_graph_data_schema(): array {
		return ( new Data_Query_Service() )->schema();
	}

	private function site_graph_data( WP_REST_Request $request, array $auth ): array {
		$args          = $this->merged_request_args( $request );
		$authorization = $this->capsules->authorize_data_args( $args, $auth );
		if ( empty( $authorization['ok'] ) ) {
			return [ 'ok' => false, 'httpStatus' => (int) ( $authorization['status'] ?? 403 ), 'error' => $authorization['error'] ?? [ 'code' => 'capsule_policy_denied' ] ];
		}
		$args    = is_array( $authorization['args'] ?? null ) ? $authorization['args'] : $args;
		$private = 'task_capsule' !== (string) ( $auth['kind'] ?? '' ) && empty( $args['publicOnly'] );
		unset( $args['publicOnly'] );

		return ( new Data_Query_Service() )->query( $args, $private );
	}

	private function validate_bindings( WP_REST_Request $request ): array {
		$binding = $this->array_param( $request, 'binding' );
		return [] === $binding ? $this->bad_request( 'missing_binding', 'Request body must include binding.' ) : ( new Binding_Plan_Validator() )->validate( $binding, $this->site_graph_from_request( $request ) );
	}

	private function validate_bricks_conversion( WP_REST_Request $request ): array {
		$conversion = $this->array_param( $request, 'conversion' );
		if ( [] === $conversion ) return $this->bad_request( 'missing_conversion', 'Request body must include conversion.' );
		return ( new Bricks_Conversion_Validator() )->validate( $conversion, $this->site_graph_from_request( $request ), (string) ( $request->get_param( 'sourceHtml' ) ?? '' ), $this->array_param( $request, 'binding' ) );
	}

	private function validate_accessibility( WP_REST_Request $request ): array {
		$args  = $this->merged_request_args( $request );
		$files = is_array( $args['files'] ?? null ) ? $args['files'] : [];
		$plan  = is_array( $args['plan'] ?? null ) ? $args['plan'] : [];
		if ( [] !== $plan && ! isset( $files['accessibility/kiwe-accessibility-plan.json'] ) ) $files['accessibility/kiwe-accessibility-plan.json'] = wp_json_encode( $plan );
		if ( [] === $files ) return $this->bad_request( 'missing_accessibility_files', 'Request body must include accessibility files or a plan.' );
		return ( new Accessibility_Validator() )->validate_files( $files, [ 'requirePlan' => ! empty( $args['requirePlan'] ), 'strictDark' => ! empty( $args['strictDark'] ) || ! empty( $args['requirePlan'] ) ] );
	}

	private function prepare_apply_plan( WP_REST_Request $request ): array {
		$binding = $this->array_param( $request, 'binding' );
		if ( [] === $binding ) return $this->bad_request( 'missing_binding', 'Request body must include binding.' );
		$site_graph = $this->site_graph_from_request( $request );
		$report     = ( new Binding_Plan_Validator() )->validate( $binding, $site_graph );
		return [ 'ok' => ! empty( $report['ok'] ), 'schema' => 'kiwe.apply-plan-result.v2', 'plan' => empty( $report['ok'] ) ? [] : ( new Apply_Plan_Preparer() )->prepare( $binding, $site_graph, $report ), 'bindingValidation' => $report, 'readOnly' => true ];
	}

	private function merged_request_args( WP_REST_Request $request ): array {
		$args = $request->get_params();
		$body = $request->get_json_params();
		if ( is_array( $body ) ) $args = array_replace_recursive( $args, $body );
		unset( $args['rest_route'] );
		return $args;
	}

	private function site_graph_from_request( WP_REST_Request $request ): array {
		$site_graph = $this->array_param( $request, 'siteGraph' );
		return [] !== $site_graph ? $site_graph : $this->site_graph->graph( [ 'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ?: 8 ) ] );
	}

	private function array_param( WP_REST_Request $request, string $key ): array {
		$value = $request->get_param( $key );
		return is_array( $value ) ? $value : [];
	}

	private function bad_request( string $code, string $message ): array {
		return [ 'ok' => false, 'httpStatus' => 400, 'error' => [ 'code' => $code, 'message' => $message ] ];
	}

	private function credential_kind( WP_REST_Request $request ): string {
		$authorization = trim( (string) $request->get_header( 'authorization' ) );
		$credential    = preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ? trim( (string) $matches[1] ) : trim( (string) $request->get_header( 'x-kiwe-ai-key' ) );
		return str_starts_with( $credential, 'kiwe_task_' ) ? 'task_capsule' : 'api_key';
	}

	private function scope_rate_limit( string $scope ): int {
		return 'prepare_apply_plan' === $scope ? 15 : ( str_starts_with( $scope, 'validate_' ) ? 40 : 120 );
	}

	private function client_ip(): string {
		$value = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : 'unknown';
	}

	private function rate_limited( string $code, string $message ): WP_REST_Response {
		$response = $this->response( [ 'ok' => false, 'error' => [ 'code' => $code, 'message' => $message, 'status' => 429 ] ], 429 );
		$response->header( 'Retry-After', '60' );
		return $response;
	}

	private function discovery_response( array $payload ): WP_REST_Response {
		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	private function response( array $payload, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $payload, $status );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		return $response;
	}
}
