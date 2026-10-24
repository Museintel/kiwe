<?php

namespace DSA\SEO;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native WordPress discovery, with a separate bounded news extension. */
final class SEO_Sitemap_Service {
	public const GENERATION = 'kiwe_seo_sitemap_generation_v1';
	private const CACHE_SLOTS = 32;
	private bool $invalidated = false;

	public function register(): void {
		add_filter( 'wp_sitemaps_enabled', [ $this, 'enabled' ], 99 );
		add_filter( 'wp_sitemaps_max_urls', [ $this, 'page_size' ], 99 );
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'posts_args' ], 99, 2 );
		add_filter( 'posts_where', [ $this, 'page_index_where' ], 99, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', [ $this, 'terms_args' ], 99, 2 );
		add_filter( 'wp_sitemaps_post_types', [ $this, 'post_types' ], 99 );
		add_filter( 'wp_sitemaps_taxonomies', [ $this, 'taxonomies' ], 99 );
		add_filter( 'wp_sitemaps_add_provider', [ $this, 'provider' ], 99, 2 );
		add_filter( 'robots_txt', [ $this, 'robots_txt' ], 99, 2 );
		add_action( 'init', [ $this, 'rewrites' ], 10 );
		add_filter( 'query_vars', static function( $v ) { $v[] = 'kiwe_news_sitemap'; return $v; } );
		add_action( 'template_redirect', [ $this, 'news_response' ], 0 );
		foreach ( [ 'save_post', 'deleted_post', 'created_term', 'edited_term', 'delete_term', 'kiwe_seo_defaults_changed' ] as $hook ) add_action( $hook, [ $this, 'invalidate' ] );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ] as $hook ) add_action( $hook, [ $this, 'meta_changed' ], 10, 4 );
		foreach ( [ 'added_option', 'updated_option', 'delete_option' ] as $hook ) add_action( $hook, [ $this, 'option_changed' ], 10, 1 );
	}
	public function enabled( bool $enabled ): bool {
		if ( ! SEO_Defaults_Service::enabled() ) return $enabled;
		return '1' === (string) get_option( 'blog_public', '1' ) && SEO_Defaults_Service::settings()['sitemap']['enabled'];
	}
	private function owns(): bool { return SEO_Defaults_Service::enabled() && $this->enabled( true ); }
	public function page_size( int $size ): int { return $this->owns() ? SEO_Defaults_Service::settings()['sitemap']['pageSize'] : $size; }
	public function post_types( array $types ): array {
		if ( ! $this->owns() ) return $types;
		foreach ( $types as $key=>$object ) if ( false === ( SEO_Defaults_Service::rule( 'postTypes', $key )['sitemap'] ?? true ) ) unset( $types[$key] );
		return $types;
	}
	public function taxonomies( array $taxonomies ): array {
		if ( ! $this->owns() ) return $taxonomies;
		foreach ( $taxonomies as $key=>$object ) if ( false === ( SEO_Defaults_Service::rule( 'taxonomies', $key )['sitemap'] ?? true ) ) unset( $taxonomies[$key] );
		return $taxonomies;
	}
	public function provider( $provider, string $name ) {
		if ( ! $this->owns() ) return $provider;
		if ( 'users' === $name && ( ! SEO_Defaults_Service::settings()['sitemap']['authors'] || SEO_Defaults_Service::settings()['policies']['redirectAuthorArchives'] || in_array( 'noindex', SEO_Defaults_Service::robots_for( 'archives', 'author' ), true ) ) ) return false;
		if ( ! in_array( $name, [ 'posts', 'taxonomies' ], true ) ) return $provider;
		require_once __DIR__ . ( 'posts' === $name ? '/SEO_Posts_Sitemap_Provider.php' : '/SEO_Taxonomies_Sitemap_Provider.php' );
		return 'posts' === $name ? new SEO_Posts_Sitemap_Provider( $this ) : new SEO_Taxonomies_Sitemap_Provider( $this );
	}
	private function constraints( string $scope, string $key ): array {
		$robots = '_kiwe_seo_robots';
		$not_noindex = [ 'relation'=>'OR', [ 'key'=>$robots, 'compare'=>'NOT EXISTS' ], [ 'key'=>$robots, 'value'=>'"noindex"', 'compare'=>'NOT LIKE' ] ];
		$index = [ 'key'=>$robots, 'value'=>'"index"', 'compare'=>'LIKE' ];
		$inherited_index = $index;
		if ( 'postTypes' === $scope && defined( 'BRICKS_DB_PAGE_SETTINGS' ) && class_exists( '\\Bricks\\Database' ) && empty( \Bricks\Database::$global_settings['disableSeo'] ) ) $inherited_index = [ 'relation'=>'OR', $index, [ 'key'=>BRICKS_DB_PAGE_SETTINGS, 'value'=>'"metaRobots";a:[0-9]+:[{][^}]*"index";', 'compare'=>'REGEXP' ] ];
		$visibility = 'taxonomies' === $scope ? \DSA\Access\Taxonomy_App_Service::VISIBILITY_META : \DSA\Onboarding\Design_Context_Profile_Service::PAGE_VISIBILITY_META;
		// Visibility remains the established native mirror. An explicit Kiwe index directive overrides inherited defaults.
		$visible = [ 'relation'=>'OR', [ 'key'=>$visibility, 'compare'=>'NOT EXISTS' ], [ 'key'=>$visibility, 'value'=>'secondary', 'compare'=>'!=' ], $index ];
		$clauses = [ 'relation'=>'AND', $not_noindex ];
		if ( 'taxonomies' === $scope || 'page' === $key ) $clauses[] = $visible;
		if ( in_array( 'noindex', SEO_Defaults_Service::robots_for( $scope, $key ), true ) && ! ( 'postTypes' === $scope && 'page' === $key ) ) $clauses[] = $inherited_index;
		return $clauses;
	}
	public function posts_args( array $args, string $type ): array {
		if ( ! $this->owns() ) return $args;
		$clauses = $this->constraints( 'postTypes', $type );
		$args['meta_query'] = empty( $args['meta_query'] ) ? $clauses : [ 'relation'=>'AND', $args['meta_query'], $clauses ];
		$args['has_password'] = false;
		if ( 'page' === $type && in_array( 'noindex', SEO_Defaults_Service::robots_for( 'postTypes', 'page' ), true ) ) $args['kiwe_seo_page_index_scope'] = true;
		$args['post__not_in'] = array_values( array_unique( array_merge( $args['post__not_in'] ?? [], SEO_Defaults_Service::settings()['sitemap']['excludePosts'] ) ) );
		return $args;
	}
	/** The static homepage and blog listing have their own defaults, independent of the other pages. */
	public function page_index_where( string $where, \WP_Query $query ): string {
		if ( ! $this->owns() || ! $query->get( 'kiwe_seo_page_index_scope' ) ) return $where;
		global $wpdb;
		$ids = []; foreach ( [ 'page_on_front'=>'home', 'page_for_posts'=>'blog' ] as $option=>$scope ) {
			$id = absint( get_option( $option ) );
			if ( $id && ( 'home' !== $scope || 'page' === get_option( 'show_on_front' ) ) && ! \DSA\Onboarding\SEO_Context_Service::object_noindex( $id ) ) $ids[] = $id;
		}
		$index = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} kiwe_index WHERE kiwe_index.post_id={$wpdb->posts}.ID AND kiwe_index.meta_key=%s AND kiwe_index.meta_value LIKE %s)", '_kiwe_seo_robots', '%"index"%' );
		if ( defined( 'BRICKS_DB_PAGE_SETTINGS' ) && class_exists( '\\Bricks\\Database' ) && empty( \Bricks\Database::$global_settings['disableSeo'] ) ) $index .= $wpdb->prepare( " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} kiwe_bricks WHERE kiwe_bricks.post_id={$wpdb->posts}.ID AND kiwe_bricks.meta_key=%s AND kiwe_bricks.meta_value REGEXP %s)", BRICKS_DB_PAGE_SETTINGS, '"metaRobots";a:[0-9]+:[{][^}]*"index";' );
		return $where . ' AND (' . ( $ids ? $wpdb->posts . '.ID IN (' . implode( ',', $ids ) . ') OR ' : '' ) . $index . ')';
	}
	public function terms_args( array $args, string $taxonomy ): array {
		if ( ! $this->owns() ) return $args;
		$clauses = $this->constraints( 'taxonomies', $taxonomy );
		$args['meta_query'] = empty( $args['meta_query'] ) ? $clauses : [ 'relation'=>'AND', $args['meta_query'], $clauses ];
		$args['exclude'] = array_values( array_unique( array_merge( (array) ( $args['exclude'] ?? [] ), SEO_Defaults_Service::settings()['sitemap']['excludeTerms'] ) ) );
		return $args;
	}
	/** WordPress's renderer does not support false entries. Filter complete native URL lists instead. */
	public function post_entry( array $entry, \WP_Post $post ): array {
		if ( ! $this->owns() ) return $entry;
		$canonical = SEO_Defaults_Service::url( (string) get_post_meta( $post->ID, '_kiwe_seo_canonical_url', true ) );
		if ( \DSA\Onboarding\SEO_Context_Service::object_noindex( (int) $post->ID ) || ( $canonical && $canonical !== get_permalink( $post ) ) ) $entry['_kiwe_excluded'] = true;
		return $entry;
	}
	public function term_entry( array $entry, \WP_Term $term ): array {
		if ( $this->owns() && \DSA\Onboarding\SEO_Context_Service::term_noindex( (int) $term->term_id ) ) $entry['_kiwe_excluded'] = true;
		return $entry;
	}
	public function home_entry( array $entry ): array {
		if ( $this->owns() && in_array( 'noindex', SEO_Defaults_Service::robots_for( 'home', '' ), true ) ) $entry['_kiwe_excluded'] = true;
		return $entry;
	}
	public static function clean_entries( array $entries ): array {
		$out = []; foreach ( $entries as $entry ) { if ( ! is_array( $entry ) || ! empty( $entry['_kiwe_excluded'] ) || empty( $entry['loc'] ) ) continue; unset( $entry['_kiwe_excluded'] ); $out[] = $entry; }
		return $out;
	}
	public function robots_txt( string $output, bool $public ): string {
		if ( ! $public || ! $this->owns() || ! function_exists( 'wp_sitemaps_get_server' ) ) return $output;
		foreach ( [ '/wp-sitemap.xml', '/news-sitemap.xml' ] as $path ) {
			if ( '/news-sitemap.xml' === $path && ! SEO_Defaults_Service::news()['enabled'] ) continue;
			$url = SEO_Defaults_Service::url( home_url( $path ) );
			if ( $url && ! preg_match( '/^\s*Sitemap:\s*' . preg_quote( $url, '/' ) . '\s*$/mi', $output ) ) $output = rtrim( $output ) . "\nSitemap: " . $url . "\n";
		}
		return $output;
	}
	public function rewrites(): void {
		if ( ! SEO_Defaults_Service::enabled() ) return;
		// Register cheap rules; an authorized admin maintenance request owns any flush.
		add_rewrite_rule( '^sitemap_index\\.xml$', 'index.php?sitemap=index', 'top' );
		// Retain old provider child addresses while WordPress remains the sole XML renderer.
		$types = array_diff( get_post_types( ['public'=>true] ), ['attachment'] );
		$taxonomies = get_taxonomies( ['public'=>true] );
		foreach ( ['posts'=>$types, 'taxonomies'=>$taxonomies] as $provider=>$names ) foreach ( $names as $name ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $name ) || in_array( $name, array_intersect( $types, $taxonomies ), true ) ) continue;
			$prefix = '^' . preg_quote( $name, '#' ) . '-sitemap';
			add_rewrite_rule( $prefix . '\\.xml$', 'index.php?sitemap=' . $provider . '&sitemap-subtype=' . $name . '&paged=1', 'top' );
			add_rewrite_rule( $prefix . '([1-9][0-9]{0,3})\\.xml$', 'index.php?sitemap=' . $provider . '&sitemap-subtype=' . $name . '&paged=$matches[1]', 'top' );
		}
		add_rewrite_rule( '^news-sitemap\\.xml$', 'index.php?kiwe_news_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap-news\\.xml$', 'index.php?kiwe_news_sitemap=index', 'top' );
		add_rewrite_rule( '^news-sitemap-([1-9][0-9]{0,3})\\.xml$', 'index.php?kiwe_news_sitemap=$matches[1]', 'top' );
	}
	public function invalidate(): void {
		if ( $this->invalidated || ! SEO_Defaults_Service::enabled() ) return;
		$this->invalidated = true;
		update_option( self::GENERATION, bin2hex( random_bytes( 8 ) ), false );
	}
	public function meta_changed( $meta, int $id, string $key, $value ): void {
		if ( str_starts_with( $key, '_kiwe_seo_' ) || in_array( $key, [ \DSA\Onboarding\Design_Context_Profile_Service::PAGE_VISIBILITY_META, \DSA\Access\Taxonomy_App_Service::VISIBILITY_META, '_bricks_page_settings' ], true ) ) {
			// Core query caches must also forget IDs previously excluded by metadata, so indexing can be reversed.
			if ( function_exists( 'current_filter' ) && str_contains( current_filter(), '_term_meta' ) && function_exists( 'wp_cache_set_terms_last_changed' ) ) wp_cache_set_terms_last_changed();
			elseif ( function_exists( 'wp_cache_set_posts_last_changed' ) ) wp_cache_set_posts_last_changed();
			$this->invalidate();
		}
	}
	public function option_changed( string $name ): void {
		if ( in_array( $name, [ SEO_Defaults_Service::OPTION, \DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE, 'WPLANG', 'kiwe_seo_workspace_enabled_v1', 'blog_public', 'blogname', 'blogdescription', 'home', 'siteurl', 'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'bricks_global_settings' ], true ) ) $this->invalidate();
	}
	/** A fixed ring bounds persistent cache keys even without a persistent object cache. */
	public function remember( string $route, callable $build, int $ttl = 300 ) {
		$news = str_starts_with( $route, 'news:' ) ? wp_json_encode( SEO_Defaults_Service::news() ) : '';
		$identity = hash( 'sha256', $route . '|' . SEO_Defaults_Service::expected() . '|' . $news . '|' . (string) get_option( self::GENERATION, '' ) . '|' . floor( time() / $ttl ) );
		$key = 'kiwe_seo_sitemap_cache_v1_' . ( hexdec( substr( hash( 'sha256', $route ), 0, 6 ) ) % self::CACHE_SLOTS );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ( $cached['identity'] ?? '' ) === $identity ) return $cached['value'];
		$value = $build();
		global $wpdb;
		if ( ! is_wp_error( $value ) && empty( $wpdb->last_error ) && strlen( (string) wp_json_encode( $value ) ) <= 1048576 ) set_transient( $key, [ 'identity'=>$identity, 'value'=>$value ], $ttl );
		return $value;
	}
	public function news_xml( string $page = 'index' ) {
		$news = SEO_Defaults_Service::news();
		if ( ! $this->owns() || ! $news['enabled'] || false === ( SEO_Defaults_Service::rule( 'postTypes', 'post' )['sitemap'] ?? true ) ) return new \WP_Error( 'kiwe_news_disabled', 'News discovery is unavailable.' );
		if ( '' === trim( $news['publication'] ) || ! preg_match( '/^(?:[a-z]{2,3}|zh-cn|zh-tw)$/D', $news['language'] ) ) return new \WP_Error( 'kiwe_news_metadata', 'Save a publication name and a supported website language before News discovery.' );
		if ( 'index' !== $page && ( ! preg_match( '/^[1-9][0-9]{0,3}$/D', $page ) || (int) $page > 1000 ) ) return new \WP_Error( 'kiwe_news_page', 'News sitemap page is unavailable.' );
		return $this->remember( 'news:' . $page, function() use ( $page ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
			$args = $this->posts_args( [ 'post_type'=>'post', 'post_status'=>'publish', 'fields'=>'ids', 'posts_per_page'=>1000, 'paged'=>'index' === $page ? 1 : (int) $page, 'orderby'=>[ 'date'=>'DESC', 'ID'=>'DESC' ], 'ignore_sticky_posts'=>true, 'update_post_meta_cache'=>false, 'update_post_term_cache'=>false, 'date_query'=>[ [ 'column'=>'post_date_gmt', 'after'=>$cutoff, 'before'=>gmdate( 'Y-m-d H:i:s' ), 'inclusive'=>true ] ], 'no_found_rows'=>'index' !== $page ], 'post' );
			$q = new \WP_Query( $args );
			global $wpdb;
			if ( $wpdb->last_error ) return new \WP_Error( 'kiwe_news_database', 'News discovery could not be read.' );
			$news = SEO_Defaults_Service::news();
			$xml = new \XMLWriter(); $xml->openMemory(); $xml->startDocument( '1.0', 'UTF-8' );
			if ( 'index' === $page && $q->found_posts > 1000 ) {
				$pages = (int) ceil( $q->found_posts / 1000 );
				if ( $pages > 1000 ) return new \WP_Error( 'kiwe_news_capacity', 'The recent news inventory exceeds the qualified sitemap capacity.' );
				$xml->startElement( 'sitemapindex' ); $xml->writeAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
				for ( $i = 1; $i <= $pages; ++$i ) { $xml->startElement( 'sitemap' ); $xml->writeElement( 'loc', home_url( '/news-sitemap-' . $i . '.xml' ) ); $xml->endElement(); }
			} else {
				$xml->startElement( 'urlset' ); $xml->writeAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' ); $xml->writeAttribute( 'xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9' );
				// Read only permalink/publication fields, never article bodies or all metadata.
				$ids = array_map( 'absint', $q->posts );
				$rows = $ids ? $wpdb->get_results( "SELECT ID,post_author,post_date,post_date_gmt,post_modified,post_modified_gmt,post_name,post_parent,post_type,post_status,post_title,post_password FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $ids ) . ') ORDER BY post_date DESC,ID DESC' ) : [];
				if ( $wpdb->last_error ) return new \WP_Error( 'kiwe_news_database', 'News publication fields could not be read.' );
				// A bounded batch reads only discovery fields; Bricks robots must agree with the regular sitemap.
				$bricks = defined( 'BRICKS_DB_PAGE_SETTINGS' ) && class_exists( '\\Bricks\\Database' ) && empty( \Bricks\Database::$global_settings['disableSeo'] );
				$keys = [ '_kiwe_seo_canonical_url', '_kiwe_seo_robots' ]; if ( $bricks ) $keys[] = BRICKS_DB_PAGE_SETTINGS;
				$keys_sql = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
				$select = $ids ? "FROM {$wpdb->postmeta} WHERE meta_key IN ({$keys_sql}) AND post_id IN (" . implode( ',', $ids ) . ')' : '';
				$size = $ids ? $wpdb->get_row( "SELECT COUNT(*) records,COALESCE(SUM(OCTET_LENGTH(meta_value)),0) bytes,COALESCE(MAX(OCTET_LENGTH(meta_value)),0) largest {$select}" ) : null;
				if ( $wpdb->last_error ) return new \WP_Error( 'kiwe_news_database', 'News discovery fields could not be read.' );
				if ( $size && ( $size->records > 4000 || $size->bytes > 8388608 || $size->largest > 65536 ) ) return new \WP_Error( 'kiwe_news_capacity', 'News discovery metadata exceeds its bounded capacity.' );
				$metadata_rows = $ids ? $wpdb->get_results( "SELECT post_id,meta_key,meta_value {$select} ORDER BY meta_id ASC LIMIT 4000" ) : [];
				if ( $wpdb->last_error ) return new \WP_Error( 'kiwe_news_database', 'News discovery metadata could not be read.' );
				$metadata = []; foreach ( $metadata_rows as $row ) if ( ! isset( $metadata[(int) $row->post_id][$row->meta_key] ) ) $metadata[(int) $row->post_id][$row->meta_key] = maybe_unserialize( $row->meta_value );
				foreach ( $rows as $row ) {
					$post = new \WP_Post( $row ); $entry = [ 'loc'=>get_permalink( $post ) ];
					$fields = $metadata[$post->ID] ?? []; $canonical = SEO_Defaults_Service::url( (string) ( $fields['_kiwe_seo_canonical_url'] ?? '' ) );
					if ( $canonical && $canonical !== $entry['loc'] ) continue;
					$explicit = is_array( $fields['_kiwe_seo_robots'] ?? null ) ? $fields['_kiwe_seo_robots'] : [];
					if ( $bricks && ! in_array( 'index', $explicit, true ) && in_array( 'noindex', (array) ( $fields[BRICKS_DB_PAGE_SETTINGS]['metaRobots'] ?? [] ), true ) ) continue;
					$xml->startElement( 'url' ); $xml->writeElement( 'loc', $entry['loc'] ); $xml->startElement( 'news:news' ); $xml->startElement( 'news:publication' ); $xml->writeElement( 'news:name', $news['publication'] ); $xml->writeElement( 'news:language', $news['language'] ); $xml->endElement(); $xml->writeElement( 'news:publication_date', str_replace( ' ', 'T', $post->post_date_gmt ) . 'Z' ); $xml->writeElement( 'news:title', $post->post_title ); $xml->endElement(); $xml->endElement();
				}
			}
			$xml->endElement(); $xml->endDocument(); return $xml->outputMemory();
		}, 60 );
	}
	public function news_response(): void {
		$page = (string) get_query_var( 'kiwe_news_sitemap' ); if ( '' === $page ) return;
		if ( '' !== SEO_Defaults_Service::external_owner() ) return;
		if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', [ 'GET', 'HEAD' ], true ) ) { status_header( 405 ); header( 'Allow: GET, HEAD' ); exit; }
		$xml = $this->news_xml( $page );
		if ( is_wp_error( $xml ) ) { status_header( in_array( $xml->get_error_code(), [ 'kiwe_news_capacity', 'kiwe_news_database', 'kiwe_news_metadata' ], true ) ? 503 : 404 ); exit; }
		header( 'Content-Type: application/xml; charset=UTF-8' ); header( 'Cache-Control: public, max-age=60' );
		header( 'X-Robots-Tag: noindex, follow' );
		if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
