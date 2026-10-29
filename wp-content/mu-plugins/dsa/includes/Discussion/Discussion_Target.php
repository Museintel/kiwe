<?php
namespace DSA\Discussion;

use DSA\Apps\Store_App_Service;
use DSA\Utilities\Content_Visibility;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native readable records own discussion. No targets or schemas are created during reads. */
final class Discussion_Target {
	public const SITE_PAGE_OPTION = 'kiwe_discussion_site_page_v1';
	public static function get( int $id ) {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || in_array( $post->post_type, [ 'attachment','revision','product_variation','shop_order','shop_order_refund','shop_coupon' ], true ) || ! Content_Visibility::is_public( $post ) || ! Store_App_Service::content_available( $post->post_type ) || ( 'product' !== $post->post_type && ! post_type_supports( $post->post_type, 'comments' ) ) ) return self::unavailable();
		if ( 'product' === $post->post_type && ( ! Store_App_Service::enabled() || ! class_exists( '\DSA\Commerce\Discussion_Review_Adapter' ) ) ) return self::unavailable();
		return $post;
	}
	public static function unavailable(): \WP_Error { return new \WP_Error( 'kiwe_discussion_unavailable', __( 'This discussion is unavailable.', 'dsa' ), [ 'status'=>404 ] ); }
	public static function automatic_id( int $current_id ): int {
		$post = $current_id > 0 ? get_post( $current_id ) : null;
		if ( $post && in_array( $post->post_type, [ 'post','product' ], true ) && ! is_wp_error( self::get( $current_id ) ) ) return $current_id;
		$id = (int) get_option( self::SITE_PAGE_OPTION, 0 ); if ( $id < 1 ) return 0;
		$site = self::get( $id );
		return ! is_wp_error( $site ) && 'page' === $site->post_type ? $id : 0;
	}
	public static function types( \WP_Post $post ): array { return 'product' === $post->post_type ? [ '', 'comment', 'review' ] : [ '', 'comment' ]; }
	public static function parent( \WP_Post $post, int $id ) {
		if ( ! $id ) return [ 'id'=>0, 'depth'=>0 ];
		$seen = []; $current = $id;
		while ( $current && count( $seen ) < 32 ) {
			if ( isset( $seen[$current] ) ) return self::unavailable();
			$comment = get_comment( $current );
			if ( ! $comment || (int) $comment->comment_post_ID !== (int) $post->ID || '1' !== (string) $comment->comment_approved || ! in_array( $comment->comment_type, self::types( $post ), true ) ) return self::unavailable();
			$seen[$current] = true; $current = (int) $comment->comment_parent;
		}
		return $current ? self::unavailable() : [ 'id'=>$id, 'depth'=>count( $seen ) ];
	}
	public static function descriptor( \WP_Post $post ): array {
		$review = 'product' === $post->post_type;
		$site = 'page' === $post->post_type && (int) get_option( self::SITE_PAGE_OPTION, 0 ) === (int) $post->ID;
		// This JSON value is plain text; the client assigns textContent rather than rendering title HTML.
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out = [ 'id'=>(int) $post->ID, 'kind'=>$review ? 'review' : ( $site ? 'site' : 'comment' ), 'title'=>$title, 'url'=>(string) get_permalink( $post ), 'label'=>$review ? __( 'Reviews', 'dsa' ) : ( $site ? __( 'Site feedback', 'dsa' ) : __( 'Comments', 'dsa' ) ), 'open'=>comments_open( $post->ID ), 'threaded'=>(bool) get_option( 'thread_comments' ), 'depth'=>max( 1, min( 10, (int) get_option( 'thread_comments_depth', 5 ) ) ), 'loginRequired'=>(bool) get_option( 'comment_registration' ) ];
		if ( $review ) { $out['review'] = \DSA\Commerce\Discussion_Review_Adapter::descriptor( $post->ID ); $out['open'] = $out['open'] && $out['review']['enabled']; }
		return $out;
	}
}
