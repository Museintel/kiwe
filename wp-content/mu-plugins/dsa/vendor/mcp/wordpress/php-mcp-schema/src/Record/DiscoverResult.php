<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class DiscoverResult extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ServerResult
{
    public const DEFINITION = 'DiscoverResult';

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
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\ServerCapabilities
     */
    public function getCapabilities(): \DSA\MCP\Vendor\WP\McpSchema\Record\ServerCapabilities
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\ServerCapabilities $value */
        $value = $this->declaredValue('capabilities');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getInstructions(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('instructions');

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
     * @return array<int, string>
     */
    public function getSupportedVersions(): array
    {
        /** @var array<int, string> $value */
        $value = $this->declaredValue('supportedVersions');

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
