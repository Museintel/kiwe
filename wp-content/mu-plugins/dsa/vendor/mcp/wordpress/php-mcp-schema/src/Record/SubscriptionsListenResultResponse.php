<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class SubscriptionsListenResultResponse extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'SubscriptionsListenResultResponse';

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
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionsListenResult
     */
    public function getResult(): \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionsListenResult
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionsListenResult $value */
        $value = $this->declaredValue('result');

        return $value;
    }
}
