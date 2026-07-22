<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redacted, machine-readable SecureTrack context for Kiwe internal AI.
 *
 * This service deliberately summarizes security posture without exposing raw
 * IPs, usernames, secrets, full URLs, request payloads, or visitor trails.
 */
final class SecureTrack_AI_Brief_Service {
	public function brief( int $limit = 12 ): array {
		$limit = max( 1, min( 40, $limit ) );
		$ready = $this->ready();
		$cfg   = function_exists( 'stp_cfg' ) ? stp_cfg() : [];

		return [
			'schema'      => 'kiwe.securetrack.ai-brief.v1',
			'generatedAt' => gmdate( 'c' ),
			'available'   => $ready,
			'privacy'     => [
				'redacted'     => true,
				'rawIps'       => false,
				'rawUsers'     => false,
				'rawSecrets'   => false,
				'rawUrls'      => false,
				'payloads'     => false,
				'description'  => 'Brief uses counts, lanes, statuses, hashes/buckets, and sanitized labels only.',
			],
			'configuration' => $this->configuration( is_array( $cfg ) ? $cfg : [] ),
			'counts'        => $ready ? $this->counts() : [],
			'aiQueue'       => $ready ? $this->ai_queue( $limit ) : [],
			'threatLanes'   => $ready ? $this->threat_lanes( $limit ) : [],
			'alerts'        => $ready ? $this->alerts( $limit ) : [],
			'brain'         => $ready ? $this->brain( $limit ) : [],
			'recommendations' => $this->recommendations( is_array( $cfg ) ? $cfg : [], $ready ),
			'boundaries'    => [
				'This is a read-only security brief.',
				'It may guide internal AI explanations, triage, and admin recommendations.',
				'It must not silently block, unblock, trust, delete, or mutate security data.',
			],
		];
	}

	private function ready(): bool {
		return function_exists( 'stp_table_exists' )
			&& function_exists( 'stp_t' )
			&& function_exists( 'stp_cfg' )
			&& function_exists( 'stp_tables_ready' )
			&& stp_tables_ready();
	}

	private function configuration( array $cfg ): array {
		return [
			'emergencySafeMode' => ! empty( $cfg['emergency_safe_mode'] ) || ( class_exists( SecureTrack_Runtime_Guard::class ) && SecureTrack_Runtime_Guard::enforcement_paused() ),
			'siteBrainEnabled'  => ! empty( $cfg['v2_site_brain'] ),
			'aiProvider'        => sanitize_key( (string) ( $cfg['v2_ai_provider'] ?? 'none' ) ),
			'aiConfigured'      => ! empty( $cfg['v2_ai_key'] ),
			'aiMode'            => sanitize_key( (string) ( $cfg['v2_ai_mode'] ?? '' ) ),
			'trackVisitors'     => ! empty( $cfg['track_visitors'] ),
			'trackPages'        => ! empty( $cfg['track_pages'] ),
			'adaptiveLearning'  => ! empty( $cfg['adaptive_learning'] ),
			'behavioralRisk'    => ! empty( $cfg['behavioral_risk'] ),
			'attackGraph'       => ! empty( $cfg['attack_graph'] ),
			'chainDetection'    => ! empty( $cfg['chain_detection'] ),
			'endpointRateLimits' => ! empty( $cfg['endpoint_rate_limits'] ),
			'bruteForceBlocking' => ! empty( $cfg['block_brute_force'] ),
		];
	}

	private function counts(): array {
		$overview = class_exists( SecureTrack_Admin_Data_Service::class ) ? SecureTrack_Admin_Data_Service::events_overview_counts() : [];

		return [
			'overview'       => is_array( $overview ) ? array_map( 'absint', $overview ) : [],
			'openAlerts'     => class_exists( SecureTrack_Admin_Data_Service::class ) ? SecureTrack_Admin_Data_Service::open_alerts_count() : 0,
			'protections24h' => class_exists( SecureTrack_Admin_Data_Service::class ) ? SecureTrack_Admin_Data_Service::protections_count_24h() : 0,
			'tables'         => $this->table_status(),
		];
	}

	private function table_status(): array {
		$out = [];
		foreach ( [ 'ips', 'sessions', 'events', 'profiles', 'pages', 'alerts', 'subnets', 'brain', 'ai_queue', 'rate_limits' ] as $table ) {
			$out[ $table ] = function_exists( 'stp_table_exists' ) && stp_table_exists( $table );
		}

		return $out;
	}

	private function ai_queue( int $limit ): array {
		global $wpdb;

		if ( ! stp_table_exists( 'ai_queue' ) ) {
			return [ 'available' => false ];
		}

		$status_rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . stp_t( 'ai_queue' ) . ' GROUP BY status', ARRAY_A );
		$statuses    = [];
		foreach ( is_array( $status_rows ) ? $status_rows : [] as $row ) {
			$statuses[ sanitize_key( (string) ( $row['status'] ?? '' ) ) ] = absint( $row['total'] ?? 0 );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT provider, local_score, status, ai_score, ai_label, created_at, reviewed_at FROM ' . stp_t( 'ai_queue' ) . ' ORDER BY id DESC LIMIT %d',
				$limit
			),
			ARRAY_A
		);

		return [
			'available' => true,
			'statuses'  => $statuses,
			'recent'    => array_map( [ $this, 'queue_row' ], is_array( $rows ) ? $rows : [] ),
		];
	}

	private function queue_row( array $row ): array {
		return [
			'provider'   => sanitize_key( (string) ( $row['provider'] ?? '' ) ),
			'localScore' => max( 0, min( 100, (int) ( $row['local_score'] ?? 0 ) ) ),
			'status'     => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'aiScore'    => isset( $row['ai_score'] ) ? max( 0, min( 100, (int) $row['ai_score'] ) ) : null,
			'aiLabel'    => sanitize_key( (string) ( $row['ai_label'] ?? '' ) ),
			'createdAt'  => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
			'reviewedAt' => sanitize_text_field( (string) ( $row['reviewed_at'] ?? '' ) ),
		];
	}

	private function threat_lanes( int $limit ): array {
		global $wpdb;

		if ( ! stp_table_exists( 'events' ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, event_sub, flag_status, COUNT(*) AS total, ROUND(AVG(risk_score)) AS avg_score, MAX(risk_score) AS max_score
				FROM " . stp_t( 'events' ) . "
				WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
				GROUP BY event_type, event_sub, flag_status
				ORDER BY max_score DESC, total DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return [
					'eventType' => sanitize_key( (string) ( $row['event_type'] ?? '' ) ),
					'eventSub'  => sanitize_key( (string) ( $row['event_sub'] ?? '' ) ),
					'flag'      => sanitize_key( (string) ( $row['flag_status'] ?? '' ) ),
					'total'     => absint( $row['total'] ?? 0 ),
					'avgScore'  => max( 0, min( 100, (int) ( $row['avg_score'] ?? 0 ) ) ),
					'maxScore'  => max( 0, min( 100, (int) ( $row['max_score'] ?? 0 ) ) ),
				];
			},
			is_array( $rows ) ? $rows : []
		);
	}

	private function alerts( int $limit ): array {
		global $wpdb;

		if ( ! stp_table_exists( 'alerts' ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT chain_type, severity, is_resolved, COUNT(*) AS total, MAX(alert_time) AS last_seen
				FROM " . stp_t( 'alerts' ) . '
				GROUP BY chain_type, severity, is_resolved
				ORDER BY is_resolved ASC, FIELD(severity, "critical", "high", "medium", "low") ASC, total DESC
				LIMIT %d',
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return [
					'chainType'  => sanitize_key( (string) ( $row['chain_type'] ?? '' ) ),
					'severity'   => sanitize_key( (string) ( $row['severity'] ?? '' ) ),
					'resolved'   => ! empty( $row['is_resolved'] ),
					'total'      => absint( $row['total'] ?? 0 ),
					'lastSeen'   => sanitize_text_field( (string) ( $row['last_seen'] ?? '' ) ),
				];
			},
			is_array( $rows ) ? $rows : []
		);
	}

	private function brain( int $limit ): array {
		global $wpdb;

		if ( ! stp_table_exists( 'brain' ) ) {
			return [ 'available' => false ];
		}

		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . stp_t( 'brain' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT feature_type, COUNT(*) AS total, SUM(risk_count) AS risk_total, SUM(good_count) AS good_total, ROUND(AVG(confidence)) AS avg_confidence
				FROM " . stp_t( 'brain' ) . '
				GROUP BY feature_type
				ORDER BY risk_total DESC, total DESC
				LIMIT %d',
				$limit
			),
			ARRAY_A
		);

		return [
			'available'    => true,
			'featureCount' => max( 0, $total ),
			'featureTypes' => array_map(
				static function ( array $row ): array {
					return [
						'type'          => sanitize_key( (string) ( $row['feature_type'] ?? '' ) ),
						'total'         => absint( $row['total'] ?? 0 ),
						'riskTotal'     => absint( $row['risk_total'] ?? 0 ),
						'goodTotal'     => absint( $row['good_total'] ?? 0 ),
						'avgConfidence' => max( 0, min( 100, (int) ( $row['avg_confidence'] ?? 0 ) ) ),
					];
				},
				is_array( $rows ) ? $rows : []
			),
		];
	}

	private function recommendations( array $cfg, bool $ready ): array {
		$out = [];
		if ( ! $ready ) {
			$out[] = 'SecureTrack tables are not ready; repair/activation should run before AI security reasoning.';
			return $out;
		}
		if ( ! empty( $cfg['emergency_safe_mode'] ) ) {
			$out[] = 'Emergency safe mode is active; keep AI recommendations advisory until enforcement is intentionally resumed.';
		}
		if ( empty( $cfg['v2_site_brain'] ) ) {
			$out[] = 'Enable Site Brain for local-first behavioral learning before relying on provider AI review.';
		}
		if ( empty( $cfg['endpoint_rate_limits'] ) ) {
			$out[] = 'Endpoint rate limits are disabled; internal AI should flag REST/login/XML-RPC exposure in security summaries.';
		}
		if ( empty( $cfg['v2_ai_key'] ) || 'none' === (string) ( $cfg['v2_ai_provider'] ?? 'none' ) ) {
			$out[] = 'SecureTrack provider AI is not configured; use local Site Brain and queue status only.';
		}

		return array_values( array_unique( $out ) );
	}
}
