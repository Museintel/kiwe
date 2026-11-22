<?php
namespace DSA\Discussion;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One client editing home for existing native discussion policy; no second moderation engine. */
final class Discussion_Settings {
	private const OPTIONS = [ 'newPosts'=>'default_comment_status', 'accountRequired'=>'comment_registration', 'moderateAll'=>'comment_moderation', 'previousApproval'=>'comment_previously_approved', 'threaded'=>'thread_comments', 'threadDepth'=>'thread_comments_depth', 'siteWide'=>Site_Conversation::ENABLED ];
	private static function allowed(): bool { return \DSA\Discussion\Feedback_Access::allowed() && ! \DSA\Access\Team_Workspace_Service::suspended( get_current_user_id() ) && ( ! is_multisite() || is_user_member_of_blog( get_current_user_id() ) ); }
	private static function raw(): array { $out = []; foreach ( self::OPTIONS as $key=>$option ) $out[$key] = (string) get_option( $option, 'siteWide' === $key ? ( Site_Conversation::enabled() ? '1' : '0' ) : '' ); return $out; }
	public static function read(): array {
		if ( ! self::allowed() ) return [];
		$raw = self::raw(); $values = $raw;
		foreach ( [ 'accountRequired','moderateAll','previousApproval','threaded','siteWide' ] as $key ) $values[$key] = (bool) $raw[$key];
		$values['newPosts'] = 'open' === $raw['newPosts']; $values['threadDepth'] = max( 1, min( 10, (int) $raw['threadDepth'] ) );
		return [ 'values'=>$values, 'expected'=>hash( 'sha256', wp_json_encode( $raw ) ) ];
	}
	public static function save( array $input ) {
		if(!self::allowed())return new \WP_Error('kiwe_discussion_policy_denied',__('Only the website settings owner can change discussion policy.','dsa'),['status'=>403]);
		global $wpdb;$lock='kiwe-feedback-policy-'.substr(hash('sha256',$wpdb->prefix),0,32);
		if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return new \WP_Error('kiwe_discussion_policy_busy',__('Another feedback change is finishing. Try again shortly.','dsa'),['status'=>409]);
		try{
			foreach(self::OPTIONS as$option)wp_cache_delete($option,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
			return self::write($input);
		}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	private static function write( array $input ) {
		if ( ! self::allowed() ) return new \WP_Error( 'kiwe_discussion_policy_denied', __( 'Only the website settings owner can change discussion policy.', 'dsa' ), [ 'status'=>403 ] );
		if ( array_diff( array_keys( $input ), [ 'field','value','expected' ] ) || ! is_string( $input['field'] ?? null ) || ! isset( self::OPTIONS[$input['field']] ) || ! array_key_exists( 'value', $input ) || ! is_string( $input['expected'] ?? null ) ) return self::error();
		$raw = self::raw(); if ( ! hash_equals( hash( 'sha256', wp_json_encode( $raw ) ), $input['expected'] ) ) return new \WP_Error( 'kiwe_discussion_policy_stale', __( 'Discussion settings changed elsewhere. Reload before saving.', 'dsa' ), [ 'status'=>409 ] );
		$field = $input['field']; $value = $input['value'];
		if ( 'threadDepth' === $field ) {
			if ( ! is_int( $value ) || $value < 1 || $value > 10 ) return self::error();
			$value = (string) $value;
		} else {
			if ( ! is_bool( $value ) ) return self::error();
			if ( 'siteWide' === $field && $value ) { $created=Site_Conversation::enable(); if ( is_wp_error($created) ) return $created; }
			$value = 'newPosts' === $field ? ( $value ? 'open' : 'closed' ) : ( $value ? '1' : '0' );
		}
		$option = self::OPTIONS[$field]; update_option( $option, $value, false );
		if ( (string) get_option( $option, null ) !== (string) $value ) return new \WP_Error( 'kiwe_discussion_policy_write', __( 'WordPress did not confirm this setting. Reload before retrying.', 'dsa' ), [ 'status'=>503 ] );
		return self::read();
	}
	private static function error(): \WP_Error { return new \WP_Error( 'kiwe_discussion_policy_input', __( 'Choose one valid discussion setting.', 'dsa' ), [ 'status'=>400 ] ); }
}
