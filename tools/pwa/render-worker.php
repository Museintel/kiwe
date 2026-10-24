<?php
// Local renderer fixture: executes the actual PHP worker endpoint with synthetic WP helpers.
namespace DSA {
	class Settings {
		public function get( $name, $default = null ) {
			$values = [ 'enabled' => ($GLOBALS['argv'][2] ?? 'on') !== 'disabled', 'service_worker' => ($GLOBALS['argv'][2] ?? 'on') === 'on', 'permissions' => ['pwa_enabled' => true, 'offline_editorial_enabled' => true] ];
			return $values[$name] ?? $default;
		}
	}
}
namespace {
	if (PHP_SAPI !== 'cli') exit(1);
	define('ABSPATH', __DIR__);
	define('DSA_VERSION', '8.0.0-test');
	define('MINUTE_IN_SECONDS', 60);
	function home_url($path = '/') { return 'https://example.test' . ($GLOBALS['argv'][3] ?? '/shop-a/') . ltrim($path, '/'); }
	define('DSA_URL', home_url('/wp-content/mu-plugins/dsa/'));
	function __($value, $domain = '') { return $value; }
	function get_option($name, $default = false) { return $default; }
	function get_site_icon_url($size) { return ''; }
	function get_bloginfo($name) { return 'Synthetic App'; }
	function wp_strip_all_tags($value) { return strip_tags($value); }
	function sanitize_hex_color($value) { return preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : ''; }
	function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
	function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
	function trailingslashit($value) { return rtrim($value, '/') . '/'; }
	function rest_url($path) { return home_url('wp-json/' . $path); }
	function add_query_arg($key, $value, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value); }
	function status_header($code) {}
	function nocache_headers() {}
	function do_action($name, ...$args) { $GLOBALS['fixture_actions'][] = $name; }
	require dirname(__DIR__, 2) . '/wp-content/mu-plugins/dsa/includes/PWA/PWA_Service.php';
	$service = new \DSA\PWA\PWA_Service(new \DSA\Settings());
	if (($argv[1] ?? '') === 'config') { echo json_encode($service->public_config()); exit; }
	if (($argv[1] ?? '') === 'boundary') {
		ob_start();
		register_shutdown_function(static function () { ob_end_clean(); echo json_encode(['pageCacheDisabled'=>defined('DONOTCACHEPAGE')&&DONOTCACHEPAGE,'litespeedDisabled'=>in_array('litespeed_control_set_nocache',$GLOBALS['fixture_actions']??[],true)]); });
	}
	$_GET['dsa_service_worker'] = '1';
	$service->serve_endpoint();
}
