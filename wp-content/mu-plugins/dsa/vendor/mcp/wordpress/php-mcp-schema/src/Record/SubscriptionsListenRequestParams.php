<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class SubscriptionsListenRequestParams extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'SubscriptionsListenRequestParams';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\RequestMetaObject
     */
    public function getMeta(): \DSA\MCP\Vendor\WP\McpSchema\Record\RequestMetaObject
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\RequestMetaObject $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionFilter
     */
    public function getNotifications(): \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionFilter
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionFilter $value */
        $value = $this->declaredValue('notifications');

        return $value;
    }
}
