<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class TaskAugmentedRequestParams extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'TaskAugmentedRequestParams';

    /**
     * @return \stdClass|null
     */
    public function getMeta(): ?\stdClass
    {
        /** @var \stdClass|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\TaskMetadata|null
     */
    public function getTask(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\TaskMetadata
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\TaskMetadata|null $value */
        $value = $this->declaredValue('task');

        return $value;
    }
}
