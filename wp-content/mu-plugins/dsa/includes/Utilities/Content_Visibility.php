<?php

namespace DSA\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native content visibility for public exports and viewer-specific saved lists. */
final class Content_Visibility {
	/** Public evidence never inherits an administrator's permissions or a password cookie. */
	public static function is_public( $post ): bool {
		$post = get_post( $post );
		if ( ! $post || '' !== (string) $post->post_password ) return false;
		if ( 'product_variation' === $post->post_type ) {
			$parent = get_post( $post->post_parent );
			return 'publish' === $post->post_status && $parent && 'product' === $parent->post_type && self::is_public( $parent ) && ! post_password_required( $post );
		}
		if ( 'attachment' === $post->post_type ) {
			if ( ! in_array( $post->post_status, [ 'inherit', 'publish' ], true ) ) return false;
			if ( $post->post_parent ) {
				$parent = get_post( $post->post_parent );
				return $parent && ! in_array( $parent->post_type, [ 'attachment', 'revision' ], true ) && self::is_public( $parent );
			}
			return is_post_type_viewable( 'attachment' ) && ! post_password_required( $post );
		}
		return is_post_publicly_viewable( $post ) && ! post_password_required( $post );
	}

	/** WordPress remains the authority for an authenticated viewer's private records. */
	public static function can_read( $post ): bool {
		$post = get_post( $post );
		if ( ! $post || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) || 'revision' === $post->post_type ) return false;
		if ( 'product_variation' === $post->post_type ) {
			$parent = get_post( $post->post_parent );
			if ( ! $parent || 'product' !== $parent->post_type || ! self::can_read( $parent ) ) return false;
		} elseif ( 'attachment' === $post->post_type && $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( ! $parent || in_array( $parent->post_type, [ 'attachment', 'product_variation', 'revision' ], true ) || ! self::can_read( $parent ) ) return false;
		}
		if ( self::is_public( $post ) ) return true;
		if ( ! get_current_user_id() ) return false;
		if ( current_user_can( 'edit_post', $post->ID ) ) return true;
		// A generic "read" capability on a published internal object is not a public content grant.
		if ( ! is_post_type_viewable( $post->post_type ) && 'product_variation' !== $post->post_type ) return false;
		return current_user_can( 'read_post', $post->ID ) && ! post_password_required( $post );
	}
}
