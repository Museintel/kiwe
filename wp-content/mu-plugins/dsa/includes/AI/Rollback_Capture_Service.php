<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rollback_Capture_Service {
	public function capture( array $stage, array $context = [] ): array {
		$target_resolution = isset( $stage['targetResolution'] ) && is_array( $stage['targetResolution'] ) ? $stage['targetResolution'] : [];
		$rollback          = isset( $stage['rollbackReadinessCheckpoint'] ) && is_array( $stage['rollbackReadinessCheckpoint'] ) ? $stage['rollbackReadinessCheckpoint'] : [];
		$fresh             = isset( $stage['freshSiteGraphRevalidation'] ) && is_array( $stage['freshSiteGraphRevalidation'] ) ? $stage['freshSiteGraphRevalidation'] : [];
		$plan_hash         = (string) ( $stage['plan']['hash'] ?? '' );
		$target            = isset( $target_resolution['target'] ) && is_array( $target_resolution['target'] ) ? $target_resolution['target'] : [];
		$target_id         = isset( $target['id'] ) ? absint( $target['id'] ) : 0;
		$post              = $this->target_post( $target_id );
		$snapshot          = $post ? $this->snapshot( $post ) : [];
		$blockers          = $this->blockers( $stage, $target_resolution, $rollback, $fresh, $post, $snapshot, $plan_hash );
		$created_at        = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id           = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id          = (string) ( $stage['id'] ?? '' );
		$snapshot_hash     = [] === $snapshot ? '' : $this->stable_hash( $snapshot );
		$meta              = isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : [];

		return [
			'schema'                        => 'kiwe.rollback-capture.v1',
			'id'                            => 'capture-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . $target_id . '|' . $snapshot_hash . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'                       => $stage_id,
			'createdAt'                     => $created_at,
			'createdBy'                     => $user_id,
			'status'                        => [] === $blockers ? 'rollback-capture-ready' : 'rollback-capture-blocked',
			'planHash'                      => $plan_hash,
			'targetResolutionId'            => (string) ( $target_resolution['id'] ?? '' ),
			'rollbackCheckpointId'          => (string) ( $rollback['id'] ?? '' ),
			'freshRevalidationId'           => (string) ( $fresh['id'] ?? '' ),
			'targetPostId'                  => $target_id,
			'target'                        => $this->target_summary( $post, $target_id ),
			'snapshot'                      => $snapshot,
			'snapshotHash'                  => $snapshot_hash,
			'metaHash'                      => [] === $meta ? '' : $this->stable_hash( $meta ),
			'metaKeys'                      => array_keys( $meta ),
			'blockers'                      => $blockers,
			'gates'                         => $this->gates( $stage, $target_resolution, $rollback, $fresh, $post, $snapshot, $blockers ),
			'mutatesWordPress'              => false,
			'mutatesBricksContent'          => false,
			'writesKiweInternalRecord'      => true,
			'actualRollbackSnapshotCaptured' => [] === $blockers,
			'actualWordPressRevisionCreated' => false,
			'mayExecuteMutationNow'         => false,
			'nextRequiredStep'              => 'Inspect the rendered current target and build the minimal trusted adapter against this locked snapshot before any mutation.',
		];
	}

	private function gates( array $stage, array $target_resolution, array $rollback, array $fresh, $post, array $snapshot, array $blockers ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Rollback capture accepts only staged Kiwe candidates.',
			],
			[
				'id'      => 'fresh-sitegraph',
				'label'   => 'Fresh Site Graph revalidation ready',
				'status'  => 'fresh-sitegraph-ready' === ( $fresh['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The rollback capture must follow the latest live-site revalidation.',
			],
			[
				'id'      => 'rollback-readiness',
				'label'   => 'Rollback readiness checkpoint ready',
				'status'  => 'rollback-readiness-ready' === ( $rollback['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The capture is allowed only after readiness proves the required rollback chain.',
			],
			[
				'id'      => 'target-resolution',
				'label'   => 'Exact target resolved',
				'status'  => 'target-resolution-ready' === ( $target_resolution['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The snapshot must be tied to one resolved WordPress target.',
			],
			[
				'id'      => 'target-post',
				'label'   => 'Target post can be read',
				'status'  => is_object( $post ) ? 'passed' : 'blocked',
				'details' => 'Kiwe must be able to read the current target before capturing rollback data.',
			],
			[
				'id'      => 'snapshot',
				'label'   => 'Snapshot contains fields and relevant meta',
				'status'  => [] !== $snapshot ? 'passed' : 'blocked',
				'details' => 'Current WordPress fields plus Bricks/Kiwe/DSA meta are recorded into Kiwe internal staging.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No rollback capture blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve blockers before any trusted mutation adapter can be built.',
			],
			[
				'id'      => 'non-mutating',
				'label'   => 'Rollback capture does not mutate page content',
				'status'  => 'passed',
				'details' => 'This writes only the Kiwe staging record; it does not save Bricks, WordPress content, or publish state.',
			],
		];
	}

	private function blockers( array $stage, array $target_resolution, array $rollback, array $fresh, $post, array $snapshot, string $plan_hash ): array {
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
		if ( 'target-resolution-ready' !== ( $target_resolution['status'] ?? '' ) ) {
			$blockers[] = 'Target resolution is missing or blocked.';
		}
		if ( '' === $plan_hash || $plan_hash !== (string) ( $fresh['planHash'] ?? '' ) || $plan_hash !== (string) ( $rollback['planHash'] ?? '' ) || $plan_hash !== (string) ( $target_resolution['planHash'] ?? '' ) ) {
			$blockers[] = 'Plan hash mismatch across stage, fresh revalidation, rollback readiness, and target resolution.';
		}
		foreach ( $this->all_blockers( [ $stage, $fresh, $rollback, $target_resolution ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( ! is_object( $post ) ) {
			$blockers[] = 'Target post/page/template could not be read.';
		}
		if ( [] === $snapshot ) {
			$blockers[] = 'Rollback snapshot is empty.';
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $fresh['mutatesWordPress'] ) || ! empty( $fresh['mutatesBricksContent'] ) || ! empty( $rollback['mutatesWordPress'] ) || ! empty( $rollback['mutatesBricksContent'] ) || ! empty( $target_resolution['mutatesWordPress'] ) || ! empty( $target_resolution['mutatesBricksContent'] ) ) {
			$blockers[] = 'Stage/fresh revalidation/rollback readiness/target resolution claims mutation authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
	}

	private function snapshot( $post ): array {
		$post_id = isset( $post->ID ) ? absint( $post->ID ) : 0;
		if ( $post_id <= 0 ) {
			return [];
		}

		$fields = [
			'ID'                => $post_id,
			'post_type'         => (string) ( $post->post_type ?? '' ),
			'post_status'       => (string) ( $post->post_status ?? '' ),
			'post_title'        => (string) ( $post->post_title ?? '' ),
			'post_name'         => (string) ( $post->post_name ?? '' ),
			'post_content'      => (string) ( $post->post_content ?? '' ),
			'post_excerpt'      => (string) ( $post->post_excerpt ?? '' ),
			'post_parent'       => isset( $post->post_parent ) ? (int) $post->post_parent : 0,
			'menu_order'        => isset( $post->menu_order ) ? (int) $post->menu_order : 0,
			'comment_status'    => (string) ( $post->comment_status ?? '' ),
			'ping_status'       => (string) ( $post->ping_status ?? '' ),
			'post_modified_gmt' => (string) ( $post->post_modified_gmt ?? '' ),
		];

		return [
			'capturedAt' => gmdate( 'c' ),
			'fields'     => $fields,
			'url'        => function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '',
			'meta'       => $this->relevant_meta( $post_id ),
		];
	}

	private function relevant_meta( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return [];
		}
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return [];
		}
		$out = [];
		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( ! $this->is_relevant_meta_key( $key ) ) {
				continue;
			}
			$out[ $key ] = is_array( $values ) ? array_values( $values ) : [ $values ];
		}
		ksort( $out );

		return $out;
	}

	private function is_relevant_meta_key( string $key ): bool {
		if ( '_wp_page_template' === $key ) {
			return true;
		}
		foreach ( [ '_bricks', '_kiwe_', '_dsa_' ] as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private function target_post( int $target_id ) {
		if ( $target_id <= 0 || ! function_exists( 'get_post' ) ) {
			return null;
		}
		$post = get_post( $target_id );

		return is_object( $post ) ? $post : null;
	}

	private function target_summary( $post, int $target_id ): array {
		if ( ! is_object( $post ) ) {
			return [
				'id' => $target_id,
			];
		}

		return [
			'id'       => $target_id,
			'postType' => (string) ( $post->post_type ?? '' ),
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

	private function stable_hash( array $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return hash( 'sha256', false !== $json ? $json : serialize( $value ) );
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
