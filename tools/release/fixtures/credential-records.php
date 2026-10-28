<?php
/** Deterministic option SQL and interleavings; never booted by WordPress. */
if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );
function kiwe_credential_test_barrier(): void {
	if ( Fiber::getCurrent() && ! empty( $GLOBALS['credential_pause_commit'] ) ) {
		$GLOBALS['credential_pause_commit'] = false;
		Fiber::suspend( 'credential_commit_snapshot' );
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ): bool {
		$GLOBALS['credential_cache_deleted'][] = [ $key, $group ];
		return true;
	}
}

final class Kiwe_Credential_Test_DB {
	public string $options = 'wp_options';
	public string $last_error = '';
	public int $reads = 0;
	public int $writes = 0;
	public bool $fail_read = false;
	public bool $fail_write = false;
	public bool $conflict = false;
	public array $autoload = [];

	public function prepare( string $sql, ...$args ): array { return [ $sql, $args ]; }
	public function get_row( array $query, $format = null ): ?array {
		++$this->reads;
		$this->last_error = $this->fail_read ? 'Synthetic read failure' : '';
		if ( $this->fail_read ) return null;
		$key = $query[1][0];
		if ( ! array_key_exists( $key, $GLOBALS['kiwe_test_options'] ) ) return null;
		$value = $GLOBALS['kiwe_test_options'][ $key ];
		return [ 'option_value' => is_array( $value ) ? serialize( $value ) : $value, 'autoload' => $this->autoload[ $key ] ?? 'no' ];
	}
	public function query( array $query ) {
		++$this->writes;
		kiwe_credential_test_barrier();
		$this->last_error = $this->fail_write ? 'Synthetic write failure' : '';
		if ( $this->fail_write ) return false;
		if ( $this->conflict ) return 0;
		[ $sql, $args ] = $query;
		if ( str_starts_with( $sql, 'INSERT IGNORE' ) ) {
			[ $key, $next ] = $args;
			if ( array_key_exists( $key, $GLOBALS['kiwe_test_options'] ) ) return 0;
			$this->autoload[ $key ] = 'no';
		} elseif ( str_starts_with( $sql, 'UPDATE ' ) ) {
			[ $next, $key, $expected ] = $args;
			if ( ! str_contains( $sql, 'BINARY option_value=BINARY %s' ) ) throw new RuntimeException( 'Byte-exact SQL comparison required' );
			if ( ! array_key_exists( $key, $GLOBALS['kiwe_test_options'] ) ) return 0;
			$current = $GLOBALS['kiwe_test_options'][ $key ];
			$current = is_array( $current ) ? serialize( $current ) : $current;
			if ( $current !== $expected || $next === $current ) return 0;
		} else {
			throw new RuntimeException( 'Unexpected credential SQL' );
		}
		$GLOBALS['kiwe_test_options'][ $key ] = unserialize( $next, [ 'allowed_classes' => false ] );
		return 1;
	}
}
