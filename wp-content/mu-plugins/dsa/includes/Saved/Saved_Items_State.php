<?php
namespace DSA\Saved;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Conditional writes to the native single-value user-meta owner. */
final class Saved_Items_State {
	public const META_KEY = 'dsa_saved_items';
	public const MAX_BYTES = 4194304;

	public static function read( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT umeta_id,CASE WHEN OCTET_LENGTH(meta_value)<=%d THEN meta_value ELSE NULL END AS raw_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id ASC LIMIT 1", self::MAX_BYTES, $user_id, self::META_KEY ), ARRAY_A );
		if ( $wpdb->last_error ) return null;
		if ( $row === null ) return [ 'id'=>0, 'raw'=>null, 'items'=>[] ];
		$raw = $row['raw_value'] ?? null;
		if ( ! is_string( $raw ) ) return null;
		$items = @unserialize( $raw, [ 'allowed_classes'=>false, 'max_depth'=>16 ] );
		if ( ! is_array( $items ) ) return null;
		return [ 'id'=>(int) $row['umeta_id'], 'raw'=>$raw, 'items'=>$items ];
	}

	/** The caller reapplies its intent to a fresh state after a bounded conflict. */
	public static function write( int $user_id, array $before, array $items, Saved_DB_Session $owner ): array {
		global $wpdb;
		$items = sanitize_meta( self::META_KEY, $items, 'user' );
		if ( ! is_array( $items ) || count( $items ) > 100 ) return [ 'status'=>503 ];
		$raw = serialize( $items );
		if ( strlen( $raw ) > self::MAX_BYTES || ! $owner->owns() ) return [ 'status'=>503 ];
		$added = empty( $before['id'] );
		if ( $before['raw'] !== $raw && ! Saved_Report_Journal::mark_user( $user_id ) ) return [ 'status'=>503 ];
		$intercepted = apply_filters( 'update_user_metadata', null, $user_id, self::META_KEY, $items, '' );
		if ( $intercepted === null && $added ) $intercepted = apply_filters( 'add_user_metadata', null, $user_id, self::META_KEY, $items, false );
		if ( $intercepted !== null ) {
			$current = self::read( $user_id );
			return $intercepted && $current && $current['raw'] === $raw && $owner->owns() ? [ 'status'=>200, 'items'=>$current['items'], 'changed'=>false ] : [ 'status'=>503 ];
		}
		if ( $before['raw'] === $raw ) {
			$current = self::read( $user_id );
			return $current && $current['id'] === $before['id'] && $current['raw'] === $raw && $owner->owns() ? [ 'status'=>200, 'items'=>$current['items'], 'changed'=>false ] : [ 'status'=>409 ];
		}
		// The report marker precedes both ordinary and intercepted source changes.
		if ( $added ) do_action( 'add_user_meta', $user_id, self::META_KEY, $items );
		else {
			do_action( 'update_user_meta', (int) $before['id'], $user_id, self::META_KEY, $items );
			do_action( 'update_usermeta', (int) $before['id'], $user_id, self::META_KEY, $raw );
		}
		$fence = $owner->fence();
		if ( $added ) {
			$sql = $wpdb->prepare( "INSERT INTO {$wpdb->usermeta} (user_id,meta_key,meta_value) SELECT %d,%s,%s FROM DUAL WHERE {$fence} AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s)", $user_id, self::META_KEY, $raw, $user_id, self::META_KEY );
		} else {
			$sql = $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value=%s WHERE umeta_id=%d AND user_id=%d AND meta_key=%s AND BINARY meta_value=BINARY %s AND {$fence}", $raw, (int) $before['id'], $user_id, self::META_KEY, $before['raw'] );
		}
		$changed = $wpdb->query( $sql );
		if ( $changed === false ) return [ 'status'=>503 ];
		if ( $changed !== 1 ) return [ 'status'=>$owner->owns() ? 409 : 503 ];
		$id = $added ? (int) $wpdb->insert_id : (int) $before['id'];
		wp_cache_delete( $user_id, 'user_meta' );
		if ( $added ) do_action( 'added_user_meta', $id, $user_id, self::META_KEY, $items );
		else {
			do_action( 'updated_user_meta', $id, $user_id, self::META_KEY, $items );
			do_action( 'updated_usermeta', $id, $user_id, self::META_KEY, $raw );
		}
		$current = self::read( $user_id );
		return $current && $current['id'] === $id && $current['raw'] === $raw && $owner->owns()
			? [ 'status'=>200, 'items'=>$current['items'], 'changed'=>true ] : [ 'status'=>409 ];
	}
}
