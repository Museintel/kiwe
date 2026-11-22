<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class UntitledSingleSelectEnumSchema extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\EnumSchema, \DSA\MCP\Vendor\WP\McpSchema\Contract\PrimitiveSchemaDefinition, \DSA\MCP\Vendor\WP\McpSchema\Contract\SingleSelectEnumSchema
{
    public const DEFINITION = 'UntitledSingleSelectEnumSchema';

    /**
     * @return null|string
     */
    public function getDefault(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('default');

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
     * @return array<int, string>
     */
    public function getEnum(): array
    {
        /** @var array<int, string> $value */
        $value = $this->declaredValue('enum');

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
     * @return 'string'
     */
    public function getType(): string
    {
        /** @var 'string' $value */
        $value = $this->declaredValue('type');

        return $value;
    }
}
