<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class ElicitationCompleteNotification extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ServerNotification
{
    public const DEFINITION = 'ElicitationCompleteNotification';

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
     * @return 'notifications/elicitation/complete'
     */
    public function getMethod(): string
    {
        /** @var 'notifications/elicitation/complete' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \stdClass
     */
    public function getParams(): \stdClass
    {
        /** @var \stdClass $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
