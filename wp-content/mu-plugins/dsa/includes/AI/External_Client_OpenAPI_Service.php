<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Vendor-neutral discovery contract for ChatGPT, Claude, Cursor and API tools. */
final class External_Client_OpenAPI_Service {
	public function __construct( private array $routes ) {}

	public function specification( bool $task_only = false ): array {
		$paths = [];
		foreach ( $this->routes as $definition ) {
			if ( ! is_array( $definition ) || count( $definition ) < 4 ) {
				continue;
			}
			[ $methods, $route, $callback, $scope ] = $definition;
			if ( $task_only && 'status' !== (string) $scope && ! in_array( (string) $scope, Task_Capsule_Service::SCOPES, true ) ) {
				continue;
			}
			$path = $this->openapi_path( (string) $route );
			foreach ( (array) $methods as $method ) {
				$method = strtolower( (string) $method );
				if ( ! in_array( $method, [ 'get', 'post', 'put', 'patch', 'delete' ], true ) ) {
					continue;
				}
				$operation = [
					'operationId'    => $this->operation_id( (string) $callback, $path, $method ),
					'summary'        => $this->summary( (string) $callback ),
					'tags'           => [ $this->tag( $path ) ],
					'security'       => [ [ 'KiweBearer' => [] ], [ 'KiweHeader' => [] ] ],
					'x-kiwe-scope'   => (string) $scope,
					'x-kiwe-boundary'=> $this->boundary( (string) $scope ),
					'x-kiwe-task-capsule-compatible' => 'status' === (string) $scope || in_array( (string) $scope, Task_Capsule_Service::SCOPES, true ),
					'parameters'     => $this->parameters( $path, (string) $callback ),
					'responses'      => [
						'200' => [ 'description' => 'Successful Kiwe response.', 'content' => [ 'application/json' => [ 'schema' => [ 'type' => 'object', 'additionalProperties' => true ] ] ] ],
						'401' => [ 'description' => 'Missing, invalid, expired or revoked credential.' ],
						'403' => [ 'description' => 'Credential does not permit this operation.' ],
						'429' => [ 'description' => 'Client or origin request budget exceeded.' ],
					],
				];
				if ( in_array( $method, [ 'post', 'put', 'patch' ], true ) ) {
					$operation['requestBody'] = [
						'required' => true,
						'content'  => [ 'application/json' => [ 'schema' => [ 'type' => 'object', 'additionalProperties' => true ] ] ],
					];
				}
				$paths[ $path ][ $method ] = $operation;
			}
		}

		$paths['/openapi.json']['get'] = $this->public_operation( 'kiwe_openapi', 'Discover the Kiwe external-client API contract.' );
		$paths['/openapi.task.json']['get'] = $this->public_operation( 'kiwe_task_openapi', 'Discover the task-capsule-only external-client API contract.' );
		$paths['/client-manifest']['get'] = $this->public_operation( 'kiwe_client_manifest', 'Discover vendor-neutral connection options and safety boundaries.' );
		$paths['/client-adapters']['get'] = $this->public_operation( 'kiwe_client_adapters', 'Discover secret-safe client adapter configuration templates.' );

		return [
			'openapi' => '3.1.0',
			'info'    => [
				'title'       => $task_only ? 'Kiwe SiteGraph Task API' : 'Kiwe SiteGraph and SEAM Tool API',
				'version'     => defined( 'DSA_VERSION' ) ? DSA_VERSION : '1.0.0',
				'description' => 'Vendor-neutral, scoped access to deterministic SiteGraph, Bricks context, SEAM conversion validation and the separately gated trusted staging chain. A SiteGraph task capsule can never mutate WordPress, Bricks, WooCommerce or Kiwe runtime state.',
			],
			'servers' => [ [ 'url' => untrailingslashit( rest_url( 'dsa/v1/ai' ) ) ] ],
			'paths'   => $paths,
			'components' => [
				'securitySchemes' => [
					'KiweBearer' => [ 'type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'kiwe_ai_* or kiwe_task_*' ],
					'KiweHeader' => [ 'type' => 'apiKey', 'in' => 'header', 'name' => 'X-Kiwe-AI-Key' ],
				],
			],
			'x-kiwe-authentication' => [
				'taskCapsule' => 'Recommended for external AI/IDE content, conversion and validation work. Short-lived, public-data-only and mutation-forbidden.',
				'apiKey'      => 'Required for separately approved staging, controlled execution or mutation capabilities.',
			],
			'x-kiwe-task-only' => $task_only,
		];
	}

	public function client_manifest(): array {
		$base = untrailingslashit( rest_url( 'dsa/v1/ai' ) );
		return [
			'schema'        => 'kiwe.external-client-manifest.v1',
			'generatedAt'   => gmdate( 'c' ),
			'vendorNeutral' => true,
			'baseUrl'       => $base,
			'openapiUrl'    => $base . '/openapi.json',
			'taskOpenapiUrl'=> $base . '/openapi.task.json',
			'adaptersUrl'   => $base . '/client-adapters',
			'statusUrl'     => $base . '/status',
			'authentication'=> [
				'header'      => 'Authorization',
				'scheme'      => 'Bearer',
				'alternative' => 'X-Kiwe-AI-Key',
				'never'       => [ 'URL query string', 'raw HTML/CSS/JS', 'Git', 'public prompt', 'screenshot' ],
			],
			'clients'       => [
				'openapi'  => 'Use the task-only OpenAPI URL for ordinary external content, conversion and validation work.',
				'mcp'      => 'Use a local or hosted adapter that keeps the credential outside model context.',
				'ide'      => 'Use an HTTP/OpenAPI/MCP integration with environment or secret-vault storage.',
				'browser'  => 'Use a trusted extension or action connector; normal browsing alone cannot securely add a bearer credential.',
				'fileOnly' => 'Download SiteGraph JSON when the client cannot store authentication securely.',
			],
			'publicData' => [
				'schema' => rest_url( 'dsa/v1/site-graph/data/schema' ),
				'query'  => rest_url( 'dsa/v1/site-graph/data' ),
			],
			'boundaries' => [
				'taskCapsules are public-data-only, expire automatically and have request budgets.',
				'taskCapsules never include staging, publishing, runtime, authentication or mutation scopes.',
				'permanent API keys remain revocable and should use the smallest required scopes.',
			],
		];
	}

	private function openapi_path( string $route ): string {
		$route = preg_replace( '#^/ai#', '', $route );
		$route = preg_replace( '/\(\?P<([A-Za-z][A-Za-z0-9_]*)>[^)]+\)/', '{$1}', (string) $route );
		return '/' . ltrim( (string) $route, '/' );
	}

	private function parameters( string $path, string $callback ): array {
		$parameters = [];
		if ( preg_match_all( '/\{([A-Za-z][A-Za-z0-9_]*)\}/', $path, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$parameters[] = [ 'name' => $name, 'in' => 'path', 'required' => true, 'schema' => [ 'type' => 'string' ] ];
			}
		}
		if ( in_array( $callback, [ 'site_graph', 'site_graph_data' ], true ) ) {
			$parameters[] = [ 'name' => 'sampleLimit', 'in' => 'query', 'required' => false, 'schema' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 24 ] ];
		}
		if ( 'site_graph_data' === $callback ) {
			$parameters[] = [ 'name' => 'publicOnly', 'in' => 'query', 'required' => false, 'schema' => [ 'type' => 'boolean' ], 'description' => 'Always forced true for task capsules.' ];
		}
		return $parameters;
	}

	private function boundary( string $scope ): string {
		if ( in_array( $scope, Task_Capsule_Service::SCOPES, true ) || 'status' === $scope ) {
			return 'read-convert-validate';
		}
		if ( in_array( $scope, [ 'prepare_apply_plan', 'stage_apply_plan', 'trusted_apply_chain' ], true ) ) {
			return 'separately-authorized-staging';
		}
		if ( in_array( $scope, [ 'staging_execute', 'controlled_mutation', 'themes' ], true ) ) {
			return 'high-authority-permanent-key-only';
		}
		return 'permanent-key-only';
	}

	private function tag( string $path ): string {
		return match ( true ) {
			str_contains( $path, '/site-graph' ) => 'SiteGraph',
			str_contains( $path, '/bricks' ) => 'Bricks',
			str_contains( $path, '/seamflow' ) => 'SEAM',
			str_contains( $path, '/validate' ) => 'Validation',
			str_contains( $path, '/stages' ), str_contains( $path, '/staging' ), str_contains( $path, '/mutations' ) => 'Controlled execution',
			default => 'Kiwe',
		};
	}

	private function summary( string $callback ): string {
		return ucfirst( str_replace( '_', ' ', sanitize_key( $callback ) ) ) . '.';
	}

	private function operation_id( string $callback, string $path, string $method ): string {
		$path_id = preg_replace( '/[^A-Za-z0-9]+/', '_', trim( $path, '/' ) );
		$path_id = trim( strtolower( (string) $path_id ), '_' );
		return substr( 'kiwe_' . sanitize_key( $callback ) . '_' . $path_id . '_' . sanitize_key( $method ), 0, 120 );
	}

	private function public_operation( string $operation_id, string $summary ): array {
		return [
			'operationId' => $operation_id,
			'summary'     => $summary,
			'tags'        => [ 'Discovery' ],
			'security'    => [],
			'responses'   => [ '200' => [ 'description' => 'Public, secret-free discovery document.' ] ],
		];
	}
}
