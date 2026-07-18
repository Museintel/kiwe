<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fresh_Site_Graph_Revalidator {
	public function revalidate( array $stage, array $site_graph, array $context = [] ): array {
		$confirmation = isset( $stage['finalConfirmation'] ) && is_array( $stage['finalConfirmation'] ) ? $stage['finalConfirmation'] : [];
		$apply_plan   = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$operations   = $this->array_value( $apply_plan, 'operations' );
		$blockers     = $this->blockers( $stage, $confirmation, $apply_plan, $site_graph, $operations );
		$warnings     = $this->warnings( $site_graph, $operations );
		$created_at   = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id      = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id     = (string) ( $stage['id'] ?? '' );
		$plan_hash    = (string) ( $stage['plan']['hash'] ?? '' );
		$graph_hash   = $this->stable_hash( $site_graph );

		return [
			'schema'               => 'kiwe.fresh-sitegraph-revalidation.v1',
			'id'                   => 'fresh-' . substr( hash( 'sha256', $stage_id . '|' . $plan_hash . '|' . $graph_hash . '|' . $created_at . '|' . $user_id ), 0, 16 ),
			'stageId'              => $stage_id,
			'createdAt'            => $created_at,
			'createdBy'            => $user_id,
			'status'               => [] === $blockers ? 'fresh-sitegraph-ready' : 'fresh-sitegraph-blocked',
			'planHash'             => $plan_hash,
			'confirmationId'       => (string) ( $confirmation['id'] ?? '' ),
			'siteGraphHash'        => $graph_hash,
			'siteGraphGeneratedAt' => (string) ( $site_graph['generatedAt'] ?? '' ),
			'site'                 => [
				'name'    => (string) ( $site_graph['site']['name'] ?? '' ),
				'homeUrl' => (string) ( $site_graph['site']['homeUrl'] ?? '' ),
			],
			'capabilities'         => [
				'bricksActive'             => ! empty( $site_graph['bricks']['active'] ),
				'bricksVersion'            => (string) ( $site_graph['bricks']['version'] ?? '' ),
				'htmlCssToBricksAvailable' => ! empty( $site_graph['bricks']['conversion']['htmlCssToBricksAvailable'] ),
				'wooActive'                => ! empty( $site_graph['woocommerce']['active'] ),
			],
			'counts'               => [
				'operations'        => count( $operations ),
				'bricksOperations'  => count( $this->bricks_operations( $operations ) ),
				'kiweOperations'    => count( $this->kiwe_operations( $operations ) ),
				'blockers'          => count( $blockers ),
				'warnings'          => count( $warnings ),
			],
			'gates'                => $this->gates( $stage, $confirmation, $apply_plan, $site_graph, $blockers ),
			'operationChecks'      => $this->operation_checks( $operations, $site_graph ),
			'warnings'             => $warnings,
			'blockers'             => $blockers,
			'mutatesWordPress'     => false,
			'mutatesBricksContent' => false,
			'nextRequiredStep'     => 'Build the rollback capture/checkpoint layer before any trusted adapter is allowed to mutate Bricks or WordPress.',
		];
	}

	private function gates( array $stage, array $confirmation, array $apply_plan, array $site_graph, array $blockers ): array {
		return [
			[
				'id'      => 'stage',
				'label'   => 'Trusted stage available',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Fresh Site Graph revalidation accepts only staged Kiwe candidates.',
			],
			[
				'id'      => 'apply-plan',
				'label'   => 'Dry-run apply plan available',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The current plan is rechecked against live site capabilities.',
			],
			[
				'id'      => 'final-confirmation',
				'label'   => 'Final confirmation present',
				'status'  => 'confirmed-for-future-adapter' === ( $confirmation['status'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'A human must confirm the exact execution preview before fresh revalidation.',
			],
			[
				'id'      => 'site-graph',
				'label'   => 'Fresh Site Graph schema',
				'status'  => 'kiwe.site-graph.v1' === ( $site_graph['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'The revalidation must use a live admin-generated Site Graph.',
			],
			[
				'id'      => 'bricks',
				'label'   => 'Bricks available for Bricks operations',
				'status'  => [] === $this->bricks_operations( $this->array_value( $apply_plan, 'operations' ) ) || ! empty( $site_graph['bricks']['active'] ) ? 'passed' : 'blocked',
				'details' => 'Bricks query-loop and dynamic-field operations require Bricks to be active.',
			],
			[
				'id'      => 'no-blockers',
				'label'   => 'No fresh blockers',
				'status'  => [] === $blockers ? 'passed' : 'blocked',
				'details' => 'Resolve fresh live-site blockers before any adapter can be built.',
			],
			[
				'id'      => 'non-mutating',
				'label'   => 'Revalidation does not mutate content',
				'status'  => 'passed',
				'details' => 'This batch inspects the current Site Graph only.',
			],
		];
	}

	private function blockers( array $stage, array $confirmation, array $apply_plan, array $site_graph, array $operations ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is invalid.';
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Dry-run apply plan is missing.';
		}
		if ( 'confirmed-for-future-adapter' !== ( $confirmation['status'] ?? '' ) ) {
			$blockers[] = 'Final apply confirmation is missing or blocked.';
		}
		$stage_hash = (string) ( $stage['plan']['hash'] ?? '' );
		if ( '' === $stage_hash || $stage_hash !== (string) ( $confirmation['planHash'] ?? '' ) ) {
			$blockers[] = 'Final confirmation plan hash does not match the staged plan.';
		}
		if ( 'kiwe.site-graph.v1' !== ( $site_graph['schema'] ?? '' ) ) {
			$blockers[] = 'Fresh Site Graph schema is invalid.';
		}
		if ( [] !== $this->bricks_operations( $operations ) && empty( $site_graph['bricks']['active'] ) ) {
			$blockers[] = 'Bricks operations are present but Bricks is not active in the fresh Site Graph.';
		}
		foreach ( $this->operation_checks( $operations, $site_graph ) as $check ) {
			if ( is_array( $check ) && 'blocked' === (string) ( $check['status'] ?? '' ) ) {
				$blockers[] = (string) ( $check['message'] ?? 'Fresh operation check failed.' );
			}
		}
		if ( ! empty( $stage['mutatesWordPress'] ) || ! empty( $stage['mutatesBricksContent'] ) || ! empty( $confirmation['mutatesWordPress'] ) || ! empty( $confirmation['mutatesBricksContent'] ) ) {
			$blockers[] = 'Stage or confirmation claims mutation authority.';
		}

		return array_values( array_unique( array_filter( $blockers ) ) );
	}

	private function warnings( array $site_graph, array $operations ): array {
		$warnings = [];
		if ( [] !== $this->bricks_operations( $operations ) && empty( $site_graph['bricks']['conversion']['htmlCssToBricksAvailable'] ) && empty( $site_graph['bricks']['conversion']['kiweFallbackAvailable'] ) ) {
			$warnings[] = 'Fresh Site Graph does not expose a Bricks HTML/CSS conversion signal; manual builder/import fallback may be required.';
		}
		if ( [] === $this->dynamic_tag_names( $site_graph ) ) {
			$warnings[] = 'Fresh Site Graph did not expose Bricks dynamic tags; dynamic-field checks are limited.';
		}

		return array_values( array_unique( $warnings ) );
	}

	private function operation_checks( array $operations, array $site_graph ): array {
		$checks = [];
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$type = (string) ( $operation['type'] ?? '' );
			if ( 'bricks.query-loop' === $type ) {
				$checks = array_merge( $checks, $this->query_operation_checks( $operation, $site_graph ) );
			} elseif ( 'bricks.dynamic-field' === $type ) {
				$checks[] = $this->dynamic_field_check( $operation, $site_graph );
			} elseif ( 'kiwe.launcher-attribute' === $type || 'kiwe.menu-context' === $type ) {
				$checks[] = [
					'id'      => (string) ( $operation['id'] ?? $type ),
					'type'    => $type,
					'status'  => 'passed',
					'message' => 'Kiwe runtime operation remains owned by Kiwe; no fresh Bricks mutation required.',
				];
			}
		}

		return $checks;
	}

	private function query_operation_checks( array $operation, array $site_graph ): array {
		$checks = [];
		$bricks = isset( $operation['bricks'] ) && is_array( $operation['bricks'] ) ? $operation['bricks'] : [];
		foreach ( $this->array_value( $bricks, 'postType' ) as $post_type ) {
			$post_type = (string) $post_type;
			if ( '' === $post_type ) {
				continue;
			}
			$checks[] = [
				'id'      => (string) ( $operation['id'] ?? 'query' ) . ':post-type:' . $post_type,
				'type'    => 'bricks.query-loop',
				'status'  => in_array( $post_type, $this->post_type_names( $site_graph ), true ) ? 'passed' : 'blocked',
				'message' => sprintf( 'Post type "%s" must exist in the fresh Site Graph.', $post_type ),
			];
		}
		foreach ( $this->array_value( $bricks, 'taxQuery' ) as $tax_ref ) {
			$tax_ref = (string) $tax_ref;
			if ( '' === $tax_ref || false === strpos( $tax_ref, '::' ) ) {
				continue;
			}
			[ $taxonomy, $term_id ] = explode( '::', $tax_ref, 2 );
			$checks[] = [
				'id'      => (string) ( $operation['id'] ?? 'query' ) . ':term:' . $tax_ref,
				'type'    => 'bricks.query-loop',
				'status'  => in_array( $taxonomy . '::' . absint( $term_id ), $this->taxonomy_term_refs( $site_graph ), true ) ? 'passed' : 'blocked',
				'message' => sprintf( 'Taxonomy term "%s" must exist in the fresh Site Graph sample.', $tax_ref ),
			];
		}

		return $checks;
	}

	private function dynamic_field_check( array $operation, array $site_graph ): array {
		$tag = (string) ( $operation['tag'] ?? '' );
		if ( '' === $tag || [] === $this->dynamic_tag_names( $site_graph ) ) {
			return [
				'id'      => (string) ( $operation['id'] ?? 'dynamic-field' ),
				'type'    => 'bricks.dynamic-field',
				'status'  => 'manual-review',
				'message' => 'Dynamic tag cannot be fully checked from the fresh Site Graph.',
			];
		}

		return [
			'id'      => (string) ( $operation['id'] ?? 'dynamic-field' ),
			'type'    => 'bricks.dynamic-field',
			'status'  => in_array( $tag, $this->dynamic_tag_names( $site_graph ), true ) ? 'passed' : 'blocked',
			'message' => sprintf( 'Dynamic tag "%s" must exist in the fresh Site Graph.', $tag ),
		];
	}

	private function post_type_names( array $site_graph ): array {
		$out = [];
		foreach ( $this->array_value( $site_graph['wordpress'] ?? [], 'postTypes' ) as $post_type ) {
			if ( is_array( $post_type ) && '' !== (string) ( $post_type['name'] ?? '' ) ) {
				$out[] = (string) $post_type['name'];
			}
		}

		return array_values( array_unique( $out ) );
	}

	private function taxonomy_term_refs( array $site_graph ): array {
		$out = [];
		foreach ( $this->array_value( $site_graph['wordpress'] ?? [], 'taxonomies' ) as $taxonomy ) {
			if ( ! is_array( $taxonomy ) ) {
				continue;
			}
			$name = (string) ( $taxonomy['name'] ?? '' );
			foreach ( $this->array_value( $taxonomy, 'terms' ) as $term ) {
				if ( is_array( $term ) && '' !== $name && ! empty( $term['id'] ) ) {
					$out[] = $name . '::' . absint( $term['id'] );
				}
			}
		}
		foreach ( $this->array_value( $site_graph['woocommerce'] ?? [], 'productCategories' ) as $term ) {
			if ( is_array( $term ) && ! empty( $term['id'] ) ) {
				$out[] = 'product_cat::' . absint( $term['id'] );
			}
		}
		foreach ( $this->array_value( $site_graph['woocommerce'] ?? [], 'productTags' ) as $term ) {
			if ( is_array( $term ) && ! empty( $term['id'] ) ) {
				$out[] = 'product_tag::' . absint( $term['id'] );
			}
		}

		return array_values( array_unique( $out ) );
	}

	private function dynamic_tag_names( array $site_graph ): array {
		$out = [];
		foreach ( $this->array_value( $site_graph['bricks'] ?? [], 'dynamicTags' ) as $tag ) {
			if ( is_array( $tag ) && '' !== (string) ( $tag['name'] ?? '' ) ) {
				$out[] = (string) $tag['name'];
			}
		}
		foreach ( $this->array_value( $site_graph['bricks'] ?? [], 'kiweDynamicTags' ) as $tag ) {
			$out[] = (string) $tag;
		}

		return array_values( array_unique( array_filter( $out ) ) );
	}

	private function bricks_operations( array $operations ): array {
		return array_values(
			array_filter(
				$operations,
				static fn( $operation ): bool => is_array( $operation ) && 0 === strpos( (string) ( $operation['type'] ?? '' ), 'bricks.' )
			)
		);
	}

	private function kiwe_operations( array $operations ): array {
		return array_values(
			array_filter(
				$operations,
				static fn( $operation ): bool => is_array( $operation ) && 0 === strpos( (string) ( $operation['type'] ?? '' ), 'kiwe.' )
			)
		);
	}

	private function stable_hash( array $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return hash( 'sha256', false !== $json ? $json : serialize( $value ) );
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
