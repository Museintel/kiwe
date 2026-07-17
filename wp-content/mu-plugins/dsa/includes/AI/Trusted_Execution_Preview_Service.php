<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Trusted_Execution_Preview_Service {
	public function preview( array $stage, array $context = [] ): array {
		$gate          = isset( $stage['preExecutionGate'] ) && is_array( $stage['preExecutionGate'] ) ? $stage['preExecutionGate'] : [];
		$authorization = isset( $stage['applyAuthorization'] ) && is_array( $stage['applyAuthorization'] ) ? $stage['applyAuthorization'] : [];
		$proof         = isset( $stage['adapterProof'] ) && is_array( $stage['adapterProof'] ) ? $stage['adapterProof'] : [];
		$apply_plan    = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$operations    = $this->array_value( $apply_plan, 'operations' );
		$blockers      = $this->blockers( $stage, $gate, $authorization, $proof, $apply_plan );
		$created_at    = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id       = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id      = (string) ( $stage['id'] ?? '' );
		$plan_hash     = (string) ( $stage['plan']['hash'] ?? '' );

		return [
			'schema'               => 'kiwe.trusted-execution-preview.v1',
			'id'                   => 'preview-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'              => $stage_id,
			'createdAt'            => $created_at,
			'createdBy'            => $user_id,
			'status'               => [] === $blockers ? 'ready-for-final-confirmation' : 'execution-preview-blocked',
			'planHash'             => $plan_hash,
			'gateId'               => (string) ( $gate['id'] ?? '' ),
			'authorizationId'      => (string) ( $authorization['id'] ?? '' ),
			'blockers'             => $blockers,
			'mutationMode'         => 'preview-only',
			'mutatesWordPress'     => false,
			'mutatesBricksContent' => false,
			'gates'                => $this->gates( $stage, $gate, $authorization, $proof, $apply_plan ),
			'rollbackPlan'         => [
				'captureWordPressRevision',
				'captureBricksElementTreeSnapshot',
				'captureKiweSettingsSnapshot',
				'captureSourceHandoffAndBindingPlan',
				'verifyRollbackRestorePathBeforeSave',
			],
			'renderPreviewPlan'    => [
				'resolveTargetPageOrTemplate',
				'mapSelectorsToImportedBricksElements',
				'prepareQueryLoopAndDynamicTagDiff',
				'renderBeforeSavePreview',
				'compareViewportDesktopTabletMobile',
				'confirmKiweLaunchersAndMenuContextStillWork',
			],
			'operationPreview'     => $this->operation_preview( $operations ),
			'finalConfirmationContract' => [
				'mustShowTargetPage'       => true,
				'mustShowOperationCounts'  => true,
				'mustShowRollbackState'    => true,
				'mustShowRenderedPreview'  => true,
				'mustRequireHumanConfirm'  => true,
				'mustRefuseIfStageChanged' => true,
			],
			'postApplyAuditPlan'   => [
				'runKiweOutputAudit',
				'runBindingValidationAgainstFreshSiteGraph',
				'runBrowserSmokeTestForLaunchersAndMenuContext',
				'verifyNoDuplicateCartSearchAuthCheckoutRuntime',
				'verifyRollbackStillAvailable',
			],
			'forbiddenNow'         => [
				'bricks-save',
				'wordpress-post-update',
				'publish',
				'woocommerce-mutation',
				'custom-runtime-code',
			],
		];
	}

	private function gates( array $stage, array $gate, array $authorization, array $proof, array $apply_plan ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Execution preview accepts only staged Kiwe apply candidates.',
			],
			[
				'id'      => 'apply-plan',
				'label'   => 'Dry-run apply plan available',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The future executor must consume the reviewed dry-run apply plan.',
			],
			[
				'id'      => 'proof',
				'label'   => 'Trusted adapter proof ready',
				'status'  => 'adapter-proof-ready' === ( $proof['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Proof remains part of the preview chain.',
			],
			[
				'id'      => 'authorization',
				'label'   => 'Guarded authorization ready',
				'status'  => 'authorized-for-future-adapter' === ( $authorization['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Authorization remains future-only.',
			],
			[
				'id'      => 'pre-execution-gate',
				'label'   => 'Pre-execution gate ready',
				'status'  => 'ready-for-final-executor-build' === ( $gate['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The preview can be built only after the pre-execution gate passes.',
			],
			[
				'id'      => 'plan-hash',
				'label'   => 'Gate/authorization hash matches stage',
				'status'  => $this->hashes_match( $stage, $gate, $authorization ) ? 'passed' : 'blocked',
				'details' => 'The preview must refuse stale gates or authorizations.',
			],
			[
				'id'      => 'preview-only',
				'label'   => 'Execution preview does not mutate content',
				'status'  => 'passed',
				'details' => 'This artifact previews execution requirements only.',
			],
		];
	}

	private function blockers( array $stage, array $gate, array $authorization, array $proof, array $apply_plan ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Dry-run apply plan is missing.';
		}
		if ( 'adapter-proof-ready' !== ( $proof['status'] ?? '' ) ) {
			$blockers[] = 'Trusted adapter proof is not ready.';
		}
		if ( 'authorized-for-future-adapter' !== ( $authorization['status'] ?? '' ) ) {
			$blockers[] = 'Guarded authorization is missing or not ready.';
		}
		if ( 'ready-for-final-executor-build' !== ( $gate['status'] ?? '' ) ) {
			$blockers[] = 'Pre-execution gate is missing or blocked.';
		}
		foreach ( $this->array_value( $stage, 'blockers' ) as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = 'Stage blocker: ' . $text;
			}
		}
		foreach ( $this->array_value( $proof, 'blockers' ) as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = 'Proof blocker: ' . $text;
			}
		}
		foreach ( $this->array_value( $authorization, 'blockers' ) as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = 'Authorization blocker: ' . $text;
			}
		}
		foreach ( $this->array_value( $gate, 'blockers' ) as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = 'Gate blocker: ' . $text;
			}
		}
		if ( ! $this->hashes_match( $stage, $gate, $authorization ) ) {
			$blockers[] = 'Plan hash mismatch across stage, authorization, and gate.';
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $proof['mutatesWordPress'] ) || ! empty( $proof['mutatesBricksContent'] ) || ! empty( $authorization['mutatesWordPress'] ) || ! empty( $authorization['mutatesBricksContent'] ) || ! empty( $gate['mutatesWordPress'] ) || ! empty( $gate['mutatesBricksContent'] ) ) {
			$blockers[] = 'Stage/proof/authorization/gate claims mutation authority.';
		}

		return array_values( array_unique( $blockers ) );
	}

	private function hashes_match( array $stage, array $gate, array $authorization ): bool {
		$stage_hash = (string) ( $stage['plan']['hash'] ?? '' );
		if ( '' === $stage_hash ) {
			return false;
		}

		return $stage_hash === (string) ( $gate['planHash'] ?? '' ) && $stage_hash === (string) ( $authorization['planHash'] ?? '' );
	}

	private function operation_preview( array $operations ): array {
		$out = [];
		foreach ( $operations as $position => $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$type = (string) ( $operation['type'] ?? '' );
			$out[] = [
				'id'                 => (string) ( $operation['id'] ?? 'operation-' . $position ),
				'type'               => $type,
				'label'              => (string) ( $operation['label'] ?? $operation['id'] ?? 'Operation' ),
				'selector'           => (string) ( $operation['selector'] ?? '' ),
				'currentStatus'      => (string) ( $operation['status'] ?? '' ),
				'futureAuthority'    => $this->authority_for_type( $type ),
				'previewRequirement' => $this->preview_requirement_for_type( $type ),
				'mutationNow'        => false,
			];
		}

		return $out;
	}

	private function authority_for_type( string $type ): string {
		if ( 'bricks.query-loop' === $type || 'bricks.dynamic-field' === $type ) {
			return 'future-kiwe-bricks-adapter';
		}
		if ( 'kiwe.launcher-attribute' === $type || 'kiwe.menu-context' === $type ) {
			return 'kiwe-runtime-preservation';
		}

		return 'manual-review';
	}

	private function preview_requirement_for_type( string $type ): string {
		if ( 'bricks.query-loop' === $type ) {
			return 'Preview real query-loop results against the target Site Graph and rendered Bricks tree.';
		}
		if ( 'bricks.dynamic-field' === $type ) {
			return 'Preview resolved dynamic tags before saving the builder tree.';
		}
		if ( 'kiwe.launcher-attribute' === $type ) {
			return 'Smoke-test canonical Kiwe launcher open/close behavior after import.';
		}
		if ( 'kiwe.menu-context' === $type ) {
			return 'Smoke-test Kiwe Menu context scroll behavior against visible page sections.';
		}

		return 'Review manually before any future mutation.';
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
