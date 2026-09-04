<?php

namespace DSA;

use DSA\Access\WordPress_Role_Access_Service;
use DSA\Access\Guest_Contribution_Service;
use DSA\Admin\Admin;
use DSA\AI\AI_Broker_Service;
use DSA\AI\Copilot_Service;
use DSA\AI\Site_Graph_Service;
use DSA\Bricks\Bricks_Integration;
use DSA\Bricks\Clean_Conversion_Test_Service;
use DSA\Commerce\Cart_Payload_Service;
use DSA\Commerce\Abandoned_Cart_Service;
use DSA\Commerce\Checkout_Service;
use DSA\Commerce\COD_Gate_Service;
use DSA\Commerce\Commerce_Context_Service;
use DSA\Commerce\Linked_Products_Service;
use DSA\Commerce\Product_Context_Service;
use DSA\Commerce\Purchase_Identity_Gate;
use DSA\Commerce\Store_Analytics_Service;
use DSA\Diagnostics\Asset_Manifest_Service;
use DSA\Diagnostics\Runtime_Profiler;
use DSA\Diagnostics\Production_Readiness_Service;
use DSA\Diagnostics\Apex_Acceptance_Service;
use DSA\Communications\Email_Service;
use DSA\Communications\Channel_Service;
use DSA\Link_Hub\Review_Service;
use DSA\Metrics\Metrics_Service;
use DSA\Modules\Module_Registry;
use DSA\Notifications\Notification_Preference_Service;
use DSA\Notifications\Notification_Center_Service;
use DSA\Notifications\Notification_Campaign_Service;
use DSA\Notifications\Push_Service;
use DSA\Notifications\Admin_Event_Notification_Service;
use DSA\Notifications\Order_Notification_Service;
use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Onboarding\Design_Context_Refinement_Service;
use DSA\Onboarding\Onboarding_Service;
use DSA\Onboarding\SEO_Context_Service;
use DSA\Permissions\Permission_Journey_Service;
use DSA\PWA\PWA_Service;
use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\PhoneKey\PhoneKey_Core_Loader;
use DSA\Protected_Flow\Flow_Guard;
use DSA\Public_Endpoint\Assets;
use DSA\Public_Endpoint\Surface_Renderer;
use DSA\Rest\Metrics_Controller;
use DSA\Rest\Notification_Preferences_Controller;
use DSA\Rest\Push_Controller;
use DSA\Rest\Admin_Notifications_Controller;
use DSA\Rest\Permissions_Controller;
use DSA\Rest\Rewards_Controller;
use DSA\Rest\Account_Controller;
use DSA\Rest\Cart_Controller;
use DSA\Rest\Checkout_Controller;
use DSA\Rest\Settings_Controller;
use DSA\Rest\Search_Controller;
use DSA\Rest\Saved_Items_Controller;
use DSA\Rest\Site_Graph_Controller;
use DSA\Rest\AI_Access_Controller;
use DSA\Rest\Bricks_Studio_Controller;
use DSA\Rest\Editorial_Envelope_Controller;
use DSA\Rest\Apex_Profile_Controller;
use DSA\Rest\Runtime_Hydration_Controller;
use DSA\Rewards\Reward_Service;
use DSA\Runtime\Route_Capability_Service;
use DSA\Runtime\Editorial_Fragment_Service;
use DSA\Runtime\Update_Service;
use DSA\Schema\Schema_Geo_Service;
use DSA\Search\Search_Service;
use DSA\SEO\SEO_Refinement_Service;
use DSA\Saved\Saved_Items_Service;
use DSA\Secure\SecureTrack_Loader;
use DSA\Site\Site_Identity_Service;
use DSA\Trust\Trust_Service;
use DSA\Trigger\Trigger_Service;
use DSA\Utilities\Atomic_Rate_Limiter;
use DSA\WP7\Native_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static $instance = null;

	private $modules;
	private $settings;
	private $registry;
	private $phonekey;
	private $trust;
	private $flow_guard;
	private $triggers;
	private $native;
	private $copilot;
	private $linked_products;
	private $store_analytics;
	private $email;
	private $channels;
	private $abandoned_carts;
	private $cart_payload;
	private $checkout;
	private $commerce;
	private $cod_gate;
	private $schema_geo;
	private $rewards;
	private $metrics;
	private $permissions;
	private $notification_preferences;
	private $notification_campaigns;
	private $push;
	private $admin_notifications;
	private $order_notifications;
	private $pwa;
	private $readiness;
	private $reviews;
	private $route_capabilities;
	private $search;
	private $saved_items;
	private $editorial_fragments;
	private $apex_acceptance;
	private $site_graph;
	private $ai_broker;
	private $design_context_profile;
	private $onboarding;
	private $design_context_refinement;
	private $seo_context;
	private $product_context;
	private $seo_refinement;
	private $role_access;
	private $purchase_identity_gate;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->settings   = new Settings();
		$this->modules    = new Module_Registry();
		$this->registry   = new Element_Registry();
		$this->linked_products = new Linked_Products_Service( $this->settings );
		$this->store_analytics = new Store_Analytics_Service( $this->settings );
		$this->cart_payload = new Cart_Payload_Service( $this->settings, $this->linked_products, $this->store_analytics );
		$this->phonekey   = new PhoneKey_Bridge( $this->cart_payload );
		$this->email        = new Email_Service( $this->settings );
		$this->channels     = new Channel_Service( $this->settings, $this->email, $this->phonekey );
		$this->design_context_profile = new Design_Context_Profile_Service();
		$this->ai_broker = new AI_Broker_Service( $this->settings );
		$this->design_context_refinement = new Design_Context_Refinement_Service( $this->design_context_profile, $this->ai_broker );
		$this->onboarding   = new Onboarding_Service( $this->design_context_profile, $this->channels, $this->design_context_refinement );
		$this->seo_context  = new SEO_Context_Service( $this->design_context_profile );
		$this->seo_refinement = new SEO_Refinement_Service( $this->ai_broker, $this->design_context_profile );
		$this->product_context = new Product_Context_Service();
		$this->role_access = new WordPress_Role_Access_Service();
		$this->purchase_identity_gate = new Purchase_Identity_Gate();
		$this->abandoned_carts = new Abandoned_Cart_Service( $this->settings, $this->store_analytics, $this->channels );
		$this->checkout    = new Checkout_Service( $this->settings, $this->cart_payload );
		$this->trust      = new Trust_Service();
		$this->flow_guard = new Flow_Guard();
		$this->triggers   = new Trigger_Service();
		$this->site_graph = new Site_Graph_Service( $this->settings, $this->modules );
		$this->native     = new Native_Service( $this->settings, $this->registry, $this->trust, $this->site_graph );
		$this->copilot    = new Copilot_Service( $this->settings, $this->registry, $this->trust, $this->native );
		$this->commerce   = new Commerce_Context_Service( $this->linked_products );
		$this->cod_gate   = new COD_Gate_Service( $this->settings );
		$this->schema_geo = new Schema_Geo_Service( $this->settings, $this->registry );
		$this->rewards    = new Reward_Service( $this->settings );
		$this->metrics    = new Metrics_Service( $this->settings, $this->store_analytics );
		$this->permissions = new Permission_Journey_Service( $this->settings );
		$this->notification_preferences = new Notification_Preference_Service( $this->settings, $this->trust );
		$this->abandoned_carts->set_preferences( $this->notification_preferences );
		$this->push = new Push_Service();
		$this->admin_notifications = new Admin_Event_Notification_Service( $this->notification_preferences, $this->push, $this->store_analytics, $this->channels );
		$this->order_notifications = new Order_Notification_Service( $this->notification_preferences, $this->channels, $this->push );
		$this->notification_campaigns = new Notification_Campaign_Service( $this->notification_preferences, $this->channels, $this->push );
		$this->pwa        = new PWA_Service( $this->settings, $this->push );
		$this->readiness  = new Production_Readiness_Service( $this->settings, $this->trust, $this->push );
		$this->reviews    = new Review_Service();
		$this->route_capabilities = new Route_Capability_Service();
		$this->search      = new Search_Service( $this->settings, $this->cart_payload );
		$this->saved_items = new Saved_Items_Service( $this->store_analytics );
		$this->editorial_fragments = new Editorial_Fragment_Service( $this->registry );
		$this->apex_acceptance = new Apex_Acceptance_Service( $this->settings );
	}

	public function boot(): void {
		add_action( 'muplugins_loaded', [ $this, 'register_services' ], 5 );
	}

	public function register_services(): void {
		Clean_Conversion_Test_Service::register_runtime_isolation();
		$this->settings->run_migrations();
		$settings    = $this->settings->all();
		$diagnostics = isset( $settings['diagnostics'] ) && is_array( $settings['diagnostics'] ) ? $settings['diagnostics'] : [];
		$permissions = isset( $settings['permissions'] ) && is_array( $settings['permissions'] ) ? $settings['permissions'] : [];
		$commerce    = isset( $settings['commerce'] ) && is_array( $settings['commerce'] ) ? $settings['commerce'] : [];
		$ai          = isset( $settings['ai'] ) && is_array( $settings['ai'] ) ? $settings['ai'] : [];
		$surface_enabled   = ! empty( $settings['enabled'] );
		$phonekey_enabled  = ! empty( $settings['phonekey']['enabled'] );
		$push_enabled      = ! empty( $permissions['enabled'] ) && ! empty( $permissions['notifications_enabled'] );
		$preferences_enabled = ! empty( $permissions['enabled'] ) && ! empty( $permissions['notification_preferences_enabled'] );
		$abandoned_enabled = ! empty( $settings['abandoned_cart']['enabled'] );
		$linked_enabled    = ! empty( $commerce['linked_products_enabled'] );
		$analytics_enabled = ! empty( $settings['metrics']['enabled'] ) || $abandoned_enabled || $linked_enabled;
		$commerce_enabled  = $surface_enabled && ( ! empty( $commerce['cart_surface_enabled'] ) || ! empty( $commerce['checkout_surface_enabled'] ) );
		Runtime_Profiler::configure( ! empty( $diagnostics['enabled'] ) && ! empty( $diagnostics['performance_profile'] ) );
		Runtime_Profiler::mark(
			'route.context',
			[
				'admin' => is_admin(),
				'rest'  => defined( 'REST_REQUEST' ) && REST_REQUEST,
				'ajax'  => wp_doing_ajax(),
				'cron'  => wp_doing_cron(),
			]
		);
		$service_profile = Runtime_Profiler::start( 'service.register_services' );
		( new Asset_Manifest_Service( ! empty( $diagnostics['enabled'] ) && ! empty( $diagnostics['asset_manifest'] ) ) )->register();
		Atomic_Rate_Limiter::register();
		( new Update_Service() )->register();
		$this->apex_acceptance->register();

		$this->registry->register();
		// Ownership and the business workspace must survive disabling Key login.
		$this->role_access->register();
		( new Guest_Contribution_Service() )->register();
		( new Notification_Center_Service( $this->notification_preferences ) )->register();
		// Every Kiwe subsystem, including SecureTrack, publishes through this
		// lightweight ingress. Event-source hooks remain preference-gated below.
		$this->admin_notifications->register_router();
		if ( $phonekey_enabled ) {
			( new PhoneKey_Core_Loader() )->register();
			// Regular plugins load after MU plugins. Attach the WooCommerce lifecycle
			// only after Woo itself is present; editorial sites pay no commerce-hook cost.
			add_action( 'plugins_loaded', [ $this->purchase_identity_gate, 'register_if_available' ], 30 );
		}
		$this->native->register();
		/** Third-party integrations receive the broker object, never provider credentials. */
		do_action( 'kiwe_ai_broker_ready', $this->ai_broker );
		( new Site_Identity_Service() )->register();

		$this->modules->register_defaults();

		if ( ! empty( $settings['email']['enabled'] ) ) {
			$this->email->register();
		}
		if ( $surface_enabled && ! empty( $permissions['enabled'] ) && ! empty( $permissions['pwa_enabled'] ) ) {
			$this->pwa->register();
		}
		if ( $push_enabled ) {
			$this->push->register();
		}
		if ( $preferences_enabled ) {
			$this->admin_notifications->register();
			$this->order_notifications->register();
		}
		( new Admin( $this->settings, $this->modules, $this->native, $this->readiness, $this->store_analytics, $this->linked_products, $this->email, $this->abandoned_carts, $this->notification_preferences, $this->notification_campaigns, $this->saved_items, $this->search ) )->register();
		$this->onboarding->register();
		$this->seo_context->register();
		$this->seo_refinement->register();
		$this->product_context->register();
		( new SecureTrack_Loader() )->register();
		if ( $surface_enabled ) {
			( new Assets( $this->settings, $this->registry, $this->modules, $this->phonekey, $this->trust, $this->flow_guard, $this->triggers, $this->native, $this->commerce, $this->rewards, $this->metrics, $this->permissions, $this->notification_preferences, $this->pwa, $this->reviews, $this->route_capabilities ) )->register();
			( new Surface_Renderer( $this->settings, $this->modules, $this->registry, $this->phonekey ) )->register();
			( new Runtime_Hydration_Controller( $this->settings, $this->phonekey, $this->trust, $this->flow_guard, $this->commerce ) )->register();
		}
		if ( $phonekey_enabled ) {
			( new Account_Controller() )->register();
		}
		if ( $commerce_enabled ) {
			( new Cart_Controller( $this->cart_payload, $this->store_analytics ) )->register();
			( new Checkout_Controller( $this->checkout ) )->register();
		}
		( new Rewards_Controller( $this->rewards ) )->register();
		if ( $analytics_enabled ) {
			( new Metrics_Controller( $this->metrics ) )->register();
		}
		if ( $surface_enabled && ! empty( $permissions['enabled'] ) ) {
			( new Permissions_Controller( $this->permissions ) )->register();
		}
		if ( $preferences_enabled ) {
			( new Notification_Preferences_Controller( $this->notification_preferences ) )->register();
		}
		if ( $push_enabled ) {
			( new Push_Controller( $this->push ) )->register();
		}
		if ( $preferences_enabled ) {
			( new Admin_Notifications_Controller( $this->admin_notifications ) )->register();
		}
		( new Settings_Controller( $this->settings, $this->registry, $this->trust, $this->modules, $this->native, $this->copilot, $this->reviews ) )->register();
		( new Site_Graph_Controller( $this->site_graph ) )->register();
		( new AI_Access_Controller( $this->site_graph ) )->register();
		if ( ! empty( $ai['studio_enabled'] ) && ! empty( $ai['bricks_editor_companion_enabled'] ) ) {
			( new Bricks_Studio_Controller( $this->settings, $this->site_graph ) )->register();
		}
		// Context awareness only chooses the initial search scope. The endpoint and
		// cache/index hooks must remain available whenever the Surface is enabled.
		if ( $surface_enabled ) {
			( new Search_Controller( $this->search ) )->register();
		}
		if ( $surface_enabled ) {
			( new Saved_Items_Controller( $this->saved_items ) )->register();
			( new Editorial_Envelope_Controller( $this->editorial_fragments ) )->register();
		}
		( new Apex_Profile_Controller( $this->apex_acceptance ) )->register();
		if ( $surface_enabled ) {
			$this->search->register();
		}
		// MU plugins run before the theme loads. Register editor capabilities after
		// theme setup, before Bricks initializes elements on init. No option writes.
		$bricks_integration = new Bricks_Integration( $this->registry, $this->settings, $this->linked_products, $this->store_analytics );
		add_action( 'after_setup_theme', [ $bricks_integration, 'register' ], 20 );
		if ( $linked_enabled ) {
			$this->linked_products->register();
		}
		if ( $analytics_enabled ) {
			$this->store_analytics->register();
		}
		if ( $preferences_enabled ) {
			$this->notification_preferences->register();
		}
		if ( $abandoned_enabled ) {
			$this->abandoned_carts->register();
		}
		if ( $commerce_enabled ) {
			$this->checkout->register();
		}
		if ( ! empty( $commerce['cod_gate']['enabled'] ) ) {
			$this->cod_gate->register();
		}
		if ( $surface_enabled && ! empty( $settings['schema_geo']['enabled'] ) ) {
			$this->schema_geo->register();
		}

		Runtime_Profiler::finish( 'service.register_services', $service_profile );
	}

	public function settings(): Settings {
		return $this->settings;
	}

	/** Shared, credential-blind AI broker for Kiwe and approved integrations. */
	public function ai_broker(): AI_Broker_Service {
		return $this->ai_broker;
	}

	public function modules(): Module_Registry {
		return $this->modules;
	}

	public function registry(): Element_Registry {
		return $this->registry;
	}
}
