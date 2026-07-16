<?php

namespace DSA\Commerce;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Linked_Products_Service {
	private const BESTSELLER_HOOK = 'dsa_bestseller_daily_sync';
	private const CO_PURCHASE_HOOK = 'dsa_co_purchase_daily_sync';

	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'woocommerce_product_options_related', [ $this, 'render_product_cross_sell_panel' ] );
		add_action( 'wp_ajax_dsa_apply_cross_sells', [ $this, 'ajax_apply_cross_sells' ] );
		add_action( 'wp_ajax_dsa_apply_co_purchased_upsells', [ $this, 'ajax_apply_co_purchased_upsells' ] );
		add_action( 'admin_post_dsa_bestseller_sync', [ $this, 'admin_sync_bestsellers' ] );
		add_action( self::BESTSELLER_HOOK, [ $this, 'sync_bestseller_terms' ] );
		add_action( self::CO_PURCHASE_HOOK, [ $this, 'sync_co_purchase_upsells' ] );
		add_action( 'init', [ $this, 'maybe_schedule_bestsellers' ] );
		add_action( 'init', [ $this, 'maybe_schedule_co_purchase_sync' ] );

		if ( ! empty( $this->commerce_config()['bestseller_sync_on_order'] ) ) {
			add_action( 'woocommerce_order_status_completed', [ $this, 'bust_bestseller_cache' ] );
			add_action( 'woocommerce_order_status_processing', [ $this, 'bust_bestseller_cache' ] );
		}
	}

	public function recommendations_for_context( array $cart, array $current, int $limit = 4 ): array {
		$config = $this->commerce_config();

		if ( ! $this->woo_available() || empty( $config['linked_products_enabled'] ) || empty( $config['commerce_recommendations_enabled'] ) ) {
			return [];
		}

		$exclude_ids = array_filter( array_map( 'absint', $cart['productIds'] ?? [] ) );

		if ( ! empty( $current['id'] ) ) {
			$exclude_ids[] = (int) $current['id'];
		}

		$ids = [];

		if ( ! empty( $this->commerce_config()['cross_sells_enabled'] ) ) {
			$ids = array_merge( $ids, $this->cross_sell_ids_from_current( $current ), $this->cross_sell_ids_from_cart(), $this->co_purchased_ids_from_cart( $limit * 2 ) );
		}

		if ( count( $ids ) < $limit && ! empty( $this->commerce_config()['bestseller_enabled'] ) ) {
			$ids = array_merge( $ids, $this->bestseller_product_ids( 'month', $limit * 2 ) );
		}

		$ids = array_values( array_diff( array_unique( array_filter( array_map( 'absint', $ids ) ) ), array_unique( $exclude_ids ) ) );

		return $this->normalize_products( array_slice( $ids, 0, $limit ), 'linked_products' );
	}

	public function cart_recommendations( int $limit = 6 ): array {
		$config = $this->commerce_config();

		if ( ! $this->woo_available() || empty( $config['linked_products_enabled'] ) || empty( $config['commerce_recommendations_enabled'] ) || empty( $config['fbt_enabled'] ) ) {
			return [];
		}

		$limit = max( 1, min( 12, $limit ) );
		$cart_product_ids = $this->cart_product_ids();
		$ids = array_merge( $this->cross_sell_ids_from_cart(), $this->co_purchased_ids_from_cart( $limit * 2 ) );

		if ( count( $ids ) < $limit && ! empty( $config['bestseller_enabled'] ) ) {
			$ids = array_merge( $ids, $this->bestseller_product_ids( 'month', $limit * 2 ) );
		}

		$ids = array_values( array_diff( array_unique( array_filter( array_map( 'absint', $ids ) ) ), $cart_product_ids ) );

		$products = $this->normalize_products( $ids, 'frequently_bought_together' );

		if ( empty( $config['fbt_show_out_of_stock'] ) ) {
			$products = array_values(
				array_filter(
					$products,
					static fn ( array $product ): bool => ! empty( $product['inStock'] )
				)
			);
		}

		return array_slice( $products, 0, $limit );
	}

	public function product_categories(): array {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return [];
		}

		return array_map(
			static function ( $term ): array {
				return [
					'id'    => (int) $term->term_id,
					'name'  => (string) $term->name,
					'count' => (int) $term->count,
				];
			},
			$terms
		);
	}

	public function run_bulk_cross_sells( array $args ): array {
		$source_type = sanitize_key( $args['source_type'] ?? 'all' );
		$source_cat = absint( $args['source_cat'] ?? 0 );
		$target_cats = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $args['target_cats'] ?? [] ) ) ) ) );
		$own_category = ! empty( $args['own_category'] );
		$mode = 'replace' === sanitize_key( $args['mode'] ?? 'merge' ) ? 'replace' : 'merge';
		$product_ids = $this->product_ids_for_source( $source_type, $source_cat );
		$updated = 0;
		$total_links = 0;

		foreach ( $product_ids as $product_id ) {
			$categories = $own_category ? $this->product_category_ids( (int) $product_id ) : $target_cats;

			if ( empty( $categories ) ) {
				continue;
			}

			$result = $this->apply_cross_sells_for_product( (int) $product_id, $categories, $mode );

			if ( $result['changed'] ) {
				$updated++;
			}

			$total_links += (int) $result['count'];
		}

		$this->save_cross_sell_mapping(
			[
				'source_type'  => $source_type,
				'source_cat'   => $source_cat,
				'target_cats'  => $target_cats,
				'own_category' => $own_category,
				'mode'         => $mode,
			]
		);

		return [
			'products'    => count( $product_ids ),
			'updated'     => $updated,
			'total_links' => $total_links,
		];
	}

	public function clear_cross_sells( array $args ): array {
		$source_type = sanitize_key( $args['source_type'] ?? 'all' );
		$source_cat = absint( $args['source_cat'] ?? 0 );
		$product_ids = $this->product_ids_for_source( $source_type, $source_cat );
		$updated = 0;

		foreach ( $product_ids as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;

			if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'set_cross_sell_ids' ) ) {
				continue;
			}

			$existing = method_exists( $product, 'get_cross_sell_ids' ) ? array_map( 'absint', $product->get_cross_sell_ids() ) : [];

			if ( empty( $existing ) ) {
				continue;
			}

			$product->set_cross_sell_ids( [] );
			$product->save();
			$updated++;
		}

		return [
			'products' => count( $product_ids ),
			'updated'  => $updated,
		];
	}

	public function saved_cross_sell_mappings(): array {
		$mappings = get_option( 'acs_saved_mappings', [] );

		if ( ! is_array( $mappings ) ) {
			return [];
		}

		$out = [];

		foreach ( array_values( $mappings ) as $index => $mapping ) {
			if ( ! is_array( $mapping ) ) {
				continue;
			}

			$source_type = sanitize_key( $mapping['source_type'] ?? 'all' );
			$source_cat = absint( $mapping['source_cat'] ?? 0 );
			$target_cats = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $mapping['target_cats'] ?? $mapping['target_categories'] ?? [] ) ) ) ) );
			$own_category = ! empty( $mapping['own_category'] );

			$out[] = [
				'index'        => $index,
				'source_type'  => $source_type,
				'source_cat'   => $source_cat,
				'source_label' => 'category' === $source_type && $source_cat ? $this->category_name( $source_cat ) : __( 'All products', 'dsa' ),
				'target_cats'  => $target_cats,
				'target_label' => $own_category ? __( 'Each product category', 'dsa' ) : $this->category_list_label( $target_cats ),
				'own_category' => $own_category,
				'mode'         => 'replace' === sanitize_key( $mapping['mode'] ?? 'merge' ) ? 'replace' : 'merge',
				'last_run'     => sanitize_text_field( (string) ( $mapping['last_run'] ?? $mapping['updated_at'] ?? '' ) ),
			];
		}

		return $out;
	}

	public function rerun_saved_cross_sell_mapping( int $index ): array {
		$mappings = array_values( (array) get_option( 'acs_saved_mappings', [] ) );

		if ( ! isset( $mappings[ $index ] ) || ! is_array( $mappings[ $index ] ) ) {
			return [
				'products'    => 0,
				'updated'     => 0,
				'total_links' => 0,
			];
		}

		return $this->run_bulk_cross_sells( $mappings[ $index ] );
	}

	public function delete_saved_cross_sell_mapping( int $index ): bool {
		$mappings = array_values( (array) get_option( 'acs_saved_mappings', [] ) );

		if ( ! isset( $mappings[ $index ] ) ) {
			return false;
		}

		unset( $mappings[ $index ] );
		update_option( 'acs_saved_mappings', array_values( $mappings ), false );

		return true;
	}

	public function run_bulk_co_purchase_upsells( array $args ): array {
		$source_type = sanitize_key( $args['source_type'] ?? 'all' );
		$source_cat = absint( $args['source_cat'] ?? 0 );
		$depth = max( 1, min( 50, absint( $args['depth'] ?? 5 ) ) );
		$mode = 'replace' === sanitize_key( $args['mode'] ?? 'merge' ) ? 'replace' : 'merge';
		$product_ids = $this->product_ids_for_source( $source_type, $source_cat );
		$updated = 0;
		$total_links = 0;

		foreach ( $product_ids as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;

			if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'set_upsell_ids' ) ) {
				continue;
			}

			$ids = array_values( array_diff( $this->co_purchased_ids_for_product( (int) $product_id, $depth ), [ (int) $product_id ] ) );

			if ( empty( $ids ) ) {
				continue;
			}

			$existing = method_exists( $product, 'get_upsell_ids' ) ? array_map( 'absint', $product->get_upsell_ids() ) : [];
			$next = 'replace' === $mode ? $ids : array_values( array_unique( array_merge( $existing, $ids ) ) );

			if ( $next === $existing ) {
				continue;
			}

			$product->set_upsell_ids( $next );
			$product->save();
			$updated++;
			$total_links += count( $next );
		}

		return [
			'products'    => count( $product_ids ),
			'updated'     => $updated,
			'total_links' => $total_links,
		];
	}

	public function current_upsell_rows( int $limit = 100 ): array {
		global $wpdb;

		$limit = max( 1, min( 250, $limit ) );
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"
				SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					AND pm.meta_key = '_upsell_ids'
					AND pm.meta_value NOT IN ('a:0:{}', '', 'N;')
					AND pm.meta_value IS NOT NULL
					AND LENGTH(pm.meta_value) > 7
				WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				ORDER BY p.post_title ASC
				LIMIT %d
				",
				$limit
			)
		);
		$out = [];

		foreach ( array_map( 'absint', is_array( $product_ids ) ? $product_ids : [] ) as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

			if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_upsell_ids' ) ) {
				continue;
			}

			$upsell_ids = array_values( array_unique( array_filter( array_map( 'absint', $product->get_upsell_ids() ) ) ) );

			if ( empty( $upsell_ids ) ) {
				continue;
			}

			$out[] = [
				'id'       => $product_id,
				'name'     => wp_strip_all_tags( $product->get_name() ),
				'count'    => count( $upsell_ids ),
				'upsells'  => array_map( [ $this, 'product_title' ], array_slice( $upsell_ids, 0, 8 ) ),
				'edit_url' => get_edit_post_link( $product_id, '' ),
			];
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return (int) $b['count'] <=> (int) $a['count'];
			}
		);

		return $out;
	}

	public function render_product_cross_sell_panel(): void {
		global $post;

		$config = $this->commerce_config();

		if ( empty( $config['linked_products_enabled'] ) || empty( $config['cross_sells_product_panel_enabled'] ) || ! $post || empty( $post->ID ) ) {
			return;
		}

		$categories = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		$categories = empty( $categories ) || is_wp_error( $categories ) ? [] : $categories;
		$co_purchased = $this->co_purchased_ids_for_product( (int) $post->ID, 8 );
		$co_purchased_titles = [];

		foreach ( array_slice( $co_purchased, 0, 5 ) as $co_purchased_id ) {
			$co_purchased_titles[] = $this->product_title( (int) $co_purchased_id );
		}

		?>
		<div class="options_group dsa-linked-products-panel">
			<p class="form-field">
				<label for="dsa_cross_sell_categories"><?php esc_html_e( 'Kiwe cross-sells', 'dsa' ); ?></label>
				<select id="dsa_cross_sell_categories" multiple="multiple" style="min-width:50%;">
					<?php foreach ( $categories as $category ) : ?>
						<option value="<?php echo esc_attr( (string) $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" data-dsa-apply-cross-sells data-product-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dsa_apply_cross_sells' ) ); ?>"><?php esc_html_e( 'Apply from categories', 'dsa' ); ?></button>
				<span class="description"><?php esc_html_e( 'Adds visible products from selected categories as WooCommerce cross-sells for this product. Existing cross-sells are preserved.', 'dsa' ); ?></span>
			</p>
			<p class="form-field">
				<label><?php esc_html_e( 'Kiwe co-purchase helper', 'dsa' ); ?></label>
				<span class="description">
					<?php if ( empty( $co_purchased ) ) : ?>
						<?php esc_html_e( 'No co-purchase signal yet. WooCommerce analytics needs completed or processing orders before this helper can suggest products.', 'dsa' ); ?>
					<?php else : ?>
						<?php echo esc_html( implode( ', ', $co_purchased_titles ) ); ?>
						<br>
						<button type="button" class="button" data-dsa-apply-co-purchase="woo_upsells" data-product-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dsa_apply_co_purchased_upsells' ) ); ?>"><?php esc_html_e( 'Merge into Woo upsells', 'dsa' ); ?></button>
						<button type="button" class="button" data-dsa-apply-co-purchase="cart_upsell" data-product-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dsa_apply_co_purchased_upsells' ) ); ?>"><?php esc_html_e( 'Set first as Kiwe cart pick', 'dsa' ); ?></button>
					<?php endif; ?>
				</span>
			</p>
		</div>
		<script>
		(function(){
			var button = document.querySelector("[data-dsa-apply-cross-sells]");
			if(!button){ return; }
			button.addEventListener("click", function(){
				var select = document.getElementById("dsa_cross_sell_categories");
				var ids = Array.prototype.map.call(select ? select.selectedOptions : [], function(option){ return option.value; });
				var body = new URLSearchParams();
				body.set("action", "dsa_apply_cross_sells");
				body.set("nonce", button.getAttribute("data-nonce") || "");
				body.set("product_id", button.getAttribute("data-product-id") || "");
				ids.forEach(function(id){ body.append("category_ids[]", id); });
				button.disabled = true;
				fetch(ajaxurl, { method: "POST", credentials: "same-origin", body: body }).then(function(response){ return response.json(); }).then(function(payload){
					button.textContent = payload && payload.success ? "<?php echo esc_js( __( 'Applied', 'dsa' ) ); ?>" : "<?php echo esc_js( __( 'Could not apply', 'dsa' ) ); ?>";
				}).catch(function(){
					button.textContent = "<?php echo esc_js( __( 'Could not apply', 'dsa' ) ); ?>";
				}).finally(function(){
					window.setTimeout(function(){ button.disabled = false; button.textContent = "<?php echo esc_js( __( 'Apply from categories', 'dsa' ) ); ?>"; }, 1800);
				});
			});
			document.querySelectorAll("[data-dsa-apply-co-purchase]").forEach(function(coButton){
				coButton.addEventListener("click", function(){
					var body = new URLSearchParams();
					body.set("action", "dsa_apply_co_purchased_upsells");
					body.set("nonce", coButton.getAttribute("data-nonce") || "");
					body.set("product_id", coButton.getAttribute("data-product-id") || "");
					body.set("mode", coButton.getAttribute("data-dsa-apply-co-purchase") || "woo_upsells");
					coButton.disabled = true;
					fetch(ajaxurl, { method: "POST", credentials: "same-origin", body: body }).then(function(response){ return response.json(); }).then(function(payload){
						coButton.textContent = payload && payload.success ? "<?php echo esc_js( __( 'Applied', 'dsa' ) ); ?>" : "<?php echo esc_js( __( 'Could not apply', 'dsa' ) ); ?>";
					}).catch(function(){
						coButton.textContent = "<?php echo esc_js( __( 'Could not apply', 'dsa' ) ); ?>";
					}).finally(function(){
						window.setTimeout(function(){ coButton.disabled = false; coButton.textContent = coButton.getAttribute("data-dsa-apply-co-purchase") === "cart_upsell" ? "<?php echo esc_js( __( 'Set first as Kiwe cart pick', 'dsa' ) ); ?>" : "<?php echo esc_js( __( 'Merge into Woo upsells', 'dsa' ) ); ?>"; }, 1800);
					});
				});
			});
		}());
		</script>
		<?php
	}

	public function ajax_apply_cross_sells(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dsa' ) ], 403 );
		}

		check_ajax_referer( 'dsa_apply_cross_sells', 'nonce' );

		$product_id   = absint( $_POST['product_id'] ?? 0 );
		$category_ids = isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['category_ids'] ) ) : [];
		$count        = $this->apply_cross_sells_to_product( $product_id, $category_ids );

		wp_send_json_success( [ 'count' => $count ] );
	}

	public function ajax_apply_co_purchased_upsells(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dsa' ) ], 403 );
		}

		check_ajax_referer( 'dsa_apply_co_purchased_upsells', 'nonce' );

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'woo_upsells' ) );
		$count = $this->apply_co_purchased_to_product( $product_id, $mode );

		wp_send_json_success( [ 'count' => $count ] );
	}

	public function admin_sync_bestsellers(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dsa' ) );
		}

		check_admin_referer( 'dsa_bestseller_sync' );
		$this->sync_bestseller_terms();
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-woocommerce', 'bestseller-synced' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function maybe_schedule_co_purchase_sync(): void {
		if ( empty( $this->commerce_config()['co_purchase_daily_sync_enabled'] ) ) {
			$timestamp = wp_next_scheduled( self::CO_PURCHASE_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CO_PURCHASE_HOOK );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::CO_PURCHASE_HOOK ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', self::CO_PURCHASE_HOOK );
		}
	}

	public function sync_co_purchase_upsells(): array {
		$config = $this->commerce_config();

		if ( empty( $config['linked_products_enabled'] ) || empty( $config['co_purchase_daily_sync_enabled'] ) ) {
			return [
				'products'    => 0,
				'updated'     => 0,
				'total_links' => 0,
			];
		}

		$result = $this->run_bulk_co_purchase_upsells(
			[
				'source_type' => 'all',
				'depth'       => absint( $config['co_purchase_daily_sync_depth'] ?? 5 ),
				'mode'        => sanitize_key( $config['co_purchase_daily_sync_mode'] ?? 'merge' ),
			]
		);

		update_option( 'dsa_co_purchase_last_sync', current_time( 'mysql' ), false );

		return $result;
	}

	public function maybe_schedule_bestsellers(): void {
		if ( empty( $this->commerce_config()['bestseller_enabled'] ) ) {
			$timestamp = wp_next_scheduled( self::BESTSELLER_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::BESTSELLER_HOOK );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::BESTSELLER_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::BESTSELLER_HOOK );
		}
	}

	public function sync_bestseller_terms(): void {
		if ( empty( $this->commerce_config()['bestseller_enabled'] ) ) {
			return;
		}

		$terms = $this->ensure_bestseller_terms();

		if ( empty( $terms ) ) {
			return;
		}

		$parent_id = (int) ( $terms['parent'] ?? 0 );
		$parent_targets = [];

		foreach ( [ 'week', 'month', 'year' ] as $period ) {
			if ( empty( $terms[ $period ] ) ) {
				continue;
			}

			$term_id = (int) $terms[ $period ];
			$product_ids = $this->bestseller_product_ids( $period, $this->bestseller_limit() );
			$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
			$current_ids = $this->products_in_product_cat( $term_id );

			foreach ( $product_ids as $product_id ) {
				$term_ids = $parent_id ? [ $parent_id, $term_id ] : [ $term_id ];
				wp_set_object_terms( (int) $product_id, $term_ids, 'product_cat', true );
			}

			foreach ( array_diff( $current_ids, $product_ids ) as $stale_id ) {
				wp_remove_object_terms( (int) $stale_id, $term_id, 'product_cat' );
			}

			$parent_targets = array_merge( $parent_targets, $product_ids );
		}

		if ( $parent_id ) {
			$parent_targets = array_values( array_unique( array_filter( array_map( 'absint', $parent_targets ) ) ) );

			foreach ( array_diff( $this->products_in_product_cat( $parent_id ), $parent_targets ) as $stale_id ) {
				wp_remove_object_terms( (int) $stale_id, $parent_id, 'product_cat' );
			}
		}

		update_option( 'dsa_bestseller_last_sync', current_time( 'mysql' ), false );
	}

	public function bust_bestseller_cache(): void {
		foreach ( [ 'week', 'month', 'year' ] as $period ) {
			delete_transient( 'dsa_bestsellers_' . $period );
		}

		foreach ( $this->cart_product_ids() as $product_id ) {
			delete_transient( 'dsa_cop_' . (int) $product_id );
		}
	}

	private function apply_cross_sells_to_product( int $product_id, array $category_ids ): int {
		if ( ! $product_id || empty( $category_ids ) || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'set_cross_sell_ids' ) ) {
			return 0;
		}

		$existing = method_exists( $product, 'get_cross_sell_ids' ) ? array_map( 'absint', $product->get_cross_sell_ids() ) : [];
		$next     = array_values( array_diff( $this->products_by_categories( $category_ids, [ $product_id ] ), [ $product_id ] ) );
		$merged   = array_values( array_unique( array_merge( $existing, $next ) ) );

		$product->set_cross_sell_ids( $merged );
		$product->save();

		return count( $merged );
	}

	private function apply_co_purchased_to_product( int $product_id, string $mode ): int {
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$ids = array_values( array_diff( $this->co_purchased_ids_for_product( $product_id, 12 ), [ $product_id ] ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		if ( 'cart_upsell' === $mode ) {
			update_post_meta( $product_id, '_sc_upsell_product_id', absint( $ids[0] ) );
			return 1;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'set_upsell_ids' ) ) {
			return 0;
		}

		$existing = method_exists( $product, 'get_upsell_ids' ) ? array_map( 'absint', $product->get_upsell_ids() ) : [];
		$merged = array_values( array_unique( array_merge( $existing, $ids ) ) );
		$product->set_upsell_ids( $merged );
		$product->save();

		return count( $merged );
	}

	private function product_ids_for_source( string $source_type, int $source_cat = 0 ): array {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		];

		if ( 'category' === $source_type && $source_cat ) {
			$args['tax_query'] = [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => [ $source_cat ],
				],
			];
		}

		return array_values( array_unique( array_filter( array_map( 'absint', get_posts( $args ) ) ) ) );
	}

	private function product_category_ids( int $product_id ): array {
		if ( ! $product_id ) {
			return [];
		}

		$terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );

		return empty( $terms ) || is_wp_error( $terms ) ? [] : array_values( array_unique( array_filter( array_map( 'absint', $terms ) ) ) );
	}

	private function apply_cross_sells_for_product( int $product_id, array $category_ids, string $mode ): array {
		if ( ! $product_id || empty( $category_ids ) || ! function_exists( 'wc_get_product' ) ) {
			return [ 'changed' => false, 'count' => 0 ];
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'set_cross_sell_ids' ) ) {
			return [ 'changed' => false, 'count' => 0 ];
		}

		$existing = method_exists( $product, 'get_cross_sell_ids' ) ? array_values( array_unique( array_filter( array_map( 'absint', $product->get_cross_sell_ids() ) ) ) ) : [];
		$next = array_values( array_diff( $this->products_by_categories( $category_ids, [ $product_id ] ), [ $product_id ] ) );
		$final = 'replace' === $mode ? $next : array_values( array_unique( array_merge( $existing, $next ) ) );

		if ( $final === $existing ) {
			return [ 'changed' => false, 'count' => count( $final ) ];
		}

		$product->set_cross_sell_ids( $final );
		$product->save();

		return [ 'changed' => true, 'count' => count( $final ) ];
	}

	private function save_cross_sell_mapping( array $mapping ): void {
		$mappings = array_values( (array) get_option( 'acs_saved_mappings', [] ) );
		$normalized = [
			'source_type'  => sanitize_key( $mapping['source_type'] ?? 'all' ),
			'source_cat'   => absint( $mapping['source_cat'] ?? 0 ),
			'target_cats'  => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $mapping['target_cats'] ?? [] ) ) ) ) ),
			'own_category' => ! empty( $mapping['own_category'] ),
			'mode'         => 'replace' === sanitize_key( $mapping['mode'] ?? 'merge' ) ? 'replace' : 'merge',
			'last_run'     => current_time( 'mysql' ),
		];

		foreach ( $mappings as $index => $existing ) {
			if ( ! is_array( $existing ) ) {
				continue;
			}

			$compare = [
				'source_type'  => sanitize_key( $existing['source_type'] ?? 'all' ),
				'source_cat'   => absint( $existing['source_cat'] ?? 0 ),
				'target_cats'  => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $existing['target_cats'] ?? $existing['target_categories'] ?? [] ) ) ) ) ),
				'own_category' => ! empty( $existing['own_category'] ),
				'mode'         => 'replace' === sanitize_key( $existing['mode'] ?? 'merge' ) ? 'replace' : 'merge',
			];

			if ( $compare === array_intersect_key( $normalized, $compare ) ) {
				$mappings[ $index ] = $normalized;
				update_option( 'acs_saved_mappings', array_values( $mappings ), false );
				return;
			}
		}

		$mappings[] = $normalized;
		update_option( 'acs_saved_mappings', array_values( $mappings ), false );
	}

	private function category_name( int $term_id ): string {
		$term = get_term( $term_id, 'product_cat' );

		return $term && ! is_wp_error( $term ) ? (string) $term->name : sprintf( __( 'Category #%d', 'dsa' ), $term_id );
	}

	private function category_list_label( array $term_ids ): string {
		$names = array_map( [ $this, 'category_name' ], array_slice( array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) ), 0, 4 ) );

		if ( empty( $names ) ) {
			return __( 'No categories', 'dsa' );
		}

		$extra = count( $term_ids ) - count( $names );

		return implode( ', ', $names ) . ( $extra > 0 ? sprintf( __( ' +%d more', 'dsa' ), $extra ) : '' );
	}

	private function products_by_categories( array $category_ids, array $exclude_ids = [] ): array {
		$category_ids = array_values( array_unique( array_filter( array_map( 'absint', $category_ids ) ) ) );

		if ( empty( $category_ids ) ) {
			return [];
		}

		return get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'post__not_in'   => array_values( array_unique( array_filter( array_map( 'absint', $exclude_ids ) ) ) ),
				'tax_query'      => [
					[
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $category_ids,
					],
				],
			]
		);
	}

	private function cross_sell_ids_from_current( array $current ): array {
		if ( 'product' !== ( $current['type'] ?? '' ) || empty( $current['id'] ) || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$product = wc_get_product( (int) $current['id'] );

		return $product && is_object( $product ) && method_exists( $product, 'get_cross_sell_ids' ) ? array_map( 'absint', $product->get_cross_sell_ids() ) : [];
	}

	private function cross_sell_ids_from_cart(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return [];
		}

		$ids = [];

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;

			if ( $product && is_object( $product ) && method_exists( $product, 'get_cross_sell_ids' ) ) {
				$ids = array_merge( $ids, array_map( 'absint', $product->get_cross_sell_ids() ) );
			}
		}

		return $ids;
	}

	private function co_purchased_ids_from_cart( int $limit ): array {
		$out = [];

		foreach ( $this->cart_product_ids() as $product_id ) {
			$out = array_merge( $out, $this->co_purchased_ids_for_product( (int) $product_id, $limit ) );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $out ) ) ) );
	}

	private function co_purchased_ids_for_product( int $product_id, int $limit ): array {
		global $wpdb;

		if ( ! $product_id ) {
			return [];
		}

		$limit  = max( 1, min( 50, $limit ) );
		$cached = get_transient( 'dsa_cop_' . $product_id );

		if ( is_array( $cached ) ) {
			return array_map( 'absint', $cached );
		}

		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$stats  = $wpdb->prefix . 'wc_order_stats';

		if ( $lookup !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) || $stats !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats ) ) ) {
			return [];
		}

		$sql = "
			SELECT pair.product_id
			FROM {$lookup} base
			INNER JOIN {$lookup} pair ON base.order_id = pair.order_id AND pair.product_id <> base.product_id
			INNER JOIN {$stats} stats ON base.order_id = stats.order_id
			WHERE base.product_id = %d
			AND stats.status IN ('wc-completed', 'wc-processing', 'completed', 'processing')
			GROUP BY pair.product_id
			ORDER BY COUNT(*) DESC
			LIMIT %d
		";

		$ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $product_id, $limit ) ) );
		set_transient( 'dsa_cop_' . $product_id, $ids, WEEK_IN_SECONDS );

		return $ids;
	}

	private function cart_product_ids(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return [];
		}

		$ids = [];

		foreach ( WC()->cart->get_cart() as $item ) {
			$ids[] = (int) ( $item['product_id'] ?? 0 );

			if ( ! empty( $item['variation_id'] ) ) {
				$ids[] = (int) $item['variation_id'];
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private function bestseller_product_ids( string $period, int $limit ): array {
		$period = in_array( $period, [ 'week', 'month', 'year' ], true ) ? $period : 'month';
		$cached = get_transient( 'dsa_bestsellers_' . $period );

		if ( is_array( $cached ) ) {
			return array_map( 'absint', $cached );
		}

		$ids = $this->analytics_bestsellers( $period, $limit );

		if ( empty( $ids ) ) {
			$ids = $this->meta_bestsellers( $limit );
		}

		set_transient( 'dsa_bestsellers_' . $period, $ids, HOUR_IN_SECONDS * 6 );

		return $ids;
	}

	private function analytics_bestsellers( string $period, int $limit ): array {
		global $wpdb;

		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$stats  = $wpdb->prefix . 'wc_order_stats';

		if ( $lookup !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) || $stats !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats ) ) ) {
			return [];
		}

		$after = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( 'week' === $period ? '7 days' : ( 'year' === $period ? '365 days' : '30 days' ) ) ) );

		$sql = "
			SELECT lookup.product_id
			FROM {$lookup} lookup
			INNER JOIN {$stats} stats ON lookup.order_id = stats.order_id
			WHERE stats.date_created >= %s
			AND stats.status IN ('wc-completed', 'wc-processing', 'completed', 'processing')
			GROUP BY lookup.product_id
			ORDER BY SUM(lookup.product_qty) DESC
			LIMIT %d
		";

		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $after, $limit ) ) );
	}

	private function meta_bestsellers( int $limit ): array {
		return get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'meta_key'       => 'total_sales',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			]
		);
	}

	private function ensure_bestseller_terms(): array {
		$config = $this->commerce_config();
		$parent_label = sanitize_text_field( $config['bestseller_parent_label'] ?? 'Bestseller' );
		$parent_slug  = sanitize_title( $config['bestseller_parent_slug'] ?? 'bestseller' );
		$parent       = term_exists( $parent_slug, 'product_cat' );

		if ( ! $parent ) {
			$parent = wp_insert_term( $parent_label, 'product_cat', [ 'slug' => $parent_slug ] );
		}

		if ( is_wp_error( $parent ) ) {
			return [];
		}

		$parent_id = (int) ( is_array( $parent ) ? $parent['term_id'] : $parent );
		$out       = [ 'parent' => $parent_id ];

		foreach ( [ 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year' ] as $period => $label ) {
			$slug = $parent_slug . '-' . $period;
			$term = term_exists( $slug, 'product_cat' );

			if ( ! $term ) {
				$term = wp_insert_term( $parent_label . ' - ' . $label, 'product_cat', [ 'slug' => $slug, 'parent' => $parent_id ] );
			}

			if ( ! is_wp_error( $term ) ) {
				$out[ $period ] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}
		}

		return $out;
	}

	private function products_in_product_cat( int $term_id ): array {
		if ( ! $term_id ) {
			return [];
		}

		return array_map(
			'absint',
			get_posts(
				[
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'tax_query'      => [
						[
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => [ $term_id ],
						],
					],
				]
			)
		);
	}

	private function normalize_products( array $ids, string $source ): array {
		$out = [];

		foreach ( $ids as $id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;

			if ( ! $product || ! is_object( $product ) ) {
				continue;
			}

			$image_id = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
			$type = method_exists( $product, 'get_type' ) ? (string) $product->get_type() : 'simple';
			$in_stock = ! method_exists( $product, 'is_in_stock' ) || $product->is_in_stock();
			$purchasable = ! method_exists( $product, 'is_purchasable' ) || $product->is_purchasable();
			$addable = $in_stock && $purchasable && in_array( $type, [ 'simple', 'variation' ], true );
			$regular_price = method_exists( $product, 'get_regular_price' ) ? (float) $product->get_regular_price() : 0.0;
			$sale_price = method_exists( $product, 'get_sale_price' ) ? (float) $product->get_sale_price() : 0.0;
			$current_price = method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0;
			$is_sale = method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() && $regular_price > 0 && $current_price > 0 && $current_price < $regular_price;

			$out[] = [
				'id'         => method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $id,
				'title'      => wp_strip_all_tags( $product->get_name() ),
				'weight'     => $this->formatted_product_weight( $product ),
				'url'        => method_exists( $product, 'get_permalink' ) ? esc_url_raw( $product->get_permalink() ) : '',
				'image'      => $image_id ? esc_url_raw( wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) ) : '',
				'price'      => $current_price > 0 ? $this->price_text( $current_price ) : ( method_exists( $product, 'get_price_html' ) ? $this->money_text( $product->get_price_html() ) : '' ),
				'salePrice'  => $is_sale ? $this->price_text( $sale_price > 0 ? $sale_price : $current_price ) : '',
				'regularPrice' => $is_sale ? $this->price_text( $regular_price ) : '',
				'isOnSale'   => $is_sale,
				'inStock'    => $in_stock,
				'purchasable' => $purchasable,
				'source'     => sanitize_key( $source ),
				'addable'    => $addable,
				'actionSafe' => $addable ? 'add_to_cart' : 'view_only',
			];
		}

		return $out;
	}

	private function formatted_product_weight( $product ): string {
		$weight = is_object( $product ) && method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '';

		return '' !== $weight && function_exists( 'wc_format_weight' ) ? $this->money_text( wc_format_weight( $weight ) ) : '';
	}

	private function bestseller_limit(): int {
		return max( 3, min( 100, (int) ( $this->commerce_config()['bestseller_limit'] ?? 20 ) ) );
	}

	private function commerce_config(): array {
		$config = $this->settings->get( 'commerce', [] );

		return is_array( $config ) ? $config : [];
	}

	private function woo_available(): bool {
		return function_exists( 'wc_get_product' ) && taxonomy_exists( 'product_cat' );
	}

	private function product_title( int $product_id ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( $product && is_object( $product ) && method_exists( $product, 'get_name' ) ) {
			return wp_strip_all_tags( $product->get_name() );
		}

		return $product_id ? sprintf( __( 'Product #%d', 'dsa' ), $product_id ) : __( 'Unknown product', 'dsa' );
	}

	private function money_text( string $html ): string {
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, $charset );
	}

	private function price_text( float $amount ): string {
		return function_exists( 'wc_price' ) ? $this->money_text( wc_price( $amount ) ) : number_format_i18n( $amount, 2 );
	}
}
