<?php
namespace DSA\Notifications;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Fenced, bounded transitions of the existing private notification job options. */
final class Worker_Job {
	private const LEASE = 90;
	private const BYTES = 2097152;
	private string $token;
	private float $started;
	private function __construct( private string $option, private string $raw, private array $job, private string $autoload ) {
		$this->token = bin2hex( random_bytes( 16 ) );
		$this->started = microtime( true );
	}

	public static function claim( string $option ): ?self {
		if ( ! self::job_option( $option ) ) return null;
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$before = self::read( $option );
			if ( ! $before || ! $before['value'] || (int) ( $before['value']['_kiwe_worker']['until'] ?? 0 ) > time() ) return null;
			$owner = new self( $option, $before['raw'], $before['value'], $before['autoload'] );
			$next = $before['value'];
			$next['_kiwe_worker'] = [ 'token'=>$owner->token, 'until'=>time() + self::LEASE ];
			if ( self::write( $option, $before['raw'], $next, $before['autoload'] ) ) {
				$owner->raw = serialize( $next ); $owner->job = $next;
				return $owner;
			}
		}
		return null;
	}

	public function data(): array { return $this->job; }
	public function owns(): bool {
		if ( microtime( true ) - $this->started >= 20 || (int) ( $this->job['_kiwe_worker']['until'] ?? 0 ) <= time() ) return false;
		$current = self::read( $this->option );
		return $current && hash_equals( $this->raw, $current['raw'] );
	}

	/** Cursor/unknown is committed before I/O; stale workers cannot replace it. */
	public function save( array $job ): bool {
		if ( ! $this->owns() ) return false;
		$job['_kiwe_worker'] = [ 'token'=>$this->token, 'until'=>time() + self::LEASE ];
		if ( ! self::write( $this->option, $this->raw, $job, $this->autoload ) ) return false;
		$this->raw = serialize( $job ); $this->job = $job;
		return true;
	}

	public function remove(): bool {
		return $this->owns() && self::write( $this->option, $this->raw, null, $this->autoload );
	}

	/** A late finally block can release only its own still-current claim. */
	public function release(): void {
		$current = self::read( $this->option );
		if ( ! $current || ( $current['value']['_kiwe_worker']['token'] ?? '' ) !== $this->token ) return;
		$next = $current['value']; unset( $next['_kiwe_worker'] );
		self::write( $this->option, $current['raw'], $next, $current['autoload'] );
	}

	/** Terminal jobs remain resumable until their bounded history is durable. */
	public function remember( string $option, array $summary, string $identity ): bool {
		if ( ! in_array( $option, [ 'dsa_notification_campaign_history', 'dsa_new_post_notification_history' ], true ) || ! isset( $summary[$identity] ) ) return false;
		for ( $attempt = 0; $attempt < 4 && $this->owns(); ++$attempt ) {
			$before = self::read( $option, true );
			if ( ! $before ) return false;
			foreach($before['value']as$row)if(is_array($row)&&($row[$identity]??null)===$summary[$identity]&&$row===$summary)return true;
			$history = array_values( array_filter( $before['value'], static fn( $row ) => is_array( $row ) && ( $row[$identity] ?? null ) !== $summary[$identity] ) );
			array_unshift( $history, $summary );
			if ( self::write( $option, $before['raw'], array_slice( $history, 0, 20 ), $before['autoload'] ) ) return true;
		}
		return false;
	}

	public function last_push( array $summary ): bool {
		for($attempt=0;$attempt<4&&$this->owns();++$attempt){
			$before=self::read('dsa_push_last_job',true);if(!$before)return false;
			$prior=$before['value'];
			if($prior===$summary||[(int)($prior['completed']??0),(string)($prior['jobId']??'')]>[(int)$summary['completed'],(string)$summary['jobId']])return true;
			if(self::write('dsa_push_last_job',$before['raw'],$summary,$before['autoload']))return true;
		}
		return false;
	}

	public static function discard( string $option, array $original ): bool {
		return self::job_option($option)&&self::write($option,serialize($original),null);
	}

	private static function job_option( string $option ): bool {
		return $option === 'dsa_notification_campaign_active' || preg_match( '/^dsa_new_post_alert_[1-9][0-9]{0,18}$/D', $option ) === 1 || preg_match( '/^dsa_push_job_[a-z0-9]{1,40}$/D', $option ) === 1;
	}
	private static function read( string $option, bool $missing = false ): ?array {
		global $wpdb;
		$prior = $wpdb->suppress_errors( true );
		try { $row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $option ), ARRAY_A ); }
		finally { $wpdb->suppress_errors( $prior ); }
		if ( $wpdb->last_error ) return null;
		if ( $row === null ) return $missing ? [ 'raw'=>null, 'value'=>[], 'autoload'=>'no' ] : null;
		$raw = $row['option_value'] ?? null;
		if ( ! is_string( $raw ) || strlen( $raw ) > self::BYTES ) return null;
		$value = @unserialize( $raw, [ 'allowed_classes'=>false ] );
		return is_array( $value ) ? [ 'raw'=>$raw, 'value'=>$value, 'autoload'=>(string)($row['autoload']??'no') ] : null;
	}
	private static function write( string $option, ?string $before, ?array $next, string $autoload='no' ): bool {
		global $wpdb;
		$raw = $next === null ? null : serialize( $next );
		if ( $raw !== null && strlen( $raw ) > self::BYTES ) return false;
		if ( $raw === $before ) { $current = self::read( $option ); return $current && $current['raw'] === $before; }
		if ( $next === null ) $sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s", $option, $before );
		elseif ( $before === null ) $sql = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", $option, $raw );
		else $sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", $raw, $option, $before );
		$prior = $wpdb->suppress_errors( true );
		try { $result = $wpdb->query( $sql ); }
		finally { $wpdb->suppress_errors( $prior ); }
		if ( $result !== 1 ) return false;
		foreach ( [ $option, 'notoptions' ] as $key ) wp_cache_delete( $key, 'options' );
		if(!in_array($autoload,['no','off','auto-off'],true))wp_cache_delete('alloptions','options');
		return true;
	}
}
