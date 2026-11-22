<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class LoggingMessageNotification extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ServerNotification
{
    public const DEFINITION = 'LoggingMessageNotification';

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
     * @return 'notifications/message'
     */
    public function getMethod(): string
    {
        /** @var 'notifications/message' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\LoggingMessageNotificationParams
     */
    public function getParams(): \DSA\MCP\Vendor\WP\McpSchema\Record\LoggingMessageNotificationParams
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\LoggingMessageNotificationParams $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
