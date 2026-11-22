<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class CreateTaskResult extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'CreateTaskResult';

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
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\Task
     */
    public function getTask(): \DSA\MCP\Vendor\WP\McpSchema\Record\Task
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\Task $value */
        $value = $this->declaredValue('task');

        return $value;
    }
}
