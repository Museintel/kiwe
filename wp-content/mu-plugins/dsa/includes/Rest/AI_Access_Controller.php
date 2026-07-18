<?php

namespace DSA\Rest;

use DSA\AI\Access_Key_Service;
use DSA\AI\Apply_Plan_Preparer;
use DSA\AI\Binding_Plan_Validator;
use DSA\AI\Bricks_Controlled_Adapter_Service;
use DSA\AI\Controlled_Executor_Service;
use DSA\AI\Final_Apply_Confirmation_Service;
use DSA\AI\Final_Save_Approval_Service;
use DSA\AI\Fresh_Site_Graph_Revalidator;
use DSA\AI\Guarded_Apply_Authorizer;
use DSA\AI\Minimal_Adapter_Shell_Service;
use DSA\AI\Post_Apply_Verification_Service;
use DSA\AI\Pre_Execution_Gate_Service;
use DSA\AI\Rendered_Target_Inspection_Service;
use DSA\AI\Rollback_Capture_Service;
use DSA\AI\Rollback_Readiness_Checkpoint_Service;
use DSA\AI\Site_Graph_Service;
use DSA\AI\Target_Resolution_Service;
use DSA\AI\Trusted_Adapter_Proof_Service;
use DSA\AI\Trusted_Apply_Stager;
use DSA\AI\Trusted_Execution_Preview_Service;
use DSA\Settings;
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
			[ 'POST', '/ai/validate-bindings', 'validate_bindings', 'validate_bindings' ],
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
				'validateBindings'  => true,
				'prepareApplyPlan'  => true,
				'stageApplyPlan'    => true,
				'trustedApplyChain' => true,
				'themes'            => true,
				'mutatesContent'    => false,
				'controlledMutationIntents' => [
					'bricksPageSave'       => 'locked-behind-future-controlled-executor',
					'wordpressPublish'     => 'locked-behind-future-controlled-executor',
					'woocommerceMutation'  => 'locked-behind-future-controlled-executor',
					'checkoutCartAuthRun'  => 'runtime-owned-not-direct-ai-api',
				],
			],
		];
	}

	private function site_graph( WP_REST_Request $request, array $auth ): array {
		return $this->site_graph->graph( [ 'sampleLimit' => absint( $request->get_param( 'sampleLimit' ) ?: 8 ) ] );
	}

	private function validate_bindings( WP_REST_Request $request, array $auth ): array {
		$binding = $this->array_param( $request, 'binding' );
		if ( [] === $binding ) {
			return $this->bad_request( 'missing_binding', 'Request body must include binding.' );
		}
		$site_graph = $this->site_graph_from_request( $request );

		return ( new Binding_Plan_Validator() )->validate( $binding, $site_graph );
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

		return [
			'ok'      => true,
			'schema'  => 'kiwe.ai-themes.v1',
			'active'  => $service->public_record( $active ),
			'records' => array_map( [ $service, 'public_record' ], $service->all() ),
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

	private function locked_mutation( WP_REST_Request $request, array $auth ): array {
		return [
			'ok'         => false,
			'httpStatus' => 423,
			'schema'     => 'kiwe.ai-controlled-mutation-locked.v1',
			'route'      => $request->get_route(),
			'message'    => 'Direct AI mutation is intentionally locked. Use the trusted apply chain, target resolution, rollback capture, rendered inspection, final save approval, controlled executor, adapter plan, and post-apply verification before any real mutation adapter may be enabled.',
			'canMutateNow' => false,
			'requiredBeforeUnlock' => [
				'validated-bindings',
				'staged-apply-plan',
				'adapter-proof',
				'guarded-authorization',
				'pre-execution-gate',
				'execution-preview',
				'final-confirmation',
				'fresh-site-graph',
				'rollback-readiness',
				'target-resolution',
				'rollback-capture',
				'rendered-target-inspection',
				'minimal-adapter-shell',
				'final-save-approval',
				'controlled-executor',
				'bricks-adapter-plan',
				'post-apply-verification',
				'staging-site-human-run',
			],
		];
	}

	private function locked_runtime( WP_REST_Request $request, array $auth ): array {
		return [
			'ok'         => false,
			'httpStatus' => 423,
			'schema'     => 'kiwe.ai-runtime-authority-locked.v1',
			'route'      => $request->get_route(),
			'message'    => 'Cart, checkout, and authentication flows are runtime-owned by Kiwe, WooCommerce, WordPress, and PhoneKey. External AI keys cannot run or impersonate visitor/customer runtime actions.',
			'canRunNow'  => false,
			'allowedUse' => 'Generate launchers, validate bindings, stage/apply review artifacts, and preserve canonical attributes such as data-dsa-open-module.',
		];
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
		return [
			'userId'    => 0,
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
