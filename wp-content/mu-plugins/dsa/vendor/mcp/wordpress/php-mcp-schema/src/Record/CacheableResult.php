<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class CacheableResult extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'CacheableResult';

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
     * @return 'private'|'public'
     */
    public function getCacheScope(): string
    {
        /** @var 'private'|'public' $value */
        $value = $this->declaredValue('cacheScope');

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

    /**
     * @return float|int
     */
    public function getTtlMs()
    {
        /** @var float|int $value */
        $value = $this->declaredValue('ttlMs');

        return $value;
    }
}
