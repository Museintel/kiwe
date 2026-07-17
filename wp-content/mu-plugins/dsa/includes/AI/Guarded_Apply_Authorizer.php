<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Guarded_Apply_Authorizer {
	public function authorize( array $stage, array $context = [] ): array {
		$proof        = isset( $stage['adapterProof'] ) && is_array( $stage['adapterProof'] ) ? $stage['adapterProof'] : [];
		$stage_blocks = $this->array_value( $stage, 'blockers' );
		$proof_blocks = $this->array_value( $proof, 'blockers' );
		$blockers     = $this->blockers( $stage, $proof, $stage_blocks, $proof_blocks );
		$plan_hash    = (string) ( $stage['plan']['hash'] ?? '' );
		$created_at   = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id      = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$auth_id      = 'auth-' . substr( hash( 'sha256', (string) ( $stage['id'] ?? '' ) . '|' . $plan_hash . '|' . $created_at . '|' . $user_id ), 0, 16 );

		return [
			'schema'               => 'kiwe.guarded-apply-authorization.v1',
			'id'                   => $auth_id,
			'stageId'              => (string) ( $stage['id'] ?? '' ),
			'createdAt'            => $created_at,
			'createdBy'            => $user_id,
			'status'               => [] === $blockers ? 'authorized-for-future-adapter' : 'authorization-blocked',
			'planHash'             => $plan_hash,
			'proofStatus'          => (string) ( $proof['status'] ?? '' ),
			'gates'                => $this->gates( $stage, $proof, $stage_blocks, $proof_blocks ),
			'blockers'             => $blockers,
			'mutatesWordPress'     => false,
			'mutatesBricksContent' => false,
			'authority'            => [
				'authorizedFor' => 'future-admin-approved-kiwe-bricks-adapter',
				'authorizedNow' => 'review-token-only',
				'forbiddenNow'  => [
					'bricks-save',
					'wordpress-post-update',
					'publish',
					'woocommerce-mutation',
					'custom-runtime-code',
				],
			],
			'nextRequiredStep'      => 'Implement a trusted adapter executor that revalidates this authorization, captures rollback/revision state, renders a preview, asks final admin confirmation, then performs the smallest possible Bricks mutation.',
		];
	}

	private function gates( array $stage, array $proof, array $stage_blocks, array $proof_blocks ): array {
		return [
			[
				'id'      => 'stage-schema',
				'label'   => 'Trusted stage schema',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Only Kiwe trusted apply stages can be authorized.',
			],
			[
				'id'      => 'stage-clean',
				'label'   => 'Stage has no blockers',
				'status'  => [] === $stage_blocks ? 'passed' : 'blocked',
				'details' => 'Resolve stage blockers before authorization.',
			],
			[
				'id'      => 'proof-schema',
				'label'   => 'Trusted adapter proof present',
				'status'  => 'kiwe.trusted-adapter-proof.v1' === ( $proof['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Run adapter proof before authorization.',
			],
			[
				'id'      => 'proof-ready',
				'label'   => 'Adapter proof is ready',
				'status'  => 'adapter-proof-ready' === ( $proof['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Proof must be ready and current before authorization.',
			],
			[
				'id'      => 'proof-clean',
				'label'   => 'Proof has no blockers',
				'status'  => [] === $proof_blocks ? 'passed' : 'blocked',
				'details' => 'Resolve proof blockers before authorization.',
			],
			[
				'id'      => 'future-only',
				'label'   => 'Authorization is future-only',
				'status'  => 'passed',
				'details' => 'This authorization does not save Bricks or WordPress data.',
			],
		];
	}

	private function blockers( array $stage, array $proof, array $stage_blocks, array $proof_blocks ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is not kiwe.trusted-apply-stage.v1.';
		}
		foreach ( $stage_blocks as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = 'Stage blocker: ' . $text;
			}
		}
		if ( 'kiwe.trusted-adapter-proof.v1' !== ( $proof['schema'] ?? '' ) ) {
			$blockers[] = 'Trusted adapter proof is missing.';
		}
		if ( 'adapter-proof-ready' !== ( $proof['status'] ?? '' ) ) {
			$blockers[] = 'Trusted adapter proof is not ready.';
		}
		foreach ( $proof_blocks as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = 'Proof blocker: ' . $text;
			}
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $proof['mutatesWordPress'] ) || ! empty( $proof['mutatesBricksContent'] ) ) {
			$blockers[] = 'Stage/proof claims mutation authority.';
		}

		return array_values( array_unique( $blockers ) );
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
