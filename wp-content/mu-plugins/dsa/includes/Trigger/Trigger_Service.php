<?php

namespace DSA\Trigger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Trigger_Service {
	public function contract( array $settings, array $manifest, array $protected_flow = [] ): array {
		$visual = isset( $settings['visual_effects'] ) && is_array( $settings['visual_effects'] ) ? $settings['visual_effects'] : [];
		$app    = isset( $settings['app'] ) && is_array( $settings['app'] ) ? $settings['app'] : [];
		$games  = isset( $settings['games'] ) && is_array( $settings['games'] ) ? $settings['games'] : [];

		return [
			'version'         => 1,
			'currentMode'     => ! empty( $protected_flow['active'] ) ? 'protected' : '',
			'activeProtected' => ! empty( $protected_flow['active'] ),
			'modePriority'    => [
				'protected'   => 1,
				'game'        => 2,
				'transition'  => 3,
				'dock'        => 4,
				'appsiteHome' => 5,
			],
			'routeExclusions' => $this->route_exclusions( $manifest ),
			'rules'           => array_values(
				array_filter(
					[
						$this->first_session_home_rule( $visual ),
						$this->idle_home_rule( $app ),
						$this->safe_link_transition_rule( $visual ),
						$this->scheduled_game_rule( $games ),
						$this->protected_flow_rule( $protected_flow ),
					]
				)
			),
		];
	}

	private function first_session_home_rule( array $visual ): array {
		return [
			'id'           => 'first_session_home',
			'event'        => 'first_session',
			'mode'         => 'appsiteHome',
			'module'       => '',
			'enabled'      => ! empty( $visual['initial_preloader_enabled'] ),
			'durationMs'   => 0,
			'dismiss'      => 'scroll_touch_escape',
			'frequency'    => 'session_once',
			'priority'     => 5,
			'guards'       => [ 'not_protected_flow', 'no_active_mode' ],
			'adminSource'  => 'visual_effects.initial_preloader_enabled',
		];
	}

	private function idle_home_rule( array $app ): array {
		$delay = max( 10000, min( 1800000, (int) ( $app['idle_delay_ms'] ?? 60000 ) ) );

		return [
			'id'           => 'idle_home',
			'event'        => 'idle',
			'mode'         => 'appsiteHome',
			'module'       => '',
			'enabled'      => ! empty( $app['idle_enabled'] ),
			'delayMs'      => $delay,
			'durationMs'   => 0,
			'dismiss'      => 'scroll_touch_escape',
			'frequency'    => 'after_idle',
			'priority'     => 5,
			'guards'       => [ 'not_protected_flow', 'no_active_mode', 'no_interactive_focus' ],
			'adminSource'  => 'app.idle_enabled',
		];
	}

	private function safe_link_transition_rule( array $visual ): array {
		return [
			'id'           => 'safe_link_transition',
			'event'        => 'link_click',
			'mode'         => 'transition',
			'module'       => '',
			'enabled'      => ! empty( $visual['show_on_navigation'] ),
			'durationMs'   => max( 0, min( 10000, (int) ( $visual['min_loader_ms'] ?? 700 ) ) ),
			'dismiss'      => 'click_escape_hover_hold',
			'frequency'    => 'every_safe_navigation',
			'priority'     => 3,
			'guards'       => [ 'same_origin', 'route_not_excluded', 'not_protected_flow' ],
			'adminSource'  => 'visual_effects.show_on_navigation',
		];
	}

	private function scheduled_game_rule( array $games ): array {
		$game = in_array( $games['trigger_game'] ?? 'dino', [ 'dino', 'star' ], true ) ? sanitize_key( $games['trigger_game'] ) : 'dino';
		$path = trim( sanitize_text_field( $games['trigger_path'] ?? '/shop' ) );

		return [
			'id'           => 'scheduled_game',
			'event'        => 'route_match',
			'mode'         => 'game',
			'module'       => $game,
			'enabled'      => ! empty( $games['surface_enabled'] ) && ! empty( $games['show_on_page_load'] ) && '' !== $path,
			'path'         => $path,
			'durationMs'   => max( 0, min( 60000, (int) ( $games['duration_ms'] ?? 0 ) ) ),
			'dismiss'      => 'game_rules',
			'frequency'    => 'matching_route',
			'priority'     => 2,
			'guards'       => [ 'not_protected_flow', 'no_active_mode' ],
			'adminSource'  => 'games.surface_enabled',
			'payload'      => [
				'game' => $game,
			],
		];
	}

	private function protected_flow_rule( array $protected_flow ): array {
		if ( empty( $protected_flow['active'] ) ) {
			return [];
		}

		return [
			'id'           => 'protected_flow',
			'event'        => 'protected_route',
			'mode'         => 'protected',
			'module'       => '',
			'enabled'      => true,
			'durationMs'   => 0,
			'dismiss'      => 'protected_rules',
			'frequency'    => 'every_protected_route',
			'priority'     => 1,
			'guards'       => [ 'server_authoritative', 'full_navigation', 'trust_visible' ],
			'adminSource'  => 'protected_flow_guard',
			'payload'      => [
				'context' => sanitize_key( $protected_flow['context'] ?? '' ),
			],
		];
	}

	private function route_exclusions( array $manifest ): array {
		if ( isset( $manifest['routes']['excluded'] ) && is_array( $manifest['routes']['excluded'] ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $manifest['routes']['excluded'] ) ) );
		}

		return [];
	}
}
