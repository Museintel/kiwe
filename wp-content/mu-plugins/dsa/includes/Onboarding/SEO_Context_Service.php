<?php

namespace DSA\Onboarding;

use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Native SEO foundation. It yields to dedicated SEO plugins when detected. */
final class SEO_Context_Service {
	public function __construct( private Design_Context_Profile_Service $profiles ) {}

	public function register(): void {
		add_filter( 'wp_robots', [ $this, 'robots' ] );
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'sitemap_args' ], 10, 2 );
		add_action( 'wp_head', [ $this, 'head' ], 2 );
	}

	public function robots( array $robots ): array {
		if ( is_page() && 'secondary' === get_post_meta( get_queried_object_id(), Design_Context_Profile_Service::PAGE_VISIBILITY_META, true ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		return $robots;
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
		if ( $this->dedicated_seo_plugin_active() ) return;
		$context = $this->profiles->public_context( false );
		$description = trim( (string) ( $context['seo']['homepageDescription'] ?? '' ) );
		if ( is_front_page() && '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		}
		if ( ! is_front_page() || empty( $context['complete'] ) ) return;
		$identity = $context['identity']; $contact = $context['contact'];
		$socials = array_values( array_filter( is_array( $contact['socialLinks'] ?? null ) ? $contact['socialLinks'] : [], static fn( $url ): bool => is_string( $url ) && '' !== $url ) );
		$schema = [
			'@context' => 'https://schema.org',
			'@type' => 'ecommerce' === ( $identity['siteType'] ?? '' ) ? 'OnlineStore' : 'Organization',
			'name' => (string) ( $identity['siteName'] ?? '' ), 'url' => home_url( '/' ),
			'description' => $description, 'logo' => (string) ( $identity['logo'] ?? '' ),
			'email' => (string) ( $contact['email'] ?? '' ), 'telephone' => (string) ( $contact['phone'] ?? '' ),
			'sameAs' => $socials,
		];
		$schema = array_filter( $schema, static fn( $value ): bool => '' !== $value && null !== $value && [] !== $value );
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function dedicated_seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
	}
}
