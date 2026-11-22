<?php
// HTTP fixture for the real consent controller, using synthetic identities and codes.
namespace DSA\Utilities {
 class Origin_Checker {
  public static function client_ip(): string { return '127.0.0.1'; }
  public static function is_same_site_request(): bool { return ($_SERVER['HTTP_ORIGIN'] ?? '') === \fixture_origin(); }
 }
 class Atomic_Rate_Limiter { public static function allow(...$args): bool { return true; } }
}
namespace DSA\MCP {
 class Operation_Registry {}
 class Credentials { public static function secret(): string { return 'synthetic-browser-secret'; } }
 class Authorization_Service {
  public const FLOW_TTL = 600;
  public function begin(array $args, string $secret): string { return 'p_' . str_repeat('a', 32); }
  public function review(string $flow, string $secret, int $actor): array {
   return ['id'=>$flow, 'clientName'=>'Browser test', 'redirect'=>getenv('KIWE_CONSENT_CALLBACK'),
    'scopes'=>['mcp.read'], 'reviewDigest'=>str_repeat('b', 64), 'operations'=>[['label'=>'Read guidance', 'effect'=>'read']]];
  }
  public function approve(string $flow, string $secret, int $actor, bool $allow, bool $offline, string $digest): array {
   if ($secret !== Credentials::secret() || $actor !== 1 || $digest !== str_repeat('b', 64)) throw new \RuntimeException('Unexpected test approval');
   return ['redirect'=>getenv('KIWE_CONSENT_CALLBACK'), 'state'=>'synthetic-state', 'browserUntil'=>time()+180] +
    ($allow ? ['code'=>'synthetic-code'] : ['error'=>'access_denied']);
  }
 }
}
namespace {
 define('ABSPATH', __DIR__ . '/');
 function fixture_origin(): string { return 'http://' . $_SERVER['HTTP_HOST']; }
 function home_url(string $path): string { return fixture_origin() . $path; }
 function admin_url(string $path): string { return fixture_origin() . '/' . $path; }
 function untrailingslashit(string $value): string { return rtrim($value, '/'); }
 function wp_unslash($value) { return $value; }
 function is_user_logged_in(): bool { return true; }
 function get_current_user_id(): int { return 1; }
 function get_bloginfo(string $key): string { return 'Fixture site'; }
 function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
 function esc_attr(string $value): string { return esc_html($value); }
 function esc_url(string $value): string { return esc_html($value); }
 function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'fixture-nonce'; }
 function wp_nonce_field(string $action): void { echo '<input name="_wpnonce" type="hidden" value="fixture-nonce">'; }
 function nocache_headers(): void { header('Cache-Control: no-store, private'); }
 function wp_redirect(string $url, int $status, string $source): void { header('Location: ' . $url, true, $status); }
 function add_query_arg(array $args, string $url): string {
  [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
  parse_str($query, $existing);
  return $base . '?' . http_build_query(array_replace($existing, $args), '', '&', PHP_QUERY_RFC3986);
 }
 function wp_die(string $message, string $title, array $args): never { http_response_code($args['response']); exit($message); }

 require __DIR__ . '/../../../wp-content/mu-plugins/dsa/includes/MCP/Urls.php';
 require getenv('KIWE_CONSENT_CONTROLLER');
 $http = new DSA\MCP\HTTP_Controller(new DSA\MCP\Authorization_Service(), new DSA\MCP\Operation_Registry());
 if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') $http->consent();
 else $http->authorize();
}
