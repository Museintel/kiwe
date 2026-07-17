<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Minimal_Adapter_Shell_Service {
	public function build( array $stage, array $context = [] ): array {
		$inspection        = isset( $stage['renderedTargetInspection'] ) && is_array( $stage['renderedTargetInspection'] ) ? $stage['renderedTargetInspection'] : [];
		$capture           = isset( $stage['rollbackCapture'] ) && is_array( $stage['rollbackCapture'] ) ? $stage['rollbackCapture'] : [];
		$target_resolution = isset( $stage['targetResolution'] ) && is_array( $stage['targetResolution'] ) ? $stage['targetResolution'] : [];
		$proof             = isset( $stage['adapterProof'] ) && is_array( $stage['adapterProof'] ) ? $stage['adapterProof'] : [];
		$apply_plan        = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$operations        = $this->array_value( $apply_plan, 'operations' );
		$capabilities      = isset( $proof['capabilities'] ) && is_array( $proof['capabilities'] ) ? $proof['capabilities'] : [];
		$plan_hash         = (string) ( $stage['plan']['hash'] ?? '' );
		$blockers          = $this->blockers( $stage, $inspection, $capture, $target_resolution, $proof, $apply_plan, $plan_hash );
		$created_at        = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id           = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id          = (string) ( $stage['id'] ?? '' );
		$strategy          = $this->strategy( $operations, $capabilities );
		$operation_plan    = $this->operation_plan( $operations, $inspection );
		$warnings          = $this->warnings( $strategy, $operation_plan, $inspection, $capabilities );

		return [
			'schema'                    => 'kiwe.minimal-adapter-shell.v1',
			'id'                        => 'adapter-shell-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . (string) ( $inspection['id'] ?? '' ) . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'                   => $stage_id,
			'createdAt'                 => $created_at,
			'createdBy'                 => $user_id,
			'status'                    => [] === $blockers ? 'minimal-adapter-shell-ready' : 'minimal-adapter-shell-blocked',
			'planHash'                  => $plan_hash,
			'targetResolutionId'        => (string) ( $target_resolution['id'] ?? '' ),
			'rollbackCaptureId'         => (string) ( $capture['id'] ?? '' ),
			'renderedInspectionId'      => (string) ( $inspection['id'] ?? '' ),
			'targetPostId'              => isset( $inspection['targetPostId'] ) ? absint( $inspection['targetPostId'] ) : absint( $capture['targetPostId'] ?? 0 ),
			'selectedStrategy'          => $strategy,
			'operationPlan'             => $operation_plan,
			'warnings'                  => $warnings,
			'blockers'                  => $blockers,
			'gates'                     => $this->gates( $stage, $inspection, $capture, $target_resolution, $proof, $apply_plan, $blockers ),
			'futureAdapterContract'     => [
				'executionMode'              => 'future-reviewed-mutation-only',
				'allowedTargetPostId'        => isset( $inspection['targetPostId'] ) ? absint( $inspection['targetPostId'] ) : absint( $capture['targetPostId'] ?? 0 ),
				'allowedPlanHash'            => $plan_hash,
				'allowedOperationIds'        => array_values( array_filter( array_map( static fn( $item ): string => is_array( $item ) ? (string) ( $item['id'] ?? '' ) : '', $operation_plan ) ) ),
				'mustRevalidatePlanHash'     => true,
				'mustRevalidateTarget'       => true,
				'mustRevalidateRollbackHash' => true,
				'mustMapSelectorsBeforeSave' => true,
				'mustAskFinalSaveApproval'   => true,
				'mustRunPostApplyAudit'      => true,
				'forbiddenNow'               => [
					'bricks-save',
					'wordpress-post-update',
					'publish',
					'woocommerce-mutation',
					'payment-code',
					'custom-runtime-code',
				],
			],
			'mutatesWordPress'          => false,
			'mutatesBricksContent'      => false,
			'writesKiweInternalRecord'  => true,
			'actualAdapterExecuted'     => false,
			'mayExecuteMutationNow'     => false,
			'nextRequiredStep'          => 'Run the reviewed adapter in a controlled save step only after final save approval, then run post-apply Kiwe audit and browser smoke tests.',
		];
	}

	private function strategy( array $operations, array $capabilities ): array {
		$bricks_ops = $this->bricks_operations( $operations );
		if ( [] === $bricks_ops ) {
			return [
				'id'       => 'kiwe-runtime-only-review',
				'label'    => 'Kiwe runtime-only review',
				'priority' => 1,
				'reason'   => 'No Bricks query-loop or dynamic-field operations are present.',
			];
		}
		if ( ! empty( $capabilities['trustedAdapterSignalAvailable'] ) ) {
			return [
				'id'       => 'bricks-abilities-adapter-preferred',
				'label'    => 'Bricks/WP abilities adapter preferred',
				'priority' => 1,
				'reason'   => 'The target Site Graph exposed a trusted Bricks/WP abilities signal.',
			];
		}
		if ( ! empty( $capabilities['htmlCssToBricksAvailable'] ) ) {
			return [
				'id'       => 'html-css-to-bricks-import-review',
				'label'    => 'HTML/CSS to Bricks import review',
				'priority' => 2,
				'reason'   => 'Bricks HTML/CSS conversion is available, but no trusted abilities signal was proven.',
			];
		}

		return [
			'id'       => 'manual-builder-fallback',
			'label'    => 'Manual Bricks builder fallback',
			'priority' => 3,
			'reason'   => 'No trusted abilities or HTML/CSS conversion signal was proven; a human must map the imported page in Bricks.',
		];
	}

	private function operation_plan( array $operations, array $inspection ): array {
		$coverage = $this->coverage_by_id( $inspection );
		$out      = [];
		foreach ( $operations as $position => $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$id       = (string) ( $operation['id'] ?? 'operation-' . $position );
			$type     = (string) ( $operation['type'] ?? '' );
			$selector = (string) ( $operation['selector'] ?? '' );
			$item     = isset( $coverage[ $id ] ) && is_array( $coverage[ $id ] ) ? $coverage[ $id ] : [];
			$coverage_status = (string) ( $item['coverage'] ?? 'not-checked' );
			$needs_bricks = 0 === strpos( $type, 'bricks.' );
			$out[] = [
				'id'               => $id,
				'type'             => $type,
				'label'            => (string) ( $operation['label'] ?? $id ),
				'selector'         => $selector,
				'coverage'         => $coverage_status,
				'authority'        => $needs_bricks ? 'bricks-adapter-required' : 'kiwe-runtime-owned',
				'plannedMutation'  => $needs_bricks ? 'future-reviewed-bricks-mapping' : 'no-content-mutation-runtime-contract',
				'smallestSafeStep' => $this->smallest_step( $type, $coverage_status ),
				'blocking'         => false,
				'reviewRequired'   => true,
			];
		}

		return $out;
	}

	private function smallest_step( string $type, string $coverage ): string {
		if ( 0 !== strpos( $type, 'bricks.' ) ) {
			return 'preserve-or-verify-existing-kiwe-runtime-contract';
		}
		if ( 'existing-target-match' === $coverage ) {
			return 'update-existing-matched-bricks-region-after-final-save-approval';
		}
		if ( 'not-selector-based' === $coverage ) {
			return 'manual-map-non-selector-operation-before-save';
		}

		return 'map-after-html-css-import-or-builder-selection-before-save';
	}

	private function warnings( array $strategy, array $operation_plan, array $inspection, array $capabilities ): array {
		$warnings = [];
		foreach ( $this->array_value( $inspection, 'warnings' ) as $warning ) {
			$text = (string) $warning;
			if ( '' !== $text ) {
				$warnings[] = $text;
			}
		}
		if ( 'manual-builder-fallback' === ( $strategy['id'] ?? '' ) ) {
			$warnings[] = 'Manual builder fallback selected because no trusted Bricks abilities or HTML/CSS conversion signal was proven.';
		}
		if ( [] !== $this->bricks_operation_plan( $operation_plan ) && empty( $capabilities['trustedAdapterSignalAvailable'] ) ) {
			$warnings[] = 'Bricks operations are present, but trusted adapter signal is unavailable; final save must remain human-reviewed.';
		}

		return array_values( array_unique( $warnings ) );
	}

	private function gates( array $stage, array $inspection, array $capture, array $target_resolution, array $proof, array $apply_plan, array $blockers ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Minimal adapter shell accepts only staged Kiwe candidates.',
			],
			[
				'id'      => 'apply-plan',
				'label'   => 'Dry-run apply plan available',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The shell is built from the reviewed dry-run operation list.',
			],
			[
				'id'      => 'proof',
				'label'   => 'Trusted adapter proof ready',
				'status'  => 'adapter-proof-ready' === ( $proof['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The shell requires capability proof from the current live Site Graph.',
			],
			[
				'id'      => 'target-resolution',
				'label'   => 'Exact target resolved',
				'status'  => 'target-resolution-ready' === ( $target_resolution['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The shell is scoped to one target only.',
			],
			[
				'id'      => 'rollback-capture',
				'label'   => 'Rollback capture ready',
				'status'  => 'rollback-capture-ready' === ( $capture['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The shell must be protected by a target snapshot.',
			],
			[
				'id'      => 'rendered-inspection',
				'label'   => 'Rendered target baseline inspected',
				'status'  => 'rendered-target-inspection-ready' === ( $inspection['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The shell must be based on inspected current target shape.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No shell blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve blockers before a future reviewed save can be attempted.',
			],
			[
				'id'      => 'non-mutating',
				'label'   => 'Adapter shell does not mutate content',
				'status'  => 'passed',
				'details' => 'This records a future execution shell only; it does not save Bricks, WordPress content, or publish state.',
			],
		];
	}

	private function blockers( array $stage, array $inspection, array $capture, array $target_resolution, array $proof, array $apply_plan, string $plan_hash ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Dry-run apply plan is missing.';
		}
		if ( 'adapter-proof-ready' !== ( $proof['status'] ?? '' ) ) {
			$blockers[] = 'Trusted adapter proof is missing or blocked.';
		}
		if ( 'target-resolution-ready' !== ( $target_resolution['status'] ?? '' ) ) {
			$blockers[] = 'Target resolution is missing or blocked.';
		}
		if ( 'rollback-capture-ready' !== ( $capture['status'] ?? '' ) ) {
			$blockers[] = 'Rollback capture is missing or blocked.';
		}
		if ( 'rendered-target-inspection-ready' !== ( $inspection['status'] ?? '' ) ) {
			$blockers[] = 'Rendered target inspection is missing or blocked.';
		}
		if ( '' === $plan_hash || $plan_hash !== (string) ( $proof['planHash'] ?? $plan_hash ) || $plan_hash !== (string) ( $target_resolution['planHash'] ?? '' ) || $plan_hash !== (string) ( $capture['planHash'] ?? '' ) || $plan_hash !== (string) ( $inspection['planHash'] ?? '' ) ) {
			$blockers[] = 'Plan hash mismatch across stage, proof, target, rollback capture, and rendered inspection.';
		}
		foreach ( $this->all_blockers( [ $stage, $proof, $target_resolution, $capture, $inspection ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $proof['mutatesWordPress'] ) || ! empty( $proof['mutatesBricksContent'] ) || ! empty( $target_resolution['mutatesWordPress'] ) || ! empty( $target_resolution['mutatesBricksContent'] ) || ! empty( $capture['mutatesWordPress'] ) || ! empty( $capture['mutatesBricksContent'] ) || ! empty( $inspection['mutatesWordPress'] ) || ! empty( $inspection['mutatesBricksContent'] ) ) {
			$blockers[] = 'Previous artifact claims mutation authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
	}

	private function coverage_by_id( array $inspection ): array {
		$out = [];
		foreach ( $this->array_value( $inspection, 'operationSelectorCoverage' ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = (string) ( $item['id'] ?? '' );
			if ( '' !== $id ) {
				$out[ $id ] = $item;
			}
		}

		return $out;
	}

	private function bricks_operations( array $operations ): array {
		return array_values(
			array_filter(
				$operations,
				static fn( $operation ): bool => is_array( $operation ) && 0 === strpos( (string) ( $operation['type'] ?? '' ), 'bricks.' )
			)
		);
	}

	private function bricks_operation_plan( array $operation_plan ): array {
		return array_values(
			array_filter(
				$operation_plan,
				static fn( $operation ): bool => is_array( $operation ) && 'bricks-adapter-required' === ( $operation['authority'] ?? '' )
			)
		);
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
