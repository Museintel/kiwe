<?php
namespace DSA\SEO;

use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Onboarding\SEO_Context_Service;
use DSA\Onboarding\Design_Context_Enhancement_Service;

if (!defined('ABSPATH')) exit;

/** Optional SEO App output. Core retains business goals and per-record visibility. */
final class SEO_Output_Service extends SEO_Context_Service {
 public const PUBLIC_CACHE = 'kiwe_seo_public_context_v1';
 private int $head_capture_level = 0;
	public function __construct( private Design_Context_Profile_Service $profiles ) {}

	public function register(): void {
		( new SEO_Defaults_Service() )->register();
		( new SEO_Sitemap_Service() )->register();
		add_filter( 'pre_get_document_title', [ $this, 'document_title' ], 99 );
		add_filter( 'bricks/frontend/disable_seo', [ $this, 'bricks_seo' ], 99 );
		add_filter( 'bricks/frontend/disable_opengraph', [ $this, 'bricks_seo' ], 99 );
		add_filter( 'wp_robots', [ $this, 'robots' ] );
		add_filter( 'dsa_schema_geo_graph', [ $this, 'organization_graph' ] );
		add_filter( 'dsa_schema_geo_facts', [ $this, 'schema_facts' ] );
		add_filter( 'get_canonical_url', [ $this, 'canonical_url' ], 20, 2 );
		add_filter( 'rank_math/frontend/robots', [ $this, 'rankmath_robots' ], 99 );
		add_filter( 'rank_math/sitemap/entry', [ $this, 'rankmath_sitemap_entry' ], 99, 3 );
		add_action( 'added_post_meta', [ $this, 'sync_rankmath_indexing' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'sync_rankmath_indexing' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'sync_rankmath_indexing' ], 10, 4 );
		add_action( 'added_term_meta', [ $this, 'sync_rankmath_term_indexing' ], 10, 4 );
		add_action( 'updated_term_meta', [ $this, 'sync_rankmath_term_indexing' ], 10, 4 );
		add_action( 'deleted_term_meta', [ $this, 'sync_rankmath_term_indexing' ], 10, 4 );
		add_filter( 'jetpack_enable_open_graph', [ $this, 'jetpack_open_graph' ], 99 );
		add_filter( 'jetpack_disable_twitter_cards', [ $this, 'jetpack_twitter_cards' ], 99 );
		add_action( 'admin_init', [ $this, 'ensure_sitemap_rewrite' ], 99 );
		add_action( 'admin_init', [ $this, 'prepare_public_context' ], 100 );
		add_action( 'wp', [ $this, 'suppress_jetpack_social_metadata' ], 0 );
		add_action( 'wp_head', [ $this, 'begin_head_capture' ], -999999 );
		add_action( 'wp_head', [ $this, 'suppress_jetpack_social_metadata' ], 0 );
		add_action( 'wp_head', [ $this, 'head' ], 2 );
		add_action( 'wp_head', [ $this, 'end_head_capture' ], PHP_INT_MAX );
	}

	public function begin_head_capture(): void {
		if ( ! SEO_Defaults_Service::enabled() || ! $this->indexable_request() ) return;
		ob_start();
		$this->head_capture_level = ob_get_level();
	}

	public function end_head_capture(): void {
		if ( 0 === $this->head_capture_level || ob_get_level() !== $this->head_capture_level ) return;
		$output = (string) ob_get_clean();
		$this->head_capture_level = 0;
		echo $this->filter_social_metadata_output( $output ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function filter_social_metadata_output( string $output ): string {
		return (string) preg_replace_callback(
			'/<(?:meta|link)\b[^>]*>/i',
			static function ( array $match ): string {
				$tag = $match[0];
				if ( preg_match( '/\bdata-kiwe-seo\s*=\s*(["\'])(?:social|description|canonical)\1/i', $tag ) ) return $tag;
				return preg_match( '/\b(?:property|name)\s*=\s*(["\'])(?:(?:og:|twitter:)[^"\']*|description)\1/i', $tag ) || preg_match( '/\brel\s*=\s*(["\'])canonical\1/i', $tag ) ? '' : $tag;
			},
			$output
		);
	}

	public function robots( array $robots ): array {
		if ( ! SEO_Defaults_Service::enabled() ) return $robots;
		$data = $this->request_data();
		foreach ( SEO_Defaults_Service::robots_for( $data['scope'], $data['key'], $data['robots'] ) as $directive ) {
			$robots[$directive] = true;
			foreach ( [ 'index'=>'noindex', 'noindex'=>'index', 'follow'=>'nofollow', 'nofollow'=>'follow' ] as $positive=>$opposite ) if ( $directive === $positive ) unset( $robots[$opposite] );
		}
		foreach ( $data['rule']['advancedRobots'] ?? [] as $key=>$value ) $robots[$key] = $value;
		if ( is_singular() && ! in_array( 'index', $data['robots'], true ) && self::object_noindex( (int) get_queried_object_id() ) ) { $robots['noindex'] = true; unset( $robots['index'] ); }
		if ( self::taxonomy_request() && self::term_noindex( (int) get_queried_object_id() ) ) { $robots['noindex'] = true; unset( $robots['index'] ); }
		if ( '0' === (string) get_option( 'blog_public', '1' ) || is_preview() || is_search() || is_404() || ( is_singular() && ( post_password_required( get_queried_object_id() ) || 'publish' !== get_post_status( get_queried_object_id() ) ) ) ) { $robots['noindex'] = true; unset( $robots['index'] ); }
		if ( '1' === (string) get_option( 'blog_public', '1' ) && empty( $robots['noindex'] ) && ! isset( $robots['max-image-preview'] ) ) $robots['max-image-preview'] = 'large';
		return $robots;
	}

	private static function taxonomy_request(): bool {
		return function_exists( 'is_category' ) && ( is_category() || ( function_exists( 'is_tag' ) && is_tag() ) || ( function_exists( 'is_tax' ) && is_tax() ) );
	}

	public function sync_rankmath_term_indexing( $meta_id, int $id, string $key, $value ): void {
		if ( 'rank_math_robots' !== $key || ! defined( 'RANK_MATH_VERSION' ) ) return;
		$robots = (array) get_term_meta( $id, $key, true );
		update_term_meta( $id, \DSA\Access\Taxonomy_App_Service::VISIBILITY_META, in_array( 'noindex', $robots, true ) ? 'secondary' : 'primary' );
	}

	public function taxonomy_sitemap_args( array $args, string $taxonomy ): array {
		return ( new SEO_Sitemap_Service() )->terms_args( $args, $taxonomy );
	}

	public function sync_rankmath_indexing( $meta_id, int $id, string $key, $value ): void {
		if ( 'rank_math_robots' !== $key || 'page' !== get_post_type( $id ) ) return;
		$robots = (array) get_post_meta( $id, $key, true );
		update_post_meta( $id, Design_Context_Profile_Service::PAGE_VISIBILITY_META, in_array( 'noindex', $robots, true ) ? 'secondary' : 'primary' );
	}

	public function rankmath_robots( array $robots ): array {
		if ( is_singular() && self::object_noindex( (int) get_queried_object_id() ) ) $robots['index'] = 'noindex';
		if ( self::taxonomy_request() && self::term_noindex( (int) get_queried_object_id() ) ) $robots['index'] = 'noindex';
		return $robots;
	}

	public function rankmath_sitemap_entry( $url, string $type, $object ) {
		if ( 'post' === $type && isset( $object->ID ) && self::object_noindex( (int) $object->ID ) ) return false;
		return 'term' === $type && isset( $object->term_id ) && self::term_noindex( (int) $object->term_id ) ? false : $url;
	}

	public function robots_txt( string $output, bool $public ): string {
		return ( new SEO_Sitemap_Service() )->robots_txt( $output, $public );
	}

	public function sitemap_args( array $args, string $post_type ): array {
		return ( new SEO_Sitemap_Service() )->posts_args( $args, $post_type );
	}

	public function sitemap_entry( array $entry, \WP_Post $post, string $post_type ): array {
		return ( new SEO_Sitemap_Service() )->post_entry( $entry, $post );
	}

	public function canonical_url( string $url, \WP_Post $post ): string {
		if ( ! SEO_Defaults_Service::enabled() ) return $url;
		$custom = SEO_Defaults_Service::url( (string) get_post_meta( (int) $post->ID, '_kiwe_seo_canonical_url', true ) );
		return $custom ?: $url;
	}

	public function head(): void {
		if ( ! SEO_Defaults_Service::enabled() || ! $this->indexable_request() ) return;
		$context = is_front_page() ? $this->public_context() : [];
		$metadata = $this->metadata( $context );
		$description = $metadata['description'];
		if ( '' !== $description ) echo '<meta data-kiwe-seo="description" name="description" content="' . esc_attr( $description ) . '">' . "\n";
		if ( '' !== $metadata['url'] ) echo '<link data-kiwe-seo="canonical" rel="canonical" href="' . esc_url( $metadata['url'] ) . '">' . "\n";
		$this->social_metadata( $metadata );
		if(is_front_page())foreach(SEO_Defaults_Service::settings()['verification'] as$provider=>$code){if($code==='')continue;$name=['google'=>'google-site-verification','bing'=>'msvalidate.01','pinterest'=>'p:domain_verify'][$provider];echo '<meta data-kiwe-seo="verification" name="'.esc_attr($name).'" content="'.esc_attr($code).'">'."\n";}
	}

	public function organization_graph( array $graph ): array {
		if ( ! SEO_Defaults_Service::enabled() || ! $this->indexable_request() || ! is_front_page() ) return $graph;
		foreach ( $graph as $node ) if ( array_intersect( (array) ( $node['@type'] ?? [] ), [ 'Organization', 'OnlineStore' ] ) ) return $graph;
		$context = $this->public_context(); if ( empty( $context['complete'] ) ) return $graph;
		$description = $this->metadata( $context )['description'];
		$identity = $context['identity'];
		$contact = $context['contact'];
		$socials = array_values( array_filter( is_array( $contact['socialLinks'] ?? null ) ? $contact['socialLinks'] : [], static fn( $url ): bool => is_string( $url ) && '' !== $url ) );
		$address_source = is_array( $contact['address'] ?? null ) ? $contact['address'] : [];
		$street = implode( ', ', array_filter( [ trim( (string) ( $address_source['line1'] ?? '' ) ), trim( (string) ( $address_source['line2'] ?? '' ) ) ] ) );
		$address = array_filter( [
			'@type' => 'PostalAddress',
			'streetAddress' => $street,
			'addressLocality' => trim( (string) ( $address_source['city'] ?? '' ) ),
			'addressRegion' => trim( (string) ( $address_source['state'] ?? '' ) ),
			'postalCode' => trim( (string) ( $address_source['postcode'] ?? '' ) ),
			'addressCountry' => trim( (string) ( $address_source['country'] ?? '' ) ),
		], static fn( $value ): bool => '' !== $value );
		$schema = [
			'@context' => 'https://schema.org',
			'@type' => 'ecommerce' === ( $identity['siteType'] ?? '' ) ? 'OnlineStore' : 'Organization',
			'@id' => home_url( '/' ) . '#organization',
			'name' => (string) ( $identity['siteName'] ?? '' ),
			'url' => home_url( '/' ),
			'description' => $description,
			'logo' => (string) ( $identity['logo'] ?? '' ),
			'legalName' => (string) ( $context['seo']['legalName'] ?? '' ),
			'foundingDate' => ! empty( $context['seo']['foundedYear'] ) ? (string) absint( $context['seo']['foundedYear'] ) : '',
			'email' => (string) ( $contact['email'] ?? '' ),
			'telephone' => (string) ( $contact['phone'] ?? '' ),
			'address' => count( $address ) > 1 ? $address : [],
			'sameAs' => $socials,
		];
		$schema = array_filter( $schema, static fn( $value ): bool => '' !== $value && null !== $value && [] !== $value );
		unset( $schema['@context'] ); $graph[] = $schema; return $graph;
	}

	public function schema_facts( array $unused = [] ): array {
		if ( ! SEO_Defaults_Service::enabled() || ! $this->indexable_request() ) return [];
		$id = is_singular() ? (int) get_queried_object_id() : 0;
		$meta = $this->metadata( is_front_page() ? $this->public_context() : [] );
		$post = $id ? get_post( $id ) : null; $author = $post ? get_userdata( (int) $post->post_author ) : null;
		$excerpt = $post ? (string) $post->post_excerpt : '';
		if ( $post && '' === $excerpt ) $excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 55, '' );
		$facts = [ 'title'=>$id ? get_the_title( $id ) : $meta['title'], 'seo_title'=>$meta['title'], 'seo_description'=>$meta['description'], 'description'=>$meta['description'], 'sitename'=>get_bloginfo('name'), 'siteurl'=>home_url('/'), 'permalink'=>$meta['url'], 'excerpt'=>$excerpt, 'post_author'=>$author ? (string)$author->display_name : '', 'name'=>$author ? (string)$author->display_name : '', 'post_thumbnail'=>$meta['image'] ];
		foreach ( [ 'date'=>'get_post_time', 'modified'=>'get_post_modified_time' ] as $name=>$getter ) {
			$value=$id?(string)$getter(DATE_W3C,false,$id):'';foreach([ 'Y-m-dTH:i:sP', 'Y-m-d\TH:i:sP' ] as$format)$facts[$name.'('.$format.')']=$value;
		}
		$facts['url'] = $facts['permalink']; return $facts;
	}

	private function public_stamp(): string {
		return hash( 'sha256', (string) wp_json_encode( [ get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] ), get_option( Design_Context_Enhancement_Service::OPTION, [] ), get_option( SEO_Sitemap_Service::GENERATION, '' ), get_bloginfo( 'name' ), get_bloginfo( 'description' ), home_url( '/' ), get_theme_mod( 'custom_logo', 0 ), get_option( 'site_icon', 0 ), get_option( \DSA\Site\Site_Identity_Service::OPTION_LOGO, 0 ), get_option( \DSA\Site\Site_Identity_Service::OPTION_STORE_PHONE, '' ), get_option( \DSA\Site\Site_Identity_Service::OPTION_STORE_EMAIL, '' ) ] ) );
	}

	public function prepare_public_context(): void {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ! SEO_Defaults_Service::enabled() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'kiwe_manage_context' ) ) ) return;
		$stamp = $this->public_stamp(); $cached = get_option( self::PUBLIC_CACHE, [] );
		if ( is_array( $cached ) && ( $cached['stamp'] ?? '' ) === $stamp ) return;
		$full = ( new Design_Context_Enhancement_Service( $this->profiles ) )->resolved_public_context( false );
		$context = array_intersect_key( $full, array_flip( [ 'complete', 'identity', 'contact', 'seo' ] ) );
		if ( strlen( (string) wp_json_encode( $context ) ) <= 32768 ) update_option( self::PUBLIC_CACHE, [ 'stamp'=>$stamp, 'context'=>$context ], false );
	}

	private function public_context(): array {
		$cached = get_option( self::PUBLIC_CACHE, [] );
		if ( is_array( $cached ) && ( $cached['stamp'] ?? '' ) === $this->public_stamp() && is_array( $cached['context'] ?? null ) ) return $cached['context'];
		// Cold visitors use native facts, without a library inventory or cache rebuild.
		$raw = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] ); $raw = is_array( $raw ) ? $raw : [];
		return [ 'complete'=>false, 'identity'=>[ 'description'=>(string) ( $raw['identity']['description'] ?? '' ), 'logo'=>'' ], 'seo'=>[ 'homepageDescription'=>(string) ( $raw['seo']['homepageDescription'] ?? '' ) ] ];
	}

	private function request_data(): array {
		$scope = 'archives'; $key = ''; $post_id = 0; $term_id = 0; $native = '';
		if ( is_front_page() ) { $scope = 'home'; $post_id = is_singular() ? (int) get_queried_object_id() : 0; }
		elseif ( is_home() ) { $scope = 'blog'; $post_id = (int) get_option( 'page_for_posts' ); }
		elseif ( is_singular() ) { $scope = 'postTypes'; $post_id = (int) get_queried_object_id(); $key = (string) get_post_type( $post_id ); }
		elseif ( self::taxonomy_request() ) { $scope = 'taxonomies'; $term = get_queried_object(); if ( $term instanceof \WP_Term ) { $term_id = (int) $term->term_id; $key = $term->taxonomy; $native = $term->name; } }
		elseif ( is_author() ) { $key = 'author'; $native = (string) get_the_author_meta( 'display_name', get_queried_object_id() ); }
		elseif ( is_date() ) { $key = 'date'; $native = wp_strip_all_tags( get_the_archive_title() ); }
		elseif ( is_post_type_archive() ) { $key = (string) get_query_var( 'post_type' ); $native = post_type_archive_title( '', false ); }
		$post = $post_id ? get_post( $post_id ) : null;
		$excerpt = $post ? ( $post->post_excerpt ?: wp_trim_words( wp_strip_all_tags( strip_shortcodes( mb_substr( $post->post_content, 0, 8000 ) ) ), 32 ) ) : '';
		$category = ''; if ( $post ) { $terms = get_the_terms( $post, 'category' ); if ( is_array( $terms ) && $terms ) $category = $terms[0]->name; }
		$variables = [ 'title'=>$post ? $post->post_title : ( $native ?: get_bloginfo( 'name' ) ), 'sitename'=>get_bloginfo( 'name' ), 'sep'=>SEO_Defaults_Service::settings()['separator'], 'excerpt'=>$excerpt, 'category'=>$category, 'term'=>$native, 'term_description'=>$term_id ? term_description( $term_id, $key ) : '', 'name'=>$native, 'date'=>is_date() ? $native : ( $post ? mysql2date( get_option( 'date_format' ), $post->post_date ) : '' ), 'currentyear'=>wp_date( 'Y' ), 'page'=>max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) ), 'tagline'=>get_bloginfo( 'description' ) ];
		$explicit = $post_id ? (array) get_post_meta( $post_id, '_kiwe_seo_robots', true ) : ( $term_id ? (array) get_term_meta( $term_id, '_kiwe_seo_robots', true ) : [] );
		$bricks = $this->bricks_rule( $post_id );
		if ( ! $explicit || [ '' ] === $explicit ) $explicit = $bricks['robots'] ?? [];
		return [ 'scope'=>$scope, 'key'=>$key, 'postId'=>$post_id, 'termId'=>$term_id, 'variables'=>$variables, 'robots'=>$explicit, 'rule'=>SEO_Defaults_Service::rule( $scope, $key ), 'bricks'=>$bricks ];
	}

	private function bricks_rule( int $id ): array {
		if ( ! defined( 'BRICKS_DB_PAGE_SETTINGS' ) || ! class_exists( '\\Bricks\\Database' ) || ! empty( \Bricks\Database::$global_settings['disableSeo'] ) ) return [];
		$template = absint( \Bricks\Database::$active_templates['content'] ?? 0 );
		$base = $template ? get_post_meta( $template, BRICKS_DB_PAGE_SETTINGS, true ) : [];
		$page = $id ? get_post_meta( $id, BRICKS_DB_PAGE_SETTINGS, true ) : [];
		if ( ! is_array( $base ) || strlen( (string) wp_json_encode( $base ) ) > 65536 ) $base = [];
		if ( ! is_array( $page ) || strlen( (string) wp_json_encode( $page ) ) > 65536 ) $page = [];
		$out = [];
		foreach ( [ 'documentTitle'=>'title', 'metaDescription'=>'description', 'sharingTitle'=>'facebookTitle', 'sharingDescription'=>'facebookDescription' ] as $key=>$field ) {
			if ( str_starts_with( $key, 'sharing' ) && ! empty( \Bricks\Database::$global_settings['disableOpenGraph'] ) ) continue;
			$value = ! empty( $page[$key] ) ? $page[$key] : ( $base[$key] ?? '' );
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 1000 ) continue;
			if ( function_exists( 'bricks_render_dynamic_data' ) && str_contains( $value, '{' ) ) $value = bricks_render_dynamic_data( $value, $id );
			$out[$field] = wp_strip_all_tags( $value );
		}
		$r = ! empty( $page['metaRobots'] ) ? $page['metaRobots'] : ( $base['metaRobots'] ?? [] );
		if ( is_array( $r ) && false !== ( $r = SEO_Defaults_Service::validate_robots( $r ) ) ) $out['robots'] = $r;
		$image = ! empty( $page['sharingImage'] ) ? $page['sharingImage'] : ( $base['sharingImage'] ?? [] );
		if ( empty( \Bricks\Database::$global_settings['disableOpenGraph'] ) && is_array( $image ) && ! empty( $image['url'] ) ) $out['facebookImage'] = SEO_Defaults_Service::url( (string) ( $image['url'] ?? '' ) );
		return $out;
	}

	private function record_text( array $data, string $suffix ): ?string {
		$type = $data['termId'] ? 'term' : 'post'; $id = $data['termId'] ?: $data['postId'];
		if ( ! $id || ! metadata_exists( $type, $id, '_kiwe_seo_' . $suffix ) ) return null;
		return (string) get_metadata( $type, $id, '_kiwe_seo_' . $suffix, true );
	}

	public function document_title( string $title ): string {
		if ( ! SEO_Defaults_Service::enabled() || ! $this->indexable_request() ) return $title;
		$data = $this->request_data(); $value = $this->record_text( $data, 'title' );
		if ( null === $value ) $value = $data['bricks']['title'] ?? $data['rule']['title'] ?? '';
		if ( '' === $value ) return $title;
		$resolved = SEO_Defaults_Service::expand( $value, $data['variables'], 300 );
		if ( $data['variables']['page'] > 1 && ! str_contains( $value, '%page%' ) ) $resolved .= ' ' . $data['variables']['sep'] . ' ' . sprintf( __( 'Page %d', 'dsa' ), $data['variables']['page'] );
		return $resolved ?: $title;
	}

	public function bricks_seo( bool $disabled ): bool { return SEO_Defaults_Service::enabled() || '' !== SEO_Defaults_Service::external_owner() ? true : $disabled; }

	private function canonical( array $data ): string {
		$value = $this->record_text( $data, 'canonical_url' );
		if ( null !== $value && SEO_Defaults_Service::url( $value ) ) return SEO_Defaults_Service::url( $value );
		if ( is_singular() ) return (string) wp_get_canonical_url( $data['postId'] );
		if ( is_front_page() ) $url = home_url( '/' );
		elseif ( is_home() ) $url = $data['postId'] ? get_permalink( $data['postId'] ) : home_url( '/' );
		elseif ( $data['termId'] ) { $url = get_term_link( get_queried_object() ); if ( is_wp_error( $url ) ) return ''; }
		elseif ( is_author() ) $url = get_author_posts_url( get_queried_object_id() );
		elseif ( is_post_type_archive() ) $url = get_post_type_archive_link( $data['key'] );
		elseif ( is_day() ) $url = get_day_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ), (int) get_query_var( 'day' ) );
		elseif ( is_month() ) $url = get_month_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ) );
		elseif ( is_year() ) $url = get_year_link( (int) get_query_var( 'year' ) );
		else return '';
		$page = (int) $data['variables']['page'];
		if ( $page > 1 ) { global $wp_rewrite; $url = $wp_rewrite->using_permalinks() ? user_trailingslashit( trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . $page ) : add_query_arg( 'paged', $page, $url ); }
		return SEO_Defaults_Service::url( (string) $url );
	}

	private function metadata( array $context ): array {
		$data = $this->request_data(); $post_id = $data['postId'];
		$description = $this->record_text( $data, 'description' );
		if ( null === $description ) $description = $data['bricks']['description'] ?? $data['rule']['description'] ?? null;
		if ( null === $description && is_front_page() ) {
			foreach ( [ $context['seo']['homepageDescription'] ?? '', $context['identity']['description'] ?? '', get_bloginfo( 'description' ) ] as $fallback ) if ( '' !== trim( (string) $fallback ) ) { $description = $fallback; break; }
		}
		if ( null === $description ) {
			if ( $post_id ) $description = SEO_Refinement_Service::singular_description() ?: $data['variables']['excerpt'];
			elseif ( $data['termId'] ) $description = SEO_Refinement_Service::archive_description() ?: $data['variables']['term_description'];
			elseif ( is_author() ) $description = get_the_author_meta( 'description', get_queried_object_id() );
			else $description = get_the_archive_description();
		}
		$image = ''; $image_alt = '';
		if ( $post_id && has_post_thumbnail( $post_id ) ) { $image_id = (int) get_post_thumbnail_id( $post_id ); $image = (string) wp_get_attachment_image_url( $image_id, 'full' ); $image_alt = (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ); }
		if ( ! $image ) $image = SEO_Defaults_Service::settings()['social']['image'];
		if ( ! $image && is_front_page() ) $image = (string) ( $context['identity']['logo'] ?? '' );
		if ( ! $image && function_exists( 'get_site_icon_url' ) ) $image = (string) get_site_icon_url( 512 );
		$result = [ 'title'=>trim( wp_strip_all_tags( wp_get_document_title() ) ), 'description'=>SEO_Defaults_Service::expand( (string) $description, $data['variables'] ), 'url'=>$this->canonical( $data ), 'type'=>is_singular( 'post' ) ? 'article' : ( function_exists( 'is_product' ) && is_product() ? 'product' : 'website' ), 'image'=>SEO_Defaults_Service::url( $image ), 'imageAlt'=>wp_strip_all_tags( $image_alt ), 'siteName'=>wp_strip_all_tags( get_bloginfo( 'name' ) ), 'locale'=>str_replace( '-', '_', get_bloginfo( 'language' ) ), 'twitterCard'=>SEO_Defaults_Service::settings()['social']['twitterCard'] ];
		foreach ( [ 'facebookTitle'=>'facebook_title', 'facebookDescription'=>'facebook_description', 'facebookImage'=>'facebook_image', 'twitterTitle'=>'twitter_title', 'twitterDescription'=>'twitter_description', 'twitterImage'=>'twitter_image' ] as $field=>$suffix ) {
			$value = $this->record_text( $data, $suffix );
			if ( null === $value ) $value = $data['bricks'][$field] ?? $data['rule'][$field] ?? '';
			$result[$field] = str_ends_with( $field, 'Image' ) ? SEO_Defaults_Service::url( $value ) : SEO_Defaults_Service::expand( $value, $data['variables'] );
		}
		return $result;
	}

	private function social_metadata( array $metadata ): void {
		$tags = [
			'og:locale' => $metadata['locale'],
			'og:type' => $metadata['type'],
			'og:title' => $metadata['facebookTitle'] ?: $metadata['title'],
			'og:description' => $metadata['facebookDescription'] ?: $metadata['description'],
			'og:url' => $metadata['url'],
			'og:site_name' => $metadata['siteName'],
			'og:image' => $metadata['facebookImage'] ?: $metadata['image'],
			'og:image:alt' => $metadata['imageAlt'],
		];
		foreach ( $tags as $property => $tag_content ) {
			if ( '' !== $tag_content ) echo '<meta data-kiwe-seo="social" property="' . esc_attr( $property ) . '" content="' . esc_attr( $tag_content ) . '">' . "\n";
		}
		$twitter = [
			'twitter:card' => '' !== ( $metadata['twitterImage'] ?: $metadata['facebookImage'] ?: $metadata['image'] ) ? $metadata['twitterCard'] : 'summary',
			'twitter:title' => $metadata['twitterTitle'] ?: $metadata['facebookTitle'] ?: $metadata['title'],
			'twitter:description' => $metadata['twitterDescription'] ?: $metadata['facebookDescription'] ?: $metadata['description'],
			'twitter:image' => $metadata['twitterImage'] ?: $metadata['facebookImage'] ?: $metadata['image'],
			'twitter:image:alt' => $metadata['imageAlt'],
		];
		foreach ( $twitter as $name => $tag_content ) {
			if ( '' !== $tag_content ) echo '<meta data-kiwe-seo="social" name="' . esc_attr( $name ) . '" content="' . esc_attr( $tag_content ) . '">' . "\n";
		}
	}

	private function indexable_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_robots() || is_preview() || is_search() || is_404() ) return false;
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) return false;
		$post_id = is_singular() ? absint( get_queried_object_id() ) : 0;
		return ! $post_id || ( ! post_password_required( $post_id ) && 'publish' === get_post_status( $post_id ) );
	}

	public function native_sitemaps_enabled( bool $enabled ): bool {
		return ( new SEO_Sitemap_Service() )->enabled( $enabled );
	}

	public function ensure_sitemap_rewrite(): void {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) || ! SEO_Defaults_Service::enabled() || '2' === (string) get_option( 'kiwe_native_sitemap_rewrite_v1', '' ) ) return;
		flush_rewrite_rules( false );
		update_option( 'kiwe_native_sitemap_rewrite_v1', '2', false );
	}

	public function suppress_jetpack_social_metadata(): void {
		if ( ! SEO_Defaults_Service::enabled() ) return;
		remove_action( 'wp_head', 'jetpack_og_tags' );
		remove_action( 'web_stories_story_head', 'jetpack_og_tags' );
	}

	public function jetpack_open_graph( bool $enabled ): bool {
		return SEO_Defaults_Service::enabled() ? false : $enabled;
	}

	public function jetpack_twitter_cards( bool $disabled ): bool {
		return SEO_Defaults_Service::enabled() ? true : $disabled;
	}

	private function dedicated_seo_plugin_active(): bool { return '' !== SEO_Defaults_Service::external_owner(); }
}
