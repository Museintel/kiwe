<?php

namespace DSA\WP7;

use DSA\AI\Apply_Plan_Preparer;
use DSA\AI\Binding_Plan_Validator;
use DSA\AI\Site_Graph_Service;
use DSA\AI\Trusted_Apply_Stager;
use DSA\Element_Registry;
use DSA\Settings;
use DSA\Trust\Trust_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Abilities_Service {
	private const CATEGORY = 'kiwe-appsite';

	public function __construct(
		private Settings $settings,
		private Element_Registry $registry,
		private Trust_Service $trust,
		private ?Site_Graph_Service $site_graph = null
	) {}

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
						$this->site_graph ? 'dsa/get-site-graph' : '',
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
