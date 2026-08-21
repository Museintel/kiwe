<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates vendor-neutral setup descriptors for least-privilege SiteGraph clients.
 *
 * The descriptors deliberately reference one secret instead of copying it into each
 * client snippet. They are configuration guidance, not a second authorization layer:
 * Kiwe's task capsule and REST guard remain the only authority.
 */
final class External_Client_Adapter_Service {
	public const SCHEMA = 'kiwe.external-client-adapters.v1';

	public function catalog(): array {
		return $this->descriptor( $this->base_url(), false );
	}

	public function connection_bundle( array $connection ): array {
		$base = untrailingslashit( esc_url_raw( (string) ( $connection['baseUrl'] ?? $this->base_url() ) ) );

		return $this->descriptor( $base, true );
	}

	private function descriptor( string $base, bool $connection_specific ): array {
		$task_openapi = $base . '/openapi.task.json';
		$mcp_entry    = 'kiwe-ai-toolkit/mcp/sitegraph-client.js';
		$environment  = [
			'KIWE_SITEGRAPH_BASE_URL'  => $base,
			'KIWE_SITEGRAPH_TASK_TOKEN'=> '${KIWE_SITEGRAPH_TASK_TOKEN}',
		];
		$mcp_config = [
			'mcpServers' => [
				'kiwe-sitegraph' => [
					'command' => 'node',
					'args'    => [ '/absolute/path/to/' . $mcp_entry ],
					'env'     => $environment,
				],
			],
		];

		return [
			'schema'             => self::SCHEMA,
			'generatedAt'        => gmdate( 'c' ),
			'connectionSpecific' => $connection_specific,
			'authority'          => [
				'contract' => 'Kiwe task capsule plus task-only OpenAPI',
				'openapi'  => $task_openapi,
				'mutation' => 'forbidden',
				'staging'  => 'forbidden',
			],
			'secret' => [
				'ref'         => $connection_specific ? 'connection.authentication.token' : 'environment:KIWE_SITEGRAPH_TASK_TOKEN',
				'environment' => 'KIWE_SITEGRAPH_TASK_TOKEN',
				'storage'     => 'client secret store, OS environment, or Chrome storage.local only',
				'never'       => [ 'prompt text', 'URL', 'Git', 'screenshot', 'chrome.storage.sync', 'page DOM' ],
			],
			'adapters' => [
				'chatgptOpenAPI' => [
					'kind'           => 'authenticated-openapi',
					'openapiUrl'     => $task_openapi,
					'authentication' => [ 'type' => 'http-bearer', 'secretRef' => $connection_specific ? 'connection.authentication.token' : 'environment:KIWE_SITEGRAPH_TASK_TOKEN' ],
					'note'           => 'Import the task-only schema and configure the bearer value in the action/tool secret field. Do not put it in instructions.',
				],
				'genericHttp' => [
					'baseUrl' => $base,
					'headers' => [ 'Authorization' => 'Bearer ${KIWE_SITEGRAPH_TASK_TOKEN}', 'Accept' => 'application/json' ],
					'allowedDiscovery' => [ '/status', '/site-graph', '/site-graph-data/schema', '/site-graph-data', '/bricks/context', '/seamflow/status' ],
				],
				'mcp' => [
					'transport'  => 'stdio',
					'entrypoint' => $mcp_entry,
					'environment'=> $environment,
					'config'     => $mcp_config,
				],
				'claude' => [
					'kind'   => 'mcp-stdio',
					'config' => $mcp_config,
					'note'   => 'Use a user/local secret environment value; keep the shared project config placeholder-only.',
				],
				'cursor' => [
					'kind'       => 'mcp-stdio',
					'configFile' => '.cursor/mcp.json or the global Cursor MCP configuration',
					'config'     => $mcp_config,
				],
				'chromeExtension' => [
					'kind'             => 'local-secret-proxy',
					'importSchema'     => 'kiwe.external-client-connection.v1',
					'minimumVersion'   => '0.16.0',
					'secretStorage'    => 'chrome.storage.local',
					'hostPermission'   => 'requested only for the imported WordPress origin',
				],
			],
			'verification' => [
				'endpoint' => $base . '/status',
				'expected' => [ 'authenticated' => true, 'credentialKind' => 'task_capsule' ],
			],
		];
	}

	private function base_url(): string {
		return untrailingslashit( rest_url( 'dsa/v1/ai' ) );
	}
}
