<?php

namespace DSA\MCP;

use DSA\Access\Guest_Contribution_Service;
use DSA\Access\Guest_Engine_Service;
use DSA\Access\Guest_Referral_Service;
use DSA\Apps\Guest_App_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Guest_Provider {
	public static function operations(): array {
		if ( ! class_exists( Guest_App_Service::class ) || ! Guest_App_Service::enabled() || ! defined( Guest_Engine_Service::class . '::GRANTS_API' )
			|| Guest_Engine_Service::GRANTS_API !== 1 || ! defined( Guest_Contribution_Service::class . '::INTENTS' ) ) return [];
		$can_manage = static fn (): bool => Credentials::actor_allowed( get_current_user_id() ) && current_user_can( 'kiwe_manage_guest_applications' );
		$object = static fn ( array $properties, array $required = [] ): array => [
			'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
		];
		$id = [ 'type' => 'integer', 'minimum' => 1 ];
		$output = $object( [ 'resultJson' => [ 'type' => 'string', 'maxLength' => 14000 ] ], [ 'resultJson' ] );
		$service = new Guest_Contribution_Service();
		return [
			'mcp.guest.read' => [
				'scope' => 'mcp.guest.read', 'label' => 'Read Guest applications and permissions',
				'effect' => 'read', 'approval' => 'none', 'accessVersion' => 1,
				'discoverPermission' => $can_manage, 'permission' => $can_manage,
				'inputSchema' => $object( [ 'id' => $id, 'page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ] ] ),
				'outputSchema' => $output,
				'execute' => static function ( array $input ) use ( $service ): array {
					$data = $service->workspace( [ ...$input, 'view' => 'applications' ] );
					return [ 'resultJson' => Authoring_Service::json( [
						'applications' => $data['items'], 'page' => $data['page'], 'hasMore' => $data['hasNext'],
					], 14000 ) ];
				},
			],
			'mcp.guest.decide' => [
				'scope' => 'mcp.guest.write', 'label' => 'Review permission changes for one Guest application',
				'effect' => 'write', 'approval' => 'required', 'accessVersion' => 1,
				'discoverPermission' => $can_manage, 'permission' => $can_manage,
				'inputSchema' => $object( [
					'id' => $id,
					'decision' => [ 'type' => 'string', 'enum' => [ 'approved', 'denied', 'revoked' ], 'maxLength' => 8 ],
					'grants' => [ 'type' => 'array', 'maxItems' => 3, 'items' => [ 'type' => 'string', 'enum' => Guest_Contribution_Service::INTENTS, 'maxLength' => 8 ] ],
					'reason' => [ 'type' => 'string', 'maxLength' => 180 ],
					'expected' => [ 'type' => 'string', 'maxLength' => 64 ],
				], [ 'id', 'decision', 'grants', 'expected' ] ),
				'outputSchema' => $output,
				'prepare' => static fn ( array $input ): array => self::plan( $service, $input ),
				'current' => static fn ( array $input ): string => self::plan( $service, $input )['nativeExpected'],
				'execute' => static function ( array $input, string $expected ) use ( $service ): array {
					$result = $service->decide_application( $input['id'], $input['decision'], $input['reason'] ?? '', $expected, $input['grants'] );
					if ( is_wp_error( $result ) ) throw new \RuntimeException( $result->get_error_message() );
					return [ 'resultJson' => Authoring_Service::json( $result ) ];
				},
			],
			'mcp.guest.analytics' => [
				'scope' => 'mcp.guest.analytics', 'label' => 'Read one Guest’s attributed accounts and paid orders',
				'effect' => 'read', 'approval' => 'none', 'accessVersion' => 1,
				'discoverPermission' => $can_manage, 'permission' => $can_manage,
				'inputSchema' => $object( [ 'id' => $id, 'page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ] ], [ 'id' ] ),
				'outputSchema' => $output,
				'execute' => static function ( array $input ): array {
					$report = Guest_Referral_Service::report( $input['id'], $input['page'] ?? 1 );
					if ( null === $report ) throw new \RuntimeException( 'This Guest report is unavailable.' );
					return [ 'resultJson' => Authoring_Service::json( $report, 14000 ) ];
				},
			],
		];
	}

	private static function plan( Guest_Contribution_Service $service, array $input ): array {
		$record = $service->workspace( [ 'view' => 'applications', 'id' => $input['id'] ] )['items'][0] ?? null;
		if ( ! $record || ! hash_equals( $record['expected'], $input['expected'] ) ) {
			throw new \RuntimeException( 'Read the current Guest application before submitting a decision.' );
		}
		$grants = 'approved' === $input['decision'] ? $input['grants'] : [];
		$allowed = match ( $record['status'] ) {
			'pending' => [ 'approved', 'denied' ],
			'approved' => [ 'approved', 'revoked' ],
			default => [],
		};
		if ( ! in_array( $input['decision'], $allowed, true ) || ( 'approved' === $input['decision']
			&& ( ! $record['verified'] || ! $grants || array_diff( $grants, $record['availableGrants'] ) || count( $grants ) !== count( array_unique( $grants ) ) ) ) ) {
			throw new \RuntimeException( 'Choose a valid decision and the requested Guest permissions.' );
		}
		return Authoring_Service::plan( 'Review Guest permissions for ' . $record['name'], $record['expected'],
			[ 'status' => $record['status'], 'grants' => $record['grants'] ],
			Authoring_Service::json( [ 'status' => $input['decision'], 'grants' => $grants, 'reason' => $input['reason'] ?? '' ] ), true );
	}
}
