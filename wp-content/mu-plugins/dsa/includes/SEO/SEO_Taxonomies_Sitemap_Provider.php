<?php

namespace DSA\SEO;

if ( ! defined( 'ABSPATH' ) || ! class_exists( '\WP_Sitemaps_Taxonomies' ) ) exit;

/** Keep the native taxonomy provider and renderer contract. */
final class SEO_Taxonomies_Sitemap_Provider extends \WP_Sitemaps_Taxonomies {
	public function __construct( private SEO_Sitemap_Service $owner ) { parent::__construct(); }
	public function get_url_list( $page_num, $object_subtype = '' ) {
		return $this->owner->remember( 'terms:' . $object_subtype . ':' . (int) $page_num, function() use ( $page_num, $object_subtype ) {
			$entry = fn( $url, $id, $taxonomy, $term ) => $this->owner->term_entry( $url, $term );
			add_filter( 'wp_sitemaps_taxonomies_entry', $entry, PHP_INT_MAX, 4 );
			try { return SEO_Sitemap_Service::clean_entries( parent::get_url_list( $page_num, $object_subtype ) ); }
			finally { remove_filter( 'wp_sitemaps_taxonomies_entry', $entry, PHP_INT_MAX ); }
		} );
	}
	public function get_max_num_pages( $object_subtype = '' ) { return $this->owner->remember( 'terms-count:' . $object_subtype, fn() => parent::get_max_num_pages( $object_subtype ) ); }
}
