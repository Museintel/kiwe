<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class ListPromptsResultResponse extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'ListPromptsResultResponse';

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
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\ListPromptsResult
     */
    public function getResult(): \DSA\MCP\Vendor\WP\McpSchema\Record\ListPromptsResult
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\ListPromptsResult $value */
        $value = $this->declaredValue('result');

        return $value;
    }
}
