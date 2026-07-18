<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Apply_Plan_Preparer {
	public function prepare( array $binding, array $site_graph, array $validation ): array {
		$capabilities = $this->site_capabilities( $site_graph );
		$operations   = array_merge(
			$this->query_operations( $binding, $capabilities ),
			$this->dynamic_field_operations( $binding, $capabilities ),
			$this->launcher_operations( $binding ),
			$this->menu_context_operations( $binding )
		);

		return [
			'schema'           => 'kiwe.bricks-apply-plan.v1',
			'target'           => [
				'builder'              => 'bricks',
				'mode'                 => 'dry-run-apply-plan',
				'applyAuthority'       => 'admin-approved-kiwe-bricks-adapter',
				'mutatesWordPress'     => false,
				'mutatesBricksContent' => false,
			],
			'siteCapabilities' => $capabilities,
			'safety'           => [
				'dryRunOnly'                  => true,
				'mutatesWordPress'            => false,
				'requiresAdminApprovalBeforeSave' => true,
				'requiresRevisionBeforeSave'  => true,
				'forbidden'                   => [
					'direct-ai-write',
					'direct-ai-save',
					'direct-ai-publish',
					'front-end-runtime-mutation',
					'unreviewed-builder-mutation',
				],
			],
			'preflight'        => $this->preflight( $validation, $capabilities ),
			'operations'       => $operations,
			'manualReview'     => $this->manual_review( $binding, $validation ),
			'applySequence'    => [
				'Capture a WordPress revision or staging backup.',
				'Confirm the binding validation report has no failures.',
				'Review each prepared Bricks query loop, dynamic field, Kiwe launcher, and menu-context operation.',
				'Let a trusted Kiwe/Bricks adapter translate approved operations into builder changes.',
				'Run the Kiwe audit loop again after any future apply path saves changes.',
			],
			'limitations'      => [
				'This plan is a WordPress admin preview only; it does not call Bricks save APIs.',
				'Site Graph samples may omit rare terms, post types, or dynamic tags that exist outside the sample window.',
				'Human review remains required before any future trusted adapter writes to Bricks or WordPress.',
			],
		];
	}

	private function site_capabilities( array $site_graph ): array {
		$bricks     = isset( $site_graph['bricks'] ) && is_array( $site_graph['bricks'] ) ? $site_graph['bricks'] : [];
		$abilities  = isset( $bricks['abilities'] ) && is_array( $bricks['abilities'] ) ? $bricks['abilities'] : [];
		$conversion = isset( $bricks['conversion'] ) && is_array( $bricks['conversion'] ) ? $bricks['conversion'] : [];
		$active     = ! empty( $bricks['active'] );
		$trusted    = $active && ( ! empty( $abilities['bricksAbilityManager'] ) || ! empty( $abilities['bricksAbilityManagerPresent'] ) || ! empty( $abilities['wpAbilitiesApiPresent'] ) || ! empty( $abilities['mcpLikelyAvailable'] ) );

		return [
			'bricksActive'                => $active,
			'bricksVersion'               => (string) ( $bricks['version'] ?? '' ),
			'wordpressAbilitiesApiPresent' => ! empty( $abilities['wpAbilitiesApiPresent'] ),
			'bricksAbilityManager'        => ! empty( $abilities['bricksAbilityManager'] ) || ! empty( $abilities['bricksAbilityManagerPresent'] ),
			'bricksMcpLikelyAvailable'    => ! empty( $abilities['mcpLikelyAvailable'] ),
			'htmlCssToBricksAvailable'    => ! empty( $conversion['htmlCssToBricksAvailable'] ),
			'bricksNativeConverterAvailable' => ! empty( $conversion['bricksNativeAvailable'] ),
			'kiweFallbackConverterAvailable' => ! empty( $conversion['kiweFallbackAvailable'] ),
			'trustedAdapterLikelyAvailable' => $trusted,
			'manualBuilderFallback'       => true,
		];
	}

	private function preflight( array $validation, array $capabilities ): array {
		return [
			[
				'id'      => 'validate-bindings',
				'label'   => 'Validate kiwe-bindings.json against the live Site Graph.',
				'status'  => ! empty( $validation['ok'] ) ? 'passed' : 'blocked',
				'details' => ! empty( $validation['ok'] ) ? 'No validation failures were found.' : 'Fix validation failures before any apply path.',
			],
			[
				'id'      => 'capture-revision',
				'label'   => 'Capture a revision or staging backup before future mutation.',
				'status'  => 'manual-review',
				'details' => 'Kiwe does not mutate builder content from this dry run.',
			],
			[
				'id'      => 'html-css-to-bricks',
				'label'   => 'Confirm Bricks HTML/CSS import/conversion path.',
				'status'  => ! empty( $capabilities['htmlCssToBricksAvailable'] ) ? 'available' : 'manual-builder-fallback',
				'details' => ! empty( $capabilities['htmlCssToBricksAvailable'] ) ? ( ! empty( $capabilities['bricksNativeConverterAvailable'] ) ? 'Bricks native HTML/CSS conversion appears available.' : 'Kiwe controlled fallback conversion appears available for staging page/template creation.' ) : 'Use manual Bricks review if conversion is unavailable.',
			],
			[
				'id'      => 'trusted-adapter',
				'label'   => 'Confirm trusted Kiwe/Bricks adapter authority.',
				'status'  => ! empty( $capabilities['trustedAdapterLikelyAvailable'] ) ? 'available' : 'manual-builder-fallback',
				'details' => ! empty( $capabilities['trustedAdapterLikelyAvailable'] ) ? 'Abilities/MCP signals suggest a trusted adapter path may be available.' : 'No trusted adapter signal was detected; keep this as a manual builder checklist.',
			],
			[
				'id'      => 'post-apply-audit',
				'label'   => 'Run Kiwe audit after any future apply/save.',
				'status'  => 'required-after-apply',
				'details' => 'The audit loop remains mandatory after mutation, even if this dry-run preview passes.',
			],
		];
	}

	private function query_operations( array $binding, array $capabilities ): array {
		$operations = [];
		foreach ( $this->array_value( $binding, 'queries' ) as $position => $query ) {
			if ( ! is_array( $query ) ) {
				continue;
			}
			$id           = $this->operation_id( 'query', (string) ( $query['id'] ?? $position ) );
			$bricks       = isset( $query['bricks'] ) && is_array( $query['bricks'] ) ? $query['bricks'] : [];
			$operations[] = [
				'id'       => $id,
				'type'     => 'bricks.query-loop',
				'label'    => (string) ( $query['label'] ?? $id ),
				'selector' => (string) ( $query['selector'] ?? '' ),
				'status'   => $this->operation_status( $capabilities ),
				'bricks'   => [
					'objectType' => (string) ( $bricks['objectType'] ?? '' ),
					'postType'   => $this->array_value( $bricks, 'post_type' ),
					'taxQuery'   => $this->array_value( $bricks, 'tax_query' ),
					'perPage'    => (int) ( $bricks['posts_per_page'] ?? 0 ),
				],
			];
		}

		return $operations;
	}

	private function dynamic_field_operations( array $binding, array $capabilities ): array {
		$operations = [];
		foreach ( $this->array_value( $binding, 'dynamicFields' ) as $position => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$id           = $this->operation_id( 'dynamic', (string) ( $field['id'] ?? $position ) );
			$operations[] = [
				'id'       => $id,
				'type'     => 'bricks.dynamic-field',
				'label'    => (string) ( $field['label'] ?? $id ),
				'selector' => (string) ( $field['selector'] ?? '' ),
				'tag'      => (string) ( $field['tag'] ?? '' ),
				'status'   => $this->operation_status( $capabilities ),
			];
		}

		return $operations;
	}

	private function launcher_operations( array $binding ): array {
		$operations = [];
		foreach ( $this->array_value( $binding, 'launchers' ) as $position => $launcher ) {
			if ( ! is_array( $launcher ) ) {
				continue;
			}
			$module       = (string) ( $launcher['module'] ?? '' );
			$id           = $this->operation_id( 'launcher', (string) ( $launcher['id'] ?? $module ?: $position ) );
			$operations[] = [
				'id'        => $id,
				'type'      => 'kiwe.launcher-attribute',
				'label'     => (string) ( $launcher['label'] ?? $module ?: $id ),
				'selector'  => (string) ( $launcher['selector'] ?? '' ),
				'module'    => $module,
				'attribute' => 'data-dsa-open-module',
				'status'    => 'ready-existing-kiwe-runtime',
			];
		}

		return $operations;
	}

	private function menu_context_operations( array $binding ): array {
		$operations = [];
		foreach ( $this->array_value( $binding, 'menuContext' ) as $position => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id           = $this->operation_id( 'menu', (string) ( $item['id'] ?? $position ) );
			$operations[] = [
				'id'       => $id,
				'type'     => 'kiwe.menu-context',
				'label'    => (string) ( $item['label'] ?? $id ),
				'selector' => (string) ( $item['selector'] ?? '' ),
				'status'   => 'ready-existing-kiwe-runtime',
			];
		}

		return $operations;
	}

	private function manual_review( array $binding, array $validation ): array {
		$items = [];
		foreach ( $this->array_value( $binding, 'requiresHumanReview' ) as $position => $item ) {
			$text = is_array( $item ) ? (string) ( $item['message'] ?? $item['label'] ?? '' ) : (string) $item;
			if ( '' !== $text ) {
				$items[] = [
					'id'      => $this->operation_id( 'review', (string) $position ),
					'source'  => 'binding.requiresHumanReview',
					'message' => $text,
				];
			}
		}

		foreach ( $this->array_value( $validation, 'findings' ) as $position => $finding ) {
			if ( ! is_array( $finding ) || 'warn' !== (string) ( $finding['level'] ?? '' ) ) {
				continue;
			}
			$message = (string) ( $finding['message'] ?? '' );
			if ( '' !== $message ) {
				$items[] = [
					'id'      => $this->operation_id( 'validator-warning', (string) $position ),
					'source'  => 'binding.validator',
					'message' => $message,
				];
			}
		}

		return $items;
	}

	private function operation_status( array $capabilities ): string {
		if ( empty( $capabilities['bricksActive'] ) ) {
			return 'manual-review-no-bricks';
		}
		if ( ! empty( $capabilities['trustedAdapterLikelyAvailable'] ) ) {
			return 'ready-for-trusted-adapter-review';
		}

		return 'ready-for-manual-bricks-review';
	}

	private function operation_id( string $prefix, string $id ): string {
		$id = strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $id ) ?? '' );
		$id = trim( $id, '-' );

		return $prefix . ':' . ( '' !== $id ? $id : 'item' );
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
