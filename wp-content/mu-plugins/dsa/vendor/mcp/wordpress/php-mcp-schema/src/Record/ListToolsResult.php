<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class ListToolsResult extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ServerResult
{
    public const DEFINITION = 'ListToolsResult';

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
     * Declared in: 2026-07-28.
     *
     * @return 'private'|'public'|null
     */
    public function getCacheScope(): ?string
    {
        /** @var 'private'|'public'|null $value */
        $value = $this->declaredValue('cacheScope');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getNextCursor(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('nextCursor');

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
     * @return array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\Tool>
     */
    public function getTools(): array
    {
        /** @var array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\Tool> $value */
        $value = $this->declaredValue('tools');

        return $value;
    }

    /**
     * Declared in: 2026-07-28.
     *
     * @return float|int|null
     */
    public function getTtlMs()
    {
        /** @var float|int|null $value */
        $value = $this->declaredValue('ttlMs');

        return $value;
    }
}
