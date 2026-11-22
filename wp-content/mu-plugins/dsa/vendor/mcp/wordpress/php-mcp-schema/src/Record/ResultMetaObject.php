<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class ResultMetaObject extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'ResultMetaObject';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\Implementation|null
     */
    public function getIoModelcontextprotocolServerInfo(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\Implementation
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\Implementation|null $value */
        $value = $this->declaredValue('io.modelcontextprotocol/serverInfo');

        return $value;
    }
}
