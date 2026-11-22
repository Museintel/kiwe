<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class SamplingMessage extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'SamplingMessage';

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
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\AudioContent|\DSA\MCP\Vendor\WP\McpSchema\Record\ImageContent|\DSA\MCP\Vendor\WP\McpSchema\Record\TextContent|\DSA\MCP\Vendor\WP\McpSchema\Record\ToolResultContent|\DSA\MCP\Vendor\WP\McpSchema\Record\ToolUseContent|array<int, \DSA\MCP\Vendor\WP\McpSchema\Contract\SamplingMessageContentBlock>
     */
    public function getContent()
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\AudioContent|\DSA\MCP\Vendor\WP\McpSchema\Record\ImageContent|\DSA\MCP\Vendor\WP\McpSchema\Record\TextContent|\DSA\MCP\Vendor\WP\McpSchema\Record\ToolResultContent|\DSA\MCP\Vendor\WP\McpSchema\Record\ToolUseContent|array<int, \DSA\MCP\Vendor\WP\McpSchema\Contract\SamplingMessageContentBlock> $value */
        $value = $this->declaredValue('content');

        return $value;
    }

    /**
     * @return 'assistant'|'user'
     */
    public function getRole(): string
    {
        /** @var 'assistant'|'user' $value */
        $value = $this->declaredValue('role');

        return $value;
    }
}
