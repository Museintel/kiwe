<?php

namespace DSA\SEO;

if ( ! defined( 'ABSPATH' ) || ! class_exists( '\WP_Sitemaps_Posts' ) ) exit;

/** Preserve core pagination, queries and renderer contracts; never pass false URL entries. */
final class SEO_Posts_Sitemap_Provider extends \WP_Sitemaps_Posts {
	public function __construct( private SEO_Sitemap_Service $owner ) { parent::__construct(); }
	public function get_url_list( $page_num, $object_subtype = '' ) {
		return $this->owner->remember( 'posts:' . $object_subtype . ':' . (int) $page_num, function() use ( $page_num, $object_subtype ) {
			$entry = fn( $url, $post ) => $this->owner->post_entry( $url, $post );
			$home = fn( $url ) => $this->owner->home_entry( $url );
			add_filter( 'wp_sitemaps_posts_entry', $entry, PHP_INT_MAX, 2 );
			add_filter( 'wp_sitemaps_posts_show_on_front_entry', $home, PHP_INT_MAX );
			try { return SEO_Sitemap_Service::clean_entries( parent::get_url_list( $page_num, $object_subtype ) ); }
			finally { remove_filter( 'wp_sitemaps_posts_entry', $entry, PHP_INT_MAX ); remove_filter( 'wp_sitemaps_posts_show_on_front_entry', $home, PHP_INT_MAX ); }
		} );
	}
	public function get_max_num_pages( $object_subtype = '' ) { return $this->owner->remember( 'posts-count:' . $object_subtype, fn() => parent::get_max_num_pages( $object_subtype ) ); }
}
