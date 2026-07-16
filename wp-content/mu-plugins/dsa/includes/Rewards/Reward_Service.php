<?php

namespace DSA\Rewards;

use DSA\Settings;
use DSA\Diagnostics\Runtime_Profiler;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reward_Service {
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function start_attempt( array $payload ) {
		$config = $this->config();

		if ( empty( $config['rewards_enabled'] ) ) {
			return new WP_Error( 'dsa_rewards_disabled', __( 'Game rewards are not enabled for this appsite.', 'dsa' ), [ 'status' => 403 ] );
		}

		$game = $this->sanitize_game( $payload['game'] ?? 'dino' );
		$identity = $this->identity( (string) ( $payload['visitorId'] ?? '' ) );
		$state = $this->state( $identity['key'] );
		$remaining = max( 0, (int) $config['max_attempts_per_day'] - count( $state['attempts'] ) );

		if ( $remaining < 1 ) {
			return new WP_Error( 'dsa_reward_attempt_limit', __( 'Today\'s reward attempts are finished.', 'dsa' ), [ 'status' => 429 ] );
		}

		$token = wp_generate_password( 32, false, false );
		set_transient(
			$this->token_key( $token ),
			[
				'identity'   => $identity['key'],
				'ip_bucket'  => $identity['ip_bucket'],
				'game'       => $game,
				'started_at'    => time(),
				'started_at_ms' => (int) round( microtime( true ) * 1000 ),
			],
			10 * MINUTE_IN_SECONDS
		);

		return [
			'ok'        => true,
			'token'     => $token,
			'game'      => $game,
			'remaining' => $remaining,
			'limits'    => [
				'maxAttempts' => (int) $config['max_attempts_per_day'],
				'minPlayMs'   => (int) $config['min_play_ms'],
				'maxPlayMs'   => (int) $config['max_play_ms'],
			],
		];
	}

	public function complete_attempt( array $payload ) {
		$config = $this->config();

		if ( empty( $config['rewards_enabled'] ) ) {
			return new WP_Error( 'dsa_rewards_disabled', __( 'Game rewards are not enabled for this appsite.', 'dsa' ), [ 'status' => 403 ] );
		}

		$token = sanitize_text_field( (string) ( $payload['token'] ?? '' ) );
		$token_state = $token ? $this->consume_attempt_token( $token ) : false;

		if ( is_wp_error( $token_state ) ) {
			return $token_state;
		}

		if ( ! is_array( $token_state ) ) {
			return new WP_Error( 'dsa_reward_token_invalid', __( 'This game attempt expired. Start a new attempt.', 'dsa' ), [ 'status' => 409 ] );
		}

		$identity = $this->identity( (string) ( $payload['visitorId'] ?? '' ) );
		if ( ! hash_equals( (string) $token_state['identity'], $identity['key'] ) ) {
			return new WP_Error( 'dsa_reward_identity_mismatch', __( 'This reward attempt could not be verified.', 'dsa' ), [ 'status' => 403 ] );
		}

		$identity_lock = $this->acquire_lock( 'identity|' . $identity['key'], 30 );
		if ( ! $identity_lock ) {
			return new WP_Error( 'dsa_reward_attempt_busy', __( 'This reward attempt is already being processed.', 'dsa' ), [ 'status' => 409 ] );
		}

		$ip_lock = $this->acquire_lock( 'ip|' . $identity['ip_bucket'], 30 );
		if ( ! $ip_lock ) {
			$this->release_lock( $identity_lock );
			return new WP_Error( 'dsa_reward_attempt_busy', __( 'Another reward attempt is being processed. Try again.', 'dsa' ), [ 'status' => 409 ] );
		}

		try {

		$state = $this->state( $identity['key'] );
		$ip_state = $this->ip_state( $identity['ip_bucket'] );

		if ( count( $state['attempts'] ) >= (int) $config['max_attempts_per_day'] || $ip_state['count'] >= (int) $config['max_attempts_per_day'] ) {
			return new WP_Error( 'dsa_reward_attempt_limit', __( 'Today\'s reward attempts are finished.', 'dsa' ), [ 'status' => 429 ] );
		}

		$started_at_ms = max( 0, (int) ( $token_state['started_at_ms'] ?? 0 ) );
		if ( ! $started_at_ms ) {
			$started_at_ms = max( 0, (int) ( $token_state['started_at'] ?? 0 ) * 1000 );
		}
		$server_duration_ms = $started_at_ms ? max( 0, (int) round( microtime( true ) * 1000 ) - $started_at_ms ) : 0;
		$client_duration_ms = max( 0, min( (int) $config['max_play_ms'], absint( $payload['durationMs'] ?? 0 ) ) );
		$duration_ms = $server_duration_ms;

		if ( $duration_ms < (int) $config['min_play_ms'] ) {
			return new WP_Error( 'dsa_reward_too_fast', __( 'That game ended too quickly to unlock a reward.', 'dsa' ), [ 'status' => 400 ] );
		}

		if ( $duration_ms > (int) $config['max_play_ms'] ) {
			return new WP_Error( 'dsa_reward_too_long', __( 'That game attempt expired. Start a new attempt.', 'dsa' ), [ 'status' => 400 ] );
		}

		$game = $this->sanitize_game( $payload['game'] ?? ( $token_state['game'] ?? 'dino' ) );
		if ( $game !== ( $token_state['game'] ?? '' ) ) {
			return new WP_Error( 'dsa_reward_game_mismatch', __( 'This reward attempt could not be verified.', 'dsa' ), [ 'status' => 400 ] );
		}

		$score = max( 0, min( (int) $config['max_score'], absint( $payload['score'] ?? 0 ) ) );
		$attempt_number = count( $state['attempts'] ) + 1;
		$bonus = $this->bonus_for_attempt( $attempt_number, $config );
		$discount = max( 0, min( 100, (int) ( $bonus['discount'] ?? 0 ) ) );
		$coupon = null;
		$coupon_error = '';

		$state['attempts'][] = [
			'attempt'    => $attempt_number,
			'game'       => $game,
			'score'      => $score,
			'durationMs' => $duration_ms,
			'reportedDurationMs' => $client_duration_ms,
			'scoreTrusted' => false,
			'discount'   => $discount,
			'completed'  => time(),
		];
		$state['best_score'] = max( (int) $state['best_score'], $score );
		$state['best_discount'] = max( (int) $state['best_discount'], $discount );

		if ( $discount > 0 && ! empty( $config['coupon_enabled'] ) ) {
			$coupon = $this->issue_coupon( $identity['key'], $discount, $config );
			if ( is_wp_error( $coupon ) ) {
				$coupon_error = $coupon->get_error_message();
				$state['coupon_error'] = $coupon_error;
				$coupon = null;
			} else {
				$state['coupons'][] = $coupon;
			}
		}

		$this->save_state( $identity['key'], $state );
		$this->save_ip_state( $identity['ip_bucket'], [ 'count' => $ip_state['count'] + 1 ] );

		$remaining = max( 0, (int) $config['max_attempts_per_day'] - count( $state['attempts'] ) );

		return [
			'ok'         => true,
			'attempt'    => $attempt_number,
			'remaining'  => $remaining,
			'score'      => $score,
			'bestScore'  => (int) $state['best_score'],
			'scoreTrusted' => false,
			'bonus'      => [
				'label'    => sanitize_text_field( $bonus['label'] ?? __( 'Reward', 'dsa' ) ),
				'discount' => $discount,
			],
			'coupon'     => $coupon,
			'couponError' => $coupon_error,
			'message'    => $coupon_error ?: $this->reward_message( $discount, $coupon, $remaining, $config ),
			'serverHeld' => true,
		];
		} finally {
			$this->release_lock( $ip_lock );
			$this->release_lock( $identity_lock );
		}
	}

	public function public_config(): array {
		$config = $this->config();

		return [
			'enabled'       => ! empty( $config['rewards_enabled'] ),
			'couponEnabled' => ! empty( $config['coupon_enabled'] ),
			'maxAttempts'   => (int) $config['max_attempts_per_day'],
			'couponMinutes' => (int) $config['coupon_expiry_minutes'],
		];
	}

	private function config(): array {
		$games = $this->settings->get( 'games', [] );
		$games = is_array( $games ) ? $games : [];

		return [
			'rewards_enabled'       => ! empty( $games['rewards_enabled'] ),
			'coupon_enabled'        => ! empty( $games['coupon_enabled'] ),
			'max_attempts_per_day'  => max( 1, min( 10, (int) ( $games['max_attempts_per_day'] ?? 3 ) ) ),
			'coupon_expiry_minutes' => max( 5, min( 1440, (int) ( $games['coupon_expiry_minutes'] ?? 20 ) ) ),
			'min_play_ms'           => max( 1000, min( 30000, (int) ( $games['min_play_ms'] ?? 4000 ) ) ),
			'max_play_ms'           => max( 15000, min( 300000, (int) ( $games['max_play_ms'] ?? 180000 ) ) ),
			'max_score'             => max( 100, min( 100000, (int) ( $games['max_score'] ?? 10000 ) ) ),
			'daily_coupon_budget'   => max( 1, min( 100000, (int) ( $games['daily_coupon_budget'] ?? 100 ) ) ),
			'bonuses'               => isset( $games['bonuses'] ) && is_array( $games['bonuses'] ) ? $games['bonuses'] : [],
		];
	}

	private function identity( string $visitor_id ): array {
		$visitor_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $visitor_id );
		$visitor_id = substr( $visitor_id ?: 'anon', 0, 64 );
		$user_id = get_current_user_id();
		$ip = $this->remote_addr();
		$ua = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 180 );
		$subject = $user_id ? 'user:' . $user_id : 'visitor:' . $visitor_id . '|' . $ip . '|' . $ua;
		$ip_bucket = hash_hmac( 'sha256', 'ip:' . $ip, wp_salt( 'auth' ) );

		return [
			'key'       => hash_hmac( 'sha256', $subject, wp_salt( 'auth' ) ),
			'ip_bucket' => $ip_bucket,
		];
	}

	private function remote_addr(): string {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	}

	private function state( string $identity ): array {
		$state = get_transient( $this->state_key( $identity ) );
		$state = is_array( $state ) ? $state : [];

		return wp_parse_args(
			$state,
			[
				'attempts'      => [],
				'coupons'       => [],
				'best_score'    => 0,
				'best_discount' => 0,
			]
		);
	}

	private function save_state( string $identity, array $state ): void {
		$profile = Runtime_Profiler::start();
		set_transient( $this->state_key( $identity ), $state, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		Runtime_Profiler::finish( 'rewards.identity_state_write', $profile );
	}

	private function ip_state( string $ip_bucket ): array {
		$state = get_transient( $this->ip_key( $ip_bucket ) );
		$state = is_array( $state ) ? $state : [];

		return [ 'count' => max( 0, (int) ( $state['count'] ?? 0 ) ) ];
	}

	private function save_ip_state( string $ip_bucket, array $state ): void {
		$profile = Runtime_Profiler::start();
		set_transient( $this->ip_key( $ip_bucket ), $state, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		Runtime_Profiler::finish( 'rewards.ip_state_write', $profile );
	}

	private function consume_attempt_token( string $token ) {
		$lock = $this->acquire_lock( 'token|' . hash( 'sha256', $token ), 30 );

		if ( ! $lock ) {
			return new WP_Error( 'dsa_reward_token_busy', __( 'This game attempt is already being completed.', 'dsa' ), [ 'status' => 409 ] );
		}

		try {
			$state = get_transient( $this->token_key( $token ) );
			delete_transient( $this->token_key( $token ) );
			return $state;
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function acquire_lock( string $scope, int $ttl ): string {
		$key = 'dsa_reward_lock_' . substr( hash( 'sha256', $scope ), 0, 32 );
		$expires = time() + max( 5, min( 60, $ttl ) );

		if ( add_option( $key, $expires, '', false ) ) {
			return $key;
		}

		$current = absint( get_option( $key, 0 ) );
		if ( $current > 0 && $current < time() ) {
			delete_option( $key );
			if ( add_option( $key, $expires, '', false ) ) {
				return $key;
			}
		}

		return '';
	}

	private function release_lock( string $key ): void {
		if ( '' !== $key ) {
			delete_option( $key );
		}
	}

	private function token_key( string $token ): string {
		return 'dsa_reward_token_' . md5( $token );
	}

	private function state_key( string $identity ): string {
		return 'dsa_reward_state_' . gmdate( 'Ymd' ) . '_' . substr( $identity, 0, 28 );
	}

	private function ip_key( string $ip_bucket ): string {
		return 'dsa_reward_ip_' . gmdate( 'Ymd' ) . '_' . substr( $ip_bucket, 0, 28 );
	}

	private function sanitize_game( string $game ): string {
		$game = sanitize_key( $game );
		return in_array( $game, [ 'dino', 'star' ], true ) ? $game : 'dino';
	}

	private function bonus_for_attempt( int $attempt, array $config ): array {
		$bonuses = isset( $config['bonuses'] ) && is_array( $config['bonuses'] ) ? $config['bonuses'] : [];
		$index = max( 0, min( count( $bonuses ) - 1, $attempt - 1 ) );

		return isset( $bonuses[ $index ] ) && is_array( $bonuses[ $index ] ) ? $bonuses[ $index ] : [
			'label'    => __( 'Reward', 'dsa' ),
			'discount' => 0,
		];
	}

	private function issue_coupon( string $identity, int $discount, array $config ) {
		if ( ! post_type_exists( 'shop_coupon' ) ) {
			return new WP_Error( 'dsa_reward_woo_missing', __( 'WooCommerce coupons are not available.', 'dsa' ) );
		}

		$budget_lock = $this->acquire_lock( 'coupon-budget|' . gmdate( 'Ymd' ), 30 );
		if ( ! $budget_lock ) {
			return new WP_Error( 'dsa_reward_coupon_busy', __( 'Coupon issuing is busy. Try again in a moment.', 'dsa' ) );
		}

		$budget_reserved = false;

		try {
			$budget_key = 'dsa_reward_coupon_budget_' . gmdate( 'Ymd' );
			$issued = max( 0, absint( get_option( $budget_key, 0 ) ) );

			if ( $issued >= (int) $config['daily_coupon_budget'] ) {
				return new WP_Error( 'dsa_reward_coupon_budget', __( 'Today\'s reward coupon budget has been reached.', 'dsa' ) );
			}

			update_option( $budget_key, $issued + 1, false );
			$budget_reserved = true;
		} finally {
			$this->release_lock( $budget_lock );
		}

		$code = $this->unique_coupon_code();
		$post_id = wp_insert_post(
			[
				'post_title'   => $code,
				'post_content' => __( 'Generated by Kiwe game reward.', 'dsa' ),
				'post_status'  => 'publish',
				'post_type'    => 'shop_coupon',
				'post_author'  => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			if ( $budget_reserved ) {
				$this->release_coupon_budget();
			}
			return $post_id;
		}

		$expires = time() + ( (int) $config['coupon_expiry_minutes'] * MINUTE_IN_SECONDS );
		update_post_meta( $post_id, 'discount_type', 'percent' );
		update_post_meta( $post_id, 'coupon_amount', (string) $discount );
		update_post_meta( $post_id, 'individual_use', 'yes' );
		update_post_meta( $post_id, 'usage_limit', '1' );
		update_post_meta( $post_id, 'usage_limit_per_user', '1' );
		update_post_meta( $post_id, 'date_expires', (string) $expires );
		update_post_meta( $post_id, '_dsa_reward_coupon', 'yes' );
		update_post_meta( $post_id, '_dsa_reward_identity', $identity );
		update_post_meta( $post_id, '_dsa_reward_issued_day', gmdate( 'Ymd' ) );

		$current_user = wp_get_current_user();
		$bound_email  = $current_user && $current_user->exists() ? sanitize_email( (string) $current_user->user_email ) : '';

		if ( '' !== $bound_email && is_email( $bound_email ) ) {
			update_post_meta( $post_id, 'customer_email', [ $bound_email ] );
			update_post_meta( $post_id, '_dsa_reward_bound_email', $bound_email );
		}

		return [
			'code'      => $code,
			'discount'  => $discount,
			'expiresAt' => gmdate( 'c', $expires ),
			'minutes'   => (int) $config['coupon_expiry_minutes'],
		];
	}

	private function release_coupon_budget(): void {
		$key = 'dsa_reward_coupon_budget_' . gmdate( 'Ymd' );
		$lock = $this->acquire_lock( 'coupon-budget|' . gmdate( 'Ymd' ), 30 );

		if ( ! $lock ) {
			return;
		}

		try {
			update_option( $key, max( 0, absint( get_option( $key, 0 ) ) - 1 ), false );
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function unique_coupon_code(): string {
		for ( $i = 0; $i < 8; $i++ ) {
			$code = 'KIWE-' . strtoupper( wp_generate_password( 8, false, false ) );

			if ( ! $this->coupon_exists( $code ) ) {
				return $code;
			}
		}

		return 'KIWE-' . strtoupper( substr( md5( microtime( true ) . wp_rand() ), 0, 10 ) );
	}

	private function coupon_exists( string $code ): bool {
		if ( function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return (int) wc_get_coupon_id_by_code( $code ) > 0;
		}

		$matches = get_posts(
			[
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'title'          => $code,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return ! empty( $matches );
	}

	private function reward_message( int $discount, ?array $coupon, int $remaining, array $config ): string {
		if ( $coupon && ! empty( $coupon['code'] ) ) {
			return sprintf(
				/* translators: 1: coupon code, 2: discount percent, 3: expiry minutes. */
				__( 'Unlocked %2$d%% off. Use code %1$s in the next %3$d minutes.', 'dsa' ),
				$coupon['code'],
				$discount,
				(int) $config['coupon_expiry_minutes']
			);
		}

		if ( $discount > 0 && empty( $config['coupon_enabled'] ) ) {
			return sprintf(
				/* translators: %d: discount percent. */
				__( 'Server verified: %d%% reward unlocked. Coupon generation is off in admin.', 'dsa' ),
				$discount
			);
		}

		if ( $remaining > 0 ) {
			return __( 'Attempt saved. Try again for the next reward tier.', 'dsa' );
		}

		return __( 'Attempts saved for today.', 'dsa' );
	}
}
