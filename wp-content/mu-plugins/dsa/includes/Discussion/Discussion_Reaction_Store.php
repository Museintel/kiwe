<?php
namespace DSA\Discussion;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Only per-account likes/reports live here; WordPress still owns every discussion record. */
final class Discussion_Reaction_Store {
	private const VERSION = '1';
	private const OPTION = 'kiwe_discussion_reactions_schema_v1';
	private static ?bool $table_available = null;
	public static function table(): string { global $wpdb; return $wpdb->prefix . 'dsa_discussion_reactions'; }
	public static function configured(): bool { return self::VERSION === get_option( self::OPTION, '' ); }
	public static function unavailable(): void { self::$table_available = false; }
	public static function ready(): bool {
		if ( ! self::configured() ) return false;
		// Only discussion requests probe this small indexed store; a stale schema receipt must not break native history.
		if ( null === self::$table_available ) {
			global $wpdb; $old = $wpdb->suppress_errors( true );
			$wpdb->query( 'SELECT comment_id FROM ' . self::table() . ' LIMIT 0' );
			self::$table_available = ! $wpdb->last_error; $wpdb->suppress_errors( $old );
		}
		return self::$table_available;
	}
	public static function install(): bool {
		if ( ! current_user_can( 'manage_options' ) ) return false;
		if ( self::ready() ) return true;
		global $wpdb; $table = self::table();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			comment_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			kind varchar(12) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (comment_id,user_id,kind),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) return false;
		update_option( self::OPTION, self::VERSION, false );
		self::$table_available = null;
		return self::ready();
	}
	public static function projection( array $comment_ids, int $actor ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $comment_ids ) ) ) );
		if ( ! self::ready() || ! $ids || count( $ids ) > 50 ) return [];
		global $wpdb; $table = self::table(); $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = "SELECT comment_id,SUM(kind='like') AS likes,SUM(kind='report') AS reports,MAX(user_id=%d AND kind='like') AS liked,MAX(user_id=%d AND kind='report') AS reported FROM {$table} WHERE comment_id IN ({$in}) GROUP BY comment_id";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ $actor,$actor ], $ids ) ) );
		if ( $wpdb->last_error ) { self::unavailable(); return new \WP_Error( 'kiwe_discussion_reaction_read', __( 'Conversation actions are temporarily unavailable.', 'dsa' ), [ 'status'=>503 ] ); }
		$out = []; foreach ( (array) $rows as $row ) $out[(int) $row->comment_id] = [ 'likes'=>(int) $row->likes,'reports'=>(int) $row->reports,'liked'=>(bool) $row->liked,'reported'=>(bool) $row->reported ];
		return $out;
	}
	public static function has( int $comment, int $user, string $kind ): bool {
		global $wpdb;
		return self::ready() && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . ' WHERE comment_id=%d AND user_id=%d AND kind=%s', $comment,$user,$kind ) );
	}
	/** Existence only; public curation never reads who liked a contribution. */
	public static function has_likes( int $comment ): bool {
		if ( $comment < 1 || ! self::ready() ) return false;
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . " WHERE comment_id=%d AND kind='like' LIMIT 1", $comment ) );
	}
	public static function set( int $comment, int $user, string $kind, bool $enabled ): bool {
		if ( ! self::ready() || ! in_array( $kind, [ 'like','report' ], true ) || $comment < 1 || $user < 1 ) return false;
		global $wpdb;
		$sql = $enabled ? $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (comment_id,user_id,kind,created_at) VALUES(%d,%d,%s,%s)', $comment,$user,$kind,current_time( 'mysql', true ) ) : $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE comment_id=%d AND user_id=%d AND kind=%s', $comment,$user,$kind );
		$written = $wpdb->query( $sql );
		if ( false !== $written && $written > 0 && 'like' === $kind ) wp_cache_set_last_changed( 'kiwe_testimonials' );
		return false !== $written && self::has( $comment,$user,$kind ) === $enabled;
	}
	public static function delete_comment( int $id ): void { if ( self::ready() ) { global $wpdb; $wpdb->delete( self::table(), [ 'comment_id'=>$id ], [ '%d' ] ); } }
	public static function clear_reports( int $id ): bool {
		if ( ! self::ready() || $id < 1 ) return false;
		global $wpdb; $removed = $wpdb->delete( self::table(), [ 'comment_id'=>$id, 'kind'=>'report' ], [ '%d','%s' ] );
		if ( false === $removed ) return false;
		$left = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . " WHERE comment_id=%d AND kind='report' LIMIT 1", $id ) );
		return ! $wpdb->last_error && ! $left;
	}
	public static function delete_user( int $id ): void {
		if ( self::ready() && $id > 0 ) {
			global $wpdb; $removed = $wpdb->delete( self::table(), [ 'user_id'=>$id ], [ '%d' ] );
			if ( $removed ) { Discussion_Privacy::invalidate(); wp_cache_set_last_changed( 'comment' ); }
		}
	}
}
