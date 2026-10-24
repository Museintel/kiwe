<?php

namespace DSA\AI;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One bounded, non-autoloaded site ledger. No provider call or visitor hook. */
final class AI_Allowance_Service {
	public const OPTION = 'kiwe_ai_allowance_v1';
	public const DEFAULTS = [ 'minute'=>2, 'day'=>30, 'month'=>300 ];
	public const TOKEN_DEFAULTS = [ 'minute'=>100000, 'day'=>300000, 'month'=>3000000 ];
	private const MAXIMUMS = [ 'minute'=>60, 'day'=>10000, 'month'=>100000 ];
	private const RETRIES = 6;
	private const PENDING_LIMIT = 64;
	private const PENDING_TTL = 300;

	public function __construct( private Settings $settings ) {}

	/** Zero pauses requests. These are Kiwe ceilings, not provider-advertised quotas. */
	public static function sanitize_limits( array $input, array $current = [] ): array {
		$out = [];
		foreach ( self::DEFAULTS as $window=>$default ) {
			$value = $input[ $window ] ?? $current[ $window ] ?? $default;
			$out[ $window ] = is_scalar( $value ) && preg_match( '/^\d+$/D', (string) $value ) ? min( self::MAXIMUMS[ $window ], (int) $value ) : 0;
		}
		return $out;
	}

	public function limits(): array {
		$ai = $this->settings->get( 'ai', [] );
		return self::sanitize_limits( is_array( $ai['request_allowance'] ?? null ) ? $ai['request_allowance'] : [] );
	}

	public static function sanitize_token_limits( array $input, array $current = [] ): array {
		$out = [];
		foreach ( self::TOKEN_DEFAULTS as $window=>$default ) {
			$value = array_key_exists( $window, $input ) ? $input[ $window ] : ( $current[ $window ] ?? $default );
			$out[ $window ] = is_scalar( $value ) && preg_match( '/^\d+$/D', (string) $value ) ? min( 1000000000, (int) $value ) : 0;
		}
		return $out;
	}

	public function token_limits(): array {
		$ai = $this->settings->get( 'ai', [] );
		return self::sanitize_token_limits( is_array( $ai['token_allowance'] ?? null ) ? $ai['token_allowance'] : [] );
	}

	public function status(): array {
		$now = time();
		$read = $this->read( $now );
		if ( empty( $read['ok'] ) ) return $read + [ 'limits'=>$this->limits(), 'usageAvailable'=>false ];
		$state = $read['state'];
		$limits = $this->limits();
		$remaining = [];
		foreach ( $limits as $window=>$limit ) $remaining[ $window ] = max( 0, $limit - $state[ $window . 'Used' ] );
		$token_limits = $this->token_limits();
		$token_used = $token_remaining = [];
		foreach ( $token_limits as $window=>$limit ) { $token_used[ $window ] = $state['tokens'][ $window ]; $token_remaining[ $window ] = max( 0, $limit - $token_used[ $window ] ); }
		return [ 'ok'=>true, 'schema'=>'kiwe.ai-allowance.status.v1', 'unit'=>'request-attempts', 'timezone'=>'UTC', 'limits'=>$limits, 'used'=>[ 'minute'=>$state['minuteUsed'], 'day'=>$state['dayUsed'], 'month'=>$state['monthUsed'] ], 'remaining'=>$remaining, 'retryAt'=>$state['holdUntil'] > $now ? $state['holdUntil'] : 0, 'usageAvailable'=>true, 'monetaryCap'=>false, 'tokenBudget'=>[ 'limits'=>$token_limits, 'used'=>$token_used, 'remaining'=>$token_remaining, 'startedAt'=>$state['tokens']['since'], 'includesEstimates'=>true ] ];
	}

	/** Charge before each transport attempt. Uncertain/failed attempts are never refunded. */
	public function reserve( int $tokens = 0 ): array {
		global $wpdb;
		if ( $tokens < 0 || $tokens > 500000 ) return $this->blocked( 'site_ai_token_estimate_invalid' );
		$limits = $this->limits();
		$token_limits = $this->token_limits();
		$ticket = $tokens ? bin2hex( random_bytes( 16 ) ) : '';
		for ( $attempt = 0; $attempt < self::RETRIES; ++$attempt ) {
			$now = time();
			$read = $this->read( $now );
			if ( empty( $read['ok'] ) ) return $read;
			$state = $read['state'];
			if ( $state['holdUntil'] > $now ) return $this->blocked( 'site_ai_cooldown', $state['holdUntil'] );
			foreach ( [ 'month','day','minute' ] as $window ) {
				if ( $state[ $window . 'Used' ] >= $limits[ $window ] ) return $this->blocked( 'site_ai_' . $window . '_limit', $limits[ $window ] ? $this->reset_at( $window, $now ) : 0 );
			}
			if ( $tokens ) {
				// A packet that cannot fit any whole window will never be fixed by waiting.
				foreach ( $token_limits as $limit ) if ( $tokens > $limit ) return $this->blocked( 'site_ai_token_request_too_large' );
				foreach ( [ 'month','day','minute' ] as $window ) {
					if ( $tokens > $token_limits[ $window ] - $state['tokens'][ $window ] ) return $this->blocked( 'site_ai_token_' . $window . '_limit', $this->reset_at( $window, $now ) );
				}
				if ( count( $state['pending'] ) >= self::PENDING_LIMIT ) return $this->blocked( 'site_ai_pending_limit', $now + self::PENDING_TTL );
				$state['pending'][ $ticket ] = [ 'at'=>$now, 'units'=>$tokens ];
				if ( ! $state['tokens']['since'] ) $state['tokens']['since'] = $now;
				foreach ( array_keys( $limits ) as $window ) $state['tokens'][ $window ] += $tokens;
			}
			foreach ( array_keys( $limits ) as $window ) ++$state[ $window . 'Used' ];
			$state['updatedAt'] = $now;
			$written = $this->write( $read['raw'], $state );
			if ( false === $written ) return $this->blocked( 'site_ai_storage_unavailable' );
			if ( 1 === $written ) return [ 'ok'=>true, 'reserved'=>true, 'unit'=>'request-attempts', 'ticket'=>$ticket, 'tokenUnits'=>$tokens ];
		}
		return $this->blocked( 'site_ai_busy', time() + 5 );
	}

	/** Exactly-once release of unused token units; request attempts are never refunded. */
	public function settle( string $ticket, ?int $reported ): array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $ticket ) || ( null !== $reported && ( $reported < 1 || $reported > 1000000000 ) ) ) return $this->blocked( 'site_ai_settlement_invalid' );
		for ( $attempt = 0; $attempt < self::RETRIES; ++$attempt ) {
			$now = time();
			$read = $this->read( $now );
			if ( empty( $read['ok'] ) ) return $read;
			$state = $read['state'];
			$pending = $state['pending'][ $ticket ] ?? null;
			// Unknown, expired or already settled receipts cannot release any allowance.
			if ( null === $pending ) return [ 'ok'=>true, 'settled'=>false, 'reason'=>'receipt_unavailable' ];
			$charge = $reported ?? $pending['units'];
			$delta = $charge - $pending['units'];
			$windows = $this->empty_state( $pending['at'] );
			foreach ( [ 'month','day','minute' ] as $window ) {
				if ( $state[ $window ] === $windows[ $window ] ) $state['tokens'][ $window ] += $delta;
			}
			unset( $state['pending'][ $ticket ] );
			$state['updatedAt'] = $now;
			// Unexpected usage is charged, then all consumers pause instead of trusting this estimate again immediately.
			if ( $delta > 0 ) $state['holdUntil'] = max( $state['holdUntil'], $now + 3600 );
			$written = $this->write( $read['raw'], $state );
			if ( false === $written ) return $this->blocked( 'site_ai_storage_unavailable' );
			if ( 1 === $written ) return [ 'ok'=>true, 'settled'=>true, 'reported'=>null !== $reported, 'charged'=>$charge, 'exceededReservation'=>$delta > 0 ];
		}
		return $this->blocked( 'site_ai_busy', time() + 5 );
	}

	/** A provider 429 pauses all site consumers; concurrent holds can only extend it. */
	public function cooldown( string $retry_after = '' ): array {
		$now = time();
		$retry_after = trim( $retry_after );
		$seconds = ctype_digit( $retry_after ) ? min( 366 * 86400, (float) $retry_after ) : 0;
		$date = $seconds ? false : strtotime( $retry_after );
		$until = $seconds ? $now + (int) $seconds : ( false !== $date && $date > $now ? min( $now + 366 * 86400, $date ) : $now + 3600 );
		$until = max( $now + 60, $until );
		for ( $attempt = 0; $attempt < self::RETRIES; ++$attempt ) {
			$at = time();
			$read = $this->read( $at );
			if ( empty( $read['ok'] ) ) return $read;
			$state = $read['state'];
			if ( $state['holdUntil'] >= $until ) return $this->blocked( 'site_ai_cooldown', $state['holdUntil'] );
			$state['holdUntil'] = $until;
			$state['updatedAt'] = $at;
			$written = $this->write( $read['raw'], $state );
			if ( false === $written ) return $this->blocked( 'site_ai_storage_unavailable' );
			if ( 1 === $written ) return $this->blocked( 'site_ai_cooldown', $until );
		}
		return $this->blocked( 'site_ai_busy', time() + 5 );
	}

	private function read( int $now ): array {
		global $wpdb;
		if ( ! isset( $wpdb->options ) ) return $this->blocked( 'site_ai_storage_unavailable' );
		// Read the database directly: a persistent option cache cannot arbitrate reservations.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::OPTION ) );
		if ( ! empty( $wpdb->last_error ) ) return $this->blocked( 'site_ai_storage_unavailable' );
		if ( null === $raw ) return [ 'ok'=>true, 'raw'=>null, 'state'=>$this->empty_state( $now ) ];
		$state = is_string( $raw ) && strlen( $raw ) <= 16384 ? json_decode( $raw, true ) : null;
		if ( ! $this->valid( $state ) ) return $this->blocked( 'site_ai_ledger_invalid' );
		if ( $state['updatedAt'] > $now ) return $this->blocked( 'site_ai_clock_changed', $state['updatedAt'] );
		// Add tracking forward from RC61; do not invent token usage for pre-upgrade requests.
		if ( 1 === $state['schema'] ) { $state['schema'] = 2; $state['tokens'] = [ 'since'=>0, 'minute'=>0, 'day'=>0, 'month'=>0 ]; $state['pending'] = []; }
		$current = $this->empty_state( $now );
		foreach ( [ 'month','day','minute' ] as $window ) {
			if ( $state[ $window ] !== $current[ $window ] ) {
				$state[ $window ] = $current[ $window ];
				$state[ $window . 'Used' ] = 0;
				$state['tokens'][ $window ] = 0;
			}
		}
		foreach ( $state['pending'] as $ticket=>$pending ) if ( $pending['at'] + self::PENDING_TTL <= $now ) unset( $state['pending'][ $ticket ] );
		return [ 'ok'=>true, 'raw'=>$raw, 'state'=>$state ];
	}

	private function write( ?string $raw, array $state ) {
		global $wpdb;
		$next = wp_json_encode( $state );
		if ( null === $raw ) {
			$sql = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::OPTION, $next );
		} else {
			// Exactly one contender can replace this snapshot, including across window rollover.
			$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", $next, self::OPTION, $raw );
		}
		$result = $wpdb->query( $sql );
		if ( 1 === $result ) { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
		return $result;
	}

	private function valid( $state ): bool {
		if ( ! is_array( $state ) || ! in_array( $state['schema'] ?? null, [ 1,2 ], true ) || count( $state ) !== ( 1 === $state['schema'] ? 9 : 11 ) ) return false;
		if ( ! is_string( $state['month'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}$/D', $state['month'] ) || ! is_string( $state['day'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $state['day'] ) ) return false;
		foreach ( [ 'minute','minuteUsed','dayUsed','monthUsed','holdUntil','updatedAt' ] as $key ) if ( ! isset( $state[ $key ] ) || ! is_int( $state[ $key ] ) || $state[ $key ] < 0 ) return false;
		if ( $state['minuteUsed'] > $state['dayUsed'] || $state['dayUsed'] > $state['monthUsed'] ) return false;
		if ( 2 === $state['schema'] ) {
			if ( ! is_array( $state['tokens'] ?? null ) || count( $state['tokens'] ) !== 4 || ! is_array( $state['pending'] ?? null ) || count( $state['pending'] ) > self::PENDING_LIMIT ) return false;
			foreach ( [ 'since','minute','day','month' ] as $key ) if ( ! is_int( $state['tokens'][ $key ] ?? null ) || $state['tokens'][ $key ] < 0 ) return false;
			if ( $state['tokens']['since'] > $state['updatedAt'] || $state['tokens']['minute'] > $state['tokens']['day'] || $state['tokens']['day'] > $state['tokens']['month'] ) return false;
			$sums = [ 'minute'=>0, 'day'=>0, 'month'=>0 ];
			foreach ( $state['pending'] as $ticket=>$pending ) {
				if ( ! preg_match( '/^[a-f0-9]{32}$/D', (string) $ticket ) || ! is_array( $pending ) || count( $pending ) !== 2 || ! is_int( $pending['at'] ?? null ) || $pending['at'] < 0 || $pending['at'] > $state['updatedAt'] || ! is_int( $pending['units'] ?? null ) || $pending['units'] < 1 || $pending['units'] > 500000 ) return false;
				$windows = $this->empty_state( $pending['at'] );
				foreach ( array_keys( $sums ) as $window ) if ( $state[ $window ] === $windows[ $window ] ) $sums[ $window ] += $pending['units'];
			}
			foreach ( $sums as $window=>$sum ) if ( $sum > $state['tokens'][ $window ] ) return false;
		}
		return $state['month'] === gmdate( 'Y-m', $state['updatedAt'] ) && $state['day'] === gmdate( 'Y-m-d', $state['updatedAt'] ) && $state['minute'] === intdiv( $state['updatedAt'], 60 );
	}

	private function empty_state( int $now ): array {
		return [ 'schema'=>2, 'month'=>gmdate( 'Y-m', $now ), 'day'=>gmdate( 'Y-m-d', $now ), 'minute'=>intdiv( $now, 60 ), 'monthUsed'=>0, 'dayUsed'=>0, 'minuteUsed'=>0, 'holdUntil'=>0, 'updatedAt'=>$now, 'tokens'=>[ 'since'=>0, 'minute'=>0, 'day'=>0, 'month'=>0 ], 'pending'=>[] ];
	}

	private function reset_at( string $window, int $now ): int {
		return match ( $window ) {
			'month'=>(int) strtotime( gmdate( 'Y-m-01', $now ) . ' +1 month UTC' ),
			'day'=>(int) strtotime( gmdate( 'Y-m-d', $now ) . ' +1 day UTC' ),
			default=>( intdiv( $now, 60 ) + 1 ) * 60,
		};
	}

	private function blocked( string $reason, int $retry_at = 0 ): array {
		return [ 'ok'=>false, 'reason'=>$reason, 'retryAt'=>$retry_at ];
	}
}
