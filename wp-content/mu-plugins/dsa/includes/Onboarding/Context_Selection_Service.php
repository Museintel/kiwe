<?php

namespace DSA\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Explicit public-context membership on native records; never a copied catalogue. */
final class Context_Selection_Service {
	public const MEDIA_META = '_kiwe_design_context_media';
	public const TESTIMONIAL_META = '_kiwe_design_context_testimonial';

	public static function public_post( $post ): bool {
		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) return false;
		$type = get_post_type_object( $post->post_type );
		return $type && ( ! empty( $type->publicly_queryable ) || in_array( $post->post_type, [ 'post', 'page' ], true ) );
	}

	public static function public_media( $post ): bool {
		return $post && 'attachment' === $post->post_type && 'inherit' === $post->post_status && '' === $post->post_password
			&& ( ! $post->post_parent || self::public_post( get_post( $post->post_parent ) ) );
	}

	/** Same visibility predicate as public_media(), applied before pagination. */
	public static function media_visibility_where( string $where, \WP_Query $query ): string {
		$view = $query->get( 'kiwe_media_visibility' );
		if ( ! in_array( $view, [ 'public', 'private' ], true ) ) return $where;
		global $wpdb;
		$types = array_values( array_unique( array_merge( [ 'post','page' ], get_post_types( [ 'publicly_queryable'=>true ] ) ) ) );
		$slots = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$parent = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID={$wpdb->posts}.post_parent AND p.post_status='publish' AND p.post_password='' AND p.post_type IN ($slots))", ...$types );
		$public = "({$wpdb->posts}.post_status='inherit' AND {$wpdb->posts}.post_password='' AND ({$wpdb->posts}.post_parent=0 OR $parent))";
		return $where . ( 'public' === $view ? " AND $public" : " AND NOT $public" );
	}
	public static function public_testimonial( $comment ): bool {
		if ( ! $comment || '1' !== (string) $comment->comment_approved || ! in_array( $comment->comment_type, [ '', 'comment', 'review' ], true ) ) return false;
		$post = get_post( $comment->comment_post_ID );
		if ( ! self::public_post( $post ) ) return false;
		if ( 'product' === $post->post_type && !\DSA\Apps\Store_App_Service::enabled() ) return false;
		if ( 'review' === $comment->comment_type && 'product' !== $post->post_type ) return false;
		return '' !== trim( wp_strip_all_tags( $comment->comment_content ) );
	}

	public static function selected( string $kind, int $id ): bool {
		return 'media' === $kind ? '1' === (string) get_post_meta( $id, self::MEDIA_META, true ) : ( 'testimonial' === $kind && '1' === (string) get_comment_meta( $id, self::TESTIMONIAL_META, true ) );
	}

	/** Selection does not grant upload, publishing, moderation or builder authority. */
	public function select( string $kind, int $id, bool $selected, ?bool $expected = null ) {
		if ( ! current_user_can( 'kiwe_manage_context' ) || $id < 1 ) return new \WP_Error( 'kiwe_context_forbidden', __( 'Design Context selection is unavailable.', 'dsa' ) );
		if ( 'media' === $kind ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) return new \WP_Error( 'kiwe_context_media', __( 'You cannot select this media file.', 'dsa' ) );
			if ( class_exists( Context_Media_Service::class ) && Context_Media_Service::automatic( $id ) ) return new \WP_Error( 'kiwe_context_inherited_media', __( 'This image is included automatically through its original content or profile. Its Design Context switch cannot be changed here.', 'dsa' ) );
			if ( $selected && ! self::public_media( $post ) ) return new \WP_Error( 'kiwe_context_private_media', __( 'Only public media can be included. Files attached to unpublished, private or password-protected content are excluded.', 'dsa' ) );
		} elseif ( 'testimonial' === $kind ) {
			$comment = get_comment( $id );
			if ( ! current_user_can( 'moderate_comments' ) || ! $comment || ! current_user_can( 'read_post', (int) $comment->comment_post_ID ) ) return new \WP_Error( 'kiwe_context_comment', __( 'You cannot select this comment or review.', 'dsa' ) );
			if ( $selected && ! self::public_testimonial( $comment ) ) return new \WP_Error( 'kiwe_context_private_comment', __( 'Choose an approved comment or review on public content.', 'dsa' ) );
		} else return new \WP_Error( 'kiwe_context_kind', __( 'Unknown selection type.', 'dsa' ) );
		if ( null !== $expected && self::selected( $kind, $id ) !== $expected ) return new \WP_Error( 'kiwe_context_stale', __( 'This selection changed elsewhere. Reload and review it before saving.', 'dsa' ) );
		if ( self::selected( $kind, $id ) === $selected ) return true;
		if ( 'media' === $kind ) {
			$selected ? update_post_meta( $id, self::MEDIA_META, '1' ) : delete_post_meta( $id, self::MEDIA_META );
		} else {
			$selected ? update_comment_meta( $id, self::TESTIMONIAL_META, '1' ) : delete_comment_meta( $id, self::TESTIMONIAL_META );
		}
		if ( self::selected( $kind, $id ) !== $selected ) return new \WP_Error( 'kiwe_context_write', __( 'Selection could not be confirmed. Refresh before trying again.', 'dsa' ) );
		do_action( 'kiwe_context_selection_changed', $kind, $id, $selected );
		return true;
	}

	/** Bounded live projection. Revoked approval or public visibility removes a quote immediately. */
	public static function testimonials( int $limit = 20 ): array {
		$comments = get_comments( [ 'status'=>'approve', 'type__in'=>[ '', 'comment', 'review' ], 'meta_key'=>self::TESTIMONIAL_META, 'meta_value'=>'1', 'number'=>min( 100, max( 1, $limit ) ), 'orderby'=>'comment_ID', 'order'=>'DESC' ] );
		$items = [];
		foreach ( $comments as $comment ) {
			if ( ! self::public_testimonial( $comment ) ) continue;
			$full_quote = trim( wp_strip_all_tags( $comment->comment_content ) );
			$quote = wp_html_excerpt( $full_quote, 2000, '' );
			$item = [ 'id'=>(int) $comment->comment_ID, 'source'=>'wordpress-comment', 'name'=>sanitize_text_field( $comment->comment_author ), 'quote'=>$quote, 'isExcerpt'=>$quote !== $full_quote, 'url'=>esc_url_raw( get_comment_link( $comment ) ) ];
			if ( 'product' === get_post_type( $comment->comment_post_ID ) ) {
				$item['source'] = 'woocommerce-review';
				$rating = get_comment_meta( $comment->comment_ID, 'rating', true );
				if ( in_array( (string) $rating, [ '1', '2', '3', '4', '5' ], true ) ) $item['rating'] = (int) $rating;
			}
			$items[] = $item;
		}
		return $items;
	}
}
