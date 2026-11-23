<?php

namespace DSA\MCP;

use DSA\Bricks\Document_Publication_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Bricks_Document_Provider {
	public const SCOPE = 'mcp.bricks.document';

	public static function operations(): array {
		if ( ! class_exists( Document_Publication_Service::class ) || ! Document_Publication_Service::available() ) return [];
		$permission = static fn() => Credentials::actor_allowed( get_current_user_id() ) && Document_Publication_Service::allowed();
		$fields = [
			'job'=>[ 'type'=>'string', 'minLength'=>32, 'maxLength'=>32 ],
			'archiveSha256'=>[ 'type'=>'string', 'minLength'=>64, 'maxLength'=>64 ],
			'draftId'=>[ 'type'=>'integer', 'minimum'=>1 ],
			'target'=>[ 'type'=>'string', 'minLength'=>1, 'maxLength'=>24 ],
			'pageId'=>[ 'type'=>'integer', 'minimum'=>0 ],
		];
		$base = [
			'scope'=>self::SCOPE, 'accessVersion'=>1, 'discoverPermission'=>$permission, 'permission'=>$permission,
			'inputSchema'=>[ 'type'=>'object', 'properties'=>$fields, 'required'=>array_keys( $fields ), 'additionalProperties'=>false ],
			'outputSchema'=>[ 'type'=>'object', 'properties'=>[ 'resultJson'=>[ 'type'=>'string', 'maxLength'=>3000 ] ],
				'required'=>[ 'resultJson' ], 'additionalProperties'=>false ],
		];
		$ops = [ self::SCOPE . '.status'=>$base + [
			'label'=>'Read this page or template’s native publication receipt',
			'effect'=>'read', 'approval'=>'none',
			'execute'=>static fn( $a ) => self::result( ( new Document_Publication_Service() )->status( $a ) ),
		] ];
		foreach ( [ 'publish'=>false, 'restore'=>true ] as $action=>$restore ) {
			$label = $restore ? 'Restore the selected page or template’s previous layout' : 'Publish the reviewed page or template';
			$ops[self::SCOPE . '.' . $action] = $base + [
				'label'=>$label, 'effect'=>'write', 'approval'=>'required',
				'prepare'=>static function( $args ) use ( $label, $restore ): array {
					$plan = ( new Document_Publication_Service() )->plan( $args, $restore );
					return Authoring_Service::plan( $label, $plan['expected'], $plan['beforeText'], $plan['afterText'], true );
				},
				'current'=>static fn( $args ) => ( new Document_Publication_Service() )->plan( $args, $restore )['expected'],
				'execute'=>static fn( $args, $expected ) => Authoring_Service::mutate( 'bricks-publication', static fn() =>
					self::result( ( new Document_Publication_Service() )->apply( $args, $expected, $restore ) ) ),
			];
		}
		return $ops;
	}

	private static function result( array $receipt ): array {
		return [ 'resultJson'=>Authoring_Service::json( $receipt, 3000 ) ];
	}
}
