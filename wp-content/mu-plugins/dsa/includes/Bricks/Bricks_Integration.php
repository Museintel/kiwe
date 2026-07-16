<?php

namespace DSA\Bricks;

use DSA\Commerce\Linked_Products_Service;
use DSA\Commerce\Store_Analytics_Service;
use DSA\Element_Registry;
use DSA\Settings;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bricks_Integration {
	private const MINI_CART_SETTINGS_OPTION = 'dsa_bricks_mini_cart_settings';
	private const ATC_GROUP = 'dsaAtcBehaviour';
	private const LINKED_PRODUCTS_GROUP = 'dsaLinkedProducts';

	private $registry;
	private $settings;
	private $linked_products;
	private $store_analytics;
	private array $mini_cart_context = [];

	public function __construct( Element_Registry $registry, Settings $settings, ?Linked_Products_Service $linked_products = null, ?Store_Analytics_Service $store_analytics = null ) {
		$this->registry        = $registry;
		$this->settings        = $settings;
		$this->linked_products = $linked_products;
		$this->store_analytics = $store_analytics;
	}

	public function register(): void {
		add_filter( 'bricks/dynamic_tags_list', [ $this, 'add_dynamic_tags' ], 20 );
		add_filter( 'bricks/dynamic_data/render_tag', [ $this, 'render_dynamic_tag' ], 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', [ $this, 'render_dynamic_content' ], 20, 3 );
		add_filter( 'bricks/element/render_attributes', [ $this, 'add_element_attributes' ], 20, 3 );
		add_filter( 'bricks/frontend/render_element', [ $this, 'observe_rendered_element' ], 20, 2 );
		add_filter( 'bricks/elements/woocommerce-mini-cart/controls', [ $this, 'add_mini_cart_controls' ], 20 );
		add_filter( 'bricks/elements/filter-search/controls', [ $this, 'add_search_bridge_controls' ], 20 );
		add_filter( 'bricks/elements/icon/controls', [ $this, 'add_dsa_icon_launcher_controls' ], 20 );
		add_filter( 'bricks/elements/product-add-to-cart/control_groups', [ $this, 'add_add_to_cart_control_group' ], 20 );
		add_filter( 'bricks/elements/product-add-to-cart/controls', [ $this, 'add_add_to_cart_controls' ], 20 );
		add_filter( 'bricks/elements/product-upsells/control_groups', [ $this, 'add_linked_products_control_group' ], 20 );
		add_filter( 'bricks/elements/product-upsells/controls', [ $this, 'add_linked_products_controls' ], 20 );
		add_filter( 'bricks/element/settings', [ $this, 'bridge_element_settings' ], 20, 2 );
		add_filter( 'bricks/element/set_root_attributes', [ $this, 'add_add_to_cart_attributes' ], 20, 2 );
		add_action( 'woocommerce_before_mini_cart', [ $this, 'restore_mini_cart_context' ] );
		add_filter( 'woocommerce_widget_cart_item_quantity', [ $this, 'render_mini_cart_quantity' ], 20, 3 );
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', [ $this, 'render_mini_cart_recommendations' ], 8 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_mini_cart_adapter' ] );
	}

	public function add_dynamic_tags( array $tags ): array {
		if ( empty( $this->bricks_config()['dynamic_tags_enabled'] ) ) {
			return $tags;
		}

		$existing = array_filter( array_column( $tags, 'name' ), 'is_string' );

		foreach ( $this->dynamic_tag_definitions() as $name => $label ) {
			if ( in_array( '{' . $name . '}', $existing, true ) ) {
				continue;
			}

			$tags[] = [
				'name'  => '{' . $name . '}',
				'label' => $label,
				'group' => 'Kiwe',
			];
		}

		return $tags;
	}

	public function render_dynamic_tag( $tag, $post = null, $context = 'text' ) {
		if ( empty( $this->bricks_config()['dynamic_tags_enabled'] ) || ! is_string( $tag ) ) {
			return $tag;
		}

		$name = trim( $tag, "{} \t\n\r\0\x0B" );

		if ( ! array_key_exists( $name, $this->dynamic_tag_definitions() ) ) {
			return $tag;
		}

		return $this->dynamic_tag_value( $name, is_string( $context ) ? $context : 'text', $post );
	}

	public function render_dynamic_content( $content, $post = null, $context = 'text' ) {
		if ( empty( $this->bricks_config()['dynamic_tags_enabled'] ) || ! is_string( $content ) || ( false === strpos( $content, '{kiwe_' ) && false === strpos( $content, '{woo_product_weight}' ) ) ) {
			return $content;
		}

		foreach ( array_keys( $this->dynamic_tag_definitions() ) as $name ) {
			$token = '{' . $name . '}';

			if ( false !== strpos( $content, $token ) ) {
				$content = str_replace( $token, (string) $this->dynamic_tag_value( $name, is_string( $context ) ? $context : 'text', $post ), $content );
			}
		}

		return $content;
	}

	private function dynamic_tag_definitions(): array {
		return [
			'kiwe_site_logo'          => __( 'Site logo URL', 'dsa' ),
			'kiwe_site_logo_inverse'  => __( 'Site logo inverse URL', 'dsa' ),
			'kiwe_store_address_1'    => __( 'Store address line 1', 'dsa' ),
			'kiwe_store_address_2'    => __( 'Store address line 2', 'dsa' ),
			'kiwe_store_city'         => __( 'Store city', 'dsa' ),
			'kiwe_store_country'      => __( 'Store country', 'dsa' ),
			'kiwe_store_state'        => __( 'Store state', 'dsa' ),
			'kiwe_store_postcode'     => __( 'Store postcode / ZIP', 'dsa' ),
			'kiwe_store_phone'        => __( 'Store phone', 'dsa' ),
			'kiwe_store_email'        => __( 'Store email', 'dsa' ),
			'kiwe_selling_locations'  => __( 'Selling locations', 'dsa' ),
			'kiwe_shipping_locations' => __( 'Shipping locations', 'dsa' ),
			'woo_product_weight'      => __( 'Product weight', 'dsa' ),
		] + $this->nav_menu_tag_definitions();
	}

	private function dynamic_tag_value( string $name, string $context, $post = null ) {
		switch ( $name ) {
			case 'kiwe_site_logo':
				return $this->logo_tag_value( 'default', $context );
			case 'kiwe_site_logo_inverse':
				return $this->logo_tag_value( 'inverse', $context );
			case 'kiwe_store_address_1':
				return $this->woo_store_option( 'woocommerce_store_address' );
			case 'kiwe_store_address_2':
				return $this->woo_store_option( 'woocommerce_store_address_2' );
			case 'kiwe_store_city':
				return $this->woo_store_option( 'woocommerce_store_city' );
			case 'kiwe_store_country':
				$location = $this->woo_store_country_state();
				return $location['country_label'];
			case 'kiwe_store_state':
				$location = $this->woo_store_country_state();
				return $location['state_label'];
			case 'kiwe_store_postcode':
				return $this->woo_store_option( 'woocommerce_store_postcode' );
			case 'kiwe_store_phone':
				return Site_Identity_Service::store_phone();
			case 'kiwe_store_email':
				return Site_Identity_Service::store_email();
			case 'kiwe_selling_locations':
				return $this->woo_locations_label( 'selling' );
			case 'kiwe_shipping_locations':
				return $this->woo_locations_label( 'shipping' );
			case 'woo_product_weight':
				return $this->product_weight_tag_value( $post, $context );
			default:
				if ( preg_match( '/^kiwe_menu_(\d+)$/', $name, $matches ) ) {
					return $this->nav_menu_tag_value( absint( $matches[1] ), $context );
				}
				return '';
		}
	}

	private function product_weight_tag_value( $post, string $context ): string {
		if ( in_array( $context, [ 'image', 'media', 'link' ], true ) || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product_id = is_object( $post ) && isset( $post->ID ) ? absint( $post->ID ) : absint( $post );

		if ( ! $product_id ) {
			$product_id = absint( get_queried_object_id() );
		}

		$product = $product_id ? wc_get_product( $product_id ) : null;
		$weight  = $product && method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '';

		if ( '' === $weight || ! function_exists( 'wc_format_weight' ) ) {
			return '';
		}

		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';
		return html_entity_decode( wp_strip_all_tags( wc_format_weight( $weight ) ), ENT_QUOTES, $charset );
	}

	private function nav_menu_tag_definitions(): array {
		$menus = wp_get_nav_menus();
		$tags  = [];

		foreach ( is_array( $menus ) ? $menus : [] as $menu ) {
			if ( empty( $menu->term_id ) || empty( $menu->name ) ) {
				continue;
			}

			$tags[ 'kiwe_menu_' . absint( $menu->term_id ) ] = sprintf(
				/* translators: %s: WordPress nav menu name. */
				__( 'Menu: %s', 'dsa' ),
				sanitize_text_field( (string) $menu->name )
			);
		}

		return $tags;
	}

	private function nav_menu_tag_value( int $menu_id, string $context ): string {
		if ( ! $menu_id || ! is_nav_menu( $menu_id ) || in_array( $context, [ 'image', 'media', 'link' ], true ) ) {
			return '';
		}

		$html = wp_nav_menu(
			[
				'menu'        => $menu_id,
				'container'   => 'nav',
				'container_class' => 'kiwe-bricks-menu',
				'echo'        => false,
				'fallback_cb' => '__return_empty_string',
			]
		);

		return is_string( $html ) ? $html : '';
	}

	private function logo_tag_value( string $variant, string $context ) {
		$option = 'inverse' === $variant ? Site_Identity_Service::OPTION_LOGO_INVERSE : Site_Identity_Service::OPTION_LOGO;
		$id     = Site_Identity_Service::attachment_id( $option );

		if ( ! $id && Site_Identity_Service::OPTION_LOGO === $option ) {
			$id = absint( get_theme_mod( 'custom_logo' ) );
		}

		if ( in_array( $context, [ 'image', 'media' ], true ) ) {
			return $id ? [ $id ] : [];
		}

		return Site_Identity_Service::logo_url( $variant );
	}

	private function woo_store_option( string $option ): string {
		return sanitize_text_field( (string) get_option( $option, '' ) );
	}

	private function woo_store_country_state(): array {
		$raw = sanitize_text_field( (string) get_option( 'woocommerce_default_country', '' ) );
		[ $country, $state ] = array_pad( explode( ':', $raw, 2 ), 2, '' );

		return [
			'country'       => $country,
			'state'         => $state,
			'country_label' => $this->country_label( $country ),
			'state_label'   => $this->state_label( $country, $state ),
		];
	}

	private function woo_locations_label( string $type ): string {
		if ( 'shipping' === $type ) {
			$mode = sanitize_key( (string) get_option( 'woocommerce_ship_to_countries', 'ship_to_all' ) );

			if ( 'disabled' === $mode ) {
				return __( 'Shipping disabled', 'dsa' );
			}

			if ( 'shipping' === $mode ) {
				return __( 'Ship to selling locations', 'dsa' );
			}

			if ( 'specific' === $mode ) {
				return sprintf(
					/* translators: %s: comma-separated country names. */
					__( 'Ship to %s', 'dsa' ),
					$this->country_list_label( (array) get_option( 'woocommerce_specific_ship_to_countries', [] ) )
				);
			}

			return __( 'Ship to all countries', 'dsa' );
		}

		$mode = sanitize_key( (string) get_option( 'woocommerce_allowed_countries', 'all' ) );

		if ( 'specific' === $mode ) {
			return sprintf(
				/* translators: %s: comma-separated country names. */
				__( 'Sell to %s', 'dsa' ),
				$this->country_list_label( (array) get_option( 'woocommerce_specific_allowed_countries', [] ) )
			);
		}

		if ( 'all_except' === $mode ) {
			return sprintf(
				/* translators: %s: comma-separated country names. */
				__( 'Sell to all countries except %s', 'dsa' ),
				$this->country_list_label( (array) get_option( 'woocommerce_all_except_countries', [] ) )
			);
		}

		return __( 'Sell to all countries', 'dsa' );
	}

	private function country_list_label( array $codes ): string {
		$labels = [];

		foreach ( $codes as $code ) {
			$label = $this->country_label( sanitize_text_field( (string) $code ) );

			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		return $labels ? implode( ', ', $labels ) : __( 'selected countries', 'dsa' );
	}

	private function country_label( string $country ): string {
		if ( '' === $country ) {
			return '';
		}

		if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			return sanitize_text_field( (string) ( $countries[ $country ] ?? $country ) );
		}

		return sanitize_text_field( $country );
	}

	private function state_label( string $country, string $state ): string {
		if ( '' === $state ) {
			return '';
		}

		if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
			$states = WC()->countries->get_states( $country );
			return sanitize_text_field( (string) ( is_array( $states ) && isset( $states[ $state ] ) ? $states[ $state ] : $state ) );
		}

		return sanitize_text_field( $state );
	}

	public function add_element_attributes( array $attributes, string $key, $element ): array {
		if ( '_root' !== $key || ! is_object( $element ) || empty( $element->id ) ) {
			return $attributes;
		}

		if ( ! isset( $attributes['_root'] ) || ! is_array( $attributes['_root'] ) ) {
			$attributes['_root'] = [];
		}

		$attributes['_root']['data-dsa-bricks-id'] = sanitize_key( (string) $element->id );

		if ( ! empty( $element->name ) ) {
			$attributes['_root']['data-dsa-bricks-type'] = sanitize_key( (string) $element->name );
		}

		if ( 'filter-search' === (string) ( $element->name ?? '' ) && ! empty( $element->settings['dsaSearchBridge'] ) ) {
			$attributes['_root']['data-dsa-search-bridge'] = '1';
			$target_query = sanitize_key( (string) ( $element->settings['filterQueryId'] ?? '' ) );
			if ( '' !== $target_query ) {
				$attributes['_root']['data-dsa-search-query'] = $target_query;
			}
		}

		if ( 'icon' === (string) ( $element->name ?? '' ) && ! empty( $this->bricks_config()['dsa_icon_launcher_enabled'] ) ) {
			$module = sanitize_key( (string) ( $element->settings['dsaOpenModule'] ?? '' ) );
			$allowed = [ 'menu', 'search', 'profile', 'links', 'saved', 'cart', 'ai', 'theme' ];
			if ( in_array( $module, $allowed, true ) ) {
				$attributes['_root']['data-dsa-open-module'] = $module;
				$attributes['_root']['role'] = 'button';
				$attributes['_root']['tabindex'] = '0';
				$attributes['_root']['aria-label'] = sprintf( 'Open %s', ucfirst( $module ) );
			}
		}

		return $attributes;
	}

	public function add_dsa_icon_launcher_controls( array $controls ): array {
		if ( empty( $this->bricks_config()['dsa_icon_launcher_enabled'] ) ) return $controls;
		$controls['dsaLauncherSep'] = [
			'tab' => 'content',
			'type' => 'separator',
			'label' => esc_html__( 'Kiwe DSA', 'dsa' ),
		];
		$controls['dsaOpenModule'] = [
			'tab' => 'content',
			'type' => 'select',
			'label' => esc_html__( 'Open DSA screen', 'dsa' ),
			'placeholder' => esc_html__( 'No DSA action', 'dsa' ),
			'options' => [
				'menu' => esc_html__( 'Menu', 'dsa' ),
				'search' => esc_html__( 'Search', 'dsa' ),
				'profile' => esc_html__( 'Profile', 'dsa' ),
				'links' => esc_html__( 'Links', 'dsa' ),
				'saved' => esc_html__( 'Saved', 'dsa' ),
				'cart' => esc_html__( 'Cart', 'dsa' ),
				'ai' => esc_html__( 'AI Assistant', 'dsa' ),
				'theme' => esc_html__( 'Light / dark mode', 'dsa' ),
			],
			'description' => esc_html__( 'Opens the selected registered Kiwe destination even when its dock icon is hidden.', 'dsa' ),
		];
		return $controls;
	}

	public function add_search_bridge_controls( array $controls ): array {
		$controls['dsaSearchBridgeSep'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Kiwe DSA Search', 'dsa' ),
		];
		$controls['dsaSearchBridge'] = [
			'tab'         => 'content',
			'type'        => 'checkbox',
			'label'       => esc_html__( 'Use as DSA Search bridge', 'dsa' ),
			'description' => esc_html__( 'Synchronizes this Filter Search and its targeted query with the DSA Search screen. Element and query IDs remain site-owned.', 'dsa' ),
		];

		return $controls;
	}

	public function observe_rendered_element( string $html, $element ): string {
		if ( ! is_object( $element ) || empty( $element->id ) || '' === trim( $html ) ) {
			return $html;
		}

		$id             = sanitize_key( (string) $element->id );
		$element_name   = ! empty( $element->name ) ? sanitize_key( (string) $element->name ) : 'bricks-element';
		$class_fallback = $this->classify_from_bricks_name( $element_name );
		$classification = $this->registry->classify_html( $html, $class_fallback );

		$this->registry->add(
			[
				'id'         => $id,
				'source'     => 'bricks',
				'bricksType' => $element_name,
				'type'       => $classification['type'],
				'label'      => $classification['label'],
				'selector'   => '[data-dsa-bricks-id="' . esc_attr( $id ) . '"]',
				'confidence' => $classification['confidence'],
				'postId'     => get_queried_object_id(),
				'editable'   => $classification['editable'],
				'aiVisible'  => $classification['aiVisible'],
			]
		);

		if ( 'product-add-to-cart' === $element_name && $this->add_to_cart_runtime_enabled( (array) ( $element->settings ?? [] ) ) ) {
			$this->enqueue_add_to_cart_adapter();
		}

		return $html;
	}

	public function add_mini_cart_controls( array $controls ): array {
		$config = $this->bricks_config();

		if ( empty( $config['mini_cart_adapter_enabled'] ) ) {
			return $controls;
		}

		if ( ! empty( $config['quantity_stepper_enabled'] ) ) {
			$controls['brxMcStepperSep'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'separator',
				'label' => esc_html__( 'Quantity Stepper', 'dsa' ),
			];
			$controls['brxMcStepperEnable'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'checkbox',
				'label' => esc_html__( 'Enable quantity stepper', 'dsa' ),
			];
			$controls['brxMcStepperAlign'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'label'    => esc_html__( 'Stepper alignment', 'dsa' ),
				'type'     => 'justify-content',
				'css'      => [ [ 'property' => 'justify-content', 'selector' => '.brxmc-stepper-wrap' ] ],
				'required' => [ 'brxMcStepperEnable', '!=', '' ],
			];

			foreach ( [ 'Minus' => [ '-', '.brxmc-btn.brxmc-minus' ], 'Plus' => [ '+', '.brxmc-btn.brxmc-plus' ] ] as $name => $meta ) {
				$key = 'Minus' === $name ? 'Minus' : 'Plus';
				$controls[ "brxMc{$key}Sep" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'type'     => 'separator',
					'label'    => sprintf( '%s %s', $meta[0], esc_html__( 'Button', 'dsa' ) ),
					'required' => [ 'brxMcStepperEnable', '!=', '' ],
				];
				$controls[ "brxMc{$key}Bg" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'label'    => sprintf( '%s %s', $meta[0], esc_html__( 'Background', 'dsa' ) ),
					'type'     => 'color',
					'css'      => [ [ 'property' => 'background-color', 'selector' => $meta[1] ] ],
					'required' => [ 'brxMcStepperEnable', '!=', '' ],
				];
				$controls[ "brxMc{$key}Padding" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'label'    => sprintf( '%s %s', $meta[0], esc_html__( 'Padding', 'dsa' ) ),
					'type'     => 'spacing',
					'css'      => [ [ 'property' => 'padding', 'selector' => $meta[1] ] ],
					'required' => [ 'brxMcStepperEnable', '!=', '' ],
				];
				$controls[ "brxMc{$key}Border" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'label'    => sprintf( '%s %s', $meta[0], esc_html__( 'Border & radius', 'dsa' ) ),
					'type'     => 'border',
					'css'      => [ [ 'property' => 'border', 'selector' => $meta[1] ] ],
					'required' => [ 'brxMcStepperEnable', '!=', '' ],
				];
				$controls[ "brxMc{$key}Typography" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'label'    => sprintf( '%s %s', $meta[0], esc_html__( 'Typography', 'dsa' ) ),
					'type'     => 'typography',
					'css'      => [ [ 'property' => 'font', 'selector' => '.brxmc-qty-wrap.quantity .brxmc-stepper ' . $meta[1] ] ],
					'required' => [ 'brxMcStepperEnable', '!=', '' ],
				];
			}

			$controls['brxMcNumSep'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'type'     => 'separator',
				'label'    => esc_html__( 'Quantity Number', 'dsa' ),
				'required' => [ 'brxMcStepperEnable', '!=', '' ],
			];
			$controls['brxMcNumBg'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'label'    => esc_html__( 'Number background', 'dsa' ),
				'type'     => 'color',
				'css'      => [ [ 'property' => 'background-color', 'selector' => '.brxmc-num' ] ],
				'required' => [ 'brxMcStepperEnable', '!=', '' ],
			];
			$controls['brxMcNumBorder'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'label'    => esc_html__( 'Number border & radius', 'dsa' ),
				'type'     => 'border',
				'css'      => [ [ 'property' => 'border', 'selector' => '.brxmc-num' ] ],
				'required' => [ 'brxMcStepperEnable', '!=', '' ],
			];
			$controls['brxMcNumTypography'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'label'    => esc_html__( 'Number typography', 'dsa' ),
				'type'     => 'typography',
				'css'      => [ [ 'property' => 'font', 'selector' => '.brxmc-qty-wrap.quantity .brxmc-stepper .brxmc-num' ] ],
				'required' => [ 'brxMcStepperEnable', '!=', '' ],
			];
			$controls['brxMcNumPadding'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'label'    => esc_html__( 'Number padding', 'dsa' ),
				'type'     => 'spacing',
				'css'      => [ [ 'property' => 'padding', 'selector' => '.brxmc-num' ] ],
				'required' => [ 'brxMcStepperEnable', '!=', '' ],
			];
			$controls['brxMcBtnGap'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'label'    => esc_html__( 'Gap between items', 'dsa' ),
				'type'     => 'number',
				'units'    => true,
				'unit'     => 'px',
				'min'      => 0,
				'css'      => [ [ 'property' => 'gap', 'selector' => '.brxmc-stepper' ] ],
				'required' => [ 'brxMcStepperEnable', '!=', '' ],
			];
		}

		if ( ! empty( $config['stock_badge_enabled'] ) ) {
			$controls['brxMcBadgeSep'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'separator',
				'label' => esc_html__( 'Stock Urgency Badge', 'dsa' ),
			];
			$controls['brxMcBadgeEnable'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'checkbox',
				'label'       => esc_html__( 'Enable stock badge', 'dsa' ),
				'description' => esc_html__( 'Text and thresholds are configured in Kiwe > WooCommerce.', 'dsa' ),
			];

			foreach ( [ 'Alert' => '.brxmc-badge.brxmc-badge-alert', 'Urgent' => '.brxmc-badge.brxmc-badge-urgent' ] as $label => $selector ) {
				$key = 'Alert' === $label ? '1' : '2';
				$controls[ "brxMcBadge{$key}Sep" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'type'     => 'separator',
					'label'    => sprintf( '%s %s', esc_html__( 'Badge', 'dsa' ), $label ),
					'required' => [ 'brxMcBadgeEnable', '!=', '' ],
				];
				$controls[ "brxMcBadge{$key}Bg" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'label'    => esc_html__( 'Background', 'dsa' ),
					'type'     => 'color',
					'css'      => [ [ 'property' => 'background-color', 'selector' => $selector ] ],
					'required' => [ 'brxMcBadgeEnable', '!=', '' ],
				];
				$controls[ "brxMcBadge{$key}Typography" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'label'    => esc_html__( 'Typography', 'dsa' ),
					'type'     => 'typography',
					'css'      => [ [ 'property' => 'font', 'selector' => $selector ] ],
					'required' => [ 'brxMcBadgeEnable', '!=', '' ],
				];
				$controls[ "brxMcBadge{$key}Border" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'label'    => esc_html__( 'Border & radius', 'dsa' ),
					'type'     => 'border',
					'css'      => [ [ 'property' => 'border', 'selector' => $selector ] ],
					'required' => [ 'brxMcBadgeEnable', '!=', '' ],
				];
				$controls[ "brxMcBadge{$key}Padding" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'label'    => esc_html__( 'Padding', 'dsa' ),
					'type'     => 'spacing',
					'css'      => [ [ 'property' => 'padding', 'selector' => $selector ] ],
					'required' => [ 'brxMcBadgeEnable', '!=', '' ],
				];
				$controls[ "brxMcBadge{$key}Margin" ] = [
					'tab'      => 'content',
					'group'    => 'cartDetails',
					'label'    => esc_html__( 'Margin', 'dsa' ),
					'type'     => 'spacing',
					'css'      => [ [ 'property' => 'margin', 'selector' => $selector ] ],
					'required' => [ 'brxMcBadgeEnable', '!=', '' ],
				];
			}
		}

		if ( ! empty( $config['linked_products_controls_enabled'] ) ) {
			$controls['brxUsCsSep'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'separator',
				'label' => esc_html__( 'Frequently Bought Together', 'dsa' ),
			];
			$controls['brxUsCsInfo'] = [
				'tab'     => 'content',
				'group'   => 'cartDetails',
				'type'    => 'info',
				'content' => esc_html__( 'Title, max products, Cart Picks, generation, and analytics are configured in Kiwe > WooCommerce. Bricks controls below style the mini-cart cards and offer banners.', 'dsa' ),
			];
			$controls['brxUsPicksInfo'] = [
				'tab'     => 'content',
				'group'   => 'cartDetails',
				'type'    => 'info',
				'content' => esc_html__( 'Cart Picks appear as pending, ready, and applied offer banners when Kiwe cart upsells are enabled and a product has a searched upsell product selected.', 'dsa' ),
			];
			$controls['brxUsCsEnable'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'checkbox',
				'label'       => esc_html__( 'Show FBT rail', 'dsa' ),
				'placeholder' => esc_html__( 'Enable', 'dsa' ),
			];
			$controls['brxUsCsTitle'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'text',
				'label'       => esc_html__( 'Section title', 'dsa' ),
				'placeholder' => esc_html__( 'Frequently Bought Together', 'dsa' ),
			];
			$controls['brxUsCsMax'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'number',
				'label'       => esc_html__( 'Max products shown', 'dsa' ),
				'placeholder' => '6',
				'min'         => 1,
				'max'         => 12,
			];
			$controls['brxUsCsTitleTypo'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'typography',
				'label' => esc_html__( 'Title typography', 'dsa' ),
				'css'   => [ [ 'property' => 'font', 'selector' => '.brxus-section-title' ] ],
			];
			$controls['brxUsCsGap'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'number',
				'label' => esc_html__( 'Gap', 'dsa' ),
				'units' => true,
				'css'   => [ [ 'property' => 'row-gap', 'selector' => '.brxus-cross-sells' ] ],
			];
			$controls['brxUsCsImgRadius'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'border',
				'label' => esc_html__( 'Image border radius', 'dsa' ),
				'css'   => [ [ 'property' => 'border', 'selector' => '.brxus-product-img img' ] ],
			];
			$controls['brxUsCsNameTypo'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'typography',
				'label' => esc_html__( 'Product name typography', 'dsa' ),
				'css'   => [ [ 'property' => 'font', 'selector' => '.brxus-product-name' ] ],
			];
			$controls['brxUsCsPriceTypo'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'typography',
				'label' => esc_html__( 'Price typography', 'dsa' ),
				'css'   => [ [ 'property' => 'font', 'selector' => '.brxus-product-price' ] ],
			];
			$controls['brxUsCsBtnBg'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'color',
				'label' => esc_html__( 'Button background', 'dsa' ),
				'css'   => [ [ 'property' => 'background-color', 'selector' => '.brxus-cs-btn' ] ],
			];
			$controls['brxUsCsBtnTypo'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'typography',
				'label' => esc_html__( 'Button typography', 'dsa' ),
				'css'   => [ [ 'property' => 'font', 'selector' => '.brxus-cs-btn' ] ],
			];
			$controls['brxUsCsBtnRadius'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'border',
				'label' => esc_html__( 'Button radius', 'dsa' ),
				'css'   => [ [ 'property' => 'border', 'selector' => '.brxus-cs-btn' ] ],
			];
			$controls['brxUsCsBtnPadding'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'spacing',
				'label' => esc_html__( 'Button padding', 'dsa' ),
				'css'   => [ [ 'property' => 'padding', 'selector' => '.brxus-cs-btn' ] ],
			];
			$controls['brxUsCsCardBg'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'color',
				'label' => esc_html__( 'Card background', 'dsa' ),
				'css'   => [ [ 'property' => 'background-color', 'selector' => '.brxus-product-card' ] ],
			];
			$controls['brxUsCsCardRadius'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'border',
				'label' => esc_html__( 'Card radius', 'dsa' ),
				'css'   => [ [ 'property' => 'border', 'selector' => '.brxus-product-card' ] ],
			];
			$controls['brxUsCsCardPadding'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'spacing',
				'label' => esc_html__( 'Card padding', 'dsa' ),
				'css'   => [ [ 'property' => 'padding', 'selector' => '.brxus-product-card' ] ],
			];
			$controls['brxUsCsCardWidth'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'number',
				'label' => esc_html__( 'Card width', 'dsa' ),
				'units' => true,
				'css'   => [ [ 'property' => 'flex-basis', 'selector' => '.brxus-product-card' ] ],
			];
			$controls['brxUsUsSep'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'separator',
				'label' => esc_html__( 'Upsell Offer Banner', 'dsa' ),
			];
			$controls['brxUsUsPendingSep'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'separator',
				'label' => esc_html__( 'Pending Offer State', 'dsa' ),
			];
			$controls['brxUsUsPendingBadgeText'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'text',
				'label'       => esc_html__( 'Pending badge text', 'dsa' ),
				'placeholder' => esc_html__( 'Offer', 'dsa' ),
			];
			$controls['brxUsUsPendingButtonText'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'text',
				'label'       => esc_html__( 'Pending button text', 'dsa' ),
				'placeholder' => esc_html__( 'Add & Save', 'dsa' ),
			];
			$controls['brxUsUsPendingBannerBg'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'color',
				'label' => esc_html__( 'Pending banner background', 'dsa' ),
				'css'   => [ [ 'property' => 'background-color', 'selector' => '.brxus-offer--pending' ] ],
			];
			$controls['brxUsUsEligibleSep'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'separator',
				'label' => esc_html__( 'Eligible Offer State', 'dsa' ),
			];
			$controls['brxUsUsEligibleBadgeText'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'text',
				'label'       => esc_html__( 'Eligible badge text', 'dsa' ),
				'placeholder' => esc_html__( 'Ready', 'dsa' ),
			];
			$controls['brxUsUsEligibleButtonText'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'text',
				'label'       => esc_html__( 'Eligible button text', 'dsa' ),
				'placeholder' => esc_html__( 'Apply', 'dsa' ),
			];
			$controls['brxUsUsEligibleBannerBg'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'color',
				'label' => esc_html__( 'Eligible banner background', 'dsa' ),
				'css'   => [ [ 'property' => 'background-color', 'selector' => '.brxus-offer--eligible' ] ],
			];
			$controls['brxUsUsAppliedSep'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'separator',
				'label' => esc_html__( 'Applied Offer State', 'dsa' ),
			];
			$controls['brxUsUsAppliedBadgeText'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'text',
				'label'       => esc_html__( 'Applied badge text', 'dsa' ),
				'placeholder' => esc_html__( 'Applied', 'dsa' ),
			];
			$controls['brxUsUsAppliedButtonText'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'text',
				'label'       => esc_html__( 'Applied button text', 'dsa' ),
				'placeholder' => esc_html__( 'Applied', 'dsa' ),
			];
			$controls['brxUsUsBadgeTypo'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'typography',
				'label' => esc_html__( 'Badge typography', 'dsa' ),
				'css'   => [ [ 'property' => 'font', 'selector' => '.brxus-offer-badge' ] ],
			];
			$controls['brxUsUsBadgeBg'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'color',
				'label' => esc_html__( 'Badge background', 'dsa' ),
				'css'   => [ [ 'property' => 'background-color', 'selector' => '.brxus-offer-badge' ] ],
			];
			$controls['brxUsUsBadgeRadius'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'border',
				'label' => esc_html__( 'Badge radius', 'dsa' ),
				'css'   => [ [ 'property' => 'border', 'selector' => '.brxus-offer-badge' ] ],
			];
			$controls['brxUsUsBadgePadding'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'spacing',
				'label' => esc_html__( 'Badge padding', 'dsa' ),
				'css'   => [ [ 'property' => 'padding', 'selector' => '.brxus-offer-badge' ] ],
			];
			$controls['brxUsUsHeadlineTypo'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'typography',
				'label' => esc_html__( 'Headline typography', 'dsa' ),
				'css'   => [ [ 'property' => 'font', 'selector' => '.brxus-offer-headline' ] ],
			];
			$controls['brxUsUsSubTypo'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'typography',
				'label' => esc_html__( 'Sub-text typography', 'dsa' ),
				'css'   => [ [ 'property' => 'font', 'selector' => '.brxus-offer-sub' ] ],
			];
			$controls['brxUsUsBtnTypo'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'typography',
				'label' => esc_html__( 'Offer button typography', 'dsa' ),
				'css'   => [ [ 'property' => 'font', 'selector' => '.brxus-add-btn' ] ],
			];
			$controls['brxUsUsBtnBg'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'color',
				'label' => esc_html__( 'Offer button background', 'dsa' ),
				'css'   => [ [ 'property' => 'background-color', 'selector' => '.brxus-add-btn' ] ],
			];
			$controls['brxUsUsBtnRadius'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'border',
				'label' => esc_html__( 'Offer button radius', 'dsa' ),
				'css'   => [ [ 'property' => 'border', 'selector' => '.brxus-add-btn' ] ],
			];
			$controls['brxUsUsBtnPadding'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'spacing',
				'label' => esc_html__( 'Offer button padding', 'dsa' ),
				'css'   => [ [ 'property' => 'padding', 'selector' => '.brxus-add-btn' ] ],
			];
			$controls['brxUsUsBtnWidth'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'number',
				'label' => esc_html__( 'Offer button width', 'dsa' ),
				'units' => true,
				'css'   => [ [ 'property' => 'width', 'selector' => '.brxus-add-btn' ] ],
			];
			$controls['brxUsUsBannerGap'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'number',
				'label' => esc_html__( 'Banner gap', 'dsa' ),
				'units' => true,
				'css'   => [ [ 'property' => 'column-gap', 'selector' => '.brxus-offer-banner' ] ],
			];
			$controls['brxUsUsBannerBg'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'color',
				'label' => esc_html__( 'Banner background', 'dsa' ),
				'css'   => [ [ 'property' => 'background-color', 'selector' => '.brxus-offer-banner' ] ],
			];
			$controls['brxUsUsBannerRadius'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'border',
				'label' => esc_html__( 'Banner radius', 'dsa' ),
				'css'   => [ [ 'property' => 'border', 'selector' => '.brxus-offer-banner' ] ],
			];
			$controls['brxUsUsBannerPadding'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'spacing',
				'label' => esc_html__( 'Banner padding', 'dsa' ),
				'css'   => [ [ 'property' => 'padding', 'selector' => '.brxus-offer-banner' ] ],
			];
			$controls['brxUsUsAppliedBg'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'color',
				'label' => esc_html__( 'Applied banner background', 'dsa' ),
				'css'   => [ [ 'property' => 'background-color', 'selector' => '.brxus-offer--applied' ] ],
			];
			$controls['brxUsTrSep'] = [
				'tab'   => 'content',
				'group' => 'cartDetails',
				'type'  => 'separator',
				'label' => esc_html__( 'Total After Discount', 'dsa' ),
			];
			$controls['brxUsTrEnable'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'checkbox',
				'label'       => esc_html__( 'Show total after discount row', 'dsa' ),
				'placeholder' => esc_html__( 'Enable', 'dsa' ),
			];
			$controls['brxUsTrLabelText'] = [
				'tab'         => 'content',
				'group'       => 'cartDetails',
				'type'        => 'text',
				'label'       => esc_html__( 'Total row label', 'dsa' ),
				'placeholder' => esc_html__( 'Total after discount', 'dsa' ),
				'required'    => [ 'brxUsTrEnable', '!=', '' ],
			];
			$controls['brxUsTrLabelTypo'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'type'     => 'typography',
				'label'    => esc_html__( 'Label typography', 'dsa' ),
				'css'      => [ [ 'property' => 'font', 'selector' => '.brxus-total-after-label' ] ],
				'required' => [ 'brxUsTrEnable', '!=', '' ],
			];
			$controls['brxUsTrValueTypo'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'type'     => 'typography',
				'label'    => esc_html__( 'Value typography', 'dsa' ),
				'css'      => [ [ 'property' => 'font', 'selector' => '.brxus-total-after-value' ] ],
				'required' => [ 'brxUsTrEnable', '!=', '' ],
			];
			$controls['brxUsTrBg'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'type'     => 'color',
				'label'    => esc_html__( 'Row background', 'dsa' ),
				'css'      => [ [ 'property' => 'background-color', 'selector' => '.brxus-total-after-row' ] ],
				'required' => [ 'brxUsTrEnable', '!=', '' ],
			];
			$controls['brxUsTrBorder'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'type'     => 'border',
				'label'    => esc_html__( 'Row border/radius', 'dsa' ),
				'css'      => [ [ 'property' => 'border', 'selector' => '.brxus-total-after-row' ] ],
				'required' => [ 'brxUsTrEnable', '!=', '' ],
			];
			$controls['brxUsTrPadding'] = [
				'tab'      => 'content',
				'group'    => 'cartDetails',
				'type'     => 'spacing',
				'label'    => esc_html__( 'Row padding', 'dsa' ),
				'css'      => [ [ 'property' => 'padding', 'selector' => '.brxus-total-after-row' ] ],
				'required' => [ 'brxUsTrEnable', '!=', '' ],
			];
		}

		return $controls;
	}

	public function add_add_to_cart_control_group( array $groups ): array {
		if ( empty( $this->bricks_config()['add_to_cart_enhancer_enabled'] ) ) {
			return $groups;
		}

		$groups[ self::ATC_GROUP ] = [
			'title' => esc_html__( 'Kiwe Add To Cart', 'dsa' ),
			'tab'   => 'content',
		];

		return $groups;
	}

	public function add_add_to_cart_controls( array $controls ): array {
		if ( empty( $this->bricks_config()['add_to_cart_enhancer_enabled'] ) ) {
			return $controls;
		}

		$group = self::ATC_GROUP;
		$controls['brxAtcSeparator'] = [ 'tab' => 'content', 'group' => $group, 'type' => 'separator', 'label' => esc_html__( 'Behaviour Toggles', 'dsa' ) ];
		$controls['brxQtyAjax'] = [ 'tab' => 'content', 'group' => $group, 'label' => esc_html__( 'Quantity Button Ajax', 'dsa' ), 'type' => 'checkbox', 'inline' => true, 'placeholder' => esc_html__( 'Enable', 'dsa' ) ];
		$controls['brxHideQty'] = [ 'tab' => 'content', 'group' => $group, 'label' => esc_html__( 'Hide Quantity Button', 'dsa' ), 'type' => 'checkbox', 'inline' => true, 'placeholder' => esc_html__( 'Enable', 'dsa' ) ];
		$controls['brxSwapAtc'] = [ 'tab' => 'content', 'group' => $group, 'label' => esc_html__( 'Hide Add to Cart on Click', 'dsa' ), 'type' => 'checkbox', 'inline' => true, 'placeholder' => esc_html__( 'Enable', 'dsa' ) ];
		$controls['brxPlusOnly'] = [ 'tab' => 'content', 'group' => $group, 'label' => esc_html__( 'Show Only + Icon', 'dsa' ), 'type' => 'checkbox', 'inline' => true, 'placeholder' => esc_html__( 'Enable', 'dsa' ) ];
		$controls['brxPlusAdd'] = [ 'tab' => 'content', 'group' => $group, 'label' => esc_html__( 'Plus Only Never Expand', 'dsa' ), 'type' => 'checkbox', 'inline' => true, 'placeholder' => esc_html__( 'Enable', 'dsa' ) ];
		$controls['brxSwapDuration'] = [ 'tab' => 'style', 'group' => $group, 'label' => esc_html__( 'Swap Duration', 'dsa' ), 'type' => 'number', 'units' => true, 'placeholder' => '0ms', 'css' => [ [ 'property' => '--brx-atc-swap-duration', 'selector' => '' ] ] ];
		$controls['brxSwapEasing'] = [ 'tab' => 'style', 'group' => $group, 'label' => esc_html__( 'Swap Easing', 'dsa' ), 'type' => 'select', 'options' => [ 'ease' => 'ease', 'ease-in' => 'ease-in', 'ease-out' => 'ease-out', 'ease-in-out' => 'ease-in-out', 'linear' => 'linear' ], 'inline' => true, 'placeholder' => 'ease', 'css' => [ [ 'property' => '--brx-atc-swap-easing', 'selector' => '' ] ] ];
		$controls['brxQtyStyleSep'] = [ 'tab' => 'style', 'group' => $group, 'type' => 'separator', 'label' => esc_html__( 'Quantity Buttons Styling', 'dsa' ) ];
		$controls['brxQtyWrapperGap'] = [ 'tab' => 'style', 'group' => $group, 'label' => esc_html__( 'Wrapper gap', 'dsa' ), 'type' => 'number', 'units' => true, 'css' => [ [ 'property' => 'gap', 'selector' => '.cart .quantity' ] ] ];

		foreach ( [ 'Plus' => [ '+', '.quantity .plus' ], 'Minus' => [ '-', '.quantity .minus' ] ] as $name => $meta ) {
			$controls[ "brxQty{$name}Sep" ] = [ 'tab' => 'style', 'group' => $group, 'type' => 'separator', 'label' => sprintf( '%s %s', $meta[0], esc_html__( 'Button', 'dsa' ) ) ];
			$controls[ "brxQty{$name}Typo" ] = [ 'tab' => 'style', 'group' => $group, 'label' => sprintf( '%s %s', $meta[0], esc_html__( 'Typography', 'dsa' ) ), 'type' => 'typography', 'css' => [ [ 'property' => 'font', 'selector' => $meta[1] ] ] ];
			$controls[ "brxQty{$name}Bg" ] = [ 'tab' => 'style', 'group' => $group, 'label' => sprintf( '%s %s', $meta[0], esc_html__( 'Background', 'dsa' ) ), 'type' => 'color', 'css' => [ [ 'property' => 'background-color', 'selector' => $meta[1] ] ] ];
			$controls[ "brxQty{$name}Border" ] = [ 'tab' => 'style', 'group' => $group, 'label' => sprintf( '%s %s', $meta[0], esc_html__( 'Border/Radius', 'dsa' ) ), 'type' => 'border', 'css' => [ [ 'property' => 'border', 'selector' => $meta[1] ] ] ];
			$controls[ "brxQty{$name}Padding" ] = [ 'tab' => 'style', 'group' => $group, 'label' => sprintf( '%s %s', $meta[0], esc_html__( 'Padding', 'dsa' ) ), 'type' => 'spacing', 'css' => [ [ 'property' => 'padding', 'selector' => $meta[1] ] ] ];
			$controls[ "brxQty{$name}Size" ] = [ 'tab' => 'style', 'group' => $group, 'label' => sprintf( '%s %s', $meta[0], esc_html__( 'Width', 'dsa' ) ), 'type' => 'number', 'units' => true, 'css' => [ [ 'property' => 'width', 'selector' => $meta[1] ] ] ];
		}

		$controls['brxQtyInputSep'] = [ 'tab' => 'style', 'group' => $group, 'type' => 'separator', 'label' => esc_html__( 'Qty Input', 'dsa' ) ];
		$controls['brxQtyInputTypo'] = [ 'tab' => 'style', 'group' => $group, 'label' => esc_html__( 'Input typography', 'dsa' ), 'type' => 'typography', 'css' => [ [ 'property' => 'font', 'selector' => '.quantity input.qty' ] ] ];
		$controls['brxQtyInputBg'] = [ 'tab' => 'style', 'group' => $group, 'label' => esc_html__( 'Input background', 'dsa' ), 'type' => 'color', 'css' => [ [ 'property' => 'background-color', 'selector' => '.quantity input.qty' ] ] ];
		$controls['brxQtyInputBorder'] = [ 'tab' => 'style', 'group' => $group, 'label' => esc_html__( 'Input border/radius', 'dsa' ), 'type' => 'border', 'css' => [ [ 'property' => 'border', 'selector' => '.quantity input.qty' ] ] ];
		$controls['brxQtyInputPadding'] = [ 'tab' => 'style', 'group' => $group, 'label' => esc_html__( 'Input padding', 'dsa' ), 'type' => 'spacing', 'css' => [ [ 'property' => 'padding', 'selector' => '.quantity input.qty' ] ] ];
		$controls['brxQtyInputWidth'] = [ 'tab' => 'style', 'group' => $group, 'label' => esc_html__( 'Input width', 'dsa' ), 'type' => 'number', 'units' => true, 'css' => [ [ 'property' => 'width', 'selector' => '.quantity input.qty' ] ] ];

		return $controls;
	}

	public function add_linked_products_control_group( array $groups ): array {
		if ( empty( $this->bricks_config()['linked_products_controls_enabled'] ) ) {
			return $groups;
		}

		$groups[ self::LINKED_PRODUCTS_GROUP ] = [
			'title' => esc_html__( 'Kiwe Linked Products', 'dsa' ),
			'tab'   => 'content',
		];

		return $groups;
	}

	public function add_linked_products_controls( array $controls ): array {
		if ( empty( $this->bricks_config()['linked_products_controls_enabled'] ) ) {
			return $controls;
		}

		$group = self::LINKED_PRODUCTS_GROUP;
		$controls['brxKiweLinkedInfo'] = [
			'tab'     => 'content',
			'group'   => $group,
			'type'    => 'info',
			'content' => esc_html__( 'Kiwe stores cross-sells as native WooCommerce linked products. Style this element in Bricks; manage generation and bestseller analytics in Kiwe > WooCommerce.', 'dsa' ),
		];
		$controls['brxKiweLinkedProductsMode'] = [
			'tab'         => 'content',
			'group'       => $group,
			'type'        => 'select',
			'label'       => esc_html__( 'Kiwe source preset', 'dsa' ),
			'options'     => [
				''                 => esc_html__( 'Use Bricks setting', 'dsa' ),
				'cross_sells'      => esc_html__( 'Current product cross-sells', 'dsa' ),
				'cart_cross_sells' => esc_html__( 'Cart cross-sells', 'dsa' ),
				'upsells'          => esc_html__( 'Woo upsells', 'dsa' ),
			],
			'description' => esc_html__( 'Maps to Bricks 2.3.7 product-upsells source modes. Bestseller categories can be selected in normal product/category queries.', 'dsa' ),
		];

		return $controls;
	}

	public function bridge_element_settings( $settings, $element ) {
		if ( ! is_object( $element ) || empty( $element->name ) ) {
			return $settings;
		}

		$settings = is_array( $settings ) ? $settings : [];

		if ( 'product-upsells' === $element->name && ! empty( $this->bricks_config()['linked_products_controls_enabled'] ) ) {
			$mode = sanitize_key( $settings['brxKiweLinkedProductsMode'] ?? '' );

			if ( in_array( $mode, [ 'cross_sells', 'cart_cross_sells', 'upsells' ], true ) ) {
				$settings['type'] = $mode;
			}

			return $settings;
		}

		if ( 'woocommerce-mini-cart' !== $element->name || empty( $this->bricks_config()['mini_cart_adapter_enabled'] ) ) {
			return $settings;
		}

		$persist = [
			'brxMcStepperEnable' => ! empty( $settings['brxMcStepperEnable'] ),
			'brxMcBadgeEnable'   => ! empty( $settings['brxMcBadgeEnable'] ),
			'brxUsCsEnable'      => ! array_key_exists( 'brxUsCsEnable', $settings ) || ! empty( $settings['brxUsCsEnable'] ),
			'brxUsTrEnable'      => ! empty( $settings['brxUsTrEnable'] ),
		];

		foreach ( [
			'brxUsCsTitle',
			'brxUsCsMax',
			'brxUsUsPendingBadgeText',
			'brxUsUsPendingButtonText',
			'brxUsUsEligibleBadgeText',
			'brxUsUsEligibleButtonText',
			'brxUsUsAppliedBadgeText',
			'brxUsUsAppliedButtonText',
			'brxUsTrLabelText',
		] as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$persist[ $key ] = is_bool( $settings[ $key ] ) ? (bool) $settings[ $key ] : sanitize_text_field( (string) $settings[ $key ] );
			}
		}

		$this->mini_cart_context = $persist;
		$stored = get_option( self::MINI_CART_SETTINGS_OPTION, [] );

		if ( $stored !== $persist ) {
			update_option( self::MINI_CART_SETTINGS_OPTION, $persist, false );
		}

		if ( method_exists( $element, 'set_attribute' ) ) {
			$element->set_attribute( '_root', 'data-brxmc', wp_json_encode( $persist ) );
		}

		return $settings;
	}

	public function add_add_to_cart_attributes( array $attributes, $element ): array {
		if ( empty( $this->bricks_config()['add_to_cart_enhancer_enabled'] ) || ! is_object( $element ) || ( $element->name ?? '' ) !== 'product-add-to-cart' ) {
			return $attributes;
		}

		$settings = is_array( $element->settings ?? null ) ? $element->settings : [];
		$flags = [
			'data-brx-t1' => ! empty( $settings['brxQtyAjax'] ),
			'data-brx-t2' => ! empty( $settings['brxHideQty'] ),
			'data-brx-t3' => ! empty( $settings['brxSwapAtc'] ),
			'data-brx-t4' => ! empty( $settings['brxPlusOnly'] ),
			'data-brx-t5' => ! empty( $settings['brxPlusAdd'] ),
		];

		if ( ! in_array( true, $flags, true ) ) {
			return $attributes;
		}

		foreach ( $flags as $attribute => $enabled ) {
			if ( $enabled ) {
				$attributes[ $attribute ] = 'true';
			}
		}

		$product_id = $this->product_id_from_element( $element );
		$cart_item = $product_id ? $this->cart_item_for_product( $product_id ) : [];

		if ( $product_id ) {
			$attributes['data-dsa-product-id'] = (string) $product_id;
		}

		if ( $cart_item ) {
			$attributes['data-brx-in-cart'] = 'true';
			$attributes['data-brx-cart-key'] = (string) $cart_item['key'];
			$attributes['data-brx-cart-qty'] = (string) $cart_item['qty'];
		}

		return $attributes;
	}

	public function restore_mini_cart_context(): void {
		if ( $this->mini_cart_context ) {
			return;
		}

		$stored = get_option( self::MINI_CART_SETTINGS_OPTION, [] );
		$this->mini_cart_context = is_array( $stored ) ? $stored : [];
	}

	public function render_mini_cart_quantity( $output, $cart_item, $cart_item_key ): string {
		$config = $this->bricks_config();

		if ( empty( $config['mini_cart_adapter_enabled'] ) || ! is_array( $cart_item ) ) {
			return (string) $output;
		}

		$context = $this->mini_cart_settings();
		$commerce = $this->commerce_config();
		$stepper_on = ! empty( $config['quantity_stepper_enabled'] ) && ! empty( $context['brxMcStepperEnable'] );
		$badge_on = ! empty( $config['stock_badge_enabled'] ) && ! empty( $commerce['cart_badges_enabled'] ) && ! empty( $context['brxMcBadgeEnable'] );

		if ( ! $stepper_on && ! $badge_on ) {
			return (string) $output;
		}

		$product = $cart_item['data'] ?? null;

		if ( ! $product || ! is_object( $product ) ) {
			return (string) $output;
		}

		$quantity = (int) ( $cart_item['quantity'] ?? 1 );
		$badge_html = $badge_on ? $this->stock_badge_html( $product ) : '';

		if ( ! $stepper_on ) {
			return (string) $output . $badge_html;
		}

		$max = $this->product_max_quantity( $product, $quantity );
		$price = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_product_price( $product ) : '';

		return sprintf(
			'<span class="quantity brxmc-qty-wrap"><span class="brxmc-price">%1$s</span><span class="brxmc-stepper-wrap"><span class="brxmc-stepper" data-dsa-keep-open><button class="brxmc-btn brxmc-minus" type="button" data-dsa-bricks-cart-quantity="%2$s" data-dsa-bricks-cart-product="%12$d" data-dsa-bricks-cart-variation="%13$d" data-dsa-bricks-cart-next="%3$d" aria-label="%4$s" %5$s>&minus;</button><span class="brxmc-num">%6$d</span><button class="brxmc-btn brxmc-plus" type="button" data-dsa-bricks-cart-quantity="%2$s" data-dsa-bricks-cart-product="%12$d" data-dsa-bricks-cart-variation="%13$d" data-dsa-bricks-cart-next="%7$d" data-max="%8$d" aria-label="%9$s" %10$s>+</button></span></span></span>%11$s',
			wp_kses_post( $price ),
			esc_attr( (string) $cart_item_key ),
			max( 0, $quantity - 1 ),
			esc_attr__( 'Decrease quantity', 'dsa' ),
			$quantity <= 1 ? 'disabled' : '',
			$quantity,
			min( $max, $quantity + 1 ),
			$max,
			esc_attr__( 'Increase quantity', 'dsa' ),
			$quantity >= $max ? 'disabled' : '',
			$badge_html,
			absint( $cart_item['product_id'] ?? 0 ),
			absint( $cart_item['variation_id'] ?? 0 )
		);
	}

	public function render_mini_cart_recommendations(): void {
		$config = $this->bricks_config();

		if ( empty( $config['mini_cart_adapter_enabled'] ) || empty( $config['linked_products_controls_enabled'] ) ) {
			return;
		}

		$commerce = $this->commerce_config();

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return;
		}

		$fbt_products = [];
		$cart_picks = [];

		if ( ! empty( $commerce['fbt_enabled'] ) && $this->linked_products ) {
			$max = (int) ( $commerce['fbt_max_products'] ?? 6 );
			$max = max( 1, min( 12, $max ) );

			try {
				$fbt_products = $this->linked_products->cart_recommendations( $max );
			} catch ( \Throwable $error ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'DSA Bricks mini-cart recommendations skipped: ' . $error->getMessage() );
				}
			}
		}

		if ( ! empty( $commerce['upsell_banner_enabled'] ) ) {
			try {
				if ( $this->store_analytics ) {
					$cart_picks = $this->store_analytics->cart_upsell_offers( 3 );
				}
			} catch ( \Throwable $error ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'DSA Bricks mini-cart cart picks skipped: ' . $error->getMessage() );
				}
			}
		}

		if ( empty( $fbt_products ) && empty( $cart_picks ) ) {
			return;
		}

		$context = $this->mini_cart_settings();
		$fbt_enabled = ! isset( $context['brxUsCsEnable'] ) || ! empty( $context['brxUsCsEnable'] );
		$title = ! empty( $context['brxUsCsTitle'] ) ? (string) $context['brxUsCsTitle'] : ( $commerce['fbt_title'] ?? __( 'Frequently Bought Together', 'dsa' ) );
		if ( ! empty( $context['brxUsCsMax'] ) && is_array( $fbt_products ) ) {
			$fbt_products = array_slice( $fbt_products, 0, max( 1, min( 12, absint( $context['brxUsCsMax'] ) ) ) );
		}

		if ( $fbt_enabled ) {
			$this->render_mini_cart_product_rail( $title, $fbt_products, 'brxus-fbt-rail' );
		}

		$this->render_mini_cart_offer_banners( $cart_picks );
		$this->render_mini_cart_total_after_discount();
	}

	private function render_mini_cart_product_rail( string $title, array $products, string $extra_class ): void {
		if ( empty( $products ) ) {
			return;
		}
		?>
		<div class="brxus-cross-sells <?php echo esc_attr( $extra_class ); ?>" data-dsa-keep-open>
			<p class="brxus-section-title"><?php echo esc_html( $title ); ?></p>
			<div class="brxus-products-scroll">
				<?php foreach ( $products as $product ) : ?>
					<?php
					$action = sanitize_key( (string) ( $product['actionSafe'] ?? ( ! empty( $product['addable'] ) ? 'add_to_cart' : 'view_product' ) ) );
					$action_label = sanitize_text_field( (string) ( $product['actionLabel'] ?? ( 'claim_discount' === $action ? __( 'Apply', 'dsa' ) : __( 'Add', 'dsa' ) ) ) );
					$state_label = sanitize_text_field( (string) ( $product['stateLabel'] ?? '' ) );
					?>
					<div class="brxus-product-card">
						<div class="brxus-product-img">
							<?php if ( ! empty( $product['image'] ) ) : ?>
								<img src="<?php echo esc_url( $product['image'] ); ?>" alt="">
							<?php endif; ?>
						</div>
						<div class="brxus-product-info">
							<span class="brxus-product-name"><?php echo esc_html( $product['title'] ?? __( 'Product', 'dsa' ) ); ?></span>
							<?php if ( ! empty( $product['isOnSale'] ) && ! empty( $product['salePrice'] ) && ! empty( $product['regularPrice'] ) ) : ?>
								<span class="brxus-product-price"><span><?php echo esc_html( $product['salePrice'] ); ?></span> <del><?php echo esc_html( $product['regularPrice'] ); ?></del></span>
							<?php elseif ( ! empty( $product['price'] ) ) : ?>
								<span class="brxus-product-price"><?php echo esc_html( $product['price'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $state_label ) : ?>
								<span class="brxus-product-state"><?php echo esc_html( $state_label ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( 'claim_discount' === $action ) : ?>
							<button class="brxus-cs-btn" type="button" data-dsa-bricks-cart-claim="<?php echo esc_attr( (string) ( $product['id'] ?? 0 ) ); ?>" data-dsa-bricks-cart-trigger="<?php echo esc_attr( (string) ( $product['triggerId'] ?? 0 ) ); ?>"><?php echo esc_html( $action_label ); ?></button>
						<?php elseif ( 'applied' === $action ) : ?>
							<button class="brxus-cs-btn" type="button" disabled><?php echo esc_html( $action_label ); ?></button>
						<?php elseif ( ! empty( $product['addable'] ) ) : ?>
							<button class="brxus-cs-btn brxus-cs-btn--plus" type="button" data-dsa-bricks-cart-add="<?php echo esc_attr( (string) ( $product['id'] ?? 0 ) ); ?>" data-dsa-bricks-cart-trigger="<?php echo esc_attr( (string) ( $product['triggerId'] ?? 0 ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Add %s to cart', 'dsa' ), $product['title'] ?? __( 'product', 'dsa' ) ) ); ?>">+</button>
						<?php elseif ( ! empty( $product['url'] ) ) : ?>
							<a class="brxus-cs-btn" href="<?php echo esc_url( $product['url'] ); ?>"><?php esc_html_e( 'View', 'dsa' ); ?></a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function render_mini_cart_offer_banners( array $offers ): void {
		if ( empty( $offers ) ) {
			return;
		}
		?>
		<div class="brxus-upsell-wrap" data-dsa-keep-open>
			<?php foreach ( $offers as $offer ) : ?>
				<?php
				$state        = sanitize_key( (string) ( $offer['state'] ?? 'pending' ) );
				$state        = in_array( $state, [ 'pending', 'eligible', 'applied' ], true ) ? $state : 'pending';
				$action       = sanitize_key( (string) ( $offer['actionSafe'] ?? ( 'eligible' === $state ? 'claim_discount' : ( 'applied' === $state ? 'applied' : 'add_to_cart' ) ) ) );
				$action_label = sanitize_text_field( (string) ( $offer['actionLabel'] ?? ( 'claim_discount' === $action ? __( 'Apply', 'dsa' ) : __( 'Add & Save', 'dsa' ) ) ) );
				$action_label = $this->cart_offer_button_label( $state, $action_label );
				$badge        = $this->cart_offer_badge( $state );
				$headline     = $this->cart_offer_headline( $offer, $state );
				$subtext      = $this->cart_offer_subtext( $offer, $state );
				?>
				<div class="brxus-offer-banner brxus-offer--<?php echo esc_attr( $state ); ?>">
					<div class="brxus-offer-copy">
						<span class="brxus-offer-badge"><?php echo esc_html( $badge ); ?></span>
						<span class="brxus-offer-headline"><?php echo wp_kses_post( $headline ); ?></span>
						<?php if ( '' !== $subtext ) : ?>
							<span class="brxus-offer-sub"><?php echo esc_html( $subtext ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( 'claim_discount' === $action ) : ?>
						<button class="brxus-add-btn brxus-offer-action" type="button" data-dsa-bricks-cart-claim="<?php echo esc_attr( (string) ( $offer['id'] ?? 0 ) ); ?>" data-dsa-bricks-cart-trigger="<?php echo esc_attr( (string) ( $offer['triggerId'] ?? 0 ) ); ?>"><?php echo esc_html( $action_label ); ?></button>
					<?php elseif ( 'applied' === $action ) : ?>
						<button class="brxus-add-btn brxus-offer-action" type="button" disabled><?php echo esc_html( $action_label ?: __( 'Applied', 'dsa' ) ); ?></button>
					<?php elseif ( ! empty( $offer['addable'] ) ) : ?>
						<button class="brxus-add-btn brxus-offer-action" type="button" data-dsa-bricks-cart-add="<?php echo esc_attr( (string) ( $offer['id'] ?? 0 ) ); ?>" data-dsa-bricks-cart-trigger="<?php echo esc_attr( (string) ( $offer['triggerId'] ?? 0 ) ); ?>"><?php echo esc_html( $action_label ); ?></button>
					<?php elseif ( ! empty( $offer['url'] ) ) : ?>
						<a class="brxus-add-btn brxus-offer-action" href="<?php echo esc_url( $offer['url'] ); ?>"><?php esc_html_e( 'View', 'dsa' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_mini_cart_total_after_discount(): void {
		$context = $this->mini_cart_settings();

		if ( empty( $context['brxUsTrEnable'] ) || ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return;
		}

		$total = (float) WC()->cart->get_total( 'edit' );

		if ( $total <= 0 ) {
			return;
		}

		$label = ! empty( $context['brxUsTrLabelText'] ) ? (string) $context['brxUsTrLabelText'] : __( 'Total after discount', 'dsa' );
		?>
		<div class="brxus-total-after-row" data-dsa-keep-open>
			<span class="brxus-total-after-label"><?php echo esc_html( $label ); ?></span>
			<span class="brxus-total-after-value"><?php echo wp_kses_post( wc_price( $total ) ); ?></span>
		</div>
		<?php
	}

	private function cart_offer_badge( string $state ): string {
		$context = $this->mini_cart_settings();

		switch ( $state ) {
			case 'eligible':
				return ! empty( $context['brxUsUsEligibleBadgeText'] ) ? (string) $context['brxUsUsEligibleBadgeText'] : __( 'Ready', 'dsa' );
			case 'applied':
				return ! empty( $context['brxUsUsAppliedBadgeText'] ) ? (string) $context['brxUsUsAppliedBadgeText'] : __( 'Applied', 'dsa' );
			case 'pending':
			default:
				return ! empty( $context['brxUsUsPendingBadgeText'] ) ? (string) $context['brxUsUsPendingBadgeText'] : __( 'Offer', 'dsa' );
		}
	}

	private function cart_offer_button_label( string $state, string $fallback ): string {
		$context = $this->mini_cart_settings();

		if ( 'eligible' === $state && ! empty( $context['brxUsUsEligibleButtonText'] ) ) {
			return (string) $context['brxUsUsEligibleButtonText'];
		}

		if ( 'applied' === $state && ! empty( $context['brxUsUsAppliedButtonText'] ) ) {
			return (string) $context['brxUsUsAppliedButtonText'];
		}

		if ( 'pending' === $state && ! empty( $context['brxUsUsPendingButtonText'] ) ) {
			return (string) $context['brxUsUsPendingButtonText'];
		}

		return $fallback;
	}

	private function cart_offer_headline( array $offer, string $state ): string {
		$title = sanitize_text_field( (string) ( $offer['title'] ?? __( 'this cart pick', 'dsa' ) ) );
		$discount = sanitize_text_field( (string) ( $offer['offerLabel'] ?? '' ) );
		$scope = sanitize_text_field( (string) ( $offer['discountScopeLabel'] ?? '' ) );
		$bonus = trim( $discount . ( $scope ? ' ' . $scope : '' ) );

		if ( 'applied' === $state ) {
			return '' !== $bonus
				? sprintf( esc_html__( '%s bonus applied.', 'dsa' ), esc_html( $discount ) )
				: esc_html__( 'Cart bonus applied.', 'dsa' );
		}

		if ( 'eligible' === $state ) {
			return '' !== $bonus
				? sprintf( esc_html__( 'Apply %s now.', 'dsa' ), esc_html( $bonus ) )
				: esc_html__( 'Your cart bonus is ready.', 'dsa' );
		}

		if ( '' === $bonus ) {
			return sprintf( esc_html__( 'Add %s to complete the set.', 'dsa' ), esc_html( $title ) );
		}

		return sprintf(
			/* translators: 1: product title, 2: discount label and scope. */
			esc_html__( 'Add %1$s & save %2$s.', 'dsa' ),
			'<strong>' . esc_html( $title ) . '</strong>',
			esc_html( $bonus )
		);
	}

	private function cart_offer_subtext( array $offer, string $state ): string {
		$trigger = sanitize_text_field( (string) ( $offer['triggerTitle'] ?? '' ) );

		if ( 'applied' === $state ) {
			return __( 'The discount is active in this cart.', 'dsa' );
		}

		if ( 'eligible' === $state ) {
			return $trigger ? sprintf( __( 'Because %s is already in your cart.', 'dsa' ), $trigger ) : __( 'Tap apply to claim it.', 'dsa' );
		}

		return $trigger ? sprintf( __( 'Because %s is in your cart.', 'dsa' ), $trigger ) : '';
	}

	public function enqueue_mini_cart_adapter(): void {
		$config = $this->bricks_config();

		if ( empty( $config['mini_cart_adapter_enabled'] ) || ( empty( $config['quantity_stepper_enabled'] ) && empty( $config['stock_badge_enabled'] ) && empty( $config['linked_products_controls_enabled'] ) ) ) {
			return;
		}

		wp_register_style( 'dsa-bricks-mini-cart', false, [], DSA_VERSION );
		wp_enqueue_style( 'dsa-bricks-mini-cart' );
		wp_add_inline_style( 'dsa-bricks-mini-cart', $this->mini_cart_css() );

		wp_register_script( 'dsa-bricks-mini-cart', false, [], DSA_VERSION, true );
		wp_enqueue_script( 'dsa-bricks-mini-cart' );
		wp_add_inline_script( 'dsa-bricks-mini-cart', 'window.DSA_BRICKS_CART=' . wp_json_encode( [ 'restUrl' => esc_url_raw( rest_url( 'dsa/v1/cart/item' ) ), 'addUrl' => esc_url_raw( rest_url( 'dsa/v1/cart/add' ) ), 'claimUrl' => esc_url_raw( rest_url( 'dsa/v1/cart/upsell/claim' ) ), 'nonceUrl' => esc_url_raw( rest_url( 'dsa/v1/cart/nonce' ) ), 'nonce' => '', 'debug' => $this->frontend_console_debug_enabled() ] ) . ';', 'before' );
		wp_add_inline_script( 'dsa-bricks-mini-cart', $this->mini_cart_js() );
	}

	private function enqueue_add_to_cart_adapter(): void {
		if ( wp_script_is( 'dsa-bricks-add-to-cart', 'enqueued' ) || wp_script_is( 'dsa-bricks-add-to-cart', 'done' ) ) {
			return;
		}

		wp_register_style( 'dsa-bricks-add-to-cart', false, [], DSA_VERSION );
		wp_enqueue_style( 'dsa-bricks-add-to-cart' );
		wp_add_inline_style( 'dsa-bricks-add-to-cart', $this->add_to_cart_css() );

		wp_register_script( 'dsa-bricks-add-to-cart', false, [ 'jquery' ], DSA_VERSION, true );
		wp_enqueue_script( 'dsa-bricks-add-to-cart' );
		wp_add_inline_script(
			'dsa-bricks-add-to-cart',
			'window.DSA_BRICKS_ATC=' . wp_json_encode(
				[
					'addUrl'  => esc_url_raw( rest_url( 'dsa/v1/cart/add' ) ),
					'itemUrl' => esc_url_raw( rest_url( 'dsa/v1/cart/item' ) ),
					'nonceUrl' => esc_url_raw( rest_url( 'dsa/v1/cart/nonce' ) ),
					'nonce'   => '',
					'debug'   => $this->frontend_console_debug_enabled(),
				]
			) . ';',
			'before'
		);
		wp_add_inline_script( 'dsa-bricks-add-to-cart', $this->add_to_cart_js() );
	}

	private function frontend_console_debug_enabled(): bool {
		$settings    = $this->settings->all();
		$diagnostics = isset( $settings['diagnostics'] ) && is_array( $settings['diagnostics'] )
			? $settings['diagnostics']
			: [];

		return ! empty( $diagnostics['enabled'] )
			&& ! empty( $diagnostics['frontend_debug'] )
			&& ! empty( $diagnostics['console_logs'] );
	}

	private function classify_from_bricks_name( string $name ): string {
		$image_types = [ 'image', 'logo', 'image-gallery' ];
		$form_types  = [ 'form', 'search' ];
		$nav_types   = [ 'nav-menu', 'nav-nested', 'pagination', 'button', 'icon-box' ];
		$region_types = [ 'section' ];
		$layout_types = [ 'container', 'block', 'div', 'divider', 'slider-nested' ];

		if ( 'icon' === $name || 'svg' === $name ) {
			return 'icon';
		}

		if ( in_array( $name, $region_types, true ) ) {
			return 'region';
		}

		if ( in_array( $name, $layout_types, true ) ) {
			return 'layout';
		}

		if ( in_array( $name, $image_types, true ) ) {
			return 'image';
		}

		if ( in_array( $name, $form_types, true ) ) {
			return 'form';
		}

		if ( in_array( $name, $nav_types, true ) ) {
			return 'navigation';
		}

		if ( false !== strpos( $name, 'heading' ) ) {
			return 'heading';
		}

		if ( false !== strpos( $name, 'text' ) || false !== strpos( $name, 'rich-text' ) ) {
			return 'text';
		}

		return 'unknown';
	}

	private function bricks_config(): array {
		$config = $this->settings->get( 'bricks', [] );

		return is_array( $config ) ? $config : [];
	}

	private function commerce_config(): array {
		$config = $this->settings->get( 'commerce', [] );

		return is_array( $config ) ? $config : [];
	}

	private function mini_cart_settings(): array {
		if ( $this->mini_cart_context ) {
			return $this->mini_cart_context;
		}

		$stored = get_option( self::MINI_CART_SETTINGS_OPTION, [] );
		$this->mini_cart_context = is_array( $stored ) ? $stored : [];

		return $this->mini_cart_context;
	}

	private function product_max_quantity( $product, int $quantity ): int {
		if ( is_object( $product ) && method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually() ) {
			return 1;
		}

		if ( is_object( $product ) && method_exists( $product, 'managing_stock' ) && $product->managing_stock() ) {
			return max( $quantity, (int) $product->get_stock_quantity() );
		}

		return 99;
	}

	private function stock_badge_html( $product ): string {
		if ( ! is_object( $product ) || ! method_exists( $product, 'managing_stock' ) || ! $product->managing_stock() ) {
			return '';
		}

		$config = $this->commerce_config();
		$stock = (int) $product->get_stock_quantity();
		$alert_threshold = max( 1, min( 999, (int) ( $config['stock_badge_alert_threshold'] ?? 10 ) ) );
		$urgent_threshold = max( 1, min( $alert_threshold, (int) ( $config['stock_badge_urgent_threshold'] ?? 3 ) ) );

		if ( $stock <= 0 || $stock > $alert_threshold ) {
			return '';
		}

		$urgent = $stock <= $urgent_threshold;
		$text = $urgent ? ( $config['stock_badge_urgent_text'] ?? '' ) : ( $config['stock_badge_alert_text'] ?? '' );
		$text = '' !== $text ? $text : ( $urgent ? __( 'Almost gone: %d left', 'dsa' ) : __( 'Only %d left', 'dsa' ) );
		$label = false !== strpos( $text, '%d' ) ? sprintf( $text, $stock ) : $text;

		return sprintf(
			'<div class="brxmc-badge %1$s">%2$s</div>',
			esc_attr( $urgent ? 'brxmc-badge-urgent' : 'brxmc-badge-alert' ),
			esc_html( $label )
		);
	}

	private function product_id_from_element( $element ): int {
		if ( ! empty( $element->post_id ) ) {
			return (int) $element->post_id;
		}

		global $product;

		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			return (int) $product->get_id();
		}

		return 0;
	}

	private function cart_item_for_product( int $product_id ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [];
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$item_id = (int) ( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );

			if ( $item_id === $product_id ) {
				return [
					'key' => (string) $key,
					'qty' => (int) ( $item['quantity'] ?? 0 ),
				];
			}
		}

		return [];
	}

	private function add_to_cart_runtime_enabled( array $settings ): bool {
		if ( empty( $this->bricks_config()['add_to_cart_enhancer_enabled'] ) ) {
			return false;
		}

		return ! empty( $settings['brxQtyAjax'] ) || ! empty( $settings['brxHideQty'] ) || ! empty( $settings['brxSwapAtc'] ) || ! empty( $settings['brxPlusOnly'] ) || ! empty( $settings['brxPlusAdd'] );
	}

	private function mini_cart_css(): string {
		return '
:where(.brxmc-qty-wrap){display:flex;flex-direction:column;gap:.35rem;margin-top:.35rem}
:where(.brxmc-qty-wrap.quantity .brxmc-price){all:revert;white-space:nowrap}
:where(.brxmc-price .woocommerce-Price-amount){font-size:inherit!important}
:where(.brxmc-stepper-wrap){display:flex;overflow:hidden}
:where(.brxmc-stepper){display:inline-flex;align-items:stretch;gap:0}
:where(.brxmc-qty-wrap.quantity .brxmc-stepper .brxmc-btn){all:unset;box-sizing:border-box;flex-shrink:0;cursor:pointer;display:flex;align-items:center;justify-content:center;user-select:none}
:where(.brxmc-qty-wrap.quantity .brxmc-stepper .brxmc-num){all:unset;box-sizing:border-box;display:flex;align-items:center;justify-content:center;text-align:center;line-height:1}
:where(.brxmc-btn:disabled){opacity:.35;cursor:not-allowed}
:where(.brxmc-btn.brxmc-busy){opacity:.5;pointer-events:none}
:where(.brxmc-badge){display:flex;align-items:center;gap:5px;line-height:1.3;width:100%;box-sizing:border-box}
:where(.brxmc-badge-urgent){animation:brxmc-pulse 2s ease-in-out infinite}
:where(.brxus-cross-sells){display:flex;flex-direction:column;gap:.65rem;padding:0 0 .75rem;box-sizing:border-box}
:where(.brxus-section-title){margin:0}
:where(.brxus-products-scroll){display:flex;gap:.75rem;overflow-x:auto;padding-bottom:.45rem;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
:where(.brxus-products-scroll::-webkit-scrollbar){height:3px}
:where(.brxus-product-card){display:flex;flex-direction:column;flex:0 0 148px;gap:.45rem;scroll-snap-align:start;box-sizing:border-box}
:where(.brxus-product-img img){display:block;width:100%;height:80px;object-fit:cover}
:where(.brxus-product-info){display:flex;flex-direction:column;gap:2px;min-width:0}
:where(.brxus-product-name){display:-webkit-box;overflow:hidden;line-height:1.3;-webkit-box-orient:vertical;-webkit-line-clamp:2}
:where(.brxus-product-state){line-height:1.3}
:where(.brxus-product-price .woocommerce-Price-amount){font-size:inherit!important}
:where(.brxus-cs-btn){cursor:pointer;line-height:1;text-decoration:none;transition:opacity .2s ease,transform .1s ease}
:where(.brxus-cs-btn:hover:not(:disabled)){transform:translateY(-1px)}
:where(.brxus-cs-btn.brxmc-busy){opacity:.55;pointer-events:none}
:where(.brxus-upsell-wrap){display:grid;gap:.75rem;padding:.45rem 0 .85rem;box-sizing:border-box}
:where(.brxus-offer-banner){display:flex;align-items:center;justify-content:space-between;gap:.85rem;box-sizing:border-box;padding:.8rem;border:1px solid rgba(180,120,20,.22);border-radius:.45rem;background:rgba(245,214,164,.55)}
:where(.brxus-offer-copy){display:grid;gap:.25rem;min-width:0}
:where(.brxus-offer-badge){display:inline-flex;width:max-content;max-width:100%;padding:.25rem .45rem;border-radius:.2rem;background:#8a1f12;color:#fff;font-weight:700;line-height:1;text-transform:uppercase}
:where(.brxus-offer-headline){line-height:1.35}
:where(.brxus-offer-headline strong){font-weight:inherit}
:where(.brxus-offer-sub){line-height:1.35}
:where(.brxus-add-btn){cursor:pointer;padding:.65rem 1rem;border:0;border-radius:.35rem;background:#e65a3a;color:#fff;font-weight:700;text-align:center;text-decoration:none;white-space:nowrap;transition:opacity .2s ease,transform .1s ease}
:where(.brxus-add-btn:hover:not(:disabled)){transform:translateY(-1px)}
:where(.brxus-add-btn.brxmc-busy){opacity:.55;pointer-events:none}
:where(.brxus-offer--applied .brxus-add-btn){opacity:.72}
:where(.brxus-total-after-row){display:flex;align-items:center;justify-content:space-between;gap:1rem;box-sizing:border-box;margin:.35rem 0 .85rem;padding:.8rem;border:1px solid rgba(0,0,0,.1);border-radius:.45rem}
:where(.brxus-total-after-value .woocommerce-Price-amount){font-size:inherit!important}
@keyframes brxmc-pulse{0%,100%{opacity:1}50%{opacity:.75}}
';
	}

	private function mini_cart_js(): string {
		return '
(function(){
	"use strict";
	var config = window.DSA_BRICKS_CART || {};
	var debugEnabled = !!config.debug || !!(window.DSA && window.DSA.diagnostics && window.DSA.diagnostics.console);
	function redact(value, depth){
		depth = Number(depth || 0);
		if(depth > 5){ return "[depth-limit]"; }
		if(value === null || value === undefined || typeof value !== "object"){ return value; }
		if(Array.isArray(value)){ return value.slice(0,24).map(function(item){ return redact(item, depth + 1); }); }
		var clean = {};
		Object.keys(value).slice(0,60).forEach(function(key){
			if(/(?:authorization|code|credential|currentpassword|email|identifier|nonce|otp|passcode|password|phone|secret|token)/i.test(String(key || ""))){ clean[key] = "[redacted]"; return; }
			clean[key] = redact(value[key], depth + 1);
		});
		return clean;
	}
	function debug(label, details){ if(debugEnabled && window.console && window.console.log){ window.console.log("[Kiwe DSA][Bricks Cart] " + label, redact(details || {})); } }
	function setBusy(scope,busy){
		if(!scope){ return; }
		if(busy){ scope.setAttribute("data-dsa-busy","1"); }
		else { scope.removeAttribute("data-dsa-busy"); }
		scope.querySelectorAll("[data-dsa-bricks-cart-quantity],[data-dsa-bricks-cart-add],[data-dsa-bricks-cart-claim]").forEach(function(item){
			if(busy && item.disabled){ item.setAttribute("data-was-disabled","1"); }
			item.classList.toggle("brxmc-busy", busy);
			item.disabled = busy || item.hasAttribute("data-was-disabled");
		});
	}
	function applyFragments(payload){
		var fragments = payload && payload.fragments ? payload.fragments : {};
		var replaced = 0;
		Object.keys(fragments).forEach(function(selector){
			document.querySelectorAll(selector).forEach(function(node){
				var wrapper = document.createElement("div");
				wrapper.innerHTML = fragments[selector];
				var fresh = wrapper.firstElementChild;
				if(fresh){ node.replaceWith(fresh); replaced++; }
			});
		});
		debug("fragments applied",{keys:Object.keys(fragments),replaced:replaced,cartHash:payload && payload.cart_hash ? payload.cart_hash : ""});
		if(payload && payload.cart_hash){
			try {
				window.sessionStorage.setItem("wc_cart_hash", payload.cart_hash);
				window.localStorage.setItem("wc_cart_hash", payload.cart_hash);
			} catch(error) {}
		}
	}
	function changed(payload){
		debug("cart changed payload",{count:payload && payload.cart ? payload.cart.count : null,item:payload && payload.item ? payload.item : null});
		applyFragments(payload || {});
		try {
			document.dispatchEvent(new CustomEvent("dsa:cart:changed",{detail:(payload && payload.cart) || {}}));
		} catch(error) {
			var event = document.createEvent("Event");
			event.initEvent("dsa:cart:changed", true, true);
			document.dispatchEvent(event);
		}
		if(window.jQuery){
			window.jQuery(document.body).trigger("added_to_cart",[payload && payload.fragments ? payload.fragments : {}, payload && payload.cart_hash ? payload.cart_hash : "", null]);
			window.jQuery(document.body).trigger("wc_fragments_refreshed",[payload && payload.fragments ? payload.fragments : {}]);
		}
	}
	function refreshNonce(){
		if(!config.nonceUrl){ return Promise.reject(new Error("Nonce refresh unavailable")); }
		return fetch(config.nonceUrl,{credentials:"same-origin"}).then(function(response){ return response.json(); }).then(function(payload){
			if(payload && payload.nonce){ config.nonce = payload.nonce; return payload.nonce; }
			throw new Error("Nonce refresh failed");
		});
	}
	function post(url,data,retried){
		if(!config.nonce && !retried){ return refreshNonce().then(function(){ return post(url,data,true); }); }
		debug("REST POST start",{url:url,data:data || {},retried:!!retried});
		return fetch(url,{
			method:"POST",
			credentials:"same-origin",
			headers:{"Content-Type":"application/json","X-Kiwe-Mutation":"1","X-WP-Nonce":config.nonce || ""},
			body:JSON.stringify(data || {})
		}).then(function(response){
			debug("REST POST response",{url:url,status:response.status,ok:response.ok});
			if(response.ok){ return response.json().then(function(payload){ debug("REST POST json",{count:payload && payload.cart ? payload.cart.count : null,item:payload && payload.item ? payload.item : null,fragmentKeys:payload && payload.fragments ? Object.keys(payload.fragments) : []}); return payload; }); }
			return response.json().catch(function(){ return {}; }).then(function(payload){
				var code = payload && payload.code ? payload.code : "";
				if(!retried && (response.status === 401 || response.status === 403 || code === "rest_cookie_invalid_nonce")){
					debug("REST nonce retry",{status:response.status,code:code});
					return refreshNonce().then(function(){ return post(url,data,true); });
				}
				debug("REST POST error",{status:response.status,code:code,message:(payload && payload.message) || "Cart request failed"});
				throw new Error((payload && payload.message) || "Cart request failed");
			});
		});
	}
	function showError(scope,message){
		if(!scope){ return; }
		scope.setAttribute("data-dsa-cart-error", message || "Cart request failed");
		scope.title = message || "Cart request failed";
		var action = scope.querySelector("[data-dsa-bricks-cart-add],[data-dsa-bricks-cart-claim]");
		if(action){
			var original = action.getAttribute("data-dsa-label") || action.textContent || "";
			action.setAttribute("data-dsa-label", original);
			action.textContent = message || "Failed";
			window.setTimeout(function(){
				if(action && action.isConnected){ action.textContent = original; }
			}, 2500);
		}
	}
	function update(button){
		var key = button.getAttribute("data-dsa-bricks-cart-quantity");
		var quantity = Number(button.getAttribute("data-dsa-bricks-cart-next")) || 0;
		if(!key || !config.restUrl){ return; }
		var scope = button.closest(".brxmc-stepper") || button.parentNode;
		if(scope && scope.getAttribute("data-dsa-busy") === "1"){ return; }
		setBusy(scope,true);
		post(config.restUrl,{key:key,quantity:quantity,productId:button.getAttribute("data-dsa-bricks-cart-product") || "",variationId:button.getAttribute("data-dsa-bricks-cart-variation") || ""}).then(function(payload){
			changed(payload);
		}).catch(function(error){ showError(scope,error.message); setBusy(scope,false); });
	}
	function add(button){
		var productId = Number(button.getAttribute("data-dsa-bricks-cart-add")) || 0;
		var triggerId = Number(button.getAttribute("data-dsa-bricks-cart-trigger")) || 0;
		if(!productId || !config.addUrl){ return; }
		var scope = button.closest(".brxus-product-card,.brxus-offer-banner") || button.parentNode;
		if(scope && scope.getAttribute("data-dsa-busy") === "1"){ return; }
		debug("add click",{productId:productId,triggerId:triggerId});
		setBusy(scope,true);
		post(config.addUrl,{productId:productId,quantity:1,triggerId:triggerId}).then(function(payload){
			changed(payload);
		}).catch(function(error){ showError(scope,error.message); setBusy(scope,false); });
	}
	function claim(button){
		var productId = Number(button.getAttribute("data-dsa-bricks-cart-claim")) || 0;
		var triggerId = Number(button.getAttribute("data-dsa-bricks-cart-trigger")) || 0;
		if(!productId || !triggerId || !config.claimUrl){ return; }
		var scope = button.closest(".brxus-product-card,.brxus-offer-banner") || button.parentNode;
		if(scope && scope.getAttribute("data-dsa-busy") === "1"){ return; }
		debug("claim click",{productId:productId,triggerId:triggerId});
		setBusy(scope,true);
		post(config.claimUrl,{productId:productId,triggerId:triggerId}).then(function(payload){
			changed(payload);
		}).catch(function(error){ showError(scope,error.message); setBusy(scope,false); });
	}
	document.addEventListener("click",function(event){
		var button = event.target.closest("[data-dsa-bricks-cart-quantity]");
		if(button && !button.disabled){
			event.preventDefault();
			update(button);
			return;
		}
		button = event.target.closest("[data-dsa-bricks-cart-add]");
		if(button && !button.disabled){
			event.preventDefault();
			add(button);
			return;
		}
		button = event.target.closest("[data-dsa-bricks-cart-claim]");
		if(button && !button.disabled){
			event.preventDefault();
			claim(button);
		}
	});
}());
';
	}

	private function add_to_cart_css(): string {
		return '
:where(.quantity .plus,.quantity .minus){font:inherit}
:where(.quantity input.qty){font:inherit}
:where(.quantity .plus svg,.quantity .minus svg),:where(.quantity .plus svg *,.quantity .minus svg *){fill:currentColor}
:where(.quantity .plus svg,.quantity .minus svg){width:1em;height:1em;display:block}
[data-brx-t2] .quantity{display:none!important}
[data-brx-t3] .quantity{transition:opacity var(--brx-atc-swap-duration,0s) var(--brx-atc-swap-easing,ease),visibility var(--brx-atc-swap-duration,0s) var(--brx-atc-swap-easing,ease),max-height var(--brx-atc-swap-duration,0s) var(--brx-atc-swap-easing,ease)}
[data-brx-t3]:not([data-brx-added]) .quantity{opacity:0;visibility:hidden;pointer-events:none;max-height:0;overflow:hidden}
[data-brx-t3][data-brx-added] .quantity{display:flex!important;opacity:1!important;visibility:visible!important;pointer-events:auto!important;max-height:200px!important;overflow:visible!important}
[data-brx-t3][data-brx-added] .add_to_cart_button,[data-brx-t3][data-brx-added] .added_to_cart{display:none!important;pointer-events:none!important}
[data-brx-t4] .add_to_cart_button,[data-brx-t4] .added_to_cart{display:none!important}
[data-brx-t4] .quantity{display:flex!important}
[data-brx-t4] .quantity .action.minus,[data-brx-t4] .quantity input.qty{opacity:0;visibility:hidden;pointer-events:none;max-width:0;overflow:hidden;transition:opacity var(--brx-atc-swap-duration,0s) var(--brx-atc-swap-easing,ease),visibility var(--brx-atc-swap-duration,0s) var(--brx-atc-swap-easing,ease),max-width var(--brx-atc-swap-duration,0s) var(--brx-atc-swap-easing,ease)}
[data-brx-t4][data-brx-in-cart] .quantity .action.minus,[data-brx-t4][data-brx-in-cart] .quantity input.qty{opacity:1!important;visibility:visible!important;pointer-events:auto!important;max-width:200px!important;overflow:visible!important}
[data-brx-t5] .add_to_cart_button,[data-brx-t5] .added_to_cart{display:none!important}
[data-brx-t5] .quantity{display:flex!important}
[data-brx-t5] .quantity .action.minus,[data-brx-t5] .quantity input.qty{display:none!important}
[data-brx-loading] .quantity{opacity:.5;pointer-events:none}
';
	}

	private function add_to_cart_js(): string {
		return '
(function($){
	"use strict";
	var config = window.DSA_BRICKS_ATC || {};
	var debugEnabled = !!config.debug || !!(window.DSA && window.DSA.diagnostics && window.DSA.diagnostics.console);
	function redact(value, depth){
		depth = Number(depth || 0);
		if(depth > 5){ return "[depth-limit]"; }
		if(value === null || value === undefined || typeof value !== "object"){ return value; }
		if(Array.isArray(value)){ return value.slice(0,24).map(function(item){ return redact(item, depth + 1); }); }
		var clean = {};
		Object.keys(value).slice(0,60).forEach(function(key){
			if(/(?:authorization|code|credential|currentpassword|email|identifier|nonce|otp|passcode|password|phone|secret|token)/i.test(String(key || ""))){ clean[key] = "[redacted]"; return; }
			clean[key] = redact(value[key], depth + 1);
		});
		return clean;
	}
	function debug(label, details){ if(debugEnabled && window.console && window.console.log){ window.console.log("[Kiwe DSA][Bricks ATC] " + label, redact(details || {})); } }
	function root(el){ return el && el.closest("[data-brx-t1],[data-brx-t2],[data-brx-t3],[data-brx-t4],[data-brx-t5]"); }
	function qtyInput(r){ return r ? r.querySelector(".quantity input.qty") : null; }
	function productId(r){ var b = r ? r.querySelector("[data-product_id]") : null; return (b && b.getAttribute("data-product_id")) || (r && r.getAttribute("data-dsa-product-id")) || ""; }
	function applyFragments(payload){
		var fragments = payload && payload.fragments ? payload.fragments : {};
		var replaced = 0;
		Object.keys(fragments).forEach(function(selector){
			document.querySelectorAll(selector).forEach(function(node){
				var wrapper = document.createElement("div");
				wrapper.innerHTML = fragments[selector];
				var fresh = wrapper.firstElementChild;
				if(fresh){ node.replaceWith(fresh); replaced++; }
			});
		});
		debug("fragments applied",{keys:Object.keys(fragments),replaced:replaced,cartHash:payload && payload.cart_hash ? payload.cart_hash : ""});
		if(payload && payload.cart_hash){
			try {
				window.sessionStorage.setItem("wc_cart_hash", payload.cart_hash);
				window.localStorage.setItem("wc_cart_hash", payload.cart_hash);
			} catch(error) {}
		}
		if(fragments && Object.keys(fragments).length){
			$(document.body).trigger("added_to_cart",[fragments,payload && payload.cart_hash ? payload.cart_hash : "",null]);
			$(document.body).trigger("wc_fragments_refreshed",[fragments]);
		}
	}
	function setLoading(r,on){ if(!r){ return; } if(on){ r.setAttribute("data-brx-loading","true"); } else { r.removeAttribute("data-brx-loading"); } }
	function setQty(r,qty){ r.setAttribute("data-brx-cart-qty", qty); var input = qtyInput(r); if(input){ input.value = qty; } }
	function markAdded(r,key,qty){ if(key){ r.setAttribute("data-brx-cart-key", key); } r.setAttribute("data-brx-in-cart","true"); r.setAttribute("data-brx-added","true"); setQty(r, qty || 1); }
	function markRemoved(r){ ["data-brx-in-cart","data-brx-added","data-brx-cart-key","data-brx-cart-qty"].forEach(function(attr){ r.removeAttribute(attr); }); var input = qtyInput(r); if(input){ input.value = 1; } }
	function syncRoots(cart){
		if(!cart || !Array.isArray(cart.items)){ return; }
		document.querySelectorAll("[data-dsa-product-id]").forEach(function(r){
			var pid = String(r.getAttribute("data-dsa-product-id") || "");
			var match = cart.items.find(function(item){
				return String(item.variationId || "") === pid || String(item.productId || "") === pid;
			});
			if(match){ markAdded(r,match.key || "",match.quantity || 1); }
			else { markRemoved(r); }
		});
	}
	function refresh(payload){
		debug("refresh payload",{count:payload && payload.cart ? payload.cart.count : null,item:payload && payload.item ? payload.item : null});
		syncRoots(payload && payload.cart ? payload.cart : null);
		applyFragments(payload || {});
		if(payload && payload.cart){
			try {
				document.dispatchEvent(new CustomEvent("dsa:cart:changed",{detail:payload.cart}));
			} catch(error) {
				var event = document.createEvent("Event");
				event.initEvent("dsa:cart:changed", true, true);
				document.dispatchEvent(event);
			}
		}
	}
	function refreshNonce(){
		if(!config.nonceUrl){ return Promise.reject(new Error("Nonce refresh unavailable")); }
		return fetch(config.nonceUrl,{credentials:"same-origin"}).then(function(response){ return response.json(); }).then(function(payload){
			if(payload && payload.nonce){ config.nonce = payload.nonce; return payload.nonce; }
			throw new Error("Nonce refresh failed");
		});
	}
	function post(url,data,retried){
		if(!config.nonce && !retried){ return refreshNonce().then(function(){ return post(url,data,true); }); }
		debug("REST POST start",{url:url,data:data || {},retried:!!retried});
		return fetch(url,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-Kiwe-Mutation":"1","X-WP-Nonce":config.nonce || ""},body:JSON.stringify(data)}).then(function(response){
			debug("REST POST response",{url:url,status:response.status,ok:response.ok});
			if(response.ok){ return response.json().then(function(payload){ debug("REST POST json",{count:payload && payload.cart ? payload.cart.count : null,item:payload && payload.item ? payload.item : null,fragmentKeys:payload && payload.fragments ? Object.keys(payload.fragments) : []}); return payload; }); }
			return response.json().catch(function(){ return {}; }).then(function(payload){
				var code = payload && payload.code ? payload.code : "";
				if(!retried && (response.status === 401 || response.status === 403 || code === "rest_cookie_invalid_nonce")){
					debug("REST nonce retry",{status:response.status,code:code});
					return refreshNonce().then(function(){ return post(url,data,true); });
				}
				debug("REST POST error",{status:response.status,code:code,message:(payload && payload.message) || "Cart request failed"});
				throw new Error((payload && payload.message) || "Cart request failed");
			});
		});
	}
	function add(r,pid,qty){
		debug("add click",{productId:pid,quantity:qty || 1});
		setLoading(r,true);
		post(config.addUrl,{productId:pid,quantity:qty || 1}).then(function(res){
			var data = res && res.item ? res.item : {};
			markAdded(r,data.key || "",data.quantity || qty || 1);
			refresh(res);
		}).catch(function(){ refresh(); }).finally(function(){ setLoading(r,false); });
	}
	function update(r,key,qty,rollback){
		debug("quantity click",{key:key,quantity:qty,rollback:rollback});
		setLoading(r,true);
		post(config.itemUrl,{key:key,quantity:qty}).then(function(res){
			if(qty <= 0){ markRemoved(r); } else { setQty(r, qty); }
			refresh(res);
		}).catch(function(){ setQty(r,rollback || 1); refresh(); }).finally(function(){ setLoading(r,false); });
	}
	$(document).on("click",".cart .action.plus",function(event){
		var r = root(this);
		if(!r || r.hasAttribute("data-brx-loading")){ return; }
		if(!r.hasAttribute("data-brx-t1") && !r.hasAttribute("data-brx-t4") && !r.hasAttribute("data-brx-t5")){ return; }
		event.preventDefault();
		event.stopImmediatePropagation();
		var key = r.getAttribute("data-brx-cart-key") || "";
		var current = parseInt(r.getAttribute("data-brx-cart-qty") || "0",10);
		if(key && !r.hasAttribute("data-brx-t5")){ update(r,key,current + 1,current); return; }
		var pid = productId(r);
		if(pid){ add(r,pid,1); }
	});
	$(document).on("click",".cart .action.minus",function(event){
		var r = root(this);
		if(!r || !r.hasAttribute("data-brx-t1") || r.hasAttribute("data-brx-loading")){ return; }
		var key = r.getAttribute("data-brx-cart-key") || "";
		if(!key){ return; }
		event.preventDefault();
		event.stopImmediatePropagation();
		var current = parseInt(r.getAttribute("data-brx-cart-qty") || "1",10);
		update(r,key,Math.max(0,current - 1),current);
	});
	$(document).on("change",".cart .quantity input.qty",function(){
		var r = root(this);
		if(!r || !r.hasAttribute("data-brx-t1") || r.hasAttribute("data-brx-loading")){ return; }
		var key = r.getAttribute("data-brx-cart-key") || "";
		if(!key){ return; }
		var current = parseInt(r.getAttribute("data-brx-cart-qty") || "1",10);
		var next = Math.max(0,parseInt(this.value || "0",10));
		if(next !== current){ update(r,key,next,current); }
	});
})(jQuery);
';
	}
}
