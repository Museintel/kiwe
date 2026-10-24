<?php

// Execute the production guard with synthetic WordPress URL and request adapters.
define( 'ABSPATH', __DIR__ . '/' );

function home_url( string $path = '' ): string { return $GLOBALS['test_home_url']; }
function site_url( string $path = '' ): string { return $GLOBALS['test_site_url']; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function __( string $value, string $domain = '' ): string { return $value; }

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data ) {}
}
class WP_REST_Request {
	public function __construct( private array $headers = [] ) {}
	public function get_header( string $name ): string { return $this->headers[ $name ] ?? ''; }
}

require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Utilities/Origin_Checker.php';
require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Rest/Runtime_Hydration_Controller.php';

use DSA\Utilities\Origin_Checker;

$cases = [
	[ 'legacy request without origin headers', [], true ],
	[ 'same-origin fetch without optional headers', [ 'HTTP_SEC_FETCH_SITE' => 'same-origin' ], true ],
	[ 'configured frontend origin', [ 'HTTP_ORIGIN' => 'https://store.example.test' ], true ],
	[ 'configured WordPress origin', [ 'HTTP_ORIGIN' => 'https://admin.example.test' ], true ],
	[ 'same-site configured admin fetch', [ 'HTTP_ORIGIN' => 'https://admin.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-site' ], true ],
	[ 'subdirectory frontend referrer', [ 'HTTP_REFERER' => 'https://store.example.test/catalog/item?view=1' ], true ],
	[ 'subdirectory admin referrer', [ 'HTTP_REFERER' => 'https://admin.example.test/wordpress/wp-admin/' ], true ],
	[ 'case-insensitive host and explicit default port', [ 'HTTP_ORIGIN' => 'https://STORE.example.test:443' ], true ],
	[ 'untrusted host does not replace configured origin', [ 'HTTP_HOST' => 'alias.example.test', 'HTTP_ORIGIN' => 'https://store.example.test' ], true ],
	[ 'foreign origin', [ 'HTTP_ORIGIN' => 'https://foreign.example.test' ], false ],
	[ 'same host with different scheme', [ 'HTTP_ORIGIN' => 'http://store.example.test' ], false ],
	[ 'same host with different port', [ 'HTTP_ORIGIN' => 'https://store.example.test:8443' ], false ],
	[ 'opaque browser origin', [ 'HTTP_ORIGIN' => 'null' ], false ],
	[ 'malformed origin', [ 'HTTP_ORIGIN' => 'not-an-origin' ], false ],
	[ 'explicit empty origin', [ 'HTTP_ORIGIN' => '' ], false ],
	[ 'protocol-relative origin', [ 'HTTP_ORIGIN' => '//store.example.test' ], false ],
	[ 'non-HTTP origin', [ 'HTTP_ORIGIN' => 'ftp://store.example.test' ], false ],
	[ 'origin with credentials', [ 'HTTP_ORIGIN' => 'https://user@store.example.test' ], false ],
	[ 'origin with path', [ 'HTTP_ORIGIN' => 'https://store.example.test/path' ], false ],
	[ 'origin with query', [ 'HTTP_ORIGIN' => 'https://store.example.test?ignored=1' ], false ],
	[ 'origin with fragment', [ 'HTTP_ORIGIN' => 'https://store.example.test#ignored' ], false ],
	[ 'origin with embedded whitespace', [ 'HTTP_ORIGIN' => 'https://store.example.test /path' ], false ],
	[ 'origin with an empty port', [ 'HTTP_ORIGIN' => 'https://store.example.test:' ], false ],
	[ 'origin with an invalid port', [ 'HTTP_ORIGIN' => 'https://store.example.test:99999' ], false ],
	[ 'referrer with a backslash', [ 'HTTP_REFERER' => 'https://store.example.test/\\foreign' ], false ],
	[ 'malformed referrer', [ 'HTTP_REFERER' => 'not-a-url' ], false ],
	[ 'foreign referrer with allowed origin', [ 'HTTP_ORIGIN' => 'https://store.example.test', 'HTTP_REFERER' => 'https://foreign.example.test/' ], false ],
	[ 'host header cannot authorize a foreign origin', [ 'HTTP_HOST' => 'foreign.example.test', 'HTTP_ORIGIN' => 'https://foreign.example.test' ], false ],
	[ 'cross-site fetch metadata veto', [ 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://store.example.test' ], false ],
	[ 'cross-site fetch without origin headers', [ 'HTTP_SEC_FETCH_SITE' => 'cross-site' ], false ],
];

$failures = [];
$checks = 0;
$hydration = ( new ReflectionClass( \DSA\Rest\Runtime_Hydration_Controller::class ) )->newInstanceWithoutConstructor();
foreach ( $cases as [ $label, $headers, $expected ] ) {
	$GLOBALS['test_home_url'] = 'https://store.example.test/catalog/';
	$GLOBALS['test_site_url'] = 'https://admin.example.test/wordpress/';
	$_SERVER = array_replace( [ 'HTTP_HOST' => 'store.example.test' ], $headers );
	$actual = Origin_Checker::is_same_site_request();
	$read = $hydration->can_read( new WP_REST_Request() );
	$mutation = Origin_Checker::mutation_allowed( new WP_REST_Request( [ 'x-kiwe-mutation' => '1' ] ) );
	$passed = $actual === $expected
		&& ( $expected ? true === $read : $read instanceof WP_Error && 403 === $read->data['status'] )
		&& ( $expected ? true === $mutation : $mutation instanceof WP_Error && 'dsa_cross_site_mutation' === $mutation->code );
	++$checks;
	if ( ! $passed ) $failures[] = $label;
	echo ( $passed ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
}

// Keep development ports, IPv6, and explicit mutation proof intact.
foreach ( [ 'http://localhost:8080/subdir/', 'https://[::1]:8443/wordpress/' ] as $configured ) {
	$GLOBALS['test_home_url'] = $configured;
	$GLOBALS['test_site_url'] = $configured;
	$parts = parse_url( $configured );
	$_SERVER = [ 'HTTP_ORIGIN' => $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] ];
	$passed = Origin_Checker::is_same_site_request();
	++$checks;
	if ( ! $passed ) $failures[] = 'configured development origin ' . $configured;
	echo ( $passed ? 'PASS ' : 'FAIL ' ) . 'configured development origin' . PHP_EOL;
}

$missing = Origin_Checker::mutation_allowed( new WP_REST_Request() );
$passed = $missing instanceof WP_Error && 'dsa_mutation_proof_missing' === $missing->code;
++ $checks;
if ( ! $passed ) $failures[] = 'same-origin mutations still require proof';
echo ( $passed ? 'PASS ' : 'FAIL ' ) . 'same-origin mutations still require proof' . PHP_EOL;

echo ( $checks - count( $failures ) ) . '/' . $checks . ' origin-guard behavior checks passed.' . PHP_EOL;
exit( $failures ? 1 : 0 );
