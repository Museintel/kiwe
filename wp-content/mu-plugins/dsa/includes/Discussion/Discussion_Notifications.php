<?php
namespace DSA\Discussion;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Runs in the existing notification queue. No email attribution by a typed guest contact. */
final class Discussion_Notifications {
	public static function visible_for( int $user, array $item ): bool {
		$id = (int) ( $item['objectId'] ?? 0 );
		// Earlier owner notices did not carry an object ID; recover only their native comment-ID format.
		if ( ! $id && 'admin_new_comment' === ( $item['type'] ?? '' ) && preg_match( '/^comment-(\d+)$/D', (string) ( $item['id'] ?? '' ), $match ) ) $id = (int) $match[1];
		$comment = $id ? get_comment( $id ) : null;
		if ( ! $comment || ! in_array( $comment->comment_type, [ '', 'comment','review' ], true ) || ! \DSA\Apps\Store_App_Service::content_available( (string) get_post_type( $comment->comment_post_ID ) ) ) return false;
		if ( in_array( $item['type'] ?? '', [ 'admin_new_comment','admin_comment_report' ], true ) ) return user_can( $user, 'moderate_comments' ) && user_can( $user, 'read_post', (int) $comment->comment_post_ID );
		$post = Discussion_Target::get( (int) $comment->comment_post_ID );
		if ( is_wp_error( $post ) || is_wp_error( Discussion_Target::parent( $post, $id ) ) ) return false;
		// A reply belongs to its native parent's owner; other updates belong to the comment owner.
		if ( (int) $comment->user_id === $user ) return true;
		$parent = $comment->comment_parent ? get_comment( $comment->comment_parent ) : null;
		return $parent && (int) $parent->user_id === $user && (int) $comment->user_id !== $user;
	}
	public static function dispatch( string $event, int $id ): void {
		$comment = get_comment( $id ); if ( ! $comment ) return;
		$post = Discussion_Target::get( (int) $comment->comment_post_ID ); if ( is_wp_error( $post ) ) return;
		if ( 'discussion_report' === $event ) {
			$counts = Discussion_Reaction_Store::projection( [ $id ], 0 );
			if ( is_wp_error( $counts ) || empty( $counts[$id]['reports'] ) ) return;
			do_action( 'kiwe_notification_event', 'admin_comment_report', [ 'id'=>'discussion-report-' . $id . '-' . gmdate( 'YmdH' ), 'objectId'=>$id, 'title'=>__( 'A comment was reported', 'dsa' ), 'message'=>__( 'Review the report and conversation in Daily Feedback. Reporting does not remove a comment.', 'dsa' ), 'url'=>add_query_arg( 'feedback', $id, \DSA\Access\Continuity_App_Service::feedback_url() ), 'actionLabel'=>__( 'Review feedback', 'dsa' ) ] );
			return;
		}
		if ( is_wp_error( Discussion_Target::parent( $post, $id ) ) ) return;
		$user = (int) $comment->user_id; $title = ''; $message = '';
		if ( 'discussion_reply' === $event ) {
			$parent = $comment->comment_parent ? get_comment( $comment->comment_parent ) : null;
			if ( ! $parent || (int) $parent->user_id === $user ) return; $user = (int) $parent->user_id;
			$title = __( 'A reply to your comment', 'dsa' ); $message = __( 'Someone replied in a conversation you joined. Open it to read the reply.', 'dsa' );
		} elseif ( 'discussion_status' === $event ) {
			$title = __( 'Your comment is published', 'dsa' ); $message = __( 'The website team approved your contribution. It is now visible in the conversation.', 'dsa' );
		} elseif ( 'discussion_heart' === $event ) {
			if ( ! get_comment_meta( $id, Discussion_Reactions::HEART, true ) ) return;
			$title = __( 'The website team liked your comment', 'dsa' ); $message = __( 'Your contribution received a heart from the website team.', 'dsa' );
		} elseif ( 'discussion_like' === $event ) {
			if ( ! Discussion_Reaction_Store::ready() ) return;
			global $wpdb; $other = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Discussion_Reaction_Store::table() . " WHERE comment_id=%d AND kind='like' AND user_id<>%d LIMIT 1", $id, $user ) ); if ( ! $other || $wpdb->last_error ) return;
			$title = __( 'Your comment received a like', 'dsa' ); $message = __( 'Someone appreciated your contribution. Open the conversation to see it.', 'dsa' );
		}
		if ( ! $user || ! $title || ! Public_Identity::verified( $user ) ) return;
		$url = add_query_arg( [ 'kiwe-screen'=>'comments', 'kiwe-discussion'=>(int) $post->ID, 'kiwe-comment'=>$id ], get_permalink( $post ) );
		do_action( 'kiwe_notification_event', 'discussion_update', [ 'id'=>$event . '-' . $id . ( 'discussion_like' === $event ? '-' . gmdate( 'Ymd' ) : '' ), 'objectId'=>$id, 'userIds'=>[ $user ], 'title'=>$title, 'message'=>$message, 'url'=>$url, 'actionLabel'=>__( 'Open conversation', 'dsa' ) ] );
	}
}
