<?php

namespace DSA;

use DSA\Diagnostics\Runtime_Profiler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	private const SAFETY_MIGRATION_VERSION = 3;

	private static bool $safety_migrations_checked = false;
	private ?array $resolved_settings = null;
	private ?array $resolved_manifest = null;

	public function all(): array {
		$profile = Runtime_Profiler::start();

		if ( is_array( $this->resolved_settings ) ) {
			Runtime_Profiler::finish( 'settings.all', $profile, true );
			return $this->resolved_settings;
		}

		$settings = get_option( DSA_OPTION_SETTINGS, [] );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$settings = $this->recursive_parse_args( $settings, $this->defaults() );
		$settings['fragment_navigation'] = false;

		$this->resolved_settings = $settings;
		Runtime_Profiler::finish( 'settings.all', $profile, false );

		return $this->resolved_settings;
	}

	public function run_migrations(): void {
		if ( self::$safety_migrations_checked ) {
			return;
		}

		self::$safety_migrations_checked = true;

		if ( (int) get_option( 'dsa_safety_migration_version', 0 ) >= self::SAFETY_MIGRATION_VERSION ) {
			return;
		}

		$settings = get_option( DSA_OPTION_SETTINGS, [] );
		$settings = is_array( $settings ) ? $settings : [];
		$changed = false;

		if ( get_option( 'dsa_idle_home_default_off_v2' ) !== 'done' ) {
			if ( isset( $settings['app'] ) && is_array( $settings['app'] ) ) {
				$settings['app']['idle_enabled'] = false;
			}

			update_option( 'dsa_idle_home_default_off_v2', 'done', false );
			$changed = true;
		}

		if ( get_option( 'dsa_secure_auto_logout_default_off_v2' ) !== 'done' ) {
			if ( isset( $settings['secure'] ) && is_array( $settings['secure'] ) ) {
				$settings['secure']['auto_logout_enabled'] = false;
				$settings['secure']['auto_logout_roles']   = [];
			}

			$stp_settings = get_option( 'stp_settings', [] );
			if ( is_array( $stp_settings ) ) {
				$stp_settings['idle_timeout_mins']  = 0;
				$stp_settings['idle_timeout_roles'] = [];
				update_option( 'stp_settings', $stp_settings, false );
			}

			update_option( 'dsa_secure_auto_logout_default_off_v2', 'done', false );
			$changed = true;
		}

		if ( get_option( 'dsa_link_score_legacy_default_blank_v3' ) !== 'done' ) {
			if ( isset( $settings['link_hub'] ) && is_array( $settings['link_hub'] ) && '96' === trim( (string) ( $settings['link_hub']['site_score'] ?? '' ) ) ) {
				$settings['link_hub']['site_score'] = '';
				$changed = true;
			}

			update_option( 'dsa_link_score_legacy_default_blank_v3', 'done', false );
		}

		$search = isset( $settings['search'] ) && is_array( $settings['search'] ) ? $settings['search'] : null;
		if ( is_array( $search ) && (int) ( $search['configuration_version'] ?? 0 ) < 2 ) {
			$families = isset( $search['families'] ) && is_array( $search['families'] ) ? $search['families'] : [];
			$collapsed_by_absent_form = empty( $families['products'] )
				&& ! empty( $families['posts'] )
				&& empty( $families['authors'] )
				&& empty( $search['context_aware'] )
				&& empty( $search['alphabet_enabled'] )
				&& empty( $search['product_add_enabled'] )
				&& empty( $search['bricks_bridge_enabled'] );

			if ( $collapsed_by_absent_form ) {
				$search = array_replace_recursive( $search, $this->defaults()['search'] );
			}

			$search['configuration_version'] = 2;
			$settings['search'] = $search;
			$changed = true;
		}

		if ( $changed ) {
			update_option( DSA_OPTION_SETTINGS, $settings, false );
			$this->resolved_settings = null;
			$this->resolved_manifest = null;
		}

		update_option( 'dsa_safety_migration_version', self::SAFETY_MIGRATION_VERSION, true );
	}

	public function defaults(): array {
		return [
				'enabled'             => true,
				'style'               => [
					'visual_profile'    => 'legacy',
					'mode'              => 'classic',
					'sheet_position'    => 'bottom',
					'sheet_animation'   => 'slide',
					'sheet_backdrop'    => 'blur',
					'sheet_duration_ms' => 320,
					'sheet_max_height'  => 82,
					'sheet_spacing'     => 'edge',
					'sheet_origin'      => 'bottom',
					'sheet_width_percent' => 78,
					'screen_heading_tag' => 'h2',
					'active_theme_id'   => 'legacy',
				],
				'position'            => 'right-center',
				'surface_width'       => 72,
				'surface_bottom'      => 24,
				'fragment_navigation' => false,
				'service_worker'      => false,
				'bricks_first'        => true,
				'diagnostics'         => [
					'enabled'             => false,
					'frontend_debug'      => false,
					'console_logs'        => false,
					'performance_profile' => false,
					'asset_manifest'      => false,
					'asset_build_pilot'   => false,
					'asset_build_apply'   => false,
					'asset_build_hints'   => false,
				],
				'enhancements'        => [
					'enabled' => false,
					'htmx'    => false,
					'alpine'  => false,
				],
				'protected_flow'      => [
					'rail_enabled' => false,
				],
				'secure'              => [
					'enabled'             => false,
					'auto_logout_enabled' => false,
					'auto_logout_minutes' => 30,
					'auto_logout_roles'   => [],
					'trusted_proxy_cidrs' => '',
				],
				'ai'                  => [
					'studio_enabled'          => false,
					'studio_mode'             => 'browser_companion',
					'native_provider'         => 'none',
					'native_model'            => '',
					'native_base_url'         => '',
					'native_api_key'          => '',
					'allow_native_generation' => false,
					'token_saver_enabled'     => true,
					'prefer_companion_context'=> true,
					'bricks_editor_companion_enabled' => false,
					'max_native_tokens'       => 1200,
					'max_native_context_bytes'=> 60000,
					'allow_browser_handoff_export' => true,
					'companion_enabled'        => false,
					'companion_modes'          => [
						'website'  => true,
						'theme'    => true,
						'combined' => true,
						'dynamic'  => true,
						'audit'    => true,
						'staging'  => true,
						'security' => false,
					],
					'securetrack_brief_enabled' => false,
					'memory_enabled'           => true,
					'memory_retention_days'    => 90,
					'max_context_cards'        => 12,
					'max_review_bytes'         => 120000,
					'cache_ttl_seconds'        => 300,
					'log_prompts'              => false,
				],
				'email'               => [
					'enabled'    => true,
					'transport'  => 'wordpress',
					'from_name'  => '',
					'from_email' => '',
					'smtp'       => [
						'host'       => '',
						'port'       => 587,
						'encryption' => 'tls',
						'auth'       => true,
						'username'   => '',
						'password'   => '',
					],
				],
				'abandoned_cart'      => [
					'enabled'                  => true,
					'manual_reminders_enabled' => true,
					'inactivity_minutes'       => 60,
					'heartbeat_minutes'        => 5,
					'cooldown_hours'           => 24,
					'max_reminders'            => 3,
					'recovery_link_days'       => 7,
					'retention_days'           => 90,
					'email_subject'             => 'You left something at {site_name}',
					'email_message'             => "Your {item_count}-item cart is still waiting at {site_name}.\n\nCart total: {cart_total}\n\nRestore your cart: {recovery_url}",
					'sms_message'               => '{site_name}: your cart is waiting. Restore it here: {recovery_url}',
					'whatsapp_message'          => '{site_name}: your cart is waiting. Restore it here: {recovery_url}',
					'channels'                  => [
						'sms'      => [
							'enabled'     => false,
							'webhook_url' => '',
							'api_token'   => '',
							'sender'      => '',
						],
						'whatsapp' => [
							'enabled'     => false,
							'webhook_url' => '',
							'api_token'   => '',
							'sender'      => '',
						],
					],
				],
				'commerce'            => [
					'cart_surface_enabled' => true,
					'checkout_surface_enabled' => true,
					'cart_quantity_controls' => true,
					'cart_badges_enabled' => true,
					'stock_badge_alert_threshold' => 10,
					'stock_badge_alert_text' => 'Only %d left',
					'stock_badge_urgent_threshold' => 3,
					'stock_badge_urgent_text' => 'Almost gone: %d left',
					'cross_sells_enabled' => false,
					'upsell_banner_enabled' => false,
					'cart_upsell_discounts_enabled' => false,
					'linked_products_enabled' => true,
					'cross_sells_product_panel_enabled' => true,
					'commerce_recommendations_enabled' => true,
					'fbt_enabled' => true,
					'fbt_title' => 'Frequently Bought Together',
					'fbt_max_products' => 6,
					'fbt_show_out_of_stock' => false,
					'add_to_cart_mode' => 'default',
					'first_cart_confetti_enabled' => true,
					'co_purchase_daily_sync_enabled' => false,
					'co_purchase_daily_sync_depth' => 5,
					'co_purchase_daily_sync_mode' => 'merge',
					'bestseller_enabled' => false,
					'bestseller_limit' => 20,
					'bestseller_sync_on_order' => true,
					'bestseller_parent_label' => 'Bestseller',
					'bestseller_parent_slug' => 'bestseller',
					'cod_gate' => [
						'enabled'                      => false,
						'strikes_to_block'             => 1,
						'trusted_skip_after_completed' => 1,
						'regain'                       => 'prepaid_success',
						'block_message'                => 'Cash on delivery is not available for this order. Please choose a prepaid payment method.',
						'allow_unverified_on_failure'  => true,
					],
				],
				'bricks'              => [
					'mini_cart_adapter_enabled' => false,
					'add_to_cart_enhancer_enabled' => false,
					'dynamic_tags_enabled' => true,
					'dsa_icon_launcher_enabled' => true,
					'linked_products_controls_enabled' => false,
					'prefer_bricks_native_cart' => true,
					'quantity_stepper_enabled'  => true,
					'stock_badge_enabled'       => true,
					'verified_version'          => '2.4-beta-source-reviewed',
				],
				'search'              => [
					'configuration_version' => 2,
					'context_aware'       => true,
					'alphabet_enabled'    => true,
					'product_add_enabled' => true,
					'bricks_bridge_enabled' => true,
					'result_limit'        => 6,
					'families'            => [
						'products' => true,
						'posts'    => true,
						'authors'  => true,
					],
					'custom_taxonomies'   => [],
				],
				'haptic'             => [
					'enabled'           => true,
					'vibration_enabled' => true,
					'sound_enabled'     => true,
					'sound_profile'     => 'soft',
					'context'           => 'both',
					'events'            => [
						'buttons'       => true,
						'quantity'      => true,
						'swipe_back'    => true,
						'notifications' => true,
					],
				],
				'dock'                => [
					'hide_frontend_admin_bar' => true,
					'style'               => 'phonekey',
					'presentation'        => 'dock',
					'shape'               => 'pill',
					'material'            => 'glass',
					'fill_axis'           => false,
					'context_rail_enabled' => false,
					'split_style'         => false,
					'focus_item'          => 'ai',
					'desktop_orientation' => 'auto',
					'tablet_orientation'  => 'auto',
					'mobile_orientation'  => 'auto',
					'desktop_vertical_edge'   => 'right',
					'desktop_horizontal_edge' => 'bottom',
					'tablet_vertical_edge'    => 'right',
					'tablet_horizontal_edge'  => 'bottom',
					'mobile_vertical_edge'    => 'right',
					'mobile_horizontal_edge'  => 'bottom',
					'desktop_vertical_position'   => 'center',
					'desktop_horizontal_position' => 'right',
					'desktop_horizontal_vertical_position' => 'bottom',
					'tablet_vertical_position'    => 'center',
					'tablet_horizontal_position'  => 'center',
					'tablet_horizontal_vertical_position' => 'bottom',
					'mobile_vertical_position'    => 'bottom',
					'mobile_horizontal_position'  => 'right',
					'mobile_horizontal_vertical_position' => 'bottom',
					'mobile_breakpoint'   => 640,
					'tablet_breakpoint'   => 1024,
					'enabled_items'       => [
						'menu'    => true,
						'search'  => true,
						'profile' => true,
						'links'   => true,
						'saved'   => true,
						'cart'    => true,
						'theme'   => true,
						'ai'      => true,
						'secure'  => true,
					],
					'item_order'          => [ 'menu', 'search', 'profile', 'links', 'saved', 'cart', 'theme', 'ai', 'secure' ],
					'custom_items'        => [],
					'menu_label'          => 'Menu',
					'menu_url'            => '',
					'menu_nav_id'         => 0,
					'menu_nav_ids'        => [],
					'menu_heading_tag'    => 'span',
					'menu_items'          => [],
					'menu_context_enabled' => true,
					'menu_context_title'   => 'Table of contents',
					'menu_context_locations' => [
						'everywhere'     => false,
						'single_post'    => true,
						'single_product' => true,
						'front_page'     => false,
						'selected_pages' => true,
					],
					'menu_context_page_ids' => [],
					'menu_context_heading_levels' => [ 'h1', 'h2', 'h3' ],
					'admin_dashboard_link_enabled' => true,
					'phonekey_visibility' => 'all',
				],
				'visual_effects'      => [
					'blur_type'             => 'gaussian',
					'blur_strength'         => 10,
					'glass_intensity'       => 'medium',
					'screen_material'       => 'glass',
					'screen_animation'      => 'bottom',
					'loader_type'           => 'orb-chase',
					'show_on_overlay_open'  => true,
					'show_on_navigation'    => true,
					'show_on_page_in'       => false,
					'show_on_page_out'      => true,
					'editorial_view_transitions' => true,
					'editorial_morph_navigation' => false,
					'min_loader_ms'         => 700,
					'artificial_delay_ms'   => 0,
					'initial_preloader_enabled' => false,
					'initial_preloader_title' => '',
					'initial_preloader_hero' => '',
					'initial_preloader_store' => '',
					'transition_message_mode' => 'random',
					'transition_message_index' => 0,
					'transition_title_position' => 'above',
					'transition_messages'   => [
						[
							'title'   => 'Did you know',
							'message' => 'Kiwe keeps the Surface dock available while the next page loads.',
						],
					],
				],
				'app'                 => [
					'welcome_message' => 'Welcome to Our Appsite',
					'pwa_pitch'       => 'Try our app. No app store required.',
					'ios_url'         => '',
					'playstore_url'   => '',
					'android_url'     => '',
					'pc_url'          => '',
					'idle_enabled'    => false,
					'idle_delay_ms'   => 60000,
					'button_labels'   => [
						'playstore' => 'Play Store',
						'android'   => 'Android',
						'pc'        => 'PC',
					],
				],
				'dsa_theme'           => [
					'active_color'          => '#8f8f98',
					'hover_color'           => '#24c6a1',
					'hero_text_color'       => 'rgba(20,24,34,0.18)',
					'confetti_color_source' => 'hero',
				],
				'tokens'              => [
					'enabled'            => true,
					'profile_label'      => 'Kiwe Universal',
					'overrides'          => [],
					'bricks_theme_style' => [
						'enabled' => true,
						'id'      => 'kiwe-global-design',
						'label'   => 'Kiwe Universal Design Tokens',
					],
				],
				'theme_screens'       => [],
				'schema_geo'          => [
					'enabled'        => true,
					'woo_product'    => true,
					'breadcrumb'     => true,
					'webpage'        => true,
					'registry_hints' => true,
				],
				'metrics'             => [
					'enabled'        => true,
					'retention_days' => 14,
				],
				'permissions'         => [
					'enabled'                      => true,
					'retention_days'               => 30,
					'cooldown_hours'               => 24,
					'max_asks_per_session'         => 1,
					'pwa_enabled'                  => true,
					'offline_editorial_enabled'   => false,
					'pwa_min_home_views'           => 1,
					'pwa_min_dock_opens'           => 1,
					'pwa_min_transition_completes' => 1,
					'pwa_min_game_completes'       => 0,
					'pwa_title'                    => 'Install this appsite?',
					'pwa_message'                  => 'Kiwe will open your browser install prompt now.',
					'notifications_enabled'        => true,
					'notifications_title'          => 'Turn on browser notifications?',
					'notifications_message'        => 'Get useful order, account, and store updates when you choose.',
					'notification_preferences_enabled' => true,
					'notification_order_prompt_enabled' => true,
					'notification_cta_label'       => 'Notify me',
					'notification_cta_color'       => 'active',
					'ai_popup_duration_ms'         => 3200,
					'location_enabled'             => false,
					'camera_enabled'               => false,
				],
				'games'               => [
					'surface_enabled'  => false,
					'show_on_page_load' => false,
					'trigger_path'     => '/shop',
					'trigger_game'     => 'dino',
					'start_title'      => 'Are You Game! for discount??',
					'start_text'       => 'Press any key to start',
					'mobile_start_text' => 'Touch to start',
					'duration_ms'      => 0,
					'confetti_enabled' => true,
					'rewards_enabled'  => true,
					'coupon_enabled'   => false,
					'max_attempts_per_day' => 3,
					'coupon_expiry_minutes' => 20,
					'daily_coupon_budget' => 100,
					'min_play_ms'      => 4000,
					'max_play_ms'      => 180000,
					'max_score'        => 10000,
					'bonuses'          => [
						[ 'label' => 'First high score', 'discount' => 2 ],
						[ 'label' => 'Second high score', 'discount' => 6 ],
						[ 'label' => 'Third high score', 'discount' => 10 ],
					],
					'retry_texts'      => [
						'You got this! One more try for a bigger discount?',
						'Well done! Want to try once more?',
						'AMAZING! You got MAX discount.',
					],
				],
				'link_hub'            => [
					'site_score'         => '',
					'shop_label'         => 'Shop',
					'shop_url'           => '',
					'posts_title'        => '',
					'posts_category'     => 0,
					'ssl_provider'       => '',
					'payment_provider'   => '',
					'review_source'      => 'manual',
					'google_place_id'    => '',
					'google_api_key'     => '',
					'testimonials'       => "Lovely chikki and fast delivery.\nFresh taste, beautiful packaging.",
					'social_links'       => [
						'facebook'  => '',
						'instagram' => '',
						'x'         => '',
						'youtube'   => '',
						'pinterest' => '',
						'linkedin'  => '',
					],
				],
			];
	}

	public function get( string $key, $default = null ) {
		$settings = $this->all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public function update( array $settings ): void {
		$resolved = $this->recursive_parse_args( $settings, $this->all() );
		$resolved['fragment_navigation'] = false;

		update_option( DSA_OPTION_SETTINGS, $resolved, false );

		$this->resolved_settings = $resolved;
		$this->resolved_manifest = null;
	}

	private function recursive_parse_args( array $settings, array $defaults ): array {
		foreach ( $defaults as $key => $default ) {
			if ( is_array( $default ) ) {
				$value = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : [];
				$settings[ $key ] = $this->recursive_parse_args( $value, $default );
				continue;
			}

			if ( ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $default;
			}
		}

		return $settings;
	}

	public function manifest(): array {
		$profile = Runtime_Profiler::start();

		if ( is_array( $this->resolved_manifest ) ) {
			Runtime_Profiler::finish( 'settings.manifest', $profile, true );
			return $this->resolved_manifest;
		}

		$manifest = get_option( DSA_OPTION_MANIFEST, [] );

		if ( ! is_array( $manifest ) ) {
			$manifest = [];
		}

		$this->resolved_manifest = $this->recursive_parse_args(
			$manifest,
			[
				'version'   => DSA_VERSION,
				'footprint' => [
					'top'    => 0,
					'right'  => (int) $this->get( 'surface_width', 72 ),
					'bottom' => 0,
					'left'   => 0,
				],
				'routes'    => [
					'excluded' => [
						'/wp-admin/*',
						'/wp-login.php',
						'/?bricks=*',
						'/?wc-ajax=*',
						'/*?add-to-cart=*',
						'/cart*',
						'/checkout*',
						'/my-account*',
						'/order-pay*',
						'/order-received*',
						'/wp-json/*',
					],
				],
				'delivery' => [
					'schema' => 1,
					'profile' => 'kiwe-apex-v1',
					'profileUrl' => rest_url( 'dsa/v1/apex-profile' ),
					'htmlEdgePolicy' => 'origin-required',
					'publicEditorialContract' => rest_url( 'dsa/v1/offline-editorial' ),
				],
			]
		);
		$this->resolved_manifest['version'] = DSA_VERSION;
		$this->resolved_manifest['delivery'] = [
			'schema' => 1,
			'profile' => 'kiwe-apex-v1',
			'profileUrl' => rest_url( 'dsa/v1/apex-profile' ),
			'htmlEdgePolicy' => 'origin-required',
			'publicEditorialContract' => rest_url( 'dsa/v1/offline-editorial' ),
		];
		Runtime_Profiler::finish( 'settings.manifest', $profile, false );

		return $this->resolved_manifest;
	}
}
