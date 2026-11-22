<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace DSA\MCP\Vendor\WP\McpSchema\Record;

final class CreateMessageRequestParams extends \DSA\MCP\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'CreateMessageRequestParams';

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
     * @return 'allServers'|'none'|'thisServer'|null
     */
    public function getIncludeContext(): ?string
    {
        /** @var 'allServers'|'none'|'thisServer'|null $value */
        $value = $this->declaredValue('includeContext');

        return $value;
    }

    /**
     * @return float|int
     */
    public function getMaxTokens()
    {
        /** @var float|int $value */
        $value = $this->declaredValue('maxTokens');

        return $value;
    }

    /**
     * @return array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\SamplingMessage>
     */
    public function getMessages(): array
    {
        /** @var array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\SamplingMessage> $value */
        $value = $this->declaredValue('messages');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\JSONObject|\stdClass|null
     */
    public function getMetadata()
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\JSONObject|\stdClass|null $value */
        $value = $this->declaredValue('metadata');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\ModelPreferences|null
     */
    public function getModelPreferences(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\ModelPreferences
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\ModelPreferences|null $value */
        $value = $this->declaredValue('modelPreferences');

        return $value;
    }

    /**
     * @return array<int, string>|null
     */
    public function getStopSequences(): ?array
    {
        /** @var array<int, string>|null $value */
        $value = $this->declaredValue('stopSequences');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getSystemPrompt(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('systemPrompt');

        return $value;
    }

    /**
     * Declared in: 2025-11-25.
     *
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\TaskMetadata|null
     */
    public function getTask(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\TaskMetadata
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\TaskMetadata|null $value */
        $value = $this->declaredValue('task');

        return $value;
    }

    /**
     * @return float|int|null
     */
    public function getTemperature()
    {
        /** @var float|int|null $value */
        $value = $this->declaredValue('temperature');

        return $value;
    }

    /**
     * @return \DSA\MCP\Vendor\WP\McpSchema\Record\ToolChoice|null
     */
    public function getToolChoice(): ?\DSA\MCP\Vendor\WP\McpSchema\Record\ToolChoice
    {
        /** @var \DSA\MCP\Vendor\WP\McpSchema\Record\ToolChoice|null $value */
        $value = $this->declaredValue('toolChoice');

        return $value;
    }

    /**
     * @return array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\Tool>|null
     */
    public function getTools(): ?array
    {
        /** @var array<int, \DSA\MCP\Vendor\WP\McpSchema\Record\Tool>|null $value */
        $value = $this->declaredValue('tools');

        return $value;
    }
}
