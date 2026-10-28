<?php
namespace DSA\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Scoped native queries and signed, bounded continuation. No persistent search index. */
final class Search_Query {

	private const WINDOW = 96;
	private const LIFETIME = 1800;

	/** Filters apply only to this WP_Query instance, including nested third-party queries. */
	public static function ids( array $args, string $text, array $taxonomies, ?array $after = null ): array {
		global $wpdb;
		$query = new \WP_Query();
		$phrase = trim( $text, " \t\n\r\0\x0B\"" );
		// Preserve native exclusion syntax; never broaden a negative query into a tag OR.
		$exclusion = (string) apply_filters( 'wp_query_search_exclusion_prefix', '-' );
		$expand = '' !== $phrase && preg_match( '/[\p{L}\p{N}]/u', $phrase ) && ( '' === $exclusion || ! preg_match( '/(?:^|\s)' . preg_quote( $exclusion, '/' ) . '\S/u', $phrase ) );
		$search = static function ( $sql, $current ) use ( $query, $expand, $taxonomies, $phrase, $wpdb ) {
			if ( $current !== $query || ! $expand || ! $taxonomies || '' === trim( $sql ) ) return $sql;
			$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
			$name = '%' . $wpdb->esc_like( $phrase ) . '%';
			$slug = preg_match( '/^[\p{L}\p{N}_-]+$/u', $phrase ) ? sanitize_title( $phrase ) : '';
			$term = 't.name LIKE %s'; $values = array_merge( $taxonomies, [ $name ] );
			if ( '' !== $slug ) { $term .= ' OR t.slug LIKE %s'; $values[] = '%' . $wpdb->esc_like( $slug ) . '%'; }
			$tag = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tr.object_id={$wpdb->posts}.ID AND tt.taxonomy IN ($placeholders) AND ($term))", ...$values );
			$native = preg_replace( '/^\s*AND\s+/i', '', $sql, 1 );
			return " AND (($native) OR ($tag)) ";
		};
		$where = static function ( $sql, $current ) use ( $query, $after, $wpdb ) {
			if ( $current !== $query || ! $after ) return $sql;
			return $sql . $wpdb->prepare( " AND ({$wpdb->posts}.post_date < %s OR ({$wpdb->posts}.post_date = %s AND {$wpdb->posts}.ID < %d))", $after['date'], $after['date'], $after['id'] );
		};
		// Add tag membership before other plugins apply their search restrictions.
		add_filter( 'posts_search', $search, PHP_INT_MIN, 2 );
		add_filter( 'posts_where', $where, PHP_INT_MAX, 2 );
		try { $query->query( $args ); return array_map( 'absint', (array) $query->posts ); }
		finally { remove_filter( 'posts_search', $search, PHP_INT_MIN ); remove_filter( 'posts_where', $where, PHP_INT_MAX ); }
	}

	/** One recent candidate window at a time for Popular; Latest uses native date/ID keysets. */
	public static function page( array $args, string $text, array $taxonomies, string $sort, string $cursor, string $context ) {
		global $wpdb;
		$state = '' === $cursor ? [ 'after'=>null, 'pending'=>[], 'older'=>true ] : self::decode( $cursor, $context );
		if ( ! is_array( $state ) ) return new \WP_Error( 'kiwe_search_cursor', __( 'Search results changed. Refresh the results to continue.', 'dsa' ), [ 'status'=>409 ] );
		$limit = (int) $args['posts_per_page'];
		$args['orderby'] = [ 'date'=>'DESC', 'ID'=>'DESC' ];
		$after = $state['after']; $pending = $state['pending']; $older = $state['older'];
		if ( $pending ) {
			$args['post__in'] = $pending; $args['orderby'] = 'post__in'; $args['posts_per_page'] = count( $pending );
			$ids = self::ids( $args, $text, $taxonomies );
		} else {
			$args['posts_per_page'] = 'popular' === $sort ? self::WINDOW : $limit + 1;
			$ids = self::ids( $args, $text, $taxonomies, $after );
			$older = count( $ids ) === $args['posts_per_page'];
			if ( 'popular' === $sort && $ids ) {
				$dates = (array) $wpdb->get_results( "SELECT ID,post_date FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $ids ) . ')', OBJECT_K );
				$last = end( $ids ); $after = isset( $dates[ $last ] ) ? [ 'date'=>$dates[ $last ]->post_date, 'id'=>$last ] : null;
				$scores = apply_filters( 'dsa_search_popularity_scores', [], $ids, 30 );
				$scores = is_array( $scores ) ? array_map( 'absint', $scores ) : [];
				usort( $ids, static function ( $left, $right ) use ( $scores, $dates ): int {
					return ( ( $scores[ $right ] ?? 0 ) <=> ( $scores[ $left ] ?? 0 ) ) ?: ( strcmp( (string) ( $dates[ $right ]->post_date ?? '' ), (string) ( $dates[ $left ]->post_date ?? '' ) ) ?: ( $right <=> $left ) );
				} );
				$older = $older && null !== $after;
			}
		}
		$selected = array_slice( $ids, 0, $limit );
		if ( 'popular' === $sort ) $pending = array_slice( $ids, $limit );
		else {
			$pending = []; $older = count( $ids ) > $limit;
			$last = $selected ? get_post( end( $selected ) ) : null;
			$after = $last ? [ 'date'=>$last->post_date, 'id'=>(int) $last->ID ] : null;
			$older = $older && null !== $after;
		}
		$next = ( $pending || $older ) ? self::encode( [ 'after'=>$after, 'pending'=>$pending, 'older'=>$older ], $context ) : '';
		if ( function_exists( '_prime_post_caches' ) && $selected ) _prime_post_caches( $selected, false, false );
		return [ 'ids'=>$selected, 'next'=>$next ];
	}

	private static function encode( array $state, string $context ): string {
		$body = rtrim( strtr( base64_encode( wp_json_encode( [ 'v'=>1, 'context'=>$context, 'expires'=>time()+self::LIFETIME, ...$state ] ) ), '+/', '-_' ), '=' );
		return $body . '.' . hash_hmac( 'sha256', $body, wp_salt( 'nonce' ) );
	}
	public static function accepts( string $cursor, string $context ): bool { return null !== self::decode( $cursor, $context ); }

	private static function decode( string $cursor, string $context ): ?array {
		if ( strlen( $cursor ) > 4096 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[1], wp_salt( 'nonce' ) ), $parts[2] ) ) return null;
		$state = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ), true ), true );
		if ( ! is_array( $state ) || 1 !== ( $state['v'] ?? null ) || ( $state['context'] ?? null ) !== $context || ! is_int( $state['expires'] ?? null ) || $state['expires'] < time() || ! is_bool( $state['older'] ?? null ) || ! is_array( $state['pending'] ?? null ) || ! array_is_list( $state['pending'] ) || count( $state['pending'] ) > self::WINDOW ) return null;
		foreach ( $state['pending'] as $id ) if ( ! is_int( $id ) || $id < 1 ) return null;
		if ( count( array_unique( $state['pending'] ) ) !== count( $state['pending'] ) ) return null;
		$after = $state['after'] ?? null;
		if ( null !== $after && ( ! is_array( $after ) || ! is_int( $after['id'] ?? null ) || $after['id'] < 1 || ! is_string( $after['date'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $after['date'] ) ) ) return null;
		if ( $state['older'] && null === $after ) return null;
		return $state;
	}
}
