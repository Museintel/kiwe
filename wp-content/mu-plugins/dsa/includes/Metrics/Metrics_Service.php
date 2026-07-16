<?php

namespace DSA\Metrics;

use DSA\Commerce\Store_Analytics_Service;
use DSA\Diagnostics\Runtime_Profiler;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Metrics_Service {
	private const OPTION = 'dsa_interstice_metrics';

	private $settings;
	private $store_analytics;

	public function __construct( Settings $settings, ?Store_Analytics_Service $store_analytics = null ) {
		$this->settings = $settings;
		$this->store_analytics = $store_analytics;
	}

	public function public_config(): array {
		$config = $this->config();

		return [
			'enabled'       => ! empty( $config['enabled'] ),
			'retentionDays' => (int) $config['retention_days'],
		];
	}

	public function record( array $payload ): array {
		$config = $this->config();

		if ( empty( $config['enabled'] ) ) {
			return [ 'ok' => false, 'disabled' => true ];
		}

		$event = $this->sanitize_event( (string) ( $payload['event'] ?? '' ) );
		if ( '' === $event ) {
			return [ 'ok' => false, 'message' => __( 'Unknown metric event.', 'dsa' ) ];
		}

		$context = $this->sanitize_context( (string) ( $payload['context'] ?? '' ) );
		$value = isset( $payload['value'] ) ? max( 0, min( 1000000, (int) $payload['value'] ) ) : 0;
		$day = gmdate( 'Y-m-d' );
		$data = $this->data();

		if ( empty( $data['days'][ $day ] ) || ! is_array( $data['days'][ $day ] ) ) {
			$data['days'][ $day ] = $this->empty_day();
		}

		$data['days'][ $day ]['total']++;
		$data['days'][ $day ]['events'][ $event ] = (int) ( $data['days'][ $day ]['events'][ $event ] ?? 0 ) + 1;

		if ( '' !== $context ) {
			$key = $event . ':' . $context;
			$contexts = is_array( $data['days'][ $day ]['contexts'] ?? null ) ? $data['days'][ $day ]['contexts'] : [];
			if ( ! isset( $contexts[ $key ] ) && count( $contexts ) >= 100 ) $key = $event . ':other';
			$data['days'][ $day ]['contexts'][ $key ] = (int) ( $data['days'][ $day ]['contexts'][ $key ] ?? 0 ) + 1;
		}

		if ( $value > 0 ) {
			if ( empty( $data['days'][ $day ]['values'][ $event ] ) || ! is_array( $data['days'][ $day ]['values'][ $event ] ) ) {
				$data['days'][ $day ]['values'][ $event ] = [ 'count' => 0, 'sum' => 0, 'max' => 0 ];
			}

			$data['days'][ $day ]['values'][ $event ]['count']++;
			$data['days'][ $day ]['values'][ $event ]['sum'] += $value;
			$data['days'][ $day ]['values'][ $event ]['max'] = max( (int) $data['days'][ $day ]['values'][ $event ]['max'], $value );
		}

		$data['lastEventAt'] = gmdate( 'c' );
		$data = $this->prune( $data, (int) $config['retention_days'] );
		$profile = Runtime_Profiler::start();
		update_option( self::OPTION, $data, false );
		Runtime_Profiler::finish( 'metrics.rollup_write', $profile );
		$this->record_adoption_event( $event, $context );

		return [ 'ok' => true ];
	}

	public function summary(): array {
		$config = $this->config();
		$data = $this->data();
		$days = isset( $data['days'] ) && is_array( $data['days'] ) ? $data['days'] : [];
		krsort( $days );

		$totals = [
			'total' => 0,
			'events' => [],
			'contexts' => [],
			'values' => [],
		];
		$daily = [];

		foreach ( array_slice( $days, 0, (int) $config['retention_days'], true ) as $day => $row ) {
			$row = wp_parse_args( is_array( $row ) ? $row : [], $this->empty_day() );
			$totals['total'] += (int) $row['total'];
			$this->merge_counts( $totals['events'], is_array( $row['events'] ) ? $row['events'] : [] );
			$this->merge_counts( $totals['contexts'], is_array( $row['contexts'] ) ? $row['contexts'] : [] );
			$this->merge_values( $totals['values'], is_array( $row['values'] ) ? $row['values'] : [] );
			$daily[] = [
				'day'      => $day,
				'total'    => (int) $row['total'],
				'topEvent' => $this->top_key( is_array( $row['events'] ) ? $row['events'] : [] ),
			];
		}

		return [
			'enabled'       => ! empty( $config['enabled'] ),
			'retentionDays' => (int) $config['retention_days'],
			'lastEventAt'   => sanitize_text_field( $data['lastEventAt'] ?? '' ),
			'totals'        => [
				'total'    => (int) $totals['total'],
				'events'   => $this->sorted_counts( $totals['events'] ),
				'contexts' => $this->sorted_counts( $totals['contexts'] ),
				'values'   => $this->value_summary( $totals['values'] ),
			],
			'daily'         => $daily,
			'notes'         => [
				__( 'Metrics are daily aggregates. DSA does not store visitor URLs, IP addresses, names, phone numbers, emails, or per-user timelines in this v1 proof layer.', 'dsa' ),
				__( 'Revenue/session lift is intentionally deferred until checkout/order attribution can be audited safely.', 'dsa' ),
			],
		];
	}

	public function reset(): void {
		delete_option( self::OPTION );
	}

	private function config(): array {
		$config = $this->settings->get( 'metrics', [] );
		$config = is_array( $config ) ? $config : [];

		return [
			'enabled'        => ! empty( $config['enabled'] ),
			'retention_days' => max( 1, min( 90, (int) ( $config['retention_days'] ?? 14 ) ) ),
		];
	}

	private function data(): array {
		$data = get_option( self::OPTION, [] );
		$data = is_array( $data ) ? $data : [];

		return wp_parse_args(
			$data,
			[
				'version'     => 1,
				'lastEventAt' => '',
				'days'        => [],
			]
		);
	}

	private function empty_day(): array {
		return [
			'total'    => 0,
			'events'   => [],
			'contexts' => [],
			'values'   => [],
		];
	}

	private function prune( array $data, int $retention_days ): array {
		$days = isset( $data['days'] ) && is_array( $data['days'] ) ? $data['days'] : [];
		krsort( $days );
		$data['days'] = array_slice( $days, 0, $retention_days, true );

		return $data;
	}

	private function sanitize_event( string $event ): string {
		$event = sanitize_key( $event );
		$allowed = [
			'dock_open',
			'dock_close',
			'transition_start',
			'transition_complete',
			'transition_timeout',
			'transition_fallback',
			'appsite_home_view',
			'appsite_home_dismiss',
			'pwa_prompt_available',
			'pwa_install_click',
			'pwa_install_choice',
			'pwa_primer_ok',
			'pwa_install_intent',
			'pwa_prompt_accepted',
			'pwa_install_dismissed',
			'pwa_installed',
			'pwa_standalone_launch',
			'notification_prompt',
			'notification_granted',
			'notification_denied',
			'notification_preferences_saved',
			'game_surface_show',
			'game_start',
			'game_complete',
			'game_reward_verified',
			'protected_flow_view',
		];

		return in_array( $event, $allowed, true ) ? $event : '';
	}

	private function record_adoption_event( string $event, string $context ): void {
		if ( ! $this->store_analytics ) {
			return;
		}

		$map = [
			'pwa_primer_ok'           => 'pwa_primer_ok',
			'pwa_install_intent'      => 'pwa_install_intent',
			'pwa_prompt_accepted'     => 'pwa_prompt_accepted',
			'pwa_install_dismissed'   => 'pwa_install_dismissed',
			'pwa_installed'           => 'pwa_installed',
			'pwa_standalone_launch'   => 'pwa_standalone',
			'notification_prompt'     => 'notification_prompt',
			'notification_granted'    => 'notification_granted',
			'notification_denied'     => 'notification_denied',
			'notification_preferences_saved' => 'notification_preferences_saved',
		];

		if ( isset( $map[ $event ] ) ) {
			$this->store_analytics->record_adoption_event( $map[ $event ], $context );
		}
	}

	private function sanitize_context( string $context ): string {
		$context = sanitize_key( $context );
		return substr( $context, 0, 60 );
	}

	private function merge_counts( array &$target, array $source ): void {
		foreach ( $source as $key => $count ) {
			$key = sanitize_text_field( (string) $key );
			$target[ $key ] = (int) ( $target[ $key ] ?? 0 ) + max( 0, (int) $count );
		}
	}

	private function merge_values( array &$target, array $source ): void {
		foreach ( $source as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( empty( $target[ $key ] ) || ! is_array( $target[ $key ] ) ) {
				$target[ $key ] = [ 'count' => 0, 'sum' => 0, 'max' => 0 ];
			}

			$target[ $key ]['count'] += max( 0, (int) ( $row['count'] ?? 0 ) );
			$target[ $key ]['sum'] += max( 0, (int) ( $row['sum'] ?? 0 ) );
			$target[ $key ]['max'] = max( (int) $target[ $key ]['max'], max( 0, (int) ( $row['max'] ?? 0 ) ) );
		}
	}

	private function sorted_counts( array $counts ): array {
		arsort( $counts );
		$out = [];

		foreach ( array_slice( $counts, 0, 20, true ) as $key => $count ) {
			$out[] = [
				'key'   => sanitize_text_field( (string) $key ),
				'count' => (int) $count,
			];
		}

		return $out;
	}

	private function value_summary( array $values ): array {
		$out = [];

		foreach ( $values as $event => $row ) {
			$count = max( 0, (int) ( $row['count'] ?? 0 ) );
			$sum = max( 0, (int) ( $row['sum'] ?? 0 ) );
			$out[] = [
				'event' => sanitize_key( $event ),
				'count' => $count,
				'avg'   => $count ? (int) round( $sum / $count ) : 0,
				'max'   => max( 0, (int) ( $row['max'] ?? 0 ) ),
			];
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return (int) $b['count'] <=> (int) $a['count'];
			}
		);

		return $out;
	}

	private function top_key( array $counts ): string {
		if ( empty( $counts ) ) {
			return '';
		}

		arsort( $counts );
		$key = array_key_first( $counts );

		return is_string( $key ) ? sanitize_text_field( $key ) : '';
	}
}
