<?php
namespace DSA\Saved;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Short, nonblocking ownership. SQL fences also survive wpdb reconnecting. */
final class Saved_DB_Session {
	private static array $active = [];
	private bool $released = false;
	private function __construct( private string $name, private int $connection, private int $until ) {}

	public static function claim( string $purpose ): array {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT CONNECTION_ID() AS connection_id, DATABASE() AS database_name', ARRAY_A );
		if ( ! is_array( $row ) || $wpdb->last_error || empty( $row['connection_id'] ) ) return [ 'status'=>503 ];
		$name = 'kiwe_saved_' . substr( hash( 'sha256', (string) $row['database_name'] . ':' . $wpdb->usermeta . ':' . $purpose ), 0, 48 );
		if ( isset( self::$active[$name] ) ) return [ 'status'=>409 ];
		$lock = $wpdb->get_row( $wpdb->prepare( 'SELECT GET_LOCK(%s,0) AS acquired, CONNECTION_ID() AS connection_id', $name ), ARRAY_A );
		if ( ! is_array( $lock ) || (string) $lock['acquired'] !== '1' ) return [ 'status'=>! is_array( $lock ) || $lock['acquired'] === null || $wpdb->last_error ? 503 : 409 ];
		$owner = new self( $name, (int) $lock['connection_id'], time() + 20 );
		self::$active[$name] = true;
		if ( ! $owner->owns() ) { $owner->release(); return [ 'status'=>503 ]; }
		return [ 'status'=>200, 'owner'=>$owner ];
	}

	public function fence(): string {
		global $wpdb;
		return $wpdb->prepare( '(CONNECTION_ID()=%d AND IS_USED_LOCK(%s)=%d AND UNIX_TIMESTAMP()<=%d)', $this->connection, $this->name, $this->connection, $this->until );
	}
	public function owns(): bool {
		global $wpdb;
		return ! $this->released && (string) $wpdb->get_var( 'SELECT ' . $this->fence() ) === '1' && ! $wpdb->last_error;
	}
	public function release(): void {
		if ( $this->released ) return;
		$this->released = true;
		unset( self::$active[$this->name] );
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID()=%d,RELEASE_LOCK(%s),NULL)', $this->connection, $this->name ) );
	}
	public function __destruct() { $this->release(); }
}
