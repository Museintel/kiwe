<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Post_Apply_Verification_Service {
	public function build( array $stage, array $context = [] ): array {
		$adapter    = isset( $stage['bricksControlledAdapter'] ) && is_array( $stage['bricksControlledAdapter'] ) ? $stage['bricksControlledAdapter'] : [];
		$executor   = isset( $stage['controlledExecutor'] ) && is_array( $stage['controlledExecutor'] ) ? $stage['controlledExecutor'] : [];
		$approval   = isset( $stage['finalSaveApproval'] ) && is_array( $stage['finalSaveApproval'] ) ? $stage['finalSaveApproval'] : [];
		$capture    = isset( $stage['rollbackCapture'] ) && is_array( $stage['rollbackCapture'] ) ? $stage['rollbackCapture'] : [];
		$inspection = isset( $stage['renderedTargetInspection'] ) && is_array( $stage['renderedTargetInspection'] ) ? $stage['renderedTargetInspection'] : [];
		$target     = isset( $stage['targetResolution'] ) && is_array( $stage['targetResolution'] ) ? $stage['targetResolution'] : [];
		$apply_plan = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$plan_hash  = (string) ( $stage['plan']['hash'] ?? '' );
		$created_at = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id    = isset( $context['userId'] ) ? $this->absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$target_id  = $this->absint( $adapter['targetPostId'] ?? $executor['targetPostId'] ?? $capture['targetPostId'] ?? $target['target']['id'] ?? 0 );
		$instructions = isset( $adapter['operationInstructions'] ) && is_array( $adapter['operationInstructions'] ) ? $adapter['operationInstructions'] : [];
		$candidate    = $this->smallest_run_candidate( $instructions, $target_id );
		$blockers     = $this->blockers( $stage, $adapter, $executor, $approval, $capture, $inspection, $target, $apply_plan, $candidate, $plan_hash );

		return [
			'schema'                         => 'kiwe.post-apply-verification.v1',
			'id'                             => 'post-apply-' . substr( hash( 'sha256', (string) ( $stage['id'] ?? '' ) . '|' . $plan_hash . '|' . (string) ( $adapter['id'] ?? '' ) . '|' . (string) ( $capture['snapshotHash'] ?? '' ) . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'                        => (string) ( $stage['id'] ?? '' ),
			'createdAt'                      => $created_at,
			'createdBy'                      => $user_id,
			'status'                         => [] === $blockers ? 'post-apply-verification-ready' : 'post-apply-verification-blocked',
			'mode'                           => 'pre-mutation-verification-and-rollback-proof',
			'planHash'                       => $plan_hash,
			'targetPostId'                   => $target_id,
			'bricksControlledAdapterId'       => (string) ( $adapter['id'] ?? '' ),
			'controlledExecutorId'            => (string) ( $executor['id'] ?? '' ),
			'rollbackCaptureId'               => (string) ( $capture['id'] ?? '' ),
			'renderedInspectionId'            => (string) ( $inspection['id'] ?? '' ),
			'finalSaveApprovalId'             => (string) ( $approval['id'] ?? '' ),
			'smallestControlledRunCandidate'  => $candidate,
			'postApplyVerificationPlan'       => $this->post_apply_verification_plan( $candidate, $instructions, $inspection, $approval ),
			'rollbackProof'                   => $this->rollback_proof( $target_id, $capture ),
			'verificationMatrix'              => $this->verification_matrix( $instructions ),
			'gates'                           => $this->gates( $stage, $adapter, $executor, $approval, $capture, $inspection, $target, $candidate, $blockers ),
			'blockers'                        => $blockers,
			'mutatesWordPress'                => false,
			'mutatesBricksContent'            => false,
			'writesKiweInternalRecord'        => true,
			'actualApplyExecuted'             => false,
			'actualPostApplyVerificationRun'  => false,
			'actualRollbackExecuted'          => false,
			'mayExecuteMutationNow'           => false,
			'mayPrepareControlledRunNext'     => [] === $blockers,
			'nextRequiredStep'                => 'Use this proof artifact to run one explicitly approved smallest adapter mutation on a staging target, then attach real post-apply render/audit results and rollback execution evidence before broad apply is allowed.',
		];
	}

	private function smallest_run_candidate( array $instructions, int $target_id ): array {
		$preferred = [];
		foreach ( $instructions as $instruction ) {
			if ( ! is_array( $instruction ) ) {
				continue;
			}
			$type = (string) ( $instruction['type'] ?? '' );
			if ( str_starts_with( $type, 'bricks.' ) ) {
				$preferred[] = $instruction;
			}
		}
		if ( [] === $preferred ) {
			foreach ( $instructions as $instruction ) {
				if ( is_array( $instruction ) ) {
					$preferred[] = $instruction;
				}
			}
		}
		$first = $preferred[0] ?? [];
		if ( ! is_array( $first ) || [] === $first ) {
			return [
				'status' => 'blocked-no-operation',
				'reason' => 'No adapter instruction is available for a smallest controlled run.',
			];
		}

		return [
			'status'                => 'planned-not-executed',
			'targetPostId'          => $target_id,
			'maxOperationCount'     => 1,
			'operationId'           => (string) ( $first['operationId'] ?? '' ),
			'type'                  => (string) ( $first['type'] ?? '' ),
			'adapterAction'         => (string) ( $first['adapterAction'] ?? '' ),
			'selector'              => (string) ( $first['selector'] ?? '' ),
			'reason'                => 'Use the smallest meaningful approved operation first so rollback and rendered proof are easy to inspect.',
			'requiresHumanApproval' => true,
			'executesNow'           => false,
		];
	}

	private function post_apply_verification_plan( array $candidate, array $instructions, array $inspection, array $approval ): array {
		return [
			'reloadStageById',
			'revalidatePlanHashAndTargetPostId',
			'revalidateApprovedOperationId:' . (string) ( $candidate['operationId'] ?? '' ),
			'confirmRollbackSnapshotHash:' . (string) ( $inspection['baseline']['snapshotHash'] ?? $approval['rollbackVerificationPlan']['snapshotHash'] ?? '' ),
			'executeOneSmallestMutationOnlyOnExplicitRun',
			'capturePostApplyTargetSnapshot',
			'compareChangedScopeToApprovedOperationOnly',
			'renderTargetWithCacheBypass',
			'runKiweAuthorityAudit',
			'runBrowserSmokeForLaunchersMenuContextResponsiveAndDarkMode',
			'ifAnyCheckFailsExecuteRollbackImmediately',
			'reverifyRollbackSnapshotHashAfterRestore',
			'storeAllResultsAsKiweInternalProof',
		];
	}

	private function rollback_proof( int $target_id, array $capture ): array {
		$snapshot = isset( $capture['snapshot'] ) && is_array( $capture['snapshot'] ) ? $capture['snapshot'] : [];
		$fields   = isset( $snapshot['fields'] ) && is_array( $snapshot['fields'] ) ? $snapshot['fields'] : [];
		$meta     = isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : [];

		return [
			'status'                         => [] !== $snapshot ? 'rollback-proof-planned' : 'rollback-proof-blocked',
			'targetPostId'                   => $target_id,
			'rollbackCaptureId'              => (string) ( $capture['id'] ?? '' ),
			'snapshotHash'                   => (string) ( $capture['snapshotHash'] ?? '' ),
			'metaHash'                       => (string) ( $capture['metaHash'] ?? '' ),
			'fieldKeys'                      => array_keys( $fields ),
			'metaKeys'                       => array_keys( $meta ),
			'restoreAuthority'               => 'Kiwe trusted adapter only after explicit failure/rollback path approval.',
			'restorePlan'                    => [
				'restoreCapturedWordPressFields',
				'restoreCapturedRelevantMetaFamilies:_bricks,_kiwe_,_dsa_,_wp_page_template',
				'removeOnlyRelevantMetaKeysCreatedByTheFailedControlledRun',
				'doNotTouchWooCommerceOrdersPaymentsCustomers',
				'doNotPublishOrChangePostStatusBeyondCapturedValue',
			],
			'verificationAfterRestore'       => [
				'recomputeSnapshotHashMatchesCapture',
				'recomputeMetaHashMatchesCapture',
				'renderBaselineUrlAgain',
				'confirmNoUnexpectedKiweRuntimeErrors',
			],
			'actualRollbackExecuted'         => false,
			'actualRollbackVerificationRun'  => false,
		];
	}

	private function verification_matrix( array $instructions ): array {
		$out = [];
		foreach ( $instructions as $instruction ) {
			if ( ! is_array( $instruction ) ) {
				continue;
			}
			$type = (string) ( $instruction['type'] ?? '' );
			$out[] = [
				'operationId' => (string) ( $instruction['operationId'] ?? '' ),
				'type'        => $type,
				'checks'      => $this->checks_for_type( $type ),
			];
		}

		return $out;
	}

	private function checks_for_type( string $type ): array {
		if ( 'bricks.query-loop' === $type ) {
			return [
				'Bricks element id exists after conversion/import',
				'query objectType exists on current Site Graph/Bricks runtime',
				'taxonomy terms still exist',
				'rendered card count is within expected bounds',
				'no custom query JavaScript/PHP was introduced by AI output',
			];
		}
		if ( 'bricks.dynamic-field' === $type ) {
			return [
				'Bricks element id exists after conversion/import',
				'dynamic tag exists on current Site Graph/Bricks runtime',
				'rendered dynamic value is not unresolved placeholder text',
				'no runtime imitation JavaScript was introduced',
			];
		}
		if ( 'kiwe.launcher-attribute' === $type ) {
			return [
				'canonical data-dsa-open-module attribute remains present',
				'click and keyboard activation open the Kiwe-owned surface',
				'page does not implement duplicate AppShell runtime JavaScript',
			];
		}
		if ( 'kiwe.menu-context' === $type ) {
			return [
				'visible heading or Seam semantic section remains present',
				'Kiwe menu context label resolves from visible/semantic content',
				'clicking menu context scrolls the page to the target section',
			];
		}

		return [ 'manual review required for unsupported operation type' ];
	}

	private function blockers( array $stage, array $adapter, array $executor, array $approval, array $capture, array $inspection, array $target, array $apply_plan, array $candidate, string $plan_hash ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'kiwe.bricks-controlled-adapter.v1' !== ( $adapter['schema'] ?? '' ) || 'bricks-controlled-adapter-ready' !== ( $adapter['status'] ?? '' ) ) {
			$blockers[] = 'Bricks controlled adapter plan is missing or blocked.';
		}
		if ( 'kiwe.controlled-executor.v1' !== ( $executor['schema'] ?? '' ) || 'controlled-executor-skeleton-ready' !== ( $executor['status'] ?? '' ) ) {
			$blockers[] = 'Controlled executor skeleton is missing or blocked.';
		}
		if ( 'final-save-approved' !== ( $approval['status'] ?? '' ) ) {
			$blockers[] = 'Final save approval is missing or blocked.';
		}
		if ( 'rollback-capture-ready' !== ( $capture['status'] ?? '' ) ) {
			$blockers[] = 'Rollback capture is missing or blocked.';
		}
		if ( 'rendered-target-inspection-ready' !== ( $inspection['status'] ?? '' ) ) {
			$blockers[] = 'Rendered target inspection is missing or blocked.';
		}
		if ( 'target-resolution-ready' !== ( $target['status'] ?? '' ) ) {
			$blockers[] = 'Target resolution is missing or blocked.';
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Dry-run apply plan is missing.';
		}
		if ( '' === $plan_hash || $plan_hash !== (string) ( $adapter['planHash'] ?? '' ) || $plan_hash !== (string) ( $executor['planHash'] ?? '' ) || $plan_hash !== (string) ( $approval['planHash'] ?? '' ) || $plan_hash !== (string) ( $capture['planHash'] ?? '' ) || $plan_hash !== (string) ( $inspection['planHash'] ?? '' ) || $plan_hash !== (string) ( $target['planHash'] ?? '' ) ) {
			$blockers[] = 'Plan hash mismatch across adapter, executor, approval, rollback capture, inspection, target, and stage.';
		}
		foreach ( $this->all_blockers( [ $stage, $adapter, $executor, $approval, $capture, $inspection, $target ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( 'blocked-no-operation' === ( $candidate['status'] ?? '' ) ) {
			$blockers[] = 'No smallest controlled run candidate could be selected.';
		}
		if ( empty( $capture['snapshotHash'] ) || empty( $capture['snapshot'] ) || ! is_array( $capture['snapshot'] ) ) {
			$blockers[] = 'Rollback capture does not contain a usable snapshot hash and payload.';
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $adapter['mutatesWordPress'] ) || ! empty( $adapter['mutatesBricksContent'] ) || ! empty( $adapter['actualSaveExecuted'] ) || ! empty( $adapter['mayExecuteMutationNow'] ) || ! empty( $executor['actualSaveExecuted'] ) || ! empty( $executor['mayExecuteMutationNow'] ) ) {
			$blockers[] = 'Upstream artifact claims mutation or executed save authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
	}

	private function gates( array $stage, array $adapter, array $executor, array $approval, array $capture, array $inspection, array $target, array $candidate, array $blockers ): array {
		$plan_hash = (string) ( $stage['plan']['hash'] ?? '' );

		return [
			[
				'id'      => 'adapter-plan',
				'label'   => 'Bricks controlled adapter plan ready',
				'status'  => 'kiwe.bricks-controlled-adapter.v1' === ( $adapter['schema'] ?? '' ) && 'bricks-controlled-adapter-ready' === ( $adapter['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Post-apply verification can only be planned from a clean adapter plan.',
			],
			[
				'id'      => 'rollback-capture',
				'label'   => 'Rollback snapshot captured',
				'status'  => 'rollback-capture-ready' === ( $capture['status'] ?? '' ) && ! empty( $capture['snapshotHash'] ) ? 'passed' : 'blocked',
				'details' => 'A future smallest run must have a known restore source before it starts.',
			],
			[
				'id'      => 'baseline-inspection',
				'label'   => 'Rendered baseline inspected',
				'status'  => 'rendered-target-inspection-ready' === ( $inspection['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Post-apply checks compare against the protected baseline inspection.',
			],
			[
				'id'      => 'plan-hash-lock',
				'label'   => 'Plan hash locked across late artifacts',
				'status'  => '' !== $plan_hash && $plan_hash === (string) ( $adapter['planHash'] ?? '' ) && $plan_hash === (string) ( $executor['planHash'] ?? '' ) && $plan_hash === (string) ( $approval['planHash'] ?? '' ) && $plan_hash === (string) ( $capture['planHash'] ?? '' ) && $plan_hash === (string) ( $inspection['planHash'] ?? '' ) && $plan_hash === (string) ( $target['planHash'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The future run cannot drift from the reviewed plan.',
			],
			[
				'id'      => 'smallest-run-candidate',
				'label'   => 'Smallest controlled run candidate selected',
				'status'  => 'planned-not-executed' === ( $candidate['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The first mutation must be the smallest useful approved operation.',
			],
			[
				'id'      => 'non-mutating',
				'label'   => 'Verification proof does not mutate content',
				'status'  => 'passed',
				'details' => 'This records the verification and rollback proof plan only; no Bricks save, WordPress update, WooCommerce mutation, or publish action runs.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No verification blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve blockers before preparing a real controlled run.',
			],
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

	private function absint( $value ): int {
		return function_exists( 'absint' ) ? absint( $value ) : max( 0, (int) $value );
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
