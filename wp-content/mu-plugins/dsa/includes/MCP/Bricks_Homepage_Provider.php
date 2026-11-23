<?php

namespace DSA\MCP;

use DSA\Bricks\Homepage_Publication_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Homepage publication has a separate grant and the native exact-intent review. */
final class Bricks_Homepage_Provider {
	public const SCOPE = 'mcp.bricks.homepage';

	public static function operations(): array {
		if ( ! class_exists( Homepage_Publication_Service::class ) || ! Homepage_Publication_Service::available() ) return [];
		$permission = static fn() => Credentials::actor_allowed( get_current_user_id() ) && Homepage_Publication_Service::allowed();
		$args = [ 'job'=>[ 'type'=>'string', 'maxLength'=>32 ], 'archiveSha256'=>[ 'type'=>'string', 'maxLength'=>64 ],
			'draftId'=>[ 'type'=>'integer', 'minimum'=>1 ] ];
		$base = [
			'scope'=>self::SCOPE, 'accessVersion'=>2, 'discoverPermission'=>$permission, 'permission'=>$permission,
			'inputSchema'=>[ 'type'=>'object', 'properties'=>$args, 'required'=>array_keys( $args ), 'additionalProperties'=>false ],
			'outputSchema'=>[ 'type'=>'object', 'properties'=>[ 'resultJson'=>[ 'type'=>'string', 'maxLength'=>3000 ] ],
				'required'=>[ 'resultJson' ], 'additionalProperties'=>false ],
		];
		$ops = [ self::SCOPE . '.status'=>$base + [
			'label'=>'Read the current homepage publication receipt',
			'effect'=>'read', 'approval'=>'none',
			'execute'=>static fn( $a ) => self::result( ( new Homepage_Publication_Service() )->status( $a ) ),
		] ];
		foreach ( [ 'publish'=>false, 'restore'=>true ] as $action=>$restore ) {
			$label = $restore ? 'Restore the exact previous homepage design' : 'Publish the reviewed homepage design';
			$ops[self::SCOPE . '.' . $action] = $base + [
				'label'=>$label, 'effect'=>'write', 'approval'=>'required',
				'prepare'=>static function( $a ) use ( $restore, $label ): array {
					$plan = ( new Homepage_Publication_Service() )->plan( $a, $restore );
					if ( ! empty( $plan['assignment'] ) ) {
						return Authoring_Service::plan( $label, $plan['expected'], $plan['beforeText'], $plan['afterText'], true );
					}
					$page = $plan['post'];
					return Authoring_Service::plan( $label, $plan['expected'],
						home_url( '/' ) . ' · ' . get_the_title( $page ) . ' · WordPress page ' . $page->ID,
						$restore ? 'Restore the previous native Bricks layout.' :
							'Replace the homepage’s Bricks content with the exact reviewed draft and save the previous layout for recovery.', true );
				},
				'current'=>static fn( $a ) => ( new Homepage_Publication_Service() )->plan( $a, $restore )['expected'],
				'execute'=>static fn( $a, $expected ) => Authoring_Service::mutate( 'bricks-homepage', static fn() =>
					self::result( ( new Homepage_Publication_Service() )->apply( $a, $expected, $restore ) ) ),
			];
		}
		return $ops;
	}

	private static function result( array $receipt ): array {
		return [ 'resultJson'=>Authoring_Service::json( $receipt, 3000 ) ];
	}
}
