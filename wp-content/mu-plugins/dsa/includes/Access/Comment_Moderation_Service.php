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
	private array $verified = [];
	private array $purchases = [];
	private array $social = [];
	private array $primed = [];
	private bool $actions_available = false;
	private function prime( array $comments ): void {
		$ids = array_map( static fn( $c ) => (int) $c->comment_ID, $comments );
		$users = array_values( array_unique( array_filter( array_map( static fn( $c ) => (int) $c->user_id, $comments ) ) ) );
		if ( $users ) cache_users( $users );
		$proof = \DSA\PhoneKey\PhoneKey_Bridge::verified_accounts( $users ); $this->verified += $proof;
		if ( \DSA\Apps\Store_App_Service::enabled() && class_exists( '\DSA\Commerce\Discussion_Review_Adapter' ) ) $this->purchases += \DSA\Commerce\Discussion_Review_Adapter::customer_context( $users, $proof );
		$social = \DSA\Discussion\Discussion_Reaction_Store::projection( $ids, get_current_user_id() ); $this->actions_available = \DSA\Discussion\Discussion_Reaction_Store::ready() && ! is_wp_error( $social ); if ( ! is_wp_error( $social ) ) $this->social += $social;
		$this->primed += array_fill_keys( $ids, true );
	}

	public static function allowed(): bool { return current_user_can( 'moderate_comments' ) && ! Team_Workspace_Service::suspended( get_current_user_id() ) && ( ! is_multisite() || is_user_member_of_blog( get_current_user_id() ) ); }
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
	private function reports_expected( \WP_Comment $comment, int $reports ): string {
		return hash_hmac( 'sha256', $this->expected( $comment ) . '|' . $reports . '|' . \DSA\Discussion\Discussion_Reactions::revision( (int) $comment->comment_post_ID ), wp_salt( 'nonce' ) );
	}
	private function permitted( \WP_Comment $comment ): bool {
		return self::allowed() && current_user_can( 'read_post', (int) $comment->comment_post_ID ) && ( 'review' !== (string) $comment->comment_type || \DSA\Apps\Store_App_Service::enabled() ) && \DSA\Apps\Store_App_Service::content_available( (string) ( get_post( (int) $comment->comment_post_ID )->post_type ?? '' ) );
	}
	public function record( int $id ) {
		$comment = get_comment( $id );
		if ( ! $comment || ! $this->permitted( $comment ) ) return $this->error( 'forbidden', 'This feedback record is unavailable for your account.', 403 );
		if ( ! isset( $this->primed[$id] ) ) $this->prime( [ $comment ] );
		$type = 'product' === get_post_type( $comment->comment_post_ID ) && \DSA\Apps\Store_App_Service::enabled() ? 'review' : 'comment';
		$selected = Selection::selected( 'testimonial', $id );
		$public = Selection::public_testimonial( $comment );
		$post = get_post( (int) $comment->comment_post_ID );
		$target = \DSA\Discussion\Discussion_Target::get( (int) $comment->comment_post_ID );
		$descriptor = ! is_wp_error( $target ) ? \DSA\Discussion\Discussion_Target::descriptor( $target ) : [];
		$can_react = ! is_wp_error( $target ) && '1' === (string) $comment->comment_approved && ! is_wp_error( \DSA\Discussion\Discussion_Target::parent( $post, $id ) ) && \DSA\Discussion\Discussion_Reaction_Store::ready();
		$verified = ! empty( $this->verified[(int) $comment->user_id] );
		return [
			'id'=>$id,
			'author'=>Task_Workspace_UI::text( $comment->comment_author ?: __( 'Anonymous', 'dsa' ), 200 ),
			'quote'=>Task_Workspace_UI::text( wp_trim_words( wp_strip_all_tags( (string) $comment->comment_content ), 80 ), 1200 ),
			'text'=>Task_Workspace_UI::text( wp_strip_all_tags( (string) $comment->comment_content ), 5000 ),
			'createdAt'=>max( 0, (int) strtotime( $comment->comment_date_gmt . ' UTC' ) ),
			'parent'=>(int) $comment->comment_parent,
			'context'=>'product' === ( $post->post_type ?? '' ) ? 'product' : ( 'page' === ( $post->post_type ?? '' ) ? 'website' : 'post' ),
			'likes'=>$this->actions_available ? (int) ( $this->social[$id]['likes'] ?? 0 ) : null,
			'reports'=>$this->actions_available ? (int) ( $this->social[$id]['reports'] ?? 0 ) : null,
			'reportsExpected'=>$this->actions_available ? $this->reports_expected( $comment, (int) ( $this->social[$id]['reports'] ?? 0 ) ) : '',
			'verified'=>$verified,
			'tone'=>'approve' === $this->status( $comment ) && $verified ? 'success' : ( 'approve' === $this->status( $comment ) ? 'info' : ( in_array( $this->status( $comment ), [ 'spam','trash' ], true ) ? 'danger' : 'waiting' ) ),
			'purchase'=>$this->purchases[(int) $comment->user_id] ?? null,
			'canReply'=>! empty( $descriptor['open'] ) && (bool) get_option( 'thread_comments' ) && ! is_wp_error( \DSA\Discussion\Discussion_Target::parent( $post, $id ) ),
			'social'=>$can_react ? \DSA\Discussion\Discussion_Reactions::decorate( $comment, $this->social[$id] ?? [], [ 'canModerate'=>true ] ) : null,
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
		$status = is_string( $input['status'] ?? null ) && in_array( $input['status'], array_merge( [ 'all' ], self::STATUSES ), true ) ? $input['status'] : 'all';
		$type = is_string( $input['type'] ?? null ) && in_array( $input['type'], [ 'all','post','product','website','comment','review','testimonial' ], true ) ? $input['type'] : 'all';
		$sort = in_array( $input['sort'] ?? '', [ 'date_desc','date_asc' ], true ) ? $input['sort'] : 'date_desc';
		$has_reviews = \DSA\Apps\Store_App_Service::enabled();
		if ( 'testimonial' === $type && ! current_user_can( 'kiwe_manage_context' ) ) return $this->error( 'forbidden', 'Testimonial curation is unavailable for your account.', 403 );
		if ( in_array( $type, [ 'review','product' ], true ) && ! $has_reviews ) $type = 'all';
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
		if ( in_array( $type, [ 'review','product' ], true ) ) $args['post_type'] = 'product';
		if ( 'website' === $type ) $args['post_type'] = 'page';
		if ( 'post' === $type ) $args['post_type'] = array_values( array_filter( get_post_types( [ 'public'=>true ], 'names' ), static fn( $name ) => ! in_array( $name, [ 'page','product','attachment' ], true ) && post_type_supports( $name, 'comments' ) ) ) ?: [ '__kiwe_no_discussion_post_type__' ];
		if ( 'testimonial' === $type ) { $args['meta_key'] = Selection::TESTIMONIAL_META; $args['meta_value'] = '1'; }
		$records = get_comments( $args ); $items = []; $focus = null;
		$focus_id = Task_Workspace_UI::id( $input['id'] ?? 0 ); $focused = $focus_id ? get_comment( $focus_id ) : null;
		$prime = array_values( array_filter( $records, fn( $c ) => $this->permitted( $c ) ) );
		if ( $focused && $this->permitted( $focused ) && ! in_array( $focus_id, array_map( static fn( $c ) => (int) $c->comment_ID, $prime ), true ) ) $prime[] = $focused;
		$this->prime( $prime );
		foreach ( $records as $comment ) {
			if ( count( $items ) > self::PAGE_SIZE || ! $this->permitted( $comment ) ) continue;
			$record = $this->record( (int) $comment->comment_ID );
			if ( ! is_wp_error( $record ) ) $items[] = $record;
		}
		$has_next = count( $items ) > self::PAGE_SIZE;
		if ( $focused && $this->permitted( $focused ) ) { $focus = $this->record( $focus_id ); if ( is_wp_error( $focus ) ) $focus = null; }
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
			'focus'=>$focus,
			'actorKey'=>( new \DSA\Discussion\Discussion_Service() )->current_actor_key(),
			'publicName'=>\DSA\Discussion\Public_Identity::current_name(),
			'policy'=>current_user_can( 'manage_options' ) ? \DSA\Discussion\Discussion_Settings::read() : null,
		];
	}
	public function reply( array $input ) {
		if ( array_diff( array_keys( $input ), [ 'id','expected','text','name','sendId','actorKey' ] ) || ! is_int( $input['id'] ?? null ) || ! is_string( $input['expected'] ?? null ) ) return $this->error( 'input', 'Review one reply before sending.', 400 );
		$record = $this->record( $input['id'] ); if ( is_wp_error( $record ) ) return $record;
		if ( ! hash_equals( $record['expected'], $input['expected'] ) ) return $this->error( 'stale', 'This conversation changed. Refresh before replying.' );
		$comment = get_comment( $input['id'] );
		return ( new \DSA\Discussion\Discussion_Service() )->submit( (int) $comment->comment_post_ID, [ 'parent'=>(int) $comment->comment_ID, 'text'=>$input['text'] ?? null, 'name'=>$input['name'] ?? '', 'sendId'=>$input['sendId'] ?? null, 'actorKey'=>$input['actorKey'] ?? null ] );
	}
	public function reaction( array $input ) {
		if ( array_diff( array_keys( $input ), [ 'id','kind','enabled','expected','actorKey' ] ) || ! is_int( $input['id'] ?? null ) || ! in_array( $input['kind'] ?? '', [ 'heart','pin' ], true ) ) return $this->error( 'input', 'Choose one website-team action.', 400 );
		$record = $this->record( $input['id'] ); if ( is_wp_error( $record ) ) return $record;
		$comment = get_comment( $input['id'] ); unset( $input['id'] );
		return ( new \DSA\Discussion\Discussion_Reactions() )->set( (int) $comment->comment_post_ID, (int) $comment->comment_ID, $input );
	}
	public function resolve_reports( array $input ) {
		if ( array_diff( array_keys( $input ), [ 'id','expected','actorKey' ] ) || ! is_int( $input['id'] ?? null ) || ! is_string( $input['expected'] ?? null ) || ! is_string( $input['actorKey'] ?? null ) ) return $this->error( 'input', 'Review one report group.', 400 );
		$record = $this->record( $input['id'] ); if ( is_wp_error( $record ) ) return $record;
		if ( ! hash_equals( ( new \DSA\Discussion\Discussion_Service() )->current_actor_key(), $input['actorKey'] ) ) return $this->error( 'actor', 'Your account changed. Reopen Feedback.' );
		global $wpdb; $id = $input['id']; $lock = 'kiwe-feedback-' . substr( hash( 'sha256', $wpdb->prefix . ':' . $id ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) return $this->error( 'busy', 'This comment is being reviewed. Try again shortly.' );
		$committed = false; $begun = false;
		try {
			if ( ! \DSA\Discussion\Discussion_Storage::begin( true ) ) return $this->error( 'storage', 'Reports are temporarily unavailable.', 503 );
			$begun = true;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID=%d FOR UPDATE", $id ) );
			if ( $wpdb->last_error || ! $row ) return $this->error( 'storage', 'Reports are temporarily unavailable.', 503 );
			$comment = new \WP_Comment( $row ); if ( ! $this->permitted( $comment ) ) return $this->error( 'forbidden', 'This feedback is no longer available to your account.', 403 );
			$projection = \DSA\Discussion\Discussion_Reaction_Store::projection( [ $id ], get_current_user_id() ); if ( is_wp_error( $projection ) ) return $projection;
			$count = (int) ( $projection[$id]['reports'] ?? 0 ); wp_cache_delete( (int) $comment->comment_post_ID, 'post_meta' );
			if ( $count && ! hash_equals( $this->reports_expected( $comment, $count ), $input['expected'] ) ) return $this->error( 'stale', 'The reports or conversation changed. Reload before reviewing.' );
			if ( $count && ( ! \DSA\Discussion\Discussion_Reaction_Store::clear_reports( $id ) || ! \DSA\Discussion\Discussion_Reactions::changed( (int) $comment->comment_post_ID ) ) ) return $this->error( 'write', 'Report review could not be confirmed.', 503 );
			if ( ! \DSA\Discussion\Discussion_Storage::commit() ) return $this->error( 'write', 'Report review could not be confirmed.', 503 );
			$committed = true;
		} finally {
			if ( $begun ) { if ( ! $committed ) { \DSA\Discussion\Discussion_Storage::rollback(); wp_cache_delete( (int) ( $row->comment_post_ID ?? 0 ), 'post_meta' ); } \DSA\Discussion\Discussion_Storage::restore(); }
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		unset( $this->primed[$id], $this->social[$id] ); return $this->record( $id );
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
