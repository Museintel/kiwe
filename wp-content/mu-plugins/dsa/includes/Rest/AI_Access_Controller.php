<?php

namespace DSA\Rest;

use DSA\AI\Access_Key_Service;
use DSA\AI\AI_Companion_Memory_Service;
use DSA\AI\AI_Companion_Service;
use DSA\AI\Bricks_AI_Intelligence_Service;
use DSA\AI\Apply_Plan_Preparer;
use DSA\AI\Binding_Plan_Validator;
use DSA\AI\Bricks_Conversion_Validator;
use DSA\AI\Bricks_Controlled_Adapter_Service;
use DSA\AI\Controlled_Executor_Service;
use DSA\AI\Final_Apply_Confirmation_Service;
use DSA\AI\Final_Save_Approval_Service;
use DSA\AI\Fresh_Site_Graph_Revalidator;
use DSA\AI\Guarded_Apply_Authorizer;
use DSA\AI\Internal_AI_Advisor_Service;
use DSA\AI\Internal_AI_Context_Service;
use DSA\AI\Internal_AI_Enrichment_Service;
use DSA\AI\Minimal_Adapter_Shell_Service;
use DSA\AI\Post_Apply_Verification_Service;
use DSA\AI\Pre_Execution_Gate_Service;
use DSA\AI\Rendered_Target_Inspection_Service;
use DSA\AI\Rollback_Capture_Service;
use DSA\AI\Rollback_Readiness_Checkpoint_Service;
use DSA\AI\AI_Provider_Service;
use DSA\AI\Site_Introspection_Service;
use DSA\AI\Site_Graph_Service;
use DSA\AI\Staging_Execution_Service;
use DSA\AI\Studio_AI_Service;
use DSA\AI\Target_Resolution_Service;
use DSA\AI\Trusted_Adapter_Proof_Service;
use DSA\AI\Trusted_Apply_Stager;
use DSA\AI\Trusted_Execution_Preview_Service;
use DSA\Secure\SecureTrack_AI_Brief_Service;
use DSA\Settings;
use DSA\Site_Graph\Data_Query_Service;
use DSA\Theme\Theme_Package_Service;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Access_Controller {
	public function __construct(
		private Site_Graph_Service $site_graph,
		private ?Settings $settings = null,
		private ?Access_Key_Service $keys = null
	) {
		$this->keys = $this->keys ?: new Access_Key_Service();
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$routes = [
			[ 'GET', '/ai/status', 'status', 'status' ],
			[ 'GET', '/ai/site-graph', 'site_graph', 'site_graph' ],
			[ 'GET', '/ai/site-graph-data/schema', 'site_graph_data_schema', 'site_graph_data' ],
			[ [ 'GET', 'POST' ], '/ai/site-graph-data', 'site_graph_data', 'site_graph_data' ],
			[ 'GET', '/ai/security-brief', 'security_brief', 'security_brief' ],
			[ 'GET', '/ai/internal-context', 'internal_context', 'internal_ai' ],
			[ [ 'GET', 'POST' ], '/ai/advisor', 'advisor', 'internal_ai' ],
			[ [ 'GET', 'POST' ], '/ai/advisor/enrich', 'advisor_enrichment', 'internal_ai' ],
			[ 'GET', '/ai/companion/status', 'companion_status', 'companion' ],
			[ [ 'GET', 'POST' ], '/ai/companion/context', 'companion_context', 'companion' ],
			[ 'POST', '/ai/companion/ask', 'companion_ask', 'companion' ],
			[ 'POST', '/ai/companion/review-output', 'companion_review_output', 'companion' ],
			[ [ 'GET', 'POST' ], '/ai/audit-companion/context', 'audit_companion_context', 'companion' ],
			[ 'POST', '/ai/audit-companion/review', 'audit_companion_review', 'companion' ],
			[ 'GET', '/ai/companion/memory', 'companion_memory', 'companion' ],
			[ 'POST', '/ai/companion/memory/clear', 'companion_memory_clear', 'companion' ],
			[ 'GET', '/ai/studio/status', 'studio_status', 'studio_ai' ],
			[ 'POST', '/ai/studio/start', 'studio_start', 'studio_ai' ],
			[ 'POST', '/ai/studio/draft', 'studio_draft', 'studio_ai' ],
			[ 'POST', '/ai/studio/review', 'studio_review', 'studio_ai' ],
			[ [ 'GET', 'POST' ], '/ai/bricks/context', 'bricks_ai_context', 'bricks_ai' ],
			[ 'POST', '/ai/bricks/plan', 'bricks_ai_plan', 'bricks_ai' ],
			[ 'POST', '/ai/validate-bindings', 'validate_bindings', 'validate_bindings' ],
			[ 'POST', '/ai/validate-bricks-conversion', 'validate_bricks_conversion', 'validate_bricks_conversion' ],
			[ 'POST', '/ai/prepare-apply-plan', 'prepare_apply_plan', 'prepare_apply_plan' ],
			[ 'POST', '/ai/stage-apply-plan', 'stage_apply_plan', 'stage_apply_plan' ],
			[ 'GET', '/ai/stages', 'stages', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/prove-adapter', 'prove_adapter', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/authorize', 'authorize_stage', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/pre-execution-gate', 'pre_execution_gate', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/execution-preview', 'execution_preview', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/final-confirmation', 'final_confirmation', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/fresh-site-graph', 'fresh_site_graph', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/rollback-readiness', 'rollback_readiness', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/resolve-target', 'resolve_target', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/capture-rollback', 'capture_rollback', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/inspect-target', 'inspect_target', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/adapter-shell', 'adapter_shell', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/final-save-approval', 'final_save_approval', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/controlled-executor', 'controlled_executor', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/bricks-adapter-plan', 'bricks_adapter_plan', 'trusted_apply_chain' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/post-apply-verification', 'post_apply_verification', 'trusted_apply_chain' ],
			[ 'GET', '/ai/themes', 'themes', 'themes' ],
			[ 'POST', '/ai/themes/install', 'install_theme', 'themes' ],
			[ 'POST', '/ai/themes/(?P<themeId>[a-zA-Z0-9._-]+)/activate', 'activate_theme', 'themes' ],
			[ 'GET', '/ai/site-inspection', 'site_inspection', 'site_inspection' ],
			[ 'POST', '/ai/staging/execute', 'execute_staging', 'staging_execute' ],
			[ 'POST', '/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/execute-staging', 'execute_stage_staging', 'staging_execute' ],
			[ 'POST', '/ai/mutations/bricks-page-save', 'locked_mutation', 'controlled_mutation' ],
			[ 'POST', '/ai/mutations/wordpress-publish', 'locked_mutation', 'controlled_mutation' ],
			[ 'POST', '/ai/mutations/woocommerce', 'locked_mutation', 'controlled_mutation' ],
			[ 'POST', '/ai/runtime/cart', 'locked_runtime', 'controlled_mutation' ],
			[ 'POST', '/ai/runtime/checkout', 'locked_runtime', 'controlled_mutation' ],
			[ 'POST', '/ai/runtime/auth', 'locked_runtime', 'controlled_mutation' ],
		];

		foreach ( $routes as [ $method, $route, $callback, $scope ] ) {
			register_rest_route(
				'dsa/v1',
				$route,
				[
					'methods'             => $method,
					'callback'            => fn( WP_REST_Request $request ) => $this->guarded( $request, $scope, $callback ),
					'permission_callback' => '__return_true',
				]
			);
		}
	}

	public function guarded( WP_REST_Request $request, string $scope, string $callback ): WP_REST_Response {
		$auth = $this->keys->authenticate_request( $request, $scope );
		if ( empty( $auth['ok'] ) ) {
			return $this->response( [ 'ok' => false, 'error' => $auth ], (int) ( $auth['status'] ?? 401 ) );
		}
		$result = $this->{$callback}( $request, $auth );
		$status = isset( $result['httpStatus'] ) ? max( 100, min( 599, (int) $result['httpStatus'] ) ) : 200;
		unset( $result['httpStatus'] );

		return $this->response( $result, $status );
	}

	private function status( WP_REST_Request $request, array $auth ): array {
		return [
			'ok'         => true,
			'schema'     => 'kiwe.ai-access-status.v1',
			'key'        => $auth['record'],
			'capability' => [
				'siteGraph'         => true,
				'siteGraphData'     => true,
				'securityBrief'     => $this->securetrack_brief_allowed( $auth ) ? true : 'requires Kiwe > AI SecureTrack sharing plus security_brief or companion_securetrack scope',
				'internalAiContext' => true,
				'internalAiAdvisor' => true,
				'internalAiEnrichment' => 'model-optional-readonly',
				'companion'         => [
					'enabled' => ! empty( $this->ai_settings()['companion_enabled'] ),
					'route'   => '/wp-json/dsa/v1/ai/companion/context',
					'auditRoute' => '/wp-json/dsa/v1/ai/audit-companion/review',
					'model'   => 'deterministic-context-broker-no-model-call',
				],
				'studioAi'          => [
					'enabled'       => ! empty( $this->ai_settings()['studio_enabled'] ),
					'route'         => '/wp-json/dsa/v1/ai/studio/start',
					'operatingMode' => sanitize_key( (string) ( $this->ai_settings()['studio_mode'] ?? 'browser_companion' ) ),
					'nativeDraft'   => $this->auth_has_scope( $auth, 'native_ai' ) ? 'allowed-if-enabled-in-kiwe-ai' : 'requires-native_ai-scope',
				],
				'bricksAi'          => [
					'route'       => '/wp-json/dsa/v1/ai/bricks/context',
					'planRoute'   => '/wp-json/dsa/v1/ai/bricks/plan',
					'scope'       => 'bricks_ai or studio_ai',
					'covers'      => [ 'elements', 'elementControls', 'queryLoops', 'dynamicTags', 'conditions', 'interactions', 'Seam rules', 'Kiwe launchers' ],
					'readOnly'    => true,
				],
				'validateBindings'  => true,
				'validateBricksConversion' => true,
				'prepareApplyPlan'  => true,
				'stageApplyPlan'    => true,
				'trustedApplyChain' => true,
				'themes'            => true,
				'siteInspection'    => true,
				'stagingExecution'  => 'explicit-confirmation-required',
				'mutatesContent'    => 'staging-only-with-explicit-confirmation',
				'controlledMutationIntents' => [
					'bricksPageSave'       => 'available-through-staging-executor-with-confirmRawBricksJsonWrite',
					'wordpressPublish'     => 'available-through-staging-executor-with-publishOnStaging',
					'woocommerceMutation'  => 'available-through-staging-executor-with-confirmWooCommerceMutation',
					'checkoutCartAuthRun'  => 'available-through-staging-executor-with-confirmRuntimeExecution',
				],
			],
		];
	}

	private function site_graph( WP_REST_Request $request, array $auth ): array {
		return $this->site_graph->graph( [ 'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ?: 8 ) ] );
	}

	private function site_graph_data_schema( WP_REST_Request $request, array $auth ): array {
		return ( new Data_Query_Service() )->schema();
	}

	private function site_graph_data( WP_REST_Request $request, array $auth ): array {
		$args = $request->get_params();
		$body = $request->get_json_params();
		if ( is_array( $body ) ) {
			$args = array_replace_recursive( $args, $body );
		}
		unset( $args['rest_route'] );

		$private = empty( $args['publicOnly'] );
		unset( $args['publicOnly'] );

		return ( new Data_Query_Service() )->query( $args, $private );
	}

	private function security_brief( WP_REST_Request $request, array $auth ): array {
		if ( ! $this->securetrack_brief_allowed( $auth ) ) {
			return [
				'ok'         => false,
				'httpStatus' => 403,
				'schema'     => 'kiwe.securetrack-ai-brief.v1',
				'error'      => [
					'code'    => 'securetrack_brief_not_allowed',
					'message' => 'Enable SecureTrack brief sharing in Kiwe > AI and use a key scoped to security_brief, companion_securetrack, or all.',
				],
			];
		}

		return ( new SecureTrack_AI_Brief_Service() )->brief( absint( $request->get_param( 'limit' ) ?: 12 ) );
	}

	private function internal_context( WP_REST_Request $request, array $auth ): array {
		return ( new Internal_AI_Context_Service( $this->site_graph ) )->context(
			[
				'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ?: 8 ),
				'secureLimit' => absint( $request->get_param( 'secureLimit' ) ?: 12 ),
				'includeSecureTrack' => $this->securetrack_brief_allowed( $auth ),
			]
		);
	}

	private function advisor( WP_REST_Request $request, array $auth ): array {
		$args = $request->get_params();
		$body = $request->get_json_params();
		if ( is_array( $body ) ) {
			$args = array_replace_recursive( $args, $body );
		}
		unset( $args['rest_route'] );

		$args['includeSecureTrack'] = $this->securetrack_brief_allowed( $auth );

		return ( new Internal_AI_Advisor_Service( new Internal_AI_Context_Service( $this->site_graph ) ) )->advise( $args );
	}

	private function advisor_enrichment( WP_REST_Request $request, array $auth ): array {
		$args = $request->get_params();
		$body = $request->get_json_params();
		if ( is_array( $body ) ) {
			$args = array_replace_recursive( $args, $body );
		}
		unset( $args['rest_route'] );

		$args['includeSecureTrack'] = $this->securetrack_brief_allowed( $auth );

		return ( new Internal_AI_Enrichment_Service( new Internal_AI_Advisor_Service( new Internal_AI_Context_Service( $this->site_graph ) ) ) )->enrich( $args );
	}

	private function companion_status( WP_REST_Request $request, array $auth ): array {
		return $this->companion()->status( $auth );
	}

	private function companion_context( WP_REST_Request $request, array $auth ): array {
		return $this->companion()->context( $this->merged_request_args( $request ), $auth );
	}

	private function companion_ask( WP_REST_Request $request, array $auth ): array {
		return $this->companion()->ask( $this->merged_request_args( $request ), $auth );
	}

	private function companion_review_output( WP_REST_Request $request, array $auth ): array {
		return $this->companion()->review_output( $this->merged_request_args( $request ), $auth );
	}

	private function audit_companion_context( WP_REST_Request $request, array $auth ): array {
		return $this->companion()->audit_context( $this->merged_request_args( $request ), $auth );
	}

	private function audit_companion_review( WP_REST_Request $request, array $auth ): array {
		return $this->companion()->audit_review( $this->merged_request_args( $request ), $auth );
	}

	private function companion_memory( WP_REST_Request $request, array $auth ): array {
		return $this->companion()->memory();
	}

	private function companion_memory_clear( WP_REST_Request $request, array $auth ): array {
		return $this->companion()->clear_memory();
	}

	private function studio_status( WP_REST_Request $request, array $auth ): array {
		return $this->studio()->status( $auth );
	}

	private function studio_start( WP_REST_Request $request, array $auth ): array {
		return $this->studio()->start_project( $this->merged_request_args( $request ), $auth );
	}

	private function studio_draft( WP_REST_Request $request, array $auth ): array {
		return $this->studio()->draft( $this->merged_request_args( $request ), $auth );
	}

	private function studio_review( WP_REST_Request $request, array $auth ): array {
		return $this->studio()->review( $this->merged_request_args( $request ), $auth );
	}

	private function bricks_ai_context( WP_REST_Request $request, array $auth ): array {
		return ( new Bricks_AI_Intelligence_Service( $this->settings_service() ) )->context( $this->merged_request_args( $request ) );
	}

	private function bricks_ai_plan( WP_REST_Request $request, array $auth ): array {
		return ( new Bricks_AI_Intelligence_Service( $this->settings_service() ) )->planning_packet( $this->merged_request_args( $request ) );
	}

	private function validate_bindings( WP_REST_Request $request, array $auth ): array {
		$binding = $this->array_param( $request, 'binding' );
		if ( [] === $binding ) {
			return $this->bad_request( 'missing_binding', 'Request body must include binding.' );
		}
		$site_graph = $this->site_graph_from_request( $request );

		return ( new Binding_Plan_Validator() )->validate( $binding, $site_graph );
	}

	private function validate_bricks_conversion( WP_REST_Request $request, array $auth ): array {
		$conversion = $this->array_param( $request, 'conversion' );
		if ( [] === $conversion ) {
			return $this->bad_request( 'missing_conversion', 'Request body must include conversion.' );
		}
		$site_graph  = $this->site_graph_from_request( $request );
		$source_html = (string) ( $request->get_param( 'sourceHtml' ) ?? $request->get_param( 'sourceHTML' ) ?? '' );
		$binding     = $this->array_param( $request, 'binding' );

		return ( new Bricks_Conversion_Validator() )->validate( $conversion, $site_graph, $source_html, $binding );
	}

	private function prepare_apply_plan( WP_REST_Request $request, array $auth ): array {
		$binding = $this->array_param( $request, 'binding' );
		if ( [] === $binding ) {
			return $this->bad_request( 'missing_binding', 'Request body must include binding.' );
		}
		$site_graph = $this->site_graph_from_request( $request );
		$report     = ( new Binding_Plan_Validator() )->validate( $binding, $site_graph );
		if ( empty( $report['ok'] ) ) {
			return [
				'ok'                => false,
				'schema'            => 'kiwe.apply-plan-result.v1',
				'plan'              => [],
				'bindingValidation' => $report,
			];
		}

		return [
			'ok'                => true,
			'schema'            => 'kiwe.apply-plan-result.v1',
			'plan'              => ( new Apply_Plan_Preparer() )->prepare( $binding, $site_graph, $report ),
			'bindingValidation' => $report,
		];
	}

	private function stage_apply_plan( WP_REST_Request $request, array $auth ): array {
		$apply_plan = $this->array_param( $request, 'applyPlan' );
		if ( [] === $apply_plan ) {
			return $this->bad_request( 'missing_apply_plan', 'Request body must include applyPlan.' );
		}

		return ( new Trusted_Apply_Stager() )->stage(
			$apply_plan,
			[
				'userId'              => 0,
				'createdAt'           => gmdate( 'c' ),
				'siteName'            => sanitize_text_field( (string) ( $request->get_param( 'siteName' ) ?? '' ) ),
				'fileName'            => sanitize_file_name( (string) ( $request->get_param( 'fileName' ) ?? '' ) ),
				'bindingReportKey'    => sanitize_key( (string) ( $request->get_param( 'bindingReportKey' ) ?? '' ) ),
				'abilityInvocationId' => sanitize_key( (string) ( $request->get_param( 'abilityInvocationId' ) ?? '' ) ),
				'apiKeyId'            => (string) ( $auth['record']['id'] ?? '' ),
			]
		);
	}

	private function stages( WP_REST_Request $request, array $auth ): array {
		return [
			'ok'      => true,
			'schema'  => 'kiwe.ai-stages.v1',
			'records' => ( new Trusted_Apply_Stager() )->records(),
		];
	}

	private function prove_adapter( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Trusted_Adapter_Proof_Service() )->prove( $stage, $this->site_graph->graph( [ 'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ?: 8 ) ] ) ),
			'attach_proof',
			'adapter-proof-ready'
		);
	}

	private function authorize_stage( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Guarded_Apply_Authorizer() )->authorize( $stage, $this->context( $auth ) ),
			'attach_authorization',
			'authorized-for-future-adapter'
		);
	}

	private function pre_execution_gate( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Pre_Execution_Gate_Service() )->evaluate( $stage, $this->context( $auth ) ),
			'attach_execution_gate',
			'ready-for-final-executor-build'
		);
	}

	private function execution_preview( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Trusted_Execution_Preview_Service() )->preview( $stage, $this->context( $auth ) ),
			'attach_execution_preview',
			'ready-for-final-confirmation'
		);
	}

	private function final_confirmation( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Final_Apply_Confirmation_Service() )->confirm( $stage, array_merge( $this->context( $auth ), [ 'explicitAdminConfirmation' => ! empty( $request->get_param( 'confirmExactPreview' ) ) ] ) ),
			'attach_final_confirmation',
			'confirmed-for-future-adapter'
		);
	}

	private function fresh_site_graph( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Fresh_Site_Graph_Revalidator() )->revalidate( $stage, $this->site_graph->graph( [ 'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ?: 12 ) ] ), $this->context( $auth ) ),
			'attach_fresh_sitegraph_revalidation',
			'fresh-sitegraph-ready'
		);
	}

	private function rollback_readiness( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Rollback_Readiness_Checkpoint_Service() )->checkpoint( $stage, $this->context( $auth ) ),
			'attach_rollback_readiness_checkpoint',
			'rollback-readiness-ready'
		);
	}

	private function resolve_target( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Target_Resolution_Service() )->resolve( $stage, array_merge( $this->context( $auth ), [ 'targetPostId' => absint( $request->get_param( 'targetPostId' ) ) ] ) ),
			'attach_target_resolution',
			'target-resolution-ready'
		);
	}

	private function capture_rollback( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Rollback_Capture_Service() )->capture( $stage, $this->context( $auth ) ),
			'attach_rollback_capture',
			'rollback-capture-ready'
		);
	}

	private function inspect_target( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Rendered_Target_Inspection_Service() )->inspect( $stage, $this->context( $auth ) ),
			'attach_rendered_target_inspection',
			'rendered-target-inspection-ready'
		);
	}

	private function adapter_shell( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Minimal_Adapter_Shell_Service() )->build( $stage, $this->context( $auth ) ),
			'attach_minimal_adapter_shell',
			'minimal-adapter-shell-ready'
		);
	}

	private function final_save_approval( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Final_Save_Approval_Service() )->approve( $stage, array_merge( $this->context( $auth ), [ 'explicitFinalSaveApproval' => ! empty( $request->get_param( 'explicitFinalSaveApproval' ) ) ] ) ),
			'attach_final_save_approval',
			'final-save-approved'
		);
	}

	private function controlled_executor( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Controlled_Executor_Service() )->build( $stage, $this->context( $auth ) ),
			'attach_controlled_executor',
			'controlled-executor-skeleton-ready'
		);
	}

	private function bricks_adapter_plan( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Bricks_Controlled_Adapter_Service() )->prepare( $stage, $this->context( $auth ) ),
			'attach_bricks_controlled_adapter',
			'bricks-controlled-adapter-ready'
		);
	}

	private function post_apply_verification( WP_REST_Request $request, array $auth ): array {
		return $this->attach_stage_artifact(
			$request,
			fn( array $stage ): array => ( new Post_Apply_Verification_Service() )->build( $stage, $this->context( $auth ) ),
			'attach_post_apply_verification',
			'post-apply-verification-ready'
		);
	}

	private function themes( WP_REST_Request $request, array $auth ): array {
		$service = new Theme_Package_Service();
		$settings = $this->settings ? $this->settings->all() : [];
		$active = $service->active( $settings );
		$active_id = (string) ( $active['id'] ?? '' );
		$records = array_map(
			static function ( array $record ) use ( $service, $active_id ): array {
				$public = $service->public_record( $record );
				$public['active'] = $active_id !== '' && $active_id === (string) ( $record['id'] ?? '' );
				return $public;
			},
			$service->all()
		);

		return [
			'ok'      => true,
			'schema'  => 'kiwe.ai-themes.v1',
			'active'  => $service->public_record( $active ),
			'records' => $records,
		];
	}

	private function install_theme( WP_REST_Request $request, array $auth ): array {
		$package = $this->array_param( $request, 'package' );
		if ( [] === $package ) {
			$package = $request->get_json_params();
			$package = is_array( $package ) ? $package : [];
		}
		if ( [] === $package ) {
			return $this->bad_request( 'missing_theme_package', 'Request body must include a Kiwe theme package.' );
		}
		$result = ( new Theme_Package_Service() )->install(
			$package,
			[
				'userId'    => 0,
				'createdAt' => gmdate( 'c' ),
				'apiKeyId'  => (string) ( $auth['record']['id'] ?? '' ),
			]
		);
		if ( empty( $result['ok'] ) ) {
			return [
				'ok'         => false,
				'httpStatus' => 400,
				'error'      => $result,
			];
		}

		return [
			'ok'     => true,
			'schema' => 'kiwe.ai-theme-install-result.v1',
			'record' => $result['record'],
		];
	}

	private function activate_theme( WP_REST_Request $request, array $auth ): array {
		if ( ! $this->settings ) {
			return $this->bad_request( 'settings_unavailable', 'Kiwe settings service is unavailable.' );
		}
		$service  = new Theme_Package_Service();
		$theme_id = $service->sanitize_id( (string) $request->get_param( 'themeId' ) );
		$record   = $service->find( $theme_id );
		if ( [] === $record ) {
			return $this->not_found( 'theme_not_found', 'Theme was not found.' );
		}

		$this->settings->update( $service->safe_settings_overlay( $record, $this->settings->all() ) );

		return [
			'ok'     => true,
			'schema' => 'kiwe.ai-theme-activation-result.v1',
			'active' => $service->public_record( $record ),
		];
	}

	private function site_inspection( WP_REST_Request $request, array $auth ): array {
		return [
			'ok'         => true,
			'inspection' => ( new Site_Introspection_Service() )->inspect( [ 'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ?: 12 ) ] ),
		];
	}

	private function execute_staging( WP_REST_Request $request, array $auth ): array {
		$payload = $this->array_param( $request, 'execution' );
		if ( [] === $payload ) {
			$payload = $request->get_json_params();
			$payload = is_array( $payload ) ? $payload : [];
		}
		if ( [] === $payload ) {
			return $this->bad_request( 'missing_execution', 'Request body must include a staging execution package.' );
		}

		$context       = $this->context( $auth );
		$previous_user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$owner_user    = absint( $context['userId'] ?? 0 );

		if ( $owner_user > 0 && function_exists( 'wp_set_current_user' ) && function_exists( 'user_can' ) && user_can( $owner_user, 'manage_options' ) ) {
			wp_set_current_user( $owner_user );
		}

		try {
			$result = ( new Staging_Execution_Service( $this->settings ) )->execute( $payload, $context );
		} finally {
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( absint( $previous_user ) );
			}
		}

		return [
			'ok'      => 'staging-execution-complete' === (string) ( $result['status'] ?? '' ),
			'schema'  => 'kiwe.ai-staging-execution-result.v1',
			'result'  => $result,
		];
	}

	private function execute_stage_staging( WP_REST_Request $request, array $auth ): array {
		$stage_id = sanitize_key( (string) $request->get_param( 'stageId' ) );
		$stager   = new Trusted_Apply_Stager();
		$stage    = $stager->find( $stage_id );
		if ( [] === $stage ) {
			return $this->not_found( 'stage_not_found', 'Apply stage was not found.' );
		}
		$payload = $this->array_param( $request, 'execution' );
		if ( [] === $payload ) {
			$payload = $request->get_json_params();
			$payload = is_array( $payload ) ? $payload : [];
		}
		if ( [] === $payload ) {
			return $this->bad_request( 'missing_execution', 'Request body must include a staging execution package.' );
		}

		$result  = ( new Staging_Execution_Service( $this->settings ) )->execute( $payload, $this->context( $auth ), $stage );
		$updated = $stager->attach_staging_execution( $stage_id, $result );

		return [
			'ok'      => 'staging-execution-complete' === (string) ( $result['status'] ?? '' ),
			'schema'  => 'kiwe.ai-stage-staging-execution-result.v1',
			'result'  => $result,
			'stage'   => $updated,
		];
	}

	private function locked_mutation( WP_REST_Request $request, array $auth ): array {
		$payload = $this->controlled_route_payload( $request, $this->default_operation_for_route( $request->get_route() ) );
		if ( ! empty( $payload['confirmControlledStagingExecution'] ) ) {
			$result = ( new Staging_Execution_Service( $this->settings ) )->execute( $payload, $this->context( $auth ) );

			return [
				'ok'      => 'staging-execution-complete' === (string) ( $result['status'] ?? '' ),
				'schema'  => 'kiwe.ai-controlled-mutation-result.v1',
				'route'   => $request->get_route(),
				'result'  => $result,
			];
		}

		return [
			'ok'         => false,
			'httpStatus' => 423,
			'schema'     => 'kiwe.ai-controlled-mutation-confirmation-required.v1',
			'route'      => $request->get_route(),
			'message'    => 'Controlled mutation is available only through the staging executor body with explicit confirmation flags. Send confirmControlledStagingExecution, stagingSiteConfirmed, and the operation-specific confirmation required by the operation type.',
			'canMutateNow' => false,
			'stagingExecutorRoute' => '/wp-json/dsa/v1/ai/staging/execute',
			'defaultOperationType' => $this->default_operation_for_route( $request->get_route() ),
			'requiredBeforeUnlock' => [
				'confirmControlledStagingExecution',
				'stagingSiteConfirmed',
				'confirmWooCommerceMutation for WooCommerce/order/checkout mutations',
				'confirmRuntimeExecution for cart/checkout/auth runtime harnesses',
				'confirmRawBricksJsonWrite for raw Bricks meta writes',
			],
		];
	}

	private function locked_runtime( WP_REST_Request $request, array $auth ): array {
		$payload = $this->controlled_route_payload( $request, $this->default_operation_for_route( $request->get_route() ) );
		if ( ! empty( $payload['confirmControlledStagingExecution'] ) ) {
			$result = ( new Staging_Execution_Service( $this->settings ) )->execute( $payload, $this->context( $auth ) );

			return [
				'ok'      => 'staging-execution-complete' === (string) ( $result['status'] ?? '' ),
				'schema'  => 'kiwe.ai-controlled-runtime-result.v1',
				'route'   => $request->get_route(),
				'result'  => $result,
			];
		}

		return [
			'ok'         => false,
			'httpStatus' => 423,
			'schema'     => 'kiwe.ai-runtime-confirmation-required.v1',
			'route'      => $request->get_route(),
			'message'    => 'Cart, checkout, and authentication harnesses require the staging executor body with explicit confirmation. The API does not impersonate a shopper silently.',
			'canRunNow'  => false,
			'stagingExecutorRoute' => '/wp-json/dsa/v1/ai/staging/execute',
			'defaultOperationType' => $this->default_operation_for_route( $request->get_route() ),
			'allowedUse' => 'Run staging-only cart snapshots/adds, checkout validation/pending order creation, and test-user auth probes/creation/deletion when the request carries explicit confirmation flags.',
		];
	}

	private function controlled_route_payload( WP_REST_Request $request, string $default_type ): array {
		$payload = $this->array_param( $request, 'execution' );
		if ( [] === $payload ) {
			$payload = $request->get_json_params();
			$payload = is_array( $payload ) ? $payload : [];
		}
		if ( [] === $payload ) {
			return [];
		}
		if ( empty( $payload['operations'] ) || ! is_array( $payload['operations'] ) ) {
			$operation = $payload;
			unset(
				$operation['confirmControlledStagingExecution'],
				$operation['stagingSiteConfirmed'],
				$operation['allowCurrentHostAsStaging'],
				$operation['confirmWooCommerceMutation'],
				$operation['confirmRuntimeExecution'],
				$operation['confirmAuthRuntime'],
				$operation['confirmRawBricksJsonWrite']
			);
			$operation['type'] = sanitize_text_field( (string) ( $operation['type'] ?? $default_type ) );
			$payload['operations'] = [ $operation ];
		}

		return $payload;
	}

	private function default_operation_for_route( string $route ): string {
		if ( str_contains( $route, '/mutations/woocommerce' ) ) {
			return 'woocommerce.mutate';
		}
		if ( str_contains( $route, '/mutations/bricks-page-save' ) ) {
			return 'bricks.raw-meta-write';
		}
		if ( str_contains( $route, '/mutations/wordpress-publish' ) ) {
			return 'wordpress.page.upsert';
		}
		if ( str_contains( $route, '/runtime/cart' ) ) {
			return 'cart.run';
		}
		if ( str_contains( $route, '/runtime/checkout' ) ) {
			return 'checkout.run';
		}
		if ( str_contains( $route, '/runtime/auth' ) ) {
			return 'auth.run';
		}

		return '';
	}

	private function companion(): AI_Companion_Service {
		return new AI_Companion_Service(
			$this->settings_service(),
			$this->site_graph,
			new AI_Companion_Memory_Service()
		);
	}

	private function studio(): Studio_AI_Service {
		return new Studio_AI_Service(
			$this->settings_service(),
			$this->site_graph,
			$this->companion(),
			new AI_Provider_Service( $this->settings_service() )
		);
	}

	private function settings_service(): Settings {
		if ( ! $this->settings ) {
			$this->settings = new Settings();
		}

		return $this->settings;
	}

	private function ai_settings(): array {
		$service  = $this->settings_service();
		$defaults = $service->defaults()['ai'] ?? [];
		$current  = $service->get( 'ai', [] );

		return array_replace_recursive( is_array( $defaults ) ? $defaults : [], is_array( $current ) ? $current : [] );
	}

	private function securetrack_brief_allowed( array $auth ): bool {
		$ai = $this->ai_settings();
		if ( empty( $ai['securetrack_brief_enabled'] ) ) {
			return false;
		}

		return $this->auth_has_scope( $auth, 'security_brief' ) || $this->auth_has_scope( $auth, 'companion_securetrack' );
	}

	private function auth_has_scope( array $auth, string $scope ): bool {
		$record = isset( $auth['record'] ) && is_array( $auth['record'] ) ? $auth['record'] : [];
		$scopes = isset( $record['scopes'] ) && is_array( $record['scopes'] ) ? array_map( 'sanitize_key', $record['scopes'] ) : [];

		return in_array( 'all', $scopes, true ) || in_array( sanitize_key( $scope ), $scopes, true );
	}

	private function merged_request_args( WP_REST_Request $request ): array {
		$args = $request->get_params();
		$body = $request->get_json_params();
		if ( is_array( $body ) ) {
			$args = array_replace_recursive( $args, $body );
		}
		unset( $args['rest_route'] );

		return $args;
	}

	private function attach_stage_artifact( WP_REST_Request $request, callable $builder, string $attach_method, string $ready_status ): array {
		$stager   = new Trusted_Apply_Stager();
		$stage_id = sanitize_key( (string) $request->get_param( 'stageId' ) );
		$stage    = $stager->find( $stage_id );
		if ( [] === $stage ) {
			return $this->not_found( 'stage_not_found', 'Apply stage was not found.' );
		}
		$artifact = $builder( $stage );
		$updated  = is_callable( [ $stager, $attach_method ] ) ? $stager->{$attach_method}( $stage_id, $artifact ) : [];

		return [
			'ok'       => $ready_status === (string) ( $artifact['status'] ?? '' ),
			'schema'   => 'kiwe.ai-stage-artifact-result.v1',
			'artifact' => $artifact,
			'stage'    => $updated,
		];
	}

	private function context( array $auth ): array {
		$created_by = absint( $auth['record']['createdBy'] ?? 0 );

		return [
			'userId'    => $created_by,
			'createdAt' => gmdate( 'c' ),
			'apiKeyId'  => (string) ( $auth['record']['id'] ?? '' ),
		];
	}

	private function site_graph_from_request( WP_REST_Request $request ): array {
		$site_graph = $this->array_param( $request, 'siteGraph' );
		if ( [] !== $site_graph ) {
			return $site_graph;
		}

		return $this->site_graph->graph( [ 'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ?: 8 ) ] );
	}

	private function array_param( WP_REST_Request $request, string $key ): array {
		$value = $request->get_param( $key );

		return is_array( $value ) ? $value : [];
	}

	private function bad_request( string $code, string $message ): array {
		return [
			'ok'         => false,
			'httpStatus' => 400,
			'error'      => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}

	private function not_found( string $code, string $message ): array {
		return [
			'ok'         => false,
			'httpStatus' => 404,
			'error'      => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}

	private function response( array $payload, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $payload, $status );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}
}
