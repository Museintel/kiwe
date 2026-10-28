<?php
namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Cheap REST registration. Settings/catalogue classes load only for a matched private route. */
final class System_App_Routes {
	public static function register(): void {
		$permission = static fn( \WP_REST_Request $request ) => ( new System_App_Workspace_Service() )->permission( $request );
		register_rest_route( 'dsa/v1', '/workspace/system/(?P<app>auth|security)', [
			[ 'methods'=>'GET', 'callback'=>static fn( \WP_REST_Request $request ) => ( new System_App_Workspace_Service() )->read( $request ), 'permission_callback'=>$permission ],
			[ 'methods'=>'POST', 'callback'=>static fn( \WP_REST_Request $request ) => ( new System_App_Workspace_Service() )->save( $request ), 'permission_callback'=>$permission ],
		] );
	}
}
