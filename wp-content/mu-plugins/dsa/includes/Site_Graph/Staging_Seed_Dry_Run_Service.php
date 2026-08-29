<?php

namespace DSA\Site_Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Deterministic target mapping preview. It never creates or updates content. */
final class Staging_Seed_Dry_Run_Service {
	public function evaluate( array $package ): array {
		$resources = is_array( $package['resources'] ?? null ) ? $package['resources'] : [];
		$summary   = [
			'terms'    => $this->empty_counts(),
			'media'    => $this->empty_counts(),
			'content'  => $this->empty_counts(),
			'products' => $this->empty_counts(),
			'menus'    => $this->empty_counts(),
			'site'     => [ 'update' => 1 ],
		];
		$blockers = [];
		$warnings = [];
		$content_types = [];
		$matched_content_ids = [];
		$matched_product_ids = [];

		foreach ( (array) ( $resources['terms'] ?? [] ) as $term ) {
			if ( ! is_array( $term ) ) continue;
			$taxonomy = sanitize_key( (string) ( $term['taxonomy'] ?? '' ) );
			$slug = sanitize_title( (string) ( $term['slug'] ?? '' ) );
			if ( '' === $taxonomy || '' === $slug ) {
				++$summary['terms']['blocked'];
				$blockers[] = 'invalid_term_record';
				continue;
			}
			if ( ! taxonomy_exists( $taxonomy ) ) {
				if ( str_starts_with( $taxonomy, 'pa_' ) && function_exists( 'wc_create_attribute' ) ) {
					++$summary['terms']['create'];
					$warnings[] = 'create_product_attribute_taxonomy:' . $taxonomy;
					continue;
				}
				++$summary['terms']['blocked'];
				$blockers[] = 'missing_destination_taxonomy:' . $taxonomy;
				continue;
			}
			$existing = term_exists( $slug, $taxonomy );
			++$summary['terms'][ $existing ? 'reuse' : 'create' ];
		}

		foreach ( (array) ( $resources['media'] ?? [] ) as $media ) {
			if ( ! is_array( $media ) || empty( $media['sourceKey'] ) || empty( $media['sourceUrl'] ) ) {
				++$summary['media']['blocked'];
				$blockers[] = 'invalid_media_record';
				continue;
			}
			++$summary['media'][ $this->post_by_source_key( (string) $media['sourceKey'], 'attachment' ) ? 'reuse' : 'create' ];
		}

		foreach ( (array) ( $resources['content'] ?? [] ) as $content ) {
			if ( ! is_array( $content ) ) continue;
			$post_type = sanitize_key( (string) ( $content['postType'] ?? '' ) );
			$content_types[] = $post_type;
			$slug = sanitize_title( (string) ( $content['slug'] ?? '' ) );
			if ( '' === $post_type || '' === $slug || ! post_type_exists( $post_type ) ) {
				++$summary['content']['blocked'];
				$blockers[] = 'missing_destination_post_type:' . $post_type;
				continue;
			}
			$existing = $this->post_by_source_key( (string) ( $content['sourceKey'] ?? '' ), $post_type );
			if ( ! $existing ) {
				$existing = get_page_by_path( $slug, OBJECT, $post_type );
			}
			$matched_id = $existing instanceof \WP_Post ? absint( $existing->ID ) : absint( $existing );
			if ( $matched_id ) $matched_content_ids[] = $matched_id;
			++$summary['content'][ $existing ? 'update' : 'create' ];
		}

		$source_skus = [];
		foreach ( (array) ( $resources['products'] ?? [] ) as $product ) {
			if ( ! is_array( $product ) ) continue;
			if ( ! function_exists( 'wc_get_product' ) ) {
				++$summary['products']['blocked'];
				$blockers[] = 'woocommerce_missing_on_destination';
				continue;
			}
			$sku = sanitize_text_field( (string) ( $product['sku'] ?? '' ) );
			$type = sanitize_key( (string) ( $product['type'] ?? 'simple' ) );
			if ( ! in_array( $type, [ 'simple', 'variable', 'grouped', 'external' ], true ) ) {
				++$summary['products']['blocked'];
				$blockers[] = 'unsupported_product_type:' . $type;
				continue;
			}
			if ( '' !== $sku ) {
				if ( isset( $source_skus[ $sku ] ) ) {
					++$summary['products']['blocked'];
					$blockers[] = 'duplicate_source_sku:' . $sku;
					continue;
				}
				$source_skus[ $sku ] = true;
			}
			$existing_id = $this->post_by_source_key( (string) ( $product['sourceKey'] ?? '' ), 'product' );
			if ( ! $existing_id && '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$existing_id = absint( wc_get_product_id_by_sku( $sku ) );
			}
			if ( ! $existing_id && ! empty( $product['slug'] ) ) {
				$existing = get_page_by_path( sanitize_title( (string) $product['slug'] ), OBJECT, 'product' );
				$existing_id = $existing instanceof \WP_Post ? $existing->ID : 0;
			}
			if ( $existing_id ) {
				$matched_product_ids[] = $existing_id;
				$existing = wc_get_product( $existing_id );
				if ( $existing && sanitize_key( (string) $existing->get_type() ) !== $type ) {
					++$summary['products']['blocked'];
					$blockers[] = 'product_type_conflict:' . $existing_id;
					continue;
				}
				++$summary['products']['update'];
			} else {
				++$summary['products']['create'];
			}
		}
		foreach ( array_unique( array_filter( $content_types ) ) as $post_type ) {
			$current = get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ] );
			$summary['content']['remove'] += count( array_diff( array_map( 'absint', (array) $current ), array_unique( $matched_content_ids ) ) );
		}
		if ( function_exists( 'wc_get_product' ) ) {
			$current = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ] );
			$summary['products']['remove'] = count( array_diff( array_map( 'absint', (array) $current ), array_unique( $matched_product_ids ) ) );
		}

		$menus = (array) ( $resources['menus'] ?? [] );
		foreach ( $menus as $menu ) {
			if ( ! is_array( $menu ) || empty( $menu['slug'] ) ) continue;
			++$summary['menus'][ wp_get_nav_menu_object( sanitize_title( (string) $menu['slug'] ) ) ? 'update' : 'create' ];
		}

		if ( ! empty( $resources['products'] ) && empty( $resources['media'] ) ) {
			$warnings[] = 'products_exist_without_transferable_media';
		}
		$page_authority = is_array( $resources['site']['pageAuthority'] ?? null ) ? $resources['site']['pageAuthority'] : [];
		if ( [] !== $page_authority ) {
			$source_page_ids = array_values( array_filter( array_map( static fn( $record ): int => is_array( $record ) && 'page' === ( $record['postType'] ?? '' ) ? absint( $record['sourceId'] ?? 0 ) : 0, (array) ( $resources['content'] ?? [] ) ) ) );
			$authority_ids = [ absint( $page_authority['frontPageSourceId'] ?? 0 ), absint( $page_authority['postsPageSourceId'] ?? 0 ) ];
			foreach ( (array) ( $page_authority['woo'] ?? [] ) as $source_id ) $authority_ids[] = absint( $source_id );
			foreach ( array_filter( $authority_ids ) as $source_id ) if ( ! in_array( $source_id, $source_page_ids, true ) ) $blockers[] = 'page_authority_source_missing:' . $source_id;
		}
		if ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) {
			$warnings[] = 'destination_reports_production_environment';
		}

		return [
			'schema'      => 'kiwe.staging-seed-dry-run.v1',
			'generatedAt' => gmdate( 'c' ),
			'status'      => [] === $blockers ? 'ready-for-baseline-and-import-confirmation' : 'blocked',
			'packageId'   => sanitize_text_field( (string) ( $package['manifest']['packageId'] ?? '' ) ),
			'revisionHash'=> sanitize_text_field( (string) ( $package['manifest']['revisionHash'] ?? '' ) ),
			'summary'     => $summary,
			'blockers'    => array_values( array_unique( $blockers ) ),
			'warnings'    => array_values( array_unique( $warnings ) ),
			'mutationsPerformed' => false,
			'nextGate'    => 'capture-target-baseline-then-explicitly-confirm-import',
		];
	}

	private function empty_counts(): array {
		return [ 'create' => 0, 'update' => 0, 'reuse' => 0, 'remove' => 0, 'blocked' => 0 ];
	}

	private function post_by_source_key( string $source_key, string $post_type ): int {
		$source_key = sanitize_text_field( $source_key );
		if ( '' === $source_key ) return 0;
		$ids = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_kiwe_staging_seed_source_key',
				'meta_value'     => $source_key,
				'no_found_rows'  => true,
			]
		);
		return absint( $ids[0] ?? 0 );
	}
}
