<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class AudioContent extends \DSA\MCP\Vendor\WP\McpSchema\Record implements \DSA\MCP\Vendor\WP\McpSchema\Contract\ContentBlock, \DSA\MCP\Vendor\WP\McpSchema\Contract\SamplingMessageContentBlock
{
    public const DEFINITION = 'AudioContent';

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
     * @return string
     */
    public function getData(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('data');

        return $value;
    }

    /**
     * @return string
     */
    public function getMimeType(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('mimeType');

        return $value;
    }

    /**
     * @return 'audio'
     */
    public function getType(): string
    {
        /** @var 'audio' $value */
        $value = $this->declaredValue('type');

        return $value;
    }
}
