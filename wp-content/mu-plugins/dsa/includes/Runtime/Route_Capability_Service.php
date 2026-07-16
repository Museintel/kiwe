<?php

namespace DSA\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Route_Capability_Service {
	public function policy( array $settings, array $manifest, array $commerce ): array {
		$routes = isset( $commerce['routes'] ) && is_array( $commerce['routes'] ) ? $commerce['routes'] : [];

		return [
			'version'            => 1,
			'observeOnly'        => true,
			'fragmentNavigation' => false,
			'morphNavigation'    => ! empty( $settings['visual_effects']['editorial_morph_navigation'] ),
			'viewTransitions'    => [
				'enabled'     => ! empty( $settings['visual_effects']['editorial_view_transitions'] ),
				'observeOnly' => false,
				'currentDocumentEditorial' => $this->current_document_is_editorial(),
			],
			'protectedPatterns'  => $this->protected_patterns( $routes ),
			'excludedPatterns'   => $this->excluded_patterns( $manifest ),
			'unsafeQueryParams'  => [
				'add-to-cart',
				'wc-ajax',
				'bricks',
			],
			'assetExtensions'    => [
				'pdf',
				'zip',
				'rar',
				'7z',
				'jpg',
				'jpeg',
				'png',
				'gif',
				'webp',
				'mp4',
				'mov',
				'doc',
				'docx',
				'xls',
				'xlsx',
			],
			'popupSelectors'     => [
				'[data-toggle]',
				'[data-bs-toggle]',
				'[data-elementor-open-lightbox]',
				'[data-popup]',
				'[data-modal]',
				'[data-offcanvas]',
				'[data-cart]',
				'[data-open-cart]',
				'[data-fancybox]',
				'.wc-block-mini-cart',
				'.widget_shopping_cart',
				'.elementor-menu-cart__toggle',
				'.elementor-menu-cart__container',
				'.xoo-wsc-cart-trigger',
				'.xoo-wsc-basket',
				'.xt_woofc-trigger',
				'.woofc-cart-trigger',
				'.cart-contents',
				'.site-header-cart .cart-contents',
				'.mini-cart .cart-contents',
				'.side-cart .cart-contents',
				'.offcanvas',
				'.modal',
				'[aria-haspopup="dialog"]',
				'[aria-haspopup="menu"]',
				'ins.adsbygoogle',
				'.adsbygoogle',
				'[data-ad-client]',
				'[data-google-query-id]',
				'#onetrust-banner-sdk',
				'.cky-consent-container',
				'.cmplz-cookiebanner',
				'[data-cookie-consent]',
			],
			'candidatePatterns'  => [
				'transition' => [
					'/*',
				],
				'fragment'   => [],
			],
			'principles'         => [
				'protected_routes_full_document',
				'commerce_mutations_server_authoritative',
				'fragments_require_future_envelope',
				'fallback_to_wordpress_navigation',
			],
		];
	}

	private function current_document_is_editorial(): bool {
		if ( is_admin() || is_feed() || is_preview() || is_search() || is_404() ) {
			return false;
		}

		if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) ) {
			return false;
		}

		return is_front_page() || is_home() || is_singular( [ 'post', 'page' ] ) || is_category() || is_tag() || is_date() || is_author();
	}

	private function protected_patterns( array $routes ): array {
		$patterns = [
			'/cart*',
			'/checkout*',
			'/my-account*',
			'/order-pay*',
			'/order-received*',
		];

		foreach ( [ 'cartUrl', 'checkoutUrl', 'accountUrl' ] as $key ) {
			$pattern = $this->pattern_from_url( $routes[ $key ] ?? '' );

			if ( '' !== $pattern ) {
				$patterns[] = $pattern;
			}
		}

		return array_values( array_unique( array_filter( $patterns ) ) );
	}

	private function excluded_patterns( array $manifest ): array {
		$excluded = [];

		if ( isset( $manifest['routes']['excluded'] ) && is_array( $manifest['routes']['excluded'] ) ) {
			$excluded = $manifest['routes']['excluded'];
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $pattern ): string {
							return sanitize_text_field( (string) $pattern );
						},
						$excluded
					)
				)
			)
		);
	}

	private function pattern_from_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? '/' . ltrim( $path, '/' ) : '';

		if ( '' === $path || '/' === $path ) {
			return '';
		}

		return rtrim( $path, '/' ) . '*';
	}
}
