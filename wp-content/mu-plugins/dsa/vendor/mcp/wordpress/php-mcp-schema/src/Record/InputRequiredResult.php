<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class InputRequiredResult extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ServerResult
{
    public const DEFINITION = 'InputRequiredResult';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\ResultMetaObject|null
     */
    public function getMeta(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\ResultMetaObject
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\ResultMetaObject|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\InputRequests|null
     */
    public function getInputRequests(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\InputRequests
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\InputRequests|null $value */
        $value = $this->declaredValue('inputRequests');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getRequestState(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('requestState');

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
