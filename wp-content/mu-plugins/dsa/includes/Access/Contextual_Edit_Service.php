<?php
namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private launcher only. Native task and record capabilities remain the authority. */
final class Contextual_Edit_Service {
	public static function link( int $id ): ?array {
		$user = wp_get_current_user();
		if ( ! $id || ! $user->ID || Team_Workspace_Service::suspended( $user->ID ) || ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) || ! current_user_can( 'edit_post', $id ) ) return null;
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page', 'product' ], true ) || ! \DSA\Apps\Store_App_Service::content_available( $post->post_type ) ) return null;
		$url = '';
		if ( Site_Owner_Service::dashboard_mandatory() && Workspace_Admin_Service::uses_workspace( $user ) ) {
			if ( 'post' === $post->post_type && Post_Workspace_Service::enabled() && current_user_can( 'edit_posts' ) ) $url = Post_Workspace_Service::url( $id );
			if ( 'product' === $post->post_type && Commerce_App_Service::enabled() && current_user_can( 'edit_products' ) ) $url = add_query_arg( 'edit', $id, Commerce_App_Service::url( 'products' ) );
			if ( 'page' === $post->post_type && current_user_can( 'kiwe_view_pages' ) ) $url = add_query_arg( 'focusTitle', $post->post_title, Continuity_App_Service::url() );
		} else $url = (string) get_edit_post_link( $id, 'raw' );
		return $url ? [ 'label'=>__( 'Edit', 'dsa' ), 'url'=>esc_url_raw( $url ), 'objectId'=>$id ] : null;
	}
	public static function current_link(): ?array {
		// Hydration is private. Cached page HTML never receives a staff editing URL.
		if ( ! is_user_logged_in() || ! Workspace_Admin_Service::uses_workspace( wp_get_current_user() ) ) return null;
		$url = wp_get_referer();
		if ( ! is_string( $url ) || ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) return null;
		return self::link( (int) url_to_postid( $url ) );
	}
}
