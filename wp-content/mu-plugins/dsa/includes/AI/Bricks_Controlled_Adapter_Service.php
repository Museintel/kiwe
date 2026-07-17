<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bricks_Controlled_Adapter_Service {
	public function prepare( array $stage, array $context = [] ): array {
		$executor   = isset( $stage['controlledExecutor'] ) && is_array( $stage['controlledExecutor'] ) ? $stage['controlledExecutor'] : [];
		$approval   = isset( $stage['finalSaveApproval'] ) && is_array( $stage['finalSaveApproval'] ) ? $stage['finalSaveApproval'] : [];
		$shell      = isset( $stage['minimalAdapterShell'] ) && is_array( $stage['minimalAdapterShell'] ) ? $stage['minimalAdapterShell'] : [];
		$target     = isset( $stage['targetResolution'] ) && is_array( $stage['targetResolution'] ) ? $stage['targetResolution'] : [];
		$apply_plan = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$operations = isset( $apply_plan['operations'] ) && is_array( $apply_plan['operations'] ) ? $apply_plan['operations'] : [];
		$plan_hash  = (string) ( $stage['plan']['hash'] ?? '' );
		$approved   = $this->approved_operation_ids( $executor, $approval, $operations );
		$prepared   = $this->operation_instructions( $operations, $approved );
		$created_at = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id    = isset( $context['userId'] ) ? $this->absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$blockers   = $this->blockers( $stage, $executor, $approval, $shell, $target, $apply_plan, $operations, $approved, $prepared, $plan_hash );
		$target_id  = $this->absint( $executor['targetPostId'] ?? $approval['targetPostId'] ?? $shell['targetPostId'] ?? $target['targetPostId'] ?? 0 );
		$strategy   = $this->selected_strategy_id( $executor, $approval, $shell );

		return [
			'schema'                               => 'kiwe.bricks-controlled-adapter.v1',
			'id'                                   => 'bricks-adapter-' . substr( hash( 'sha256', (string) ( $stage['id'] ?? '' ) . '|' . $plan_hash . '|' . (string) ( $executor['id'] ?? '' ) . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'                              => (string) ( $stage['id'] ?? '' ),
			'createdAt'                            => $created_at,
			'createdBy'                            => $user_id,
			'status'                               => [] === $blockers ? 'bricks-controlled-adapter-ready' : 'bricks-controlled-adapter-blocked',
			'planHash'                             => $plan_hash,
			'controlledExecutorId'                 => (string) ( $executor['id'] ?? '' ),
			'finalSaveApprovalId'                  => (string) ( $approval['id'] ?? '' ),
			'minimalAdapterShellId'                => (string) ( $shell['id'] ?? '' ),
			'targetPostId'                         => $target_id,
			'selectedStrategyId'                   => $strategy,
			'adapterMethod'                        => $this->adapter_method( $strategy ),
			'approvedOperationIds'                 => $approved,
			'operationInstructions'                => $prepared,
			'mutationLock'                         => $this->mutation_lock( $target_id, $plan_hash, $approved, $prepared ),
			'requiredBeforeAnySave'                => $this->required_before_any_save(),
			'gates'                                => $this->gates( $stage, $executor, $approval, $shell, $target, $apply_plan, $prepared, $blockers ),
			'blockers'                             => $blockers,
			'mutatesWordPress'                     => false,
			'mutatesBricksContent'                 => false,
			'writesKiweInternalRecord'             => true,
			'adapterImplementationPresent'         => true,
			'adapterPlanPrepared'                  => true,
			'adapterCanSaveNow'                    => false,
			'actualSaveExecuted'                   => false,
			'mayExecuteMutationNow'                => false,
			'requiresPostApplyProofBeforeMutation' => true,
			'nextRequiredStep'                     => 'Add post-apply verification and rollback proof for one smallest approved adapter run before any Bricks or WordPress mutation is allowed.',
		];
	}

	private function operation_instructions( array $operations, array $approved_ids ): array {
		$approved = array_fill_keys( $approved_ids, true );
		$out      = [];
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$id = (string) ( $operation['id'] ?? '' );
			if ( '' === $id || ! isset( $approved[ $id ] ) ) {
				continue;
			}
			$out[] = $this->operation_instruction( $operation );
		}

		return $out;
	}

	private function operation_instruction( array $operation ): array {
		$type     = (string) ( $operation['type'] ?? '' );
		$selector = (string) ( $operation['selector'] ?? '' );
		$base     = [
			'operationId'                    => (string) ( $operation['id'] ?? '' ),
			'type'                           => $type,
			'label'                          => (string) ( $operation['label'] ?? $operation['id'] ?? '' ),
			'selector'                       => $selector,
			'canExecuteNow'                  => false,
			'mutatesWordPress'               => false,
			'mutatesBricksContent'           => false,
			'requiresPostApplyProof'         => true,
			'requiresBricksElementIdMapping' => str_starts_with( $type, 'bricks.' ),
			'forbiddenDirectWrites'          => [ 'update_post_meta:_bricks*', 'wp_update_post', 'publish' ],
		];

		if ( 'bricks.query-loop' === $type ) {
			$bricks = isset( $operation['bricks'] ) && is_array( $operation['bricks'] ) ? $operation['bricks'] : [];

			return array_merge(
				$base,
				[
					'adapterAction' => 'prepare-bricks-query-loop-settings',
					'authority'     => 'Bricks query-loop settings on a mapped Bricks element only; never raw page meta.',
					'requires'      => [
						'converted-or-existing-bricks-element-id',
						'confirmed-query-loop-object-type',
						'fresh-site-graph-taxonomy-slug-proof',
						'post-apply-rendered-proof',
					],
					'instruction'   => [
						'objectType' => (string) ( $bricks['objectType'] ?? '' ),
						'postType'   => $this->array_value( $bricks, 'postType' ),
						'taxQuery'   => $this->array_value( $bricks, 'taxQuery' ),
						'perPage'    => (int) ( $bricks['perPage'] ?? 0 ),
					],
				]
			);
		}

		if ( 'bricks.dynamic-field' === $type ) {
			return array_merge(
				$base,
				[
					'adapterAction' => 'prepare-bricks-dynamic-data-binding',
					'authority'     => 'Bricks dynamic-data setting on a mapped Bricks element only; never runtime JavaScript imitation.',
					'requires'      => [
						'converted-or-existing-bricks-element-id',
						'confirmed-bricks-dynamic-tag',
						'post-apply-rendered-proof',
					],
					'instruction'   => [
						'tag' => (string) ( $operation['tag'] ?? '' ),
					],
				]
			);
		}

		if ( 'kiwe.launcher-attribute' === $type ) {
			return array_merge(
				$base,
				[
					'adapterAction'                  => 'verify-or-preserve-kiwe-launcher-attribute',
					'authority'                      => 'Kiwe runtime owns launcher behavior; the page only supplies canonical attributes.',
					'requiresBricksElementIdMapping' => false,
					'requires'                       => [
						'canonical-data-dsa-open-module-attribute',
						'no-duplicate-launcher-javascript',
						'post-apply-runtime-proof',
					],
					'instruction'                    => [
						'attribute' => (string) ( $operation['attribute'] ?? 'data-dsa-open-module' ),
						'module'    => (string) ( $operation['module'] ?? '' ),
					],
				]
			);
		}

		if ( 'kiwe.menu-context' === $type ) {
			return array_merge(
				$base,
				[
					'adapterAction'                  => 'verify-or-preserve-seam-menu-context-source',
					'authority'                      => 'Kiwe menu context reads headings and Seam semantic sections from rendered page content.',
					'requiresBricksElementIdMapping' => false,
					'requires'                       => [
						'stable-rendered-section-anchor',
						'accessible-section-label-or-heading',
						'post-apply-scroll-proof',
					],
					'instruction'                    => [
						'selector' => $selector,
					],
				]
			);
		}

		return array_merge(
			$base,
			[
				'adapterAction' => 'manual-review-unsupported-operation',
				'authority'     => 'Unsupported operation type remains manual-review only.',
				'requires'      => [ 'new-adapter-mapping-before-mutation' ],
				'instruction'   => [],
			]
		);
	}

	private function blockers( array $stage, array $executor, array $approval, array $shell, array $target, array $apply_plan, array $operations, array $approved, array $prepared, string $plan_hash ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'kiwe.controlled-executor.v1' !== ( $executor['schema'] ?? '' ) || 'controlled-executor-skeleton-ready' !== ( $executor['status'] ?? '' ) ) {
			$blockers[] = 'Controlled executor skeleton is missing or blocked.';
		}
		if ( 'final-save-approved' !== ( $approval['status'] ?? '' ) ) {
			$blockers[] = 'Final save approval is missing or blocked.';
		}
		if ( 'minimal-adapter-shell-ready' !== ( $shell['status'] ?? '' ) ) {
			$blockers[] = 'Minimal adapter shell is missing or blocked.';
		}
		if ( 'target-resolution-ready' !== ( $target['status'] ?? '' ) ) {
			$blockers[] = 'Target resolution is missing or blocked.';
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Dry-run apply plan is missing.';
		}
		if ( '' === $plan_hash || $plan_hash !== (string) ( $executor['planHash'] ?? '' ) || $plan_hash !== (string) ( $approval['planHash'] ?? '' ) || $plan_hash !== (string) ( $shell['planHash'] ?? '' ) || $plan_hash !== (string) ( $target['planHash'] ?? '' ) ) {
			$blockers[] = 'Plan hash mismatch across adapter inputs.';
		}
		foreach ( $this->all_blockers( [ $stage, $executor, $approval, $shell, $target ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( [] === $operations ) {
			$blockers[] = 'Apply plan contains no operations.';
		}
		if ( [] === $approved ) {
			$blockers[] = 'No approved operation IDs are available for adapter mapping.';
		}
		if ( count( $approved ) !== count( $prepared ) ) {
			$blockers[] = 'Approved operation IDs could not all be mapped to adapter instructions.';
		}
		if ( ! empty( $executor['actualSaveExecuted'] ) || ! empty( $executor['mayExecuteMutationNow'] ) || ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $executor['mutatesWordPress'] ) || ! empty( $executor['mutatesBricksContent'] ) ) {
			$blockers[] = 'Upstream artifact claims mutation authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
	}

	private function gates( array $stage, array $executor, array $approval, array $shell, array $target, array $apply_plan, array $prepared, array $blockers ): array {
		return [
			[
				'id'      => 'controlled-executor',
				'label'   => 'Controlled executor skeleton ready',
				'status'  => 'kiwe.controlled-executor.v1' === ( $executor['schema'] ?? '' ) && 'controlled-executor-skeleton-ready' === ( $executor['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The adapter can only prepare instructions from the approved executor skeleton.',
			],
			[
				'id'      => 'exact-plan-hash',
				'label'   => 'Exact plan hash locked',
				'status'  => (string) ( $stage['plan']['hash'] ?? '' ) !== '' && (string) ( $stage['plan']['hash'] ?? '' ) === (string) ( $executor['planHash'] ?? '' ) && (string) ( $stage['plan']['hash'] ?? '' ) === (string) ( $approval['planHash'] ?? '' ) && (string) ( $stage['plan']['hash'] ?? '' ) === (string) ( $shell['planHash'] ?? '' ) && (string) ( $stage['plan']['hash'] ?? '' ) === (string) ( $target['planHash'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'No adapter step can drift from the dry-run plan already reviewed in Kiwe admin.',
			],
			[
				'id'      => 'bricks-ability-path',
				'label'   => 'Bricks ability/conversion path preferred',
				'status'  => 'passed',
				'details' => 'The adapter plan targets Bricks conversion/ability/save-pipeline concepts and keeps raw _bricks meta writes forbidden.',
			],
			[
				'id'      => 'operation-mapping',
				'label'   => 'Approved operations mapped',
				'status'  => [] !== $prepared ? 'passed' : 'blocked',
				'details' => 'Only approved operation IDs receive adapter instructions.',
			],
			[
				'id'      => 'mutation-locked',
				'label'   => 'Mutation remains locked',
				'status'  => 'passed',
				'details' => 'This adapter artifact is a controlled plan only; it does not save Bricks, WordPress, WooCommerce, or publish state.',
			],
			[
				'id'      => 'post-apply-proof-required',
				'label'   => 'Post-apply proof still required before save',
				'status'  => 'manual-review',
				'details' => 'The next batch must prove rendered output, rollback, and smallest-run behavior before mutation is enabled.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No adapter blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve blockers before building any executable adapter run.',
			],
		];
	}

	private function mutation_lock( int $target_id, string $plan_hash, array $approved, array $prepared ): array {
		return [
			'targetPostId'                         => $target_id,
			'planHash'                             => $plan_hash,
			'allowedOperationIds'                   => $approved,
			'preparedOperationCount'                => count( $prepared ),
			'allowedMutationNow'                    => false,
			'forbiddenUntilPostApplyProof'          => [
				'wp_update_post',
				'update_post_meta:_bricks*',
				'Bricks direct page-content meta write',
				'WooCommerce mutation',
				'publish',
			],
			'preferredFutureMutationRoute'          => [
				'Bricks abilities API / MCP route where available',
				'Bricks HTML/CSS conversion ability for greenfield page structure',
				'focused Bricks settings pass for query loops and dynamic data',
				'Bricks save pipeline or documented Bricks helper only after rollback proof',
			],
			'requiresBricksElementIdMapping'        => $this->has_bricks_operation( $prepared ),
			'requiresRollbackProofBeforeMutation'   => true,
			'requiresRenderedProofAfterMutation'    => true,
			'requiresImmediateRollbackCommandReady' => true,
		];
	}

	private function required_before_any_save(): array {
		return [
			'Reload stage by id immediately before mutation.',
			'Revalidate plan hash, target id, approved operation ids, and rollback snapshot.',
			'Resolve every Bricks operation to an existing or newly converted Bricks element id.',
			'Prepare the smallest executable mutation batch, preferably one page target and one operation family.',
			'Capture post-apply rendered proof and Kiwe audit output.',
			'Prove rollback can restore the captured snapshot if smoke tests fail.',
		];
	}

	private function approved_operation_ids( array $executor, array $approval, array $operations ): array {
		$ids = isset( $executor['approvedOperationIds'] ) && is_array( $executor['approvedOperationIds'] ) ? $executor['approvedOperationIds'] : [];
		if ( [] === $ids && isset( $approval['approvedOperationIds'] ) && is_array( $approval['approvedOperationIds'] ) ) {
			$ids = $approval['approvedOperationIds'];
		}
		if ( [] === $ids ) {
			foreach ( $operations as $operation ) {
				if ( is_array( $operation ) && '' !== (string) ( $operation['id'] ?? '' ) ) {
					$ids[] = (string) $operation['id'];
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
	}

	private function adapter_method( string $strategy_id ): string {
		if ( 'kiwe-runtime-only-review' === $strategy_id ) {
			return 'kiwe-runtime-contract-verification';
		}
		if ( 'html-css-to-bricks-import-review' === $strategy_id ) {
			return 'html-css-to-bricks-conversion-plan-plus-data-pass';
		}
		if ( 'manual-builder-fallback' === $strategy_id ) {
			return 'manual-builder-review-plan';
		}

		return 'bricks-abilities-controlled-plan';
	}

	private function selected_strategy_id( array $executor, array $approval, array $shell ): string {
		if ( '' !== (string) ( $executor['selectedStrategyId'] ?? '' ) ) {
			return (string) $executor['selectedStrategyId'];
		}
		if ( '' !== (string) ( $approval['selectedStrategyId'] ?? '' ) ) {
			return (string) $approval['selectedStrategyId'];
		}
		$strategy = isset( $shell['selectedStrategy'] ) && is_array( $shell['selectedStrategy'] ) ? $shell['selectedStrategy'] : [];

		return (string) ( $strategy['id'] ?? '' );
	}

	private function has_bricks_operation( array $prepared ): bool {
		foreach ( $prepared as $operation ) {
			if ( is_array( $operation ) && ! empty( $operation['requiresBricksElementIdMapping'] ) ) {
				return true;
			}
		}

		return false;
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
