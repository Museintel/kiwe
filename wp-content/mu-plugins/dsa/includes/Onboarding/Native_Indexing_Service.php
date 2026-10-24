<?php
namespace DSA\Onboarding;

use DSA\Apps\SEO_App_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Core record privacy when the optional SEO engine and external providers are absent. */
final class Native_Indexing_Service {
	public const ROBOTS = [ 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ];
	public const ROBOTS_META = '_kiwe_seo_robots';
	public const VISIBILITY_META = '_kiwe_search_visibility';

	public function register(): void {
		if ( SEO_App_Service::enabled() || '' !== SEO_App_Service::external_owner() ) return;
		add_filter( 'wp_robots', [ $this, 'robots' ] );
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'posts_query' ], 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', [ $this, 'terms_query' ], 10, 2 );
	}
	public static function noindex( int $id, bool $term = false ): bool {
		if ( $id < 1 ) return false;
		$read = $term ? 'get_term_meta' : 'get_post_meta';
		$robots = (array) $read( $id, self::ROBOTS_META, true );
		if ( in_array( 'noindex', $robots, true ) ) return true;
		if ( in_array( 'index', $robots, true ) ) return false;
		return ( $term || 'page' === get_post_type( $id ) ) && 'secondary' === $read( $id, self::VISIBILITY_META, true );
	}
	public function robots( array $robots ): array {
		$id = (int) get_queried_object_id();
		$term = is_category() || is_tag() || is_tax();
		if ( ( is_singular() || $term ) && self::noindex( $id, $term ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		// An explicit index never overrides WordPress's site privacy, preview or search rules.
		return $robots;
	}
	private static function visible_query( bool $visibility ): array {
		$clauses = [ 'relation'=>'AND', [ 'relation'=>'OR',
			[ 'key'=>self::ROBOTS_META, 'compare'=>'NOT EXISTS' ],
			[ 'relation'=>'AND', [ 'key'=>self::ROBOTS_META, 'value'=>'"noindex"', 'compare'=>'NOT LIKE' ], [ 'key'=>self::ROBOTS_META, 'value'=>'noindex', 'compare'=>'!=' ] ],
		] ];
		if ( $visibility ) $clauses[] = [ 'relation'=>'OR',
			[ 'key'=>self::VISIBILITY_META, 'compare'=>'NOT EXISTS' ],
			[ 'key'=>self::VISIBILITY_META, 'value'=>'secondary', 'compare'=>'!=' ],
			[ 'key'=>self::ROBOTS_META, 'value'=>'"index"', 'compare'=>'LIKE' ],
			[ 'key'=>self::ROBOTS_META, 'value'=>'index', 'compare'=>'=' ],
		];
		return $clauses;
	}
	public function posts_query( array $args, string $type ): array {
		$privacy = self::visible_query( 'page' === $type );
		$args['meta_query'] = empty( $args['meta_query'] ) ? $privacy : [ 'relation'=>'AND', $args['meta_query'], $privacy ];
		return $args;
	}
	public function terms_query( array $args, string $taxonomy ): array {
		$privacy = self::visible_query( true );
		$args['meta_query'] = empty( $args['meta_query'] ) ? $privacy : [ 'relation'=>'AND', $args['meta_query'], $privacy ];
		return $args;
	}
}
