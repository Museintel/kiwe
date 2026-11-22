<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class EmbeddedResource extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ContentBlock
{
    public const DEFINITION = 'EmbeddedResource';

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
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\Annotations|null
     */
    public function getAnnotations(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\Annotations
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\Annotations|null $value */
        $value = $this->declaredValue('annotations');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\BlobResourceContents|\DSA\MCP\Vendor\WP\McpSchema\Record\TextResourceContents
     */
    public function getResource()
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\BlobResourceContents|\DSA\MCP\Vendor\WP\McpSchema\Record\TextResourceContents $value */
        $value = $this->declaredValue('resource');

        return $value;
    }

    /**
     * @return 'resource'
     */
    public function getType(): string
    {
        /** @var 'resource' $value */
        $value = $this->declaredValue('type');

        return $value;
    }
}
