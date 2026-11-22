<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class ToolUseContent extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\SamplingMessageContentBlock
{
    public const DEFINITION = 'ToolUseContent';

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
     * @return string
     */
    public function getId(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('id');

        return $value;
    }

    /**
     * @return \stdClass
     */
    public function getInput(): \stdClass
    {
        /** @var \stdClass $value */
        $value = $this->declaredValue('input');

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
     * @return 'tool_use'
     */
    public function getType(): string
    {
        /** @var 'tool_use' $value */
        $value = $this->declaredValue('type');

        return $value;
    }
}
