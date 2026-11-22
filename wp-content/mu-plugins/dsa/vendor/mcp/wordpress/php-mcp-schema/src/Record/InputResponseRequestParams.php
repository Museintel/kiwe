<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class InputResponseRequestParams extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'InputResponseRequestParams';

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
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\InputResponses|null
     */
    public function getInputResponses(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\InputResponses
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\InputResponses|null $value */
        $value = $this->declaredValue('inputResponses');

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
}
