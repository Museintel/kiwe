<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Final_Apply_Confirmation_Service {
	public function confirm( array $stage, array $context = [] ): array {
		$proof         = isset( $stage['adapterProof'] ) && is_array( $stage['adapterProof'] ) ? $stage['adapterProof'] : [];
		$authorization = isset( $stage['applyAuthorization'] ) && is_array( $stage['applyAuthorization'] ) ? $stage['applyAuthorization'] : [];
		$gate          = isset( $stage['preExecutionGate'] ) && is_array( $stage['preExecutionGate'] ) ? $stage['preExecutionGate'] : [];
		$preview       = isset( $stage['executionPreview'] ) && is_array( $stage['executionPreview'] ) ? $stage['executionPreview'] : [];
		$apply_plan    = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$explicit      = ! empty( $context['explicitAdminConfirmation'] );
		$blockers      = $this->blockers( $stage, $proof, $authorization, $gate, $preview, $apply_plan, $explicit );
		$created_at    = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id       = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id      = (string) ( $stage['id'] ?? '' );
		$plan_hash     = (string) ( $stage['plan']['hash'] ?? '' );
		$preview_id    = (string) ( $preview['id'] ?? '' );

		return [
			'schema'                    => 'kiwe.final-apply-confirmation.v1',
			'id'                        => 'confirm-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . $preview_id . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'                   => $stage_id,
			'createdAt'                 => $created_at,
			'createdBy'                 => $user_id,
			'status'                    => [] === $blockers ? 'confirmed-for-future-adapter' : 'final-confirmation-blocked',
			'planHash'                  => $plan_hash,
			'previewId'                 => $preview_id,
			'gateId'                    => (string) ( $gate['id'] ?? '' ),
			'authorizationId'           => (string) ( $authorization['id'] ?? '' ),
			'explicitAdminConfirmation' => $explicit,
			'blockers'                  => $blockers,
			'gates'                     => $this->gates( $stage, $proof, $authorization, $gate, $preview, $apply_plan, $explicit ),
			'mutatesWordPress'          => false,
			'mutatesBricksContent'      => false,
			'confirmedScope'            => [
				'stageId'         => $stage_id,
				'planHash'        => $plan_hash,
				'previewId'       => $preview_id,
				'operationCount'  => count( isset( $preview['operationPreview'] ) && is_array( $preview['operationPreview'] ) ? $preview['operationPreview'] : [] ),
				'rollbackPlan'    => isset( $preview['rollbackPlan'] ) && is_array( $preview['rollbackPlan'] ) ? $preview['rollbackPlan'] : [],
				'renderPreview'   => isset( $preview['renderPreviewPlan'] ) && is_array( $preview['renderPreviewPlan'] ) ? $preview['renderPreviewPlan'] : [],
				'postApplyAudit'  => isset( $preview['postApplyAuditPlan'] ) && is_array( $preview['postApplyAuditPlan'] ) ? $preview['postApplyAuditPlan'] : [],
			],
			'futureAdapterContract'     => [
				'mustRevalidateConfirmation' => true,
				'mustRevalidateFreshSiteGraph' => true,
				'mustRevalidatePreviewId'     => true,
				'mustRevalidatePlanHash'      => true,
				'mustCaptureRollback'         => true,
				'mustRenderBeforeSave'        => true,
				'mustRunPostApplyAudit'       => true,
			],
			'mayBuildMutationAdapter'    => [] === $blockers,
			'mayExecuteMutationNow'      => false,
			'forbiddenNow'               => [
				'bricks-save',
				'wordpress-post-update',
				'publish',
				'woocommerce-mutation',
				'custom-runtime-code',
			],
		];
	}

	private function gates( array $stage, array $proof, array $authorization, array $gate, array $preview, array $apply_plan, bool $explicit ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Only staged Kiwe apply candidates can be confirmed.',
			],
			[
				'id'      => 'apply-plan',
				'label'   => 'Dry-run apply plan available',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The confirmed scope is tied to the reviewed dry-run apply plan.',
			],
			[
				'id'      => 'proof',
				'label'   => 'Trusted adapter proof ready',
				'status'  => 'adapter-proof-ready' === ( $proof['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Proof must remain clean at confirmation time.',
			],
			[
				'id'      => 'authorization',
				'label'   => 'Guarded authorization ready',
				'status'  => 'authorized-for-future-adapter' === ( $authorization['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Authorization remains future-only and does not save content.',
			],
			[
				'id'      => 'pre-execution-gate',
				'label'   => 'Pre-execution gate ready',
				'status'  => 'ready-for-final-executor-build' === ( $gate['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The final confirmation must be gated.',
			],
			[
				'id'      => 'execution-preview',
				'label'   => 'Execution preview reviewed',
				'status'  => 'ready-for-final-confirmation' === ( $preview['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The final confirmation is bound to the current execution preview.',
			],
			[
				'id'      => 'plan-hash',
				'label'   => 'Plan hash matches all prior locks',
				'status'  => $this->hashes_match( $stage, $authorization, $gate, $preview ) ? 'passed' : 'blocked',
				'details' => 'The final confirmation must refuse stale stages, authorizations, gates, or previews.',
			],
			[
				'id'      => 'explicit-admin-confirmation',
				'label'   => 'Admin explicitly confirmed this preview',
				'status'  => $explicit ? 'passed' : 'blocked',
				'details' => 'A checked human confirmation is required before the future adapter path can continue.',
			],
			[
				'id'      => 'non-mutating-confirmation',
				'label'   => 'Confirmation does not mutate content',
				'status'  => 'passed',
				'details' => 'This confirmation records approval only; it does not save Bricks or WordPress data.',
			],
		];
	}

	private function blockers( array $stage, array $proof, array $authorization, array $gate, array $preview, array $apply_plan, bool $explicit ): array {
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
		if ( 'ready-for-final-confirmation' !== ( $preview['status'] ?? '' ) ) {
			$blockers[] = 'Execution preview is missing or blocked.';
		}
		if ( ! $explicit ) {
			$blockers[] = 'Explicit admin confirmation was not checked.';
		}
		foreach ( $this->all_blockers( [ $stage, $proof, $authorization, $gate, $preview ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( ! $this->hashes_match( $stage, $authorization, $gate, $preview ) ) {
			$blockers[] = 'Plan hash mismatch across stage, authorization, gate, and preview.';
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $proof['mutatesWordPress'] ) || ! empty( $proof['mutatesBricksContent'] ) || ! empty( $authorization['mutatesWordPress'] ) || ! empty( $authorization['mutatesBricksContent'] ) || ! empty( $gate['mutatesWordPress'] ) || ! empty( $gate['mutatesBricksContent'] ) || ! empty( $preview['mutatesWordPress'] ) || ! empty( $preview['mutatesBricksContent'] ) ) {
			$blockers[] = 'Stage/proof/authorization/gate/preview claims mutation authority.';
		}

		return array_values( array_unique( $blockers ) );
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

	private function hashes_match( array $stage, array $authorization, array $gate, array $preview ): bool {
		$stage_hash = (string) ( $stage['plan']['hash'] ?? '' );
		if ( '' === $stage_hash ) {
			return false;
		}

		return $stage_hash === (string) ( $authorization['planHash'] ?? '' )
			&& $stage_hash === (string) ( $gate['planHash'] ?? '' )
			&& $stage_hash === (string) ( $preview['planHash'] ?? '' );
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
