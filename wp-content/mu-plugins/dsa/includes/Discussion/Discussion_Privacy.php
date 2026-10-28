<?php
namespace DSA\Discussion;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native privacy requests own account-linked reactions. WordPress retains its comment exporter/eraser. */
final class Discussion_Privacy {
	private const PAGE_SIZE = 100;
	private const REVISION = 'kiwe_discussion_privacy_revision_v1';
	public static function revision(): string { return (string) get_option( self::REVISION, '' ); }
	public static function invalidate(): bool {
		$value = bin2hex( random_bytes( 12 ) ); update_option( self::REVISION, $value, false );
		do_action( 'kiwe_discussion_privacy_changed' );
		return hash_equals( $value, self::revision() );
	}
	public static function exporters( array $exporters ): array {
		$exporters['kiwe-discussion-reactions'] = [ 'exporter_friendly_name'=>__( 'Kiwe conversation actions', 'dsa' ), 'callback'=>[ self::class, 'export' ] ]; return $exporters;
	}
	public static function erasers( array $erasers ): array {
		// Run before the native comment eraser anonymizes its email/user lookup fields.
		$first = [ 'kiwe-discussion-provenance'=>[ 'eraser_friendly_name'=>__( 'Kiwe comment participation metadata', 'dsa' ), 'callback'=>[ self::class, 'erase_provenance' ] ] ];
		$erasers['kiwe-discussion-reactions'] = [ 'eraser_friendly_name'=>__( 'Kiwe conversation actions', 'dsa' ), 'callback'=>[ self::class, 'erase' ] ]; return $first + $erasers;
	}
	private static function account( string $email ): int {
		$user = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		return $user ? (int) $user->ID : 0;
	}
	public static function erase_provenance( string $email, int $page = 1 ) {
		$base = [ 'items_removed'=>false, 'items_retained'=>false, 'messages'=>[], 'done'=>true ]; if ( ! is_email( $email ) ) return $base;
		global $wpdb; $user = self::account( $email ); $limit = 20;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_author_email=%s OR (user_id=%d AND user_id>0) ORDER BY comment_ID LIMIT %d OFFSET %d", $email, $user, $limit + 1, ( max( 1, $page ) - 1 ) * $limit ) );
		if ( $wpdb->last_error ) return self::unavailable();
		$base['done'] = count( $ids ) <= $limit; $keys = Discussion_Service::privacy_metadata();
		foreach ( array_slice( $ids, 0, $limit ) as $id ) {
			$meta = get_comment_meta( $id );
			foreach ( $keys as $key ) if ( isset( $meta[$key] ) ) { if ( ! delete_comment_meta( $id, $key ) || metadata_exists( 'comment', $id, $key ) ) return self::unavailable(); $base['items_removed'] = true; }
		}
		if ( $user && 1 === $page ) {
			$choice = metadata_exists( 'user', $user, '_kiwe_discussion_name_choice_v1' );
			if ( ! Public_Identity::erase_name_choice( $user ) ) return self::unavailable();
			$base['items_removed'] = $base['items_removed'] || $choice;
		}
		// Native WordPress erasers subsequently own comment/contact anonymization; business hearts/pins are not personal reactions.
		return $base;
	}
	public static function export( string $email, int $page = 1 ) {
		$id = self::account( $email );
		if ( ! $id || ! Discussion_Reaction_Store::configured() ) return [ 'data'=>[], 'done'=>true ];
		if ( ! Discussion_Reaction_Store::ready() ) return self::unavailable();
		global $wpdb; $table = Discussion_Reaction_Store::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_id,kind,created_at FROM {$table} WHERE user_id=%d ORDER BY comment_id,kind LIMIT %d OFFSET %d", $id, self::PAGE_SIZE + 1, ( max( 1, $page ) - 1 ) * self::PAGE_SIZE ) );
		if ( $wpdb->last_error ) return self::unavailable();
		$done = count( $rows ) <= self::PAGE_SIZE; $out = [];
		foreach ( array_slice( $rows, 0, self::PAGE_SIZE ) as $row ) {
			$out[] = [ 'group_id'=>'kiwe-discussion-reactions', 'group_label'=>__( 'Conversation actions', 'dsa' ), 'item_id'=>'kiwe-reaction-' . (int) $row->comment_id . '-' . $row->kind,
				'data'=>[ [ 'name'=>__( 'Comment ID', 'dsa' ), 'value'=>(string) $row->comment_id ], [ 'name'=>__( 'Action', 'dsa' ), 'value'=>'report' === $row->kind ? __( 'Reported', 'dsa' ) : __( 'Liked', 'dsa' ) ], [ 'name'=>__( 'Date (UTC)', 'dsa' ), 'value'=>(string) $row->created_at ] ] ];
		}
		// No other person's comment text, contact or report identity is copied into this export.
		return [ 'data'=>$out, 'done'=>$done ];
	}
	public static function erase( string $email, int $page = 1 ) {
		$id = self::account( $email ); $base = [ 'items_removed'=>false, 'items_retained'=>false, 'messages'=>[], 'done'=>true ];
		if ( ! $id || ! Discussion_Reaction_Store::configured() ) return $base;
		if ( ! Discussion_Reaction_Store::ready() ) return self::unavailable();
		global $wpdb; $table = Discussion_Reaction_Store::table();
		// Each request starts at the remaining first page, so removal cannot skip later records.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_id,kind FROM {$table} WHERE user_id=%d ORDER BY comment_id,kind LIMIT %d", $id, self::PAGE_SIZE ) );
		if ( $wpdb->last_error ) return self::failed( $base );
		if ( ! $rows ) return $base;
		if ( ! Discussion_Storage::begin( true ) ) return self::failed( $base );
		$committed = false;
		try {
			$conditions = []; $args = [ $id ];
			foreach ( $rows as $row ) { $conditions[] = '(comment_id=%d AND kind=%s)'; $args[] = (int) $row->comment_id; $args[] = $row->kind; }
			$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id=%d AND (" . implode( ' OR ', $conditions ) . ')', $args ) );
			if ( false === $removed || ! self::invalidate() || ! Discussion_Storage::commit() ) return self::failed( $base );
			$committed = true; wp_cache_set_last_changed( 'comment' );
			$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE user_id=%d LIMIT 1", $id ) );
			if ( $wpdb->last_error ) { $base['items_removed'] = $removed > 0; return self::failed( $base ); }
			return [ 'items_removed'=>$removed > 0, 'items_retained'=>false, 'messages'=>[], 'done'=>! $remaining ];
		} finally {
			if ( ! $committed ) { Discussion_Storage::rollback(); wp_cache_delete( self::REVISION, 'options' ); }
			Discussion_Storage::restore();
		}
	}
	private static function unavailable(): \WP_Error { return new \WP_Error( 'kiwe_discussion_privacy_storage', __( 'Conversation actions are temporarily unavailable. Retry this privacy request when storage is available.', 'dsa' ) ); }
	private static function failed( array $base ): array {
		$base['items_retained'] = true; $base['done'] = false; $base['messages'] = [ __( 'Conversation actions could not be fully erased. Retry this privacy request when storage is available.', 'dsa' ) ]; return $base;
	}
}
