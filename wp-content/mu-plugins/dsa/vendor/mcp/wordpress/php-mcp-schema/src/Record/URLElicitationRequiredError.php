<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class URLElicitationRequiredError extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'URLElicitationRequiredError';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\Error
     */
    public function getError(): \DSA\MCP\Vendor\WP\McpSchema\Record\Error
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\Error $value */
        $value = $this->declaredValue('error');

        return $value;
    }

    /**
     * @return float|int|null|string
     */
    public function getId()
    {
        /** @var float|int|null|string $value */
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
}
