<?php

namespace DSA\Rest;

use DSA\Commerce\Cart_Payload_Service;
use DSA\Commerce\Store_Analytics_Service;
use DSA\Utilities\Atomic_Rate_Limiter;
use DSA\Utilities\Origin_Checker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cart_Controller {
	public function __construct(
		private Cart_Payload_Service $cart_payload,
		private Store_Analytics_Service $store_analytics
	) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/cart',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_cart' ],
				'permission_callback' => [ $this, 'can_read_cart' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/cart/nonce',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_nonce' ],
				'permission_callback' => [ $this, 'can_read_cart' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/cart/item',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => [ $this, 'can_mutate_cart' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/cart/add',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_item' ],
				'permission_callback' => [ $this, 'can_mutate_cart' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/cart/upsell/claim',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'claim_upsell' ],
				'permission_callback' => [ $this, 'can_mutate_cart' ],
			]
		);
	}

	public function can_read_cart(): bool {
		return Origin_Checker::is_same_site_request();
	}

	public function can_mutate_cart( WP_REST_Request $request ) {
		return Origin_Checker::mutation_allowed( $request );
	}

	public function get_cart( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->load_cart() ) {
			return new WP_REST_Response(
				[
					'ok'   => false,
					'cart' => $this->cart_data(),
				],
				200
			);
		}

		return new WP_REST_Response(
			[
				'ok'   => true,
				'cart' => $this->cart_data(),
			],
			200
		);
	}

	public function get_nonce( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'ok'    => true,
				'nonce' => wp_create_nonce( 'wp_rest' ),
			],
			200
		);
	}

	public function add_item( WP_REST_Request $request ) {
		if ( ! $this->rate_limit( 'add' ) ) {
			return new WP_Error( 'dsa_cart_rate_limited', __( 'Please wait a moment before changing the cart again.', 'dsa' ), [ 'status' => 429 ] );
		}

		if ( ! $this->load_cart() ) {
			return new WP_Error( 'dsa_cart_unavailable', __( 'Cart is not available.', 'dsa' ), [ 'status' => 503 ] );
		}

		$product_id = $this->int_param( $request, [ 'productId', 'product_id', 'add-to-cart' ] );
		$variation_id = $this->int_param( $request, [ 'variationId', 'variation_id', 'variation' ] );
		$quantity = max( 1, min( 99, $this->int_param( $request, [ 'quantity', 'qty' ], 1 ) ) );
		$before_count = (int) WC()->cart->get_cart_contents_count();
		$debug_trigger_id = $this->int_param( $request, [ 'triggerId', 'trigger_id' ] );

		$this->debug_log(
			'cart/add start',
			[
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $quantity,
				'trigger_id'   => $debug_trigger_id,
				'before_count' => $before_count,
				'has_session'  => (bool) WC()->session,
			]
		);

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			$this->debug_log( 'cart/add rejected', [ 'reason' => 'missing_product_or_wc', 'product_id' => $product_id ] );
			return new WP_Error( 'dsa_cart_invalid_product', __( 'Product is not available.', 'dsa' ), [ 'status' => 400 ] );
		}

		$product = wc_get_product( $variation_id ?: $product_id );

		if ( ! $product || ! is_object( $product ) ) {
			$this->debug_log( 'cart/add rejected', [ 'reason' => 'product_not_found', 'product_id' => $product_id, 'variation_id' => $variation_id ] );
			return new WP_Error( 'dsa_cart_invalid_product', __( 'Product is not available.', 'dsa' ), [ 'status' => 404 ] );
		}

		$variation_attributes = [];

		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
			$variation_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : $product_id;
			$variation_attributes = method_exists( $product, 'get_variation_attributes' ) ? (array) $product->get_variation_attributes() : [];
			$product_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;

			if ( ! $product_id ) {
				return new WP_Error( 'dsa_cart_invalid_product', __( 'Product is not available.', 'dsa' ), [ 'status' => 404 ] );
			}
		} elseif ( $variation_id ) {
			return new WP_Error( 'dsa_cart_invalid_product', __( 'Product variation is not available.', 'dsa' ), [ 'status' => 404 ] );
		}

		if ( method_exists( $product, 'is_purchasable' ) && ! $product->is_purchasable() ) {
			return new WP_Error( 'dsa_cart_not_purchasable', __( 'Product is not purchasable.', 'dsa' ), [ 'status' => 409 ] );
		}

		if ( method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) {
			return new WP_Error( 'dsa_cart_out_of_stock', __( 'Product is out of stock.', 'dsa' ), [ 'status' => 409 ] );
		}

		if ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() ) {
			$stock = max( 0, (int) $product->get_stock_quantity() );

			if ( $quantity > $stock ) {
				return new WP_Error(
					'dsa_cart_stock_limit',
					sprintf(
						/* translators: %d: stock quantity. */
						__( 'Only %d in stock.', 'dsa' ),
						$stock
					),
					[
						'status'  => 409,
						'max_qty' => $stock,
					]
				);
			}
		}

		$trigger_id = $this->int_param( $request, [ 'triggerId', 'trigger_id' ] );
		$requested_source = sanitize_key( (string) $request->get_param( 'source' ) );
		$source = in_array( $requested_source, [ 'dsa_search', 'dsa_cart', 'dsa_cart_upsell' ], true ) ? $requested_source : ( $trigger_id ? 'dsa_cart_upsell' : 'dsa_cart' );
		$store = $this->store_analytics;
		$offer_product_id = $variation_id ?: $product_id;
		$cart_item_data = $trigger_id ? $store->cart_item_data_for_upsell( $offer_product_id, $trigger_id ) : [];
		$cart_item_data['dsa_source_context'] = $cart_item_data['dsa_source_context'] ?? ( 'dsa_search' === $source ? 'search_result' : ( $trigger_id ? 'cart_upsell' : 'dsa_cart' ) );

		if ( WC()->session && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}

		Store_Analytics_Service::mark_cart_source( $source );
		try {
			$cart_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attributes, $cart_item_data );
		} finally {
			Store_Analytics_Service::reset_cart_source();
		}

		if ( ! $cart_key ) {
			$this->debug_log( 'cart/add failed', [ 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => $quantity ] );
			return new WP_Error( 'dsa_cart_add_failed', $this->cart_error_message( __( 'Could not add product to cart.', 'dsa' ) ), [ 'status' => 409 ] );
		}

		WC()->cart->calculate_totals();
		WC()->cart->set_session();

		if ( method_exists( WC()->cart, 'maybe_set_cart_cookies' ) ) {
			WC()->cart->maybe_set_cart_cookies();
		}

		$item = WC()->cart->get_cart_item( $cart_key );
		$claim_result = null;

		if ( $trigger_id && ! empty( $cart_item_data['dsa_upsell_pending'] ) ) {
			$claim_result = $store->claim_cart_upsell_discount( $offer_product_id, $trigger_id );

			if ( is_wp_error( $claim_result ) ) {
				$this->debug_log(
					'cart/add auto-claim skipped',
					[
						'product_id' => $offer_product_id,
						'trigger_id' => $trigger_id,
						'code'       => $claim_result->get_error_code(),
						'message'    => $claim_result->get_error_message(),
					]
				);
			} else {
				$this->debug_log( 'cart/add auto-claim success', [ 'product_id' => $offer_product_id, 'trigger_id' => $trigger_id ] );
			}
		}

		$cart = $this->cart_data();
		$fragments = $this->cart_fragments();
		$cart_hash = WC()->cart->get_cart_hash();

		$this->debug_log(
			'cart/add success',
			[
				'cart_key'      => sanitize_text_field( (string) $cart_key ),
				'item_quantity' => (int) ( $item['quantity'] ?? $quantity ),
				'before_count'  => $before_count,
				'after_count'   => (int) ( $cart['count'] ?? 0 ),
				'cart_hash'     => $cart_hash,
				'fragment_keys' => array_keys( $fragments ),
			]
		);

		return new WP_REST_Response(
			[
				'ok'   => true,
				'item' => [
					'key'      => sanitize_text_field( (string) $cart_key ),
					'quantity' => (int) ( $item['quantity'] ?? $quantity ),
				],
				'claim' => is_wp_error( $claim_result ) ? [
					'ok'      => false,
					'code'    => $claim_result->get_error_code(),
					'message' => $claim_result->get_error_message(),
				] : [
					'ok' => (bool) $claim_result,
				],
				'cart' => $cart,
				'fragments' => $fragments,
				'cart_hash' => $cart_hash,
			],
			200
		);
	}

	public function update_item( WP_REST_Request $request ) {
		if ( ! $this->rate_limit( 'item' ) ) {
			return new WP_Error( 'dsa_cart_rate_limited', __( 'Please wait a moment before changing the cart again.', 'dsa' ), [ 'status' => 429 ] );
		}

		if ( ! $this->load_cart() ) {
			return new WP_Error( 'dsa_cart_unavailable', __( 'Cart is not available.', 'dsa' ), [ 'status' => 503 ] );
		}

		$key      = sanitize_text_field( (string) $request->get_param( 'key' ) );
		$quantity = max( 0, min( 99, absint( $request->get_param( 'quantity' ) ) ) );
		$product_id = absint( $request->get_param( 'productId' ) ?: $request->get_param( 'product_id' ) );
		$variation_id = absint( $request->get_param( 'variationId' ) ?: $request->get_param( 'variation_id' ) );

		$item = '' !== $key ? WC()->cart->get_cart_item( $key ) : null;
		$key  = $item ? $key : $this->find_cart_item_key( $product_id, $variation_id );
		$item = $item ?: ( $key ? WC()->cart->get_cart_item( $key ) : null );
		$this->debug_log(
			'cart/item start',
			[
				'key'          => $key,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $quantity,
				'found'        => (bool) $item,
			]
		);

		if ( ! $item ) {
			$this->debug_log( 'cart/item rejected', [ 'reason' => 'item_missing', 'key' => $key, 'product_id' => $product_id, 'variation_id' => $variation_id ] );
			return new WP_Error( 'dsa_cart_item_missing', __( 'Cart item was not found.', 'dsa' ), [ 'status' => 404 ] );
		}

		$event_item = $item;
		$event_type = 0 === $quantity ? 'cart_remove' : 'cart_update';

		if ( 0 === $quantity ) {
			WC()->cart->remove_cart_item( $key );
		} else {
			$product = $item['data'] ?? null;

			if ( is_object( $product ) ) {
				if ( method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually() ) {
					$quantity = min( 1, $quantity );
				}

				if ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() ) {
					$stock = max( 0, (int) $product->get_stock_quantity() );

					if ( $quantity > $stock ) {
						return new WP_Error(
							'dsa_cart_stock_limit',
							sprintf(
								/* translators: %d: stock quantity. */
								__( 'Only %d in stock.', 'dsa' ),
								$stock
							),
							[
								'status'  => 409,
								'max_qty' => $stock,
							]
						);
					}
				}
			}

			WC()->cart->set_quantity( $key, $quantity, true );
		}

		$store = $this->store_analytics;
		$store->record_cart_event(
			[
				'event_type'   => $event_type,
				'source'       => 'dsa_cart',
				'product_id'   => absint( $event_item['product_id'] ?? 0 ),
				'variation_id' => absint( $event_item['variation_id'] ?? 0 ),
				'quantity'     => $quantity,
				'cart_key'     => $key,
				'context'      => $event_type,
			]
		);

		WC()->cart->calculate_totals();
		WC()->cart->set_session();
		$cart = $this->cart_data();
		$fragments = $this->cart_fragments();
		$cart_hash = WC()->cart->get_cart_hash();

		$this->debug_log(
			'cart/item success',
			[
				'key'           => $key,
				'quantity'      => $quantity,
				'after_count'   => (int) ( $cart['count'] ?? 0 ),
				'cart_hash'     => $cart_hash,
				'fragment_keys' => array_keys( $fragments ),
			]
		);

		return new WP_REST_Response(
			[
				'ok'   => true,
				'cart' => $cart,
				'fragments' => $fragments,
				'cart_hash' => $cart_hash,
			],
			200
		);
	}

	public function claim_upsell( WP_REST_Request $request ) {
		if ( ! $this->rate_limit( 'claim' ) ) {
			return new WP_Error( 'dsa_cart_rate_limited', __( 'Please wait a moment before changing the cart again.', 'dsa' ), [ 'status' => 429 ] );
		}

		if ( ! $this->load_cart() ) {
			return new WP_Error( 'dsa_cart_unavailable', __( 'Cart is not available.', 'dsa' ), [ 'status' => 503 ] );
		}

		$product_id = absint( $request->get_param( 'productId' ) ?: $request->get_param( 'product_id' ) );
		$trigger_id = absint( $request->get_param( 'triggerId' ) ?: $request->get_param( 'trigger_id' ) );
		$this->debug_log( 'cart/upsell/claim start', [ 'product_id' => $product_id, 'trigger_id' => $trigger_id, 'before_count' => (int) WC()->cart->get_cart_contents_count() ] );
		$store = $this->store_analytics;
		$result = $store->claim_cart_upsell_discount( $product_id, $trigger_id );

		if ( is_wp_error( $result ) ) {
			$this->debug_log( 'cart/upsell/claim failed', [ 'product_id' => $product_id, 'trigger_id' => $trigger_id, 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] );
			return $result;
		}

		if ( method_exists( WC()->cart, 'maybe_set_cart_cookies' ) ) {
			WC()->cart->maybe_set_cart_cookies();
		}
		$cart = $this->cart_data();
		$fragments = $this->cart_fragments();
		$cart_hash = WC()->cart->get_cart_hash();

		$this->debug_log( 'cart/upsell/claim success', [ 'product_id' => $product_id, 'trigger_id' => $trigger_id, 'after_count' => (int) ( $cart['count'] ?? 0 ), 'cart_hash' => $cart_hash, 'fragment_keys' => array_keys( $fragments ) ] );

		return new WP_REST_Response(
			[
				'ok'   => true,
				'cart' => $cart,
				'fragments' => $fragments,
				'cart_hash' => $cart_hash,
			],
			200
		);
	}

	private function load_cart(): bool {
		return $this->cart_payload->load_cart();
	}

	private function rate_limit( string $action ): bool {
		$action = sanitize_key( $action ) ?: 'cart';
		$limit  = 'item' === $action ? 120 : 90;
		$bucket = 'cart|' . $action . '|' . $this->rate_limit_identity();

		return Atomic_Rate_Limiter::allow( $bucket, $limit, MINUTE_IN_SECONDS );
	}

	private function int_param( WP_REST_Request $request, array $names, int $default = 0 ): int {
		foreach ( $names as $name ) {
			$value = $request->get_param( $name );

			if ( null !== $value && '' !== $value ) {
				return absint( $value );
			}
		}

		$json = $request->get_json_params();

		if ( is_array( $json ) ) {
			foreach ( $names as $name ) {
				if ( isset( $json[ $name ] ) && '' !== $json[ $name ] ) {
					return absint( $json[ $name ] );
				}
			}
		}

		return absint( $default );
	}

	private function rate_limit_identity(): string {
		$user_id = get_current_user_id();

		if ( $user_id ) {
			return 'user:' . $user_id;
		}

		if ( function_exists( 'WC' ) && WC() && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			$customer_id = (string) WC()->session->get_customer_id();

			if ( '' !== $customer_id ) {
				return 'wc:' . $customer_id;
			}
		}

		if ( function_exists( 'pk_ip' ) ) {
			$ip = (string) \pk_ip();
		} elseif ( function_exists( 'stp_get_ip' ) ) {
			$ip = (string) \stp_get_ip();
		} else {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		}

		return 'ip:' . preg_replace( '/[^a-fA-F0-9:\.]/', '', $ip );
	}

	private function cart_error_message( string $fallback ): string {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return $fallback;
		}

		$notices = wc_get_notices( 'error' );

		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return $fallback;
		}

		$first = reset( $notices );
		$message = is_array( $first ) ? (string) ( $first['notice'] ?? '' ) : (string) $first;
		$message = trim( wp_strip_all_tags( $message ) );

		return '' !== $message ? $message : $fallback;
	}

	private function cart_data(): array {
		return $this->cart_payload->cart_data();
	}

	private function cart_fragments(): array {
		if ( ! function_exists( 'woocommerce_mini_cart' ) || ! $this->load_cart() ) {
			return [];
		}

		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();

		return apply_filters(
			'woocommerce_add_to_cart_fragments',
			[
				'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
			]
		);
	}

	private function find_cart_item_key( int $product_id, int $variation_id = 0 ): string {
		if ( ! $product_id || ! WC()->cart ) {
			return '';
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
			$item_product_id = absint( $item['product_id'] ?? 0 );
			$item_variation_id = absint( $item['variation_id'] ?? 0 );

			if ( $variation_id && $item_variation_id === $variation_id ) {
				return (string) $cart_item_key;
			}

			if ( ! $variation_id && $item_product_id === $product_id ) {
				return (string) $cart_item_key;
			}
		}

		return '';
	}

	private function debug_log( string $message, array $context = [] ): void {
		if ( function_exists( 'kiwe_mu_debug_log' ) ) {
			kiwe_mu_debug_log( 'Cart ' . $message, $context );
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Kiwe Cart] ' . $message . ' ' . wp_json_encode( $context ) );
		}
	}
}
