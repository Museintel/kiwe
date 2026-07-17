<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Trusted_Apply_Stager {
	private const OPTION = 'dsa_trusted_apply_stages';
	private const MAX_RECORDS = 8;

	public function stage( array $apply_plan, array $context = [] ): array {
		$record  = $this->build_record( $apply_plan, $context );
		$records = $this->records();
		array_unshift( $records, $record );
		$records = array_slice( $this->dedupe_records( $records ), 0, self::MAX_RECORDS );

		update_option( self::OPTION, $records, false );

		return $record;
	}

	public function records(): array {
		$records = get_option( self::OPTION, [] );
		if ( ! is_array( $records ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$records,
				static fn( $record ): bool => is_array( $record ) && 'kiwe.trusted-apply-stage.v1' === ( $record['schema'] ?? '' )
			)
		);
	}

	public function find( string $id ): array {
		if ( '' === $id ) {
			return [];
		}
		foreach ( $this->records() as $record ) {
			if ( is_array( $record ) && $id === (string) ( $record['id'] ?? '' ) ) {
				return $record;
			}
		}

		return [];
	}

	public function attach_proof( string $id, array $proof ): array {
		$records = $this->records();
		$updated = [];
		$matched = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( $id === (string) ( $record['id'] ?? '' ) ) {
				$record['adapterProof'] = $proof;
				$record['proofedAt']    = (string) ( $proof['createdAt'] ?? gmdate( 'c' ) );
				$matched                = true;
			}
			$updated[] = $record;
		}
		if ( ! $matched ) {
			return [];
		}
		update_option( self::OPTION, array_slice( $updated, 0, self::MAX_RECORDS ), false );

		return $this->find( $id );
	}

	public function attach_authorization( string $id, array $authorization ): array {
		$records = $this->records();
		$updated = [];
		$matched = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( $id === (string) ( $record['id'] ?? '' ) ) {
				$record['applyAuthorization'] = $authorization;
				$record['authorizedAt']       = (string) ( $authorization['createdAt'] ?? gmdate( 'c' ) );
				$matched                      = true;
			}
			$updated[] = $record;
		}
		if ( ! $matched ) {
			return [];
		}
		update_option( self::OPTION, array_slice( $updated, 0, self::MAX_RECORDS ), false );

		return $this->find( $id );
	}

	public function attach_execution_gate( string $id, array $gate ): array {
		$records = $this->records();
		$updated = [];
		$matched = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( $id === (string) ( $record['id'] ?? '' ) ) {
				$record['preExecutionGate'] = $gate;
				$record['gatedAt']          = (string) ( $gate['createdAt'] ?? gmdate( 'c' ) );
				$matched                    = true;
			}
			$updated[] = $record;
		}
		if ( ! $matched ) {
			return [];
		}
		update_option( self::OPTION, array_slice( $updated, 0, self::MAX_RECORDS ), false );

		return $this->find( $id );
	}

	public function attach_execution_preview( string $id, array $preview ): array {
		$records = $this->records();
		$updated = [];
		$matched = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( $id === (string) ( $record['id'] ?? '' ) ) {
				$record['executionPreview'] = $preview;
				$record['previewedAt']       = (string) ( $preview['createdAt'] ?? gmdate( 'c' ) );
				$matched                     = true;
			}
			$updated[] = $record;
		}
		if ( ! $matched ) {
			return [];
		}
		update_option( self::OPTION, array_slice( $updated, 0, self::MAX_RECORDS ), false );

		return $this->find( $id );
	}

	public function attach_final_confirmation( string $id, array $confirmation ): array {
		$records = $this->records();
		$updated = [];
		$matched = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( $id === (string) ( $record['id'] ?? '' ) ) {
				$record['finalConfirmation'] = $confirmation;
				$record['confirmedAt']       = (string) ( $confirmation['createdAt'] ?? gmdate( 'c' ) );
				$matched                     = true;
			}
			$updated[] = $record;
		}
		if ( ! $matched ) {
			return [];
		}
		update_option( self::OPTION, array_slice( $updated, 0, self::MAX_RECORDS ), false );

		return $this->find( $id );
	}

	public function attach_fresh_sitegraph_revalidation( string $id, array $revalidation ): array {
		$records = $this->records();
		$updated = [];
		$matched = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( $id === (string) ( $record['id'] ?? '' ) ) {
				$record['freshSiteGraphRevalidation'] = $revalidation;
				$record['revalidatedAt']              = (string) ( $revalidation['createdAt'] ?? gmdate( 'c' ) );
				$matched                              = true;
			}
			$updated[] = $record;
		}
		if ( ! $matched ) {
			return [];
		}
		update_option( self::OPTION, array_slice( $updated, 0, self::MAX_RECORDS ), false );

		return $this->find( $id );
	}

	public function attach_rollback_readiness_checkpoint( string $id, array $checkpoint ): array {
		$records = $this->records();
		$updated = [];
		$matched = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( $id === (string) ( $record['id'] ?? '' ) ) {
				$record['rollbackReadinessCheckpoint'] = $checkpoint;
				$record['rollbackCheckedAt']           = (string) ( $checkpoint['createdAt'] ?? gmdate( 'c' ) );
				$matched                               = true;
			}
			$updated[] = $record;
		}
		if ( ! $matched ) {
			return [];
		}
		update_option( self::OPTION, array_slice( $updated, 0, self::MAX_RECORDS ), false );

		return $this->find( $id );
	}

	public function attach_target_resolution( string $id, array $resolution ): array {
		$records = $this->records();
		$updated = [];
		$matched = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( $id === (string) ( $record['id'] ?? '' ) ) {
				$record['targetResolution'] = $resolution;
				$record['targetResolvedAt'] = (string) ( $resolution['createdAt'] ?? gmdate( 'c' ) );
				$matched                    = true;
			}
			$updated[] = $record;
		}
		if ( ! $matched ) {
			return [];
		}
		update_option( self::OPTION, array_slice( $updated, 0, self::MAX_RECORDS ), false );

		return $this->find( $id );
	}

	public function build_record( array $apply_plan, array $context = [] ): array {
		$json        = $this->json_encode( $apply_plan );
		$plan_hash   = hash( 'sha256', false !== $json ? $json : serialize( $apply_plan ) );
		$preflight   = $this->array_value( $apply_plan, 'preflight' );
		$operations  = $this->array_value( $apply_plan, 'operations' );
		$manual      = $this->array_value( $apply_plan, 'manualReview' );
		$target      = isset( $apply_plan['target'] ) && is_array( $apply_plan['target'] ) ? $apply_plan['target'] : [];
		$safety      = isset( $apply_plan['safety'] ) && is_array( $apply_plan['safety'] ) ? $apply_plan['safety'] : [];
		$blockers    = $this->blockers( $apply_plan, $preflight, $target, $safety );
		$created_at  = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$created_by  = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$stage_id    = 'stage-' . substr( $plan_hash, 0, 12 ) . '-' . gmdate( 'YmdHis', strtotime( $created_at ) ?: time() );

		return [
			'schema'                => 'kiwe.trusted-apply-stage.v1',
			'id'                    => $stage_id,
			'status'                => [] === $blockers ? 'staged-review-only' : 'blocked-review',
			'createdAt'             => $created_at,
			'createdBy'             => $created_by,
			'source'                => [
				'siteName'         => (string) ( $context['siteName'] ?? '' ),
				'fileName'         => (string) ( $context['fileName'] ?? '' ),
				'bindingReportKey' => (string) ( $context['bindingReportKey'] ?? '' ),
			],
			'plan'                  => [
				'schema'       => (string) ( $apply_plan['schema'] ?? '' ),
				'hash'         => $plan_hash,
				'operations'   => count( $operations ),
				'preflight'    => count( $preflight ),
				'manualReview' => count( $manual ),
			],
			'gates'                 => $this->gates( $apply_plan, $preflight, $target, $safety ),
			'blockers'              => $blockers,
			'mutatesWordPress'      => false,
			'mutatesBricksContent'  => false,
			'nextActions'           => [
				'Review staged operations and blockers in Kiwe admin.',
				'Capture a revision or staging backup before any future mutation.',
				'Require explicit admin approval before a trusted adapter may save Bricks data.',
				'Run rendered-output and Kiwe authority audits after any future apply.',
			],
			'applyPlan'             => $apply_plan,
		];
	}

	private function gates( array $apply_plan, array $preflight, array $target, array $safety ): array {
		return [
			[
				'id'      => 'schema',
				'label'   => 'Dry-run apply-plan schema',
				'status'  => 'kiwe.bricks-apply-plan.v1' === ( $apply_plan['schema'] ?? '' ) ? 'passed' : 'blocked',
				'details' => 'Only Kiwe dry-run apply plans can be staged.',
			],
			[
				'id'      => 'non-mutating-plan',
				'label'   => 'Plan does not claim mutation authority',
				'status'  => empty( $target['mutatesWordPress'] ) && empty( $target['mutatesBricksContent'] ) ? 'passed' : 'blocked',
				'details' => 'A stage record is not allowed to be a direct write/save/publish instruction.',
			],
			[
				'id'      => 'dry-run-only',
				'label'   => 'Safety dry-run flag',
				'status'  => ! empty( $safety['dryRunOnly'] ) ? 'passed' : 'blocked',
				'details' => 'The staged artifact must remain a dry run until a trusted adapter is explicitly approved.',
			],
			[
				'id'      => 'preflight',
				'label'   => 'Preflight gates contain no blockers',
				'status'  => $this->preflight_has_blocker( $preflight ) ? 'blocked' : 'passed',
				'details' => 'Validation and adapter gates must be reviewed before apply.',
			],
			[
				'id'      => 'admin-approval',
				'label'   => 'Explicit admin approval required later',
				'status'  => ! empty( $safety['requiresAdminApprovalBeforeSave'] ) ? 'passed' : 'manual-review',
				'details' => 'Staging is not approval to save Bricks data.',
			],
			[
				'id'      => 'revision',
				'label'   => 'Revision or rollback point required later',
				'status'  => ! empty( $safety['requiresRevisionBeforeSave'] ) ? 'passed' : 'manual-review',
				'details' => 'A future adapter must prove rollback before mutation.',
			],
		];
	}

	private function blockers( array $apply_plan, array $preflight, array $target, array $safety ): array {
		$blockers = [];
		if ( 'kiwe.bricks-apply-plan.v1' !== ( $apply_plan['schema'] ?? '' ) ) {
			$blockers[] = 'Apply plan schema is not kiwe.bricks-apply-plan.v1.';
		}
		if ( ! empty( $target['mutatesWordPress'] ) || ! empty( $target['mutatesBricksContent'] ) ) {
			$blockers[] = 'Apply plan claims mutation authority.';
		}
		if ( empty( $safety['dryRunOnly'] ) ) {
			$blockers[] = 'Apply plan is missing safety.dryRunOnly.';
		}
		foreach ( $preflight as $gate ) {
			if ( ! is_array( $gate ) ) {
				continue;
			}
			$status = strtolower( (string) ( $gate['status'] ?? '' ) );
			if ( preg_match( '/blocked|fail|error|direct-write|mutat/', $status ) ) {
				$blockers[] = sprintf( 'Preflight gate "%s" is %s.', (string) ( $gate['id'] ?? $gate['label'] ?? 'unknown' ), $status );
			}
		}

		return array_values( array_unique( $blockers ) );
	}

	private function preflight_has_blocker( array $preflight ): bool {
		foreach ( $preflight as $gate ) {
			if ( is_array( $gate ) && preg_match( '/blocked|fail|error|direct-write|mutat/', strtolower( (string) ( $gate['status'] ?? '' ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function dedupe_records( array $records ): array {
		$seen = [];
		$out  = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$id = (string) ( $record['id'] ?? '' );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $record;
		}

		return $out;
	}

	private function json_encode( array $value ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}
}
