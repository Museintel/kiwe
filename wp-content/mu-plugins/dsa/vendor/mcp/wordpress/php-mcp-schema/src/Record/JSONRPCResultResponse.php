<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class JSONRPCResultResponse extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\JSONRPCMessage, \DSA\MCP\Vendor\WP\McpSchema\Contract\JSONRPCResponse
{
    public const DEFINITION = 'JSONRPCResultResponse';

    /**
     * @return float|int|string
     */
    public function getId()
    {
        /** @var float|int|string $value */
        $value = $this->declaredValue('id');

        return $value;
    }

    /**
     * @return '2.0'
     */
    public function getJsonrpc(): string
    {
        /** @var '2.0' $value */
        $value = $this->declaredValue('jsonrpc');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\Result
     */
    public function getResult(): \DSA\MCP\Vendor\WP\McpSchema\Record\Result
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\Result $value */
        $value = $this->declaredValue('result');

        return $value;
    }
}
