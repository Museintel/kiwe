<?php
namespace DSA\Access;

use DSA\Onboarding\Context_Selection_Service as Selection;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A bounded client projection of native WordPress comments and Woo reviews.
 *
 * WordPress continues to own every comment, status transition and notification.
 * Kiwe only adds an adjacent Design Context membership control.
 */
final class Comment_Moderation_Service {
	public const PAGE_SIZE = 16;
	private const STATUSES = [ 'hold', 'approve', 'spam', 'trash' ];

	public static function allowed(): bool { return current_user_can( 'moderate_comments' ); }
	private function error( string $code, string $message, int $status = 409 ): \WP_Error { return new \WP_Error( 'kiwe_feedback_' . $code, $message, [ 'status'=>$status ] ); }
	private function status( \WP_Comment $comment ): string {
		return [ '0'=>'hold', '1'=>'approve', 'spam'=>'spam', 'trash'=>'trash' ][ (string) $comment->comment_approved ] ?? 'hold';
	}
	private function expected( \WP_Comment $comment ): string {
		return hash_hmac( 'sha256', wp_json_encode( [
			'id'=>(int) $comment->comment_ID,
			'post'=>(int) $comment->comment_post_ID,
			'type'=>(string) $comment->comment_type,
			'status'=>$this->status( $comment ),
			'author'=>(string) $comment->comment_author,
			'content'=>(string) $comment->comment_content,
			'selected'=>Selection::selected( 'testimonial', (int) $comment->comment_ID ),
		] ), wp_salt( 'nonce' ) );
	}
	private function permitted( \WP_Comment $comment ): bool {
		return self::allowed() && current_user_can( 'read_post', (int) $comment->comment_post_ID ) && ( 'review' !== (string) $comment->comment_type || \DSA\Apps\Store_App_Service::enabled() ) && \DSA\Apps\Store_App_Service::content_available( (string) ( get_post( (int) $comment->comment_post_ID )->post_type ?? '' ) );
	}
	public function record( int $id ) {
		$comment = get_comment( $id );
		if ( ! $comment || ! $this->permitted( $comment ) ) return $this->error( 'forbidden', 'This feedback record is unavailable for your account.', 403 );
		$type = 'review' === (string) $comment->comment_type && \DSA\Apps\Store_App_Service::enabled() ? 'review' : 'comment';
		$selected = Selection::selected( 'testimonial', $id );
		$public = Selection::public_testimonial( $comment );
		$post = get_post( (int) $comment->comment_post_ID );
		return [
			'id'=>$id,
			'author'=>Task_Workspace_UI::text( $comment->comment_author ?: __( 'Anonymous', 'dsa' ), 200 ),
			'quote'=>Task_Workspace_UI::text( wp_trim_words( wp_strip_all_tags( (string) $comment->comment_content ), 80 ), 1200 ),
			'sourceTitle'=>$post ? Task_Workspace_UI::text( get_the_title( $post ) ?: __( 'Untitled', 'dsa' ), 240 ) : __( 'Unavailable content', 'dsa' ),
			'sourceType'=>$type,
			'status'=>$this->status( $comment ),
			'selected'=>$selected,
			'public'=>$public,
			'canSelect'=>current_user_can( 'kiwe_manage_context' ) && ( $public || $selected ),
			'url'=>$public ? (string) get_comment_link( $comment ) : '',
			'rating'=>'review' === $type ? min( 5, max( 0, (int) get_comment_meta( $id, 'rating', true ) ) ) : 0,
			'expected'=>$this->expected( $comment ),
		];
	}
	public function listing( array $input = [] ) {
		if ( ! self::allowed() ) return $this->error( 'forbidden', 'Comment moderation is unavailable for your account.', 403 );
		$page = min( 10000, max( 1, Task_Workspace_UI::id( $input['page'] ?? 1 ) ) );
		$search = Task_Workspace_UI::text( $input['search'] ?? '', 200 );
		$status = is_string( $input['status'] ?? null ) && in_array( $input['status'], array_merge( [ 'all' ], self::STATUSES ), true ) ? $input['status'] : 'hold';
		$type = is_string( $input['type'] ?? null ) && in_array( $input['type'], [ 'all','comment','review','testimonial' ], true ) ? $input['type'] : 'all';
		$sort = in_array( $input['sort'] ?? '', [ 'date_desc','date_asc' ], true ) ? $input['sort'] : 'date_desc';
		$has_reviews = \DSA\Apps\Store_App_Service::enabled();
		if ( 'testimonial' === $type && ! current_user_can( 'kiwe_manage_context' ) ) return $this->error( 'forbidden', 'Testimonial curation is unavailable for your account.', 403 );
		if ( 'review' === $type && ! $has_reviews ) $type = 'comment';
		$args = [
			'status'=>$status,
			'number'=>self::PAGE_SIZE + 1,
			'offset'=>( $page - 1 ) * self::PAGE_SIZE,
			'orderby'=>'comment_ID',
			'order'=>'date_asc' === $sort ? 'ASC' : 'DESC',
			'search'=>$search,
			'no_found_rows'=>true,
		];
		if ( 'review' === $type ) $args['type__in'] = [ 'review' ];
		elseif ( 'comment' === $type || ! $has_reviews ) $args['type__in'] = [ '', 'comment' ];
		else $args['type__in'] = [ '', 'comment', 'review' ];
		if ( 'testimonial' === $type ) { $args['meta_key'] = Selection::TESTIMONIAL_META; $args['meta_value'] = '1'; }
		$records = get_comments( $args ); $items = [];
		foreach ( $records as $comment ) {
			if ( count( $items ) > self::PAGE_SIZE || ! $this->permitted( $comment ) ) continue;
			$record = $this->record( (int) $comment->comment_ID );
			if ( ! is_wp_error( $record ) ) $items[] = $record;
		}
		$has_next = count( $items ) > self::PAGE_SIZE;
		return [
			'items'=>array_slice( $items, 0, self::PAGE_SIZE),
			'page'=>$page,
			'hasNext'=>$has_next,
			'search'=>$search,
			'status'=>$status,
			'type'=>$type,
			'sort'=>$sort,
			'hasReviews'=>$has_reviews,
			'canCurate'=>current_user_can( 'kiwe_manage_context' ),
		];
	}
	public function change_status( array $input ) {
		if ( ! self::allowed() ) return $this->error( 'forbidden', 'Your account cannot moderate feedback.', 403 );
		if ( array_diff( array_keys( $input ), [ 'id','status','expected','acceptEffect' ] ) || ! is_int( $input['id'] ?? null ) || $input['id'] < 1 || ! in_array( $input['status'] ?? null, self::STATUSES, true ) || ! is_string( $input['expected'] ?? null ) || true !== ( $input['acceptEffect'] ?? null ) ) return $this->error( 'input', 'Review and confirm one feedback status change.', 400 );
		global $wpdb; $lock = 'kiwe-feedback-' . substr( hash( 'sha256', $wpdb->prefix . ':' . $input['id'] ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) return $this->error( 'busy', 'This feedback is being updated. Refresh before trying again.' );
		try {
			$before = $this->record( $input['id'] ); if ( is_wp_error( $before ) ) return $before;
			if ( ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'This feedback changed. Refresh and review its current status.' );
			if ( $before['status'] === $input['status'] ) return $before;
			if ( ! wp_set_comment_status( $input['id'], $input['status'], true ) ) return $this->error( 'write', 'WordPress did not confirm the moderation change.' );
			$after = $this->record( $input['id'] );
			if ( is_wp_error( $after ) || $after['status'] !== $input['status'] || $after['author'] !== $before['author'] || $after['quote'] !== $before['quote'] || $after['sourceType'] !== $before['sourceType'] || $after['selected'] !== $before['selected'] ) return $this->error( 'readback', 'The saved moderation state differs. Refresh and review before retrying.' );
			return $after;
		} catch ( \Throwable $error ) { return $this->error( 'write', 'The moderation change could not be confirmed. Refresh and review.' ); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	public function select( array $input ) {
		if ( ! self::allowed() || ! current_user_can( 'kiwe_manage_context' ) ) return $this->error( 'forbidden', 'Your account cannot choose public testimonials.', 403 );
		if ( array_diff( array_keys( $input ), [ 'id','selected','expected' ] ) || ! is_int( $input['id'] ?? null ) || $input['id'] < 1 || ! is_bool( $input['selected'] ?? null ) || ! is_bool( $input['expected'] ?? null ) ) return $this->error( 'input', 'Choose one testimonial action.', 400 );
		$comment = get_comment( $input['id'] ); if ( ! $comment || ! $this->permitted( $comment ) ) return $this->error( 'forbidden', 'This feedback is unavailable.', 403 );
		$result = ( new Selection() )->select( 'testimonial', $input['id'], $input['selected'], $input['expected'] );
		return is_wp_error( $result ) ? $result : $this->record( $input['id'] );
	}
}
