<?php

namespace DSA\Commerce;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cart_Payload_Service {
	private bool $totals_refreshed = false;

	public function __construct(
		private Settings $settings,
		private Linked_Products_Service $linked_products,
		private Store_Analytics_Service $store_analytics
	) {}

	public function load_cart(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return false;
		}

		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		return (bool) WC()->cart;
	}

	public function cart_data(): array {
		if ( ! $this->load_cart() ) {
			return [
				'available'   => false,
				'count'       => 0,
				'total'       => '',
				'cartUrl'     => '',
				'checkoutUrl' => '',
				'items'       => [],
			];
		}
		$this->refresh_totals();

		return [
			'available'       => true,
			'count'           => (int) WC()->cart->get_cart_contents_count(),
			'total'           => $this->money_text( WC()->cart->get_total() ),
			'discountSummary' => $this->discount_summary(),
			'cartUrl'         => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'checkoutUrl'     => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'items'           => $this->cart_items(),
			'recommendations' => $this->cart_recommendations(),
			'upsells'         => $this->cart_upsells(),
		];
	}

	public function discount_summary(): array {
		if ( ! $this->load_cart() ) {
			return [ 'hasDiscount' => false, 'lines' => [], 'beforeDiscount' => '', 'subtotal' => '', 'total' => '', 'totalDiscount' => '' ];
		}
		$this->refresh_totals();

		$cart = WC()->cart;
		$lines = [];
		$total_discount = 0.0;
		$coupon_totals = method_exists( $cart, 'get_coupon_discount_totals' ) ? (array) $cart->get_coupon_discount_totals() : [];
		$coupon_tax_totals = method_exists( $cart, 'get_coupon_discount_tax_totals' ) ? (array) $cart->get_coupon_discount_tax_totals() : [];

		foreach ( $coupon_totals as $code => $amount ) {
			$amount = max( 0, (float) $amount + (float) ( $coupon_tax_totals[ $code ] ?? 0 ) );
			if ( $amount <= 0 ) {
				continue;
			}
			$is_kiwe_bonus = str_starts_with( wc_format_coupon_code( (string) $code ), 'kiwe-pair-' );
			$total_discount += $amount;
			$lines[] = [
				'type'   => $is_kiwe_bonus ? 'kiwe_bonus' : 'coupon',
				'label'  => $is_kiwe_bonus ? __( 'Kiwe cart bonus', 'dsa' ) : sprintf( __( 'Coupon: %s', 'dsa' ), wc_format_coupon_code( (string) $code ) ),
				'amount' => '-' . $this->price_text( $amount ),
			];
		}

		foreach ( (array) $cart->get_fees() as $fee ) {
			$amount = isset( $fee->total ) ? (float) $fee->total : 0.0;
			if ( $amount >= 0 ) {
				continue;
			}
			$total_discount += abs( $amount );
			$lines[] = [
				'type'   => false !== stripos( (string) ( $fee->name ?? '' ), 'kiwe' ) ? 'kiwe_bonus' : 'discount',
				'label'  => sanitize_text_field( (string) ( $fee->name ?? __( 'Cart discount', 'dsa' ) ) ),
				'amount' => '-' . $this->price_text( abs( $amount ) ),
			];
		}

		$subtotal      = method_exists( $cart, 'get_subtotal' ) ? (float) $cart->get_subtotal() : (float) $cart->get_cart_contents_total();
		$current_total = (float) $cart->get_total( 'edit' );

		return [
			'hasDiscount'   => $total_discount > 0,
			'lines'         => $lines,
			'beforeDiscount'=> $this->price_text( max( 0, $current_total + $total_discount ) ),
			'subtotal'      => $this->price_text( max( 0, $subtotal ) ),
			'total'         => $this->money_text( $cart->get_total() ),
			'totalDiscount' => $total_discount > 0 ? '-' . $this->price_text( $total_discount ) : '',
		];
	}

	private function cart_recommendations(): array {
		$commerce = $this->settings->get( 'commerce', [] );
		$limit    = is_array( $commerce ) ? (int) ( $commerce['fbt_max_products'] ?? 6 ) : 6;

		return $this->linked_products->cart_recommendations( $limit );
	}

	private function cart_upsells(): array {
		return $this->store_analytics->cart_upsell_offers( 3 );
	}

	private function refresh_totals(): void {
		if ( $this->totals_refreshed || ! function_exists( 'WC' ) || ! WC() || ! WC()->cart || ! method_exists( WC()->cart, 'calculate_totals' ) ) {
			return;
		}

		$this->totals_refreshed = true;
		WC()->cart->calculate_totals();
	}

	private function cart_items(): array {
		$items = [];

		foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product || ! is_object( $product ) ) {
				continue;
			}

			$image_id       = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
			$quantity       = (int) ( $item['quantity'] ?? 0 );
			$stock_quantity = method_exists( $product, 'managing_stock' ) && $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
			$max_quantity   = method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually()
				? 1
				: ( null === $stock_quantity ? 99 : max( $quantity, $stock_quantity ) );

			$items[] = [
				'key'           => sanitize_text_field( (string) $cart_item_key ),
				'productId'     => (int) ( $item['product_id'] ?? 0 ),
				'variationId'   => (int) ( $item['variation_id'] ?? 0 ),
				'title'         => wp_strip_all_tags( $product->get_name() ),
				'weight'        => $this->product_weight( $product ),
				'quantity'      => $quantity,
				'maxQuantity'   => $max_quantity,
				'stockQuantity' => $stock_quantity,
				'stockBadge'    => $this->stock_badge( $product ),
				'price'         => function_exists( 'wc_price' ) ? $this->money_text( wc_price( (float) $product->get_price() ) ) : (string) $product->get_price(),
				'subtotal'      => isset( $item['line_total'] ) && function_exists( 'wc_price' ) ? $this->money_text( wc_price( (float) $item['line_total'] ) ) : '',
				'image'         => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src() : '' ),
				'permalink'     => method_exists( $product, 'get_permalink' ) ? $product->get_permalink() : '',
			];
		}

		return $items;
	}

	private function product_weight( $product ): string {
		$weight = is_object( $product ) && method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '';

		return '' !== $weight && function_exists( 'wc_format_weight' ) ? $this->money_text( wc_format_weight( $weight ) ) : '';
	}

	public function stock_badge( $product ): array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'managing_stock' ) || ! $product->managing_stock() ) {
			return [];
		}

		$config = $this->stock_badge_config();

		if ( empty( $config['enabled'] ) ) {
			return [];
		}

		$stock            = (int) $product->get_stock_quantity();
		$alert_threshold  = max( 1, min( 999, (int) $config['alert_threshold'] ) );
		$urgent_threshold = max( 1, min( $alert_threshold, (int) $config['urgent_threshold'] ) );

		if ( $stock <= 0 || $stock > $alert_threshold ) {
			return [];
		}

		$urgent = $stock <= $urgent_threshold;
		$text   = $urgent ? (string) $config['urgent_text'] : (string) $config['alert_text'];
		$label  = false !== strpos( $text, '%d' ) ? sprintf( $text, $stock ) : $text;

		return [
			'type'  => $urgent ? 'urgent' : 'alert',
			'label' => $label,
		];
	}

	private function stock_badge_config(): array {
		$settings = $this->settings->all();
		$commerce = isset( $settings['commerce'] ) && is_array( $settings['commerce'] ) ? $settings['commerce'] : [];
		$bricks   = isset( $settings['bricks'] ) && is_array( $settings['bricks'] ) ? $settings['bricks'] : [];

		return [
			'enabled'          => $commerce['cart_badges_enabled'] ?? true,
			'alert_threshold'  => $commerce['stock_badge_alert_threshold'] ?? $bricks['stock_badge_alert_threshold'] ?? 10,
			'urgent_threshold' => $commerce['stock_badge_urgent_threshold'] ?? $bricks['stock_badge_urgent_threshold'] ?? 3,
			'alert_text'       => $commerce['stock_badge_alert_text'] ?? $bricks['stock_badge_alert_text'] ?? __( 'Only %d left', 'dsa' ),
			'urgent_text'      => $commerce['stock_badge_urgent_text'] ?? $bricks['stock_badge_urgent_text'] ?? __( 'Almost gone: %d left', 'dsa' ),
		];
	}

	public function money_text( string $html ): string {
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, $charset );
	}

	private function price_text( float $amount ): string {
		return function_exists( 'wc_price' ) ? $this->money_text( wc_price( $amount ) ) : number_format_i18n( $amount, 2 );
	}
}
