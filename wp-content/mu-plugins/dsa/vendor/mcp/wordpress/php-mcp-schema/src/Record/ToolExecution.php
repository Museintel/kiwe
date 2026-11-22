<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class ToolExecution extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'ToolExecution';

    /**
     * @return 'forbidden'|'optional'|'required'|null
     */
    public function getTaskSupport(): ?string
    {
        /** @var 'forbidden'|'optional'|'required'|null $value */
        $value = $this->declaredValue('taskSupport');

        return $value;
    }
}
