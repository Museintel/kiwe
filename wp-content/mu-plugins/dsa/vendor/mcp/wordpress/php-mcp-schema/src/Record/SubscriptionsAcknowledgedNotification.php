<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class SubscriptionsAcknowledgedNotification extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ServerNotification
{
    public const DEFINITION = 'SubscriptionsAcknowledgedNotification';

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
     * @return 'notifications/subscriptions/acknowledged'
     */
    public function getMethod(): string
    {
        /** @var 'notifications/subscriptions/acknowledged' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionsAcknowledgedNotificationParams
     */
    public function getParams(): \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionsAcknowledgedNotificationParams
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\SubscriptionsAcknowledgedNotificationParams $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
