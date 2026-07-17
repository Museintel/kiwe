<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Target_Resolution_Service {
	public function resolve( array $stage, array $context = [] ): array {
		$rollback   = isset( $stage['rollbackReadinessCheckpoint'] ) && is_array( $stage['rollbackReadinessCheckpoint'] ) ? $stage['rollbackReadinessCheckpoint'] : [];
		$fresh      = isset( $stage['freshSiteGraphRevalidation'] ) && is_array( $stage['freshSiteGraphRevalidation'] ) ? $stage['freshSiteGraphRevalidation'] : [];
		$plan_hash  = (string) ( $stage['plan']['hash'] ?? '' );
		$target_id  = isset( $context['targetPostId'] ) ? absint( $context['targetPostId'] ) : 0;
		$target     = $this->target_post( $target_id );
		$blockers   = $this->blockers( $stage, $rollback, $fresh, $target, $plan_hash );
		$created_at = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id    = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id   = (string) ( $stage['id'] ?? '' );

		return [
			'schema'               => 'kiwe.target-resolution.v1',
			'id'                   => 'target-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . $target_id . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'              => $stage_id,
			'createdAt'            => $created_at,
			'createdBy'            => $user_id,
			'status'               => [] === $blockers ? 'target-resolution-ready' : 'target-resolution-blocked',
			'planHash'             => $plan_hash,
			'rollbackCheckpointId' => (string) ( $rollback['id'] ?? '' ),
			'freshRevalidationId'  => (string) ( $fresh['id'] ?? '' ),
			'target'               => $target,
			'blockers'             => $blockers,
			'gates'                => $this->gates( $stage, $rollback, $fresh, $target, $blockers ),
			'mutatesWordPress'     => false,
			'mutatesBricksContent' => false,
			'allowedFutureScope'   => [
				'targetPostId' => (int) ( $target['id'] ?? 0 ),
				'postType'     => (string) ( $target['postType'] ?? '' ),
				'url'          => (string) ( $target['url'] ?? '' ),
				'planHash'     => $plan_hash,
			],
			'nextRequiredStep'     => 'Capture a real rollback/revision snapshot for this exact target before any future adapter mutation.',
		];
	}

	private function gates( array $stage, array $rollback, array $fresh, array $target, array $blockers ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Target resolution accepts only staged Kiwe candidates.',
			],
			[
				'id'      => 'fresh-sitegraph',
				'label'   => 'Fresh Site Graph revalidation ready',
				'status'  => 'fresh-sitegraph-ready' === ( $fresh['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The target must be resolved against the current live site.',
			],
			[
				'id'      => 'rollback-readiness',
				'label'   => 'Rollback readiness checkpoint ready',
				'status'  => 'rollback-readiness-ready' === ( $rollback['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Target resolution happens after the artifact chain is rollback-ready.',
			],
			[
				'id'      => 'target',
				'label'   => 'Exact target resolved',
				'status'  => ! empty( $target['id'] ) ? 'passed' : 'blocked',
				'details' => 'A future adapter must be scoped to one explicit WordPress target.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No target blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve target blockers before real rollback capture.',
			],
			[
				'id'      => 'non-mutating',
				'label'   => 'Target resolution does not mutate content',
				'status'  => 'passed',
				'details' => 'This checkpoint locks target scope only.',
			],
		];
	}

	private function blockers( array $stage, array $rollback, array $fresh, array $target, string $plan_hash ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'fresh-sitegraph-ready' !== ( $fresh['status'] ?? '' ) ) {
			$blockers[] = 'Fresh Site Graph revalidation is missing or blocked.';
		}
		if ( 'rollback-readiness-ready' !== ( $rollback['status'] ?? '' ) ) {
			$blockers[] = 'Rollback readiness checkpoint is missing or blocked.';
		}
		if ( '' === $plan_hash || $plan_hash !== (string) ( $fresh['planHash'] ?? '' ) || $plan_hash !== (string) ( $rollback['planHash'] ?? '' ) ) {
			$blockers[] = 'Plan hash mismatch across stage, fresh revalidation, and rollback readiness.';
		}
		foreach ( $this->all_blockers( [ $stage, $fresh, $rollback ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( empty( $target['id'] ) ) {
			$blockers[] = 'Target post/page/template id is missing or invalid.';
		}
		if ( ! empty( $target['postType'] ) && in_array( (string) $target['postType'], [ 'attachment', 'revision', 'nav_menu_item' ], true ) ) {
			$blockers[] = 'Target post type is not allowed for a Bricks apply adapter.';
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $fresh['mutatesWordPress'] ) || ! empty( $fresh['mutatesBricksContent'] ) || ! empty( $rollback['mutatesWordPress'] ) || ! empty( $rollback['mutatesBricksContent'] ) ) {
			$blockers[] = 'Stage/fresh revalidation/rollback readiness claims mutation authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
	}

	private function target_post( int $target_id ): array {
		if ( $target_id <= 0 || ! function_exists( 'get_post' ) ) {
			return [];
		}
		$post = get_post( $target_id );
		if ( ! is_object( $post ) ) {
			return [];
		}
		$post_type = (string) ( $post->post_type ?? '' );

		return [
			'id'       => $target_id,
			'postType' => $post_type,
			'status'   => (string) ( $post->post_status ?? '' ),
			'title'    => function_exists( 'get_the_title' ) ? (string) get_the_title( $target_id ) : (string) ( $post->post_title ?? '' ),
			'url'      => function_exists( 'get_permalink' ) ? (string) get_permalink( $target_id ) : '',
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
