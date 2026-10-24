<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
function wp_parse_url($value, $component) { return parse_url($value, $component); }
function sanitize_text_field($value) { return (string)$value; }
function wp_unslash($value) { return stripslashes((string)$value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)$value)); }
require dirname(__DIR__, 2) . '/wp-content/mu-plugins/dsa/includes/Secure/Incident_Response_Service.php';
$cases = [
 ['GET', '/wp-admin/media-new.php', '', false],
 ['HEAD', '/wp-admin/media-new.php', '', false],
 ['POST', '/wp-admin/media-new.php', '', true],
 ['GET', '/wp-admin/async-upload.php', '', true],
 ['POST', '/wp-admin/admin-ajax.php', 'media-create-image-subsizes', true],
 ['GET', '/wp-admin/admin.php?page=kiwe-media', '', false],
];
foreach ($cases as [$method, $uri, $action, $expected]) {
 $_SERVER['REQUEST_METHOD'] = $method;
 $_SERVER['REQUEST_URI'] = $uri;
 $_REQUEST = $action ? ['action'=>$action] : [];
 if (\DSA\Secure\Incident_Response_Service::is_lean_media_request() !== $expected) {
  fwrite(STDERR, "Unexpected media boot lane: $method $uri $action\n");
  exit(1);
 }
}
echo "Media GET loads the dashboard guard; upload writers stay lean.\n";
