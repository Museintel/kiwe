<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Controlled_Executor_Service {
	public function build( array $stage, array $context = [] ): array {
		$approval   = isset( $stage['finalSaveApproval'] ) && is_array( $stage['finalSaveApproval'] ) ? $stage['finalSaveApproval'] : [];
		$shell      = isset( $stage['minimalAdapterShell'] ) && is_array( $stage['minimalAdapterShell'] ) ? $stage['minimalAdapterShell'] : [];
		$inspection = isset( $stage['renderedTargetInspection'] ) && is_array( $stage['renderedTargetInspection'] ) ? $stage['renderedTargetInspection'] : [];
		$capture    = isset( $stage['rollbackCapture'] ) && is_array( $stage['rollbackCapture'] ) ? $stage['rollbackCapture'] : [];
		$target     = isset( $stage['targetResolution'] ) && is_array( $stage['targetResolution'] ) ? $stage['targetResolution'] : [];
		$apply_plan = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$plan_hash  = (string) ( $stage['plan']['hash'] ?? '' );
		$blockers   = $this->blockers( $stage, $approval, $shell, $inspection, $capture, $target, $apply_plan, $plan_hash );
		$created_at = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id    = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id   = (string) ( $stage['id'] ?? '' );
		$strategy   = isset( $shell['selectedStrategy'] ) && is_array( $shell['selectedStrategy'] ) ? $shell['selectedStrategy'] : [];
		$ops        = isset( $approval['approvedOperationIds'] ) && is_array( $approval['approvedOperationIds'] ) ? $approval['approvedOperationIds'] : [];

		return [
			'schema'                       => 'kiwe.controlled-executor.v1',
			'id'                           => 'controlled-executor-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . (string) ( $approval['id'] ?? '' ) . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'                      => $stage_id,
			'createdAt'                    => $created_at,
			'createdBy'                    => $user_id,
			'status'                       => [] === $blockers ? 'controlled-executor-skeleton-ready' : 'controlled-executor-blocked',
			'planHash'                     => $plan_hash,
			'finalSaveApprovalId'          => (string) ( $approval['id'] ?? '' ),
			'minimalAdapterShellId'         => (string) ( $shell['id'] ?? '' ),
			'rollbackCaptureId'            => (string) ( $capture['id'] ?? '' ),
			'renderedInspectionId'         => (string) ( $inspection['id'] ?? '' ),
			'targetResolutionId'           => (string) ( $target['id'] ?? '' ),
			'targetPostId'                 => absint( $approval['targetPostId'] ?? $shell['targetPostId'] ?? 0 ),
			'selectedStrategyId'           => (string) ( $approval['selectedStrategyId'] ?? $strategy['id'] ?? '' ),
			'approvedOperationIds'         => array_values( array_filter( array_map( 'strval', $ops ) ) ),
			'executionInterface'           => $this->execution_interface( (string) ( $approval['selectedStrategyId'] ?? $strategy['id'] ?? '' ) ),
			'preMutationChecklist'         => $this->pre_mutation_checklist(),
			'postApplyAuditPlan'           => isset( $approval['postApplyAuditPlan'] ) && is_array( $approval['postApplyAuditPlan'] ) ? $approval['postApplyAuditPlan'] : [],
			'browserSmokePlan'             => isset( $approval['browserSmokePlan'] ) && is_array( $approval['browserSmokePlan'] ) ? $approval['browserSmokePlan'] : [],
			'rollbackVerificationPlan'     => isset( $approval['rollbackVerificationPlan'] ) && is_array( $approval['rollbackVerificationPlan'] ) ? $approval['rollbackVerificationPlan'] : [],
			'blockers'                     => $blockers,
			'gates'                        => $this->gates( $stage, $approval, $shell, $inspection, $capture, $target, $apply_plan, $blockers ),
			'mutatesWordPress'             => false,
			'mutatesBricksContent'         => false,
			'writesKiweInternalRecord'     => true,
			'adapterImplementationPresent' => false,
			'actualSaveExecuted'           => false,
			'mayExecuteMutationNow'        => false,
			'mayBuildBricksAdapterNext'     => [] === $blockers,
			'nextRequiredStep'             => 'Implement the Bricks controlled adapter for this executor interface, then run the smallest approved mutation followed by post-apply audit, browser smoke tests, and rollback verification.',
		];
	}

	private function blockers( array $stage, array $approval, array $shell, array $inspection, array $capture, array $target, array $apply_plan, string $plan_hash ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Dry-run apply plan is missing.';
		}
		if ( 'final-save-approved' !== ( $approval['status'] ?? '' ) ) {
			$blockers[] = 'Final save approval is missing or blocked.';
		}
		if ( empty( $approval['mayBuildControlledExecutor'] ) ) {
			$blockers[] = 'Final save approval did not allow controlled executor construction.';
		}
		if ( 'minimal-adapter-shell-ready' !== ( $shell['status'] ?? '' ) ) {
			$blockers[] = 'Minimal adapter shell is missing or blocked.';
		}
		if ( 'rendered-target-inspection-ready' !== ( $inspection['status'] ?? '' ) ) {
			$blockers[] = 'Rendered target inspection is missing or blocked.';
		}
		if ( 'rollback-capture-ready' !== ( $capture['status'] ?? '' ) ) {
			$blockers[] = 'Rollback capture is missing or blocked.';
		}
		if ( 'target-resolution-ready' !== ( $target['status'] ?? '' ) ) {
			$blockers[] = 'Target resolution is missing or blocked.';
		}
		if ( '' === $plan_hash || $plan_hash !== (string) ( $approval['planHash'] ?? '' ) || $plan_hash !== (string) ( $shell['planHash'] ?? '' ) || $plan_hash !== (string) ( $inspection['planHash'] ?? '' ) || $plan_hash !== (string) ( $capture['planHash'] ?? '' ) || $plan_hash !== (string) ( $target['planHash'] ?? '' ) ) {
			$blockers[] = 'Plan hash mismatch across approval, shell, inspection, rollback capture, target, and stage.';
		}
		foreach ( $this->all_blockers( [ $stage, $approval, $shell, $inspection, $capture, $target ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $approval['mutatesWordPress'] ) || ! empty( $approval['mutatesBricksContent'] ) || ! empty( $shell['mutatesWordPress'] ) || ! empty( $shell['mutatesBricksContent'] ) || ! empty( $inspection['mutatesWordPress'] ) || ! empty( $inspection['mutatesBricksContent'] ) || ! empty( $capture['mutatesWordPress'] ) || ! empty( $capture['mutatesBricksContent'] ) || ! empty( $target['mutatesWordPress'] ) || ! empty( $target['mutatesBricksContent'] ) ) {
			$blockers[] = 'Previous artifact claims mutation authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
	}

	private function gates( array $stage, array $approval, array $shell, array $inspection, array $capture, array $target, array $apply_plan, array $blockers ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The executor skeleton accepts only Kiwe staged candidates.',
			],
			[
				'id'      => 'apply-plan',
				'label'   => 'Dry-run apply plan available',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The executor skeleton must know the approved operation set.',
			],
			[
				'id'      => 'final-save-approval',
				'label'   => 'Final save approval ready',
				'status'  => 'final-save-approved' === ( $approval['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The executor skeleton must be built from a human-approved exact shell.',
			],
			[
				'id'      => 'minimal-shell',
				'label'   => 'Minimal adapter shell ready',
				'status'  => 'minimal-adapter-shell-ready' === ( $shell['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The executor skeleton uses the shell strategy and operation IDs.',
			],
			[
				'id'      => 'target-and-rollback',
				'label'   => 'Target, rollback, and inspection ready',
				'status'  => 'target-resolution-ready' === ( $target['status'] ?? '' ) && 'rollback-capture-ready' === ( $capture['status'] ?? '' ) && 'rendered-target-inspection-ready' === ( $inspection['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The executor skeleton must be scoped to a locked target with rollback and baseline artifacts.',
			],
			[
				'id'      => 'adapter-not-present',
				'label'   => 'Real Bricks adapter intentionally absent',
				'status'  => 'passed',
				'details' => 'This batch builds the executor contract only. No Bricks save adapter is executed.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No executor blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve blockers before implementing or running the controlled adapter.',
			],
			[
				'id'      => 'non-mutating',
				'label'   => 'Executor skeleton does not mutate content',
				'status'  => 'passed',
				'details' => 'This records the controlled executor interface only; it does not save Bricks, WordPress content, WooCommerce, or publish state.',
			],
		];
	}

	private function execution_interface( string $strategy_id ): array {
		return [
			'strategyId'              => $strategy_id,
			'inputArtifacts'          => [
				'kiwe.bricks-apply-plan.v1',
				'kiwe.final-save-approval.v1',
				'kiwe.minimal-adapter-shell.v1',
				'kiwe.rollback-capture.v1',
				'kiwe.rendered-target-inspection.v1',
			],
			'allowedMethods'          => [
				'bricks-abilities-adapter-preferred' => [ 'bricks-abilities-controlled-save' ],
				'html-css-to-bricks-import-review'  => [ 'html-css-to-bricks-controlled-import', 'manual-builder-review' ],
				'manual-builder-fallback'           => [ 'manual-builder-review' ],
				'kiwe-runtime-only-review'          => [ 'kiwe-runtime-contract-verification' ],
			],
			'requiredBeforeMutation'  => $this->pre_mutation_checklist(),
			'forbiddenWithoutAdapter' => [
				'wp_update_post',
				'update_post_meta:_bricks*',
				'publish',
				'woocommerce_mutation',
				'custom_runtime_code',
			],
		];
	}

	private function pre_mutation_checklist(): array {
		return [
			'reloadStageById',
			'revalidatePlanHash',
			'revalidateTargetPostId',
			'verifyRollbackSnapshotPresent',
			'verifyFinalSaveApprovalStillCurrent',
			'mapApprovedOperationIdsOnly',
			'prepareSmallestMutationBatch',
			'prepareImmediateRollbackCommand',
		];
	}

	private function all_blockers( array $sources ): array {
		$out = [];
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			foreach ( $this->array_value( $source, 'blockers' ) as $blocker ) {
				$text = (string) $blocker;
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}

		return $out;
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
