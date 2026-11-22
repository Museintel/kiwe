<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class SubscriptionsAcknowledgedNotificationParams extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'SubscriptionsAcknowledgedNotificationParams';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\NotificationMetaObject|null
     */
    public function getMeta(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\NotificationMetaObject
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\NotificationMetaObject|null $value */
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
