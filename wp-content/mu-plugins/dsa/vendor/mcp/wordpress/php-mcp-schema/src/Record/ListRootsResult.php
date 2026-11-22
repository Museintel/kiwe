<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class ListRootsResult extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ClientResult, \DSA\MCP\Vendor\WP\McpSchema\Contract\InputResponse
{
    public const DEFINITION = 'ListRootsResult';

    /**
     * Declared in: 2025-11-25.
     *
     * @return \stdClass|null
     */
    public function getMeta(): ?\stdClass
    {
        /** @var \stdClass|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\Root>
     */
    public function getRoots(): array
    {
        /** @var array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\Root> $value */
        $value = $this->declaredValue('roots');

        return $value;
    }
}
