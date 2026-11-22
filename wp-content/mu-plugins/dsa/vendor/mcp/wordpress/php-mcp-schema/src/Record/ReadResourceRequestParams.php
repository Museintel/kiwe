<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class ReadResourceRequestParams extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'ReadResourceRequestParams';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\RequestMetaObject|\stdClass|null
     */
    public function getMeta()
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\RequestMetaObject|\stdClass|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * Declared in: 2026-07-28.
     *
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\InputResponses|null
     */
    public function getInputResponses(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\InputResponses
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\InputResponses|null $value */
        $value = $this->declaredValue('inputResponses');

        return $value;
    }

    /**
     * Declared in: 2026-07-28.
     *
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
    public function getUri(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('uri');

        return $value;
    }
}
