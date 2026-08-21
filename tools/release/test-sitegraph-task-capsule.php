<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DSA_VERSION', 'test' );

$GLOBALS['kiwe_test_options'] = [];

class WP_REST_Request {
	public function __construct( private array $headers = [] ) {}
	public function get_header( string $name ): string {
		return (string) ( $this->headers[ strtolower( $name ) ] ?? '' );
	}
}

function get_option( string $name, $default = false ) { return $GLOBALS['kiwe_test_options'][ $name ] ?? $default; }
function update_option( string $name, $value, $autoload = null ): bool { $GLOBALS['kiwe_test_options'][ $name ] = $value; return true; }
function wp_generate_uuid4(): string { return bin2hex( random_bytes( 16 ) ); }
function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
function wp_hash_password( string $value ): string { return password_hash( $value, PASSWORD_DEFAULT ); }
function wp_check_password( string $value, string $hash ): bool { return password_verify( $value, $hash ); }
function get_current_user_id(): int { return 7; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function absint( $value ): int { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function __( string $value, string $domain = '' ): string { return $value; }
function rest_url( string $path = '' ): string { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
function esc_url_raw( string $value ): string { return filter_var( $value, FILTER_SANITIZE_URL ) ?: ''; }

require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/AI/Task_Capsule_Service.php';
require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/AI/External_Client_OpenAPI_Service.php';
require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/AI/External_Client_Adapter_Service.php';

use DSA\AI\External_Client_Adapter_Service;
use DSA\AI\External_Client_OpenAPI_Service;
use DSA\AI\Task_Capsule_Service;

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

$service = new Task_Capsule_Service();
$issued = $service->issue(
	'Cross-client test',
	'convert_validate',
	[
		'ttl' => 3600,
		'maxUses' => 5,
		'maxRows' => 10,
		'sampleLimit' => 4,
		'resources' => [ 'site', 'products' ],
		'fields' => [ 'id', 'title', 'featuredImage', 'product' ],
	]
);
$token = (string) $issued['token'];
$record = (array) $issued['record'];
assert_true( str_starts_with( $token, 'kiwe_task_' ), 'Task capsule prefix is invalid.' );
assert_true( ! isset( $record['hash'] ), 'Public task capsule record exposed its hash.' );
assert_true( ! in_array( 'controlled_mutation', $record['scopes'], true ), 'Task capsule included mutation authority.' );
assert_true( ! in_array( 'stage_apply_plan', $record['scopes'], true ), 'Task capsule included staging authority.' );
assert_true( true === $record['policy']['publicOnly'], 'Task capsule did not force public-only content.' );
assert_true( 'forbidden' === $record['policy']['mutation'], 'Task capsule mutation boundary is missing.' );
assert_true( ! str_contains( serialize( $GLOBALS['kiwe_test_options'] ), $token ), 'Plain task capsule was stored in WordPress options.' );

$request = new WP_REST_Request( [ 'authorization' => 'Bearer ' . $token ] );
$auth = $service->authenticate_request( $request, 'site_graph_data' );
assert_true( true === $auth['ok'] && 'task_capsule' === $auth['kind'], 'Task capsule authentication failed.' );
$bounded = $service->authorize_data_args(
	[
		'resource' => 'products',
		'limit' => 99,
		'fields' => [ 'id', 'title', 'meta' ],
		'metaKeys' => [ '_secret' ],
		'status' => [ 'private' ],
	],
	$auth
);
assert_true( true === $bounded['ok'], 'Allowed SiteGraph Data request was denied.' );
assert_true( 1 === $bounded['args']['publicOnly'], 'Task capsule did not force publicOnly on the data request.' );
assert_true( 10 === $bounded['args']['limit'], 'Task capsule did not clamp the row budget.' );
assert_true( ! isset( $bounded['args']['metaKeys'], $bounded['args']['status'] ), 'Task capsule retained private selectors.' );
assert_true( [ 'id', 'title', 'featuredImage', 'product' ] === $bounded['args']['fields'], 'Task capsule field allowlist was not enforced.' );

$denied = $service->authorize_data_args( [ 'resource' => 'media' ], $auth );
assert_true( false === $denied['ok'] && 403 === $denied['status'], 'Disallowed SiteGraph resource did not fail closed.' );
assert_true( $service->revoke( (string) $record['id'], 7 ), 'Task capsule could not be revoked.' );
$revoked = $service->authenticate_request( $request, 'site_graph_data' );
assert_true( false === $revoked['ok'], 'Revoked task capsule remained usable.' );

$openapi = ( new External_Client_OpenAPI_Service(
	[
		[ 'GET', '/ai/status', 'status', 'status' ],
		[ [ 'GET', 'POST' ], '/ai/site-graph-data', 'site_graph_data', 'site_graph_data' ],
		[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/authorize', 'authorize_stage', 'trusted_apply_chain' ],
	]
) )->specification();
assert_true( '3.1.0' === $openapi['openapi'], 'OpenAPI version is not 3.1.' );
assert_true( isset( $openapi['paths']['/site-graph-data']['get'], $openapi['paths']['/site-graph-data']['post'] ), 'OpenAPI did not preserve multi-method routes.' );
assert_true( 'kiwe_site_graph_data_site_graph_data_get' === $openapi['paths']['/site-graph-data']['get']['operationId'], 'OpenAPI operation IDs are not deterministic and route-specific.' );
assert_true( isset( $openapi['paths']['/stages/{stageId}/authorize']['post'] ), 'WordPress regex path was not converted to OpenAPI syntax.' );
assert_true( 'high-authority-permanent-key-only' !== $openapi['paths']['/site-graph-data']['get']['x-kiwe-boundary'], 'Read-only SiteGraph route was classified as high authority.' );
assert_true( 'separately-authorized-staging' === $openapi['paths']['/stages/{stageId}/authorize']['post']['x-kiwe-boundary'], 'Trusted staging boundary is missing from OpenAPI.' );
$task_openapi = ( new External_Client_OpenAPI_Service(
	[
		[ 'GET', '/ai/status', 'status', 'status' ],
		[ 'GET', '/ai/site-graph', 'site_graph', 'site_graph' ],
		[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/authorize', 'authorize_stage', 'trusted_apply_chain' ],
		[ 'POST', '/ai/mutations/wordpress-publish', 'locked_mutation', 'controlled_mutation' ],
	]
) )->specification( true );
assert_true( true === $task_openapi['x-kiwe-task-only'], 'Task OpenAPI did not declare its reduced authority.' );
assert_true( isset( $task_openapi['paths']['/site-graph']['get'] ), 'Task OpenAPI omitted an allowed SiteGraph route.' );
assert_true( ! isset( $task_openapi['paths']['/stages/{stageId}/authorize'], $task_openapi['paths']['/mutations/wordpress-publish'] ), 'Task OpenAPI exposed staging or mutation operations.' );

$connection = [
	'baseUrl' => 'https://example.test/wp-json/dsa/v1/ai',
	'authentication' => [ 'token' => $token ],
];
$bundle = ( new External_Client_Adapter_Service() )->connection_bundle( $connection );
assert_true( 'kiwe.external-client-adapters.v1' === $bundle['schema'], 'Adapter bundle schema is invalid.' );
assert_true( str_ends_with( $bundle['adapters']['chatgptOpenAPI']['openapiUrl'], '/openapi.task.json' ), 'OpenAPI adapter did not use the task-only schema.' );
assert_true( 'chrome.storage.local' === $bundle['adapters']['chromeExtension']['secretStorage'], 'Chrome adapter did not require local-only secret storage.' );
assert_true( ! str_contains( serialize( $bundle['adapters']['mcp']['config'] ), $token ), 'MCP configuration copied the raw task secret.' );
assert_true( ! str_contains( serialize( $bundle ), $token ), 'Adapter bundle duplicated the raw task secret instead of referencing the connection secret.' );

echo "PASS SiteGraph task capsule, task OpenAPI and adapter runtime contracts\n";
