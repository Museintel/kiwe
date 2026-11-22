<?php
namespace DSA\Discussion;
if ( ! defined( 'ABSPATH' ) ) exit;

/** One native conversation; no public page, collection scan or write during a read. */
final class Site_Conversation {
	public const TYPE='kiwe_site_feedback',OPTION='kiwe_site_feedback_target_v1',ENABLED='kiwe_site_feedback_enabled_v1';
	public static function register(): void {
		register_post_type( self::TYPE, [ 'label'=>__( 'Website conversation', 'dsa' ),'public'=>false,'publicly_queryable'=>false,'show_ui'=>false,'show_in_rest'=>false,'exclude_from_search'=>true,'rewrite'=>false,'query_var'=>false,'supports'=>[ 'comments' ] ] );
	}
	public static function id(): int { return max( 0,(int) get_option( self::OPTION,get_option( Discussion_Target::SITE_PAGE_OPTION,0 ) ) ); }
	public static function enabled(): bool {
		$value=get_option( self::ENABLED,null );
		return null === $value ? self::id() > 0 : in_array( $value,[true,1,'1'],true );
	}
	public static function owns( $post ): bool {
		return $post instanceof \WP_Post && self::id() === (int) $post->ID && in_array( $post->post_type,[self::TYPE,'page'],true ) && 'publish' === $post->post_status && '' === $post->post_password;
	}
	public static function filter_open(bool $open,int $id): bool {
		return self::owns(get_post($id)) ? $open && \DSA\Discussion\Feedback_Access::enabled() && self::enabled() : $open;
	}
	/** Only the capability/nonce/stale-checked settings mutation calls this operation. */
	public static function enable() {
		global $wpdb;
		$lock='kiwe-feedback-site-'.substr( hash('sha256',$wpdb->prefix),0,32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock) ) ) return new \WP_Error('kiwe_feedback_busy',__('Another feedback change is finishing. Try again shortly.','dsa'),['status'=>409]);
		try {
			wp_cache_delete(self::OPTION,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
			if(!post_type_exists(self::TYPE))self::register();
			$id=self::id();$post=$id?get_post($id):null;
			if ( !self::owns($post) ) {
				$id=wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'publish','post_title'=>__('Website feedback','dsa'),'post_name'=>'kiwe-site-feedback','comment_status'=>'open','ping_status'=>'closed'],true);
				if(is_wp_error($id))return$id;
				update_option(self::OPTION,(string)$id,false);
				if((int)get_option(self::OPTION)!==(int)$id)return new \WP_Error('kiwe_feedback_target_write',__('The website conversation could not be confirmed. Reload before retrying.','dsa'),['status'=>503]);
			} elseif('open'!==$post->comment_status) {
				$opened=wp_update_post(['ID'=>$post->ID,'comment_status'=>'open'],true);
				if(is_wp_error($opened)||'open'!==get_post($post->ID)->comment_status)return new \WP_Error('kiwe_feedback_target_write',__('The website conversation could not be opened. Reload before retrying.','dsa'),['status'=>503]);
			}
			return true;
		} finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
	}
}
