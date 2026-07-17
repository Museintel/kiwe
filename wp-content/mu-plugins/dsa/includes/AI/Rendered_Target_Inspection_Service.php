<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rendered_Target_Inspection_Service {
	public function inspect( array $stage, array $context = [] ): array {
		$capture           = isset( $stage['rollbackCapture'] ) && is_array( $stage['rollbackCapture'] ) ? $stage['rollbackCapture'] : [];
		$target_resolution = isset( $stage['targetResolution'] ) && is_array( $stage['targetResolution'] ) ? $stage['targetResolution'] : [];
		$apply_plan        = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$operations        = $this->array_value( $apply_plan, 'operations' );
		$plan_hash         = (string) ( $stage['plan']['hash'] ?? '' );
		$snapshot          = isset( $capture['snapshot'] ) && is_array( $capture['snapshot'] ) ? $capture['snapshot'] : [];
		$fields            = isset( $snapshot['fields'] ) && is_array( $snapshot['fields'] ) ? $snapshot['fields'] : [];
		$meta              = isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : [];
		$post_content      = (string) ( $fields['post_content'] ?? '' );
		$meta_text         = $this->meta_text( $meta );
		$blockers          = $this->blockers( $stage, $target_resolution, $capture, $snapshot, $plan_hash );
		$created_at        = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id           = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id          = (string) ( $stage['id'] ?? '' );
		$coverage          = $this->operation_coverage( $operations, $post_content, $meta_text );
		$bricks_summary    = $this->bricks_summary( $meta );
		$warnings          = $this->warnings( $operations, $coverage, $bricks_summary );

		return [
			'schema'                      => 'kiwe.rendered-target-inspection.v1',
			'id'                          => 'rendered-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . (string) ( $capture['snapshotHash'] ?? '' ) . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'                     => $stage_id,
			'createdAt'                   => $created_at,
			'createdBy'                   => $user_id,
			'status'                      => [] === $blockers ? 'rendered-target-inspection-ready' : 'rendered-target-inspection-blocked',
			'planHash'                    => $plan_hash,
			'targetResolutionId'          => (string) ( $target_resolution['id'] ?? '' ),
			'rollbackCaptureId'           => (string) ( $capture['id'] ?? '' ),
			'targetPostId'                => isset( $capture['targetPostId'] ) ? absint( $capture['targetPostId'] ) : absint( $target_resolution['target']['id'] ?? 0 ),
			'inspectionMode'              => 'baseline-snapshot',
			'actualBrowserRenderCaptured' => false,
			'actualBricksSavePerformed'   => false,
			'baseline'                    => [
				'postContentLength' => strlen( $post_content ),
				'postContentHash'   => '' === $post_content ? '' : hash( 'sha256', $post_content ),
				'metaHash'          => (string) ( $capture['metaHash'] ?? '' ),
				'snapshotHash'      => (string) ( $capture['snapshotHash'] ?? '' ),
				'url'               => (string) ( $snapshot['url'] ?? $target_resolution['target']['url'] ?? '' ),
			],
			'bricks'                      => $bricks_summary,
			'operationSelectorCoverage'   => $coverage,
			'warnings'                    => $warnings,
			'blockers'                    => $blockers,
			'gates'                       => $this->gates( $stage, $target_resolution, $capture, $snapshot, $blockers ),
			'mutatesWordPress'            => false,
			'mutatesBricksContent'        => false,
			'writesKiweInternalRecord'    => true,
			'mayExecuteMutationNow'       => false,
			'nextRequiredStep'            => 'Build the minimal trusted adapter shell against this inspected baseline, then perform browser/render smoke before any reviewed save.',
		];
	}

	private function gates( array $stage, array $target_resolution, array $capture, array $snapshot, array $blockers ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Rendered target inspection accepts only staged Kiwe candidates.',
			],
			[
				'id'      => 'target-resolution',
				'label'   => 'Exact target resolved',
				'status'  => 'target-resolution-ready' === ( $target_resolution['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Inspection must be tied to one locked WordPress target.',
			],
			[
				'id'      => 'rollback-capture',
				'label'   => 'Rollback capture ready',
				'status'  => 'rollback-capture-ready' === ( $capture['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Inspection must use the exact restore snapshot that protects the target.',
			],
			[
				'id'      => 'snapshot',
				'label'   => 'Baseline snapshot available',
				'status'  => [] !== $snapshot ? 'passed' : 'blocked',
				'details' => 'Kiwe inspects the captured target fields and Bricks/Kiwe/DSA meta without saving.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No rendered inspection blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve blockers before a minimal adapter shell can be built.',
			],
			[
				'id'      => 'non-mutating',
				'label'   => 'Rendered inspection does not mutate content',
				'status'  => 'passed',
				'details' => 'This records inspection metadata only; it does not save Bricks, WordPress content, or publish state.',
			],
		];
	}

	private function blockers( array $stage, array $target_resolution, array $capture, array $snapshot, string $plan_hash ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'target-resolution-ready' !== ( $target_resolution['status'] ?? '' ) ) {
			$blockers[] = 'Target resolution is missing or blocked.';
		}
		if ( 'rollback-capture-ready' !== ( $capture['status'] ?? '' ) ) {
			$blockers[] = 'Rollback capture is missing or blocked.';
		}
		if ( '' === $plan_hash || $plan_hash !== (string) ( $target_resolution['planHash'] ?? '' ) || $plan_hash !== (string) ( $capture['planHash'] ?? '' ) ) {
			$blockers[] = 'Plan hash mismatch across stage, target resolution, and rollback capture.';
		}
		foreach ( $this->all_blockers( [ $stage, $target_resolution, $capture ] ) as $blocker ) {
			$blockers[] = $blocker;
		}
		if ( [] === $snapshot ) {
			$blockers[] = 'Rollback snapshot is unavailable for rendered target inspection.';
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $target_resolution['mutatesWordPress'] ) || ! empty( $target_resolution['mutatesBricksContent'] ) || ! empty( $capture['mutatesWordPress'] ) || ! empty( $capture['mutatesBricksContent'] ) ) {
			$blockers[] = 'Stage/target resolution/rollback capture claims mutation authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
	}

	private function operation_coverage( array $operations, string $post_content, string $meta_text ): array {
		$out = [];
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$selector = (string) ( $operation['selector'] ?? '' );
			$type     = (string) ( $operation['type'] ?? '' );
			$id       = (string) ( $operation['id'] ?? '' );
			$tokens   = $this->selector_tokens( $selector );
			$found_in_post  = $this->tokens_found( $tokens, $post_content );
			$found_in_meta  = $this->tokens_found( $tokens, $meta_text );
			$out[] = [
				'id'                => $id,
				'type'              => $type,
				'selector'          => $selector,
				'tokens'            => $tokens,
				'foundInContent'    => $found_in_post,
				'foundInBricksMeta' => $found_in_meta,
				'coverage'          => $found_in_post || $found_in_meta ? 'existing-target-match' : ( '' === $selector ? 'not-selector-based' : 'not-present-in-baseline' ),
				'blocking'          => false,
				'note'              => $found_in_post || $found_in_meta ? 'Selector appears in the current baseline.' : 'Selector is absent from the current baseline; this is acceptable for first import/new content, but the adapter must map it after conversion.',
			];
		}

		return $out;
	}

	private function selector_tokens( string $selector ): array {
		$tokens = [];
		if ( '' === $selector ) {
			return [];
		}
		if ( preg_match_all( '/#([A-Za-z0-9_-]+)/', $selector, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$tokens[] = 'id="' . $match . '"';
				$tokens[] = "'id':'" . $match . "'";
				$tokens[] = '"id":"' . $match . '"';
			}
		}
		if ( preg_match_all( '/\\.([A-Za-z0-9_-]+)/', $selector, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$tokens[] = $match;
			}
		}
		if ( preg_match_all( "/\\[([^\\]=]+)(?:=[\"']?([^\"'\\]]+)[\"']?)?\\]/", $selector, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attr  = trim( (string) ( $match[1] ?? '' ) );
				$value = trim( (string) ( $match[2] ?? '' ) );
				if ( '' !== $attr ) {
					$tokens[] = $attr;
				}
				if ( '' !== $value ) {
					$tokens[] = $value;
				}
			}
		}

		return array_values( array_unique( array_filter( $tokens ) ) );
	}

	private function tokens_found( array $tokens, string $haystack ): bool {
		if ( [] === $tokens || '' === $haystack ) {
			return false;
		}
		foreach ( $tokens as $token ) {
			if ( '' !== (string) $token && false !== strpos( $haystack, (string) $token ) ) {
				return true;
			}
		}

		return false;
	}

	private function bricks_summary( array $meta ): array {
		$keys          = [];
		$decoded_count = 0;
		$element_count = 0;
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( 0 !== strpos( $key, '_bricks' ) ) {
				continue;
			}
			$keys[] = $key;
			foreach ( is_array( $values ) ? $values : [ $values ] as $value ) {
				$decoded = json_decode( (string) $value, true );
				if ( is_array( $decoded ) ) {
					$decoded_count++;
					$element_count += $this->count_bricks_nodes( $decoded );
				}
			}
		}

		return [
			'metaKeys'              => array_values( array_unique( $keys ) ),
			'decodedJsonPayloads'   => $decoded_count,
			'estimatedElementNodes' => $element_count,
			'hasBricksMeta'         => [] !== $keys,
		];
	}

	private function count_bricks_nodes( array $value ): int {
		$count = 0;
		if ( isset( $value['id'] ) || isset( $value['name'] ) || isset( $value['settings'] ) ) {
			$count++;
		}
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$count += $this->count_bricks_nodes( $child );
			}
		}

		return $count;
	}

	private function warnings( array $operations, array $coverage, array $bricks_summary ): array {
		$warnings = [];
		$bricks_ops = array_filter(
			$operations,
			static fn( $operation ): bool => is_array( $operation ) && 0 === strpos( (string) ( $operation['type'] ?? '' ), 'bricks.' )
		);
		if ( [] !== $bricks_ops && empty( $bricks_summary['hasBricksMeta'] ) ) {
			$warnings[] = 'Target baseline has no Bricks meta. This may be a first import or a non-Bricks page; future adapter must map after conversion/import.';
		}
		$missing = 0;
		foreach ( $coverage as $item ) {
			if ( is_array( $item ) && 'not-present-in-baseline' === ( $item['coverage'] ?? '' ) ) {
				$missing++;
			}
		}
		if ( $missing > 0 ) {
			$warnings[] = sprintf( '%d operation selector(s) are not present in the current baseline. This is not blocking for new content, but must be resolved by adapter mapping before save.', $missing );
		}

		return $warnings;
	}

	private function meta_text( array $meta ): string {
		$parts = [];
		foreach ( $meta as $key => $values ) {
			$parts[] = (string) $key;
			foreach ( is_array( $values ) ? $values : [ $values ] as $value ) {
				$parts[] = (string) $value;
			}
		}

		return implode( "\n", $parts );
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
