<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace DSA\MCP\Vendor\WP\MCP\Handlers\System;

use DSA\MCP\Vendor\WP\MCP\Core\McpRequestContext;
use DSA\MCP\Vendor\WP\McpSchema\Record\PingRequest;

/**
 * Handles system-related MCP methods.
 */
class SystemHandler {
	/**
	 * Handles the ping request.
	 *
	 * @param \DSA\MCP\Vendor\WP\McpSchema\Record\PingRequest $request Validated request.
	 * @param \DSA\MCP\Vendor\WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 * @return array<string, mixed> Logical empty result.
	 * @since 0.7.0
	 */
	public function ping( PingRequest $request, McpRequestContext $request_context ): array {
		unset( $request, $request_context );
		return array();
	}
}
