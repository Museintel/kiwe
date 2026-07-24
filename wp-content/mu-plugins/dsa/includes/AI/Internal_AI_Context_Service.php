<?php

namespace DSA\AI;

use DSA\Secure\SecureTrack_AI_Brief_Service;
use DSA\Site_Graph\Data_Query_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-party internal AI context pack.
 *
 * This does not call a model. It prepares the safe, structured context that a
 * future WordPress AI Client, MCP ability, or Kiwe internal copilot can use.
 */
final class Internal_AI_Context_Service {
	public function __construct(
		private Site_Graph_Service $site_graph,
		private ?Data_Query_Service $data_query = null,
		private ?SecureTrack_AI_Brief_Service $securetrack = null
	) {
		$this->data_query  = $this->data_query ?: new Data_Query_Service();
		$this->securetrack = $this->securetrack ?: new SecureTrack_AI_Brief_Service();
	}

	public function context( array $args = [] ): array {
		$sample_limit = max( 0, min( 24, absint( $args['sampleLimit'] ?? 8 ) ) );
		$secure_limit = max( 1, min( 40, absint( $args['secureLimit'] ?? 12 ) ) );
		$include_securetrack = ! empty( $args['includeSecureTrack'] );

		$graph   = $this->site_graph->graph( [ 'sampleLimit' => $sample_limit ] );
		$summary = $this->site_graph->summary();

		return [
			'schema'      => 'kiwe.internal-ai.context.v1',
			'generatedAt' => gmdate( 'c' ),
			'purpose'     => 'Safe first-party context for Kiwe internal AI, WordPress Abilities, MCP-style tools, admin copilot, and staging planners.',
			'siteGraph'   => [
				'summary'   => $summary,
				'graphHash' => $this->stable_hash( $graph ),
				'schema'    => sanitize_text_field( (string) ( $graph['schema'] ?? 'kiwe.site-graph.v1' ) ),
				'generatedAt' => sanitize_text_field( (string) ( $graph['generatedAt'] ?? '' ) ),
				'route'     => [
					'adminGraph'      => '/wp-json/dsa/v1/site-graph',
					'aiGraph'         => '/wp-json/dsa/v1/ai/site-graph',
					'dataSchema'      => '/wp-json/dsa/v1/site-graph/data/schema',
					'dataQuery'       => '/wp-json/dsa/v1/site-graph/data',
					'internalContext' => '/wp-json/dsa/v1/ai/internal-context',
					'internalAdvisor' => '/wp-json/dsa/v1/ai/advisor',
					'internalEnrichment' => '/wp-json/dsa/v1/ai/advisor/enrich',
					'companionContext' => '/wp-json/dsa/v1/ai/companion/context',
					'companionReview'  => '/wp-json/dsa/v1/ai/companion/review-output',
					'bricksConversionValidation' => '/wp-json/dsa/v1/ai/validate-bricks-conversion',
				],
			],
			'dataLayer'   => [
				'schema'      => $this->data_query->schema(),
				'headlessUse' => [
					'Use Site Graph Data for real public posts/products/media/terms/menus instead of scraping the frontend.',
					'Use batch queries when one page needs multiple rails or datasets.',
					'Keep writes in the Controlled Executor.',
				],
			],
			'secureTrack' => $include_securetrack
				? $this->securetrack->brief( $secure_limit )
				: [
					'schema'  => 'kiwe.securetrack-ai-brief.v1',
					'enabled' => false,
					'reason'  => 'SecureTrack brief is separately gated in Kiwe > AI and by API key scope.',
				],
			'wp7'         => $this->wp7_summary(),
			'capabilityMap' => $this->capability_map(),
			'operatingModel' => [
				'read'       => [ 'site-graph', 'site-graph-data', 'site-inspection', 'securetrack-brief' ],
				'plan'       => [ 'validate-bindings', 'validate-bricks-conversion', 'prepare-apply-plan', 'trusted-apply-stage' ],
				'execute'    => [ 'controlled-staging-executor-only-with-explicit-confirmation' ],
				'neverSilent' => [ 'checkout', 'payment', 'auth', 'publish', 'WooCommerce mutation', 'raw Bricks writes', 'security enforcement changes' ],
			],
		];
	}

	private function wp7_summary(): array {
		return [
			'wpVersion'                => function_exists( 'get_bloginfo' ) ? sanitize_text_field( (string) get_bloginfo( 'version' ) ) : '',
			'serverAbilitiesAvailable' => function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' ),
			'clientAbilitiesHint'      => function_exists( 'wp_script_is' ) && ( wp_script_is( '@wordpress/core-abilities', 'registered' ) || wp_script_is( 'wp-core-abilities', 'registered' ) ),
			'aiClientAvailable'        => function_exists( 'wp_ai_client' )
				|| function_exists( 'wp_get_ai_client' )
				|| class_exists( 'WP_AI_Client' )
				|| class_exists( 'WP_AI_Service' ),
			'notes'                    => [
				'Use WordPress Abilities when available for tool-callable discovery.',
				'Keep REST/API-key routes as fallback for WordPress versions or hosts without Abilities.',
			],
		];
	}

	private function capability_map(): array {
		return [
			'abilities' => [
				'dsa/get-site-graph',
				'dsa/get-site-graph-data-schema',
				'dsa/query-site-graph-data',
				'dsa/get-securetrack-brief',
				'dsa/get-internal-ai-context',
				'dsa/run-internal-ai-advisor',
				'dsa/enrich-internal-ai-advisor',
				'dsa/get-companion-context',
				'dsa/ask-companion',
				'dsa/review-ai-output',
				'dsa/validate-bindings',
				'dsa/validate-bricks-conversion',
				'dsa/prepare-apply-plan',
				'dsa/stage-apply-plan',
			],
			'rest'      => [
				'/wp-json/dsa/v1/site-graph',
				'/wp-json/dsa/v1/site-graph/query',
				'/wp-json/dsa/v1/site-graph/data/schema',
				'/wp-json/dsa/v1/site-graph/data',
				'/wp-json/dsa/v1/ai/internal-context',
				'/wp-json/dsa/v1/ai/advisor',
				'/wp-json/dsa/v1/ai/advisor/enrich',
				'/wp-json/dsa/v1/ai/security-brief',
				'/wp-json/dsa/v1/ai/companion/context',
				'/wp-json/dsa/v1/ai/companion/ask',
				'/wp-json/dsa/v1/ai/companion/review-output',
				'/wp-json/dsa/v1/ai/validate-bricks-conversion',
			],
		];
	}

	private function stable_hash( array $value ): string {
		return substr( hash( 'sha256', (string) wp_json_encode( $value ) ), 0, 32 );
	}
}
