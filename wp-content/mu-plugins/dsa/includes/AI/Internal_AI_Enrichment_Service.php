<?php

namespace DSA\AI;

use DSA\WP7\AI_Client_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model-optional enrichment seam for the Kiwe internal advisor.
 *
 * The deterministic advisor remains authoritative. This service prepares the
 * bounded packet a native WordPress AI Client may enrich later and returns a
 * deterministic fallback summary today. It never grants mutation authority.
 */
final class Internal_AI_Enrichment_Service {
	public function __construct(
		private Internal_AI_Advisor_Service $advisor,
		private ?AI_Client_Service $ai_client = null
	) {
		$this->ai_client = $this->ai_client ?: new AI_Client_Service();
	}

	public function enrich( array $args = [] ): array {
		$advisor = $this->advisor->advise( $args );
		$style   = $this->style( $args['style'] ?? 'executive' );
		$limit   = max( 1, min( 12, absint( $args['limit'] ?? 6 ) ) );

		$findings        = isset( $advisor['findings'] ) && is_array( $advisor['findings'] ) ? $advisor['findings'] : [];
		$recommendations = isset( $advisor['recommendations'] ) && is_array( $advisor['recommendations'] ) ? $advisor['recommendations'] : [];
		$actions         = isset( $advisor['actions'] ) && is_array( $advisor['actions'] ) ? $advisor['actions'] : [];
		$priority        = $this->prioritize( $findings, $recommendations, $actions, $limit );
		$summary         = $this->deterministic_summary( $advisor, $priority, $style );
		$client_summary  = $this->ai_client->summary();

		return [
			'ok'          => true,
			'schema'      => 'kiwe.internal-ai.enrichment.v1',
			'generatedAt' => gmdate( 'c' ),
			'mode'        => ! empty( $client_summary['available'] ) ? 'native-ready-fallback-summary' : 'deterministic-fallback-summary',
			'style'       => $style,
			'advisor'     => [
				'schema'      => sanitize_text_field( (string) ( $advisor['schema'] ?? 'kiwe.internal-ai.advisor.v1' ) ),
				'contextHash' => sanitize_text_field( (string) ( $advisor['contextHash'] ?? '' ) ),
				'focus'       => sanitize_key( (string) ( $advisor['focus'] ?? 'all' ) ),
				'summary'     => isset( $advisor['summary'] ) && is_array( $advisor['summary'] ) ? $advisor['summary'] : [],
			],
			'enrichment'  => [
				'headline'       => $summary['headline'],
				'narrative'      => $summary['narrative'],
				'priorityOrder'  => $priority,
				'nextBestAction' => $summary['nextBestAction'],
			],
			'model'       => [
				'available'       => ! empty( $client_summary['available'] ),
				'called'          => false,
				'provider'        => 'wordpress-ai-client',
				'status'          => sanitize_key( (string) ( $client_summary['status'] ?? 'fallback' ) ),
				'whyNotCalled'    => ! empty( $client_summary['available'] )
					? 'Native client detected, but Batch 70 only prepares the bounded enrichment seam. A later adapter may call the model after prompt/output schema locking.'
					: 'No native WordPress AI Client detected. Deterministic fallback summary returned.',
				'futureAdapter'   => [
					'input'  => 'modelEnvelope.payload',
					'output' => 'modelEnvelope.allowedOutputSchema',
					'locked' => [ 'advisor.contextHash', 'advisor.summary', 'boundaries', 'priorityOrder.sourceIds' ],
				],
			],
			'modelEnvelope' => $this->model_envelope( $advisor, $priority, $style ),
			'boundaries'  => [
				'readonly'             => true,
				'deterministicTruth'   => 'kiwe.internal-ai.advisor.v1',
				'modelMayChange'       => [ 'headline', 'narrative', 'grouping labels', 'plain-language explanations' ],
				'modelMustNotChange'   => [ 'finding ids', 'severity', 'counts', 'mutation boundaries', 'routes', 'scope requirements', 'confirmation requirements' ],
				'mutatesWordPress'     => false,
				'mutatesBricksContent' => false,
				'mutatesWooCommerce'   => false,
				'mutatesSecurityRules' => false,
			],
		];
	}

	private function deterministic_summary( array $advisor, array $priority, string $style ): array {
		$summary = isset( $advisor['summary'] ) && is_array( $advisor['summary'] ) ? $advisor['summary'] : [];
		$blockers = absint( $summary['blockers'] ?? 0 );
		$findings = absint( $summary['findings'] ?? 0 );
		$recommendations = absint( $summary['recommendations'] ?? 0 );
		$actions = absint( $summary['actions'] ?? 0 );

		$headline = $blockers > 0
			? 'Kiwe found critical items that need human review before any apply path.'
			: 'Kiwe is ready for safe review with deterministic advisor guidance.';

		if ( 'developer' === $style ) {
			$narrative = sprintf(
				'Advisor produced %1$d finding(s), %2$d recommendation(s), and %3$d safe action(s). Treat advisor IDs/severities as locked source data; any model layer may only rewrite explanations.',
				$findings,
				$recommendations,
				$actions
			);
		} else {
			$narrative = sprintf(
				'Kiwe reviewed the current site context and found %1$d signal(s), %2$d recommendation(s), and %3$d safe next action(s). Nothing here changes the site by itself.',
				$findings,
				$recommendations,
				$actions
			);
		}

		$next = isset( $priority[0] ) && is_array( $priority[0] ) ? (string) ( $priority[0]['title'] ?? $priority[0]['message'] ?? '' ) : '';
		if ( '' === $next ) {
			$next = 'Refresh Site Graph and continue with normal review.';
		}

		return [
			'headline'       => sanitize_text_field( $headline ),
			'narrative'      => sanitize_textarea_field( $narrative ),
			'nextBestAction' => sanitize_text_field( $next ),
		];
	}

	private function prioritize( array $findings, array $recommendations, array $actions, int $limit ): array {
		$out = [];

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$out[] = [
				'source'   => 'finding',
				'id'       => sanitize_key( (string) ( $finding['id'] ?? '' ) ),
				'weight'   => $this->severity_weight( (string) ( $finding['severity'] ?? 'info' ) ),
				'severity' => sanitize_key( (string) ( $finding['severity'] ?? 'info' ) ),
				'title'    => sanitize_text_field( (string) ( $finding['title'] ?? '' ) ),
				'message'  => sanitize_textarea_field( (string) ( $finding['detail'] ?? '' ) ),
			];
		}

		foreach ( $recommendations as $recommendation ) {
			if ( ! is_array( $recommendation ) ) {
				continue;
			}
			$out[] = [
				'source'  => 'recommendation',
				'id'      => sanitize_key( (string) ( $recommendation['id'] ?? '' ) ),
				'weight'  => 40,
				'lane'    => sanitize_key( (string) ( $recommendation['lane'] ?? '' ) ),
				'title'   => sanitize_text_field( (string) ( $recommendation['type'] ?? 'recommendation' ) ),
				'message' => sanitize_textarea_field( (string) ( $recommendation['message'] ?? '' ) ),
			];
		}

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$out[] = [
				'source'  => 'action',
				'id'      => sanitize_key( (string) ( $action['id'] ?? '' ) ),
				'weight'  => ! empty( $action['mutates'] ) ? 20 : 35,
				'type'    => sanitize_key( (string) ( $action['type'] ?? '' ) ),
				'title'   => sanitize_text_field( (string) ( $action['label'] ?? '' ) ),
				'message' => sanitize_textarea_field( (string) ( $action['description'] ?? '' ) ),
			];
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => (int) ( $b['weight'] ?? 0 ) <=> (int) ( $a['weight'] ?? 0 )
		);

		return array_slice( array_values( $out ), 0, $limit );
	}

	private function model_envelope( array $advisor, array $priority, string $style ): array {
		return [
			'system' => 'You are Kiwe internal AI. Explain and prioritize only. Do not invent capabilities, change severities, grant mutation authority, or claim actions were executed.',
			'instructions' => [
				'Use the advisor packet as source of truth.',
				'Rewrite in clear admin-facing language.',
				'Keep all mutation and confirmation boundaries intact.',
				'If data is missing, say what to fetch next instead of guessing.',
			],
			'style' => $style,
			'payload' => [
				'advisorContextHash' => sanitize_text_field( (string) ( $advisor['contextHash'] ?? '' ) ),
				'advisorSummary'     => isset( $advisor['summary'] ) && is_array( $advisor['summary'] ) ? $advisor['summary'] : [],
				'priorityOrder'      => $priority,
				'boundaries'         => isset( $advisor['boundaries'] ) && is_array( $advisor['boundaries'] ) ? $advisor['boundaries'] : [],
			],
			'allowedOutputSchema' => [
				'type'       => 'object',
				'properties' => [
					'headline'       => [ 'type' => 'string', 'maxLength' => 180 ],
					'narrative'      => [ 'type' => 'string', 'maxLength' => 1200 ],
					'priorityOrder'  => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
					'nextBestAction' => [ 'type' => 'string', 'maxLength' => 240 ],
				],
				'required'   => [ 'headline', 'narrative', 'priorityOrder', 'nextBestAction' ],
			],
		];
	}

	private function style( mixed $value ): string {
		$style = sanitize_key( (string) $value );

		return in_array( $style, [ 'executive', 'developer', 'security', 'handoff' ], true ) ? $style : 'executive';
	}

	private function severity_weight( string $severity ): int {
		return match ( sanitize_key( $severity ) ) {
			'critical' => 100,
			'warning'  => 70,
			'good'     => 30,
			default    => 50,
		};
	}
}
