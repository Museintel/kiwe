<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class SubscribeRequest extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ClientRequest
{
    public const DEFINITION = 'SubscribeRequest';

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
     * @return 'resources/subscribe'
     */
    public function getMethod(): string
    {
        /** @var 'resources/subscribe' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\SubscribeRequestParams
     */
    public function getParams(): \DSA\MCP\Vendor\WP\McpSchema\Record\SubscribeRequestParams
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\SubscribeRequestParams $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
