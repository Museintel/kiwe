<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class UnsubscribeRequest extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ClientRequest
{
    public const DEFINITION = 'UnsubscribeRequest';

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
     * @return 'resources/unsubscribe'
     */
    public function getMethod(): string
    {
        /** @var 'resources/unsubscribe' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\UnsubscribeRequestParams
     */
    public function getParams(): \DSA\MCP\Vendor\WP\McpSchema\Record\UnsubscribeRequestParams
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\UnsubscribeRequestParams $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
