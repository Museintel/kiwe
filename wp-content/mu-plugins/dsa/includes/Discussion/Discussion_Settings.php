<?php
namespace DSA\Discussion;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One client editing home for existing native discussion policy; no second moderation engine. */
final class Discussion_Settings {
	private const OPTIONS = [ 'newPosts'=>'default_comment_status', 'accountRequired'=>'comment_registration', 'moderateAll'=>'comment_moderation', 'previousApproval'=>'comment_previously_approved', 'threaded'=>'thread_comments', 'threadDepth'=>'thread_comments_depth', 'sitePage'=>Discussion_Target::SITE_PAGE_OPTION ];
	private static function allowed(): bool { return current_user_can( 'manage_options' ) && ! \DSA\Access\Team_Workspace_Service::suspended( get_current_user_id() ) && ( ! is_multisite() || is_user_member_of_blog( get_current_user_id() ) ); }
	private static function raw(): array { $out = []; foreach ( self::OPTIONS as $key=>$option ) $out[$key] = (string) get_option( $option, 'sitePage' === $key ? 0 : '' ); return $out; }
	public static function read(): array {
		if ( ! self::allowed() ) return [];
		$raw = self::raw(); $values = $raw;
		foreach ( [ 'accountRequired','moderateAll','previousApproval','threaded' ] as $key ) $values[$key] = (bool) $raw[$key];
		$values['newPosts'] = 'open' === $raw['newPosts']; $values['threadDepth'] = max( 1, min( 10, (int) $raw['threadDepth'] ) ); $values['sitePage'] = (int) $raw['sitePage'];
		$pages = get_posts( [ 'post_type'=>'page', 'post_status'=>'publish', 'has_password'=>false, 'numberposts'=>100, 'orderby'=>'title', 'order'=>'ASC', 'suppress_filters'=>false ] ); $choices = [];
		foreach ( $pages as $page ) if ( ! is_wp_error( Discussion_Target::get( (int) $page->ID ) ) ) $choices[] = [ 'id'=>(int) $page->ID, 'title'=>wp_strip_all_tags( get_the_title( $page ) ), 'open'=>comments_open( $page->ID ) ];
		return [ 'values'=>$values, 'expected'=>hash( 'sha256', wp_json_encode( $raw ) ), 'pages'=>$choices ];
	}
	public static function save( array $input ) {
		if ( ! self::allowed() ) return new \WP_Error( 'kiwe_discussion_policy_denied', __( 'Only the website settings owner can change discussion policy.', 'dsa' ), [ 'status'=>403 ] );
		if ( array_diff( array_keys( $input ), [ 'field','value','expected' ] ) || ! is_string( $input['field'] ?? null ) || ! isset( self::OPTIONS[$input['field']] ) || ! array_key_exists( 'value', $input ) || ! is_string( $input['expected'] ?? null ) ) return self::error();
		$raw = self::raw(); if ( ! hash_equals( hash( 'sha256', wp_json_encode( $raw ) ), $input['expected'] ) ) return new \WP_Error( 'kiwe_discussion_policy_stale', __( 'Discussion settings changed elsewhere. Reload before saving.', 'dsa' ), [ 'status'=>409 ] );
		$field = $input['field']; $value = $input['value'];
		if ( in_array( $field, [ 'threadDepth','sitePage' ], true ) ) {
			if ( ! is_int( $value ) || ( 'threadDepth' === $field ? $value < 1 || $value > 10 : $value < 0 ) ) return self::error();
			if ( 'sitePage' === $field && $value ) { $page = Discussion_Target::get( $value ); if ( is_wp_error( $page ) || 'page' !== $page->post_type ) return self::error(); }
			if ( 'threadDepth' === $field ) $value = (string) $value;
		} else {
			if ( ! is_bool( $value ) ) return self::error();
			$value = 'newPosts' === $field ? ( $value ? 'open' : 'closed' ) : ( $value ? '1' : '0' );
		}
		$option = self::OPTIONS[$field]; update_option( $option, $value, false );
		if ( (string) get_option( $option, null ) !== (string) $value ) return new \WP_Error( 'kiwe_discussion_policy_write', __( 'WordPress did not confirm this setting. Reload before retrying.', 'dsa' ), [ 'status'=>503 ] );
		return self::read();
	}
	private static function error(): \WP_Error { return new \WP_Error( 'kiwe_discussion_policy_input', __( 'Choose one valid discussion setting.', 'dsa' ), [ 'status'=>400 ] ); }
}
