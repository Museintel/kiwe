<?php

namespace DSA\SEO;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Keyset discovery reads only IDs; eligibility and native object permissions are checked by the consumer. */
final class SEO_Discovery_Service {
	private function type( string $scope ): string {
		$type = [ 'all_posts'=>'post','all_pages'=>'page','all_products'=>'product','all_media'=>'attachment' ][ $scope ] ?? '';
		return $type && post_type_exists( $type ) && ( 'product' !== $type || function_exists( 'WC' ) ) ? $type : '';
	}
	public function boundary( string $scope ) {
		global $wpdb;
		$type = $this->type( $scope );
		if ( ! $type ) return new \WP_Error( 'kiwe_seo_scope', __( 'This content type is unavailable.', 'dsa' ) );
		$upper = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type=%s", $type ) );
		if ( ! empty( $wpdb->last_error ) ) return new \WP_Error( 'kiwe_seo_discovery', __( 'The content boundary could not be read.', 'dsa' ) );
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND ID<=%d", $type, (int) $upper ) );
		if ( ! empty( $wpdb->last_error ) ) return new \WP_Error( 'kiwe_seo_discovery', __( 'The content count could not be read.', 'dsa' ) );
		return [ 'upper'=>(int) $upper, 'total'=>(int) $total ];
	}
	public function page( string $scope, int $cursor, int $upper, int $limit ) {
		global $wpdb;
		$type = $this->type( $scope );
		if ( ! $type ) return new \WP_Error( 'kiwe_seo_scope', __( 'This content type is unavailable.', 'dsa' ) );
		$limit = max( 1, min( 5, $limit ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND ID>%d AND ID<=%d ORDER BY ID ASC LIMIT %d", $type, max( 0, $cursor ), max( 0, $upper ), $limit ) );
		if ( ! empty( $wpdb->last_error ) || ! is_array( $ids ) ) return new \WP_Error( 'kiwe_seo_discovery', __( 'The next content page could not be read.', 'dsa' ) );
		return array_map( 'intval', $ids );
	}
}
