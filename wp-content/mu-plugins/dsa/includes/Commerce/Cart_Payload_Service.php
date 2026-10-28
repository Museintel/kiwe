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
		private Linked_Products_Service|\Closure $linked_products,
		private Store_Analytics_Service|\Closure $store_analytics
	) {}

	public function load_cart(): bool {
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! WC() ) {
			return false;
		}

		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		return (bool) WC()->cart;
	}

	/**
	 * Reuse a completed native Woo calculation from this exact mutation.
	 * A removed item or an integration without the native calculation callback
	 * still gets a fresh calculation. This never trusts a browser-provided total.
	 */
	public function refresh_after_mutation( object $cart, int $calculations_before ): void {
		$this->totals_refreshed = function_exists( 'WC' ) && WC() && WC()->cart === $cart
			&& did_action( 'woocommerce_after_calculate_totals' ) > $calculations_before;
		$this->refresh_totals();
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
			'productTotal'    => $this->product_total(),
			'productSubtotal' => $this->price_text( (float) WC()->cart->get_subtotal() + ( WC()->cart->display_prices_including_tax() ? (float) WC()->cart->get_subtotal_tax() : 0 ) ),
			'totalsSummary'   => $this->totals_summary(),
			'discountSummary' => $this->discount_summary(),
			'productDiscountSummary' => $this->discount_summary( true ),
			'cartUrl'         => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'checkoutUrl'     => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'items'           => $this->cart_items(),
			'recommendations' => $this->cart_recommendations(),
			'upsells'         => $this->cart_upsells(),
		];
	}

	/**
	 * Private startup state for identity, badges, native quantity controls and offers.
	 * Read Woo's loaded totals; opening/mutating the cart owns fresh payable totals.
	 * Woo may itself initialize its session while loading; this adapter never forces
	 * a totals/shipping recalculation or builds the optional recommendations rail.
	 */
	public function runtime_data(): array {
		if ( ! $this->load_cart() ) {
			return [ 'available' => false, 'count' => 0, 'total' => '', 'cartUrl' => '', 'checkoutUrl' => '', 'items' => [], 'upsells' => [], 'runtimeOnly' => true ];
		}
		$cart = WC()->cart;
		// Native get_cart() may lazily restore the session and its saved totals.
		$items = $this->cart_items();
		$count = (int) $cart->get_cart_contents_count();
		$totals = method_exists( $cart, 'get_totals' ) ? (array) $cart->get_totals() : [];
		$inclusive = $cart->display_prices_including_tax();
		return [
			'available' => true,
			'runtimeOnly' => true,
			'count' => $count,
			'total' => array_key_exists( 'total', $totals ) ? $this->price_text( (float) $totals['total'] ) : '',
			'productTotal' => array_key_exists( 'cart_contents_total', $totals ) ? $this->price_text( (float) $totals['cart_contents_total'] + ( $inclusive ? (float) ( $totals['cart_contents_tax'] ?? 0 ) : 0 ) ) : '',
			'productSubtotal' => array_key_exists( 'subtotal', $totals ) ? $this->price_text( (float) $totals['subtotal'] + ( $inclusive ? (float) ( $totals['subtotal_tax'] ?? 0 ) : 0 ) ) : '',
			'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'items' => $items,
			'upsells' => $this->cart_upsells(),
		];
	}

	/** Product charges after coupons, in Woo's cart tax basis, excluding shipping and fees. */
	public function product_total(): string {
		if ( ! $this->load_cart() ) return '';
		$this->refresh_totals();
		$cart = WC()->cart;
		return $this->price_text( (float) $cart->get_cart_contents_total() + ( $cart->display_prices_including_tax() ? (float) $cart->get_cart_contents_tax() : 0 ) );
	}

	/** Display Woo's components; never recalculate a payable total. */
	public function totals_summary(): array {
		if ( ! $this->load_cart() ) {
			return [];
		}
		$this->refresh_totals();
		$cart      = WC()->cart;
		$inclusive = $cart->display_prices_including_tax();
		$lines     = [ [
			'type'   => 'subtotal',
			'label'  => __( 'Products subtotal', 'dsa' ),
			'amount' => $this->price_text( (float) $cart->get_subtotal() + ( $inclusive ? (float) $cart->get_subtotal_tax() : 0 ) ),
		] ];
		$tax_discounts = (array) $cart->get_coupon_discount_tax_totals();
		foreach ( (array) $cart->get_coupon_discount_totals() as $code => $amount ) {
			$amount = (float) $amount + ( $inclusive ? (float) ( $tax_discounts[ $code ] ?? 0 ) : 0 );
			if ( $amount <= 0 ) {
				continue;
			}
			$bonus   = str_starts_with( wc_format_coupon_code( (string) $code ), 'kiwe-pair-' );
			$lines[] = [
				'type'   => 'discount',
				'label'  => $bonus ? __( 'Kiwe cart bonus', 'dsa' ) : sprintf( __( 'Coupon: %s', 'dsa' ), wc_format_coupon_code( (string) $code ) ),
				'amount' => '-' . $this->price_text( $amount ),
			];
		}
		foreach ( (array) $cart->get_fees() as $fee ) {
			$amount = (float) $fee->total + ( $inclusive ? (float) ( $fee->tax ?? 0 ) : 0 );
			$lines[] = [
				'type'   => $amount < 0 ? 'discount' : 'fee',
				'label'  => sanitize_text_field( (string) $fee->name ),
				'amount' => $this->price_text( $amount ),
			];
		}
		$note = '';
		if ( $cart->needs_shipping() ) {
			$shipping = WC()->shipping();
			$packages = $shipping ? (array) $shipping->get_packages() : [];
			$chosen   = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', [] ) : [];
			$labels   = [];
			foreach ( $packages as $index => $package ) {
				$rate = $package['rates'][ $chosen[ $index ] ?? '' ] ?? null;
				if ( $rate && is_callable( [ $rate, 'get_label' ] ) ) {
					$labels[] = sanitize_text_field( $rate->get_label() );
				}
			}
			$calculated = $cart->show_shipping() && count( $packages ) > 0 && count( $labels ) === count( $packages );
			$lines[] = [
				'type'   => 'shipping',
				'label'  => $calculated ? __( 'Delivery', 'dsa' ) . ' · ' . implode( ', ', array_unique( $labels ) ) : __( 'Delivery', 'dsa' ),
				'amount' => $calculated ? $this->price_text( (float) $cart->get_shipping_total() + ( $inclusive ? (float) $cart->get_shipping_tax() : 0 ) ) : __( 'Calculated at checkout', 'dsa' ),
			];
			$note = $calculated ? __( 'Delivery is based on your current address and shipping method. It may change at checkout.', 'dsa' ) : __( 'Delivery will be calculated at checkout.', 'dsa' );
		}
		$tax = (float) $cart->get_total_tax();
		if ( $tax > 0 ) {
			if ( $inclusive ) {
				$note = trim( $note . ' ' . sprintf( __( 'Includes %s tax.', 'dsa' ), $this->price_text( $tax ) ) );
			} else {
				$lines[] = [ 'type' => 'tax', 'label' => __( 'Tax', 'dsa' ), 'amount' => $this->price_text( $tax ) ];
			}
		}
		return [ 'lines' => $lines, 'total' => $this->money_text( $cart->get_total() ), 'note' => $note ];
	}

	public function discount_summary( bool $products_only = false ): array {
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
			$include_tax = ! $products_only || $cart->display_prices_including_tax();
			$amount = max( 0, (float) $amount + ( $include_tax ? (float) ( $coupon_tax_totals[ $code ] ?? 0 ) : 0 ) );
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

		foreach ( $products_only ? [] : (array) $cart->get_fees() as $fee ) {
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
		if ( $products_only && $cart->display_prices_including_tax() ) $subtotal += (float) $cart->get_subtotal_tax();
		$current_total = (float) $cart->get_total( 'edit' );

		return [
			'hasDiscount'   => $total_discount > 0,
			'lines'         => $lines,
			'beforeDiscount'=> $this->price_text( max( 0, $products_only ? $subtotal : $current_total + $total_discount ) ),
			'subtotal'      => $this->price_text( max( 0, $subtotal ) ),
			'total'         => $products_only ? $this->product_total() : $this->money_text( $cart->get_total() ),
			'totalDiscount' => $total_discount > 0 ? '-' . $this->price_text( $total_discount ) : '',
		];
	}

	private function cart_recommendations(): array {
		$commerce = $this->settings->get( 'commerce', [] );
		$limit    = is_array( $commerce ) ? (int) ( $commerce['fbt_max_products'] ?? 6 ) : 6;

		if ( $this->linked_products instanceof \Closure ) $this->linked_products = ( $this->linked_products )();
		return $this->linked_products->cart_recommendations( $limit );
	}

	private function cart_upsells(): array {
		if ( $this->store_analytics instanceof \Closure ) $this->store_analytics = ( $this->store_analytics )();
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
		$stock_quantities = WC()->cart->get_cart_item_quantities();

		foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product || ! is_object( $product ) ) {
				continue;
			}

			$image_id       = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
			$quantity       = (int) ( $item['quantity'] ?? 0 );
			$stock_quantity = method_exists( $product, 'managing_stock' ) && $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
			$native_max     = $product->get_max_purchase_quantity();
			$max_quantity   = $native_max < 0 ? 99 : (int) floor( $native_max );
			if ( null !== $stock_quantity && ! $product->backorders_allowed() ) {
				$other_quantity = max( 0, ( $stock_quantities[ $product->get_stock_managed_by_id() ] ?? 0 ) - $quantity );
				$max_quantity = min( $max_quantity, (int) max( 0, floor( $product->get_stock_quantity() - $other_quantity ) ) );
			}
			$max_quantity = min( 99, max( $quantity, $max_quantity ) );

			$items[] = [
				'key'           => sanitize_text_field( (string) $cart_item_key ),
				'productId'     => (int) ( $item['product_id'] ?? 0 ),
				'variationId'   => (int) ( $item['variation_id'] ?? 0 ),
				'title'         => wp_strip_all_tags( html_entity_decode( $product->get_name(),ENT_QUOTES | ENT_HTML5,'UTF-8' ) ),
				'weight'        => $this->product_weight( $product ),
				'quantity'      => $quantity,
				'maxQuantity'   => $max_quantity,
				'stockQuantity' => $stock_quantity,
				'stockBadge'    => $this->stock_badge( $product ),
				'price'         => $this->money_text( WC()->cart->get_product_price( $product ) ),
				'subtotal'      => $this->money_text( WC()->cart->get_product_subtotal( $product, $quantity ) ),
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
