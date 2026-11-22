<?php
namespace DSA\Link_Hub;

use DSA\Utilities\Origin_Checker;
use DSA\Utilities\Atomic_Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Share_Controller {
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/links/share', [ 'methods'=>'GET', 'callback'=>[ $this,'read' ], 'permission_callback'=>[ $this,'permission' ] ] );
	}
	public function permission( \WP_REST_Request $r ): bool { return 'GET' === $r->get_method() && Origin_Checker::is_same_site_request(); }
	public function read( \WP_REST_Request $r ): \WP_REST_Response {
		$headers = [ 'Cache-Control'=>'private, no-store, max-age=0' ];
		if ( ! $this->permission( $r ) ) return new \WP_REST_Response( [ 'ok'=>false,'message'=>'Open sharing on this website.' ], 403, $headers );
		$q = $r->get_query_params(); $raw = $q['subjectId'] ?? '0';
		$id = is_int( $raw ) || is_string( $raw ) ? filter_var( $raw, FILTER_VALIDATE_INT, [ 'options'=>[ 'min_range'=>0,'max_range'=>9007199254740991 ] ] ) : false;
		if ( false === $id || array_diff( array_keys( $q ), [ 'subjectId','_dsa_rt','rest_route','_locale' ] ) ) return new \WP_REST_Response( [ 'ok'=>false,'message'=>'This sharing context is unavailable.' ], 400, $headers );
		if ( ! Atomic_Rate_Limiter::allow( 'public-share|' . get_current_blog_id() . '|' . Origin_Checker::client_ip(), 30, 60, false ) ) return new \WP_REST_Response( [ 'ok'=>false,'message'=>'Please wait a moment before sharing again.' ], 429, $headers );
		return new \WP_REST_Response( [ 'ok'=>true, ...Share_Service::document( (int) $id ) ], 200, $headers );
	}
}
