<?php

namespace DSA\Schema;

use DSA\Diagnostics\Runtime_Profiler;
use DSA\Element_Registry;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema_Geo_Service {
	public function __construct(
		private Settings $settings,
		private Element_Registry $registry
	) {}

	public function register(): void {
		add_action( 'wp_footer', [ $this, 'print_schema' ], 4 );
	}

	public function print_schema(): void {
		if ( ! $this->settings->get( 'enabled', true ) || ! $this->is_indexable_request() || $this->external_schema_provider() ) {
			return;
		}

		$config = $this->settings->get( 'schema_geo', [] );
		$config = is_array( $config ) ? $config : [];

		if ( empty( $config['enabled'] ) ) {
			return;
		}

		$graph = $this->schema_graph( $config );

		if ( empty( $graph ) ) {
			return;
		}

		$payload = [
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		];

		$json = wp_json_encode(
			$payload,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);

		if ( ! $json ) {
			return;
		}

		echo "\n" . '<script id="dsa-schema-geo" type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function summary(): array {
		$config = $this->settings->get( 'schema_geo', [] );
		$config = is_array( $config ) ? $config : [];

		return [
			'enabled'    => ! empty( $config['enabled'] ),
			'suppressed' => $this->external_schema_provider(),
			'provider'   => $this->external_schema_provider_name(),
			'product'    => ! empty( $config['woo_product'] ),
			'breadcrumb' => ! empty( $config['breadcrumb'] ),
			'webpage'    => ! empty( $config['webpage'] ),
			'registry'   => ! empty( $config['registry_hints'] ),
			'cacheKey'   => $this->cache_key( $config ),
		];
	}

	private function schema_graph( array $config ): array {
		$cache_key = $this->cache_key( $config );
		$profile   = Runtime_Profiler::start();
		$cached    = get_transient( $cache_key );
		Runtime_Profiler::finish( 'transient.schema.read', $profile, is_array( $cached ) );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$graph = [];

		if ( ! empty( $config['webpage'] ) ) {
			$webpage = $this->webpage_schema( $config );

			if ( $webpage ) {
				$graph[] = $webpage;
			}
		}

		if ( ! empty( $config['breadcrumb'] ) ) {
			$breadcrumb = $this->breadcrumb_schema();

			if ( $breadcrumb ) {
				$graph[] = $breadcrumb;
			}
		}

		if ( ! empty( $config['woo_product'] ) && ! $this->woo_outputs_product_schema() ) {
			$product = $this->product_schema();

			if ( $product ) {
				$graph[] = $product;
			}
		}

		$profile = Runtime_Profiler::start();
		set_transient( $cache_key, $graph, 12 * HOUR_IN_SECONDS );
		Runtime_Profiler::finish( 'transient.schema.write', $profile );

		return $graph;
	}

	private function webpage_schema( array $config ): array {
		$url      = $this->current_url();
		$post_id  = (int) get_queried_object_id();
		$title    = wp_strip_all_tags( wp_get_document_title() );
		$schema   = [
			'@type'       => is_singular( 'post' ) ? 'Article' : 'WebPage',
			'@id'         => $url . '#webpage',
			'url'         => $url,
			'name'        => $title,
			'isPartOf'    => [
				'@type' => 'WebSite',
				'@id'   => home_url( '/' ) . '#website',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			],
			'inLanguage'  => get_bloginfo( 'language' ),
			'dateModified' => $this->modified_time( $post_id ),
		];

		if ( is_singular() ) {
			$schema['headline'] = get_the_title( $post_id );
			$excerpt = $this->excerpt( $post_id );

			if ( '' !== $excerpt ) {
				$schema['description'] = $excerpt;
			}
		}

		if ( ! empty( $config['registry_hints'] ) ) {
			$registry_hints = $this->registry_hints();

			if ( ! empty( $registry_hints ) ) {
				$schema['about'] = $registry_hints;
			}
		}

		return $this->strip_empty( $schema );
	}

	private function product_schema(): array {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product || ! is_object( $product ) ) {
			return [];
		}

		$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) get_queried_object_id();
		$image_id   = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
		$image      = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
		$schema     = [
			'@type'       => 'Product',
			'@id'         => get_permalink( $product_id ) . '#product',
			'name'        => wp_strip_all_tags( $product->get_name() ),
			'url'         => get_permalink( $product_id ),
			'description' => $this->product_description( $product ),
			'image'       => $image ? [ esc_url_raw( $image ) ] : [],
			'sku'         => method_exists( $product, 'get_sku' ) ? $product->get_sku() : '',
			'offers'      => $this->offer_schema( $product ),
		];

		if ( method_exists( $product, 'get_average_rating' ) && method_exists( $product, 'get_review_count' ) ) {
			$rating = (float) $product->get_average_rating();
			$count  = (int) $product->get_review_count();

			if ( $rating > 0 && $count > 0 ) {
				$schema['aggregateRating'] = [
					'@type'       => 'AggregateRating',
					'ratingValue' => (string) $rating,
					'reviewCount' => $count,
				];
			}
		}

		return $this->strip_empty( $schema );
	}

	private function offer_schema( $product ): array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
			return [];
		}

		$price = (string) $product->get_price();

		if ( '' === $price ) {
			return [];
		}

		$availability = method_exists( $product, 'is_in_stock' ) && $product->is_in_stock()
			? 'https://schema.org/InStock'
			: 'https://schema.org/OutOfStock';

		return $this->strip_empty(
			[
				'@type'         => 'Offer',
				'url'           => method_exists( $product, 'get_permalink' ) ? $product->get_permalink() : get_permalink(),
				'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'price'         => $price,
				'availability'  => $availability,
			]
		);
	}

	private function breadcrumb_schema(): array {
		$items = [
			[
				'name' => __( 'Home', 'dsa' ),
				'url'  => home_url( '/' ),
			],
		];

		if ( function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_page_permalink' ) ) {
			$items[] = [
				'name' => __( 'Shop', 'dsa' ),
				'url'  => wc_get_page_permalink( 'shop' ),
			];

			$terms = get_the_terms( get_queried_object_id(), 'product_cat' );

			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$term = reset( $terms );
				$link = get_term_link( $term );

				if ( ! is_wp_error( $link ) ) {
					$items[] = [
						'name' => $term->name,
						'url'  => $link,
					];
				}
			}
		} elseif ( is_singular() ) {
			$ancestors = array_reverse( get_post_ancestors( get_queried_object_id() ) );

			foreach ( $ancestors as $ancestor_id ) {
				$items[] = [
					'name' => get_the_title( $ancestor_id ),
					'url'  => get_permalink( $ancestor_id ),
				];
			}
		}

		if ( is_singular() || is_page() ) {
			$items[] = [
				'name' => wp_strip_all_tags( get_the_title( get_queried_object_id() ) ?: wp_get_document_title() ),
				'url'  => $this->current_url(),
			];
		} elseif ( is_archive() ) {
			$items[] = [
				'name' => wp_strip_all_tags( get_the_archive_title() ),
				'url'  => $this->current_url(),
			];
		}

		$items = array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return ! empty( $item['name'] ) && ! empty( $item['url'] );
				}
			)
		);

		if ( count( $items ) < 2 ) {
			return [];
		}

		$list = [];

		foreach ( $items as $index => $item ) {
			$list[] = [
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => wp_strip_all_tags( $item['name'] ),
				'item'     => esc_url_raw( $item['url'] ),
			];
		}

		return [
			'@type'           => 'BreadcrumbList',
			'@id'             => $this->current_url() . '#breadcrumb',
			'itemListElement' => $list,
		];
	}

	private function registry_hints(): array {
		$registry = $this->registry->to_array();
		$elements = isset( $registry['elements'] ) && is_array( $registry['elements'] ) ? $registry['elements'] : [];
		$hints = [];

		foreach ( $elements as $element ) {
			$type = $element['type'] ?? '';
			$label = trim( (string) ( $element['label'] ?? '' ) );
			$confidence = (float) ( $element['confidence'] ?? 0 );

			if ( '' === $label || $confidence < 0.78 || ! in_array( $type, [ 'heading', 'image' ], true ) ) {
				continue;
			}

			$hints[] = [
				'@type' => 'Thing',
				'name'  => $label,
			];

			if ( count( $hints ) >= 6 ) {
				break;
			}
		}

		return $hints;
	}

	private function cache_key( array $config ): string {
		$post_id = (int) get_queried_object_id();
		$modified = $this->modified_time( $post_id );
		$registry = $this->registry->to_array();
		$route = $this->current_url();
		$parts = [
			DSA_VERSION,
			$route,
			$post_id,
			$modified,
			wp_json_encode( $config ),
			wp_json_encode( $registry['summary'] ?? [] ),
			$this->external_schema_provider_name(),
			$this->woo_outputs_product_schema() ? 'woo-product-schema' : 'dsa-product-schema',
		];

		if ( function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );

			if ( $product && is_object( $product ) && method_exists( $product, 'get_date_modified' ) && $product->get_date_modified() ) {
				$parts[] = $product->get_date_modified()->date( 'c' );
			}
		}

		return 'dsa_schema_geo_' . md5( implode( '|', array_map( 'strval', $parts ) ) );
	}

	private function external_schema_provider(): bool {
		return (bool) apply_filters( 'dsa_schema_geo_external_provider_active', '' !== $this->external_schema_provider_name() );
	}

	private function external_schema_provider_name(): string {
		$providers = [
			'yoast'             => defined( 'WPSEO_VERSION' ),
			'rank-math'         => defined( 'RANK_MATH_VERSION' ),
			'aioseo'            => defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ),
			'seopress'          => defined( 'SEOPRESS_VERSION' ),
			'the-seo-framework' => defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ),
		];

		foreach ( $providers as $name => $active ) {
			if ( $active ) {
				return $name;
			}
		}

		return '';
	}

	private function woo_outputs_product_schema(): bool {
		return function_exists( 'is_product' ) && is_product() && class_exists( 'WC_Structured_Data' );
	}

	private function product_description( $product ): string {
		if ( ! is_object( $product ) ) {
			return '';
		}

		$description = method_exists( $product, 'get_short_description' ) ? $product->get_short_description() : '';

		if ( '' === trim( wp_strip_all_tags( $description ) ) && method_exists( $product, 'get_description' ) ) {
			$description = $product->get_description();
		}

		return wp_strip_all_tags( $description );
	}

	private function excerpt( int $post_id ): string {
		if ( ! $post_id ) {
			return '';
		}

		$excerpt = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 32 );

		return wp_strip_all_tags( $excerpt );
	}

	private function modified_time( int $post_id ): string {
		if ( ! $post_id ) {
			$modified = get_lastpostmodified( 'GMT' );
			return $modified ? gmdate( 'c', strtotime( $modified . ' UTC' ) ) : 'route-static';
		}

		$modified = get_post_modified_time( 'c', true, $post_id );

		return $modified ?: gmdate( 'c' );
	}

	private function current_url(): string {
		$post_id = (int) get_queried_object_id();
		if ( $post_id && is_singular() && function_exists( 'wp_get_canonical_url' ) ) {
			$canonical = wp_get_canonical_url( $post_id );
			if ( is_string( $canonical ) && '' !== $canonical ) {
				return esc_url_raw( $canonical );
			}
		}

		if ( is_front_page() ) {
			return esc_url_raw( home_url( '/' ) );
		}

		if ( is_home() ) {
			$page_for_posts = (int) get_option( 'page_for_posts' );
			return esc_url_raw( $page_for_posts ? get_permalink( $page_for_posts ) : home_url( '/' ) );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url = remove_query_arg( [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid' ], home_url( $request_uri ) );

		return esc_url_raw( $url );
	}

	private function is_indexable_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_robots() || is_preview() || is_search() || is_404() ) {
			return false;
		}

		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			return false;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id && is_singular() && ( post_password_required( $post_id ) || 'publish' !== get_post_status( $post_id ) ) ) {
			return false;
		}

		return true;
	}

	private function strip_empty( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$item = $this->strip_empty( $item );
			}

			if ( '' === $item || [] === $item || null === $item ) {
				unset( $value[ $key ] );
				continue;
			}

			$value[ $key ] = $item;
		}

		return $value;
	}
}
