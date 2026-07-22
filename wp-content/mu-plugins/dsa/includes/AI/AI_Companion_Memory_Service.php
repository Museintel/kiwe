<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tiny local memory for repeated AI handoff/audit issues.
 *
 * It stores rule/finding fingerprints only. It never stores prompts, source
 * files, API keys, credentials, visitor data, or generated page/theme payloads.
 */
final class AI_Companion_Memory_Service {
	private const OPTION = 'dsa_ai_companion_memory';
	private const MAX_RECORDS = 200;

	public function records(): array {
		$records = get_option( self::OPTION, [] );
		if ( ! is_array( $records ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$records,
				static fn( $record ): bool => is_array( $record ) && ! empty( $record['fingerprint'] )
			)
		);
	}

	public function summary( int $limit = 12 ): array {
		$records = $this->records();
		usort(
			$records,
			static function ( array $a, array $b ): int {
				$count_compare = (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 );
				if ( 0 !== $count_compare ) {
					return $count_compare;
				}
				return strcmp( (string) ( $b['lastSeenAt'] ?? '' ), (string) ( $a['lastSeenAt'] ?? '' ) );
			}
		);

		return [
			'schema'    => 'kiwe.ai-companion.memory-summary.v1',
			'count'     => count( $records ),
			'keeps'     => 'finding fingerprints, counts, severities, lanes, modes, and compact messages only',
			'neverKeeps' => [
				'prompts',
				'file contents',
				'API keys',
				'credentials',
				'visitor identifiers',
				'orders or payment data',
			],
			'top'       => array_slice( $records, 0, max( 0, min( 40, $limit ) ) ),
		];
	}

	public function record_findings( array $findings, array $context = [] ): void {
		if ( [] === $findings ) {
			return;
		}

		$records = $this->records();
		$index   = [];
		foreach ( $records as $offset => $record ) {
			$index[ (string) ( $record['fingerprint'] ?? '' ) ] = $offset;
		}

		$now  = gmdate( 'c' );
		$mode = sanitize_key( (string) ( $context['mode'] ?? '' ) );
		$lane = sanitize_key( (string) ( $context['lane'] ?? '' ) );

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$code     = sanitize_key( (string) ( $finding['code'] ?? 'unknown' ) );
			$severity = sanitize_key( (string) ( $finding['severity'] ?? $finding['level'] ?? 'info' ) );
			if ( ! in_array( $severity, [ 'critical', 'error', 'warning', 'info', 'pass' ], true ) ) {
				$severity = 'info';
			}
			$message = $this->compact_message( (string) ( $finding['message'] ?? $finding['title'] ?? $code ) );
			$fingerprint = substr( hash( 'sha256', implode( '|', [ $code, $severity, $message, $mode, $lane ] ) ), 0, 32 );

			if ( isset( $index[ $fingerprint ] ) ) {
				$offset = $index[ $fingerprint ];
				$records[ $offset ]['count']      = max( 1, (int) ( $records[ $offset ]['count'] ?? 1 ) ) + 1;
				$records[ $offset ]['lastSeenAt'] = $now;
				continue;
			}

			$records[] = [
				'fingerprint' => $fingerprint,
				'code'        => $code,
				'severity'    => $severity,
				'mode'        => $mode,
				'lane'        => $lane,
				'message'     => $message,
				'count'       => 1,
				'firstSeenAt' => $now,
				'lastSeenAt'  => $now,
			];
			$index[ $fingerprint ] = count( $records ) - 1;
		}

		usort(
			$records,
			static fn( array $a, array $b ): int => strcmp( (string) ( $b['lastSeenAt'] ?? '' ), (string) ( $a['lastSeenAt'] ?? '' ) )
		);
		update_option( self::OPTION, array_slice( $records, 0, self::MAX_RECORDS ), false );
	}

	public function clear(): bool {
		return delete_option( self::OPTION );
	}

	private function compact_message( string $message ): string {
		$message = preg_replace( '/kiwe_ai_[A-Za-z0-9_:-]+/', 'kiwe_ai_[redacted]', $message );
		$message = preg_replace( '/Bearer\s+[A-Za-z0-9._:-]+/i', 'Bearer [redacted]', (string) $message );
		$message = sanitize_text_field( (string) $message );

		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 180 ) : substr( $message, 0, 180 );
	}
}
