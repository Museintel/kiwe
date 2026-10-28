<?php
/** Synthetic native metadata/connection I/O; real SQL is a separate lab gate. */
if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );
if ( ! function_exists( 'sanitize_meta' ) ) { function sanitize_meta( $key, $value, $type ) { return apply_filters( 'sanitize_user_meta_' . $key, $value ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value, ...$args ) { foreach ( $GLOBALS['saved_filters'][$hook] ?? [] as $callback ) $value = $callback( $value, ...$args ); return $value; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( $hook, ...$args ) { $GLOBALS['saved_events'][] = $hook; foreach ( $GLOBALS['saved_actions'][$hook] ?? [] as $callback ) $callback( ...$args ); } }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( ...$args ) { $GLOBALS['saved_cache_deletes'][] = $args; return true; } }

final class Saved_Metadata_Fixture {
	public string $usermeta = 'fixture_usermeta';
	public string $options = 'fixture_options';
	public string $last_error = '';
	public int $insert_id = 0;
	public int $connection = 17;
	public array $locks = [];
	public array $statements = [];
	public array $options_data = [];
	public array $raw_overrides = [];
	public bool $fail_read = false;
	public bool $fail_write = false;
	public ?Closure $before_write = null;
	private array $bindings = [];

	public function prepare( string $sql, ...$args ): string {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
		$at = 0;
		$rendered = preg_replace_callback( '/%[sdf]/', static function( $m ) use ( &$at, $args ) { $value = $args[$at++]; return $m[0] === '%s' ? "'" . str_replace( "'", "''", (string) $value ) . "'" : (string) (int) $value; }, $sql );
		$this->bindings[$rendered] = [ $sql, $args ]; return $rendered;
	}
	private function begin( string $sql ): array { $this->last_error = ''; $this->statements[] = $sql; return $this->bindings[$sql] ?? [ $sql, [] ]; }
	public function get_row( string $sql, $format = null ): ?array {
		[ $template, $args ] = $this->begin( $sql );
		if ( str_starts_with( $sql, 'SELECT CONNECTION_ID()' ) ) return [ 'connection_id'=>$this->connection, 'database_name'=>'fixture' ];
		if ( str_starts_with( $sql, 'SELECT GET_LOCK(' ) ) {
			$name = $args[0]; $held = $this->locks[$name] ?? null;
			if ( $held !== null && $held !== $this->connection ) return [ 'acquired'=>0, 'connection_id'=>$this->connection ];
			$this->locks[$name] = $this->connection; return [ 'acquired'=>1, 'connection_id'=>$this->connection ];
		}
		if ( str_starts_with( $sql, 'SELECT umeta_id,' ) ) {
			if ( $this->fail_read ) { $this->last_error = 'synthetic read failure'; return null; }
			$uid = (int) $args[1];
			if ( array_key_exists( $uid, $this->raw_overrides ) ) return [ 'umeta_id'=>$uid + 100, 'raw_value'=>$this->raw_overrides[$uid] ];
			if ( ! isset( $GLOBALS['meta'][$uid] ) || ! array_key_exists( 'dsa_saved_items', $GLOBALS['meta'][$uid] ) ) return null;
			return [ 'umeta_id'=>$uid + 100, 'raw_value'=>serialize( $GLOBALS['meta'][$uid]['dsa_saved_items'] ) ];
		}
		throw new RuntimeException( 'Unexpected saved fixture row SQL: ' . $template );
	}
	public function get_var( string $sql ) {
		[ $template, $args ] = $this->begin( $sql );
		if ( str_starts_with( $sql, 'SELECT IF(CONNECTION_ID()=' ) ) {
			if ( $this->connection === (int) $args[0] && ( $this->locks[$args[1]] ?? 0 ) === $this->connection ) { unset( $this->locks[$args[1]] ); return 1; }
			return null;
		}
		if ( str_contains( $sql, 'SELECT option_value FROM ' ) ) return $this->options_data[$args[0]] ?? null;
		if ( str_starts_with( $sql, 'SELECT (CONNECTION_ID()=' ) ) return $this->fenced( $sql ) ? 1 : 0;
		throw new RuntimeException( 'Unexpected saved fixture scalar SQL: ' . $template );
	}
	private function fenced( string $sql ): bool {
		if ( ! preg_match( "/CONNECTION_ID\(\)=([0-9]+) AND IS_USED_LOCK\('([^']+)'\)=([0-9]+) AND UNIX_TIMESTAMP\(\)<=([0-9]+)/", $sql, $m ) ) throw new RuntimeException( 'Saved write lacks session fence.' );
		return $this->connection === (int) $m[1] && ( $this->locks[$m[2]] ?? null ) === (int) $m[3] && time() <= (int) $m[4];
	}
	public function query( string $sql ) {
		[ $template, $args ] = $this->begin( $sql );
		if ( str_starts_with( $sql, 'INSERT INTO ' . $this->options ) ) { if ( $this->fail_write ) return false; $this->options_data[$args[0]] = $args[1]; return 1; }
		if ( ! str_starts_with( $sql, 'UPDATE ' . $this->usermeta ) && ! str_starts_with( $sql, 'INSERT INTO ' . $this->usermeta ) ) throw new RuntimeException( 'Unexpected saved fixture mutation SQL: ' . $template );
		if ( $this->before_write ) { $callback = $this->before_write; $this->before_write = null; $callback(); }
		if ( $this->fail_write ) { $this->last_error = 'synthetic write failure'; return false; }
		if ( ! $this->fenced( $sql ) ) return 0;
		$adding = str_starts_with( $sql, 'INSERT' );
		$uid = (int) ( $adding ? $args[0] : $args[2] ); $raw = (string) ( $adding ? $args[2] : $args[0] );
		$exists = isset( $GLOBALS['meta'][$uid] ) && array_key_exists( 'dsa_saved_items', $GLOBALS['meta'][$uid] );
		if ( $adding ? $exists : ( ! $exists || serialize( $GLOBALS['meta'][$uid]['dsa_saved_items'] ) !== $args[4] ) ) return 0;
		$GLOBALS['meta'][$uid]['dsa_saved_items'] = unserialize( $raw, [ 'allowed_classes'=>false ] );
		$GLOBALS['writes'] = ( $GLOBALS['writes'] ?? 0 ) + 1; $this->insert_id = $uid + 100; return 1;
	}
}
$wpdb = new Saved_Metadata_Fixture();
