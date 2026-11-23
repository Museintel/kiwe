<?php

namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Resolve only the native routes offered by Start. */
final class Document_Routes {
	public const PRESENTATION = [ 'cart', 'checkout', 'order-payment', 'thank-you', 'account' ];
	public const SECTION_META = '_kiwe_start_woo_presentation';

	public static function source_type( string $target ): string {
		return match ( $target ) {
			'header', 'footer' => $target,
			'post-archive', 'shop', 'product-category' => 'archive',
			'single-product' => 'wc_product',
			default => 'content',
		};
	}

	public static function types(): array {
		return [ 'header'=>'header', 'footer'=>'footer', 'single-post'=>'content', 'post-archive'=>'archive',
			'search'=>'search', 'not-found'=>'error', 'shop'=>'wc_archive', 'product-category'=>'wc_archive',
			'single-product'=>'wc_product' ];
	}

	public static function page_options(): array {
		return [ 'home'=>'page_on_front', 'blog'=>'page_for_posts', 'privacy'=>'wp_page_for_privacy_policy',
			'terms'=>'woocommerce_terms_page_id', 'shop'=>'woocommerce_shop_page_id', 'cart'=>'woocommerce_cart_page_id',
			'checkout'=>'woocommerce_checkout_page_id', 'account'=>'woocommerce_myaccount_page_id' ];
	}

	private static function fail( string $message ): never {
		throw new Compiler_Package_Error( $message );
	}

	private static function public_page( int $id ): \WP_Post {
		$page = get_post( $id );
		if ( ! $page || $page->post_type !== 'page' || $page->post_status !== 'publish'
			|| $page->post_password !== '' || ! current_user_can( 'edit_post', $id ) ) {
			self::fail( 'Choose a public WordPress page you can edit, then refresh the website inventory in Start.' );
		}
		return $page;
	}

	private static function find_slug( string $slug ): int {
		return (int) ( get_page_by_path( $slug, OBJECT, 'page' )->ID ?? 0 );
	}

	public static function condition( string $target, int $page ): array {
		if ( in_array( $target, self::PRESENTATION, true ) ) return [];
		if ( $page ) return [ [ 'main'=>'ids', 'ids'=>[ $page ] ] ];
		return match ( $target ) {
			'header', 'footer' => [ [ 'main'=>'any' ] ],
			'single-post' => [ [ 'main'=>'postType', 'postType'=>[ 'post' ] ] ],
			'single-product' => [ [ 'main'=>'postType', 'postType'=>[ 'product' ] ] ],
			'post-archive' => [
				[ 'main'=>'archiveType', 'archiveType'=>[ 'author' ] ],
				[ 'main'=>'archiveType', 'archiveType'=>[ 'date' ] ],
				[ 'main'=>'archiveType', 'archiveType'=>[ 'term' ], 'archiveTerms'=>[ 'category::all','post_tag::all' ] ],
			],
			'product-category' => [ [ 'main'=>'archiveType', 'archiveType'=>[ 'term' ], 'archiveTerms'=>[ 'product_cat::all' ] ] ],
			'search' => [ [ 'main'=>'search' ] ],
			'not-found' => [ [ 'main'=>'error' ] ],
			default => [],
		};
	}

	public static function resolve( string $target, int $picked, int $created = 0, array $reusable = [] ): array {
		$options = self::page_options();
		$commerce = in_array( $target, array_merge( [ 'shop', 'product-category', 'single-product' ], self::PRESENTATION ), true );
		$custom = preg_match( '/^page-([1-9][0-9]{0,14})$/D', $target, $custom_page );
		if ( ( ! isset( self::types()[$target] ) && ! in_array( $target, [ 'about','contact','privacy','terms','blog' ], true )
			&& ! in_array( $target, self::PRESENTATION, true ) && ! $custom ) || ( $commerce && ! function_exists( 'wc_get_page_id' ) ) ) {
			self::fail( 'This document is unavailable on the connected website.' );
		}
		if ( $target === 'blog' && get_option( 'show_on_front' ) !== 'page' ) {
			self::fail( 'Publish the assigned homepage first so WordPress has a separate Blog route.' );
		}
		$section = in_array( $target, self::PRESENTATION, true );
		$native = in_array( $target, [ 'order-payment','thank-you' ], true ) ? 'checkout' : $target;
		$label = [
			'about'=>'About', 'contact'=>'Contact', 'blog'=>'Blog', 'privacy'=>'Privacy policy', 'terms'=>'Terms',
			'header'=>'Header', 'footer'=>'Footer', 'single-post'=>'Single post', 'post-archive'=>'Post archives',
			'search'=>'Search results', 'not-found'=>'Page not found', 'shop'=>'Shop', 'product-category'=>'Product categories',
			'single-product'=>'Single product', 'cart'=>'Cart', 'checkout'=>'Checkout', 'order-payment'=>'Order payment',
			'thank-you'=>'Thank you', 'account'=>'My account and orders',
		][$target] ?? 'Page';
		$page = $custom ? (int) $custom_page[1] : ( isset( $options[$native] ) ? (int) get_option( $options[$native], 0 ) : 0 );
		if ( in_array( $target, [ 'about','contact','terms' ], true ) && ! $page ) {
			foreach ( [ $target, $target . ( $target === 'terms' ? '-and-conditions' : '-us' ) ] as $name ) {
				if ( ( $page = self::find_slug( $name ) ) > 0 ) break;
			}
		}
		if ( $page && $page === ( $reusable['id'] ?? 0 ) && get_post_status( $page ) === 'draft' ) $page = 0;
		if ( $page !== $picked && ! ( $picked === 0 && $page === $created ) ) {
			self::fail( 'The native page assignment changed. Refresh Pages & Templates and review this design again.' );
		}
		$slug = '';
		$url = null;
		$proof = '';
		if ( $page ) {
			$post = self::public_page( $page );
			foreach ( $options as $role=>$option ) {
				if ( $role !== $native && $page === (int) get_option( $option ) ) {
					self::fail( 'This page serves another WordPress or WooCommerce route. Review its assignments first.' );
				}
			}
			if ( $custom ) $label = get_the_title( $post );
			$url = $native === $target ? get_permalink( $post ) : null;
			$proof = hash( 'sha256', serialize( [ $post->post_content, $post->post_modified, $post->post_name,
				get_post_meta( $page, BRICKS_DB_PAGE_CONTENT, true ) ] ) );
		} elseif ( in_array( $target, [ 'about','contact','blog' ], true ) ) {
			$slug = $reusable['slug'] ?? $target;
			if ( ! $reusable && $target === 'blog' && self::find_slug( $slug ) ) $slug = 'news';
			$existing = self::find_slug( $slug );
			$resolved = url_to_postid( home_url( '/' . $slug . '/' ) );
			$allowed = (int) ( $reusable['id'] ?? 0 );
			if ( ( $existing && $existing !== $allowed ) || ( $resolved && $resolved !== $allowed ) ) {
				self::fail( 'The proposed page address is occupied. Review the native page assignment in WordPress.' );
			}
			if ( get_option( 'permalink_structure' ) !== '' ) $url = home_url( user_trailingslashit( '/' . $slug, 'page' ) );
		} elseif ( $section || in_array( $target, [ 'privacy','terms','shop' ], true ) ) {
			self::fail( 'Publish and assign the site’s native page before placing this design.' );
		}
		if ( in_array( $target, [ 'header','footer' ], true ) ) $url = home_url( '/' );
		$type = $section ? 'section' : ( self::types()[$target] ?? 'content' );
		return compact( 'target','label','page','slug','url','proof','type','section' );
	}

	/** Render the native Section on the matching public storefront route. */
	public static function render_presentation(): void {
		if ( ! function_exists( 'is_checkout' ) ) return;
		$target = match ( true ) {
			is_cart() => 'cart',
			is_account_page() => 'account',
			is_checkout() && ! is_wc_endpoint_url() => 'checkout',
			default => '',
		};
		if ( $target !== '' ) self::render_section( $target );
	}

	/** WooCommerce calls this hook after validating the order and its customer. */
	public static function render_order_payment( \WC_Order $order ): void {
		self::render_order_section( 'order-payment', 'order-pay', $order->get_id() );
	}

	public static function render_order_received( int $order_id ): void {
		self::render_order_section( 'thank-you', 'order-received', $order_id );
	}

	private static function render_order_section( string $target, string $endpoint, int $order_id ): void {
		if ( $order_id > 0 && is_checkout() && is_wc_endpoint_url( $endpoint )
			&& $order_id === absint( get_query_var( $endpoint ) ) ) self::render_section( $target );
	}

	private static function render_section( string $target ): void {
		if ( ! defined( 'BRICKS_DB_TEMPLATE_SLUG' ) || ! shortcode_exists( 'bricks_template' )
			|| ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) ) return;
		$page = (int) get_queried_object_id();
		if ( ! $page ) return;
		$assignment = [ 'site'=>home_url( '/' ), 'target'=>$target, 'page'=>$page ];
		$ids = get_posts( [
			'post_type'=>BRICKS_DB_TEMPLATE_SLUG, 'post_status'=>'publish', 'has_password'=>false,
			'fields'=>'ids', 'numberposts'=>2, 'no_found_rows'=>true, 'meta_key'=>self::SECTION_META,
			'meta_value'=>serialize( $assignment ),
		] );
		if ( count( $ids ) !== 1 || get_post_meta( $ids[0], BRICKS_DB_TEMPLATE_TYPE, true ) !== 'section'
			|| get_post_meta( $ids[0], self::SECTION_META, true ) !== $assignment ) return;
		echo do_shortcode( '[bricks_template id="' . (int) $ids[0] . '" on_hook="1"]' );
	}
}
