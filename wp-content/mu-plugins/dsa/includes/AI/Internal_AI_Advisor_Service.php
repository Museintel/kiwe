<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic first-pass advisor for Kiwe internal AI.
 *
 * This service does not call a model and does not mutate anything. It turns the
 * safe internal context packet into findings, recommendations, and next safe
 * actions so WordPress AI Client / Abilities can enrich later without gaining
 * silent write authority.
 */
final class Internal_AI_Advisor_Service {
	private const FOCUS = [ 'all', 'security', 'headless', 'wp7', 'staging', 'site_graph' ];

	public function __construct( private Internal_AI_Context_Service $context_service ) {}

	public function advise( array $args = [] ): array {
		$focus        = $this->focus( $args['focus'] ?? 'all' );
		$sample_limit = max( 0, min( 24, absint( $args['sampleLimit'] ?? 8 ) ) );
		$secure_limit = max( 1, min( 40, absint( $args['secureLimit'] ?? 12 ) ) );
		$context      = $this->context_service->context(
			[
				'sampleLimit' => $sample_limit,
				'secureLimit' => $secure_limit,
			]
		);

		$findings        = [];
		$recommendations = [];
		$actions         = [];

		$this->security_advice( $context, $focus, $findings, $recommendations, $actions );
		$this->headless_advice( $context, $focus, $findings, $recommendations, $actions );
		$this->wp7_advice( $context, $focus, $findings, $recommendations, $actions );
		$this->staging_advice( $context, $focus, $findings, $recommendations, $actions );

		return [
			'ok'              => true,
			'schema'          => 'kiwe.internal-ai.advisor.v1',
			'generatedAt'     => gmdate( 'c' ),
			'mode'            => 'deterministic-readonly',
			'focus'           => $focus,
			'contextHash'     => $this->stable_hash( $context ),
			'contextSchema'   => sanitize_text_field( (string) ( $context['schema'] ?? 'kiwe.internal-ai.context.v1' ) ),
			'model'           => $this->model_status( $context ),
			'summary'         => [
				'findings'        => count( $findings ),
				'recommendations' => count( $recommendations ),
				'actions'         => count( $actions ),
				'blockers'        => count( array_filter( $findings, static fn( array $finding ): bool => 'critical' === ( $finding['severity'] ?? '' ) ) ),
			],
			'findings'        => array_values( $findings ),
			'recommendations' => array_values( $recommendations ),
			'actions'         => array_values( $actions ),
			'boundaries'      => [
				'readonly'              => true,
				'modelOptional'         => true,
				'usesRedactedSecurity'  => true,
				'mutatesWordPress'      => false,
				'mutatesBricksContent'  => false,
				'mutatesWooCommerce'    => false,
				'mutatesSecurityRules'  => false,
				'neverSilent'           => [ 'checkout', 'payment', 'auth', 'publish', 'raw Bricks writes', 'WooCommerce mutation', 'security enforcement changes' ],
			],
		];
	}

	private function security_advice( array $context, string $focus, array &$findings, array &$recommendations, array &$actions ): void {
		if ( ! $this->in_focus( $focus, [ 'security', 'all' ] ) ) {
			return;
		}

		$brief  = $this->array_get( $context, [ 'secureTrack' ] );
		$config = $this->array_get( $brief, [ 'configuration' ] );
		$counts = $this->array_get( $brief, [ 'counts' ] );

		if ( empty( $brief['available'] ) ) {
			$findings[] = $this->finding( 'securetrack.unavailable', 'warning', 'SecureTrack brief is unavailable', 'Internal AI can still advise on Site Graph, but security posture is incomplete until SecureTrack tables/functions are loaded.', 'secureTrack.available' );
			$recommendations[] = $this->recommendation( 'securetrack-load-check', 'Check SecureTrack installation and table readiness before relying on security advice.', 'security', 'admin-review' );
			return;
		}

		if ( ! empty( $config['emergencySafeMode'] ) ) {
			$findings[] = $this->finding( 'securetrack.safe_mode', 'warning', 'SecureTrack enforcement is paused', 'Emergency safe mode is active. Kiwe should explain this clearly and avoid claiming active enforcement.', 'secureTrack.configuration.emergencySafeMode' );
			$actions[]  = $this->action( 'review-securetrack-safe-mode', 'Review SecureTrack safe mode', 'admin-review', false, 'Open Kiwe Secure/SecureTrack settings and confirm whether enforcement should remain paused.' );
		}

		if ( empty( $config['siteBrainEnabled'] ) ) {
			$recommendations[] = $this->recommendation( 'enable-site-brain-when-ready', 'Enable SecureTrack Site Brain only after baseline traffic has been reviewed.', 'security', 'configure' );
		}

		if ( empty( $config['endpointRateLimits'] ) ) {
			$recommendations[] = $this->recommendation( 'tune-endpoint-rate-limits', 'Keep endpoint rate limits visible as a tuning candidate before production traffic grows.', 'security', 'configure' );
		}

		$open_alerts = absint( $counts['openAlerts'] ?? 0 );
		if ( $open_alerts > 0 ) {
			$findings[] = $this->finding( 'securetrack.open_alerts', 'warning', 'Open SecureTrack alerts need review', sprintf( '%d open alert(s) are summarized in the redacted brief.', $open_alerts ), 'secureTrack.counts.openAlerts' );
			$actions[]  = $this->action( 'review-open-security-alerts', 'Review open SecureTrack alerts', 'admin-review', false, 'Use the SecureTrack admin UI; the advisor must not resolve or suppress alerts silently.' );
		}

		$protections = absint( $counts['protections24h'] ?? 0 );
		if ( $protections > 0 ) {
			$findings[] = $this->finding( 'securetrack.recent_protections', 'info', 'Recent protections were recorded', sprintf( '%d protection event(s) were summarized in the last 24 hours.', $protections ), 'secureTrack.counts.protections24h' );
		}
	}

	private function headless_advice( array $context, string $focus, array &$findings, array &$recommendations, array &$actions ): void {
		if ( ! $this->in_focus( $focus, [ 'headless', 'site_graph', 'all' ] ) ) {
			return;
		}

		$schema    = $this->array_get( $context, [ 'dataLayer', 'schema' ] );
		$resources = $this->array_get( $schema, [ 'resources' ] );
		$routes    = $this->array_get( $context, [ 'siteGraph', 'route' ] );

		$findings[] = $this->finding( 'sitegraph.data_available', 'good', 'Site Graph Data is available as the headless read lane', 'AI/web builders should query real posts, products, terms, media, menus, and site identity here instead of scraping the frontend.', 'dataLayer.schema' );

		if ( is_array( $resources ) && count( $resources ) > 0 ) {
			$recommendations[] = $this->recommendation( 'use-batch-site-data', sprintf( 'Use batch Site Graph Data queries for multi-rail pages; %d resource group(s) are advertised.', count( $resources ) ), 'headless', 'fetch' );
		}

		$actions[] = $this->action( 'fetch-site-graph-data-schema', 'Fetch Site Graph Data schema', 'api-read', false, 'Use the schema before creating Bricks bindings or headless sections.', esc_url_raw( (string) ( $routes['dataSchema'] ?? '/wp-json/dsa/v1/site-graph/data/schema' ) ) );
		$actions[] = $this->action( 'query-site-graph-data', 'Query real WordPress/WooCommerce data', 'api-read', false, 'Use read-only Site Graph Data for products, posts, pages, menus, media, terms, CPTs, and taxonomies.', esc_url_raw( (string) ( $routes['dataQuery'] ?? '/wp-json/dsa/v1/site-graph/data' ) ) );
	}

	private function wp7_advice( array $context, string $focus, array &$findings, array &$recommendations, array &$actions ): void {
		if ( ! $this->in_focus( $focus, [ 'wp7', 'all' ] ) ) {
			return;
		}

		$wp7 = $this->array_get( $context, [ 'wp7' ] );

		if ( ! empty( $wp7['serverAbilitiesAvailable'] ) ) {
			$findings[] = $this->finding( 'wp7.abilities_available', 'good', 'WordPress Abilities are available', 'Kiwe can expose tool-callable abilities for native/admin AI clients.', 'wp7.serverAbilitiesAvailable' );
			$actions[]  = $this->action( 'discover-kiwe-abilities', 'Discover Kiwe Abilities', 'wp-ability-read', false, 'Use WordPress Abilities for native connector discovery when the host supports them.' );
		} else {
			$findings[] = $this->finding( 'wp7.abilities_fallback', 'info', 'WordPress Abilities are not available on this host', 'Kiwe REST/API-key routes remain the fallback connector surface.', 'wp7.serverAbilitiesAvailable' );
		}

		if ( ! empty( $wp7['aiClientAvailable'] ) ) {
			$findings[] = $this->finding( 'wp7.ai_client_available', 'good', 'WordPress AI Client appears available', 'A future Kiwe advisor can use the native client for explanation/enrichment while keeping deterministic checks authoritative.', 'wp7.aiClientAvailable' );
		} else {
			$recommendations[] = $this->recommendation( 'keep-model-optional', 'Keep internal AI recommendations deterministic until a native WP AI Client or approved provider is configured.', 'wp7', 'architecture' );
		}
	}

	private function staging_advice( array $context, string $focus, array &$findings, array &$recommendations, array &$actions ): void {
		if ( ! $this->in_focus( $focus, [ 'staging', 'all' ] ) ) {
			return;
		}

		$operating = $this->array_get( $context, [ 'operatingModel' ] );
		$execute   = isset( $operating['execute'] ) && is_array( $operating['execute'] ) ? $operating['execute'] : [];

		$findings[] = $this->finding( 'staging.executor_guarded', 'good', 'Mutation authority remains behind the controlled staging executor', implode( ' ', array_map( 'sanitize_text_field', $execute ) ), 'operatingModel.execute' );
		$recommendations[] = $this->recommendation( 'plan-before-execute', 'Continue requiring binding validation, apply-plan preparation, trusted staging, explicit confirmation, and post-apply verification before any write.', 'staging', 'guardrail' );
		$actions[] = $this->action( 'prepare-apply-plan', 'Prepare a dry-run apply plan', 'api-plan', false, 'Validate and prepare first; do not jump from generated design directly to writes.', '/wp-json/dsa/v1/ai/prepare-apply-plan' );
	}

	private function model_status( array $context ): array {
		$wp7 = $this->array_get( $context, [ 'wp7' ] );

		return [
			'callsModel'       => false,
			'nativeAvailable'  => ! empty( $wp7['aiClientAvailable'] ),
			'futureEnrichment' => 'A model may rewrite explanations or group priorities later, but deterministic findings and mutation boundaries remain authoritative.',
		];
	}

	private function focus( mixed $value ): string {
		$focus = sanitize_key( (string) $value );

		return in_array( $focus, self::FOCUS, true ) ? $focus : 'all';
	}

	private function in_focus( string $focus, array $lanes ): bool {
		return in_array( $focus, $lanes, true ) || in_array( 'all', $lanes, true ) && 'all' === $focus;
	}

	private function finding( string $id, string $severity, string $title, string $detail, string $source ): array {
		return [
			'id'       => sanitize_key( $id ),
			'severity' => sanitize_key( $severity ),
			'title'    => sanitize_text_field( $title ),
			'detail'   => sanitize_textarea_field( $detail ),
			'source'   => sanitize_text_field( $source ),
		];
	}

	private function recommendation( string $id, string $message, string $lane, string $type ): array {
		return [
			'id'      => sanitize_key( $id ),
			'lane'    => sanitize_key( $lane ),
			'type'    => sanitize_key( $type ),
			'message' => sanitize_textarea_field( $message ),
		];
	}

	private function action( string $id, string $label, string $type, bool $mutates, string $description, string $route = '' ): array {
		return [
			'id'                   => sanitize_key( $id ),
			'label'                => sanitize_text_field( $label ),
			'type'                 => sanitize_key( $type ),
			'mutates'              => $mutates,
			'requiresConfirmation' => $mutates,
			'description'          => sanitize_textarea_field( $description ),
			'route'                => $route,
		];
	}

	private function array_get( array $value, array $path ): array {
		$current = $value;
		foreach ( $path as $key ) {
			if ( ! is_array( $current ) || ! isset( $current[ $key ] ) || ! is_array( $current[ $key ] ) ) {
				return [];
			}
			$current = $current[ $key ];
		}

		return $current;
	}

	private function stable_hash( array $value ): string {
		return substr( hash( 'sha256', (string) wp_json_encode( $value ) ), 0, 32 );
	}
}
