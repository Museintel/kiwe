<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Trusted_Adapter_Proof_Service {
	public function prove( array $stage, array $site_graph ): array {
		$capabilities = $this->site_capabilities( $site_graph );
		$apply_plan   = isset( $stage['applyPlan'] ) && is_array( $stage['applyPlan'] ) ? $stage['applyPlan'] : [];
		$operations   = $this->array_value( $apply_plan, 'operations' );
		$blockers     = $this->blockers( $stage, $apply_plan, $capabilities );
		$gates        = $this->gates( $stage, $apply_plan, $capabilities, $blockers );
		$plan_hash    = (string) ( $stage['plan']['hash'] ?? '' );

		return [
			'schema'               => 'kiwe.trusted-adapter-proof.v1',
			'stageId'              => (string) ( $stage['id'] ?? '' ),
			'createdAt'            => gmdate( 'c' ),
			'status'               => [] === $blockers ? 'adapter-proof-ready' : 'adapter-proof-blocked',
			'planHash'             => $plan_hash,
			'mutatesWordPress'     => false,
			'mutatesBricksContent' => false,
			'capabilities'         => $capabilities,
			'gates'                => $gates,
			'blockers'             => $blockers,
			'operationMap'         => $this->operation_map( $operations, $capabilities ),
			'futureApplyContract'  => [
				'requiresExplicitAdminApproval' => true,
				'requiresRevisionBeforeSave'    => true,
				'requiresRenderedPreview'        => true,
				'requiresPostApplyAudit'         => true,
				'allowedFutureAuthority'         => 'admin-approved-kiwe-bricks-adapter',
				'forbiddenNow'                   => [
					'bricks-save',
					'wordpress-post-update',
					'publish',
					'woocommerce-mutation',
					'custom-runtime-code',
				],
			],
		];
	}

	private function site_capabilities( array $site_graph ): array {
		$bricks     = isset( $site_graph['bricks'] ) && is_array( $site_graph['bricks'] ) ? $site_graph['bricks'] : [];
		$abilities  = isset( $bricks['abilities'] ) && is_array( $bricks['abilities'] ) ? $bricks['abilities'] : [];
		$conversion = isset( $bricks['conversion'] ) && is_array( $bricks['conversion'] ) ? $bricks['conversion'] : [];

		$bricks_active       = ! empty( $bricks['active'] );
		$wp_abilities        = ! empty( $abilities['wpAbilitiesApiPresent'] );
		$bricks_manager      = ! empty( $abilities['bricksAbilityManager'] ) || ! empty( $abilities['bricksAbilityManagerPresent'] );
		$mcp_likely          = ! empty( $abilities['mcpLikelyAvailable'] );
		$html_to_bricks      = ! empty( $conversion['htmlCssToBricksAvailable'] );
		$trusted_signal      = $bricks_active && ( $wp_abilities || $bricks_manager || $mcp_likely );

		return [
			'bricksActive'                  => $bricks_active,
			'bricksVersion'                 => (string) ( $bricks['version'] ?? '' ),
			'wordpressAbilitiesApiPresent'  => $wp_abilities,
			'bricksAbilityManager'          => $bricks_manager,
			'bricksMcpLikelyAvailable'      => $mcp_likely,
			'htmlCssToBricksAvailable'      => $html_to_bricks,
			'trustedAdapterSignalAvailable' => $trusted_signal,
			'manualBuilderFallback'         => true,
		];
	}

	private function gates( array $stage, array $apply_plan, array $capabilities, array $blockers ): array {
		$safety = isset( $apply_plan['safety'] ) && is_array( $apply_plan['safety'] ) ? $apply_plan['safety'] : [];

		return [
			[
				'id'      => 'stage-schema',
				'label'   => 'Stage schema',
				'status'  => 'kiwe.trusted-apply-stage.v1' === ( $stage['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Adapter proof only accepts Kiwe trusted apply stages.',
			],
			[
				'id'      => 'stage-clean',
				'label'   => 'Stage has no blockers',
				'status'  => empty( $stage['blockers'] ) ? 'passed' : 'blocked',
				'details' => 'Stage blockers must be resolved before a future apply action.',
			],
			[
				'id'      => 'apply-plan-schema',
				'label'   => 'Dry-run apply plan present',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'A stage must include the reviewed dry-run apply plan.',
			],
			[
				'id'      => 'bricks-active',
				'label'   => 'Bricks active on target site',
				'status'  => ! empty( $capabilities['bricksActive'] ) ? 'passed' : 'blocked',
				'details' => 'A Bricks apply adapter cannot run without Bricks active on the target site.',
			],
			[
				'id'      => 'adapter-signal',
				'label'   => 'Trusted adapter signal',
				'status'  => ! empty( $capabilities['trustedAdapterSignalAvailable'] ) ? 'passed' : 'manual-review',
				'details' => 'Abilities/MCP signals are helpful, but manual builder fallback remains possible.',
			],
			[
				'id'      => 'html-css-conversion',
				'label'   => 'HTML/CSS to Bricks path',
				'status'  => ! empty( $capabilities['htmlCssToBricksAvailable'] ) ? 'passed' : 'manual-review',
				'details' => 'If conversion is unavailable, a future adapter must use manual Bricks review/import.',
			],
			[
				'id'      => 'admin-approval',
				'label'   => 'Admin approval still required',
				'status'  => ! empty( $safety['requiresAdminApprovalBeforeSave'] ) ? 'passed' : 'manual-review',
				'details' => 'This proof is not approval to save.',
			],
			[
				'id'      => 'proof-non-mutating',
				'label'   => 'Proof did not mutate content',
				'status'  => 'passed',
				'details' => 'This batch only produces adapter proof metadata.',
			],
		];
	}

	private function blockers( array $stage, array $apply_plan, array $capabilities ): array {
		$blockers = [];
		if ( 'kiwe.trusted-apply-stage.v1' !== ( $stage['schema'] ?? '' ) ) {
			$blockers[] = 'Stage schema is not kiwe.trusted-apply-stage.v1.';
		}
		foreach ( $this->array_value( $stage, 'blockers' ) as $blocker ) {
			$text = (string) $blocker;
			if ( '' !== $text ) {
				$blockers[] = $text;
			}
		}
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Stage is missing a valid kiwe.bricks-apply-plan.v1 payload.';
		}
		$target = isset( $apply_plan['target'] ) && is_array( $apply_plan['target'] ) ? $apply_plan['target'] : [];
		if ( ! empty( $target['mutatesWordPress'] ) || ! empty( $target['mutatesBricksContent'] ) ) {
			$blockers[] = 'Apply plan claims mutation authority.';
		}
		if ( empty( $capabilities['bricksActive'] ) ) {
			$blockers[] = 'Bricks is not active on the target site.';
		}

		return array_values( array_unique( $blockers ) );
	}

	private function operation_map( array $operations, array $capabilities ): array {
		$map = [];
		foreach ( $operations as $position => $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$type = (string) ( $operation['type'] ?? '' );
			$needs_bricks = str_starts_with( $type, 'bricks.' );
			$map[] = [
				'id'             => (string) ( $operation['id'] ?? 'operation-' . $position ),
				'type'           => $type,
				'label'          => (string) ( $operation['label'] ?? $operation['id'] ?? '' ),
				'selector'       => (string) ( $operation['selector'] ?? '' ),
				'authority'      => $needs_bricks ? 'bricks-adapter-required' : 'kiwe-runtime-owned',
				'status'         => $needs_bricks && empty( $capabilities['bricksActive'] ) ? 'blocked-no-bricks' : 'mapped-for-future-review',
				'mutationNow'    => false,
				'reviewRequired' => true,
			];
		}

		return $map;
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
