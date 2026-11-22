<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class Implementation extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'Implementation';

    /**
     * @return null|string
     */
    public function getDescription(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('description');

        return $value;
    }

    /**
     * @return array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\Icon>|null
     */
    public function getIcons(): ?array
    {
        /** @var array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\Icon>|null $value */
        $value = $this->declaredValue('icons');

        return $value;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('name');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getTitle(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('title');

        return $value;
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('version');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getWebsiteUrl(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('websiteUrl');

        return $value;
    }
}
