<?php

namespace DSA\Public_Endpoint;

use DSA\Element_Registry;
use DSA\Commerce\Commerce_Context_Service;
use DSA\Design\Seam_Token_Service;
use DSA\Design\Seam_Vocabulary_Schema;
use DSA\Design\Token_Schema;
use DSA\Environment;
use DSA\Link_Hub\Review_Service;
use DSA\Metrics\Metrics_Service;
use DSA\Modules\Module_Registry;
use DSA\Notifications\Notification_Preference_Service;
use DSA\Permissions\Permission_Journey_Service;
use DSA\PWA\PWA_Service;
use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\Protected_Flow\Flow_Guard;
use DSA\Rewards\Reward_Service;
use DSA\Runtime\Route_Capability_Service;
use DSA\Settings;
use DSA\Site\Site_Identity_Service;
use DSA\Theme\Theme_Package_Service;
use DSA\Trust\Trust_Service;
use DSA\Trigger\Trigger_Service;
use DSA\WP7\Native_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {
	private const HTMX_VERSION = '2.0.10';
	private const ALPINE_VERSION = '3.15.12';

	private $settings;
	private $registry;
	private $modules;
	private $phonekey;
	private $trust;
	private $flow_guard;
	private $triggers;
	private $native;
	private $commerce;
	private $rewards;
	private $metrics;
	private $permissions;
	private $notification_preferences;
	private $pwa;
	private $reviews;
	private $route_capabilities;
	private $initial_preloader_printed = false;

	public function __construct( Settings $settings, Element_Registry $registry, Module_Registry $modules, PhoneKey_Bridge $phonekey, Trust_Service $trust, Flow_Guard $flow_guard, Trigger_Service $triggers, Native_Service $native, Commerce_Context_Service $commerce, Reward_Service $rewards, Metrics_Service $metrics, Permission_Journey_Service $permissions, Notification_Preference_Service $notification_preferences, PWA_Service $pwa, Review_Service $reviews, Route_Capability_Service $route_capabilities ) {
		$this->settings   = $settings;
		$this->registry   = $registry;
		$this->modules    = $modules;
		$this->phonekey   = $phonekey;
		$this->trust      = $trust;
		$this->flow_guard = $flow_guard;
		$this->triggers   = $triggers;
		$this->native     = $native;
		$this->commerce   = $commerce;
		$this->rewards    = $rewards;
		$this->metrics    = $metrics;
		$this->permissions = $permissions;
		$this->notification_preferences = $notification_preferences;
		$this->pwa        = $pwa;
		$this->reviews    = $reviews;
		$this->route_capabilities = $route_capabilities;
	}

	public function register(): void {
		add_filter( 'show_admin_bar', [ $this, 'filter_admin_bar' ], 1000 );
		add_action( 'wp_head', [ $this, 'print_phantom_viewport_seed' ], 0 );
		add_action( 'wp_head', [ $this, 'print_boot_seed' ], 1 );
		add_action( 'wp_head', [ $this, 'print_initial_preloader_style' ], 2 );
		add_action( 'wp_body_open', [ $this, 'print_initial_preloader' ], 0 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_footer', [ $this, 'print_initial_preloader_fallback' ], 0 );
	}

	public function filter_admin_bar( bool $show ): bool {
		if ( ! $show || is_admin() || ! $this->settings->get( 'enabled', true ) || ! Environment::should_render_frontend() ) {
			return $show;
		}

		$settings = $this->settings->all();
		return empty( $settings['dock']['hide_frontend_admin_bar'] );
	}

	public function enqueue(): void {
		if ( ! $this->settings->get( 'enabled', true ) || ! Environment::should_render_frontend() ) {
			return;
		}

		$settings       = $this->settings->all();
		$settings['dock'] = $this->dock_with_nav_menu_items( isset( $settings['dock'] ) && is_array( $settings['dock'] ) ? $settings['dock'] : [] );
		$settings['dock']['admin_dashboard'] = null;
		$manifest       = $this->settings->manifest();
		$protected_flow = $this->neutral_protected_flow( $settings );
		if ( empty( $settings['commerce']['cart_surface_enabled'] ) ) {
			$settings['dock']['enabled_items']['cart'] = false;
		}
		$link_hub       = isset( $settings['link_hub'] ) && is_array( $settings['link_hub'] ) ? $settings['link_hub'] : [];
		$trust_summary  = $this->trust->summary( $link_hub );
		$commerce       = $this->commerce->public_context();
		$phonekey       = $this->phonekey->boot_data();
		$native_data    = $this->native_readonly_data( $trust_summary, $commerce, $phonekey );
		$commerce['settings'] = [
			'cartSurfaceEnabled'   => ! empty( $settings['commerce']['cart_surface_enabled'] ),
			'checkoutSurfaceEnabled' => ! empty( $settings['commerce']['checkout_surface_enabled'] ),
			'cartQuantityControls' => ! empty( $settings['commerce']['cart_quantity_controls'] ),
			'cartBadgesEnabled'    => ! empty( $settings['commerce']['cart_badges_enabled'] ),
			'fbtEnabled'           => ! empty( $settings['commerce']['fbt_enabled'] ),
			'fbtTitle'             => sanitize_text_field( $settings['commerce']['fbt_title'] ?? __( 'Frequently Bought Together', 'dsa' ) ),
			'fbtMaxProducts'       => max( 1, min( 12, (int) ( $settings['commerce']['fbt_max_products'] ?? 6 ) ) ),
			'addToCartMode'        => in_array( $settings['commerce']['add_to_cart_mode'] ?? 'default', [ 'default', 'plus_only', 'quantity', 'replace' ], true ) ? $settings['commerce']['add_to_cart_mode'] : 'default',
			'firstCartConfettiEnabled' => ! empty( $settings['commerce']['first_cart_confetti_enabled'] ),
		];
		$route_policy = $this->route_capabilities->policy( $settings, $manifest, $commerce );

		$visual = isset( $settings['visual_effects'] ) && is_array( $settings['visual_effects'] ) ? $settings['visual_effects'] : [];
		$theme  = isset( $settings['dsa_theme'] ) && is_array( $settings['dsa_theme'] ) ? $settings['dsa_theme'] : [];
		$active = sanitize_hex_color( $theme['active_color'] ?? '' ) ?: '#8f8f98';
		$hover  = sanitize_hex_color( $theme['hover_color'] ?? '' ) ?: '#24c6a1';
		$hero   = preg_match( '/^(#[0-9a-f]{3,6}|rgba?\([^)]+\))$/i', (string) ( $theme['hero_text_color'] ?? '' ) ) ? (string) $theme['hero_text_color'] : 'rgba(20,24,34,0.18)';
		$seam_token_overrides = [
			'color-brand' => $active,
			'color-accent' => $hover,
			'color-hero' => $hero,
			'glass-blur' => max( 0, min( 24, absint( $visual['blur_strength'] ?? 10 ) ) ) . 'px',
		];
		wp_enqueue_style(
			'dsa-seam',
			DSA_URL . 'assets/css/seam.css',
			[],
			DSA_VERSION
		);
		wp_add_inline_style( 'dsa-seam', Seam_Token_Service::seam_alias_stylesheet( $seam_token_overrides ) );

		$surface_stylesheet = (string) apply_filters( 'dsa_surface_stylesheet_url', DSA_URL . 'assets/css/surface.css' );
		$surface_stylesheet_version = (string) apply_filters( 'dsa_surface_stylesheet_version', DSA_VERSION );
		wp_enqueue_style(
			'dsa-surface',
			$surface_stylesheet,
			[ 'dsa-seam' ],
			$surface_stylesheet_version
		);
		$theme_package_service = new Theme_Package_Service();
		$active_theme_record   = $theme_package_service->active( $settings );
		$active_theme_css      = $theme_package_service->active_css( $settings );
		if ( '' !== trim( $active_theme_css ) ) {
			wp_add_inline_style( 'dsa-surface', $active_theme_css );
		}
		if ( ! empty( $route_policy['viewTransitions']['enabled'] ) && ! empty( $route_policy['viewTransitions']['currentDocumentEditorial'] ) ) {
			wp_add_inline_style( 'dsa-surface', '@view-transition { navigation: auto; }' );
		}

		wp_enqueue_script(
			'dsa-seam',
			DSA_URL . 'assets/js/seam.js',
			[],
			DSA_VERSION,
			true
		);

		wp_enqueue_script(
			'dsa-surface',
			DSA_URL . 'assets/js/surface.js',
			[ 'dsa-seam' ],
			DSA_VERSION,
			true
		);

		$enhancements = $this->enhancement_data( $settings );
		if ( ! empty( $enhancements['htmx']['enabled'] ) ) {
			wp_enqueue_script(
				'dsa-htmx',
				DSA_URL . 'assets/vendor/htmx/htmx.min.js',
				[],
				self::HTMX_VERSION,
				true
			);
		}
		if ( ! empty( $enhancements['alpine']['enabled'] ) ) {
			wp_enqueue_script(
				'dsa-alpine',
				DSA_URL . 'assets/vendor/alpine/alpine.min.js',
				[],
				self::ALPINE_VERSION,
				true
			);
			wp_script_add_data( 'dsa-alpine', 'defer', true );
		}
		$debug = $this->debug_data( $settings );
		if ( ! empty( $debug['enabled'] ) && ! empty( $debug['console'] ) ) {
			wp_enqueue_script(
				'dsa-seam-dev',
				DSA_URL . 'assets/js/seam-dev.js',
				[ 'dsa-surface' ],
				DSA_VERSION,
				true
			);
		}

		if ( function_exists( 'wp_interactivity_state' ) ) {
			wp_interactivity_state(
				'kiwe/ai',
				[
					'version'    => 1,
					'actionable' => 0,
					'unread'     => 0,
					'total'      => 0,
					'latest'     => null,
				]
			);

			wp_interactivity_state(
				'kiwe/app',
				[
					'version'                => 1,
					'platform'               => '',
					'browser'                => '',
					'standalone'             => false,
					'installAvailable'       => false,
					'secureContext'          => false,
					'manifestPresent'        => false,
					'notificationPermission' => '',
					'serviceWorkerReady'     => false,
				]
			);

			wp_interactivity_state(
				'kiwe/data',
				[
					'version' => 1,
					'site'    => $native_data['site'],
					'trust'   => $native_data['trust'],
					'profile' => $native_data['profile'],
					'cart'    => $native_data['cart'],
				]
			);
		}

		if ( function_exists( 'wp_enqueue_script_module' ) && function_exists( 'wp_interactivity_state' ) ) {
			wp_enqueue_script_module(
				'dsa-native-islands',
				DSA_URL . 'assets/js/modules/native-islands.js',
				[ '@wordpress/interactivity' ],
				DSA_VERSION
			);
		} else {
			foreach ( [ 'ai', 'app', 'data' ] as $island ) {
				wp_enqueue_script( 'dsa-' . $island . '-island', DSA_URL . 'assets/js/' . $island . '-island.js', [ 'dsa-surface' ], DSA_VERSION, true );
			}
		}

		wp_localize_script(
			'dsa-surface',
			'DSA_DATA',
			[
				'restUrl'    => esc_url_raw( rest_url( 'dsa/v1' ) ),
				'iconSprite' => esc_url_raw( DSA_URL . 'assets/icons/lucide/sprite.svg' ),
				'nonce'      => '',
				'hydration'  => [ 'endpoint' => esc_url_raw( admin_url( 'admin-ajax.php?action=dsa_runtime_hydrate' ) ), 'version' => 2 ],
				'version'    => DSA_VERSION,
				'site'       => [
					'title'       => get_bloginfo( 'name' ),
					'name'        => get_bloginfo( 'name' ),
					'tagline'     => get_bloginfo( 'description' ),
					'homeUrl'     => home_url( '/' ),
					'icon'        => get_site_icon_url( 192 ),
					'logo'        => Site_Identity_Service::logo_url(),
					'logoInverse' => Site_Identity_Service::logo_url( 'inverse' ),
					'current'     => [
						'postId'      => (int) get_queried_object_id(),
						'isFrontPage' => is_front_page(),
						'frontPageId' => (int) get_option( 'page_on_front' ),
						'frontPageUrl' => (int) get_option( 'page_on_front' ) ? get_permalink( (int) get_option( 'page_on_front' ) ) : home_url( '/' ),
					],
				],
				'manifest'   => $manifest,
				'surfaceTriggers' => $this->triggers->contract( $settings, $manifest, $protected_flow ),
				'modules'    => $this->modules->frontend_contract( $settings['dock'] ?? [], $this->module_context( $settings['dock'] ?? [] ) ),
				'native'     => $this->native->summary(),
				'nativeData' => $native_data,
				'visual'     => $settings['visual_effects'] ?? [],
				'style'      => $settings['style'] ?? [],
				'haptic'     => $settings['haptic'] ?? [],
				'theme'      => $settings['dsa_theme'] ?? [],
				'installedTheme' => [
					'id'       => sanitize_key( (string) ( $active_theme_record['id'] ?? '' ) ),
					'name'     => sanitize_text_field( (string) ( $active_theme_record['name'] ?? '' ) ),
					'settings' => isset( $active_theme_record['settings'] ) && is_array( $active_theme_record['settings'] ) ? $active_theme_record['settings'] : [],
					'screens'  => isset( $active_theme_record['settings']['screens'] ) && is_array( $active_theme_record['settings']['screens'] ) ? $active_theme_record['settings']['screens'] : [],
				],
				'designTokens' => Token_Schema::contract( $settings, $manifest ),
				'kiweTokens' => $this->kiwe_tokens_data(),
				'seamTokens' => $this->kiwe_tokens_data(),
				'seam'       => [
					'enabled'      => true,
					'contract'     => 'kiwe.seam',
					'frameworkCss' => true,
					'vocabulary'   => Seam_Vocabulary_Schema::contract(),
				],
				'app'        => $this->app_data(),
				'search'     => [
					'endpoint'  => esc_url_raw( rest_url( 'dsa/v1/search' ) ),
					'moduleUrl' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/search.js' ) ),
					'limit'     => max( 1, min( 12, absint( $this->settings->get( 'search', [] )['result_limit'] ?? 6 ) ) ),
					'context'   => $this->search_context(),
					'alphabetEnabled' => ! empty( $this->settings->get( 'search', [] )['alphabet_enabled'] ),
					'productAddEnabled' => ! empty( $this->settings->get( 'search', [] )['product_add_enabled'] ),
					'bricksBridgeEnabled' => ! empty( $this->settings->get( 'search', [] )['bricks_bridge_enabled'] ),
				],
				'presentationModules' => [
					'profile' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/profile-panel.js' ) ),
					'cart' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/commerce-panels.js' ) ),
					'checkout' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/commerce-panels.js' ) ),
					'links' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/links-panel.js' ) ),
					'ai' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/ai-panel.js' ) ),
					'saved' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/surface-panels.js' ) ),
					'notifications' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/surface-panels.js' ) ),
					'ios-install' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/surface-panels.js' ) ),
					'appsite-home' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/surface-panels.js' ) ),
					'games' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/surface-panels.js' ) ),
					'menu' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/surface-panels.js' ) ),
				],
				'games'      => $this->games_data(),
				'ai'         => $this->ai_data(),
				'dock'       => $settings['dock'] ?? [],
				'trust'      => $trust_summary,
				'protectedFlow' => $protected_flow,
				'commerce'   => $commerce,
				'metrics'    => $this->metrics->public_config(),
				'permissions' => $this->permissions->public_config(),
				'notificationPreferences' => $this->notification_preferences->public_config(),
				'pwa'        => $this->pwa->public_config(),
				'enhancements' => $enhancements,
				'links'      => $this->links_data(),
				'secure'     => $this->secure_data(),
				'phonekey'   => $phonekey,
				'debug'      => $debug,
				'registry'   => [
					'availableInFooter' => true,
				],
				'navigation' => [
					'enabled' => false,
					'policy'  => $route_policy,
					'envelope' => [
						'version' => 1,
						'observeOnly' => true,
						'endpoint' => esc_url_raw( rest_url( 'dsa/v1/editorial-envelope' ) ),
						'morphingEnabled' => false,
					],
					'reconciliation' => [
						'version' => 1,
						'observeOnly' => true,
						'applyEnabled' => ! empty( $settings['visual_effects']['editorial_morph_navigation'] ),
						'endpoint' => esc_url_raw( rest_url( 'dsa/v1/editorial-envelope' ) ),
						'moduleUrl' => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/reconciliation.js' ) ),
					],
				],
			]
		);
	}

	private function search_context(): array {
		$config   = wp_parse_args( $this->settings->get( 'search', [] ), $this->settings->defaults()['search'] );
		$families = wp_parse_args( is_array( $config['families'] ?? null ) ? $config['families'] : [], $this->settings->defaults()['search']['families'] );
		$custom_taxonomies = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) ( $config['custom_taxonomies'] ?? [] ) ),
				static function ( string $taxonomy ): bool {
					if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
						return false;
					}
					$object = get_taxonomy( $taxonomy );
					return $object && ! empty( $object->public );
				}
			)
		);
		$scope  = 'all';
		$reason = 'site';

		if ( ! empty( $config['context_aware'] ) && ! empty( $families['products'] ) && function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
			$scope  = 'products';
			$reason = is_shop() ? 'shop' : 'product_archive';
		} elseif ( ! empty( $config['context_aware'] ) && ! empty( $families['products'] ) && is_post_type_archive( 'product' ) ) {
			$scope  = 'products';
			$reason = 'product_archive';
		} elseif ( ! empty( $config['context_aware'] ) && ! empty( $families['posts'] ) && is_home() ) {
			$scope  = 'posts';
			$reason = 'posts_page';
		} elseif ( ! empty( $config['context_aware'] ) && ! empty( $families['authors'] ) && is_author() ) {
			$scope  = 'authors';
			$reason = 'author_archive';
		} elseif ( ! empty( $config['context_aware'] ) && ! empty( $families['posts'] ) && ( is_category() || is_tag() || is_date() || is_post_type_archive( 'post' ) ) ) {
			$scope  = 'posts';
			$reason = 'post_archive';
		}

		return [
			'scope'        => $scope,
			'reason'       => $reason,
			'bricksNative' => defined( 'BRICKS_VERSION' ),
			'hasCommerce'  => function_exists( 'wc_get_product' ) && post_type_exists( 'product' ),
			'families'     => [
				'products' => ! empty( $families['products'] ),
				'posts'    => ! empty( $families['posts'] ),
				'authors'  => ! empty( $families['authors'] ),
				'categories' => ! empty( $custom_taxonomies ),
			],
			'customCategories' => array_map(
				static function ( string $taxonomy ): array {
					$object = get_taxonomy( $taxonomy );
					return [
						'taxonomy' => $taxonomy,
						'label'    => $object ? (string) $object->labels->name : $taxonomy,
					];
				},
				$custom_taxonomies
			),
		];
	}

	private function debug_data( array $settings ): array {
		$diagnostics = isset( $settings['diagnostics'] ) && is_array( $settings['diagnostics'] )
			? $settings['diagnostics']
			: [];
		$enabled     = ! empty( $diagnostics['enabled'] );

		return [
			'enabled'       => $enabled && ! empty( $diagnostics['frontend_debug'] ),
			'console'       => $enabled && ! empty( $diagnostics['console_logs'] ),
			'label'         => 'Kiwe DSA',
			'wpDebugActive' => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
		];
	}

	private function enhancement_data( array $settings ): array {
		$enhancements = isset( $settings['enhancements'] ) && is_array( $settings['enhancements'] )
			? wp_parse_args( $settings['enhancements'], $this->settings->defaults()['enhancements'] )
			: $this->settings->defaults()['enhancements'];
		$enabled = ! empty( $enhancements['enabled'] );

		return [
			'version' => 1,
			'htmx'    => [
				'enabled' => $enabled && ! empty( $enhancements['htmx'] ),
				'version' => self::HTMX_VERSION,
				'scope'   => 'server-fragment-pilots',
			],
			'alpine'  => [
				'enabled' => $enabled && ! empty( $enhancements['alpine'] ),
				'version' => self::ALPINE_VERSION,
				'scope'   => 'isolated-local-widget-pilots',
			],
		];
	}

	private function dock_with_nav_menu_items( array $dock ): array {
		$custom_items = isset( $dock['menu_items'] ) && is_array( $dock['menu_items'] ) ? $dock['menu_items'] : [];
		$menu_ids     = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $dock['menu_nav_ids'] ?? [] ) ) ) ) );
		if ( ! $menu_ids && ! empty( $dock['menu_nav_id'] ) ) {
			$menu_ids[] = absint( $dock['menu_nav_id'] );
		}

		$groups = [];
		$flat   = [];
		$seen   = [];

		foreach ( $menu_ids as $menu_id ) {
			if ( ! is_nav_menu( $menu_id ) ) {
				continue;
			}

			$menu  = wp_get_nav_menu_object( $menu_id );
			$items = wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] );
			$out   = [];

			foreach ( is_array( $items ) ? $items : [] as $item ) {
				if ( ! is_object( $item ) || empty( $item->url ) ) {
					continue;
				}

				$normalized = [
					'title'       => sanitize_text_field( (string) $item->title ),
					'url'         => esc_url_raw( (string) $item->url ),
					'type'        => sanitize_text_field( (string) ( $item->type_label ?? __( 'Navigation', 'dsa' ) ) ),
					'image'       => $this->menu_item_image( $item ),
					'object_id'   => absint( $item->object_id ?? 0 ),
					'object_type' => sanitize_key( (string) ( $item->object ?? '' ) ),
					'menu_parent' => absint( $item->menu_item_parent ?? 0 ),
				];
				$key = strtolower( $normalized['url'] . '|' . $normalized['title'] );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[] = $normalized;
				$flat[] = $normalized;
			}

			if ( $out ) {
				$groups[] = [
					'label' => sanitize_text_field( (string) ( $menu->name ?? __( 'Navigation', 'dsa' ) ) ),
					'items' => array_slice( $out, 0, 40 ),
				];
			}
		}

		$custom = [];
		foreach ( $custom_items as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) || empty( $item['title'] ) ) {
				continue;
			}
			$key = strtolower( (string) $item['url'] . '|' . (string) $item['title'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$custom[] = $item;
			$flat[] = $item;
		}

		if ( $custom ) {
			$groups[] = [ 'label' => __( 'More', 'dsa' ), 'items' => array_slice( $custom, 0, 40 ) ];
		}

		$dock['menu_groups']  = $groups;
		$dock['menu_items']   = array_slice( $flat, 0, 80 );
		$dock['menu_context'] = $this->menu_context_contract( $dock );
		return $dock;
	}

	private function menu_context_contract( array $dock ): array {
		$locations = wp_parse_args(
			is_array( $dock['menu_context_locations'] ?? null ) ? $dock['menu_context_locations'] : [],
			[
				'everywhere'     => false,
				'single_post'    => true,
				'single_product' => true,
				'front_page'     => false,
				'selected_pages' => true,
			]
		);
		$active = false;
		$reason = 'disabled';

		if ( ! empty( $dock['menu_context_enabled'] ) ) {
			if ( ! empty( $locations['everywhere'] ) ) {
				$active = true;
				$reason = 'everywhere';
			} elseif ( ! empty( $locations['single_product'] ) && is_singular( 'product' ) ) {
				$active = true;
				$reason = 'single_product';
			} elseif ( ! empty( $locations['single_post'] ) && is_singular( 'post' ) ) {
				$active = true;
				$reason = 'single_post';
			} elseif ( ! empty( $locations['front_page'] ) && is_front_page() ) {
				$active = true;
				$reason = 'front_page';
			} elseif ( ! empty( $locations['selected_pages'] ) && is_page() ) {
				$page_ids = array_map( 'absint', (array) ( $dock['menu_context_page_ids'] ?? [] ) );
				$active   = in_array( get_queried_object_id(), $page_ids, true );
				$reason   = $active ? 'selected_page' : 'page_not_selected';
			}
		}

		$levels = array_values( array_intersect( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], (array) ( $dock['menu_context_heading_levels'] ?? [ 'h1', 'h2', 'h3' ] ) ) );

		$title = sanitize_text_field( (string) ( $dock['menu_context_title'] ?? __( 'Table of contents', 'dsa' ) ) );
		if ( 'on this page' === strtolower( trim( $title ) ) ) {
			$title = __( 'Table of contents', 'dsa' );
		}

		return [
			'enabled'       => ! empty( $dock['menu_context_enabled'] ),
			'active'        => $active,
			'title'         => $title,
			'headingLevels' => $levels ?: [ 'h1', 'h2', 'h3' ],
			'reason'        => $reason,
		];
	}

	private function menu_item_image( $item ): string {
		$object_id = absint( $item->object_id ?? 0 );
		$object    = sanitize_key( (string) ( $item->object ?? '' ) );

		if ( $object_id && 'product_cat' === $object ) {
			$thumbnail_id = absint( get_term_meta( $object_id, 'thumbnail_id', true ) );
			$url = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '';
			return $url ? esc_url_raw( $url ) : '';
		}

		if ( $object_id && in_array( $object, get_post_types( [ 'public' => true ] ), true ) ) {
			$url = get_the_post_thumbnail_url( $object_id, 'medium' );
			return $url ? esc_url_raw( $url ) : '';
		}

		return '';
	}

	private function neutral_protected_flow( array $settings ): array {
		return [
			'active'           => false,
			'context'          => '',
			'fragmentAllowed'  => true,
			'outsideDismiss'   => true,
			'requiresFullPage' => false,
			'message'          => '',
			'railEnabled'      => ! empty( $settings['protected_flow']['rail_enabled'] ),
		];
	}

	private function native_readonly_data( array $trust_summary, array $commerce, array $phonekey ): array {
		return [
			'version' => 1,
			'site'    => [
				'title'     => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'tagline'   => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
				'homeUrl'   => home_url( '/' ),
				'icon'      => get_site_icon_url( 192 ) ?: '',
				'logo'        => Site_Identity_Service::logo_url(),
				'logoInverse' => Site_Identity_Service::logo_url( 'inverse' ),
			],
			'trust'   => [
				'badges' => isset( $trust_summary['health'] ) && is_array( $trust_summary['health'] ) ? array_map(
					static function ( array $badge ): array {
						return [
							'label'  => sanitize_text_field( $badge['label'] ?? '' ),
							'active' => ! empty( $badge['active'] ),
						];
					},
					$trust_summary['health']
				) : [],
			],
			'profile' => [
				'loggedIn'   => false,
				'displayName' => '',
				'avatar'     => '',
				'badgeCount' => 0,
			],
			'cart'    => [
				'count'      => 0,
				'itemCount'  => 0,
				'total'      => '',
				'subtotal'   => '',
				'discount'   => '',
			],
		];
	}

	public function print_phantom_viewport_seed(): void {
		if ( ! $this->settings->get( 'enabled', true ) || ! Environment::should_render_frontend() ) {
			return;
		}

		$footprint = $this->settings->manifest()['footprint'];
		$visual    = $this->settings->get( 'visual_effects', [] );
		$theme     = $this->settings->get( 'dsa_theme', [] );
		$style     = $this->settings->get( 'style', [] );
		$theme_mode = in_array( $style['mode'] ?? 'classic', [ 'classic', 'sheet' ], true ) ? (string) $style['mode'] : 'classic';
		$blur      = isset( $visual['blur_strength'] ) ? max( 0, min( 24, (int) $visual['blur_strength'] ) ) : 10;
		$active    = sanitize_hex_color( $theme['active_color'] ?? '' ) ?: '#8f8f98';
		$hover     = sanitize_hex_color( $theme['hover_color'] ?? '' ) ?: '#24c6a1';
		$hero      = preg_match( '/^(#[0-9a-f]{3,6}|rgba?\([^)]+\))$/i', (string) ( $theme['hero_text_color'] ?? '' ) ) ? (string) $theme['hero_text_color'] : 'rgba(20,24,34,0.18)';
		$kiwe_tokens = Seam_Token_Service::css_variables(
			[
				'color-brand' => $active,
				'color-accent' => $hover,
				'color-hero' => $hero,
				'glass-blur' => $blur . 'px',
			]
		);
		$kiwe_css = '';

		foreach ( $kiwe_tokens as $name => $value ) {
			$kiwe_css .= $name . ':' . $value . ';';
		}

		echo '<!-- DSA wp_head active ' . esc_html( DSA_VERSION ) . ' -->' . "\n";
		printf(
			'<style id="dsa-phantom-seed">:root{%9$s--dsa-surface-top:%1$dpx;--dsa-surface-right:%2$dpx;--dsa-surface-bottom:%3$dpx;--dsa-surface-left:%4$dpx;--dsa-content-vw:calc(100vw - var(--dsa-surface-left) - var(--dsa-surface-right));--dsa-content-vh:calc(100vh - var(--dsa-surface-top) - var(--dsa-surface-bottom));--dsa-blur-strength:var(--kiwe-glass-blur,%5$dpx);--dsa-active-color:var(--kiwe-color-brand,%6$s);--dsa-hover-color:var(--kiwe-color-accent,%7$s);--dsa-hero-text-color:var(--kiwe-color-hero,%8$s);}</style>' . "\n",
			(int) $footprint['top'],
			(int) $footprint['right'],
			(int) $footprint['bottom'],
			(int) $footprint['left'],
			$blur,
			esc_html( $active ),
			esc_html( $hover ),
			esc_html( $hero ),
			esc_html( $kiwe_css )
		);
		echo '<script id="dsa-color-mode-seed">(function(){try{var r=document.documentElement,s=localStorage.getItem("brx_mode")||localStorage.getItem("kiwe_color_mode")||"light";if(s==="auto"){s=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";}if(s!=="dark"){s="light";}r.dataset.kiweTheme=s;r.dataset.kiweStyle="classic";r.dataset.kiweSurfaceTheme=' . wp_json_encode( $theme_mode ) . ';}catch(e){document.documentElement.dataset.kiweTheme="light";document.documentElement.dataset.kiweStyle="classic";document.documentElement.dataset.kiweSurfaceTheme=' . wp_json_encode( $theme_mode ) . ';}})();</script>' . "\n";

	}

	private function kiwe_tokens_data(): array {
		$visual = $this->settings->get( 'visual_effects', [] );
		$theme = $this->settings->get( 'dsa_theme', [] );
		$active = sanitize_hex_color( $theme['active_color'] ?? '' ) ?: '#8f8f98';
		$hover = sanitize_hex_color( $theme['hover_color'] ?? '' ) ?: '#24c6a1';
		$hero = preg_match( '/^(#[0-9a-f]{3,6}|rgba?\([^)]+\))$/i', (string) ( $theme['hero_text_color'] ?? '' ) ) ? (string) $theme['hero_text_color'] : 'rgba(20,24,34,0.18)';
		$items = Seam_Token_Service::tokens_with_overrides(
			[
				'color-brand' => $active,
				'color-accent' => $hover,
				'color-hero' => $hero,
				'glass-blur' => max( 0, min( 24, absint( $visual['blur_strength'] ?? 10 ) ) ) . 'px',
			]
		);

		return [
			'enabled'         => true,
			'source'          => 'kiwe.universal',
			'count'           => count( $items ),
			'items'           => array_slice( $items, 0, 80 ),
			'affectsSurface'  => true,
			'bricksAdditive'  => true,
		];
	}

	private function games_data(): array {
		$config = $this->settings->get( 'games', [] );
		$config = is_array( $config ) ? $config : [];
		$bonuses = isset( $config['bonuses'] ) && is_array( $config['bonuses'] ) ? $config['bonuses'] : [];
		$retry_texts = isset( $config['retry_texts'] ) && is_array( $config['retry_texts'] ) ? $config['retry_texts'] : [];
		$out_bonuses = [];
		$out_retry = [];

		for ( $i = 0; $i < 3; $i++ ) {
			$bonus = isset( $bonuses[ $i ] ) && is_array( $bonuses[ $i ] ) ? $bonuses[ $i ] : [];
			$out_bonuses[] = [
				'label'    => sanitize_text_field( $bonus['label'] ?? sprintf( __( 'Attempt %d high score', 'dsa' ), $i + 1 ) ),
				'discount' => max( 0, min( 100, (int) ( $bonus['discount'] ?? 0 ) ) ),
			];
			$out_retry[] = sanitize_text_field( $retry_texts[ $i ] ?? '' );
		}

		return [
			'moduleUrl'       => esc_url_raw( add_query_arg( 'ver', DSA_VERSION, DSA_URL . 'assets/js/modules/games-engine.js' ) ),
			'surfaceEnabled'  => ! empty( $config['surface_enabled'] ),
			'showOnPageLoad'  => ! empty( $config['show_on_page_load'] ),
			'triggerPath'     => sanitize_text_field( $config['trigger_path'] ?? '/shop' ),
			'triggerGame'     => in_array( $config['trigger_game'] ?? 'dino', [ 'dino', 'star' ], true ) ? sanitize_key( $config['trigger_game'] ) : 'dino',
			'startTitle'      => sanitize_text_field( $config['start_title'] ?? __( 'Are You Game! for discount??', 'dsa' ) ),
			'startText'       => sanitize_text_field( $config['start_text'] ?? __( 'Press any key to start', 'dsa' ) ),
			'mobileStartText' => sanitize_text_field( $config['mobile_start_text'] ?? __( 'Touch to start', 'dsa' ) ),
			'durationMs'      => max( 0, min( 60000, (int) ( $config['duration_ms'] ?? 0 ) ) ),
			'confettiEnabled' => ! empty( $config['confetti_enabled'] ),
			'bonuses'         => $out_bonuses,
			'retryTexts'      => $out_retry,
			'reward'          => $this->rewards->public_config(),
			'games'           => [
				[ 'id' => 'dino', 'label' => __( 'Dinosaur Jump', 'dsa' ) ],
				[ 'id' => 'star', 'label' => __( 'Star Shooter', 'dsa' ) ],
			],
		];
	}

	public function print_boot_seed(): void {
		if ( ! $this->settings->get( 'enabled', true ) || ! Environment::should_render_frontend() ) {
			return;
		}

		printf(
			'<script id="dsa-boot-seed">window.DSA=window.DSA||{};window.DSA.boot={version:%1$s,loadedAt:%2$s,registry:{elements:[],count:0,pending:true}};</script>' . "\n",
			wp_json_encode( DSA_VERSION ),
			'0'
		);
	}

	public function print_initial_preloader_style(): void {
		if ( ! $this->should_print_appsite_assets() ) {
			return;
		}

		?>
		<script id="dsa-appsite-session-seed">
			try{if(window.sessionStorage&&window.sessionStorage.getItem('dsa_appsite_home_seen')==='1'){document.documentElement.classList.add('dsa-appsite-home-seen');}}catch(error){}
		</script>
		<style id="dsa-initial-preloader-style">
			.dsa-initial-preloader,.dsa-initial-preloader *,.dsa-initial-preloader *:before,.dsa-initial-preloader *:after{box-sizing:border-box}.dsa-initial-preloader{position:fixed;inset:0;z-index:1000004;display:grid;align-items:safe center;width:100%;min-height:100vh;min-height:100dvh;max-width:100vw;padding:clamp(28px,5vw,96px);overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;touch-action:none;background:linear-gradient(115deg,rgba(245,247,251,.80),rgba(223,229,232,.62));-webkit-backdrop-filter:blur(var(--dsa-blur-strength,14px)) saturate(1.18);backdrop-filter:blur(var(--dsa-blur-strength,14px)) saturate(1.18);color:rgba(20,24,34,.48);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;transform:translateY(var(--dsa-appsite-shift,0));opacity:var(--dsa-appsite-opacity,1);transition:transform 180ms ease,opacity 180ms ease}
			.dsa-appsite-home-seen .dsa-initial-preloader[data-dsa-session-home]{display:none}
			.dsa-initial-preloader[hidden]{display:none}
			.dsa-initial-preloader.is-dragging{transition:none}.dsa-initial-preloader.is-dismissing{transform:translateY(-110%);opacity:.2}
			.dsa-initial-preloader__inner{width:min(1040px,100%);min-width:0;max-width:100%;display:grid;gap:clamp(14px,2.3vw,28px)}
			.dsa-initial-preloader__title{margin:0;font-size:clamp(13px,1.2vw,18px);font-weight:900;line-height:1;letter-spacing:0;text-transform:uppercase;color:var(--dsa-hero-text-color,rgba(20,24,34,.22))}
			.dsa-initial-preloader__hero{margin:0;width:100%;max-width:12ch;font-size:9rem;font-weight:950;line-height:.92;letter-spacing:0;color:var(--dsa-active-color,#8f8f98)!important;overflow-wrap:break-word;text-wrap:balance}
			.dsa-initial-preloader__message{margin:0;max-width:720px;font-size:clamp(20px,2.7vw,42px);font-weight:850;line-height:1.08;color:var(--dsa-hero-text-color,rgba(20,24,34,.2))}
			.dsa-initial-preloader__clock{font-size:clamp(18px,2vw,30px);font-weight:850;color:color-mix(in srgb,var(--dsa-active-color,#8f8f98) 62%,transparent)}
			.dsa-initial-preloader__unlock{display:inline-flex;align-items:center;gap:10px;width:max-content;max-width:100%;font-size:clamp(13px,1.15vw,17px);font-weight:850;color:rgba(20,24,34,.28)}
			.dsa-initial-preloader__unlock span{display:block;width:24px;height:38px;border:2px solid currentColor;border-radius:999px;position:relative}
			.dsa-initial-preloader__unlock span:after{content:"";position:absolute;left:50%;top:9px;width:4px;height:9px;border-radius:999px;background:currentColor;transform:translateX(-50%);animation:dsa-home-scroll-cue 1.25s ease-in-out infinite}
			@keyframes dsa-home-scroll-cue{0%{opacity:.28;transform:translate(-50%,0)}50%{opacity:.82;transform:translate(-50%,10px)}100%{opacity:.28;transform:translate(-50%,0)}}
			.dsa-initial-preloader__actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:clamp(4px,1vw,14px)}
			.dsa-initial-preloader__app-pitch{margin:2px 0 -4px;font-size:clamp(14px,1.25vw,18px);font-weight:850;color:rgba(20,24,34,.42)}
			.dsa-app-badge{display:grid;grid-template-columns:40px minmax(0,1fr);align-items:center;gap:10px;width:210px;min-height:58px;padding:8px 13px;border:1px solid rgba(255,255,255,.42);border-radius:8px;background:rgba(18,21,27,.88);box-shadow:inset 0 1px 0 rgba(255,255,255,.13),0 14px 36px rgba(20,24,34,.16);color:#fff;cursor:pointer;text-align:left;text-decoration:none;transition:transform 160ms ease,background 160ms ease,box-shadow 160ms ease}
			.dsa-app-badge:hover,.dsa-app-badge:focus-visible{background:color-mix(in srgb,var(--dsa-hover-color,#24c6a1) 72%,#11151c);box-shadow:inset 0 1px 0 rgba(255,255,255,.2),0 18px 42px rgba(20,24,34,.2);outline:none;transform:translateY(-2px)}
			.dsa-app-badge__icon{position:relative;display:block;width:36px;height:36px;margin:auto;border:0;border-radius:8px;overflow:hidden}
			.dsa-app-badge__icon svg{display:block;width:100%;height:100%}
			.dsa-app-badge__icon:before,.dsa-app-badge__icon:after{display:none!important}
			.dsa-app-badge__icon:before{content:"";position:absolute;left:50%;top:3px;width:8px;height:2px;border-radius:3px;background:currentColor;transform:translateX(-50%)}
			.dsa-app-badge__icon:after{content:"";position:absolute;left:50%;bottom:3px;width:3px;height:3px;border-radius:50%;background:currentColor;transform:translateX(-50%)}
			.dsa-app-badge__icon--android{height:25px;margin-top:8px;border-radius:7px 7px 4px 4px}
			.dsa-app-badge__icon--android:before{top:-8px;width:19px;height:12px;border:2px solid currentColor;border-bottom:0;border-radius:12px 12px 0 0;background:transparent}
			.dsa-app-badge__icon--android:after{left:7px;top:-3px;bottom:auto;width:3px;height:3px;box-shadow:10px 0 0 currentColor;transform:none}
			.dsa-app-badge__copy{display:grid;gap:2px;min-width:0}.dsa-app-badge__copy small{font-size:9px;font-weight:750;line-height:1.1;text-transform:uppercase}.dsa-app-badge__copy strong{font-size:16px;font-weight:900;line-height:1.05}
			.dsa-initial-preloader__app-status{width:min(620px,100%);margin:0;padding:10px 12px;border-left:3px solid var(--dsa-hover-color,#24c6a1);background:rgba(255,255,255,.32);color:rgba(20,24,34,.62);font-size:14px;font-weight:750}
			.dsa-initial-preloader__app-status[hidden]{display:none}
			.dsa-home-trust{display:flex;flex-wrap:wrap;gap:8px;width:min(760px,100%)}
			.dsa-home-trust__badge{display:inline-flex;align-items:center;gap:7px;min-height:30px;padding:6px 10px;border:1px solid rgba(255,255,255,.5);border-radius:999px;background:rgba(255,255,255,.34);color:rgba(20,24,34,.55);font-size:11px;font-weight:850}
			.dsa-home-trust__badge i{width:7px;height:7px;border-radius:50%;background:#94a3b8;box-shadow:0 0 0 3px rgba(148,163,184,.16)}.dsa-home-trust__badge.is-active i{background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.16)}
			.dsa-initial-preloader__button{appearance:none;border:1px solid rgba(255,255,255,.58);border-radius:999px;background:rgba(255,255,255,.34);box-shadow:inset 0 1px 0 rgba(255,255,255,.52),0 18px 44px rgba(20,24,34,.08);color:color-mix(in srgb,var(--dsa-active-color,#8f8f98) 82%,#101827);cursor:pointer;font:800 15px/1 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:14px 18px;text-decoration:none;transition:background 140ms ease,color 140ms ease,transform 140ms ease}
			.dsa-initial-preloader__button:hover,.dsa-initial-preloader__button:focus-visible{background:rgba(255,255,255,.54);color:var(--dsa-hover-color,#24c6a1);outline:none;transform:translateY(-1px)}
			@media (max-width:1100px){.dsa-initial-preloader__hero{font-size:7rem}}@media (max-width:800px){.dsa-initial-preloader__hero{font-size:5.5rem}}
			@media (max-width:640px){.dsa-initial-preloader{align-items:start;padding:max(20px,env(safe-area-inset-top)) 20px max(20px,env(safe-area-inset-bottom))}.dsa-initial-preloader__inner{gap:12px;margin-block:auto}.dsa-initial-preloader__hero{font-size:3.75rem;line-height:.96}.dsa-initial-preloader__actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.dsa-app-badge{width:100%;min-width:0;min-height:62px}.dsa-initial-preloader__button{text-align:center}}
			@media (max-width:420px){.dsa-initial-preloader__hero{font-size:3rem}.dsa-initial-preloader__actions{grid-template-columns:1fr}}@media (max-width:360px){.dsa-initial-preloader__hero{font-size:2.625rem}}
		</style>
		<noscript><style>#dsa-initial-preloader{display:none!important}</style></noscript>
		<?php
	}

	public function print_initial_preloader(): void {
		if ( ! $this->should_print_initial_preloader() || $this->initial_preloader_printed ) {
			return;
		}

		$this->initial_preloader_printed = true;
		$app        = $this->app_data();
		$tagline    = trim( (string) get_bloginfo( 'description' ) );
		$site_title = trim( (string) get_bloginfo( 'name' ) );
		$title      = '' !== $tagline ? $tagline : __( 'Welcome', 'dsa' );
		$hero       = '' !== $site_title ? $site_title : __( 'Our Appsite', 'dsa' );
		$message    = '' !== $app['welcomeMessage'] ? $app['welcomeMessage'] : __( 'Welcome to Our Appsite', 'dsa' );
		$pwa_pitch  = '' !== $app['pwaPitch'] ? $app['pwaPitch'] : __( 'Try our app. No app store required.', 'dsa' );
		$buttons    = $app['buttons'];
		$trust_badges = $this->home_trust_badges();
		?>
		<div id="dsa-initial-preloader" class="dsa-initial-preloader" data-dsa-initial-preloader data-dsa-session-home data-nosnippet role="status" aria-live="polite" aria-label="<?php esc_attr_e( 'Kiwe Appsite home screen', 'dsa' ); ?>">
			<div class="dsa-initial-preloader__inner">
				<p class="dsa-initial-preloader__title"><?php echo esc_html( $title ); ?></p>
				<div class="dsa-initial-preloader__hero" role="heading" aria-level="2"><?php echo esc_html( $hero ); ?></div>
				<p class="dsa-initial-preloader__message"><?php echo esc_html( $message ); ?></p>
				<div class="dsa-initial-preloader__clock" data-dsa-initial-clock></div>
				<div class="dsa-initial-preloader__unlock" aria-hidden="true"><span></span><strong><?php esc_html_e( 'Scroll, swipe up, or press ↓ to enter', 'dsa' ); ?></strong></div>
				<p class="dsa-initial-preloader__app-pitch"><?php echo esc_html( $pwa_pitch ); ?></p>
				<div class="dsa-initial-preloader__actions" data-dsa-keep-open>
					<?php foreach ( $buttons as $button ) : ?>
						<?php if ( ! empty( $button['url'] ) ) : ?>
							<a class="dsa-app-badge" href="<?php echo esc_url( $button['url'] ); ?>" data-dsa-initial-action>
						<?php else : ?>
							<button class="dsa-app-badge" type="button" data-dsa-initial-action data-dsa-install-pwa data-dsa-pwa-platform="<?php echo esc_attr( $button['platform'] ?? $button['id'] ?? '' ); ?>">
						<?php endif; ?>
							<span class="dsa-app-badge__icon dsa-app-badge__icon--<?php echo esc_attr( $button['icon'] ?? $button['platform'] ?? $button['id'] ?? '' ); ?>" aria-hidden="true"><?php echo $this->app_badge_icon( (string) ( $button['icon'] ?? $button['platform'] ?? $button['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="dsa-app-badge__copy"><small><?php echo esc_html( $button['eyebrow'] ?? '' ); ?></small><strong><?php echo esc_html( $button['label'] ?? '' ); ?></strong></span>
						<?php if ( ! empty( $button['url'] ) ) : ?></a><?php else : ?></button><?php endif; ?>
					<?php endforeach; ?>
				</div>
				<?php if ( ! empty( $trust_badges ) ) : ?>
					<div class="dsa-home-trust" aria-label="<?php esc_attr_e( 'Site trust', 'dsa' ); ?>">
						<?php foreach ( $trust_badges as $badge ) : ?>
							<span class="dsa-home-trust__badge<?php echo ! empty( $badge['active'] ) ? ' is-active' : ''; ?>"><i aria-hidden="true"></i><?php echo esc_html( $badge['label'] ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<p class="dsa-initial-preloader__app-status" data-dsa-pwa-status hidden aria-live="polite"></p>
			</div>
		</div>
		<?php
	}

	public function print_initial_preloader_fallback(): void {
		$this->print_initial_preloader();
	}

	private function should_print_initial_preloader(): bool {
		$contract = $this->trigger_contract();

		return $this->settings->get( 'enabled', true )
			&& Environment::should_render_frontend()
			&& $this->trigger_rule_enabled( $contract, 'first_session_home' );
	}

	private function should_print_appsite_assets(): bool {
		$contract = $this->trigger_contract();

		return $this->settings->get( 'enabled', true )
			&& Environment::should_render_frontend()
			&& (
				$this->trigger_rule_enabled( $contract, 'first_session_home' )
				|| $this->trigger_rule_enabled( $contract, 'idle_home' )
			);
	}

	private function trigger_contract(): array {
		$settings       = $this->settings->all();
		$manifest       = $this->settings->manifest();
		$protected_flow = $this->flow_guard->current();

		return $this->triggers->contract( $settings, $manifest, $protected_flow );
	}

	private function trigger_rule_enabled( array $contract, string $id ): bool {
		$rules = isset( $contract['rules'] ) && is_array( $contract['rules'] ) ? $contract['rules'] : [];

		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && ( $rule['id'] ?? '' ) === $id ) {
				return ! empty( $rule['enabled'] );
			}
		}

		return false;
	}

	private function app_data(): array {
		$config = $this->settings->get( 'app', [] );
		$config = is_array( $config ) ? $config : [];

		return [
			'welcomeMessage' => sanitize_text_field( $config['welcome_message'] ?? __( 'Welcome to Our Appsite', 'dsa' ) ),
			'pwaPitch'       => sanitize_text_field( $config['pwa_pitch'] ?? __( 'Try our app. No app store required.', 'dsa' ) ),
			'idle'           => [
				'enabled' => ! empty( $config['idle_enabled'] ),
				'delayMs' => max( 10000, min( 1800000, (int) ( $config['idle_delay_ms'] ?? 60000 ) ) ),
			],
			'buttons'        => [
				[
					'id'       => 'ios',
					'platform' => 'ios',
					'icon'     => 'appstore',
					'eyebrow'  => __( 'iPhone & iPad', 'dsa' ),
					'label'    => __( 'Add to Home Screen', 'dsa' ),
					'url'      => esc_url_raw( $config['ios_url'] ?? '' ),
				],
				[
					'id'       => 'android',
					'platform' => 'android',
					'icon'     => 'play',
					'eyebrow'  => __( 'Android', 'dsa' ),
					'label'    => __( 'Add to Home Screen', 'dsa' ),
					'url'      => esc_url_raw( ! empty( $config['playstore_url'] ) ? $config['playstore_url'] : ( $config['android_url'] ?? '' ) ),
				],
			],
		];
	}

	private function home_trust_badges(): array {
		$config = $this->settings->get( 'link_hub', [] );
		$badges = $this->trust->health_data( is_array( $config ) ? $config : [] );

		return array_values(
			array_filter(
				$badges,
				static function ( array $badge ): bool {
					return ! empty( $badge['label'] );
				}
			)
		);
	}

	private function app_badge_icon( string $icon ): string {
		if ( 'play' === $icon ) {
			return '<svg viewBox="0 0 48 48" role="img" aria-label="Google Play"><path fill="#34a853" d="M7 5.5 28.2 24 7 42.5c-.7-.8-1-1.9-1-3.2V8.7c0-1.3.3-2.4 1-3.2Z"/><path fill="#4285f4" d="m28.2 24 6.7-5.8L10.8 4.7A4.8 4.8 0 0 0 7 5.5L28.2 24Z"/><path fill="#fbbc04" d="m28.2 24 6.7 5.8L10.8 43.3A4.8 4.8 0 0 1 7 42.5L28.2 24Z"/><path fill="#ea4335" d="m34.9 18.2 6.1 3.4c1.4.8 1.4 4 0 4.8l-6.1 3.4-6.7-5.8 6.7-5.8Z"/></svg>';
		}

		return '<svg viewBox="0 0 48 48" role="img" aria-label="App Store"><rect x="3" y="3" width="42" height="42" rx="10" fill="#0a84ff"/><path d="M16 34 27 14m-7-1 14 21M13 28h23" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round"/></svg>';
	}

	private function module_context( array $dock ): array {
		return [
			'phonekey_visible' => true,
			'secure_available' => $this->securetrack_available(),
			'badges'           => [
				'profile' => 0,
				'cart'    => 0,
				'ai'      => 0,
			],
			'labels'           => [
				'menu' => $dock['menu_label'] ?? __( 'Menu', 'dsa' ),
			],
		];
	}

	private function ai_data(): array {
		$permissions = $this->settings->get( 'permissions', [] );
		$permissions = is_array( $permissions ) ? $permissions : [];

		return [
			'enabled'       => true,
			'canUseCopilot' => false,
			'restUrl'       => esc_url_raw( rest_url( 'dsa/v1/copilot' ) ),
			'mode'          => 'visitor-insights',
			'popupDurationMs' => max( 2000, min( 15000, (int) ( $permissions['ai_popup_duration_ms'] ?? 3200 ) ) ),
		];
	}

	private function secure_data(): array {
		return [
			'available' => false,
			'links'     => [],
		];
	}

	private function securetrack_available(): bool {
		return defined( 'STP_VER' ) || function_exists( 'stp_cfg' );
	}

	private function links_data(): array {
		$config = $this->settings->get( 'link_hub', [] );
		$config = is_array( $config ) ? $config : [];
		$dock = $this->settings->get( 'dock', [] );
		$commerce = $this->settings->get( 'commerce', [] );
		$commerce_available = $this->links_commerce_available();

		$data = [
			'siteName'        => get_bloginfo( 'name' ),
			'logo'            => $this->site_logo_url(),
			'score'           => '' === trim( (string) ( $config['site_score'] ?? '' ) ) ? null : max( 0, min( 100, (int) $config['site_score'] ) ),
			'socials'         => $this->social_links( $config ),
			'shop'            => [
				'label' => sanitize_text_field( $config['shop_label'] ?? __( 'Shop', 'dsa' ) ),
				'url'   => $this->shop_url( $config ),
			],
			'postsSection'    => $this->posts_section_data( $config ),
			'posts'           => $this->latest_posts( $config ),
			'review'          => $this->reviews->review_data( $config, true ),
			'health'          => $this->trust->health_data( $config ),
			'paymentGateways' => $this->trust->payment_gateways( $config ),
			'commerceAvailable' => $commerce_available,
			'cartAvailable'     => $commerce_available && ! empty( $commerce['cart_surface_enabled'] ) && ! empty( $dock['enabled_items']['cart'] ),
			'canEdit'         => false,
		];

		return $data;
	}

	private function site_logo_url(): string {
		return Site_Identity_Service::logo_url();
	}

	private function social_links( array $config ): array {
		$raw = isset( $config['social_links'] ) && is_array( $config['social_links'] ) ? $config['social_links'] : [];
		$out = [];

		foreach ( $this->social_link_labels() as $id => $label ) {
			$url = esc_url_raw( $raw[ $id ] ?? '' );

			if ( '' === $url ) {
				continue;
			}

			$out[] = [
				'id'    => $id,
				'label' => $label,
				'url'   => $url,
			];
		}

		return $out;
	}

	private function links_editor_data( array $config ): array {
		$raw = isset( $config['social_links'] ) && is_array( $config['social_links'] ) ? $config['social_links'] : [];
		$socials = [];

		foreach ( $this->social_link_labels() as $id => $label ) {
			$socials[] = [
				'id'    => $id,
				'label' => $label,
				'url'   => esc_url_raw( $raw[ $id ] ?? '' ),
			];
		}

		return [
			'siteScore'       => '' === trim( (string) ( $config['site_score'] ?? '' ) ) ? '' : max( 0, min( 100, (int) $config['site_score'] ) ),
			'shopLabel'       => sanitize_text_field( $config['shop_label'] ?? __( 'Shop', 'dsa' ) ),
			'shopUrl'         => esc_url_raw( $config['shop_url'] ?? '' ),
			'postsTitle'      => sanitize_text_field( $config['posts_title'] ?? '' ),
			'postsCategory'   => (int) ( $config['posts_category'] ?? 0 ),
			'categories'      => $this->post_category_options(),
			'sslProvider'     => sanitize_text_field( $config['ssl_provider'] ?? '' ),
			'paymentProvider' => sanitize_text_field( $config['payment_provider'] ?? '' ),
			'reviewSource'    => 'google' === ( $config['review_source'] ?? 'manual' ) ? 'google' : 'manual',
			'googlePlaceId'   => sanitize_text_field( $config['google_place_id'] ?? '' ),
			'hasGoogleApiKey' => ! empty( $config['google_api_key'] ),
			'testimonials'    => sanitize_textarea_field( $config['testimonials'] ?? '' ),
			'socials'         => $socials,
			'adminUrl'        => admin_url( 'admin.php?page=dsa-settings' ),
		];
	}

	private function social_link_labels(): array {
		return [
			'facebook'  => 'Facebook',
			'instagram' => 'Instagram',
			'x'         => 'X',
			'youtube'   => 'YouTube',
			'pinterest' => 'Pinterest',
			'linkedin'  => 'LinkedIn',
		];
	}

	private function shop_url( array $config ): string {
		if ( ! $this->links_commerce_available() ) {
			return '';
		}

		$url = esc_url_raw( $config['shop_url'] ?? '' );

		if ( '' !== $url ) {
			return $url;
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );

			if ( $shop ) {
				return esc_url_raw( $shop );
			}
		}

		return '';
	}

	private function links_commerce_available(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'wc_get_page_permalink' );
	}

	private function posts_section_data( array $config ): array {
		$category = $this->selected_post_category( $config );
		$title = sanitize_text_field( $config['posts_title'] ?? '' );

		if ( '' === $title && $category ) {
			$title = $category->name;
		}

		return [
			'title'      => $title ?: __( 'Latest Posts', 'dsa' ),
			'categoryId' => $category ? (int) $category->term_id : 0,
		];
	}

	private function latest_posts( array $config ): array {
		$args = [
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 8,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		];
		$category = $this->selected_post_category( $config );

		if ( $category ) {
			$args['cat'] = (int) $category->term_id;
		}

		$query = new \WP_Query( $args );
		$posts = [];

		foreach ( $query->posts as $post ) {
			$posts[] = [
				'title' => get_the_title( $post ),
				'url'   => get_permalink( $post ),
				'image' => get_the_post_thumbnail_url( $post, 'medium' ) ?: '',
			];
		}

		wp_reset_postdata();
		return $posts;
	}

	private function selected_post_category( array $config ) {
		$category_id = absint( $config['posts_category'] ?? 0 );

		if ( $category_id ) {
			$category = get_category( $category_id );

			if ( $category && ! is_wp_error( $category ) ) {
				return $category;
			}
		}

		$categories = get_categories(
			[
				'hide_empty' => true,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
				'number'     => 1,
			]
		);

		return ! empty( $categories ) ? $categories[0] : null;
	}

	private function post_category_options(): array {
		$categories = get_categories(
			[
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);
		$out = [
			[
				'id'    => 0,
				'label' => __( 'First available category', 'dsa' ),
			],
		];

		foreach ( $categories as $category ) {
			$out[] = [
				'id'    => (int) $category->term_id,
				'label' => $category->name,
			];
		}

		return $out;
	}

}
