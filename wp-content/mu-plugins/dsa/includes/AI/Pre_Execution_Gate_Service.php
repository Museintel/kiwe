<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Pre_Execution_Gate_Service {
	public function evaluate( array $stage, array $context = [] ): array {
		$proof         = isset( $stage['adapterProof'] ) && is_array( $stage['adapterProof'] ) ? $stage['adapterProof'] : [];
		$authorization = isset( $stage['applyAuthorization'] ) && is_array( $stage['applyAuthorization'] ) ? $stage['applyAuthorization'] : [];
		$apply_plan    = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$blockers      = $this->blockers( $stage, $proof, $authorization, $apply_plan );
		$created_at    = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id       = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id      = (string) ( $stage['id'] ?? '' );
		$plan_hash     = (string) ( $stage['plan']['hash'] ?? '' );

		return [
			'schema'               => 'kiwe.pre-execution-gate.v1',
			'id'                   => 'gate-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'              => $stage_id,
			'createdAt'            => $created_at,
			'createdBy'            => $user_id,
			'status'               => [] === $blockers ? 'ready-for-final-executor-build' : 'execution-gate-blocked',
			'planHash'             => $plan_hash,
			'authorizationId'      => (string) ( $authorization['id'] ?? '' ),
			'proofStatus'          => (string) ( $proof['status'] ?? '' ),
			'blockers'             => $blockers,
			'gates'                => $this->gates( $stage, $proof, $authorization, $apply_plan ),
			'mutatesWordPress'     => false,
			'mutatesBricksContent' => false,
			'requiredBeforeMutation' => [
				'captureRollbackOrRevision',
				'reconfirmAdminIntent',
				'renderPreviewBeforeSave',
				'executeSmallestPossibleAdapterMutation',
				'postApplyKiweAudit',
				'postApplyBrowserSmokeTest',
			],
			'futureExecutorContract' => [
				'mustRevalidateStageHash'     => true,
				'mustRevalidateAuthorization' => true,
				'mustCaptureRollback'         => true,
				'mustRenderBeforeSave'        => true,
				'mustAskFinalConfirmation'    => true,
				'forbiddenNow'                => [
					'bricks-save',
					'wordpress-post-update',
					'publish',
					'woocommerce-mutation',
					'custom-runtime-code',
				],
			],
		];
	}

	private function gates( array $stage, array $proof, array $authorization, array $apply_plan ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Only a staged Kiwe candidate can enter the pre-execution gate.',
			],
			[
				'id'      => 'apply-plan',
				'label'   => 'Dry-run apply plan available',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The executor must consume a reviewed dry-run plan.',
			],
			[
				'id'      => 'proof',
				'label'   => 'Trusted adapter proof ready',
				'status'  => 'adapter-proof-ready' === ( $proof['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Proof must be refreshed before authorization/gating.',
			],
			[
				'id'      => 'authorization',
				'label'   => 'Guarded authorization present',
				'status'  => 'authorized-for-future-adapter' === ( $authorization['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Admin authorization is required before any future executor may run.',
			],
			[
				'id'      => 'plan-hash',
				'label'   => 'Authorization plan hash matches stage',
				'status'  => (string) ( $authorization['planHash'] ?? '' ) === (string) ( $stage['plan']['hash'] ?? '' ) && '' !== (string) ( $stage['plan']['hash'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The authorized plan hash must match the currently staged plan.',
			],
			[
				'id'      => 'future-only',
				'label'   => 'Gate is future-only',
				'status'  => 'passed',
				'details' => 'This pre-execution gate does not mutate Bricks or WordPress.',
			],
		];
	}

	private function blockers( array $stage, array $proof, array $authorization, array $apply_plan ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		foreach ( $this->array_value( $stage, 'blockers' ) as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = 'Stage blocker: ' . $text;
			}
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Dry-run apply plan is missing.';
		}
		if ( 'adapter-proof-ready' !== ( $proof['status'] ?? '' ) ) {
			$blockers[] = 'Trusted adapter proof is not ready.';
		}
		foreach ( $this->array_value( $proof, 'blockers' ) as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = 'Proof blocker: ' . $text;
			}
		}
		if ( 'authorized-for-future-adapter' !== ( $authorization['status'] ?? '' ) ) {
			$blockers[] = 'Guarded authorization is missing or not ready.';
		}
		foreach ( $this->array_value( $authorization, 'blockers' ) as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = 'Authorization blocker: ' . $text;
			}
		}
		$stage_hash = (string) ( $stage['plan']['hash'] ?? '' );
		if ( '' === $stage_hash || $stage_hash !== (string) ( $authorization['planHash'] ?? '' ) ) {
			$blockers[] = 'Authorization plan hash does not match the staged plan.';
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $proof['mutatesWordPress'] ) || ! empty( $proof['mutatesBricksContent'] ) || ! empty( $authorization['mutatesWordPress'] ) || ! empty( $authorization['mutatesBricksContent'] ) ) {
			$blockers[] = 'Stage/proof/authorization claims mutation authority.';
		}

		return array_values( array_unique( $blockers ) );
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
