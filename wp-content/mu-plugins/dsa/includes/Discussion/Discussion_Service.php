<?php
namespace DSA\Discussion;

use DSA\Utilities\Atomic_Rate_Limiter;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Bounded discussion over WordPress records, validation, moderation and invalidation. */
final class Discussion_Service {
	public const PAGE_SIZE = 12;
	private const SEND_META = '_kiwe_discussion_send_v1';
	private const BODY_META = '_kiwe_discussion_body_v1';
	private const ACTOR_META = '_kiwe_discussion_actor_v1';
	public static function privacy_metadata(): array { return [ self::SEND_META, self::BODY_META, self::ACTOR_META, '_kiwe_discussion_name_explicit_v1', '_kiwe_discussion_verified_v1', '_kiwe_discussion_source_v1' ]; }
	private function error( string $code, string $message, int $status = 400 ): \WP_Error { return new \WP_Error( 'kiwe_discussion_' . $code, $message, [ 'status'=>$status ] ); }
	/** Native forms and REST retain spam/manual moderation; unverified participation cannot auto-publish. */
	public static function approval( $approved, array $data ) {
		if ( is_wp_error( $approved ) || ! in_array( (string) $approved, [ '0','1' ], true ) ) return $approved;
		$post = Discussion_Target::get( (int) ( $data['comment_post_ID'] ?? 0 ) );
		if ( is_wp_error( $post ) || ! in_array( (string) ( $data['comment_type'] ?? 'comment' ), Discussion_Target::types( $post ), true ) ) return $approved;
		$user = (int) ( $data['user_id'] ?? 0 );
		if ( $user > 0 && $user === get_current_user_id() && ( Public_Identity::verified( $user ) || user_can( $user, 'moderate_comments' ) ) ) return $approved;
		return 0;
	}
	public static function author_name( string $name, $id, $comment ): string {
		return ! is_admin() && $comment instanceof \WP_Comment && ! is_wp_error( Discussion_Target::get( (int) $comment->comment_post_ID ) ) ? Public_Identity::comment_name( $comment ) : $name;
	}
	private function device(): string { return \DSA\PhoneKey\PhoneKey_Bridge::discussion_device_key(); }
	private function actor_key( string $device ): string { return hash_hmac( 'sha256', $device . '|user:' . get_current_user_id(), wp_salt( 'nonce' ) ); }
	public function current_actor_key(): string { $device=$this->device(); return $device ? $this->actor_key($device) : ''; }
	private function query_args( \WP_Post $post, int $parent ): array { return [ 'post_id'=>$post->ID, 'post_type'=>$post->post_type, 'parent'=>$parent, 'status'=>'approve', 'type__in'=>Discussion_Target::types( $post ), 'no_found_rows'=>true ]; }
	public function count( \WP_Post $post, int $parent = 0 ): int|\WP_Error {
		global $wpdb; $wpdb->last_error = ''; $count = get_comments( $this->query_args( $post, $parent ) + [ 'count'=>true ] );
		if ( $wpdb->last_error ) { wp_cache_set_last_changed( 'comment' ); return $this->error( 'counts_unavailable', __( 'Conversation counts are temporarily unavailable.', 'dsa' ), 503 ); }
		return (int) $count;
	}
	/** One native aggregate for explicitly rendered controls; no site-wide polling or per-card requests. */
	public function counts( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( count( $ids ) > 50 ) return [];
		if ( $ids ) _prime_post_caches( $ids, false, false );
		$out = []; $eligible = []; $products = [];
		foreach ( $ids as $id ) {
			$post = Discussion_Target::get( $id ); $out[$id] = [ 'available'=>! is_wp_error( $post ), 'count'=>0 ];
			if ( ! is_wp_error( $post ) ) { $eligible[]=$id; if ( 'product' === $post->post_type ) $products[]=$id; }
		}
		if ( ! $eligible ) return $out;
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $eligible ), '%d' ) );
		$review = $products ? " OR (comment_type='review' AND comment_post_ID IN (" . implode( ',', array_fill( 0, count( $products ), '%d' ) ) . '))' : '';
		$sql = "SELECT comment_post_ID,COUNT(*) AS total FROM {$wpdb->comments} WHERE comment_post_ID IN ({$in}) AND comment_parent=0 AND comment_approved='1' AND (comment_type IN ('','comment'){$review}) GROUP BY comment_post_ID";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $eligible, $products ) ) );
		if ( $wpdb->last_error ) return $this->error( 'counts_unavailable', __( 'Conversation counts are temporarily unavailable.', 'dsa' ), 503 );
		foreach ( (array) $rows as $row ) $out[(int) $row->comment_post_ID]['count']=(int) $row->total;
		return $out;
	}
	public function listing( int $id, int $parent = 0, int $before = 0, string $sort = 'latest', string $cursor_token = '', int $focus_id = 0 ) {
		$post = Discussion_Target::get( $id ); if ( is_wp_error( $post ) ) return $post;
		$ancestry = Discussion_Target::parent( $post, $parent ); if ( is_wp_error( $ancestry ) ) return $ancestry;
		if(!in_array($sort,['top','latest'],true))return $this->error('sort',__('Choose Top or Latest.','dsa'));
		if('top'===$sort&&!Discussion_Reaction_Store::ready())$sort='latest';
		$revision='top'===$sort?Discussion_Reactions::revision($id):'';
		$after='top'===$sort?Discussion_Order::decode($cursor_token,$id,$parent,$revision):[];if(is_wp_error($after))return $after;
		$scope=$id.'|'.$parent.'|'.$sort.'|'.$before.'|'.hash('sha256',$cursor_token);
		// WordPress hashes its recognized query variables before SQL filters run. Keep each cursor's native cache distinct.
		$args = $this->query_args( $post, $parent ) + [ 'number'=>self::PAGE_SIZE + 1, 'orderby'=>'comment_ID', 'order'=>'DESC', 'update_comment_meta_cache'=>true, 'cache_domain'=>'kiwe-discussion-v1-' . $scope . '-' . $revision ];
		$cursor = static function( array $clauses, \WP_Comment_Query $query ) use ( $before, $scope, $sort, $post, $parent, $after ): array {
			if(($query->query_vars['_kiwe_discussion_scope']??'')!==$scope)return $clauses;
			if('top'===$sort)return Discussion_Order::clauses($clauses,$post,$parent,$after);
			if ( $before ) { global $wpdb; $clauses['where'] .= $wpdb->prepare( ' AND comment_ID < %d', $before ); }
			return $clauses;
		};
		$args['_kiwe_discussion_scope'] = $scope; add_filter( 'comments_clauses', $cursor, 100, 2 );
		// A native cache hit does not execute SQL or clear an earlier unrelated query error.
		global $wpdb; $wpdb->last_error = '';
		try { $comments = get_comments( $args ); } finally { remove_filter( 'comments_clauses', $cursor, 100 ); }
		global $wpdb;if($wpdb->last_error) {
			if('top'===$sort) { Discussion_Reaction_Store::unavailable(); return $this->listing($id,$parent,0,'latest','',$focus_id); }
			return $this->error('read',__('The conversation could not be loaded. Please try again.','dsa'),503);
		}
		$has_more = count( $comments ) > self::PAGE_SIZE; $comments = array_slice( $comments, 0, self::PAGE_SIZE );
		$focused = ! $parent && $focus_id ? get_comment( $focus_id ) : null;
		$focus_ancestry = $focused ? Discussion_Target::parent( $post, $focus_id ) : null;
		if ( is_wp_error( $focus_ancestry ) ) $focused = null;
		$visible = $comments;
		if ( $focused && ! in_array( $focus_id, array_map( static fn( $c ) => (int) $c->comment_ID, $comments ), true ) ) $visible[] = $focused;
		$users = array_values( array_unique( array_filter( array_map( static fn( $c ) => (int) $c->user_id, $visible ) ) ) ); if ( $users ) cache_users( $users );
		$reply_counts = $this->reply_counts( $post, $visible );
		if ( is_wp_error( $reply_counts ) ) return $reply_counts;
		$verified = 'product' === $post->post_type ? \DSA\PhoneKey\PhoneKey_Bridge::verified_accounts( $users ) : [];
		$viewer=Discussion_Reactions::viewer($id);$viewer['reactionsAvailable']=Discussion_Reaction_Store::ready();
		$reactions=Discussion_Reaction_Store::projection(array_map(static fn($c)=>(int)$c->comment_ID,$visible),get_current_user_id());
		if(is_wp_error($reactions)) { $viewer['reactionsAvailable']=false; $reactions=[]; }
		$items = array_map( fn( $c ) => $this->project( $c, $post, $reply_counts[(int) $c->comment_ID] ?? 0, $verified, $ancestry['depth'] + 1 ) + Discussion_Reactions::decorate($c,$reactions[(int)$c->comment_ID]??[],$viewer), $comments );
		if('top'===$sort&&!hash_equals($revision,Discussion_Reactions::revision($id)))return $this->error('cursor_changed',__('The conversation changed. Refresh it or choose Latest to keep browsing.','dsa'));
		$focus = $focused ? $this->project( $focused, $post, $reply_counts[$focus_id] ?? 0, $verified, $focus_ancestry['depth'] ) + Discussion_Reactions::decorate( $focused, $reactions[$focus_id] ?? [], $viewer ) : null;
		$count = $this->count( $post ); $thread_count = $parent ? $this->count( $post, $parent ) : null;
		if ( is_wp_error( $count ) || is_wp_error( $thread_count ) ) return is_wp_error( $count ) ? $count : $thread_count;
		return [ 'target'=>Discussion_Target::descriptor( $post ), 'viewer'=>$viewer,'sort'=>$sort,'count'=>$count, 'threadCount'=>$thread_count, 'parent'=>$parent, 'items'=>$items, 'focus'=>$focus, 'focusUnavailable'=>$focus_id > 0 && ! $focus, 'nextBefore'=>$has_more && $comments && 'latest'===$sort ? (int) end( $comments )->comment_ID : 0,'nextCursor'=>$has_more && $items && 'top'===$sort?Discussion_Order::cursor($id,$parent,$revision,end($items)):'' ];
	}
	/** One bounded aggregate for the visible page, invalidated by native comment changes. */
	private function reply_counts( \WP_Post $post, array $comments ): array|\WP_Error {
		if ( ! $comments ) return [];
		$ids = array_map( static fn( $c ) => (int) $c->comment_ID, $comments );
		$key = 'discussion-replies:' . md5( $post->ID . '|' . implode( ',', $ids ) . '|' . wp_cache_get_last_changed( 'comment' ) );
		$cached = wp_cache_get( $key, 'kiwe_discussion' ); if ( is_array( $cached ) ) return $cached;
		global $wpdb;
		$types = Discussion_Target::types( $post );
		$sql = "SELECT comment_parent, COUNT(*) AS total FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_approved='1' AND comment_parent IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND comment_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') GROUP BY comment_parent';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ $post->ID ], $ids, $types ) ) );
		if ( $wpdb->last_error ) return $this->error( 'replies_unavailable', __( 'Reply counts are temporarily unavailable.', 'dsa' ), 503 );
		$out = array_fill_keys( $ids, 0 ); foreach ( (array) $rows as $row ) $out[(int) $row->comment_parent] = (int) $row->total;
		if ( ! $wpdb->last_error ) wp_cache_set( $key, $out, 'kiwe_discussion', 60 );
		return $out;
	}
	private function project( \WP_Comment $comment, \WP_Post $post, int $replies, array $verified, int $depth ): array {
		$review = 'product' === $post->post_type;
		return [ 'id'=>(int) $comment->comment_ID, 'parent'=>(int) $comment->comment_parent, 'depth'=>$depth, 'name'=>Public_Identity::comment_name( $comment ), 'team'=>Public_Identity::team_member( $comment ), 'text'=>html_entity_decode( wp_strip_all_tags( $comment->comment_content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'date'=>(string) $comment->comment_date_gmt, 'replies'=>$replies, 'rating'=>$review ? \DSA\Commerce\Discussion_Review_Adapter::rating( $comment ) : 0, 'buyer'=>$review && \DSA\Commerce\Discussion_Review_Adapter::verified_badge( $comment, $verified ) ];
	}
	public function composer( int $id ) {
		$post = Discussion_Target::get( $id ); if ( is_wp_error( $post ) ) return $post;
		$device = $this->device(); $actor = Public_Identity::actor(); $phonekey = \DSA\PhoneKey\PhoneKey_Bridge::discussion_configuration();
		return [ 'target'=>Discussion_Target::descriptor( $post ), 'actor'=>$actor, 'actorKey'=>$device ? $this->actor_key( $device ) : '', 'nameRequired'=>$actor['name'] === '', 'contactRequired'=>! $actor['signedIn'], 'nonce'=>wp_create_nonce( 'wp_rest' ), 'deviceReady'=>$device !== '', 'phoneKeyAvailable'=>$phonekey['available'], 'identifierMode'=>$phonekey['identifierMode'], 'phoneReady'=>$phonekey['phoneReady'], 'accountRequired'=>(bool) get_option( 'comment_registration' ), 'guestEmailRequired'=>(bool) get_option( 'require_name_email' ), 'automaticApproval'=>! get_option( 'comment_moderation' ) && ! get_option( 'comment_previously_approved' ) ];
	}
	public function submit( int $id, array $input ) {
		if ( get_current_user_id() && ( ! get_userdata( get_current_user_id() ) || '1' === (string) get_user_meta( get_current_user_id(), '_kiwe_account_suspended', true ) ) ) return $this->error( 'account', __( 'This account cannot send comments. Contact the website team.', 'dsa' ), 403 );
		if ( array_diff( array_keys( $input ), [ 'sendId','parent','name','text','email','rating','website','actorKey' ] ) || ! is_string( $input['sendId'] ?? null ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $input['sendId'] ) || ! is_string( $input['text'] ?? null ) || ! is_int( $input['parent'] ?? 0 ) || ( $input['parent'] ?? 0 ) < 0 || ! is_int( $input['rating'] ?? 0 ) || ( $input['rating'] ?? 0 ) < 0 || ( $input['rating'] ?? 0 ) > 5 || ! is_string( $input['name'] ?? '' ) || ! is_string( $input['email'] ?? '' ) || ! is_string( $input['website'] ?? '' ) || ! empty( $input['website'] ) || ( isset( $input['actorKey'] ) && ! is_string( $input['actorKey'] ) ) ) return $this->error( 'input', __( 'Review your comment and try sending again.', 'dsa' ) );
		if ( strlen( $input['text'] ) > 30000 || strlen( $input['name'] ?? '' ) > 240 || strlen( $input['email'] ?? '' ) > 254 ) return $this->error( 'input_size', __( 'Keep your comment within 5,000 characters and use a short public name.', 'dsa' ), 413 );
		$post = Discussion_Target::get( $id ); if ( is_wp_error( $post ) ) return $post;
		if ( ! comments_open( $id ) ) return $this->error( 'closed', __( 'New comments are closed.', 'dsa' ), 403 );
		$parent = $input['parent'] ?? 0; $ancestry = Discussion_Target::parent( $post, $parent ); if ( is_wp_error( $ancestry ) ) return $ancestry;
		if ( $parent && ( ! get_option( 'thread_comments' ) || $ancestry['depth'] >= max( 1, (int) get_option( 'thread_comments_depth', 5 ) ) ) ) return $this->error( 'depth', __( 'Reply to an earlier comment in this conversation.', 'dsa' ) );
		$text = trim( wp_strip_all_tags( wp_check_invalid_utf8( $input['text'] ) ) ); $length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		$name = Public_Identity::clean( $input['name'] ?? '' ) ?: Public_Identity::current_name();
		if ( ! $name || $length < 1 || $length > 5000 ) return $this->error( 'content', __( 'Add a public name and a comment of up to 5,000 characters.', 'dsa' ) );
		$rating = $input['rating'] ?? 0;
		if ( 'product' === $post->post_type ) { $valid = \DSA\Commerce\Discussion_Review_Adapter::validate( $id, $parent, $rating ); if ( is_wp_error( $valid ) ) return $valid; }
		elseif ( $rating ) return $this->error( 'rating', __( 'Star ratings belong to product reviews.', 'dsa' ) );
		$device = $this->device(); if ( ! $device ) return $this->error( 'device', __( 'Open the comment composer before sending.', 'dsa' ), 409 );
		global $wpdb; $send = hash_hmac( 'sha256', $device . '|' . $input['sendId'], wp_salt( 'nonce' ) ); $actor = $this->actor_key( $device );
		if ( isset( $input['actorKey'] ) && ! hash_equals( $actor, $input['actorKey'] ) ) return $this->error( 'actor', __( 'Your signed-in account changed. Reopen the conversation before sending.', 'dsa' ), 409 );
		$email = trim( $input['email'] ?? '' ); if ( $email && ! is_email( $email ) ) return $this->error( 'contact', __( 'Enter a valid email, or continue through PhoneKey with your phone.', 'dsa' ) );
		$body = hash( 'sha256', wp_json_encode( [ $id,$parent,$name,$text,$email,$rating ] ) );
		$lock = 'kiwe-discussion-' . substr( hash( 'sha256', $wpdb->prefix . '|' . $send ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) return $this->error( 'busy', __( 'This comment is being sent. Please wait before retrying.', 'dsa' ), 409 );
		try {
			$existing = $wpdb->get_col( $wpdb->prepare( "SELECT c.comment_ID FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id=c.comment_ID WHERE c.comment_post_ID=%d AND m.meta_key=%s AND m.meta_value=%s LIMIT 2", $id, self::SEND_META, $send ) );
			if ( $wpdb->last_error || count( $existing ) > 1 ) return $this->error( 'storage', __( 'The earlier submission could not be confirmed. Please refresh.', 'dsa' ), 503 );
			if ( $existing ) {
				$cid = (int) $existing[0];
				if ( ! hash_equals( (string) get_comment_meta( $cid, self::BODY_META, true ), $body ) || ! hash_equals( (string) get_comment_meta( $cid, self::ACTOR_META, true ), $actor ) ) return $this->error( 'changed', __( 'This submission belongs to an earlier draft or account. Refresh before sending again.', 'dsa' ), 409 );
				return $this->receipt( get_comment( $cid ), true );
			}
			if ( ! $this->write_allowed() ) return $this->error( 'rate', __( 'Please wait a little before sending another comment.', 'dsa' ), 429 );
			$meta = [ self::SEND_META=>$send, self::BODY_META=>$body, self::ACTOR_META=>$actor, '_kiwe_discussion_name_explicit_v1'=>'1', '_kiwe_discussion_verified_v1'=>Public_Identity::verified( get_current_user_id() ) ? '1' : '0', '_kiwe_discussion_source_v1'=>'composer' ];
			$prepare = static function( array $data ) use ( $id,$name,$meta ): array { if ( (int) ( $data['comment_post_ID'] ?? 0 ) === $id ) { $data['comment_author']=wp_slash( $name ); $data['comment_author_url']=''; $data['comment_meta']=array_merge( (array) ( $data['comment_meta'] ?? [] ), $meta ); } return $data; };
			$old_post = $_POST; $old_request = $_REQUEST;
			if ( ! Discussion_Storage::begin() ) return $this->error( 'storage', __( 'Comment storage is temporarily unavailable. Please try again shortly.', 'dsa' ), 503 );
			$committed = false; $created = 0;
			$_POST = [ 'comment_post_ID'=>$id, 'comment_parent'=>$parent, 'comment'=>$text, 'author'=>$name, 'email'=>$email ]; if ( $rating ) $_POST['rating']=$rating;
			$_REQUEST = $_POST; add_filter( 'preprocess_comment', $prepare, 20 );
			// Existing Kiwe comment ingress queues owner delivery; never send native mail inside Send.
			$no_mail = static fn() => false; add_filter( 'notify_moderator', $no_mail, PHP_INT_MAX ); add_filter( 'notify_post_author', $no_mail, PHP_INT_MAX );
			try {
				$comment = wp_handle_comment_submission( $_POST );
				if ( is_wp_error( $comment ) ) return $comment;
				if ( ! $comment instanceof \WP_Comment ) return $this->error( 'unconfirmed', __( 'WordPress did not confirm the complete submission. Refresh before retrying.', 'dsa' ), 503 );
				$created = (int) $comment->comment_ID;
				$saved_meta=get_comment_meta($created);
				foreach ( $meta as $key=>$value ) if ( ! hash_equals( $value, (string) ( $saved_meta[$key][0] ?? '' ) ) ) return $this->error( 'unconfirmed', __( 'WordPress did not confirm the complete submission. Refresh before retrying.', 'dsa' ), 503 );
				if ( ! Discussion_Storage::commit() ) return $this->error( 'unconfirmed', __( 'The submission could not be confirmed. Refresh before retrying.', 'dsa' ), 503 );
				$committed = true;
			} finally {
				$_POST=$old_post; $_REQUEST=$old_request; remove_filter( 'preprocess_comment', $prepare, 20 ); remove_filter( 'notify_moderator', $no_mail, PHP_INT_MAX ); remove_filter( 'notify_post_author', $no_mail, PHP_INT_MAX );
				if ( ! $committed ) {
					Discussion_Storage::rollback();
					if ( $created ) { clean_comment_cache( $created ); wp_cache_delete( $created, 'comment_meta' ); }
					clean_post_cache( $id ); wp_cache_set_last_changed( 'comment' );
					if ( 'product' === $post->post_type ) \DSA\Commerce\Discussion_Review_Adapter::after_rollback( $id );
				}
				Discussion_Storage::restore();
			}
			$name_saved = get_current_user_id() > 0 ? Public_Identity::remember_current_name( $name ) : false;
			// Adjacent counters and delivery start only after the native row and provenance commit together.
			do_action( 'kiwe_discussion_comment_committed', $created );
			$receipt = $this->receipt( $comment, false );
			return is_wp_error( $receipt ) ? $receipt : $receipt + [ 'nameSaved'=>$name_saved ];
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	private function write_allowed(): bool {
		$ready = Atomic_Rate_Limiter::diagnostics(); if ( ! $ready['tableReady'] ) return false;
		return Atomic_Rate_Limiter::allow( 'discussion-ip|' . Origin_Checker::client_ip(), 12, 600, false ) && ( ! get_current_user_id() || Atomic_Rate_Limiter::allow( 'discussion-user|' . get_current_user_id(), 12, 600, false ) );
	}
	private function receipt( ?\WP_Comment $comment, bool $repeated ) {
		if ( ! $comment || ! in_array( (string) $comment->comment_approved, [ '0','1' ], true ) ) return $this->error( 'moderated', __( 'This submission is being handled by the site team.', 'dsa' ), 409 );
		$published = '1' === (string) $comment->comment_approved;
		$message = $comment->comment_parent ? ( $published ? __( 'Your reply is published.', 'dsa' ) : __( 'Your reply is awaiting approval.', 'dsa' ) ) : ( 'product' === get_post_type( $comment->comment_post_ID ) ? ( $published ? __( 'Your review is published.', 'dsa' ) : __( 'Your review is awaiting approval.', 'dsa' ) ) : ( $published ? __( 'Your comment is published.', 'dsa' ) : __( 'Your comment is awaiting approval.', 'dsa' ) ) );
		return [ 'ok'=>true, 'id'=>(int) $comment->comment_ID, 'status'=>$published ? 'published' : 'pending', 'repeated'=>$repeated, 'message'=>$message ];
	}
}
