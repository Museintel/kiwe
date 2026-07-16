<?php

namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Commerce_Context_Service {
	private $linked_products;

	public function __construct( ?Linked_Products_Service $linked_products = null ) {
		$this->linked_products = $linked_products;
	}

	public function context( array $trust = [], array $protected_flow = [] ): array {
		$available = $this->woo_available();
		$cart      = $this->cart_context();
		$current   = $this->current_context();
		$routes    = $this->routes();
		$decision  = $this->decision( $cart, $current, $trust, $protected_flow );

		return [
			'available'          => $available,
			'cart'               => $cart,
			'current'            => $current,
			'routes'             => $routes,
			'decision'           => $decision,
			'transitionMessages' => $this->transition_messages( $cart, $current, $trust ),
			'complements'        => $this->complements( $cart, $current ),
			'safety'             => [
				'navigationBlocking' => false,
				'cartMutation'       => false,
				'fragmentCheckout'   => false,
			],
		];
	}

	public function public_context(): array {
		$available = $this->woo_available();
		$cart      = $this->neutral_cart_context( $available );
		$current   = $this->current_context();

		return [
			'available'          => $available,
			'cart'               => $cart,
			'current'            => $current,
			'routes'             => $this->routes(),
			'decision'           => $this->message_payload(
				'neutral_message',
				__( 'Shop with confidence', 'dsa' ),
				sprintf( __( 'Payment protection and account trust stay visible through %s.', 'dsa' ), get_bloginfo( 'name' ) ?: __( 'this appsite', 'dsa' ) )
			),
			'transitionMessages' => $this->transition_messages( $cart, $current, [] ),
			'complements'        => [],
			'safety'             => [
				'navigationBlocking' => false,
				'cartMutation'       => false,
				'fragmentCheckout'   => false,
			],
		];
	}

	private function woo_available(): bool {
		return function_exists( 'WC' ) && function_exists( 'wc_get_page_permalink' );
	}

	private function cart_context(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return $this->neutral_cart_context( false );
		}

		$product_ids  = [];
		$category_ids = [];

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				continue;
			}

			$product_id = (int) $product->get_id();
			$product_ids[] = $product_id;
			$category_ids = array_merge( $category_ids, $this->product_category_ids( $product_id ) );
		}

		$count = (int) WC()->cart->get_cart_contents_count();

		return [
			'available'   => true,
			'count'       => $count,
			'hasItems'    => $count > 0,
			'total'       => $this->money_text( WC()->cart->get_total() ),
			'cartUrl'     => function_exists( 'wc_get_cart_url' ) ? esc_url_raw( wc_get_cart_url() ) : '',
			'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? esc_url_raw( wc_get_checkout_url() ) : '',
			'categoryIds' => array_values( array_unique( array_filter( array_map( 'absint', $category_ids ) ) ) ),
			'productIds'  => array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) ),
		];
	}

	private function neutral_cart_context( bool $available ): array {
		return [
			'available'   => $available,
			'count'       => 0,
			'hasItems'    => false,
			'total'       => '',
			'cartUrl'     => $available && function_exists( 'wc_get_cart_url' ) ? esc_url_raw( wc_get_cart_url() ) : '',
			'checkoutUrl' => $available && function_exists( 'wc_get_checkout_url' ) ? esc_url_raw( wc_get_checkout_url() ) : '',
			'categoryIds' => [],
			'productIds'  => [],
		];
	}

	private function current_context(): array {
		$context = [
			'type'        => 'page',
			'id'          => (int) get_queried_object_id(),
			'title'       => wp_strip_all_tags( wp_get_document_title() ),
			'url'         => esc_url_raw( home_url( $this->request_uri() ) ),
			'categoryIds' => [],
		];

		if ( ! $this->woo_available() ) {
			return $context;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;

			if ( $product && is_object( $product ) ) {
				$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) get_queried_object_id();
				$image_id   = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;

				$context = [
					'type'        => 'product',
					'id'          => $product_id,
					'title'       => wp_strip_all_tags( $product->get_name() ),
					'url'         => method_exists( $product, 'get_permalink' ) ? esc_url_raw( $product->get_permalink() ) : $context['url'],
					'price'       => method_exists( $product, 'get_price_html' ) ? $this->money_text( $product->get_price_html() ) : '',
					'image'       => $image_id ? esc_url_raw( wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) ) : '',
					'categoryIds' => $this->product_category_ids( $product_id ),
				];
			}
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();

			if ( $term && ! is_wp_error( $term ) ) {
				$term_link = function_exists( 'get_term_link' ) ? get_term_link( $term ) : '';
				$context = [
					'type'        => 'product_category',
					'id'          => (int) ( $term->term_id ?? 0 ),
					'title'       => wp_strip_all_tags( $term->name ?? '' ),
					'url'         => ! is_wp_error( $term_link ) ? esc_url_raw( $term_link ) : $context['url'],
					'categoryIds' => [ (int) ( $term->term_id ?? 0 ) ],
				];
			}
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$context['type']  = 'shop';
			$context['title'] = wp_strip_all_tags( get_the_title( function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0 ) ?: __( 'Shop', 'dsa' ) );
		}

		return $context;
	}

	private function routes(): array {
		return [
			'cartUrl'     => function_exists( 'wc_get_cart_url' ) ? esc_url_raw( wc_get_cart_url() ) : '',
			'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? esc_url_raw( wc_get_checkout_url() ) : '',
			'shopUrl'     => function_exists( 'wc_get_page_permalink' ) ? esc_url_raw( wc_get_page_permalink( 'shop' ) ) : '',
			'accountUrl'  => function_exists( 'wc_get_page_permalink' ) ? esc_url_raw( wc_get_page_permalink( 'myaccount' ) ) : '',
		];
	}

	private function decision( array $cart, array $current, array $trust, array $protected_flow ): array {
		$context = sanitize_key( $protected_flow['context'] ?? '' );

		if ( in_array( $context, [ 'checkout', 'payment', 'order_received' ], true ) ) {
			return $this->message_payload(
				'checkout_readiness',
				__( 'Checkout confidence', 'dsa' ),
				sprintf( __( 'Your payment path is protected by %s.', 'dsa' ), $this->payment_label( $trust ) )
			);
		}

		if ( ! empty( $cart['hasItems'] ) ) {
			return $this->message_payload(
				'cart_reminder',
				__( 'Still in your cart', 'dsa' ),
				sprintf( _n( '%1$d item is waiting. Total %2$s.', '%1$d items are waiting. Total %2$s.', (int) $cart['count'], 'dsa' ), (int) $cart['count'], $cart['total'] )
			);
		}

		if ( 'product' === ( $current['type'] ?? '' ) ) {
			return $this->message_payload(
				'product_complement',
				__( 'Looking at', 'dsa' ),
				(string) ( $current['title'] ?? __( 'this product', 'dsa' ) )
			);
		}

		return $this->message_payload(
			'neutral_message',
			__( 'Shop with confidence', 'dsa' ),
			sprintf( __( 'Payment protection and account trust stay visible through %s.', 'dsa' ), get_bloginfo( 'name' ) ?: __( 'this appsite', 'dsa' ) )
		);
	}

	private function transition_messages( array $cart, array $current, array $trust ): array {
		return [
			'checkoutReadiness' => $this->message_payload(
				'checkout_readiness',
				__( 'Checkout confidence', 'dsa' ),
				! empty( $cart['hasItems'] )
					? sprintf( __( '%1$d item(s) ready. Payment is protected by %2$s.', 'dsa' ), (int) $cart['count'], $this->payment_label( $trust ) )
					: sprintf( __( 'Payment is protected by %s when you are ready.', 'dsa' ), $this->payment_label( $trust ) )
			),
			'cartReminder'      => $this->message_payload(
				'cart_reminder',
				__( 'Cart check', 'dsa' ),
				! empty( $cart['hasItems'] )
					? sprintf( __( '%1$d item(s) waiting. Total %2$s.', 'dsa' ), (int) $cart['count'], $cart['total'] )
					: __( 'Your cart is ready when you are.', 'dsa' )
			),
			'shopContext'       => $this->message_payload(
				'shop_context',
				__( 'Shop with confidence', 'dsa' ),
				sprintf( __( 'Secure login, SSL, and %s keep the buying path clear.', 'dsa' ), $this->payment_label( $trust ) )
			),
			'productContext'    => $this->message_payload(
				'product_context',
				__( 'Still considering', 'dsa' ),
				! empty( $current['title'] ) ? (string) $current['title'] : __( 'your next product', 'dsa' )
			),
		];
	}

	private function complements( array $cart, array $current ): array {
		if ( $this->linked_products ) {
			$linked = $this->linked_products->recommendations_for_context( $cart, $current, 4 );

			if ( ! empty( $linked ) ) {
				return $linked;
			}
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$category_ids = ! empty( $cart['categoryIds'] ) ? $cart['categoryIds'] : ( $current['categoryIds'] ?? [] );
		$exclude_ids  = ! empty( $cart['productIds'] ) ? $cart['productIds'] : [];

		if ( ! empty( $current['id'] ) ) {
			$exclude_ids[] = (int) $current['id'];
		}

		if ( empty( $category_ids ) ) {
			return [];
		}

		$category_slugs = $this->category_slugs( array_slice( $category_ids, 0, 2 ) );

		if ( empty( $category_slugs ) ) {
			return [];
		}

		$args = [
			'limit'    => 4,
			'status'   => 'publish',
			'exclude'  => array_values( array_unique( array_filter( array_map( 'absint', $exclude_ids ) ) ) ),
			'category' => $category_slugs,
			'orderby'  => 'date',
			'order'    => 'DESC',
		];

		$products = wc_get_products( $args );
		$out      = [];

		foreach ( $products as $product ) {
			if ( ! $product || ! is_object( $product ) ) {
				continue;
			}

			$image_id = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;

			$out[] = [
				'id'        => method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0,
				'title'     => wp_strip_all_tags( $product->get_name() ),
				'url'       => method_exists( $product, 'get_permalink' ) ? esc_url_raw( $product->get_permalink() ) : '',
				'image'     => $image_id ? esc_url_raw( wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) ) : '',
				'price'     => method_exists( $product, 'get_price_html' ) ? $this->money_text( $product->get_price_html() ) : '',
				'actionSafe' => 'view_only',
			];
		}

		return $out;
	}

	private function category_slugs( array $ids ): array {
		$slugs = [];

		foreach ( $ids as $id ) {
			$term = get_term( (int) $id, 'product_cat' );

			if ( $term && ! is_wp_error( $term ) && ! empty( $term->slug ) ) {
				$slugs[] = $term->slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	private function product_category_ids( int $product_id ): array {
		if ( ! $product_id ) {
			return [];
		}

		$terms = get_the_terms( $product_id, 'product_cat' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $term ): int {
						return (int) ( $term->term_id ?? 0 );
					},
					$terms
				)
			)
		);
	}

	private function payment_label( array $trust ): string {
		return sanitize_text_field( $trust['payment']['label'] ?? __( 'your payment provider', 'dsa' ) );
	}

	private function message_payload( string $kind, string $title, string $message ): array {
		return [
			'kind'    => sanitize_key( $kind ),
			'title'   => sanitize_text_field( $title ),
			'message' => sanitize_text_field( $message ),
		];
	}

	private function money_text( string $html ): string {
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, $charset );
	}

	private function request_uri(): string {
		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		return '/' . ltrim( $uri, '/' );
	}
}
