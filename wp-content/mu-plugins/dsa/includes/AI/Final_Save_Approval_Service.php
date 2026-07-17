<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Final_Save_Approval_Service {
	public function approve( array $stage, array $context = [] ): array {
		$shell       = isset( $stage['minimalAdapterShell'] ) && is_array( $stage['minimalAdapterShell'] ) ? $stage['minimalAdapterShell'] : [];
		$inspection  = isset( $stage['renderedTargetInspection'] ) && is_array( $stage['renderedTargetInspection'] ) ? $stage['renderedTargetInspection'] : [];
		$capture     = isset( $stage['rollbackCapture'] ) && is_array( $stage['rollbackCapture'] ) ? $stage['rollbackCapture'] : [];
		$target      = isset( $stage['targetResolution'] ) && is_array( $stage['targetResolution'] ) ? $stage['targetResolution'] : [];
		$apply_plan  = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$plan_hash   = (string) ( $stage['plan']['hash'] ?? '' );
		$explicit    = ! empty( $context['explicitFinalSaveApproval'] );
		$blockers    = $this->blockers( $stage, $shell, $inspection, $capture, $target, $apply_plan, $plan_hash, $explicit );
		$created_at  = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id     = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id    = (string) ( $stage['id'] ?? '' );
		$contract    = isset( $shell['futureAdapterContract'] ) && is_array( $shell['futureAdapterContract'] ) ? $shell['futureAdapterContract'] : [];
		$strategy    = isset( $shell['selectedStrategy'] ) && is_array( $shell['selectedStrategy'] ) ? $shell['selectedStrategy'] : [];
		$target_id   = isset( $contract['allowedTargetPostId'] ) ? absint( $contract['allowedTargetPostId'] ) : absint( $shell['targetPostId'] ?? 0 );

		return [
			'schema'                    => 'kiwe.final-save-approval.v1',
			'id'                        => 'save-approval-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . (string) ( $shell['id'] ?? '' ) . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'                   => $stage_id,
			'createdAt'                 => $created_at,
			'createdBy'                 => $user_id,
			'status'                    => [] === $blockers ? 'final-save-approved' : 'final-save-approval-blocked',
			'planHash'                  => $plan_hash,
			'minimalAdapterShellId'      => (string) ( $shell['id'] ?? '' ),
			'renderedInspectionId'      => (string) ( $inspection['id'] ?? '' ),
			'rollbackCaptureId'         => (string) ( $capture['id'] ?? '' ),
			'targetResolutionId'        => (string) ( $target['id'] ?? '' ),
			'targetPostId'              => $target_id,
			'selectedStrategyId'        => (string) ( $strategy['id'] ?? '' ),
			'explicitFinalSaveApproval' => $explicit,
			'approvedOperationIds'      => $this->approved_operation_ids( $shell ),
			'postApplyAuditPlan'        => $this->post_apply_audit_plan(),
			'browserSmokePlan'          => $this->browser_smoke_plan(),
			'rollbackVerificationPlan'  => $this->rollback_verification_plan( $capture ),
			'blockers'                  => $blockers,
			'gates'                     => $this->gates( $stage, $shell, $inspection, $capture, $target, $apply_plan, $explicit, $blockers ),
			'mutatesWordPress'          => false,
			'mutatesBricksContent'      => false,
			'writesKiweInternalRecord'  => true,
			'actualSaveExecuted'        => false,
			'mayExecuteMutationNow'     => false,
			'mayBuildControlledExecutor' => [] === $blockers,
			'nextRequiredStep'          => 'Run the controlled save executor for this exact approved shell, then immediately run post-apply Kiwe audit and browser smoke tests.',
		];
	}

	private function gates( array $stage, array $shell, array $inspection, array $capture, array $target, array $apply_plan, bool $explicit, array $blockers ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Final save approval accepts only staged Kiwe candidates.',
			],
			[
				'id'      => 'apply-plan',
				'label'   => 'Dry-run apply plan available',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The save approval must approve the reviewed operation plan.',
			],
			[
				'id'      => 'target-resolution',
				'label'   => 'Exact target resolved',
				'status'  => 'target-resolution-ready' === ( $target['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The approval is scoped to one WordPress target.',
			],
			[
				'id'      => 'rollback-capture',
				'label'   => 'Rollback capture ready',
				'status'  => 'rollback-capture-ready' === ( $capture['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The approved save must have a rollback snapshot.',
			],
			[
				'id'      => 'rendered-inspection',
				'label'   => 'Rendered target inspection ready',
				'status'  => 'rendered-target-inspection-ready' === ( $inspection['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The approved save must be based on the inspected baseline.',
			],
			[
				'id'      => 'minimal-shell',
				'label'   => 'Minimal adapter shell ready',
				'status'  => 'minimal-adapter-shell-ready' === ( $shell['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The approval must reference the exact future adapter shell.',
			],
			[
				'id'      => 'explicit-approval',
				'label'   => 'Explicit final save approval checked',
				'status'  => $explicit ? 'passed' : 'blocked',
				'details' => 'A human must check the final save approval box before the future executor can be built.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No final save approval blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve blockers before controlled save execution.',
			],
			[
				'id'      => 'non-mutating',
				'label'   => 'Approval does not mutate content',
				'status'  => 'passed',
				'details' => 'This records approval only; it does not save Bricks, WordPress content, WooCommerce, or publish state.',
			],
		];
	}

	private function blockers( array $stage, array $shell, array $inspection, array $capture, array $target, array $apply_plan, string $plan_hash, bool $explicit ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Dry-run apply plan is missing.';
		}
		if ( 'target-resolution-ready' !== ( $target['status'] ?? '' ) ) {
			$blockers[] = 'Target resolution is missing or blocked.';
		}
		if ( 'rollback-capture-ready' !== ( $capture['status'] ?? '' ) ) {
			$blockers[] = 'Rollback capture is missing or blocked.';
		}
		if ( 'rendered-target-inspection-ready' !== ( $inspection['status'] ?? '' ) ) {
			$blockers[] = 'Rendered target inspection is missing or blocked.';
		}
		if ( 'minimal-adapter-shell-ready' !== ( $shell['status'] ?? '' ) ) {
			$blockers[] = 'Minimal adapter shell is missing or blocked.';
		}
		if ( ! $explicit ) {
			$blockers[] = 'Explicit final save approval checkbox was not checked.';
		}
		if ( '' === $plan_hash || $plan_hash !== (string) ( $target['planHash'] ?? '' ) || $plan_hash !== (string) ( $capture['planHash'] ?? '' ) || $plan_hash !== (string) ( $inspection['planHash'] ?? '' ) || $plan_hash !== (string) ( $shell['planHash'] ?? '' ) ) {
			$blockers[] = 'Plan hash mismatch across stage, target, rollback capture, rendered inspection, and adapter shell.';
		}
		foreach ( $this->all_blockers( [ $stage, $target, $capture, $inspection, $shell ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $target['mutatesWordPress'] ) || ! empty( $target['mutatesBricksContent'] ) || ! empty( $capture['mutatesWordPress'] ) || ! empty( $capture['mutatesBricksContent'] ) || ! empty( $inspection['mutatesWordPress'] ) || ! empty( $inspection['mutatesBricksContent'] ) || ! empty( $shell['mutatesWordPress'] ) || ! empty( $shell['mutatesBricksContent'] ) ) {
			$blockers[] = 'Previous artifact claims mutation authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
	}

	private function approved_operation_ids( array $shell ): array {
		$contract = isset( $shell['futureAdapterContract'] ) && is_array( $shell['futureAdapterContract'] ) ? $shell['futureAdapterContract'] : [];
		$ids      = isset( $contract['allowedOperationIds'] ) && is_array( $contract['allowedOperationIds'] ) ? $contract['allowedOperationIds'] : [];

		return array_values( array_filter( array_map( 'strval', $ids ) ) );
	}

	private function post_apply_audit_plan(): array {
		return [
			'validateBindingPlanStillPasses',
			'compareTargetPlanHashAndApprovedShell',
			'verifyKiweLauncherAttributesStillCanonical',
			'verifyNoDuplicateCartSearchAuthCheckoutRuntime',
			'verifyMenuContextAnchorsScroll',
			'verifyNoUnexpectedWooCommerceMutation',
			'capturePostApplySnapshotHash',
		];
	}

	private function browser_smoke_plan(): array {
		return [
			'openTargetPageDesktopTabletMobile',
			'openKiweMenuSearchProfileCartFromDock',
			'activateHeaderLaunchersIfPresent',
			'checkNoHorizontalOverflowAtNarrowWidths',
			'checkConsoleForFatalErrors',
			'checkCartSearchSaveAuthorityRemainsKiweOwned',
		];
	}

	private function rollback_verification_plan( array $capture ): array {
		return [
			'rollbackCaptureId' => (string) ( $capture['id'] ?? '' ),
			'snapshotHash'      => (string) ( $capture['snapshotHash'] ?? '' ),
			'metaHash'          => (string) ( $capture['metaHash'] ?? '' ),
			'verifySnapshotPresentBeforeSave' => true,
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
