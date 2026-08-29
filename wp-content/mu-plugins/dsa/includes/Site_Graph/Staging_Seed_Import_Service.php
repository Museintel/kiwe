<?php

namespace DSA\Site_Graph;

use DSA\Commerce\Product_Context_Service;
use DSA\Diagnostics\Test_Site_Snapshot_Service;
use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies one destination-bound, hash-verified SiteGraph package to staging.
 *
 * Customers, users, orders, coupons, credentials, messages, webhooks and
 * payment state are not package resources and never enter this service.
 */
final class Staging_Seed_Import_Service {
	private Staging_Seed_Package_Service $packages;
	private Staging_Seed_Import_Ledger_Service $ledgers;
	private Test_Site_Snapshot_Service $snapshots;
	private array $media_map = [];
	private array $media_url_map = [];
	private array $term_map = [];
	private array $post_map = [];
	private array $product_map = [];
	private string $ledger_id = '';
	private string $source_origin = '';

	public function __construct( ?Staging_Seed_Package_Service $packages = null, ?Staging_Seed_Import_Ledger_Service $ledgers = null, ?Test_Site_Snapshot_Service $snapshots = null ) {
		$this->packages  = $packages ?: new Staging_Seed_Package_Service();
		$this->ledgers   = $ledgers ?: new Staging_Seed_Import_Ledger_Service();
		$this->snapshots = $snapshots ?: new Test_Site_Snapshot_Service();
	}

	public function run( string $package_record_id, string $expected_revision, bool $reconcile = false ): array {
		$package = $this->packages->read( $package_record_id );
		$resources = is_array( $package['resources'] ?? null ) ? $package['resources'] : [];
		if ( $reconcile && empty( $resources['site']['pageAuthority'] ) ) {
			throw new \RuntimeException( 'This package cannot perform clean reconciliation. Pull a new package from a source that reports Clean reconciliation ready.' );
		}
		$revision = sanitize_text_field( (string) ( $package['manifest']['revisionHash'] ?? '' ) );
		if ( '' === $expected_revision || ! hash_equals( $revision, sanitize_text_field( $expected_revision ) ) ) {
			throw new \RuntimeException( 'The import confirmation does not match the verified source revision.' );
		}
		$dry_run = ( new Staging_Seed_Dry_Run_Service() )->evaluate( $package );
		if ( ! empty( $dry_run['blockers'] ) ) {
			throw new \RuntimeException( 'The current destination dry run is blocked: ' . implode( ', ', (array) $dry_run['blockers'] ) );
		}
		$snapshot_status = $this->snapshots->status();
		if ( ! empty( $snapshot_status['active'] ) ) {
			$label = sanitize_text_field( (string) ( $snapshot_status['label'] ?? 'Unlabelled baseline' ) );
			$created = sanitize_text_field( (string) ( $snapshot_status['createdAt'] ?? 'unknown time' ) );
			throw new \RuntimeException( 'A separate test baseline (“' . $label . '”, ' . $created . ') is active. Open Kiwe > Database & Cache > Reversible test-site baseline and restore or discard it before importing.' );
		}
		foreach ( $this->ledgers->records() as $open_ledger ) {
			if ( in_array( (string) ( $open_ledger['state'] ?? '' ), [ 'running', 'failed', 'complete' ], true ) && empty( $open_ledger['closedAt'] ) ) {
				throw new \RuntimeException( 'An earlier staging import still owns its rollback boundary.' );
			}
		}

		$this->snapshots->capture( 'SiteGraph import ' . substr( $revision, 0, 12 ) );
		$ledger = $this->ledgers->begin( $package_record_id, $package );
		$this->ledger_id = (string) $ledger['id'];
		$this->source_origin = esc_url_raw( (string) ( $package['manifest']['source']['origin'] ?? '' ) );

		add_filter( 'woocommerce_webhook_should_deliver', '__return_false', PHP_INT_MAX );
		try {
			$this->ensure_product_attribute_taxonomies( (array) ( $resources['terms'] ?? [] ), (array) ( $resources['products'] ?? [] ) );
			$this->import_terms( (array) ( $resources['terms'] ?? [] ) );
			$this->import_media( (array) ( $resources['media'] ?? [] ) );
			$this->import_content( (array) ( $resources['content'] ?? [] ) );
			$this->import_products( (array) ( $resources['products'] ?? [] ) );
			$this->import_menus( (array) ( $resources['menus'] ?? [] ) );
			$this->import_site_context( is_array( $resources['site'] ?? null ) ? $resources['site'] : [], is_array( $resources['designContext'] ?? null ) ? $resources['designContext'] : [] );
			if ( $reconcile ) $this->reconcile_public_records( (array) ( $resources['content'] ?? [] ), (array) ( $resources['products'] ?? [] ), is_array( $resources['site'] ?? null ) ? $resources['site'] : [] );
			$this->flush_runtime();
			return $this->ledgers->complete( $this->ledger_id );
		} catch ( \Throwable $error ) {
			$this->ledgers->fail( $this->ledger_id, $error->getMessage() );
			throw new \RuntimeException( 'The staging import stopped safely. Its baseline is still available for rollback. ' . $error->getMessage(), 0, $error );
		} finally {
			remove_filter( 'woocommerce_webhook_should_deliver', '__return_false', PHP_INT_MAX );
		}
	}

	public function rollback( string $ledger_id ): array {
		$ledger = $this->ledgers->find( $ledger_id );
		if ( ! in_array( (string) ( $ledger['state'] ?? '' ), [ 'running', 'failed', 'complete' ], true ) || ! empty( $ledger['closedAt'] ) ) {
			throw new \RuntimeException( 'This staging import no longer owns an open rollback boundary.' );
		}
		$deleted_media = 0;
		foreach ( array_reverse( array_map( 'absint', (array) ( $ledger['created']['media'] ?? [] ) ) ) as $attachment_id ) {
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) && wp_delete_attachment( $attachment_id, true ) ) ++$deleted_media;
		}
		$restored = $this->snapshots->restore();
		$deleted_menus = 0;
		foreach ( array_reverse( array_map( 'absint', (array) ( $ledger['created']['menus'] ?? [] ) ) ) as $menu_id ) {
			$result = $menu_id ? wp_delete_nav_menu( $menu_id ) : false;
			if ( $result && ! is_wp_error( $result ) ) ++$deleted_menus;
		}
		$deleted_terms = 0;
		foreach ( array_reverse( (array) ( $ledger['created']['termrefs'] ?? [] ) ) as $reference ) {
			if ( ! is_array( $reference ) ) continue;
			$term_id = absint( $reference['id'] ?? 0 );
			$taxonomy = sanitize_key( (string) ( $reference['taxonomy'] ?? '' ) );
			if ( $term_id && taxonomy_exists( $taxonomy ) && 0 === $this->term_usage( $term_id, $taxonomy ) && ! is_wp_error( wp_delete_term( $term_id, $taxonomy ) ) ) ++$deleted_terms;
		}
		$deleted_attributes = 0;
		foreach ( array_reverse( array_map( 'absint', (array) ( $ledger['created']['attributes'] ?? [] ) ) ) as $attribute_id ) {
			if ( $attribute_id && function_exists( 'wc_delete_attribute' ) && wc_delete_attribute( $attribute_id ) ) ++$deleted_attributes;
		}
		if ( ! $this->snapshots->discard() ) throw new \RuntimeException( 'The baseline restored but its private file could not be discarded.' );
		$this->flush_runtime();
		$this->ledgers->close( $ledger_id, 'rolled-back' );
		return [ 'ledgerId' => $ledger_id, 'state' => 'rolled-back', 'snapshot' => $restored, 'deletedMedia' => $deleted_media, 'deletedMenus' => $deleted_menus, 'deletedTerms' => $deleted_terms, 'deletedAttributes' => $deleted_attributes ];
	}

	public function accept( string $ledger_id ): array {
		$ledger = $this->ledgers->find( $ledger_id );
		if ( 'complete' !== (string) ( $ledger['state'] ?? '' ) || ! empty( $ledger['closedAt'] ) ) {
			throw new \RuntimeException( 'Only a completed, open staging import can be accepted.' );
		}
		if ( ! $this->snapshots->discard() ) throw new \RuntimeException( 'Kiwe could not discard the private rollback baseline.' );
		return $this->ledgers->close( $ledger_id, 'accepted' );
	}

	private function ensure_product_attribute_taxonomies( array $terms, array $products ): void {
		$taxonomies = [];
		foreach ( $terms as $term ) if ( is_array( $term ) && str_starts_with( (string) ( $term['taxonomy'] ?? '' ), 'pa_' ) ) $taxonomies[] = sanitize_key( (string) $term['taxonomy'] );
		foreach ( $products as $product ) foreach ( (array) ( is_array( $product ) ? ( $product['attributes'] ?? [] ) : [] ) as $attribute ) {
			$name = sanitize_key( (string) ( is_array( $attribute ) ? ( $attribute['name'] ?? '' ) : '' ) );
			if ( str_starts_with( $name, 'pa_' ) ) $taxonomies[] = $name;
		}
		foreach ( array_unique( $taxonomies ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) continue;
			if ( ! function_exists( 'wc_create_attribute' ) ) throw new \RuntimeException( 'WooCommerce cannot create product attribute taxonomy ' . $taxonomy . '.' );
			$slug = substr( preg_replace( '/^pa_/', '', $taxonomy ), 0, 28 );
			$result = wc_create_attribute( [ 'name' => ucwords( str_replace( [ '-', '_' ], ' ', $slug ) ), 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ] );
			if ( is_wp_error( $result ) ) throw new \RuntimeException( $result->get_error_message() );
			$attribute_id = absint( $result );
			$this->ledgers->append( $this->ledger_id, 'created', 'attributes', $attribute_id );
			register_taxonomy( $taxonomy, [ 'product' ], [ 'hierarchical' => false, 'public' => false, 'show_ui' => true, 'query_var' => true, 'rewrite' => false ] );
		}
	}

	private function import_terms( array $records ): void {
		$pending = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) continue;
			$taxonomy = sanitize_key( (string) ( $record['taxonomy'] ?? '' ) );
			$slug = sanitize_title( (string) ( $record['slug'] ?? '' ) );
			$source_id = absint( $record['sourceId'] ?? 0 );
			if ( ! $source_id || ! taxonomy_exists( $taxonomy ) || '' === $slug ) throw new \RuntimeException( 'A term cannot be mapped safely on the destination.' );
			$exists = term_exists( $slug, $taxonomy );
			if ( $exists ) {
				$target_id = absint( is_array( $exists ) ? ( $exists['term_id'] ?? 0 ) : $exists );
				$this->ledgers->append( $this->ledger_id, 'reused', 'terms', $target_id );
			} else {
				$result = wp_insert_term( sanitize_text_field( (string) ( $record['name'] ?? $slug ) ), $taxonomy, [ 'slug' => $slug, 'description' => sanitize_textarea_field( (string) ( $record['description'] ?? '' ) ) ] );
				if ( is_wp_error( $result ) ) throw new \RuntimeException( $result->get_error_message() );
				$target_id = absint( $result['term_id'] ?? 0 );
				$this->ledgers->append( $this->ledger_id, 'created', 'terms', $target_id );
				$this->ledgers->append_reference( $this->ledger_id, 'created', 'termRefs', [ 'id' => $target_id, 'taxonomy' => $taxonomy ] );
			}
			$this->term_map[ $taxonomy ][ $source_id ] = $target_id;
			$pending[] = [ $target_id, $taxonomy, absint( $record['parentSourceId'] ?? 0 ), ! $exists ];
		}
		foreach ( $pending as [ $target_id, $taxonomy, $parent_source_id, $created ] ) {
			if ( $created && $parent_source_id && ! empty( $this->term_map[ $taxonomy ][ $parent_source_id ] ) ) wp_update_term( $target_id, $taxonomy, [ 'parent' => $this->term_map[ $taxonomy ][ $parent_source_id ] ] );
		}
	}

	private function import_media( array $records ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$source_host = strtolower( (string) parse_url( $this->source_origin, PHP_URL_HOST ) );
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) continue;
			$source_id = absint( $record['sourceId'] ?? 0 );
			$source_key = sanitize_text_field( (string) ( $record['sourceKey'] ?? '' ) );
			$url = esc_url_raw( (string) ( $record['sourceUrl'] ?? '' ) );
			if ( ! $source_id || '' === $source_key || 'https' !== strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) || $source_host !== strtolower( (string) parse_url( $url, PHP_URL_HOST ) ) ) throw new \RuntimeException( 'A media resource failed the source-host boundary.' );
			$existing = $this->post_by_source_key( $source_key, 'attachment' );
			if ( $existing ) {
				$this->media_map[ $source_id ] = $existing;
				$this->media_url_map[ $url ] = (string) wp_get_attachment_url( $existing );
				$this->ledgers->append( $this->ledger_id, 'reused', 'media', $existing );
				continue;
			}
			$temp = download_url( $url, 30 );
			if ( is_wp_error( $temp ) ) throw new \RuntimeException( 'Media download failed: ' . $temp->get_error_message() );
			try {
				if ( ! is_file( $temp ) || filesize( $temp ) > 25 * MB_IN_BYTES ) throw new \RuntimeException( 'A media resource exceeded the 25 MB import limit.' );
				$file = [ 'name' => sanitize_file_name( (string) ( $record['filename'] ?? wp_basename( (string) parse_url( $url, PHP_URL_PATH ) ) ) ), 'tmp_name' => $temp ];
				$attachment_id = media_handle_sideload( $file, 0, sanitize_text_field( (string) ( $record['title'] ?? '' ) ), [ 'post_excerpt' => sanitize_textarea_field( (string) ( $record['caption'] ?? '' ) ), 'post_content' => wp_kses_post( (string) ( $record['description'] ?? '' ) ) ] );
				if ( is_wp_error( $attachment_id ) ) throw new \RuntimeException( $attachment_id->get_error_message() );
				$temp = '';
				update_post_meta( $attachment_id, '_kiwe_staging_seed_source_key', $source_key );
				update_post_meta( $attachment_id, '_kiwe_staging_seed_source_url', $url );
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) ( $record['alt'] ?? '' ) ) );
				$this->media_map[ $source_id ] = absint( $attachment_id );
				$this->media_url_map[ $url ] = (string) wp_get_attachment_url( $attachment_id );
				$this->ledgers->append( $this->ledger_id, 'created', 'media', absint( $attachment_id ) );
			} finally {
				if ( '' !== $temp && is_file( $temp ) ) @unlink( $temp );
			}
		}
	}

	private function import_content( array $records ): void {
		$parents = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) continue;
			$post_type = sanitize_key( (string) ( $record['postType'] ?? '' ) );
			$source_id = absint( $record['sourceId'] ?? 0 );
			$source_key = sanitize_text_field( (string) ( $record['sourceKey'] ?? '' ) );
			if ( ! $source_id || ! post_type_exists( $post_type ) || in_array( $post_type, [ 'attachment', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon' ], true ) ) throw new \RuntimeException( 'A content record is outside the staging import boundary.' );
			$target_id = $this->post_by_source_key( $source_key, $post_type );
			if ( ! $target_id ) {
				$existing = get_page_by_path( sanitize_title( (string) ( $record['slug'] ?? '' ) ), OBJECT, $post_type );
				$target_id = $existing instanceof \WP_Post ? $existing->ID : 0;
			}
			$fields = [ 'post_type' => $post_type, 'post_status' => 'publish', 'post_author' => get_current_user_id(), 'post_name' => sanitize_title( (string) ( $record['slug'] ?? '' ) ), 'post_title' => sanitize_text_field( (string) ( $record['title'] ?? '' ) ), 'post_content' => $this->localize_content( (string) ( $record['content'] ?? '' ) ), 'post_excerpt' => wp_kses_post( (string) ( $record['excerpt'] ?? '' ) ), 'menu_order' => (int) ( $record['menuOrder'] ?? 0 ) ];
			if ( $target_id ) { $fields['ID'] = $target_id; $result = wp_update_post( wp_slash( $fields ), true ); $bucket = 'updated'; }
			else { $result = wp_insert_post( wp_slash( $fields ), true ); $bucket = 'created'; }
			if ( is_wp_error( $result ) ) throw new \RuntimeException( $result->get_error_message() );
			$target_id = absint( $result );
			update_post_meta( $target_id, '_kiwe_staging_seed_source_key', $source_key );
			$this->import_public_meta( $target_id, (array) ( $record['publicMeta'] ?? [] ) );
			$this->assign_terms( $target_id, (array) ( $record['terms'] ?? [] ) );
			$featured = absint( $record['featuredMediaId'] ?? 0 );
			if ( $featured && ! empty( $this->media_map[ $featured ] ) ) set_post_thumbnail( $target_id, $this->media_map[ $featured ] );
			$this->post_map[ $post_type ][ $source_id ] = $target_id;
			$this->post_map['all'][ $source_id ] = $target_id;
			$parents[] = [ $target_id, $post_type, absint( $record['parentSourceId'] ?? 0 ) ];
			$this->ledgers->append( $this->ledger_id, $bucket, 'posts', $target_id );
		}
		foreach ( $parents as [ $target_id, $post_type, $parent_source_id ] ) if ( $parent_source_id && ! empty( $this->post_map[ $post_type ][ $parent_source_id ] ) ) wp_update_post( [ 'ID' => $target_id, 'post_parent' => $this->post_map[ $post_type ][ $parent_source_id ] ] );
	}

	private function import_products( array $records ): void {
		if ( [] !== $records && ! function_exists( 'wc_get_product' ) ) throw new \RuntimeException( 'WooCommerce is required to import the verified products.' );
		$links = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) continue;
			$type = sanitize_key( (string) ( $record['type'] ?? 'simple' ) );
			$source_id = absint( $record['sourceId'] ?? 0 );
			$source_key = sanitize_text_field( (string) ( $record['sourceKey'] ?? '' ) );
			$target_id = $this->product_target_id( $record );
			$existing = $target_id ? wc_get_product( $target_id ) : false;
			if ( $existing && $type !== sanitize_key( (string) $existing->get_type() ) ) throw new \RuntimeException( 'An existing product has a conflicting WooCommerce type.' );
			$product = $existing ?: $this->new_product( $type );
			$product->set_name( sanitize_text_field( (string) ( $record['name'] ?? '' ) ) );
			$product->set_slug( sanitize_title( (string) ( $record['slug'] ?? '' ) ) );
			$product->set_status( 'publish' );
			$product->set_description( wp_kses_post( $this->localize_content( (string) ( $record['description'] ?? '' ) ) ) );
			$product->set_short_description( wp_kses_post( (string) ( $record['shortDescription'] ?? '' ) ) );
			$product->set_sku( sanitize_text_field( (string) ( $record['sku'] ?? '' ) ) );
			$product->set_regular_price( wc_format_decimal( (string) ( $record['regularPrice'] ?? '' ) ) );
			$product->set_sale_price( wc_format_decimal( (string) ( $record['salePrice'] ?? '' ) ) );
			$product->set_tax_status( sanitize_key( (string) ( $record['taxStatus'] ?? 'taxable' ) ) );
			$product->set_tax_class( sanitize_key( (string) ( $record['taxClass'] ?? '' ) ) );
			$product->set_stock_status( sanitize_key( (string) ( $record['stockStatus'] ?? 'instock' ) ) );
			$product->set_manage_stock( ! empty( $record['manageStock'] ) );
			$product->set_stock_quantity( is_null( $record['stockQuantity'] ?? null ) ? null : wc_stock_amount( $record['stockQuantity'] ) );
			$product->set_backorders( sanitize_key( (string) ( $record['backorders'] ?? 'no' ) ) );
			$product->set_sold_individually( ! empty( $record['soldIndividually'] ) );
			$product->set_virtual( ! empty( $record['virtual'] ) );
			$product->set_downloadable( ! empty( $record['downloadable'] ) );
			if ( ! empty( $record['downloadFileCount'] ) ) $this->ledgers->warning( $this->ledger_id, 'Downloadable product file URLs were excluded by the SiteGraph security boundary.' );
			$product->set_weight( wc_format_decimal( (string) ( $record['weight'] ?? '' ) ) );
			$dimensions = is_array( $record['dimensions'] ?? null ) ? $record['dimensions'] : [];
			$product->set_length( wc_format_decimal( (string) ( $dimensions['length'] ?? '' ) ) );
			$product->set_width( wc_format_decimal( (string) ( $dimensions['width'] ?? '' ) ) );
			$product->set_height( wc_format_decimal( (string) ( $dimensions['height'] ?? '' ) ) );
			$image_id = absint( $record['imageMediaId'] ?? 0 );
			$product->set_image_id( absint( $this->media_map[ $image_id ] ?? 0 ) );
			$product->set_gallery_image_ids( array_values( array_filter( array_map( fn( $id ): int => absint( $this->media_map[ absint( $id ) ] ?? 0 ), (array) ( $record['galleryMediaIds'] ?? [] ) ) ) ) );
			$product->set_attributes( $this->product_attributes( (array) ( $record['attributes'] ?? [] ) ) );
			$product->set_default_attributes( array_map( 'sanitize_text_field', (array) ( $record['defaultAttributes'] ?? [] ) ) );
			if ( 'external' === $type && method_exists( $product, 'set_product_url' ) ) { $product->set_product_url( esc_url_raw( (string) ( $record['externalUrl'] ?? '' ) ) ); $product->set_button_text( sanitize_text_field( (string) ( $record['buttonText'] ?? '' ) ) ); }
			$target_id = absint( $product->save() );
			if ( ! $target_id ) throw new \RuntimeException( 'WooCommerce did not save a product.' );
			update_post_meta( $target_id, '_kiwe_staging_seed_source_key', $source_key );
			$this->import_public_meta( $target_id, (array) ( $record['publicMeta'] ?? [] ) );
			$this->assign_terms( $target_id, (array) ( $record['terms'] ?? [] ) );
			$nutrition = absint( $record['kiwe']['nutritionImageMediaId'] ?? 0 );
			if ( $nutrition && ! empty( $this->media_map[ $nutrition ] ) ) update_post_meta( $target_id, Product_Context_Service::META_NUTRITION_IMAGE_ID, $this->media_map[ $nutrition ] );
			$this->product_map[ $source_id ] = $target_id;
			$this->post_map['all'][ $source_id ] = $target_id;
			$this->ledgers->append( $this->ledger_id, $existing ? 'updated' : 'created', 'posts', $target_id );
			$this->import_variations( $target_id, (array) ( $record['variations'] ?? [] ) );
			$links[] = [ $target_id, (array) ( $record['linkedSourceIds'] ?? [] ), $type ];
		}
		foreach ( $links as [ $target_id, $source_links, $type ] ) {
			$product = wc_get_product( $target_id );
			if ( ! $product ) continue;
			$product->set_upsell_ids( $this->map_products( (array) ( $source_links['upsells'] ?? [] ) ) );
			$product->set_cross_sell_ids( $this->map_products( (array) ( $source_links['crossSells'] ?? [] ) ) );
			if ( 'grouped' === $type && method_exists( $product, 'set_children' ) ) $product->set_children( $this->map_products( (array) ( $source_links['grouped'] ?? [] ) ) );
			$product->save();
		}
	}

	private function import_variations( int $parent_id, array $records ): void {
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) continue;
			$source_id = absint( $record['sourceId'] ?? 0 );
			$source_key = substr( hash( 'sha256', strtolower( untrailingslashit( $this->source_origin ) ) . '|product_variation|' . $source_id ), 0, 32 );
			$target_id = $this->post_by_source_key( $source_key, 'product_variation' );
			if ( ! $target_id && ! empty( $record['sku'] ) ) $target_id = absint( wc_get_product_id_by_sku( sanitize_text_field( (string) $record['sku'] ) ) );
			$variation = $target_id ? wc_get_product( $target_id ) : new \WC_Product_Variation();
			if ( ! $variation instanceof \WC_Product_Variation ) throw new \RuntimeException( 'A variation SKU resolves to a non-variation product.' );
			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' === ( $record['status'] ?? '' ) ? 'publish' : 'private' );
			$variation->set_sku( sanitize_text_field( (string) ( $record['sku'] ?? '' ) ) );
			$variation->set_regular_price( wc_format_decimal( (string) ( $record['regularPrice'] ?? '' ) ) );
			$variation->set_sale_price( wc_format_decimal( (string) ( $record['salePrice'] ?? '' ) ) );
			$variation->set_stock_status( sanitize_key( (string) ( $record['stockStatus'] ?? 'instock' ) ) );
			$variation->set_stock_quantity( is_null( $record['stockQuantity'] ?? null ) ? null : wc_stock_amount( $record['stockQuantity'] ) );
			$variation->set_attributes( array_map( 'sanitize_text_field', (array) ( $record['attributes'] ?? [] ) ) );
			$image_id = absint( $record['imageMediaId'] ?? 0 );
			$variation->set_image_id( absint( $this->media_map[ $image_id ] ?? 0 ) );
			$variation->set_virtual( ! empty( $record['virtual'] ) );
			$variation->set_downloadable( ! empty( $record['downloadable'] ) );
			$id = absint( $variation->save() );
			update_post_meta( $id, '_kiwe_staging_seed_source_key', $source_key );
			$this->ledgers->append( $this->ledger_id, $target_id ? 'updated' : 'created', 'posts', $id );
		}
	}

	private function import_menus( array $menus ): void {
		$locations = get_theme_mod( 'nav_menu_locations', [] );
		$locations = is_array( $locations ) ? $locations : [];
		foreach ( $menus as $menu ) {
			if ( ! is_array( $menu ) || empty( $menu['slug'] ) ) continue;
			$object = wp_get_nav_menu_object( sanitize_title( (string) $menu['slug'] ) );
			if ( $object ) { $menu_id = absint( $object->term_id ); $this->ledgers->append( $this->ledger_id, 'reused', 'menus', $menu_id ); foreach ( wp_get_nav_menu_items( $menu_id ) ?: [] as $old_item ) wp_delete_post( absint( $old_item->ID ?? 0 ), true ); }
			else { $result = wp_create_nav_menu( sanitize_text_field( (string) ( $menu['name'] ?? $menu['slug'] ) ) ); if ( is_wp_error( $result ) ) throw new \RuntimeException( $result->get_error_message() ); $menu_id = absint( $result ); $this->ledgers->append( $this->ledger_id, 'created', 'menus', $menu_id ); }
			$item_map = [];
			$pending = [];
			foreach ( (array) ( $menu['items'] ?? [] ) as $item ) {
				if ( ! is_array( $item ) ) continue;
				$source_item_id = absint( $item['id'] ?? 0 );
				$object_id = absint( $this->post_map['all'][ absint( $item['objectId'] ?? 0 ) ] ?? 0 );
				$args = [ 'menu-item-title' => sanitize_text_field( (string) ( $item['title'] ?? '' ) ), 'menu-item-status' => 'publish', 'menu-item-classes' => implode( ' ', array_map( 'sanitize_html_class', (array) ( $item['classes'] ?? [] ) ) ) ];
				if ( $object_id ) { $args['menu-item-type'] = 'post_type'; $args['menu-item-object'] = sanitize_key( (string) ( $item['object'] ?? get_post_type( $object_id ) ) ); $args['menu-item-object-id'] = $object_id; }
				else { $args['menu-item-type'] = 'custom'; $args['menu-item-url'] = $this->localize_url( (string) ( $item['url'] ?? '' ) ); }
				$parent_source = absint( $item['parent'] ?? 0 );
				if ( $parent_source && ! empty( $item_map[ $parent_source ] ) ) $args['menu-item-parent-id'] = $item_map[ $parent_source ];
				$target_item = wp_update_nav_menu_item( $menu_id, 0, $args );
				if ( is_wp_error( $target_item ) ) throw new \RuntimeException( $target_item->get_error_message() );
				$item_map[ $source_item_id ] = absint( $target_item );
				$pending[] = [ absint( $target_item ), $parent_source, $args ];
				$this->ledgers->append( $this->ledger_id, 'created', 'posts', absint( $target_item ) );
			}
			foreach ( $pending as [ $target_item, $parent_source, $args ] ) if ( $parent_source && ! empty( $item_map[ $parent_source ] ) ) { $args['menu-item-parent-id'] = $item_map[ $parent_source ]; $result = wp_update_nav_menu_item( $menu_id, $target_item, $args ); if ( is_wp_error( $result ) ) throw new \RuntimeException( $result->get_error_message() ); }
			foreach ( (array) ( $menu['locations'] ?? [] ) as $location ) $locations[ sanitize_key( (string) $location ) ] = $menu_id;
		}
		set_theme_mod( 'nav_menu_locations', $locations );
	}

	private function import_site_context( array $site, array $context ): void {
		if ( ! empty( $site['name'] ) ) update_option( 'blogname', sanitize_text_field( (string) $site['name'] ) );
		update_option( 'blogdescription', sanitize_text_field( (string) ( $site['description'] ?? '' ) ) );
		$logo = absint( $this->media_map[ absint( $site['logoMediaId'] ?? 0 ) ] ?? 0 );
		$inverse = absint( $this->media_map[ absint( $site['logoInverseMediaId'] ?? 0 ) ] ?? 0 );
		$icon = absint( $this->media_map[ absint( $site['siteIconMediaId'] ?? 0 ) ] ?? 0 );
		update_option( Site_Identity_Service::OPTION_LOGO, $logo, false );
		update_option( Site_Identity_Service::OPTION_LOGO_INVERSE, $inverse, false );
		set_theme_mod( 'custom_logo', $logo );
		if ( $icon ) update_option( 'site_icon', $icon, false );
		update_option( Site_Identity_Service::OPTION_STORE_PHONE, sanitize_text_field( (string) ( $site['publicContact']['phone'] ?? '' ) ), false );
		update_option( Site_Identity_Service::OPTION_STORE_EMAIL, sanitize_email( (string) ( $site['publicContact']['email'] ?? '' ) ), false );
		foreach ( [ 'currency' => 'woocommerce_currency', 'currencyPosition' => 'woocommerce_currency_pos', 'weightUnit' => 'woocommerce_weight_unit', 'dimensionUnit' => 'woocommerce_dimension_unit' ] as $source => $option ) if ( isset( $site['commerce'][ $source ] ) ) update_option( $option, sanitize_key( (string) $site['commerce'][ $source ] ), false );
		$page_authority = is_array( $site['pageAuthority'] ?? null ) ? $site['pageAuthority'] : [];
		if ( [] !== $page_authority ) {
			update_option( 'show_on_front', 'page' === ( $page_authority['showOnFront'] ?? '' ) ? 'page' : 'posts', false );
			$this->map_page_option( 'page_on_front', absint( $page_authority['frontPageSourceId'] ?? 0 ) );
			$this->map_page_option( 'page_for_posts', absint( $page_authority['postsPageSourceId'] ?? 0 ) );
			$woo_pages = is_array( $page_authority['woo'] ?? null ) ? $page_authority['woo'] : [];
			foreach ( [ 'shopSourceId' => 'woocommerce_shop_page_id', 'cartSourceId' => 'woocommerce_cart_page_id', 'checkoutSourceId' => 'woocommerce_checkout_page_id', 'myAccountSourceId' => 'woocommerce_myaccount_page_id' ] as $source_key => $option ) $this->map_page_option( $option, absint( $woo_pages[ $source_key ] ?? 0 ) );
		}
		if ( [] !== $context ) {
			$context = $this->remap_context( $context );
			$context['identity']['logoId'] = $logo;
			$context['identity']['logoInverseId'] = $inverse;
			$context['identity']['siteIconId'] = $icon;
			if ( isset( $context['commercePlan'] ) && ! isset( $context['commerce'] ) ) {
				$plan = is_array( $context['commercePlan'] ) ? $context['commercePlan'] : [];
				$context['commerce'] = $plan;
				$context['commerce']['sellingLocationMode'] = sanitize_key( (string) ( $plan['sellingLocations']['mode'] ?? 'all' ) );
				$context['commerce']['sellingCountries'] = (array) ( $plan['sellingLocations']['countries'] ?? [] );
				$context['commerce']['excludedSellingCountries'] = (array) ( $plan['sellingLocations']['excludedCountries'] ?? [] );
				$context['commerce']['shippingLocationMode'] = sanitize_key( (string) ( $plan['shippingLocations']['mode'] ?? 'all' ) );
				$context['commerce']['shippingCountries'] = (array) ( $plan['shippingLocations']['countries'] ?? [] );
			}
			unset( $context['commercePlan'], $context['privacy'], $context['complete'] );
			update_option( Design_Context_Profile_Service::OPTION_PROFILE, $context, false );
			update_option( Design_Context_Profile_Service::OPTION_STATUS, [ 'completed' => true, 'completedAt' => gmdate( 'c' ), 'completedBy' => get_current_user_id(), 'source' => 'verified-staging-seed', 'scores' => is_array( $context['scores'] ?? null ) ? $context['scores'] : [] ], false );
		}
	}

	private function remap_context( array $context ): array {
		$context['identity']['logoId'] = absint( $this->media_map[ absint( $context['identity']['logoId'] ?? 0 ) ] ?? 0 );
		$context['identity']['logoInverseId'] = absint( $this->media_map[ absint( $context['identity']['logoInverseId'] ?? 0 ) ] ?? 0 );
		$context['identity']['siteIconId'] = absint( $this->media_map[ absint( $context['identity']['siteIconId'] ?? 0 ) ] ?? 0 );
		$context['about']['founder']['userId'] = 0;
		$founder_image = absint( $context['about']['founder']['imageId'] ?? 0 );
		$context['about']['founder']['imageId'] = absint( $this->media_map[ $founder_image ] ?? 0 );
		unset( $context['about']['founder']['image'] );
		foreach ( (array) ( $context['about']['team']['members'] ?? [] ) as $index => $member ) {
			if ( ! is_array( $member ) ) continue;
			$context['about']['team']['members'][ $index ]['userId'] = 0;
			$context['about']['team']['members'][ $index ]['imageId'] = absint( $this->media_map[ absint( $member['imageId'] ?? 0 ) ] ?? 0 );
			unset( $context['about']['team']['members'][ $index ]['image'] );
		}
		foreach ( (array) ( $context['resources']['items'] ?? [] ) as $index => $resource ) if ( is_array( $resource ) ) { $context['resources']['items'][ $index ]['attachmentId'] = absint( $this->media_map[ absint( $resource['attachmentId'] ?? 0 ) ] ?? 0 ); unset( $context['resources']['items'][ $index ]['url'], $context['resources']['items'][ $index ]['mimeType'], $context['resources']['items'][ $index ]['title'] ); }
		foreach ( (array) ( $context['contentPlan']['existingPages'] ?? [] ) as $index => $page ) if ( is_array( $page ) ) $context['contentPlan']['existingPages'][ $index ]['id'] = absint( $this->post_map['page'][ absint( $page['id'] ?? 0 ) ] ?? 0 );
		foreach ( (array) ( $context['services']['items'] ?? [] ) as $index => $service ) if ( is_array( $service ) ) { $context['services']['items'][ $index ]['recordId'] = absint( $this->post_map['all'][ absint( $service['recordId'] ?? 0 ) ] ?? 0 ); $context['services']['items'][ $index ]['imageId'] = absint( $this->media_map[ absint( $service['imageId'] ?? 0 ) ] ?? 0 ); }
		return $context;
	}

	private function map_page_option( string $option, int $source_id ): void {
		$target_id = $source_id ? absint( $this->post_map['page'][ $source_id ] ?? 0 ) : 0;
		update_option( sanitize_key( $option ), $target_id, false );
	}

	/** Removes only public posts/products absent from the verified package. */
	private function reconcile_public_records( array $content, array $products, array $site ): void {
		if ( empty( $site['pageAuthority'] ) ) {
			throw new \RuntimeException( 'This package cannot perform clean reconciliation. Pull a new package from a source that reports Clean reconciliation ready.' );
		}
		$expected_by_type = [];
		foreach ( $content as $record ) {
			if ( ! is_array( $record ) ) continue;
			$type = sanitize_key( (string) ( $record['postType'] ?? '' ) );
			$target_id = absint( $this->post_map[ $type ][ absint( $record['sourceId'] ?? 0 ) ] ?? 0 );
			if ( $target_id ) $expected_by_type[ $type ][] = $target_id;
		}
		foreach ( $expected_by_type as $type => $expected ) {
			if ( ! post_type_exists( $type ) || in_array( $type, [ 'attachment', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon' ], true ) ) continue;
			$current = get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ] );
			foreach ( array_diff( array_map( 'absint', (array) $current ), array_unique( array_map( 'absint', $expected ) ) ) as $post_id ) {
				if ( $post_id > 0 && wp_delete_post( $post_id, true ) ) $this->ledgers->append( $this->ledger_id, 'deleted', 'posts', $post_id );
			}
		}

		$expected_products = [];
		foreach ( $products as $record ) if ( is_array( $record ) && ! empty( $this->product_map[ absint( $record['sourceId'] ?? 0 ) ] ) ) $expected_products[] = absint( $this->product_map[ absint( $record['sourceId'] ) ] );
		$current_products = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ] );
		foreach ( array_diff( array_map( 'absint', (array) $current_products ), array_unique( $expected_products ) ) as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			$deleted = $product && method_exists( $product, 'delete' ) ? $product->delete( true ) : wp_delete_post( $product_id, true );
			if ( $deleted ) $this->ledgers->append( $this->ledger_id, 'deleted', 'posts', $product_id );
		}
	}

	private function product_attributes( array $records ): array {
		$out = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) continue;
			$raw_name = sanitize_text_field( (string) ( $record['name'] ?? '' ) );
			$name = sanitize_key( $raw_name );
			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( str_starts_with( $name, 'pa_' ) && function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? absint( wc_attribute_taxonomy_id_by_name( $name ) ) : 0 );
			$attribute->set_name( str_starts_with( $name, 'pa_' ) ? $name : $raw_name );
			$options = (array) ( $record['options'] ?? [] );
			if ( str_starts_with( $name, 'pa_' ) ) $options = array_values( array_filter( array_map( fn( $id ): int => absint( $this->term_map[ $name ][ absint( $id ) ] ?? 0 ), $options ) ) );
			else $options = array_map( 'sanitize_text_field', $options );
			$attribute->set_options( $options );
			$attribute->set_position( absint( $record['position'] ?? 0 ) );
			$attribute->set_visible( ! empty( $record['visible'] ) );
			$attribute->set_variation( ! empty( $record['variation'] ) );
			$out[] = $attribute;
		}
		return $out;
	}

	private function new_product( string $type ) {
		$class = [ 'simple' => '\\WC_Product_Simple', 'variable' => '\\WC_Product_Variable', 'grouped' => '\\WC_Product_Grouped', 'external' => '\\WC_Product_External' ][ $type ] ?? '';
		if ( '' === $class || ! class_exists( $class ) ) throw new \RuntimeException( 'Unsupported WooCommerce product type: ' . $type . '.' );
		return new $class();
	}

	private function product_target_id( array $record ): int {
		$id = $this->post_by_source_key( (string) ( $record['sourceKey'] ?? '' ), 'product' );
		if ( ! $id && ! empty( $record['sku'] ) ) $id = absint( wc_get_product_id_by_sku( sanitize_text_field( (string) $record['sku'] ) ) );
		if ( ! $id && ! empty( $record['slug'] ) ) { $post = get_page_by_path( sanitize_title( (string) $record['slug'] ), OBJECT, 'product' ); $id = $post instanceof \WP_Post ? $post->ID : 0; }
		return $id;
	}

	private function map_products( array $ids ): array { return array_values( array_filter( array_map( fn( $id ): int => absint( $this->product_map[ absint( $id ) ] ?? 0 ), $ids ) ) ); }

	private function assign_terms( int $post_id, array $terms ): void {
		$by_taxonomy = [];
		foreach ( $terms as $term ) if ( is_array( $term ) ) { $taxonomy = sanitize_key( (string) ( $term['taxonomy'] ?? '' ) ); $target = absint( $this->term_map[ $taxonomy ][ absint( $term['sourceId'] ?? 0 ) ] ?? 0 ); if ( $target ) $by_taxonomy[ $taxonomy ][] = $target; }
		foreach ( get_object_taxonomies( (string) get_post_type( $post_id ), 'objects' ) as $taxonomy => $object ) {
			if ( ! empty( $object->public ) ) wp_set_object_terms( $post_id, array_values( array_unique( (array) ( $by_taxonomy[ $taxonomy ] ?? [] ) ) ), $taxonomy, false );
		}
	}

	private function import_public_meta( int $post_id, array $meta ): void {
		$post_type = (string) get_post_type( $post_id );
		$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : [];
		foreach ( is_array( $registered ) ? $registered : [] as $registered_key => $schema ) {
			$key = sanitize_key( (string) $registered_key );
			if ( ! empty( $schema['show_in_rest'] ) && ! str_starts_with( $key, '_' ) && ! array_key_exists( $key, $meta ) && ! preg_match( '/password|secret|token|nonce|session|cookie|license|consumer|private|payment|credential|authorization|api[_-]?key|webhook/i', $key ) ) delete_post_meta( $post_id, $key );
		}
		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || str_starts_with( $key, '_' ) || preg_match( '/password|secret|token|nonce|session|cookie|license|consumer|private|payment|credential|authorization|api[_-]?key|webhook/i', $key ) ) continue;
			if ( is_scalar( $value ) || is_array( $value ) ) update_post_meta( $post_id, $key, $value );
		}
	}

	private function post_by_source_key( string $source_key, string $post_type ): int {
		if ( '' === $source_key ) return 0;
		$ids = get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_kiwe_staging_seed_source_key', 'meta_value' => sanitize_text_field( $source_key ), 'no_found_rows' => true ] );
		return absint( $ids[0] ?? 0 );
	}

	private function localize_content( string $content ): string {
		foreach ( $this->media_url_map as $source_url => $target_url ) if ( '' !== $target_url ) $content = str_replace( $source_url, $target_url, $content );
		$content = str_replace( untrailingslashit( $this->source_origin ), untrailingslashit( home_url( '/' ) ), $content );
		foreach ( $this->media_map as $source_id => $target_id ) {
			$url = wp_get_attachment_url( $target_id );
			if ( $url ) $content = preg_replace( '/(?<=wp-image-|attachment_)' . preg_quote( (string) $source_id, '/' ) . '\\b/', (string) $target_id, $content );
		}
		return $content;
	}

	private function localize_url( string $url ): string { return esc_url_raw( str_replace( untrailingslashit( $this->source_origin ), untrailingslashit( home_url( '/' ) ), $url ) ); }

	private function term_usage( int $term_id, string $taxonomy ): int {
		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term ? absint( $term->count ) : 0;
	}

	private function flush_runtime(): void {
		wp_cache_flush();
		flush_rewrite_rules( false );
		if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients();
		if ( class_exists( '\\Bricks\\Assets_Files' ) && method_exists( '\\Bricks\\Assets_Files', 'schedule_css_file_regeneration' ) ) \Bricks\Assets_Files::schedule_css_file_regeneration();
	}
}
