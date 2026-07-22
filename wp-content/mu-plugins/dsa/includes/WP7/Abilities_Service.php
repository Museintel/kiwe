<?php

namespace DSA\WP7;

use DSA\AI\Apply_Plan_Preparer;
use DSA\AI\AI_Companion_Memory_Service;
use DSA\AI\AI_Companion_Service;
use DSA\AI\AI_Provider_Service;
use DSA\AI\Binding_Plan_Validator;
use DSA\AI\Bricks_AI_Intelligence_Service;
use DSA\AI\Internal_AI_Advisor_Service;
use DSA\AI\Internal_AI_Context_Service;
use DSA\AI\Internal_AI_Enrichment_Service;
use DSA\AI\Site_Graph_Service;
use DSA\AI\Studio_AI_Service;
use DSA\AI\Trusted_Apply_Stager;
use DSA\Element_Registry;
use DSA\Secure\SecureTrack_AI_Brief_Service;
use DSA\Settings;
use DSA\Site_Graph\Data_Query_Service;
use DSA\Trust\Trust_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Abilities_Service {
	private const CATEGORY = 'kiwe-appsite';
	private Data_Query_Service $data_query;
	private SecureTrack_AI_Brief_Service $securetrack;

	public function __construct(
		private Settings $settings,
		private Element_Registry $registry,
		private Trust_Service $trust,
		private ?Site_Graph_Service $site_graph = null
	) {
		$this->data_query  = new Data_Query_Service();
		$this->securetrack = new SecureTrack_AI_Brief_Service();
	}

	public function register(): void {
		if ( ! $this->available() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Kiwe Appsite', 'dsa' ),
				'description' => __( 'Readonly diagnostics for the Kiwe app shell, route registry, and deterministic trust state.', 'dsa' ),
			]
		);
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'dsa/audit-trust',
			[
				'label'               => __( 'Audit Kiwe trust signals', 'dsa' ),
				'description'         => __( 'Returns a deterministic, non-secret summary of SSL, Kiwe Key, Kiwe Secure, and payment-provider availability.', 'dsa' ),
				'category'            => self::CATEGORY,
				'output_schema'       => $this->trust_output_schema(),
				'execute_callback'    => [ $this, 'execute_trust_audit' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'annotations' => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'dsa/summarize-route',
			[
				'label'               => __( 'Summarize the current Kiwe route', 'dsa' ),
				'description'         => __( 'Returns a bounded semantic count of the current WordPress route without exposing element content or private visitor state.', 'dsa' ),
				'category'            => self::CATEGORY,
				'output_schema'       => $this->route_output_schema(),
				'execute_callback'    => [ $this, 'execute_route_summary' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'annotations' => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'dsa/get-site-graph-data-schema',
			[
				'label'               => __( 'Get Kiwe Site Graph Data schema', 'dsa' ),
				'description'         => __( 'Returns the read-only Site Graph Data contract for headless WordPress/WooCommerce/menu/media queries.', 'dsa' ),
				'category'            => self::CATEGORY,
				'output_schema'       => $this->generic_object_schema(),
				'execute_callback'    => [ $this, 'execute_site_graph_data_schema' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'annotations' => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'dsa/query-site-graph-data',
			[
				'label'               => __( 'Query Kiwe Site Graph Data', 'dsa' ),
				'description'         => __( 'Queries normalized WordPress, WooCommerce, media, term, menu, and site identity data through the Kiwe Site Graph Data reader.', 'dsa' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->site_graph_data_input_schema(),
				'output_schema'       => $this->generic_object_schema(),
				'execute_callback'    => [ $this, 'execute_site_graph_data' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'annotations' => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'dsa/get-securetrack-brief',
			[
				'label'               => __( 'Get SecureTrack AI brief', 'dsa' ),
				'description'         => __( 'Returns a redacted, read-only SecureTrack posture brief for internal AI triage and admin recommendations.', 'dsa' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->securetrack_brief_input_schema(),
				'output_schema'       => $this->generic_object_schema(),
				'execute_callback'    => [ $this, 'execute_securetrack_brief' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'annotations' => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'dsa/get-bricks-ai-context',
			[
				'label'               => __( 'Get Kiwe Bricks AI context', 'dsa' ),
				'description'         => __( 'Returns a read-only Bricks-native planning packet: elements, compact controls, query loops, dynamic tags, conditions, interactions, Seam rules, and Kiwe launcher boundaries.', 'dsa' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->generic_object_schema(),
				'output_schema'       => $this->generic_object_schema(),
				'execute_callback'    => [ $this, 'execute_bricks_ai_context' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'annotations' => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'dsa/plan-bricks-ai-page',
			[
				'label'               => __( 'Plan a Bricks-native Kiwe page', 'dsa' ),
				'description'         => __( 'Returns a compact Bricks + Seam planning packet for AI page, dynamic binding, combined, or audit work without mutating Bricks content.', 'dsa' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->generic_object_schema(),
				'output_schema'       => $this->generic_object_schema(),
				'execute_callback'    => [ $this, 'execute_bricks_ai_plan' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'annotations' => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		if ( $this->site_graph ) {
			wp_register_ability(
				'dsa/get-site-graph',
				[
					'label'               => __( 'Get Kiwe site graph', 'dsa' ),
					'description'         => __( 'Returns an admin-only, non-secret WordPress, WooCommerce, Bricks, Seam, and Kiwe capability graph for AI design/binding workflows.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->site_graph_input_schema(),
					'output_schema'       => $this->site_graph_output_schema(),
					'execute_callback'    => [ $this, 'execute_site_graph' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/validate-bindings',
				[
					'label'               => __( 'Validate Kiwe Bricks bindings', 'dsa' ),
					'description'         => __( 'Validates an AI-produced Kiwe/Bricks binding plan against the current live Site Graph without saving WordPress, Bricks, WooCommerce, or Kiwe runtime content.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->binding_validation_input_schema(),
					'output_schema'       => $this->binding_validation_output_schema(),
					'execute_callback'    => [ $this, 'execute_binding_validation' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/prepare-apply-plan',
				[
					'label'               => __( 'Prepare Kiwe dry-run apply plan', 'dsa' ),
					'description'         => __( 'Turns a validated Kiwe/Bricks binding plan into a non-mutating dry-run apply plan for admin review and future trusted adapter staging.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->prepare_apply_plan_input_schema(),
					'output_schema'       => $this->apply_plan_output_schema(),
					'execute_callback'    => [ $this, 'execute_prepare_apply_plan' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/stage-apply-plan',
				[
					'label'               => __( 'Stage Kiwe dry-run apply plan', 'dsa' ),
					'description'         => __( 'Stores a reviewed dry-run apply plan in Kiwe internal staging for trusted adapter review. This writes only Kiwe review metadata and does not save Bricks/page content.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->stage_apply_plan_input_schema(),
					'output_schema'       => $this->trusted_stage_output_schema(),
					'execute_callback'    => [ $this, 'execute_stage_apply_plan' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [
							'readonly'              => false,
							'mutatesWordPress'      => false,
							'mutatesBricksContent'  => false,
							'writesKiweReviewQueue' => true,
						],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/get-internal-ai-context',
				[
					'label'               => __( 'Get Kiwe internal AI context', 'dsa' ),
					'description'         => __( 'Returns the safe combined context for Kiwe internal AI: Site Graph summary, headless data schema, SecureTrack brief, WP7 signals, and operating boundaries.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->internal_ai_context_input_schema(),
					'output_schema'       => $this->generic_object_schema(),
					'execute_callback'    => [ $this, 'execute_internal_ai_context' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/run-internal-ai-advisor',
				[
					'label'               => __( 'Run Kiwe internal AI advisor', 'dsa' ),
					'description'         => __( 'Runs deterministic, read-only Kiwe advisor checks over Site Graph, Site Graph Data, SecureTrack, WP7 signals, and staging boundaries.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->internal_ai_advisor_input_schema(),
					'output_schema'       => $this->generic_object_schema(),
					'execute_callback'    => [ $this, 'execute_internal_ai_advisor' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/enrich-internal-ai-advisor',
				[
					'label'               => __( 'Enrich Kiwe internal AI advisor', 'dsa' ),
					'description'         => __( 'Returns the model-optional enrichment envelope for the deterministic Kiwe advisor. It may prepare native AI Client input, but does not mutate the site or call a model in this adapter.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->internal_ai_enrichment_input_schema(),
					'output_schema'       => $this->generic_object_schema(),
					'execute_callback'    => [ $this, 'execute_internal_ai_enrichment' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/get-companion-context',
				[
					'label'               => __( 'Get Kiwe Companion context', 'dsa' ),
					'description'         => __( 'Returns compact Kiwe contract cards for AI website/page, DSA theme, combined handoff, dynamic binding, audit, staging, or security work without reading the full codebase.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->generic_object_schema(),
					'output_schema'       => $this->generic_object_schema(),
					'execute_callback'    => [ $this, 'execute_companion_context' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/ask-companion',
				[
					'label'               => __( 'Ask Kiwe Companion', 'dsa' ),
					'description'         => __( 'Returns a deterministic, token-efficient Kiwe guidance answer. This does not call a model or mutate the site.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->generic_object_schema(),
					'output_schema'       => $this->generic_object_schema(),
					'execute_callback'    => [ $this, 'execute_companion_ask' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/review-ai-output',
				[
					'label'               => __( 'Review Kiwe AI output', 'dsa' ),
					'description'         => __( 'Runs deterministic Companion checks over AI handoff files and records only privacy-safe finding fingerprints for future guidance.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->generic_object_schema(),
					'output_schema'       => $this->generic_object_schema(),
					'execute_callback'    => [ $this, 'execute_companion_review_output' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/start-studio-project',
				[
					'label'               => __( 'Start Kiwe Studio AI project', 'dsa' ),
					'description'         => __( 'Returns a token-saving Studio context packet and workflow for website/page, AppShell theme, combined, dynamic, audit, staging, or security work.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->generic_object_schema(),
					'output_schema'       => $this->generic_object_schema(),
					'execute_callback'    => [ $this, 'execute_studio_start' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);

			wp_register_ability(
				'dsa/review-studio-output',
				[
					'label'               => __( 'Review Kiwe Studio output', 'dsa' ),
					'description'         => __( 'Runs the deterministic Studio/Companion review gate over an AI handoff before any trusted staging or live import is considered.', 'dsa' ),
					'category'            => self::CATEGORY,
					'input_schema'        => $this->generic_object_schema(),
					'output_schema'       => $this->generic_object_schema(),
					'execute_callback'    => [ $this, 'execute_studio_review' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'meta'                => [
						'annotations' => [ 'readonly' => true ],
						'show_in_rest' => true,
					],
				]
			);
		}
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function execute_trust_audit(): array {
		$link_hub = $this->settings->get( 'link_hub', [] );
		$summary  = $this->trust->summary( is_array( $link_hub ) ? $link_hub : [] );

		return [
			'version'          => 1,
			'sslActive'        => ! empty( $summary['ssl']['active'] ),
			'sslProvider'      => sanitize_text_field( (string) ( $summary['ssl']['provider'] ?? '' ) ),
			'phonekeyActive'   => ! empty( $summary['phonekey']['active'] ),
			'securetrackActive' => ! empty( $summary['secure']['active'] ),
			'paymentActive'    => ! empty( $summary['payment']['active'] ),
			'paymentProviders' => array_values( array_map( 'sanitize_text_field', array_slice( (array) ( $summary['payment']['providers'] ?? [] ), 0, 8 ) ) ),
		];
	}

	public function execute_route_summary(): array {
		$registry = $this->registry->to_array();
		$types    = [];

		foreach ( (array) ( $registry['summary'] ?? [] ) as $type => $count ) {
			$types[] = [
				'type'  => sanitize_key( (string) $type ),
				'count' => max( 0, (int) $count ),
			];
		}

		return [
			'version'        => 1,
			'route'          => esc_url_raw( (string) ( $registry['route'] ?? home_url( '/' ) ) ),
			'postId'         => max( 0, (int) ( $registry['postId'] ?? 0 ) ),
			'elementCount'   => max( 0, (int) ( $registry['count'] ?? 0 ) ),
			'registrySource' => sanitize_key( (string) ( $registry['registrySource'] ?? 'runtime' ) ),
			'types'          => array_slice( $types, 0, 24 ),
		];
	}

	public function execute_site_graph( array $input = [] ): array {
		if ( ! $this->site_graph ) {
			return [
				'schema' => 'kiwe.site-graph.v1',
				'error'  => 'site_graph_unavailable',
			];
		}

		return $this->site_graph->graph(
			[
				'sampleLimit' => isset( $input['sampleLimit'] ) ? absint( $input['sampleLimit'] ) : 8,
			]
		);
	}

	public function execute_site_graph_data_schema(): array {
		return $this->data_query->schema();
	}

	public function execute_site_graph_data( array $input = [] ): array {
		$private = empty( $input['publicOnly'] );
		unset( $input['publicOnly'], $input['abilityInvocationId'] );

		return $this->data_query->query( $input, $private );
	}

	public function execute_securetrack_brief( array $input = [] ): array {
		if ( ! $this->securetrack_brief_allowed() ) {
			return [
				'ok'     => false,
				'schema' => 'kiwe.securetrack-ai-brief.v1',
				'error'  => [
					'code'    => 'securetrack_brief_not_allowed',
					'message' => 'Enable redacted SecureTrack brief sharing in Kiwe > AI before exposing it to AI abilities.',
				],
			];
		}

		return $this->securetrack->brief( isset( $input['limit'] ) ? absint( $input['limit'] ) : 12 );
	}

	public function execute_internal_ai_context( array $input = [] ): array {
		if ( ! $this->site_graph ) {
			return [
				'schema' => 'kiwe.internal-ai.context.v1',
				'error'  => 'site_graph_unavailable',
			];
		}

		$input['includeSecureTrack'] = $this->securetrack_brief_allowed();

		return ( new Internal_AI_Context_Service( $this->site_graph, $this->data_query, $this->securetrack ) )->context( $input );
	}

	public function execute_internal_ai_advisor( array $input = [] ): array {
		if ( ! $this->site_graph ) {
			return [
				'ok'     => false,
				'schema' => 'kiwe.internal-ai.advisor.v1',
				'error'  => 'site_graph_unavailable',
			];
		}

		$input['includeSecureTrack'] = $this->securetrack_brief_allowed();

		return ( new Internal_AI_Advisor_Service( new Internal_AI_Context_Service( $this->site_graph, $this->data_query, $this->securetrack ) ) )->advise( $input );
	}

	public function execute_internal_ai_enrichment( array $input = [] ): array {
		if ( ! $this->site_graph ) {
			return [
				'ok'     => false,
				'schema' => 'kiwe.internal-ai.enrichment.v1',
				'error'  => 'site_graph_unavailable',
			];
		}

		$input['includeSecureTrack'] = $this->securetrack_brief_allowed();

		return ( new Internal_AI_Enrichment_Service( new Internal_AI_Advisor_Service( new Internal_AI_Context_Service( $this->site_graph, $this->data_query, $this->securetrack ) ) ) )->enrich( $input );
	}

	public function execute_companion_context( array $input = [] ): array {
		return $this->companion()->context( $input, [ 'record' => [ 'scopes' => [ 'admin' ] ] ] );
	}

	public function execute_companion_ask( array $input = [] ): array {
		return $this->companion()->ask( $input, [ 'record' => [ 'scopes' => [ 'admin' ] ] ] );
	}

	public function execute_companion_review_output( array $input = [] ): array {
		return $this->companion()->review_output( $input, [ 'record' => [ 'scopes' => [ 'admin' ] ] ] );
	}

	public function execute_studio_start( array $input = [] ): array {
		return $this->studio()->start_project( $input, [ 'record' => [ 'scopes' => [ 'admin' ] ] ] );
	}

	public function execute_studio_review( array $input = [] ): array {
		return $this->studio()->review( $input, [ 'record' => [ 'scopes' => [ 'admin' ] ] ] );
	}

	public function execute_bricks_ai_context( array $input = [] ): array {
		return ( new Bricks_AI_Intelligence_Service( $this->settings ) )->context( $input );
	}

	public function execute_bricks_ai_plan( array $input = [] ): array {
		return ( new Bricks_AI_Intelligence_Service( $this->settings ) )->planning_packet( $input );
	}

	public function execute_binding_validation( array $input = [] ): array {
		$binding = isset( $input['binding'] ) && is_array( $input['binding'] ) ? $input['binding'] : [];
		if ( [] === $binding ) {
			return [
				'ok'       => false,
				'schema'   => 'kiwe.binding-validation.v1',
				'counts'   => [ 'error' => 1 ],
				'findings' => [
					[
						'level'   => 'error',
						'code'    => 'missing_binding',
						'message' => 'Input must include a binding object.',
					],
				],
			];
		}

		$site_graph = $this->site_graph_from_input( $input );

		return ( new Binding_Plan_Validator() )->validate( $binding, $site_graph );
	}

	public function execute_prepare_apply_plan( array $input = [] ): array {
		$binding = isset( $input['binding'] ) && is_array( $input['binding'] ) ? $input['binding'] : [];
		if ( [] === $binding ) {
			return [
				'ok'       => false,
				'schema'   => 'kiwe.apply-plan-result.v1',
				'plan'     => [],
				'counts'   => [ 'error' => 1 ],
				'findings' => [
					[
						'level'   => 'error',
						'code'    => 'missing_binding',
						'message' => 'Input must include a binding object.',
					],
				],
			];
		}

		$site_graph = $this->site_graph_from_input( $input );
		$report     = ( new Binding_Plan_Validator() )->validate( $binding, $site_graph );
		if ( empty( $report['ok'] ) ) {
			return [
				'ok'                => false,
				'schema'            => 'kiwe.apply-plan-result.v1',
				'plan'              => [],
				'bindingValidation' => $report,
				'counts'            => isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : [],
				'findings'          => isset( $report['findings'] ) && is_array( $report['findings'] ) ? $report['findings'] : [],
			];
		}

		$plan = ( new Apply_Plan_Preparer() )->prepare( $binding, $site_graph, $report );

		return [
			'ok'                => true,
			'schema'            => 'kiwe.apply-plan-result.v1',
			'plan'              => $plan,
			'bindingValidation' => $report,
			'counts'            => [],
			'findings'          => [],
		];
	}

	public function execute_stage_apply_plan( array $input = [] ): array {
		$apply_plan = isset( $input['applyPlan'] ) && is_array( $input['applyPlan'] ) ? $input['applyPlan'] : [];
		if ( [] === $apply_plan ) {
			return [
				'schema'   => 'kiwe.trusted-apply-stage.v1',
				'status'   => 'blocked-review',
				'blockers' => [ 'Input must include an applyPlan object.' ],
				'mutatesWordPress'     => false,
				'mutatesBricksContent' => false,
			];
		}

		return ( new Trusted_Apply_Stager() )->stage(
			$apply_plan,
			[
				'userId'              => get_current_user_id(),
				'createdAt'           => gmdate( 'c' ),
				'siteName'            => isset( $input['siteName'] ) ? sanitize_text_field( (string) $input['siteName'] ) : '',
				'fileName'            => isset( $input['fileName'] ) ? sanitize_file_name( (string) $input['fileName'] ) : '',
				'bindingReportKey'    => isset( $input['bindingReportKey'] ) ? sanitize_key( (string) $input['bindingReportKey'] ) : '',
				'abilityInvocationId' => isset( $input['abilityInvocationId'] ) ? sanitize_key( (string) $input['abilityInvocationId'] ) : '',
			]
		);
	}

	public function summary(): array {
		return [
			'id'          => 'abilities',
			'label'       => __( 'Abilities API', 'dsa' ),
			'available'   => $this->available(),
			'status'      => $this->available() ? 'registered-safe-connector' : 'fallback',
			'description' => __( 'Machine-readable, admin-only diagnostics and safe connector planning surfaces for AI agents, automation, and WordPress-native command surfaces.', 'dsa' ),
			'fallback'    => __( 'DSA REST controllers and deterministic admin reports remain available.', 'dsa' ),
			'abilities'   => array_values(
				array_filter(
					[
						'dsa/audit-trust',
						'dsa/summarize-route',
						'dsa/get-site-graph-data-schema',
						'dsa/query-site-graph-data',
						'dsa/get-securetrack-brief',
						'dsa/get-bricks-ai-context',
						'dsa/plan-bricks-ai-page',
						$this->site_graph ? 'dsa/get-site-graph' : '',
						$this->site_graph ? 'dsa/get-internal-ai-context' : '',
						$this->site_graph ? 'dsa/run-internal-ai-advisor' : '',
						$this->site_graph ? 'dsa/enrich-internal-ai-advisor' : '',
						$this->site_graph ? 'dsa/get-companion-context' : '',
						$this->site_graph ? 'dsa/ask-companion' : '',
						$this->site_graph ? 'dsa/review-ai-output' : '',
						$this->site_graph ? 'dsa/start-studio-project' : '',
						$this->site_graph ? 'dsa/review-studio-output' : '',
						$this->site_graph ? 'dsa/validate-bindings' : '',
						$this->site_graph ? 'dsa/prepare-apply-plan' : '',
						$this->site_graph ? 'dsa/stage-apply-plan' : '',
					]
				)
			),
		];
	}

	public function available(): bool {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	private function companion(): AI_Companion_Service {
		return new AI_Companion_Service(
			$this->settings,
			$this->site_graph ?: new Site_Graph_Service( $this->settings ),
			new AI_Companion_Memory_Service()
		);
	}

	private function studio(): Studio_AI_Service {
		return new Studio_AI_Service(
			$this->settings,
			$this->site_graph,
			$this->companion(),
			new AI_Provider_Service( $this->settings )
		);
	}

	private function securetrack_brief_allowed(): bool {
		$defaults = $this->settings->defaults()['ai'] ?? [];
		$current  = $this->settings->get( 'ai', [] );
		$ai       = array_replace_recursive( is_array( $defaults ) ? $defaults : [], is_array( $current ) ? $current : [] );

		return ! empty( $ai['securetrack_brief_enabled'] );
	}

	private function trust_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'version'           => [ 'type' => 'integer' ],
				'sslActive'         => [ 'type' => 'boolean' ],
				'sslProvider'       => [ 'type' => 'string' ],
				'phonekeyActive'    => [ 'type' => 'boolean' ],
				'securetrackActive' => [ 'type' => 'boolean' ],
				'paymentActive'     => [ 'type' => 'boolean' ],
				'paymentProviders'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'maxItems' => 8 ],
			],
			'required'   => [ 'version', 'sslActive', 'sslProvider', 'phonekeyActive', 'securetrackActive', 'paymentActive', 'paymentProviders' ],
		];
	}

	private function route_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'version'        => [ 'type' => 'integer' ],
				'route'          => [ 'type' => 'string', 'format' => 'uri' ],
				'postId'         => [ 'type' => 'integer', 'minimum' => 0 ],
				'elementCount'   => [ 'type' => 'integer', 'minimum' => 0 ],
				'registrySource' => [ 'type' => 'string' ],
				'types'          => [
					'type'     => 'array',
					'maxItems' => 24,
					'items'    => [
						'type'       => 'object',
						'properties' => [ 'type' => [ 'type' => 'string' ], 'count' => [ 'type' => 'integer', 'minimum' => 0 ] ],
						'required'   => [ 'type', 'count' ],
					],
				],
			],
			'required'   => [ 'version', 'route', 'postId', 'elementCount', 'registrySource', 'types' ],
		];
	}

	private function site_graph_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'sampleLimit' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 24,
					'description' => __( 'Maximum number of public sample posts/pages/terms per collection.', 'dsa' ),
				],
			],
		];
	}

	private function site_graph_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'schema'        => [ 'type' => 'string' ],
				'generatedAt'   => [ 'type' => 'string' ],
				'site'          => [ 'type' => 'object' ],
				'wordpress'     => [ 'type' => 'object' ],
				'woocommerce'   => [ 'type' => 'object' ],
				'bricks'        => [ 'type' => 'object' ],
				'kiwe'          => [ 'type' => 'object' ],
				'bindingTargets' => [ 'type' => 'object' ],
				'guardrails'    => [ 'type' => 'object' ],
			],
			'required'   => [ 'schema', 'generatedAt', 'site', 'wordpress', 'woocommerce', 'bricks', 'kiwe', 'bindingTargets', 'guardrails' ],
		];
	}

	private function site_graph_data_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'resource'   => [ 'type' => 'string' ],
				'type'       => [ 'type' => 'string' ],
				'postType'   => [ 'type' => 'string' ],
				'taxonomy'   => [ 'type' => 'string' ],
				'term'       => [ 'type' => [ 'string', 'array' ] ],
				'category'   => [ 'type' => [ 'string', 'array' ] ],
				'search'     => [ 'type' => 'string' ],
				'slug'       => [ 'type' => 'string' ],
				'limit'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ],
				'page'       => [ 'type' => 'integer', 'minimum' => 1 ],
				'fields'     => [ 'type' => [ 'string', 'array' ] ],
				'queries'    => [ 'type' => 'object' ],
				'publicOnly' => [ 'type' => 'boolean' ],
			],
		];
	}

	private function securetrack_brief_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 40 ],
			],
		];
	}

	private function internal_ai_context_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'sampleLimit' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 24 ],
				'secureLimit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 40 ],
			],
		];
	}

	private function internal_ai_advisor_input_schema(): array {
		$schema = $this->internal_ai_context_input_schema();
		$schema['properties']['focus'] = [
			'type' => 'string',
			'enum' => [ 'all', 'security', 'headless', 'wp7', 'staging', 'site_graph' ],
		];

		return $schema;
	}

	private function internal_ai_enrichment_input_schema(): array {
		$schema = $this->internal_ai_advisor_input_schema();
		$schema['properties']['style'] = [
			'type' => 'string',
			'enum' => [ 'executive', 'developer', 'security', 'handoff' ],
		];
		$schema['properties']['limit'] = [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ];

		return $schema;
	}

	private function generic_object_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
		];
	}

	private function binding_validation_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'binding'   => [
					'type'        => 'object',
					'description' => __( 'The kiwe.bricks-bindings.v1 object to validate.', 'dsa' ),
				],
				'siteGraph' => [
					'type'        => 'object',
					'description' => __( 'Optional kiwe.site-graph.v1 object. If omitted, Kiwe uses the current live Site Graph.', 'dsa' ),
				],
				'sampleLimit' => [
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 24,
				],
			],
			'required'   => [ 'binding' ],
		];
	}

	private function binding_validation_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'       => [ 'type' => 'boolean' ],
				'root'     => [ 'type' => 'string' ],
				'counts'   => [ 'type' => 'object' ],
				'findings' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
			'required'   => [ 'ok', 'counts', 'findings' ],
		];
	}

	private function prepare_apply_plan_input_schema(): array {
		return $this->binding_validation_input_schema();
	}

	private function apply_plan_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                => [ 'type' => 'boolean' ],
				'schema'            => [ 'type' => 'string' ],
				'plan'              => [ 'type' => 'object' ],
				'bindingValidation' => [ 'type' => 'object' ],
				'counts'            => [ 'type' => 'object' ],
				'findings'          => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
			'required'   => [ 'ok', 'schema', 'counts', 'findings' ],
		];
	}

	private function stage_apply_plan_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'applyPlan'           => [
					'type'        => 'object',
					'description' => __( 'A reviewed kiwe.bricks-apply-plan.v1 object.', 'dsa' ),
				],
				'siteName'            => [ 'type' => 'string' ],
				'fileName'            => [ 'type' => 'string' ],
				'bindingReportKey'    => [ 'type' => 'string' ],
				'abilityInvocationId' => [ 'type' => 'string' ],
			],
			'required'   => [ 'applyPlan' ],
		];
	}

	private function trusted_stage_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'schema'               => [ 'type' => 'string' ],
				'id'                   => [ 'type' => 'string' ],
				'status'               => [ 'type' => 'string' ],
				'createdAt'            => [ 'type' => 'string' ],
				'createdBy'            => [ 'type' => 'integer' ],
				'plan'                 => [ 'type' => 'object' ],
				'gates'                => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'blockers'             => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'mutatesWordPress'     => [ 'type' => 'boolean' ],
				'mutatesBricksContent' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'schema', 'status', 'blockers', 'mutatesWordPress', 'mutatesBricksContent' ],
		];
	}

	private function site_graph_from_input( array $input ): array {
		if ( isset( $input['siteGraph'] ) && is_array( $input['siteGraph'] ) && 'kiwe.site-graph.v1' === ( $input['siteGraph']['schema'] ?? '' ) ) {
			return $input['siteGraph'];
		}

		return $this->site_graph ? $this->site_graph->graph(
			[
				'sampleLimit' => isset( $input['sampleLimit'] ) ? absint( $input['sampleLimit'] ) : 8,
			]
		) : [ 'schema' => 'kiwe.site-graph.v1' ];
	}
}
