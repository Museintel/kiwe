<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class SubscriptionsListenResult extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ServerResult
{
    public const DEFINITION = 'SubscriptionsListenResult';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionsListenResultMetaObject
     */
    public function getMeta(): \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionsListenResultMetaObject
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionsListenResultMetaObject $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return string
     */
    public function getResultType(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('resultType');

        return $value;
    }
}
