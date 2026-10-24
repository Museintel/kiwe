<?php
namespace DSA\Commerce;

use DSA\Settings;
use DSA\Diagnostics\Runtime_Profiler;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Woo-owned implementation; Core retains only general reporting and its compatibility entrance. */
final class Store_Commerce_Analytics_Service extends Store_Analytics_Service {
	private const UPSELL_COUPON_PREFIX = 'kiwe-pair-';
	private bool $syncing_upsell_coupons = false;
	private bool $commerce_registered = false;
	private Store_Analytics_Service $owner;
	public function __construct( Settings $settings, Store_Analytics_Service $owner ) {
		parent::__construct( $settings );
		$this->owner = $owner;
	}

	public function register_commerce_hooks(): void {
		if ( $this->commerce_registered || ! \DSA\Apps\Store_App_Service::enabled() ) return;
		$this->commerce_registered = true;
		add_action( 'woocommerce_checkout_order_processed', [ $this->owner, 'record_checkout_order_processed' ], 30, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this->owner, 'record_store_api_order_processed' ], 30, 1 );
		add_action( 'woocommerce_payment_complete', [ $this->owner, 'record_paid_order' ], 30, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this->owner, 'record_paid_order' ], 30, 1 );
		add_action( 'woocommerce_order_status_completed', [ $this->owner, 'record_paid_order' ], 30, 1 );
		add_action( 'woocommerce_order_refunded', [ $this->owner, 'record_order_refund' ], 30, 2 );
		add_action( 'woocommerce_add_to_cart', [ $this->owner, 'record_woocommerce_add_to_cart' ], 20, 6 );
		add_action( 'woocommerce_product_options_related', [ $this->owner, 'render_product_upsell_fields' ], 30 );
		add_action( 'woocommerce_process_product_meta', [ $this->owner, 'save_product_upsell_fields' ] );
		add_filter( 'woocommerce_get_cart_item_from_session', [ $this->owner, 'restore_cart_item_discount' ], 20, 2 );
		add_filter( 'woocommerce_get_shop_coupon_data', [ $this->owner, 'virtual_upsell_coupon_data' ], 20, 3 );
		add_filter( 'woocommerce_coupon_is_valid', [ $this->owner, 'validate_upsell_coupon' ], 20, 3 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', [ $this->owner, 'upsell_coupon_items' ], 20, 3 );
		add_filter( 'woocommerce_cart_totals_coupon_label', [ $this->owner, 'upsell_coupon_label' ], 20, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', [ $this->owner, 'sync_cart_upsell_coupons' ], 30 );
		add_action( 'woocommerce_before_calculate_totals', [ $this->owner, 'sync_cart_upsell_coupons' ], 5 );
	}


	public function record_woocommerce_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
		$this->record_cart_event(
			[
				'event_type'   => 'cart_add',
				'source'       => self::$cart_source,
				'product_id'   => (int) $product_id,
				'variation_id' => (int) $variation_id,
				'quantity'     => (int) $quantity,
				'cart_key'     => (string) $cart_item_key,
				'context'      => isset( $cart_item_data['dsa_source_context'] ) ? (string) $cart_item_data['dsa_source_context'] : '',
			]
		);
	}


	public function record_checkout_order_processed( int $order_id, $posted_data = null, $order = null ): void {
		$this->record_checkout_order( $order ?: $order_id );
	}


	public function record_store_api_order_processed( $order ): void {
		$this->record_checkout_order( $order );
	}


	public function record_paid_order( $order_id ): void {
		$this->record_purchase_order( $order_id );
	}


	public function record_order_refund( int $order_id, int $refund_id ): void {
		$refund = function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;

		if ( ! $refund || ! is_object( $refund ) || ! method_exists( $refund, 'get_amount' ) ) {
			return;
		}

		if ( method_exists( $refund, 'get_meta' ) && $refund->get_meta( '_dsa_store_refund_recorded', true ) ) {
			return;
		}

		$this->record_cart_event(
			[
				'event_type'  => 'refund',
				'source'      => 'woocommerce',
				'order_id'    => $order_id,
				'order_total' => abs( (float) $refund->get_amount() ),
				'context'     => 'order_refunded',
			]
		);

		if ( method_exists( $refund, 'update_meta_data' ) && method_exists( $refund, 'save_meta_data' ) ) {
			$refund->update_meta_data( '_dsa_store_refund_recorded', current_time( 'mysql' ) );
			$refund->save_meta_data();
		}
	}


	public function product_event_rows( int $limit = 40 ): array {
		global $wpdb;

		$this->maybe_install();
		$limit = max( 1, min( 100, $limit ) );
		$table = $this->events_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT product_id,
					COUNT(*) AS add_events,
					SUM(quantity) AS total_qty,
					COUNT(DISTINCT customer_hash) AS unique_customers,
					COUNT(DISTINCT NULLIF(user_id, 0)) AS logged_in_users,
					MAX(created_at) AS last_added
				FROM {$table}
				WHERE event_type = 'cart_add'
				AND product_id > 0
				GROUP BY product_id
				ORDER BY add_events DESC, total_qty DESC
				LIMIT %d
				",
				$limit
			),
			ARRAY_A
		);

		return array_map( [ $this, 'hydrate_product_event_row' ], is_array( $rows ) ? $rows : [] );
	}


	public function cart_event_rows( int $days = 0, int $limit = 50, string $search = '' ): array {
		global $wpdb;

		$this->maybe_install();
		$days = max( 0, min( 365, $days ) );
		$limit = max( 1, min( 250, $limit ) );
		$table = $this->events_table();
		$where = "event_type IN ('cart_add', 'cart_update', 'cart_remove', 'cart_upsell_claim')";
		$params = [];

		if ( $days > 0 ) {
			$where .= ' AND created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		}

		$sql = "
			SELECT *
			FROM {$table}
			WHERE {$where}
			ORDER BY created_at DESC
			LIMIT %d
		";
		$params[] = '' === $search ? $limit : min( 250, $limit * 4 );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$out = [];
		$query = strtolower( trim( $search ) );

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$product_id = absint( $row['product_id'] ?? 0 );
			$title = $this->product_title( $product_id );

			if ( '' !== $query && false === strpos( strtolower( $title ), $query ) ) {
				continue;
			}

			$out[] = [
				'id'                => (int) ( $row['id'] ?? 0 ),
				'event_type'        => sanitize_key( $row['event_type'] ?? '' ),
				'source'            => sanitize_key( $row['source'] ?? '' ),
				'product_id'        => $product_id,
				'title'             => $title,
				'quantity'          => (int) ( $row['quantity'] ?? 0 ),
				'context'           => sanitize_key( $row['context'] ?? '' ),
				'user_id'           => (int) ( $row['user_id'] ?? 0 ),
				'phonekey_verified' => ! empty( $row['phonekey_verified'] ),
				'customer_hash'     => substr( sanitize_text_field( (string) ( $row['customer_hash'] ?? '' ) ), 0, 12 ),
				'visitor_hash'      => substr( sanitize_text_field( (string) ( $row['visitor_hash'] ?? '' ) ), 0, 12 ),
				'contact_hash'      => substr( sanitize_text_field( (string) ( $row['contact_hash'] ?? '' ) ), 0, 12 ),
				'contact_type'      => sanitize_key( (string) ( $row['contact_type'] ?? '' ) ),
				'order_id'          => absint( $row['order_id'] ?? 0 ),
				'order_total'       => (float) ( $row['order_total'] ?? 0 ),
				'created_at'        => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				'edit_url'          => get_edit_post_link( $product_id, '' ),
			];

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}


	public function co_purchase_rows( int $limit = 40 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$stats  = $wpdb->prefix . 'wc_order_stats';

		if ( $lookup !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) || $stats !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats ) ) ) {
			return [];
		}

		$sql = "
			SELECT base.product_id AS base_product_id,
				pair.product_id AS pair_product_id,
				COUNT(DISTINCT base.order_id) AS orders,
				AVG(stats.total_sales) AS avg_order_total,
				MIN(stats.total_sales) AS min_order_total,
				MAX(stats.total_sales) AS max_order_total
			FROM {$lookup} base
			INNER JOIN {$lookup} pair ON base.order_id = pair.order_id AND pair.product_id <> base.product_id
			INNER JOIN {$stats} stats ON base.order_id = stats.order_id
			WHERE stats.status IN ('wc-completed', 'wc-processing', 'completed', 'processing')
			GROUP BY base.product_id, pair.product_id
			ORDER BY orders DESC
			LIMIT %d
		";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );
		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$base_id = absint( $row['base_product_id'] ?? 0 );
			$pair_id = absint( $row['pair_product_id'] ?? 0 );
			$out[] = [
				'base_id'         => $base_id,
				'base_title'      => $this->product_title( $base_id ),
				'pair_id'         => $pair_id,
				'pair_title'      => $this->product_title( $pair_id ),
				'orders'          => (int) ( $row['orders'] ?? 0 ),
				'avg_order_total' => $this->price_text( (float) ( $row['avg_order_total'] ?? 0 ) ),
				'min_order_total' => $this->price_text( (float) ( $row['min_order_total'] ?? 0 ) ),
				'max_order_total' => $this->price_text( (float) ( $row['max_order_total'] ?? 0 ) ),
			];
		}

		return $out;
	}


	public function co_purchase_product_summary_rows( int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 250, $limit ) );
		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$stats  = $wpdb->prefix . 'wc_order_stats';

		if ( $lookup !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) || $stats !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats ) ) ) {
			return [];
		}

		$sql = "
			SELECT base.product_id,
				COUNT(DISTINCT base.order_id) AS total_orders,
				COUNT(DISTINCT CASE WHEN bundle.order_id IS NOT NULL THEN base.order_id END) AS bundle_orders,
				MIN(CASE WHEN bundle.order_id IS NOT NULL THEN stats.total_sales END) AS min_cost,
				AVG(CASE WHEN bundle.order_id IS NOT NULL THEN stats.total_sales END) AS avg_cost,
				MAX(CASE WHEN bundle.order_id IS NOT NULL THEN stats.total_sales END) AS max_cost
			FROM {$lookup} base
			INNER JOIN {$stats} stats ON base.order_id = stats.order_id
			LEFT JOIN (
				SELECT order_id
				FROM {$lookup}
				GROUP BY order_id
				HAVING COUNT(DISTINCT product_id) > 1
			) bundle ON base.order_id = bundle.order_id
			WHERE stats.status IN ('wc-completed', 'wc-processing', 'completed', 'processing')
			GROUP BY base.product_id
			ORDER BY bundle_orders DESC, total_orders DESC
			LIMIT %d
		";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );
		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$product_id = absint( $row['product_id'] ?? 0 );
			$out[] = [
				'id'            => $product_id,
				'name'          => $this->product_title( $product_id ),
				'total_orders'  => (int) ( $row['total_orders'] ?? 0 ),
				'bundle_orders' => (int) ( $row['bundle_orders'] ?? 0 ),
				'min_cost'      => ! isset( $row['min_cost'] ) ? '' : $this->price_text( (float) $row['min_cost'] ),
				'avg_cost'      => ! isset( $row['avg_cost'] ) ? '' : $this->price_text( (float) $row['avg_cost'] ),
				'max_cost'      => ! isset( $row['max_cost'] ) ? '' : $this->price_text( (float) $row['max_cost'] ),
				'edit_url'      => get_edit_post_link( $product_id, '' ),
			];
		}

		return $out;
	}


	public function linked_product_rows( int $limit = 60 ): array {
		global $wpdb;

		$limit = max( 1, min( 120, $limit ) );
		$keys = [ '_crosssell_ids', '_upsell_ids', '_sc_upsell_product_id' ];
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$params = array_merge( $keys, [ $limit ] );
		$sql = "
			SELECT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key IN ({$placeholders})
			AND meta_value <> ''
			GROUP BY post_id
			ORDER BY post_id DESC
			LIMIT %d
		";
		$ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
		$out = [];

		foreach ( $ids as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

			if ( ! $product || ! is_object( $product ) ) {
				continue;
			}

			$cross = method_exists( $product, 'get_cross_sell_ids' ) ? array_map( 'absint', $product->get_cross_sell_ids() ) : [];
			$upsells = method_exists( $product, 'get_upsell_ids' ) ? array_map( 'absint', $product->get_upsell_ids() ) : [];
			$cart_upsell = absint( get_post_meta( $product_id, '_sc_upsell_product_id', true ) );
			$out[] = [
				'id'             => $product_id,
				'title'          => wp_strip_all_tags( $product->get_name() ),
				'cross_sells'    => count( $cross ),
				'upsells'        => count( $upsells ),
				'cart_upsell'    => $cart_upsell ? $this->product_title( $cart_upsell ) : '',
				'cart_discount'  => $cart_upsell ? $this->upsell_discount_label( $product_id ) : '',
				'edit_url'       => get_edit_post_link( $product_id, '' ),
			];
		}

		return $out;
	}


	public function bestseller_status(): array {
		$config = $this->commerce_config();
		$parent_slug = sanitize_title( $config['bestseller_parent_slug'] ?? 'bestseller' );
		$rows = [];

		foreach ( [ $parent_slug => __( 'Parent', 'dsa' ), $parent_slug . '-week' => __( 'Week', 'dsa' ), $parent_slug . '-month' => __( 'Month', 'dsa' ), $parent_slug . '-year' => __( 'Year', 'dsa' ) ] as $slug => $label ) {
			$term = term_exists( $slug, 'product_cat' );
			$count = 0;

			if ( $term && ! is_wp_error( $term ) ) {
				$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				$term_obj = get_term( $term_id, 'product_cat' );
				$count = $term_obj && ! is_wp_error( $term_obj ) ? (int) $term_obj->count : 0;
			}

			$rows[] = [
				'label' => $label,
				'slug'  => $slug,
				'count' => $count,
			];
		}

		return [
			'enabled'   => ! empty( $config['bestseller_enabled'] ),
			'sync_status' => in_array( get_option( 'dsa_bestseller_sync_status', [] )['status'] ?? '', [ 'complete','pending','failed' ], true ) ? get_option( 'dsa_bestseller_sync_status', [] )['status'] : 'unknown',
			'sync_reason' => in_array( get_option( 'dsa_bestseller_sync_status', [] )['reason'] ?? '', [ 'category_review','ranking_unavailable','write_failed' ], true ) ? get_option( 'dsa_bestseller_sync_status', [] )['reason'] : '',
			'ranking_source' => 'WooCommerce order analytics for the selected period. Empty periods have no ranked products.',
			'last_sync' => sanitize_text_field( (string) get_option( 'dsa_bestseller_last_sync', '' ) ),
			'rows'      => $rows,
		];
	}


	public function cart_upsell_offers( int $limit = 3 ): array {
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! WC() || ! WC()->cart ) {
			return [];
		}

		$limit = max( 1, min( 6, $limit ) );
		$out = [];
		$seen = [];

		foreach ( WC()->cart->get_cart() as $item ) {
			$trigger_id = absint( $item['product_id'] ?? 0 );
			$offer_id = absint( get_post_meta( $trigger_id, '_sc_upsell_product_id', true ) );
			$pair_key = $this->upsell_pair_key( $trigger_id, $offer_id );

			if ( ! $offer_id || '' === $pair_key || isset( $seen[ $pair_key ] ) ) {
				continue;
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $offer_id ) : null;

			if ( ! $product || ! is_object( $product ) || ( method_exists( $product, 'get_status' ) && 'publish' !== $product->get_status() ) || ( method_exists( $product, 'is_purchasable' ) && ! $product->is_purchasable() ) || ( method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) ) {
				continue;
			}

			$cart_item = $this->cart_item_for_product( $offer_id );
			$discount = $this->upsell_discount_meta( $trigger_id );
			$has_claimable_discount = $discount['value'] > 0;

			if ( empty( $cart_item ) ) {
				if ( ( method_exists( $product, 'is_purchasable' ) && ! $product->is_purchasable() ) || ( method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) ) {
					continue;
				}

				$state = 'pending';
			} elseif ( $has_claimable_discount ) {
				$claimed = $this->cart_item_discount_claimed( $cart_item['item'], $trigger_id ) || $this->cart_pair_discount_claimed( $trigger_id, $offer_id );

				if ( ! $claimed ) {
					$offer_unit = $this->cart_item_unit_price( $cart_item['item'] );
					$trigger_unit = $this->cart_unit_price_for_product( $trigger_id );

					if ( $this->cart_discount_claim_conflicts( $trigger_id, $offer_id, $discount, $trigger_unit, $offer_unit ) ) {
						continue;
					}
				}

				$state = $claimed ? 'applied' : 'eligible';
			} else {
				continue;
			}

			$seen[ $pair_key ] = true;
			$out[] = $this->normalize_upsell_offer( $product, $trigger_id, $state );

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}


	public function cart_item_data_for_upsell( int $product_id, int $trigger_id ): array {
		if ( ! $product_id || ! $trigger_id ) {
			return [];
		}

		$expected = absint( get_post_meta( $trigger_id, '_sc_upsell_product_id', true ) );

		if ( $expected !== $product_id ) {
			return [];
		}

		$data = [
			'dsa_upsell_trigger_id' => $trigger_id,
			'dsa_upsell_pending'    => 1,
			'dsa_source_context'    => 'cart_upsell',
		];

		return $data;
	}


	public function claim_cart_upsell_discount( int $product_id, int $trigger_id ) {
		if ( ! $product_id || ! $trigger_id || ! \DSA\Apps\Store_App_Service::enabled() || ! WC() || ! WC()->cart ) {
			return new \WP_Error( 'dsa_cart_upsell_invalid', __( 'Cart upsell is not available.', 'dsa' ), [ 'status' => 400 ] );
		}

		$discount = $this->upsell_discount_meta( $trigger_id );

		if ( $discount['product_id'] !== $product_id || $discount['value'] <= 0 ) {
			return new \WP_Error( 'dsa_cart_upsell_invalid', __( 'This cart pick is no longer valid.', 'dsa' ), [ 'status' => 409 ] );
		}

		$cart_item = $this->cart_item_for_product( $product_id );

		if ( empty( $cart_item ) ) {
			return new \WP_Error( 'dsa_cart_upsell_missing', __( 'Add the cart pick before applying the discount.', 'dsa' ), [ 'status' => 404 ] );
		}

		if ( $this->cart_item_discount_claimed( $cart_item['item'], $trigger_id ) ) {
			return true;
		}

		if ( $this->cart_pair_discount_claimed( $trigger_id, $product_id ) ) {
			return true;
		}

		$key = (string) $cart_item['key'];
		$item = $cart_item['item'];
		$unit_price = $this->cart_item_unit_price( $item );
		$trigger_unit_price = $this->cart_unit_price_for_product( $trigger_id );

		if ( $this->cart_discount_claim_conflicts( $trigger_id, $product_id, $discount, $trigger_unit_price, $unit_price ) ) {
			return new \WP_Error( 'dsa_cart_upsell_conflict', __( 'This cart bonus overlaps with another claimed bonus.', 'dsa' ), [ 'status' => 409 ] );
		}

		WC()->cart->cart_contents[ $key ]['dsa_upsell_discount'] = $discount['value'];
		WC()->cart->cart_contents[ $key ]['dsa_upsell_discount_type'] = $discount['type'];
		WC()->cart->cart_contents[ $key ]['dsa_upsell_discount_scope'] = $discount['scope'];
		WC()->cart->cart_contents[ $key ]['dsa_upsell_trigger_id'] = $trigger_id;
		WC()->cart->cart_contents[ $key ]['dsa_upsell_unit_price'] = $unit_price;
		WC()->cart->cart_contents[ $key ]['dsa_upsell_trigger_unit_price'] = $trigger_unit_price;
		WC()->cart->cart_contents[ $key ]['dsa_upsell_key'] = uniqid( 'dsa_', true );
		WC()->cart->cart_contents[ $key ]['dsa_upsell_coupon_code'] = $this->upsell_coupon_code( $trigger_id, $product_id );
		WC()->cart->cart_contents[ $key ]['dsa_source_context'] = 'cart_upsell_discount';
		unset( WC()->cart->cart_contents[ $key ]['dsa_upsell_pending'] );

		$coupon_code = (string) WC()->cart->cart_contents[ $key ]['dsa_upsell_coupon_code'];
		WC()->cart->set_session();

		if ( '' === $coupon_code || ( ! WC()->cart->has_discount( $coupon_code ) && ! WC()->cart->apply_coupon( $coupon_code ) ) ) {
			foreach ( [ 'dsa_upsell_discount', 'dsa_upsell_discount_type', 'dsa_upsell_discount_scope', 'dsa_upsell_trigger_id', 'dsa_upsell_unit_price', 'dsa_upsell_trigger_unit_price', 'dsa_upsell_key', 'dsa_upsell_coupon_code' ] as $claim_key ) {
				unset( WC()->cart->cart_contents[ $key ][ $claim_key ] );
			}
			WC()->cart->set_session();
			return new \WP_Error( 'dsa_cart_upsell_coupon_failed', __( 'The cart bonus could not be attached safely. Please try again.', 'dsa' ), [ 'status' => 409 ] );
		}

		$this->record_cart_event(
			[
				'event_type'   => 'cart_upsell_claim',
				'source'       => 'dsa_cart',
				'product_id'   => absint( $item['product_id'] ?? $product_id ),
				'variation_id' => absint( $item['variation_id'] ?? 0 ),
				'quantity'     => absint( $item['quantity'] ?? 1 ),
				'cart_key'     => $key,
				'context'      => 'cart_upsell_discount',
			]
		);

		WC()->cart->calculate_totals();
		WC()->cart->set_session();

		return true;
	}


	public function restore_cart_item_discount( $cart_item, $values ) {
		foreach ( [ 'dsa_upsell_discount', 'dsa_upsell_discount_type', 'dsa_upsell_discount_scope', 'dsa_upsell_trigger_id', 'dsa_upsell_unit_price', 'dsa_upsell_trigger_unit_price', 'dsa_upsell_key', 'dsa_upsell_coupon_code', 'dsa_upsell_pending', 'dsa_source_context' ] as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$cart_item[ $key ] = $values[ $key ];
			}
		}

		$legacy_map = [
			'sc_upsell_discount'            => 'dsa_upsell_discount',
			'sc_upsell_discount_type'       => 'dsa_upsell_discount_type',
			'sc_upsell_discount_scope'      => 'dsa_upsell_discount_scope',
			'sc_upsell_trigger_id'          => 'dsa_upsell_trigger_id',
			'sc_upsell_unit_price'          => 'dsa_upsell_unit_price',
			'sc_upsell_trigger_unit_price'  => 'dsa_upsell_trigger_unit_price',
			'sc_upsell_key'                 => 'dsa_upsell_key',
		];

		foreach ( $legacy_map as $legacy_key => $dsa_key ) {
			if ( ! isset( $cart_item[ $dsa_key ] ) && isset( $values[ $legacy_key ] ) ) {
				$cart_item[ $dsa_key ] = $values[ $legacy_key ];
			}
		}

		if ( ! isset( $cart_item['dsa_source_context'] ) && isset( $values['sc_source_context'] ) ) {
			$cart_item['dsa_source_context'] = $values['sc_source_context'];
		}

		return $cart_item;
	}


	public function apply_cart_upsell_discounts( $cart ): void {
		$this->sync_cart_upsell_coupons( $cart );
	}


	public function virtual_upsell_coupon_data( $coupon_data, $data, $coupon ) {
		$code = is_string( $data ) ? wc_format_coupon_code( $data ) : '';

		if ( '' === $code && is_object( $coupon ) && method_exists( $coupon, 'get_code' ) ) {
			$code = wc_format_coupon_code( (string) $coupon->get_code() );
		}

		if ( ! $this->is_upsell_coupon_code( $code ) ) {
			return $coupon_data;
		}

		$claim = $this->upsell_claim_for_coupon( $code );

		if ( empty( $claim ) ) {
			return false;
		}

		return [
			'code'                        => $code,
			'amount'                      => (string) $claim['value'],
			'status'                      => 'publish',
			'discount_type'               => 'fixed' === $claim['type'] ? 'fixed_cart' : 'percent',
			'description'                 => __( 'Kiwe cart pair bonus', 'dsa' ),
			'individual_use'              => false,
			'product_ids'                 => [],
			'excluded_product_ids'        => [],
			'usage_limit'                 => 0,
			'usage_limit_per_user'        => 0,
			'limit_usage_to_x_items'      => null,
			'free_shipping'               => false,
			'exclude_sale_items'          => false,
			'minimum_amount'              => '0',
			'maximum_amount'              => '0',
			'product_categories'          => [],
			'excluded_product_categories' => [],
			'email_restrictions'          => [],
		];
	}


	public function validate_upsell_coupon( $valid, $coupon, $discounts = null ): bool {
		$code = is_object( $coupon ) && method_exists( $coupon, 'get_code' ) ? wc_format_coupon_code( (string) $coupon->get_code() ) : '';

		if ( ! $this->is_upsell_coupon_code( $code ) ) {
			return (bool) $valid;
		}

		return ! empty( $this->upsell_claim_for_coupon( $code ) );
	}


	public function upsell_coupon_items( array $items, $coupon, $discounts = null ): array {
		$code = is_object( $coupon ) && method_exists( $coupon, 'get_code' ) ? wc_format_coupon_code( (string) $coupon->get_code() ) : '';
		$claim = $this->is_upsell_coupon_code( $code ) ? $this->upsell_claim_for_coupon( $code ) : [];

		if ( empty( $claim ) ) {
			return $items;
		}

		$eligible = [];

		foreach ( $items as $key => $item ) {
			$cart_item = is_object( $item ) && isset( $item->object ) && is_array( $item->object ) ? $item->object : [];
			$product_id = $this->cart_item_effective_product_id( $cart_item );

			if ( ! in_array( $product_id, $claim['affected_ids'], true ) || ! is_object( $item ) ) {
				continue;
			}

			$adjusted = clone $item;
			$original_quantity = max( 1, (int) ( $adjusted->quantity ?? 1 ) );
			$apply_quantity = min( $original_quantity, (int) $claim['pair_quantity'] );
			$unit_price = (float) ( $adjusted->price ?? 0 ) / $original_quantity;
			$adjusted->quantity = $apply_quantity;
			$adjusted->price = $unit_price * $apply_quantity;
			$eligible[ $key ] = $adjusted;
		}

		return $eligible;
	}


	public function upsell_coupon_label( string $label, $coupon ): string {
		$code = is_object( $coupon ) && method_exists( $coupon, 'get_code' ) ? wc_format_coupon_code( (string) $coupon->get_code() ) : '';
		return $this->is_upsell_coupon_code( $code ) ? __( 'Kiwe cart bonus', 'dsa' ) : $label;
	}


	public function sync_cart_upsell_coupons( $cart ): void {
		if ( $this->syncing_upsell_coupons || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$this->syncing_upsell_coupons = true;

		try {
			// Product metadata and the current cart validate every claim; no global offer switch.
			$enabled = true;
			$active = [];
			$affected_ids = [];

			if ( $enabled ) {
				foreach ( $cart->get_cart() as $key => $item ) {
					$value = max( 0.0, (float) ( $item['dsa_upsell_discount'] ?? 0 ) );
					$trigger_id = absint( $item['dsa_upsell_trigger_id'] ?? 0 );
					$offer_id = $this->cart_item_effective_product_id( $item );
					$code = $this->upsell_coupon_code( $trigger_id, $offer_id );

					$trigger = $this->cart_item_for_product( $trigger_id );

					if ( $value <= 0 || '' === $code || empty( $trigger ) || ! $this->upsell_claim_configuration_matches( $item ) ) {
						$this->clear_upsell_claim( $cart, (string) $key );
						continue;
					}

					$scope = $this->normalize_discount_scope( (string) ( $item['dsa_upsell_discount_scope'] ?? 'single_lowest' ) );
					$offer_unit = (float) ( $item['dsa_upsell_unit_price'] ?? 0 );
					$trigger_unit = (float) ( $item['dsa_upsell_trigger_unit_price'] ?? 0 );
					$offer_unit = $offer_unit > 0 ? $offer_unit : $this->cart_item_unit_price( $item );
					$trigger_unit = $trigger_unit > 0 ? $trigger_unit : $this->cart_item_unit_price( $trigger['item'] );
					$claim_ids = $this->discount_affected_product_ids( $scope, $trigger_id, $offer_id, $trigger_unit, $offer_unit );

					if ( empty( $claim_ids ) || array_intersect( $claim_ids, $affected_ids ) ) {
						$this->clear_upsell_claim( $cart, (string) $key );
						continue;
					}

					$cart->cart_contents[ $key ]['dsa_upsell_coupon_code'] = $code;
					$active[ $code ] = true;
					$affected_ids = array_values( array_unique( array_merge( $affected_ids, $claim_ids ) ) );
				}
			}

			foreach ( (array) $cart->get_applied_coupons() as $code ) {
				$code = wc_format_coupon_code( (string) $code );

				if ( $this->is_upsell_coupon_code( $code ) && empty( $active[ $code ] ) ) {
					$cart->remove_coupon( $code );
				}
			}

			foreach ( array_keys( $active ) as $code ) {
				if ( ! $cart->has_discount( $code ) ) {
					$cart->apply_coupon( $code );
				}
			}
		} finally {
			$this->syncing_upsell_coupons = false;
		}
	}


	private function clear_upsell_claim( $cart, string $key ): void {
		if ( ! isset( $cart->cart_contents[ $key ] ) ) {
			return;
		}

		foreach ( [ 'dsa_upsell_discount', 'dsa_upsell_discount_type', 'dsa_upsell_discount_scope', 'dsa_upsell_trigger_id', 'dsa_upsell_unit_price', 'dsa_upsell_trigger_unit_price', 'dsa_upsell_key', 'dsa_upsell_coupon_code' ] as $claim_key ) {
			unset( $cart->cart_contents[ $key ][ $claim_key ] );
		}
	}


	public function render_product_upsell_fields(): void {
		// Product authoring is available whenever Woo renders its related-product panel.

		global $post;
		$post_id = $post && isset( $post->ID ) ? (int) $post->ID : 0;
		$upsell_product_id = $post_id ? absint( get_post_meta( $post_id, '_sc_upsell_product_id', true ) ) : 0;
		$upsell_product = $upsell_product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $upsell_product_id ) : null;

		echo '<div class="options_group dsa-cart-upsell-fields">';
		echo '<p class="form-field dsa-cart-upsell-product-field">';
		echo '<label for="_sc_upsell_product_id">' . esc_html__( 'Kiwe cart upsell product', 'dsa' ) . '</label>';
		echo '<select class="wc-product-search" style="width: 50%;" id="_sc_upsell_product_id" name="_sc_upsell_product_id" data-placeholder="' . esc_attr__( 'Search for a product...', 'dsa' ) . '" data-action="woocommerce_json_search_products_and_variations" data-exclude="' . esc_attr( (string) $post_id ) . '" data-allow_clear="true">';
		if ( $upsell_product && is_object( $upsell_product ) ) {
			$product_name = method_exists( $upsell_product, 'get_formatted_name' ) ? $upsell_product->get_formatted_name() : $upsell_product->get_name();
			echo '<option value="' . esc_attr( (string) $upsell_product_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( (string) $product_name ) ) . '</option>';
		}
		echo '</select>';
		echo wc_help_tip( __( 'Optional product shown as an explicit DSA cart upsell when this product is already in cart.', 'dsa' ) );
		echo '</p>';
		woocommerce_wp_text_input(
			[
				'id'          => '_sc_upsell_discount',
				'label'       => __( 'Kiwe upsell discount', 'dsa' ),
				'description' => __( 'Optional visitor-claimed discount for this cart upsell. Leave 0 for no discount.', 'dsa' ),
				'desc_tip'    => true,
				'type'        => 'number',
				'custom_attributes' => [ 'min' => '0', 'step' => '0.01' ],
			]
		);
		woocommerce_wp_select(
			[
				'id'      => '_sc_upsell_discount_type',
				'label'   => __( 'Kiwe discount type', 'dsa' ),
				'options' => [
					'percent' => __( 'Percent', 'dsa' ),
					'fixed'   => __( 'Fixed amount', 'dsa' ),
				],
			]
		);
		woocommerce_wp_select(
			[
				'id'          => '_sc_upsell_discount_scope',
				'label'       => __( 'Kiwe discount scope', 'dsa' ),
				'description' => __( 'Controls the server-side discount base when the visitor claims a cart pick discount.', 'dsa' ),
				'desc_tip'    => true,
				'options'     => [
					'single_lowest'  => __( 'Lower-priced item', 'dsa' ),
					'single_highest' => __( 'Higher-priced item', 'dsa' ),
					'both'           => __( 'Both items combined', 'dsa' ),
				],
			]
		);
		echo '</div>';
	}


	public function save_product_upsell_fields( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw_discount = wp_unslash( $_POST['_sc_upsell_discount'] ?? 0 );
		$discount     = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $raw_discount ) : (float) $raw_discount;

		update_post_meta( $post_id, '_sc_upsell_product_id', absint( $_POST['_sc_upsell_product_id'] ?? 0 ) );
		$type = sanitize_key( wp_unslash( $_POST['_sc_upsell_discount_type'] ?? 'percent' ) );
		$type = in_array( $type, [ 'percent', 'fixed' ], true ) ? $type : 'percent';
		$discount = max( 0, (float) $discount );
		update_post_meta( $post_id, '_sc_upsell_discount', 'percent' === $type ? min( 100, $discount ) : $discount );
		update_post_meta( $post_id, '_sc_upsell_discount_type', $type );
		$scope = sanitize_key( wp_unslash( $_POST['_sc_upsell_discount_scope'] ?? 'single_lowest' ) );
		update_post_meta( $post_id, '_sc_upsell_discount_scope', $this->normalize_discount_scope( $scope ) );
	}


	private function hydrate_product_event_row( array $row ): array {
		$product_id = absint( $row['product_id'] ?? 0 );

		return [
			'product_id'       => $product_id,
			'title'            => $this->product_title( $product_id ),
			'add_events'       => (int) ( $row['add_events'] ?? 0 ),
			'total_qty'        => (int) ( $row['total_qty'] ?? 0 ),
			'unique_customers' => (int) ( $row['unique_customers'] ?? 0 ),
			'logged_in_users'  => (int) ( $row['logged_in_users'] ?? 0 ),
			'last_added'       => sanitize_text_field( (string) ( $row['last_added'] ?? '' ) ),
			'edit_url'         => get_edit_post_link( $product_id, '' ),
		];
	}


	private function normalize_upsell_offer( $product, int $trigger_id, string $state = 'pending' ): array {
		$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$image_id = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
		$regular_price = method_exists( $product, 'get_regular_price' ) ? (float) $product->get_regular_price() : 0.0;
		$sale_price = method_exists( $product, 'get_sale_price' ) ? (float) $product->get_sale_price() : 0.0;
		$current_price = method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0;
		$is_sale = method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() && $regular_price > 0 && $current_price > 0 && $current_price < $regular_price;
		$discount = $this->upsell_discount_meta( $trigger_id );
		$state = in_array( $state, [ 'pending', 'eligible', 'applied' ], true ) ? $state : 'pending';
		$addable = method_exists( $product, 'is_type' ) && ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) );
		$action = 'pending' === $state ? ( $addable ? 'add_to_cart' : 'view_product' ) : ( 'eligible' === $state ? 'claim_discount' : 'applied' );
		$action_label = 'Add';
		$state_label = '';

		if ( 'pending' === $state && $discount['value'] > 0 ) {
			$action_label = __( 'Add & Save', 'dsa' );
			$state_label = __( 'Add first, then apply the bonus.', 'dsa' );
		} elseif ( 'eligible' === $state ) {
			$action_label = __( 'Apply', 'dsa' );
			$state_label = __( 'Ready to claim.', 'dsa' );
		} elseif ( 'applied' === $state ) {
			$action_label = __( 'Applied', 'dsa' );
			$state_label = __( 'Bonus applied.', 'dsa' );
		}

		return [
			'id'            => $product_id,
			'triggerId'     => $trigger_id,
			'title'         => wp_strip_all_tags( $product->get_name() ),
			'url'           => method_exists( $product, 'get_permalink' ) ? esc_url_raw( $product->get_permalink() ) : '',
			'image'         => $image_id ? esc_url_raw( wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) ) : '',
			'price'         => $current_price > 0 ? $this->price_text( $current_price ) : ( method_exists( $product, 'get_price_html' ) ? $this->money_text( $product->get_price_html() ) : '' ),
			'salePrice'     => $is_sale ? $this->price_text( $sale_price > 0 ? $sale_price : $current_price ) : '',
			'regularPrice'  => $is_sale ? $this->price_text( $regular_price ) : '',
			'isOnSale'      => $is_sale,
			'source'        => 'cart_upsell',
			'addable'       => 'add_to_cart' === $action,
			'claimable'     => 'claim_discount' === $action,
			'actionSafe'    => $action,
			'actionLabel'   => $action_label,
			'state'         => $state,
			'stateLabel'    => $state_label,
			'offerLabel'    => $this->upsell_discount_label( $trigger_id ),
			'triggerTitle'  => $this->product_title( $trigger_id ),
			'discount'      => $discount['value'],
			'discountType'  => $discount['type'],
			'discountScope' => $discount['scope'],
			'discountScopeLabel' => $this->discount_scope_label( $discount['scope'] ),
		];
	}


	private function record_checkout_order( $order_or_id ): void {
		$order = is_object( $order_or_id ) ? $order_or_id : ( function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_or_id ) ) : null );

		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$order_id = (int) $order->get_id();

		if ( ! $order_id || $order->get_meta( '_dsa_store_checkout_order_recorded', true ) ) {
			return;
		}

		$phone = method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '';
		$email = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';
		$contact = $this->contact_anchor( $phone, $email );

		$this->record_cart_event(
			[
				'event_type'   => 'checkout_order',
				'source'       => 'woocommerce',
				'user_id'      => method_exists( $order, 'get_user_id' ) ? (int) $order->get_user_id() : 0,
				'order_id'     => $order_id,
				'order_total'  => method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0,
				'contact_hash' => $contact['hash'],
				'contact_type' => $contact['type'],
				'context'      => 'order_created',
			]
		);

		$order->update_meta_data( '_dsa_store_checkout_order_recorded', current_time( 'mysql' ) );
		$order->save_meta_data();
	}


	private function record_purchase_order( $order_or_id ): void {
		$order = is_object( $order_or_id ) ? $order_or_id : ( function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_or_id ) ) : null );

		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$order_id = (int) $order->get_id();

		if ( ! $order_id || $order->get_meta( '_dsa_store_purchase_recorded', true ) ) {
			return;
		}

		$phone = method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '';
		$email = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';
		$contact = $this->contact_anchor( $phone, $email );

		$this->record_cart_event(
			[
				'event_type'   => 'purchase',
				'source'       => 'woocommerce',
				'user_id'      => method_exists( $order, 'get_user_id' ) ? (int) $order->get_user_id() : 0,
				'order_id'     => $order_id,
				'order_total'  => method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0,
				'contact_hash' => $contact['hash'],
				'contact_type' => $contact['type'],
				'context'      => 'order_processed',
			]
		);

		$order->update_meta_data( '_dsa_store_purchase_recorded', current_time( 'mysql' ) );
		$order->save_meta_data();
	}


	private function cart_product_ids(): array {
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! WC() || ! WC()->cart ) {
			return [];
		}

		$ids = [];

		foreach ( WC()->cart->get_cart() as $item ) {
			$ids[] = absint( $item['product_id'] ?? 0 );
			$ids[] = absint( $item['variation_id'] ?? 0 );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}


	private function is_upsell_coupon_code( string $code ): bool {
		return str_starts_with( wc_format_coupon_code( $code ), self::UPSELL_COUPON_PREFIX );
	}


	private function upsell_coupon_code( int $trigger_id, int $offer_id ): string {
		$pair_key = $this->upsell_pair_key( $trigger_id, $offer_id );
		return '' === $pair_key ? '' : self::UPSELL_COUPON_PREFIX . str_replace( ':', '-', $pair_key );
	}


	private function cart_item_effective_product_id( array $item ): int {
		return absint( ! empty( $item['variation_id'] ) ? $item['variation_id'] : ( $item['product_id'] ?? 0 ) );
	}


	private function upsell_claim_configuration_matches( array $item ): bool {
		$trigger_id = absint( $item['dsa_upsell_trigger_id'] ?? 0 );
		$config = $this->upsell_discount_meta( $trigger_id );
		return $config['product_id'] === $this->cart_item_effective_product_id( $item ) && $config['value'] > 0
			&& (float) $config['value'] === (float) ( $item['dsa_upsell_discount'] ?? 0 )
			&& $config['type'] === ( $item['dsa_upsell_discount_type'] ?? '' )
			&& $config['scope'] === ( $item['dsa_upsell_discount_scope'] ?? '' );
	}


	private function upsell_claim_for_coupon( string $code ): array {
		if ( ! $this->is_upsell_coupon_code( $code ) || ! \DSA\Apps\Store_App_Service::enabled() || ! WC() || ! WC()->cart ) {
			return [];
		}

		$code = wc_format_coupon_code( $code );

		foreach ( WC()->cart->get_cart() as $offer_key => $offer_item ) {
			$value = max( 0.0, (float) ( $offer_item['dsa_upsell_discount'] ?? 0 ) );
			$trigger_id = absint( $offer_item['dsa_upsell_trigger_id'] ?? 0 );
			$offer_id = $this->cart_item_effective_product_id( $offer_item );

			if ( $value <= 0 || $code !== $this->upsell_coupon_code( $trigger_id, $offer_id ) || ! $this->upsell_claim_configuration_matches( $offer_item ) ) {
				continue;
			}

			$trigger = $this->cart_item_for_product( $trigger_id );

			if ( empty( $trigger ) ) {
				return [];
			}

			$trigger_item = $trigger['item'];
			$trigger_effective_id = $this->cart_item_effective_product_id( $trigger_item );
			$offer_quantity = max( 1, (int) ( $offer_item['quantity'] ?? 1 ) );
			$trigger_quantity = max( 1, (int) ( $trigger_item['quantity'] ?? 1 ) );
			$offer_unit = (float) ( $offer_item['dsa_upsell_unit_price'] ?? 0 );
			$trigger_unit = (float) ( $offer_item['dsa_upsell_trigger_unit_price'] ?? 0 );

			if ( $offer_unit <= 0 ) {
				$offer_unit = $this->cart_item_unit_price( $offer_item );
			}

			if ( $trigger_unit <= 0 ) {
				$trigger_unit = $this->cart_item_unit_price( $trigger_item );
			}

			$scope = $this->normalize_discount_scope( (string) ( $offer_item['dsa_upsell_discount_scope'] ?? 'single_lowest' ) );
			$affected = $this->discount_affected_product_ids( $scope, $trigger_effective_id, $offer_id, $trigger_unit, $offer_unit );

			return [
				'code'             => $code,
				'offer_key'        => (string) $offer_key,
				'trigger_key'      => (string) $trigger['key'],
				'trigger_id'       => $trigger_id,
				'offer_id'         => $offer_id,
				'value'            => $value,
				'type'             => 'fixed' === sanitize_key( (string) ( $offer_item['dsa_upsell_discount_type'] ?? 'percent' ) ) ? 'fixed' : 'percent',
				'scope'            => $scope,
				'pair_quantity'    => min( $offer_quantity, $trigger_quantity ),
				'affected_ids'     => $affected,
				'unit_prices'      => [
					$trigger_effective_id => max( 0.0, $trigger_unit ),
					$offer_id             => max( 0.0, $offer_unit ),
				],
			];
		}

		return [];
	}


	private function cart_item_for_product( int $product_id ): array {
		if ( ! $product_id || ! \DSA\Apps\Store_App_Service::enabled() || ! WC() || ! WC()->cart ) {
			return [];
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( $this->cart_item_matches_product( $item, $product_id ) ) {
				return [
					'key'  => (string) $key,
					'item' => $item,
				];
			}
		}

		return [];
	}


	private function cart_item_matches_product( array $item, int $product_id ): bool {
		if ( ! $product_id ) {
			return false;
		}

		return absint( $item['product_id'] ?? 0 ) === $product_id || absint( $item['variation_id'] ?? 0 ) === $product_id;
	}


	private function cart_item_discount_claimed( array $item, int $trigger_id ): bool {
		return (float) ( $item['dsa_upsell_discount'] ?? 0 ) > 0 && absint( $item['dsa_upsell_trigger_id'] ?? 0 ) === $trigger_id;
	}


	private function cart_pair_discount_claimed( int $trigger_id, int $offer_id ): bool {
		$pair_key = $this->upsell_pair_key( $trigger_id, $offer_id );

		if ( '' === $pair_key || ! \DSA\Apps\Store_App_Service::enabled() || ! WC() || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$value = (float) ( $item['dsa_upsell_discount'] ?? 0 );

			if ( $value <= 0 ) {
				continue;
			}

			$item_product_id = absint( ! empty( $item['variation_id'] ) ? $item['variation_id'] : ( $item['product_id'] ?? 0 ) );
			$item_trigger_id = absint( $item['dsa_upsell_trigger_id'] ?? 0 );

			if ( $pair_key === $this->upsell_pair_key( $item_trigger_id, $item_product_id ) ) {
				return true;
			}
		}

		return false;
	}


	private function cart_discount_claim_conflicts( int $trigger_id, int $offer_id, array $discount, float $trigger_unit, float $offer_unit ): bool {
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! WC() || ! WC()->cart ) {
			return false;
		}

		$candidate = $this->discount_affected_product_ids(
			(string) ( $discount['scope'] ?? 'single_lowest' ),
			$trigger_id,
			$offer_id,
			$trigger_unit,
			$offer_unit
		);

		if ( empty( $candidate ) ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$value = (float) ( $item['dsa_upsell_discount'] ?? 0 );

			if ( $value <= 0 ) {
				continue;
			}

			$item_product_id = absint( ! empty( $item['variation_id'] ) ? $item['variation_id'] : ( $item['product_id'] ?? 0 ) );
			$item_trigger_id = absint( $item['dsa_upsell_trigger_id'] ?? 0 );
			$item_scope = $this->normalize_discount_scope( (string) ( $item['dsa_upsell_discount_scope'] ?? 'single_lowest' ) );
			$item_offer_unit = (float) ( $item['dsa_upsell_unit_price'] ?? 0 );
			$item_trigger_unit = (float) ( $item['dsa_upsell_trigger_unit_price'] ?? 0 );

			if ( $item_offer_unit <= 0 ) {
				$item_offer_unit = $this->cart_item_unit_price( $item );
			}

			if ( $item_trigger_unit <= 0 && $item_trigger_id ) {
				$item_trigger_unit = $this->cart_unit_price_for_product( $item_trigger_id );
			}

			$claimed = $this->discount_affected_product_ids( $item_scope, $item_trigger_id, $item_product_id, $item_trigger_unit, $item_offer_unit );

			if ( array_intersect( $candidate, $claimed ) ) {
				return true;
			}
		}

		return false;
	}


	private function upsell_pair_key( int $a, int $b ): string {
		$a = absint( $a );
		$b = absint( $b );

		if ( ! $a || ! $b || $a === $b ) {
			return '';
		}

		$ids = [ $a, $b ];
		sort( $ids, SORT_NUMERIC );

		return implode( ':', $ids );
	}


	private function cart_item_unit_price( array $item ): float {
		$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		$line_total = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : ( isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0 );

		if ( $line_total > 0 ) {
			return $line_total / $quantity;
		}

		$product = $item['data'] ?? null;

		return $product && is_object( $product ) && method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0;
	}


	private function cart_unit_price_for_product( int $product_id ): float {
		$cart_item = $this->cart_item_for_product( $product_id );

		return empty( $cart_item ) ? 0.0 : $this->cart_item_unit_price( $cart_item['item'] );
	}


	public function public_pair_configuration( int $trigger_id ): array {
		$trigger = Product_Context_Service::public_product( $trigger_id );
		if ( ! $trigger ) return [];
		$discount = $this->upsell_discount_meta( $trigger_id );
		if ( ! $discount['product_id'] || $trigger_id === $discount['product_id'] ) return [];
		$companion = Product_Context_Service::public_product( $discount['product_id'] );
		if ( ! $companion ) return [];
		return [
			'id' => $trigger_id, 'trigger' => $trigger, 'companion' => $companion,
			'discount' => $discount,
			'label' => $discount['value'] > 0 ? $this->upsell_discount_label( $trigger_id ) : '',
			'scopeLabel' => $discount['value'] > 0 ? $this->discount_scope_label( $discount['scope'] ) : '',
			'available' => $trigger->is_purchasable() && $trigger->is_in_stock() && $companion->is_purchasable() && $companion->is_in_stock(),
		];
	}


	private function upsell_discount_meta( int $trigger_id ): array {
		$type = sanitize_key( get_post_meta( $trigger_id, '_sc_upsell_discount_type', true ) ?: 'percent' );
		$type = in_array( $type, [ 'percent', 'fixed' ], true ) ? $type : 'percent';
		$value = max( 0, (float) get_post_meta( $trigger_id, '_sc_upsell_discount', true ) );

		return [
			'product_id' => absint( get_post_meta( $trigger_id, '_sc_upsell_product_id', true ) ),
			'value'      => 'percent' === $type ? min( 100, $value ) : $value,
			'type'       => $type,
			'scope'      => $this->normalize_discount_scope( (string) get_post_meta( $trigger_id, '_sc_upsell_discount_scope', true ) ),
		];
	}


	private function normalize_discount_scope( string $scope ): string {
		return in_array( $scope, [ 'both', 'single_highest', 'single_lowest' ], true ) ? $scope : 'single_lowest';
	}


	private function discount_scope_base( string $scope, float $trigger_price, float $upsell_price ): float {
		if ( $trigger_price <= 0 ) {
			return max( 0, $upsell_price );
		}

		switch ( $this->normalize_discount_scope( $scope ) ) {
			case 'both':
				return max( 0, $trigger_price + $upsell_price );
			case 'single_highest':
				return max( $trigger_price, $upsell_price );
			case 'single_lowest':
			default:
				return min( $trigger_price, $upsell_price );
		}
	}


	private function discount_affected_product_ids( string $scope, int $trigger_id, int $upsell_id, float $trigger_price, float $upsell_price ): array {
		$trigger_id = absint( $trigger_id );
		$upsell_id = absint( $upsell_id );

		if ( ! $trigger_id || ! $upsell_id ) {
			return [];
		}

		switch ( $this->normalize_discount_scope( $scope ) ) {
			case 'both':
				return array_values( array_unique( [ $trigger_id, $upsell_id ] ) );
			case 'single_highest':
				return [ $trigger_price >= $upsell_price ? $trigger_id : $upsell_id ];
			case 'single_lowest':
			default:
				return [ $trigger_price <= $upsell_price && $trigger_price > 0 ? $trigger_id : $upsell_id ];
		}
	}


	private function discount_scope_label( string $scope ): string {
		switch ( $this->normalize_discount_scope( $scope ) ) {
			case 'both':
				return __( 'on both items', 'dsa' );
			case 'single_highest':
				return __( 'on the higher-priced item', 'dsa' );
			case 'single_lowest':
			default:
				return __( 'on the lower-priced item', 'dsa' );
		}
	}


	private function upsell_discount_label( int $trigger_id ): string {
		$discount = $this->upsell_discount_meta( $trigger_id );
		$value = $discount['value'];

		if ( $value <= 0 ) {
			return __( 'Cart pick', 'dsa' );
		}

		$type = $discount['type'];

		if ( 'fixed' === $type ) {
			return sprintf( __( '%s off', 'dsa' ), $this->price_text( $value ) );
		}

		return sprintf( __( '%s%% off', 'dsa' ), rtrim( rtrim( number_format( $value, 2 ), '0' ), '.' ) );
	}


	private function product_title( int $product_id ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( $product && is_object( $product ) && method_exists( $product, 'get_name' ) ) {
			return wp_strip_all_tags( $product->get_name() );
		}

		return $product_id ? sprintf( __( 'Product #%d', 'dsa' ), $product_id ) : __( 'Unknown product', 'dsa' );
	}


	private function commerce_config(): array {
		$config = $this->settings->get( 'commerce', [] );

		return is_array( $config ) ? $config : [];
	}

}
