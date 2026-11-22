<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class CallToolResult extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ServerResult
{
    public const DEFINITION = 'CallToolResult';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\ResultMetaObject|\stdClass|null
     */
    public function getMeta()
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\ResultMetaObject|\stdClass|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return array<int, \DSA\MCP\Vendor\WP\McpSchema\Contract\ContentBlock>
     */
    public function getContent(): array
    {
        /** @var array<int, \DSA\MCP\Vendor\WP\McpSchema\Contract\ContentBlock> $value */
        $value = $this->declaredValue('content');

        return $value;
    }

    /**
     * @return bool|null
     */
    public function getIsError(): ?bool
    {
        /** @var bool|null $value */
        $value = $this->declaredValue('isError');

        return $value;
    }

    /**
     * Declared in: 2026-07-28.
     *
     * @return null|string
     */
    public function getResultType(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('resultType');

        return $value;
    }

    /**
     * @return \stdClass|mixed|null
     */
    public function getStructuredContent()
    {
        /** @var \stdClass|mixed|null $value */
        $value = $this->declaredValue('structuredContent');

        return $value;
    }
}
