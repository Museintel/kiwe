<?php

namespace DSA\Onboarding;

use DSA\SEO\SEO_Refinement_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Native Kiwe SEO foundation with duplicate-output protection. */
final class SEO_Context_Service {
	public function __construct( private Design_Context_Profile_Service $profiles ) {}

	public function register(): void {
		add_filter( 'wp_robots', [ $this, 'robots' ] );
		add_filter( 'robots_txt', [ $this, 'robots_txt' ], 99, 2 );
		add_filter( 'wp_sitemaps_enabled', [ $this, 'native_sitemaps_enabled' ], 99 );
		add_filter( 'jetpack_enable_open_graph', [ $this, 'jetpack_open_graph' ], 99 );
		add_filter( 'jetpack_disable_twitter_cards', [ $this, 'jetpack_twitter_cards' ], 99 );
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'sitemap_args' ], 10, 2 );
		add_action( 'init', [ $this, 'ensure_sitemap_rewrite' ], 99 );
		add_action( 'wp', [ $this, 'suppress_jetpack_social_metadata' ], 0 );
		add_action( 'wp_head', [ $this, 'suppress_jetpack_social_metadata' ], 0 );
		add_action( 'wp_head', [ $this, 'head' ], 2 );
	}

	public function robots( array $robots ): array {
		if ( is_page() && 'secondary' === get_post_meta( get_queried_object_id(), Design_Context_Profile_Service::PAGE_VISIBILITY_META, true ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		if ( '1' === (string) get_option( 'blog_public', '1' ) && empty( $robots['noindex'] ) ) {
			$robots['max-image-preview'] = 'large';
		}
		return $robots;
	}

	/** Keep discovery standards-native and avoid a second sitemap implementation. */
	public function robots_txt( string $output, bool $public ): string {
		if ( ! $public || ! function_exists( 'wp_sitemaps_get_server' ) ) return $output;
		$sitemap = esc_url_raw( home_url( '/wp-sitemap.xml' ) );
		if ( '' === $sitemap || preg_match( '/^\s*Sitemap:\s*' . preg_quote( $sitemap, '/' ) . '\s*$/mi', $output ) ) return $output;
		return rtrim( $output ) . "\nSitemap: " . $sitemap . "\n";
	}

	public function sitemap_args( array $args, string $post_type ): array {
		if ( 'page' !== $post_type ) return $args;
		$visibility_clause = [
			'relation' => 'OR',
			[ 'key' => Design_Context_Profile_Service::PAGE_VISIBILITY_META, 'compare' => 'NOT EXISTS' ],
			[ 'key' => Design_Context_Profile_Service::PAGE_VISIBILITY_META, 'value' => 'secondary', 'compare' => '!=' ],
		];
		$args['meta_query'] = empty( $args['meta_query'] ) ? $visibility_clause : [ 'relation' => 'AND', $args['meta_query'], $visibility_clause ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}

	public function head(): void {
		if ( $this->dedicated_seo_plugin_active() || ! $this->indexable_request() ) return;
		$context = ( new Design_Context_Enhancement_Service( $this->profiles ) )->resolved_public_context( false );
		$metadata = $this->metadata( $context );
		$description = $metadata['description'];
		if ( '' !== $description ) echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		$this->social_metadata( $metadata );
		if ( ! is_front_page() || empty( $context['complete'] ) ) return;
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
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function metadata( array $context ): array {
		$post_id = is_singular() ? absint( get_queried_object_id() ) : 0;
		$description = '';
		if ( is_front_page() ) {
			$description = trim( (string) ( $context['seo']['homepageDescription'] ?? '' ) );
			if ( '' === $description && $post_id ) {
				$description = SEO_Refinement_Service::singular_description();
				if ( '' === $description ) {
					$description = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $post_id ) ) ), 32 );
				}
			}
			if ( '' === trim( wp_strip_all_tags( $description ) ) ) $description = (string) ( $context['identity']['description'] ?? '' );
			if ( '' === trim( wp_strip_all_tags( $description ) ) ) $description = (string) get_bloginfo( 'description' );
		} elseif ( $post_id ) {
			$description = SEO_Refinement_Service::singular_description();
			if ( '' === $description ) {
				$description = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $post_id ) ) ), 32 );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$description = term_description();
		} elseif ( is_post_type_archive() ) {
			$description = get_the_archive_description();
		}

		$url = is_front_page() ? home_url( '/' ) : '';
		if ( $post_id && function_exists( 'wp_get_canonical_url' ) ) $url = (string) wp_get_canonical_url( $post_id );
		if ( '' === $url && is_home() ) {
			$page_for_posts = absint( get_option( 'page_for_posts' ) );
			$url = $page_for_posts ? (string) get_permalink( $page_for_posts ) : home_url( '/' );
		}
		if ( '' === $url ) $url = (string) get_pagenum_link();

		$image = '';
		$image_alt = '';
		if ( $post_id && has_post_thumbnail( $post_id ) ) {
			$image_id = absint( get_post_thumbnail_id( $post_id ) );
			$image = (string) wp_get_attachment_image_url( $image_id, 'full' );
			$image_alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
		}
		if ( '' === $image && is_front_page() ) $image = trim( (string) ( $context['identity']['logo'] ?? '' ) );
		if ( '' === $image && function_exists( 'get_site_icon_url' ) ) $image = (string) get_site_icon_url( 512 );

		return [
			'title' => trim( wp_strip_all_tags( wp_get_document_title() ) ),
			'description' => trim( wp_strip_all_tags( $description ) ),
			'url' => esc_url_raw( $url ),
			'type' => is_singular( 'post' ) ? 'article' : ( function_exists( 'is_product' ) && is_product() ? 'product' : 'website' ),
			'image' => esc_url_raw( $image ),
			'imageAlt' => wp_strip_all_tags( $image_alt ),
			'siteName' => trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) ),
			'locale' => str_replace( '-', '_', get_bloginfo( 'language' ) ),
		];
	}

	private function social_metadata( array $metadata ): void {
		$tags = [
			'og:locale' => $metadata['locale'],
			'og:type' => $metadata['type'],
			'og:title' => $metadata['title'],
			'og:description' => $metadata['description'],
			'og:url' => $metadata['url'],
			'og:site_name' => $metadata['siteName'],
			'og:image' => $metadata['image'],
			'og:image:alt' => $metadata['imageAlt'],
		];
		foreach ( $tags as $property => $tag_content ) {
			if ( '' !== $tag_content ) echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $tag_content ) . '">' . "\n";
		}
		$twitter = [
			'twitter:card' => '' !== $metadata['image'] ? 'summary_large_image' : 'summary',
			'twitter:title' => $metadata['title'],
			'twitter:description' => $metadata['description'],
			'twitter:image' => $metadata['image'],
			'twitter:image:alt' => $metadata['imageAlt'],
		];
		foreach ( $twitter as $name => $tag_content ) {
			if ( '' !== $tag_content ) echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $tag_content ) . '">' . "\n";
		}
	}

	private function indexable_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_robots() || is_preview() || is_search() || is_404() ) return false;
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) return false;
		$post_id = is_singular() ? absint( get_queried_object_id() ) : 0;
		return ! $post_id || ( ! post_password_required( $post_id ) && 'publish' === get_post_status( $post_id ) );
	}

	/** Preserve WordPress privacy while preventing stale SEO plugins from disabling native discovery. */
	public function native_sitemaps_enabled( bool $enabled ): bool {
		return '1' === (string) get_option( 'blog_public', '1' ) ? true : $enabled;
	}

	/** Refresh native sitemap rewrites once, never on every request or release. */
	public function ensure_sitemap_rewrite(): void {
		if ( '1' === (string) get_option( 'kiwe_native_sitemap_rewrite_v1', '' ) ) return;
		flush_rewrite_rules( false );
		update_option( 'kiwe_native_sitemap_rewrite_v1', '1', false );
	}

	/** Jetpack may register its callback before Kiwe services boot; remove that late callback too. */
	public function suppress_jetpack_social_metadata(): void {
		if ( $this->dedicated_seo_plugin_active() ) return;
		remove_action( 'wp_head', 'jetpack_og_tags' );
		remove_action( 'web_stories_story_head', 'jetpack_og_tags' );
	}

	/** Kiwe owns social metadata when no dedicated SEO provider is active. */
	public function jetpack_open_graph( bool $enabled ): bool {
		return $this->dedicated_seo_plugin_active() ? $enabled : false;
	}

	public function jetpack_twitter_cards( bool $disabled ): bool {
		return $this->dedicated_seo_plugin_active() ? $disabled : true;
	}

	private function dedicated_seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
	}
}
