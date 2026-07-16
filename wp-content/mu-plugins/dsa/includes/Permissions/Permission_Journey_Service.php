<?php

namespace DSA\Permissions;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Permission_Journey_Service {
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function public_config(): array {
		$config = $this->config();

		return [
			'enabled'       => ! empty( $config['enabled'] ),
			'retentionDays' => (int) $config['retention_days'],
			'cooldownHours' => (int) $config['cooldown_hours'],
			'maxAsksPerSession' => (int) $config['max_asks_per_session'],
			'permissions'   => [
				'pwa_install' => [
					'enabled'  => ! empty( $config['pwa_enabled'] ),
					'title'    => sanitize_text_field( $config['pwa_title'] ),
					'message'  => sanitize_text_field( $config['pwa_message'] ),
					'thresholds' => [
						'homeViews'           => (int) $config['pwa_min_home_views'],
						'dockOpens'           => (int) $config['pwa_min_dock_opens'],
						'transitionCompletes' => (int) $config['pwa_min_transition_completes'],
						'gameCompletes'       => (int) $config['pwa_min_game_completes'],
					],
				],
				'browser_notifications' => [
					'enabled' => ! empty( $config['notifications_enabled'] ),
					'title'   => sanitize_text_field( $config['notifications_title'] ),
					'message' => sanitize_text_field( $config['notifications_message'] ),
				],
			],
		];
	}

	public function decision( array $payload ): array {
		$config = $this->config();
		$type = $this->permission_type( (string) ( $payload['type'] ?? '' ) );
		$events = $this->events( isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : [] );

		if ( empty( $config['enabled'] ) ) {
			return $this->deny( 'disabled', __( 'Permission journeys are disabled.', 'dsa' ), $events );
		}

		$permission_enabled = ( 'pwa_install' === $type && ! empty( $config['pwa_enabled'] ) )
			|| ( 'browser_notifications' === $type && ! empty( $config['notifications_enabled'] ) );

		if ( '' === $type || ! $permission_enabled ) {
			return $this->deny( 'not_enabled', __( 'This permission journey is not enabled yet.', 'dsa' ), $events );
		}

		if ( ! empty( $payload['protectedFlow'] ) ) {
			return $this->deny( 'protected_flow', __( 'Kiwe waits until protected checkout or account work is complete.', 'dsa' ), $events );
		}

		$identity = $this->identity( (string) ( $payload['visitorId'] ?? '' ) );
		$state = $this->state( $identity, $type );

		if ( in_array( $state['state'], [ 'granted', 'denied' ], true ) ) {
			return $this->deny( $state['state'], __( 'This permission journey is already complete.', 'dsa' ), $events, $state );
		}

		$cooldown_until = (int) ( $state['cooldown_until'] ?? 0 );
		if ( $cooldown_until > time() ) {
			return $this->deny( 'cooldown', __( 'Kiwe will wait before asking again.', 'dsa' ), $events, $state );
		}

		if ( 'browser_notifications' === $type ) {
			return [
				'ok'      => true,
				'allowed' => true,
				'reason'  => 'explicit_gesture',
				'type'    => $type,
				'title'   => sanitize_text_field( $config['notifications_title'] ),
				'message' => sanitize_text_field( $config['notifications_message'] ),
				'events'  => $events,
				'state'   => 'eligible',
			];
		}

		$missing = empty( $payload['explicit'] ) ? $this->missing_thresholds( $events, $config ) : [];
		if ( ! empty( $missing ) ) {
			return [
				'ok'       => true,
				'allowed'  => false,
				'reason'   => 'not_earned',
				'message'  => __( 'Use the appsite a little more before installing.', 'dsa' ),
				'missing'  => $missing,
				'events'   => $events,
				'state'    => $state['state'],
			];
		}

		$state['state'] = 'eligible';
		$state['eligible_at'] = time();
		$this->save_state( $identity, $type, $state, (int) $config['retention_days'] );

		if ( empty( $payload['available'] ) ) {
			return [
				'ok'      => true,
				'allowed' => true,
				'reason'  => 'fallback',
				'type'    => $type,
				'title'   => __( 'Install options ready', 'dsa' ),
				'message' => __( 'The native install prompt is not available, so Kiwe will show the browser-specific Add to Home Screen steps.', 'dsa' ),
				'events'  => $events,
				'state'   => 'eligible',
			];
		}

		return [
			'ok'      => true,
			'allowed' => true,
			'reason'  => 'earned',
			'type'    => $type,
			'title'   => sanitize_text_field( $config['pwa_title'] ),
			'message' => sanitize_text_field( $config['pwa_message'] ),
			'events'  => $events,
			'state'   => 'eligible',
		];
	}

	public function record( array $payload ): array {
		$config = $this->config();
		$type = $this->permission_type( (string) ( $payload['type'] ?? '' ) );
		$outcome = sanitize_key( (string) ( $payload['outcome'] ?? '' ) );

		$allowed_outcomes = 'pwa_install' === $type
			? [ 'shown', 'accepted', 'dismissed', 'fallback' ]
			: [ 'shown', 'granted', 'denied', 'default' ];

		if ( '' === $type || ! in_array( $outcome, $allowed_outcomes, true ) ) {
			return [ 'ok' => false, 'message' => __( 'Unknown permission journey outcome.', 'dsa' ) ];
		}

		$identity = $this->identity( (string) ( $payload['visitorId'] ?? '' ) );
		$state = $this->state( $identity, $type );
		$state['last_outcome'] = $outcome;
		$state['last_outcome_at'] = time();
		$state['ask_count'] = max( 0, (int) ( $state['ask_count'] ?? 0 ) );

		if ( 'shown' === $outcome ) {
			$state['state'] = 'asked';
			$state['ask_count']++;
		} elseif ( in_array( $outcome, [ 'accepted', 'granted' ], true ) ) {
			$state['state'] = 'granted';
		} elseif ( in_array( $outcome, [ 'dismissed', 'default' ], true ) ) {
			$state['state'] = 'asked_dismissed';
			$state['cooldown_until'] = time() + ( (int) $config['cooldown_hours'] * HOUR_IN_SECONDS );
		} elseif ( 'denied' === $outcome ) {
			$state['state'] = 'denied';
		} elseif ( 'fallback' === $outcome ) {
			$state['state'] = 'fallback';
			$state['cooldown_until'] = time() + HOUR_IN_SECONDS;
		}

		$this->save_state( $identity, $type, $state, (int) $config['retention_days'] );

		return [
			'ok'    => true,
			'state' => $state['state'],
		];
	}

	private function config(): array {
		$config = $this->settings->get( 'permissions', [] );
		$config = is_array( $config ) ? $config : [];

		return [
			'enabled'                      => ! empty( $config['enabled'] ),
			'retention_days'               => max( 1, min( 90, (int) ( $config['retention_days'] ?? 30 ) ) ),
			'cooldown_hours'               => max( 1, min( 720, (int) ( $config['cooldown_hours'] ?? 24 ) ) ),
			'max_asks_per_session'         => max( 1, min( 5, (int) ( $config['max_asks_per_session'] ?? 1 ) ) ),
			'pwa_enabled'                  => ! empty( $config['pwa_enabled'] ),
			'pwa_min_home_views'           => max( 0, min( 20, (int) ( $config['pwa_min_home_views'] ?? 1 ) ) ),
			'pwa_min_dock_opens'           => max( 0, min( 20, (int) ( $config['pwa_min_dock_opens'] ?? 1 ) ) ),
			'pwa_min_transition_completes' => max( 0, min( 50, (int) ( $config['pwa_min_transition_completes'] ?? 1 ) ) ),
			'pwa_min_game_completes'       => max( 0, min( 20, (int) ( $config['pwa_min_game_completes'] ?? 0 ) ) ),
			'pwa_title'                    => sanitize_text_field( $config['pwa_title'] ?? __( 'Install this appsite?', 'dsa' ) ),
			'pwa_message'                  => sanitize_text_field( $config['pwa_message'] ?? __( 'Kiwe will open your browser install prompt now.', 'dsa' ) ),
			'notifications_enabled'        => ! empty( $config['notifications_enabled'] ),
			'notifications_title'          => sanitize_text_field( $config['notifications_title'] ?? __( 'Turn on browser notifications?', 'dsa' ) ),
			'notifications_message'        => sanitize_text_field( $config['notifications_message'] ?? __( 'Get useful order, account, and store updates when you choose.', 'dsa' ) ),
		];
	}

	private function events( array $events ): array {
		return [
			'homeViews'           => max( 0, min( 1000, (int) ( $events['homeViews'] ?? 0 ) ) ),
			'dockOpens'           => max( 0, min( 1000, (int) ( $events['dockOpens'] ?? 0 ) ) ),
			'transitionCompletes' => max( 0, min( 1000, (int) ( $events['transitionCompletes'] ?? 0 ) ) ),
			'gameCompletes'       => max( 0, min( 1000, (int) ( $events['gameCompletes'] ?? 0 ) ) ),
		];
	}

	private function missing_thresholds( array $events, array $config ): array {
		$map = [
			'homeViews'           => (int) $config['pwa_min_home_views'],
			'dockOpens'           => (int) $config['pwa_min_dock_opens'],
			'transitionCompletes' => (int) $config['pwa_min_transition_completes'],
			'gameCompletes'       => (int) $config['pwa_min_game_completes'],
		];
		$missing = [];

		foreach ( $map as $key => $required ) {
			$current = (int) ( $events[ $key ] ?? 0 );

			if ( $current < $required ) {
				$missing[ $key ] = max( 0, $required - $current );
			}
		}

		return $missing;
	}

	private function deny( string $reason, string $message, array $events, array $state = [] ): array {
		return [
			'ok'      => true,
			'allowed' => false,
			'reason'  => sanitize_key( $reason ),
			'message' => $message,
			'events'  => $events,
			'state'   => sanitize_key( $state['state'] ?? 'never_asked' ),
			'cooldownUntil' => (int) ( $state['cooldown_until'] ?? 0 ),
		];
	}

	private function permission_type( string $type ): string {
		$type = sanitize_key( $type );
		return in_array( $type, [ 'pwa_install', 'browser_notifications' ], true ) ? $type : '';
	}

	private function identity( string $visitor_id ): string {
		$visitor_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $visitor_id );
		$visitor_id = substr( $visitor_id ?: 'anon', 0, 64 );
		$user_id = get_current_user_id();
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
		$ua = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 180 );
		$subject = $user_id ? 'user:' . $user_id : 'visitor:' . $visitor_id . '|' . $ip . '|' . $ua;

		return hash_hmac( 'sha256', $subject, wp_salt( 'auth' ) );
	}

	private function state( string $identity, string $type ): array {
		$state = get_transient( $this->state_key( $identity, $type ) );
		$state = is_array( $state ) ? $state : [];

		return wp_parse_args(
			$state,
			[
				'state'           => 'never_asked',
				'ask_count'       => 0,
				'cooldown_until'  => 0,
				'last_outcome'    => '',
				'last_outcome_at' => 0,
			]
		);
	}

	private function save_state( string $identity, string $type, array $state, int $retention_days ): void {
		set_transient( $this->state_key( $identity, $type ), $state, max( DAY_IN_SECONDS, $retention_days * DAY_IN_SECONDS ) );
	}

	private function state_key( string $identity, string $type ): string {
		return 'dsa_permission_' . sanitize_key( $type ) . '_' . substr( $identity, 0, 28 );
	}
}
