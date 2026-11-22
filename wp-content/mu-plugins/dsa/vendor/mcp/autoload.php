<?php
// Kiwe loads only its private dependency namespaces. Other plugins own theirs.
spl_autoload_register(static function(string $class): void {
    $prefixes = [
        'DSA\\MCP\\Vendor\\WP\\MCP\\' => __DIR__ . '/wordpress/mcp-adapter/includes/',
        'DSA\\MCP\\Vendor\\WP\\McpSchema\\' => __DIR__ . '/wordpress/php-mcp-schema/src/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $relative = substr($class, strlen($prefix));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $relative)) return;
        $file = $directory . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) require_once $file;
        return;
    }
});
