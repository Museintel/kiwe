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
		if ( ! $comment instanceof \WP_Comment || '1' !== (string) $comment->comment_approved ) return false;
		$post = \DSA\Discussion\Discussion_Target::get( (int) $comment->comment_post_ID );
		if ( is_wp_error( $post ) || ! in_array( $comment->comment_type, \DSA\Discussion\Discussion_Target::types( $post ), true ) ) return false;
		// A held ancestor also hides its replies. Staff replies are not customer endorsements.
		return ! is_wp_error( \DSA\Discussion\Discussion_Target::parent( $post, (int) $comment->comment_ID ) )
			&& ! \DSA\Discussion\Public_Identity::team_member( $comment )
			&& '' !== trim( wp_strip_all_tags( $comment->comment_content ) );
	}

	/** This policy lives in the existing context profile, not a second selection store. */
	public static function testimonial_sources( ?array $input = null ): array {
		if ( null === $input ) {
			$profile = (array) get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
			$input = (array) ( $profile['contentPlan']['testimonialSources'] ?? [] );
		}
		$out = [];
		foreach ( [ 'pinned'=>true, 'hearted'=>true, 'popular'=>false ] as $key=>$default ) $out[$key] = array_key_exists( $key, $input ) ? in_array( $input[$key], [ true, 1, '1' ], true ) : $default;
		return $out;
	}
	public static function testimonial_candidate( int $id ): bool {
		$policy = self::testimonial_sources();
		if ( $policy['pinned'] && '1' === (string) get_comment_meta( $id, \DSA\Discussion\Discussion_Reactions::PIN, true ) ) return true;
		if ( $policy['hearted'] && ( (int) get_comment_meta( $id, \DSA\Discussion\Discussion_Reactions::HEART, true ) > 0 || self::selected( 'testimonial', $id ) ) ) return true;
		return $policy['popular'] && \DSA\Discussion\Discussion_Reaction_Store::has_likes( $id );
	}
	/** Trusted compatibility operation, invoked by an owner/installer, never on a public read. */
	public static function migrate_testimonials( int $limit = 50 ) {
		if ( ! current_user_can( 'kiwe_manage_context' ) || ! current_user_can( 'moderate_comments' ) ) return new \WP_Error( 'kiwe_context_forbidden', __( 'Your account cannot migrate curation.', 'dsa' ) );
		global $wpdb;
		$option = 'kiwe_testimonial_curation_migration_v1';
		$cursor = max( 0, (int) get_option( $option, 0 ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT c.comment_ID FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id=c.comment_ID WHERE m.meta_key=%s AND m.meta_value='1' AND c.comment_ID>%d ORDER BY c.comment_ID ASC LIMIT %d", self::TESTIMONIAL_META, $cursor, max( 1, min( 100, $limit ) ) ) );
		if ( $wpdb->last_error ) return new \WP_Error( 'kiwe_context_read', __( 'Curation could not be read.', 'dsa' ) );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! metadata_exists( 'comment', $id, \DSA\Discussion\Discussion_Reactions::HEART ) && ! add_comment_meta( $id, \DSA\Discussion\Discussion_Reactions::HEART, '1', true ) ) return new \WP_Error( 'kiwe_context_write', __( 'Curation could not be confirmed.', 'dsa' ) );
			// Retire only the legacy membership flag; the native comment and its content stay intact.
			delete_comment_meta( $id, self::TESTIMONIAL_META );
			if ( metadata_exists( 'comment', $id, self::TESTIMONIAL_META ) ) return new \WP_Error( 'kiwe_context_write', __( 'Curation could not be confirmed.', 'dsa' ) );
			$cursor = $id;
			update_option( $option, $cursor, false );
		}
		return [ 'processed'=>count( $ids ), 'complete'=>count( $ids ) < max( 1, min( 100, $limit ) ) ];
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

	/** Bounded live projection of feedback, rechecking public visibility and safe names. */
	public static function testimonials( int $limit = 20 ): array {
		$limit = min( 100, max( 1, $limit ) ); $policy = self::testimonial_sources();
		if ( ! array_filter( $policy ) ) return [];
		global $wpdb;
		$conditions = [];
		if ( $policy['pinned'] ) $conditions[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->commentmeta} tm WHERE tm.comment_id={$wpdb->comments}.comment_ID AND tm.meta_key=%s AND tm.meta_value='1')", \DSA\Discussion\Discussion_Reactions::PIN );
		if ( $policy['hearted'] ) $conditions[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->commentmeta} tm WHERE tm.comment_id={$wpdb->comments}.comment_ID AND ((tm.meta_key=%s AND CAST(tm.meta_value AS UNSIGNED)>0) OR (tm.meta_key=%s AND tm.meta_value='1')))", \DSA\Discussion\Discussion_Reactions::HEART, self::TESTIMONIAL_META );
		if ( $policy['popular'] && \DSA\Discussion\Discussion_Reaction_Store::ready() ) $conditions[] = 'EXISTS (SELECT 1 FROM ' . \DSA\Discussion\Discussion_Reaction_Store::table() . " tr WHERE tr.comment_id={$wpdb->comments}.comment_ID AND tr.kind='like')";
		if ( ! $conditions ) return [];
		$filter = static function( array $clauses, \WP_Comment_Query $query ) use ( $conditions ): array {
			if ( ! empty( $query->query_vars['kiwe_testimonial_projection'] ) ) $clauses['where'] .= ' AND (' . implode( ' OR ', $conditions ) . ')';
			return $clauses;
		};
		add_filter( 'comments_clauses', $filter, 25, 2 );
		// WP_Comment_Query keys only native arguments, not comments_clauses or custom flags.
		$domain = 'kiwe-testimonials-' . hash( 'sha256', wp_json_encode( [ $policy, \DSA\Apps\Store_App_Service::enabled(), wp_cache_get_last_changed( 'kiwe_testimonials' ) ] ) );
		try { $comments = get_comments( [ 'status'=>'approve', 'post_status'=>'publish', 'type__in'=>[ '', 'comment', 'review' ], 'number'=>500, 'orderby'=>'comment_ID', 'order'=>'DESC', 'kiwe_testimonial_projection'=>true, 'cache_domain'=>$domain, 'update_comment_post_cache'=>true ] ); }
		finally { remove_filter( 'comments_clauses', $filter, 25 ); }
		$users = array_values( array_unique( array_filter( array_map( static fn( $comment ) => (int) $comment->user_id, $comments ) ) ) );
		if ( $users ) cache_users( $users );
		$items = [];
		foreach ( $comments as $comment ) {
			if ( ! self::public_testimonial( $comment ) ) continue;
			$full_quote = trim( wp_strip_all_tags( $comment->comment_content ) );
			$quote = wp_html_excerpt( $full_quote, 2000, '' );
			$post = get_post( $comment->comment_post_ID );
			$item = [ 'id'=>(int) $comment->comment_ID, 'source'=>'wordpress-comment', 'name'=>\DSA\Discussion\Public_Identity::comment_name( $comment ), 'quote'=>$quote, 'isExcerpt'=>$quote !== $full_quote, 'url'=>esc_url_raw( get_comment_link( $comment ) ), 'target_id'=>(int) $comment->comment_post_ID, 'source_type'=>'page' === $post->post_type ? 'website' : 'post', 'source_title'=>wp_strip_all_tags( get_the_title( $post ) ), 'pinned'=>'1' === (string) get_comment_meta( $comment->comment_ID, \DSA\Discussion\Discussion_Reactions::PIN, true ), 'hearted'=>(int) get_comment_meta( $comment->comment_ID, \DSA\Discussion\Discussion_Reactions::HEART, true ) > 0 || self::selected( 'testimonial', (int) $comment->comment_ID ) ];
			if ( 'product' === get_post_type( $comment->comment_post_ID ) ) {
				$item['source'] = 'woocommerce-review';
				$item['source_type'] = 'product';
				$rating = get_comment_meta( $comment->comment_ID, 'rating', true );
				if ( ! $comment->comment_parent && function_exists( 'wc_review_ratings_enabled' ) && wc_review_ratings_enabled() && in_array( (string) $rating, [ '1', '2', '3', '4', '5' ], true ) ) $item['rating'] = (int) $rating;
			}
			$items[] = $item;
			if ( count( $items ) >= $limit ) break;
		}
		// The account IDs and reports in the reaction store never enter public context.
		foreach ( array_chunk( array_column( $items, 'id' ), 50 ) as $ids ) {
			$counts = \DSA\Discussion\Discussion_Reaction_Store::projection( $ids, 0 );
			foreach ( $items as &$item ) if ( in_array( $item['id'], $ids, true ) ) $item['likes'] = is_wp_error( $counts ) ? 0 : (int) ( $counts[$item['id']]['likes'] ?? 0 );
			unset( $item );
		}
		return $items;
	}
}
