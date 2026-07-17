<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rollback_Readiness_Checkpoint_Service {
	public function checkpoint( array $stage, array $context = [] ): array {
		$confirmation = isset( $stage['finalConfirmation'] ) && is_array( $stage['finalConfirmation'] ) ? $stage['finalConfirmation'] : [];
		$fresh        = isset( $stage['freshSiteGraphRevalidation'] ) && is_array( $stage['freshSiteGraphRevalidation'] ) ? $stage['freshSiteGraphRevalidation'] : [];
		$preview      = isset( $stage['executionPreview'] ) && is_array( $stage['executionPreview'] ) ? $stage['executionPreview'] : [];
		$apply_plan   = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$blockers     = $this->blockers( $stage, $confirmation, $fresh, $preview, $apply_plan );
		$created_at   = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id      = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id     = (string) ( $stage['id'] ?? '' );
		$plan_hash    = (string) ( $stage['plan']['hash'] ?? '' );
		$fresh_hash   = (string) ( $fresh['siteGraphHash'] ?? '' );

		return [
			'schema'                  => 'kiwe.rollback-readiness-checkpoint.v1',
			'id'                      => 'rollback-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . $fresh_hash . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'                 => $stage_id,
			'createdAt'               => $created_at,
			'createdBy'               => $user_id,
			'status'                  => [] === $blockers ? 'rollback-readiness-ready' : 'rollback-readiness-blocked',
			'planHash'                => $plan_hash,
			'confirmationId'          => (string) ( $confirmation['id'] ?? '' ),
			'freshRevalidationId'     => (string) ( $fresh['id'] ?? '' ),
			'siteGraphHash'           => $fresh_hash,
			'blockers'                => $blockers,
			'gates'                   => $this->gates( $stage, $confirmation, $fresh, $preview, $apply_plan, $blockers ),
			'artifactHashes'          => [
				'stage'             => $this->stable_hash( $stage ),
				'applyPlan'         => $this->stable_hash( $apply_plan ),
				'executionPreview'  => $this->stable_hash( $preview ),
				'finalConfirmation' => $this->stable_hash( $confirmation ),
				'freshRevalidation' => $this->stable_hash( $fresh ),
			],
			'requiredRollbackCapture' => [
				'wordpressRevisionOrBackup',
				'bricksElementTreeBeforeSave',
				'bricksSettingsOrTemplateBeforeSave',
				'kiweSettingsSnapshot',
				'siteGraphSnapshot',
				'sourceHandoffAndBindingPlan',
				'rollbackRestoreInstructions',
			],
			'captureMode'             => 'readiness-only',
			'actualRevisionCaptured'   => false,
			'readyForRollbackCapture'  => [] === $blockers,
			'mutatesWordPress'         => false,
			'mutatesBricksContent'     => false,
			'mayExecuteMutationNow'    => false,
			'nextRequiredStep'         => 'Resolve the exact target page/template and capture a real rollback/revision point immediately before any trusted adapter mutation.',
		];
	}

	private function gates( array $stage, array $confirmation, array $fresh, array $preview, array $apply_plan, array $blockers ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Rollback readiness accepts only staged Kiwe candidates.',
			],
			[
				'id'      => 'apply-plan',
				'label'   => 'Dry-run apply plan available',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Rollback readiness is tied to the reviewed dry-run apply plan.',
			],
			[
				'id'      => 'execution-preview',
				'label'   => 'Execution preview ready',
				'status'  => 'ready-for-final-confirmation' === ( $preview['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Rollback readiness must match the reviewed preview scope.',
			],
			[
				'id'      => 'final-confirmation',
				'label'   => 'Final confirmation attached',
				'status'  => 'confirmed-for-future-adapter' === ( $confirmation['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Human confirmation must exist before rollback readiness.',
			],
			[
				'id'      => 'fresh-sitegraph',
				'label'   => 'Fresh Site Graph revalidation ready',
				'status'  => 'fresh-sitegraph-ready' === ( $fresh['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Rollback readiness must be based on the current live Site Graph.',
			],
			[
				'id'      => 'plan-hash',
				'label'   => 'Plan hash matches confirmation and fresh revalidation',
				'status'  => $this->hashes_match( $stage, $confirmation, $fresh ) ? 'passed' : 'blocked',
				'details' => 'Rollback readiness must refuse stale confirmations or revalidations.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No rollback readiness blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve blockers before target resolution or real rollback capture.',
			],
			[
				'id'      => 'non-mutating',
				'label'   => 'Readiness checkpoint does not mutate content',
				'status'  => 'passed',
				'details' => 'This checkpoint records rollback requirements only.',
			],
		];
	}

	private function blockers( array $stage, array $confirmation, array $fresh, array $preview, array $apply_plan ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Dry-run apply plan is missing.';
		}
		if ( 'ready-for-final-confirmation' !== ( $preview['status'] ?? '' ) ) {
			$blockers[] = 'Execution preview is missing or blocked.';
		}
		if ( 'confirmed-for-future-adapter' !== ( $confirmation['status'] ?? '' ) ) {
			$blockers[] = 'Final apply confirmation is missing or blocked.';
		}
		if ( 'fresh-sitegraph-ready' !== ( $fresh['status'] ?? '' ) ) {
			$blockers[] = 'Fresh Site Graph revalidation is missing or blocked.';
		}
		foreach ( $this->all_blockers( [ $stage, $confirmation, $fresh, $preview ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( ! $this->hashes_match( $stage, $confirmation, $fresh ) ) {
			$blockers[] = 'Plan hash mismatch across stage, confirmation, and fresh revalidation.';
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $confirmation['mutatesWordPress'] ) || ! empty( $confirmation['mutatesBricksContent'] ) || ! empty( $fresh['mutatesWordPress'] ) || ! empty( $fresh['mutatesBricksContent'] ) || ! empty( $preview['mutatesWordPress'] ) || ! empty( $preview['mutatesBricksContent'] ) ) {
			$blockers[] = 'Stage/confirmation/fresh revalidation/preview claims mutation authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
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

	private function hashes_match( array $stage, array $confirmation, array $fresh ): bool {
		$stage_hash = (string) ( $stage['plan']['hash'] ?? '' );
		if ( '' === $stage_hash ) {
			return false;
		}

		return $stage_hash === (string) ( $confirmation['planHash'] ?? '' )
			&& $stage_hash === (string) ( $fresh['planHash'] ?? '' );
	}

	private function stable_hash( array $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return hash( 'sha256', false !== $json ? $json : serialize( $value ) );
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
