<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class PromptMessage extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'PromptMessage';

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Contract\ContentBlock
     */
    public function getContent(): \DSA\MCP\Vendor\WP\McpSchema\Contract\ContentBlock
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Contract\ContentBlock $value */
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
