<?php
namespace DSA\Rest;

use DSA\Discussion\Discussion_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Discussion_Controller {
	public function register(): void { add_action( 'rest_api_init', [ $this, 'routes' ] ); }
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/discussion/counts', [ 'methods'=>'GET', 'callback'=>[ $this, 'counts' ], 'permission_callback'=>[ $this, 'can_read' ] ] );
		register_rest_route( 'dsa/v1', '/discussion/context', [ 'methods'=>'GET', 'callback'=>[ $this, 'context' ], 'permission_callback'=>[ $this, 'can_read' ] ] );
		register_rest_route( 'dsa/v1', '/discussion/(?P<postId>[1-9][0-9]*)', [ 'methods'=>'GET', 'callback'=>[ $this, 'listing' ], 'permission_callback'=>[ $this, 'can_read' ] ] );
		register_rest_route( 'dsa/v1', '/discussion/(?P<postId>[1-9][0-9]*)/composer', [ 'methods'=>'GET', 'callback'=>[ $this, 'composer' ], 'permission_callback'=>[ $this, 'can_read' ] ] );
		register_rest_route( 'dsa/v1', '/discussion/(?P<postId>[1-9][0-9]*)/comments', [ 'methods'=>'POST', 'callback'=>[ $this, 'submit' ], 'permission_callback'=>[ $this, 'can_write' ] ] );
		register_rest_route( 'dsa/v1', '/discussion/(?P<postId>[1-9][0-9]*)/identity', [ 'methods'=>'POST', 'callback'=>[ $this, 'identity' ], 'permission_callback'=>[ $this, 'can_write' ] ] );
		register_rest_route( 'dsa/v1', '/discussion/(?P<postId>[1-9][0-9]*)/comments/(?P<commentId>[1-9][0-9]*)/reaction', [ 'methods'=>'POST', 'callback'=>[ $this, 'reaction' ], 'permission_callback'=>[ $this, 'can_write' ] ] );
	}
	public function can_read(): bool { return Origin_Checker::is_same_site_request(); }
	public function can_write( \WP_REST_Request $request ) {
		$check = Origin_Checker::mutation_allowed( $request ); if ( true !== $check ) return $check;
		if ( is_user_logged_in() && ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) return new \WP_Error( 'kiwe_discussion_nonce', __( 'Refresh this conversation before sending.', 'dsa' ), [ 'status'=>403 ] );
		return true;
	}
	private function response( $value, bool $private = false ) {
		if ( is_wp_error( $value ) ) {
			// Native comment validation historically uses status 200 for form errors.
			// Preserve its decision/code, but never present a refused Send as HTTP success.
			$data = $value->get_error_data();
			if ( is_int( $data ) && $data >= 100 && $data <= 599 ) $value->add_data( [ 'status'=>$data >= 400 ? $data : 400 ] );
			if ( is_array( $data ) && isset( $data['status'] ) && (int) $data['status'] < 400 ) { $data['status'] = 400; $value->add_data( $data ); }
			return $value;
		}
		$response = $value instanceof \WP_REST_Response ? $value : new \WP_REST_Response( $value, 200 );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}
	public function counts( \WP_REST_Request $request ) {
		$ids = $request->get_param( 'ids' );
		if ( ! is_string( $ids ) || strlen( $ids ) > 949 || ! preg_match( '/^[1-9][0-9]{0,17}(?:,[1-9][0-9]{0,17}){0,49}$/D', $ids ) ) return new \WP_Error( 'kiwe_discussion_counts', __( 'Choose valid discussion controls.', 'dsa' ), [ 'status'=>400 ] );
		$counts = ( new Discussion_Service() )->counts( array_map( 'intval', explode( ',', $ids ) ) );
		return $this->response( is_wp_error( $counts ) ? $counts : [ 'counts'=>$counts ] );
	}
	public function context( \WP_REST_Request $request ) {
		$url = $request->get_param( 'url' ); $parts = is_string( $url ) && strlen( $url ) <= 2048 && ! preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ? wp_parse_url( $url ) : false;
		$origin = static fn( $p ) => is_array( $p ) && ! isset( $p['user'] ) && ! isset( $p['pass'] ) ? strtolower( ( $p['scheme'] ?? '' ) . '://' . ( $p['host'] ?? '' ) ) . ':' . ( $p['port'] ?? ( ( $p['scheme'] ?? '' ) === 'https' ? 443 : 80 ) ) : '';
		if ( ! $parts || ! in_array( $origin( $parts ), [ $origin( wp_parse_url( home_url() ) ), $origin( wp_parse_url( site_url() ) ) ], true ) ) return new \WP_Error( 'kiwe_discussion_context', __( 'Choose a page on this website.', 'dsa' ), [ 'status'=>400 ] );
		$current = url_to_postid( $url ); $current_post = $current ? \DSA\Discussion\Discussion_Target::get( $current ) : null;
		$id = \DSA\Discussion\Discussion_Target::automatic_id( $current );
		$count = $id ? ( new Discussion_Service() )->count( \DSA\Discussion\Discussion_Target::get( $id ) ) : 0;
		if ( is_wp_error( $count ) ) return $this->response( $count );
		return $this->response( [ 'targetId'=>$id, 'currentTargetId'=>$current_post && ! is_wp_error( $current_post ) ? $current : 0, 'count'=>$count ] );
	}
	public function listing( \WP_REST_Request $request ) {
		foreach ( [ 'parent','before','focus' ] as $key ) if ( null !== $request->get_param( $key ) && ( !is_scalar($request->get_param($key)) || ! preg_match( '/^(0|[1-9][0-9]{0,17})$/D', (string) $request->get_param( $key ) ) ) ) return new \WP_Error( 'kiwe_discussion_page', __( 'Choose a valid conversation.', 'dsa' ), [ 'status'=>400 ] );
		$sort=$request->get_param('sort')??'top';$cursor=$request->get_param('cursor')??'';
		if(!is_string($sort)||!in_array($sort,['top','latest'],true)||!is_string($cursor)||strlen($cursor)>1000||('latest'===$sort&&$cursor)||('top'===$sort&&(int)$request->get_param('before')))return new \WP_Error('kiwe_discussion_sort',__('Refresh the conversation and choose Top or Latest.','dsa'),['status'=>400]);
		return $this->response( ( new Discussion_Service() )->listing( (int) $request['postId'], (int) $request->get_param( 'parent' ), (int) $request->get_param( 'before' ),$sort,$cursor,(int)$request->get_param('focus') ) );
	}
	public function composer( \WP_REST_Request $request ) { return $this->response( ( new Discussion_Service() )->composer( (int) $request['postId'] ), true ); }
	public function submit( \WP_REST_Request $request ) {
		$permission = $this->can_write( $request ); if ( true !== $permission ) return $permission;
		if ( strlen( $request->get_body() ) > 35000 ) return new \WP_Error( 'kiwe_discussion_body_size', __( 'This contribution is too large.', 'dsa' ), [ 'status'=>413 ] );
		$body = $request->get_json_params(); if ( ! is_array( $body ) ) return new \WP_Error( 'kiwe_discussion_body', __( 'Review your comment before sending.', 'dsa' ), [ 'status'=>400 ] );
		if ( ! is_string( $body['actorKey'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $body['actorKey'] ) ) return new \WP_Error( 'kiwe_discussion_actor', __( 'Reopen the composer before sending.', 'dsa' ), [ 'status'=>409 ] );
		return $this->response( ( new Discussion_Service() )->submit( (int) $request['postId'], $body ), true );
	}
	public function identity( \WP_REST_Request $request ) {
		$permission = $this->can_write( $request ); if ( true !== $permission ) return $permission;
		if ( strlen( $request->get_body() ) > 2048 ) return new \WP_Error( 'kiwe_discussion_body_size', __( 'This request is too large.', 'dsa' ), [ 'status'=>413 ] );
		return $this->response( \DSA\Discussion\Discussion_Identity_Bridge::start( $request, (int) $request['postId'] ), true );
	}
	public function reaction( \WP_REST_Request $request ) {
		$permission=$this->can_write($request);if(true!==$permission)return $permission;
		if ( strlen( $request->get_body() ) > 2048 ) return new \WP_Error( 'kiwe_discussion_body_size', __( 'This request is too large.', 'dsa' ), [ 'status'=>413 ] );
		$input=$request->get_json_params();if(!is_array($input))return new \WP_Error('kiwe_discussion_reaction_input',__('Choose one conversation action.','dsa'),['status'=>400]);
		return $this->response((new \DSA\Discussion\Discussion_Reactions())->set((int)$request['postId'],(int)$request['commentId'],$input),true);
	}
}
