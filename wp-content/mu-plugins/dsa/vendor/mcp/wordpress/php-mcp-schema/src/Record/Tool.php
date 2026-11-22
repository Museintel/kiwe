<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class Tool extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'Tool';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\MetaObject|\stdClass|null
     */
    public function getMeta()
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\MetaObject|\stdClass|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\ToolAnnotations|null
     */
    public function getAnnotations(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\ToolAnnotations
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\ToolAnnotations|null $value */
        $value = $this->declaredValue('annotations');

        return $value;
    }

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
     * Declared in: 2025-11-25.
     *
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\ToolExecution|null
     */
    public function getExecution(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\ToolExecution
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\ToolExecution|null $value */
        $value = $this->declaredValue('execution');

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
     * @return \stdClass
     */
    public function getInputSchema(): \stdClass
    {
        /** @var \stdClass $value */
        $value = $this->declaredValue('inputSchema');

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
     * @return \stdClass|null
     */
    public function getOutputSchema(): ?\stdClass
    {
        /** @var \stdClass|null $value */
        $value = $this->declaredValue('outputSchema');

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
}
