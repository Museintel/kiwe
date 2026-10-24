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
use DSA\Notifications\New_Post_Notification_Service;
use DSA\Notifications\Push_Service;
use DSA\Notifications\Admin_Event_Notification_Service;
use DSA\Notifications\Order_Notification_Service;
use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Onboarding\Design_Context_Refinement_Service;
use DSA\Onboarding\Onboarding_Service;
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
use DSA\Search\Search_Service;
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
	private $rewards;
	private $metrics;
	private $permissions;
	private $notification_preferences;
	private $notification_campaigns;
	private $new_post_notifications;
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
	private bool $store_services_registered = false;
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
		// Shared account/search payloads must not construct the store graph.
		$this->cart_payload = null;
		$this->phonekey   = new PhoneKey_Bridge( fn() => $this->cart_payload() );
		$this->email        = new Email_Service( $this->settings );
		$this->channels     = new Channel_Service( $this->settings, $this->email, $this->phonekey );
		\DSA\Backup\Backup_Request_Service::use_channels( $this->channels );
		$this->design_context_profile = new Design_Context_Profile_Service();
		$this->ai_broker = new AI_Broker_Service( $this->settings );
		$this->design_context_refinement = new Design_Context_Refinement_Service( $this->design_context_profile, $this->ai_broker );
		$this->onboarding   = new Onboarding_Service( $this->design_context_profile, $this->channels, $this->design_context_refinement );
		$this->role_access = new WordPress_Role_Access_Service();
		$this->purchase_identity_gate = null;
		$this->trust      = new Trust_Service();
		$this->flow_guard = new Flow_Guard();
		$this->triggers   = new Trigger_Service();
		$this->site_graph = new Site_Graph_Service( $this->settings, $this->modules );
		$this->native     = new Native_Service( $this->settings, $this->registry, $this->trust, $this->site_graph );
		$this->copilot    = new Copilot_Service( $this->settings, $this->registry, $this->trust, $this->native );
		$this->commerce   = fn() => new Commerce_Context_Service( fn() => $this->linked_products() );
		$this->rewards    = fn() => new Reward_Service( $this->settings );
		$this->metrics    = new Metrics_Service( $this->settings, fn() => $this->store_analytics() );
		$this->permissions = new Permission_Journey_Service( $this->settings );
		$this->notification_preferences = new Notification_Preference_Service( $this->settings, $this->trust );
		$this->push = new Push_Service();
		$this->admin_notifications = new Admin_Event_Notification_Service( $this->notification_preferences, $this->push, fn() => $this->store_analytics(), $this->channels );
		$this->notification_campaigns = new Notification_Campaign_Service( $this->notification_preferences, $this->channels, $this->push );
		$this->new_post_notifications = new New_Post_Notification_Service( $this->notification_preferences, $this->channels, $this->push );
		$this->pwa        = new PWA_Service( $this->settings, $this->push );
		$this->readiness  = new Production_Readiness_Service( $this->settings, $this->trust, $this->push );
		$this->reviews    = new Review_Service();
		$this->route_capabilities = new Route_Capability_Service();
		$this->search      = new Search_Service( $this->settings, fn() => $this->cart_payload() );
		$this->saved_items = new Saved_Items_Service( fn() => \DSA\Apps\Store_App_Service::enabled() || ! empty( $this->settings->get( 'metrics', [] )['enabled'] ) ? $this->store_analytics() : null );
		$this->editorial_fragments = new Editorial_Fragment_Service( $this->registry );
		$this->apex_acceptance = new Apex_Acceptance_Service( $this->settings );
	}

	public function boot(): void {
		add_action( 'muplugins_loaded', [ $this, 'register_services' ], 5 );
	}

	public function register_services(): void {
		Clean_Conversion_Test_Service::register_runtime_isolation();
		if ( \DSA\Runtime\Release_Storage::pending_app() ) \DSA\Apps\Code_Recovery::guard();
		$this->settings->run_migrations();
		$settings    = $this->settings->all();
		$diagnostics = isset( $settings['diagnostics'] ) && is_array( $settings['diagnostics'] ) ? $settings['diagnostics'] : [];
		$permissions = isset( $settings['permissions'] ) && is_array( $settings['permissions'] ) ? $settings['permissions'] : [];
		$commerce    = isset( $settings['commerce'] ) && is_array( $settings['commerce'] ) ? $settings['commerce'] : [];
		$ai          = isset( $settings['ai'] ) && is_array( $settings['ai'] ) ? $settings['ai'] : [];
		$surface_enabled   = ! empty( $settings['enabled'] );
		$phonekey_enabled  = ! empty( $settings['phonekey']['enabled'] );
		$push_enabled      = ! empty( $permissions['enabled'] ) && ! empty( $permissions['notifications_enabled'] );
		$preferences_enabled = true; // The native inbox is independent of delivery configuration.
		$store_recovery_paused = \DSA\Commerce\Store_Recovery_Gate_Service::is_paused();
		$analytics_enabled = ! $store_recovery_paused && ! empty( $settings['metrics']['enabled'] );
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
		( new \DSA\Apps\Update_Hooks() )->register();
		add_action( 'admin_menu', static fn() => ( new \DSA\Apps\Library_Service() )->menu(), 37 );
		add_action( 'rest_api_init', static fn() => ( new \DSA\Apps\Library_Service() )->routes() );
		$this->apex_acceptance->register();

		$this->registry->register();
		// Ownership and the business workspace must survive disabling Key login.
		$this->role_access->register();
		( new \DSA\Onboarding\Native_Service_Source_Service() )->register();
		( new Guest_Contribution_Service() )->register();
		( new \DSA\Access\Workspace_Activity_Service() )->register();
		// App configuration is private; ordinary visitor requests do not load its editor.
		add_action('admin_init',[$this->notification_preferences,'maybe_install'],25);
		add_action('admin_menu',static fn()=>(new \DSA\Access\App_Workspace_Service())->menu(),34);
		add_action('rest_api_init',static fn()=>(new \DSA\Access\App_Workspace_Service())->routes());
		if(is_admin())add_action('load-toplevel_page_kiwe-app-settings',static fn()=>(new \DSA\Access\App_Workspace_Service())->document(),1);
		// Private Forms code is not loaded by ordinary visitor page requests.
		add_action( 'admin_menu', static fn() => ( new \DSA\Access\Form_Workspace_Service() )->menu(), 36 );
		add_action( 'rest_api_init', static fn() => ( new \DSA\Access\Form_Workspace_Service() )->routes() );
		( new \DSA\Access\Analytics_Reporting_Setup_Service( $this->settings, fn() => $this->abandoned_carts() ) )->register();
		( new Notification_Center_Service( $this->notification_preferences ) )->register();
		add_action( 'plugins_loaded', function (): void {
			if ( defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE ) ( new \DSA\Access\Notification_App_Service( $this->notification_preferences, $this->admin_notifications, $this->notification_campaigns, $this->new_post_notifications, \DSA\Apps\Store_App_Service::enabled() ? $this->abandoned_carts() : null ) )->register();
		}, 30 );
		// Every Kiwe subsystem, including SecureTrack, publishes through this
		// lightweight ingress. Event-source hooks remain preference-gated below.
		$this->admin_notifications->register_router();
		if ( $phonekey_enabled ) {
			( new PhoneKey_Core_Loader() )->register();
			// Regular plugins load after MU plugins. Attach the WooCommerce lifecycle
			// only after Woo itself is present; editorial sites pay no commerce-hook cost.
			add_action( 'plugins_loaded', function (): void {
				if ( \DSA\Apps\Store_App_Service::enabled() ) { $this->purchase_identity_gate = new Purchase_Identity_Gate(); $this->purchase_identity_gate->register_if_available(); }
			}, 30 );
		}
		$this->native->register();
		/** Third-party integrations receive the broker object, never provider credentials. */
		do_action( 'kiwe_ai_broker_ready', $this->ai_broker );
		( new Site_Identity_Service() )->register();

		$this->modules->register_defaults();

		if ( ! empty( $settings['email']['enabled'] ) ) {
			$this->email->register();
		}
		if ( $surface_enabled ) {
			$this->pwa->register();
		}
		if ( $push_enabled ) {
			$this->push->register();
		}
		if ( $preferences_enabled ) {
			$this->admin_notifications->register( ! empty( $settings['metrics']['enabled'] ) );
		}
		$this->notification_campaigns->register();
		$this->new_post_notifications->register( $preferences_enabled );
		$this->register_administration();
		$this->onboarding->register();
		( new \DSA\Apps\Store_App_Service() )->register();
		( new \DSA\Apps\SEO_App_Service() )->register( function (): void {
			( new \DSA\SEO\SEO_Engine_Service() )->register( $this->ai_broker, $this->design_context_profile, $this->settings, $this->registry );
		} );
		( new \DSA\Analytics\Search_Performance_Service() )->register();
		( new SecureTrack_Loader() )->register();
		if ( $surface_enabled ) {
			( new Assets( $this->settings, $this->registry, $this->modules, $this->phonekey, $this->trust, $this->flow_guard, $this->triggers, $this->native, $this->commerce, $this->rewards, $this->metrics, $this->permissions, $this->notification_preferences, $this->pwa, $this->reviews, $this->route_capabilities ) )->register();
			( new Surface_Renderer( $this->settings, $this->modules, $this->registry, $this->phonekey ) )->register();
			( new Runtime_Hydration_Controller( $this->settings, $this->phonekey, $this->trust, $this->flow_guard, $this->commerce ) )->register();
		}
		if ( $phonekey_enabled ) {
			( new Account_Controller() )->register();
		}
		// MU plugins precede regular plugins: native Woo availability is known here.
		add_action( 'plugins_loaded', [ $this, 'register_store_services' ], 30 );
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
		// Resolve the native flag before Bricks loads its request-local settings.
		add_filter( 'option_bricks_global_settings', [ Bricks_Integration::class, 'native_woocommerce_defaults' ] );
		add_filter( 'default_option_bricks_global_settings', [ Bricks_Integration::class, 'native_woocommerce_defaults' ] );
		add_action( 'after_setup_theme', function (): void {
			$woo = \DSA\Apps\Store_App_Service::enabled();
			( new Bricks_Integration( $this->registry, $this->settings, $woo ? $this->linked_products() : null, $woo ? $this->store_analytics() : null ) )->register();
		}, 20 );
		// Explicit traffic reporting remains available on editorial sites.
		if ( ! $store_recovery_paused && ! empty( $settings['metrics']['enabled'] ) ) $this->store_analytics()->register();
		if ( $preferences_enabled ) {
			$this->notification_preferences->register();
		}

		Runtime_Profiler::finish( 'service.register_services', $service_profile );
		if ( ! defined( 'KIWE_PACKAGE_BOOT_COMPLETE' ) ) define( 'KIWE_PACKAGE_BOOT_COMPLETE', true );
	}

	/** Public requests do not parse the technical admin UI merely to register unused hooks. */
	private function register_administration(): void {
		$create = fn( bool $tools = true ) => new Admin( $this->settings, $this->modules, $this->native, $this->readiness, $tools ? $this->store_analytics() : null, $tools && \DSA\Apps\Store_App_Service::enabled() ? $this->linked_products() : null, $this->email, $tools && \DSA\Apps\Store_App_Service::enabled() ? $this->abandoned_carts() : null, $this->notification_preferences, $this->notification_campaigns, $this->saved_items, $this->search );
		// This read-only bootstrap runs through admin-ajax to retain native
		// cookie authentication even when the public document has an old nonce.
		// It needs no technical editor, admin screen or settings-save hooks.
		$runtime_hydration = wp_doing_ajax() && 'dsa_runtime_hydrate' === ( $_REQUEST['action'] ?? '' );
		if ( is_admin() && ! $runtime_hydration ) {
			// Resolve optional store tools only after regular plugins have loaded.
			add_action( 'plugins_loaded', static fn() => $create()->register(), 40 );
			return;
		}
		// The same native permission checks still own the signed-in frontend toolbar.
		add_action( 'admin_bar_menu', static function ( $bar ) use ( $create ): void {
			$create( false )->database_admin_bar( $bar );
		}, 95 );
	}

	public function settings(): Settings {
		return $this->settings;
	}
	public function register_woo_capture_analytics(): void {
		if ( \DSA\Apps\Store_App_Service::enabled() && class_exists( 'WC_Order' ) && ! \DSA\Commerce\Store_Recovery_Gate_Service::is_paused() ) $this->store_analytics()->register();
	}

	/** Register store callbacks only after WordPress has actually loaded Woo. */
	public function register_store_services(): void {
		if ( $this->store_services_registered || ! \DSA\Apps\Store_App_Service::enabled() ) return;
		$this->store_services_registered = true;
		$this->cart_payload();
		if ( $this->rewards instanceof \Closure ) $this->rewards = ( $this->rewards )();
		( new Rewards_Controller( $this->rewards ) )->register();
		$settings = $this->settings->all();
		$commerce = is_array( $settings['commerce'] ?? null ) ? $settings['commerce'] : [];
		$paused = \DSA\Commerce\Store_Recovery_Gate_Service::is_paused();
		$surface = ! $paused && ! empty( $settings['enabled'] ) && ( ! empty( $commerce['cart_surface_enabled'] ) || ! empty( $commerce['checkout_surface_enabled'] ) );
		( new Product_Context_Service() )->register();
		( new \DSA\Commerce\Weight_Shipping_Service() )->register();
		( new Order_Notification_Service( $this->notification_preferences, $this->channels, $this->push ) )->register();
		if ( $paused ) return;
		if ( ! empty( $commerce['linked_products_enabled'] ) ) $this->linked_products()->register();
		$this->register_woo_capture_analytics();
		$this->abandoned_carts()->register();
		if ( $surface ) {
			$this->checkout ??= new Checkout_Service( $this->settings, $this->cart_payload );
			( new Cart_Controller( $this->cart_payload, $this->store_analytics() ) )->register();
			( new Checkout_Controller( $this->checkout ) )->register();
			$this->checkout->register();
		} else {
			add_action( 'after_setup_theme', function (): void {
				if ( Bricks_Integration::is_active_theme() ) ( new Cart_Controller( $this->cart_payload, $this->store_analytics() ) )->register();
			}, 20 );
		}
		if ( ! empty( $commerce['cod_gate']['enabled'] ) ) ( new COD_Gate_Service( $this->settings ) )->register();
	}

	private function cart_payload(): Cart_Payload_Service {
		return $this->cart_payload ??= new Cart_Payload_Service( $this->settings, fn() => $this->linked_products(), fn() => $this->store_analytics() );
	}
	private function linked_products(): Linked_Products_Service {
		return $this->linked_products ??= new Linked_Products_Service( $this->settings );
	}
	private function store_analytics(): Store_Analytics_Service {
		return $this->store_analytics ??= new Store_Analytics_Service( $this->settings );
	}
	private function abandoned_carts(): Abandoned_Cart_Service {
		if ( ! $this->abandoned_carts ) {
			$this->abandoned_carts = new Abandoned_Cart_Service( $this->settings, $this->store_analytics(), $this->channels );
			$this->abandoned_carts->set_preferences( $this->notification_preferences );
		}
		return $this->abandoned_carts;
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
