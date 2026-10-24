<?php
// Build-only projection of real canonical owners. No WordPress boot or state access.
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
$root = dirname(__DIR__, 2) . '/wp-content/mu-plugins/dsa/includes/';
require $root . 'Design/Seam_Token_Service.php';
require $root . 'Access/Workspace_Design_Service.php';
echo DSA\Access\Workspace_Design_Service::workspace_stylesheet();
