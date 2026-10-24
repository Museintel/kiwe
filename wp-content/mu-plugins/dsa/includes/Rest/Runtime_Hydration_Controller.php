<?php

namespace DSA\Rest;

use DSA\Commerce\Commerce_Context_Service;
use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\Protected_Flow\Flow_Guard;
use DSA\Settings;
use DSA\Trust\Trust_Service;
use DSA\Utilities\Origin_Checker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Runtime_Hydration_Controller {
	public function __construct(
		private Settings $settings,
		private PhoneKey_Bridge $phonekey,
		private Trust_Service $trust,
		private Flow_Guard $flow_guard,
		private Commerce_Context_Service|\Closure $commerce
	) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'wp_ajax_dsa_runtime_hydrate', [ $this, 'ajax_hydrate' ] );
		add_action( 'wp_ajax_nopriv_dsa_runtime_hydrate', [ $this, 'ajax_hydrate' ] );
	}

	public function routes(): void {
		register_rest_route( 'dsa/v1', '/runtime/hydrate', [
			'methods' => 'GET',
			'callback' => [ $this, 'hydrate' ],
			'permission_callback' => [ $this, 'can_read' ],
		] );
	}

	public function can_read( WP_REST_Request $request ) {
		return Origin_Checker::is_same_site_request()
			? true
			: new WP_Error( 'dsa_cross_site_hydration', __( 'Cross-site runtime hydration is not allowed.', 'dsa' ), [ 'status' => 403 ] );
	}

	public function hydrate(): WP_REST_Response {
		nocache_headers();
		$response = new WP_REST_Response( $this->payload(), 200 );
		$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Vary', 'Cookie' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		return $response;
	}

	public function ajax_hydrate(): void {
		if ( ! Origin_Checker::is_same_site_request() ) {
			wp_send_json_error( [ 'code' => 'dsa_cross_site_hydration', 'message' => __( 'Cross-site runtime hydration is not allowed.', 'dsa' ) ], 403 );
		}

		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Vary: Cookie' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		wp_send_json( $this->payload(), 200 );
	}

	private function payload(): array {
		$settings = $this->settings->all();
		$link_hub = is_array( $settings['link_hub'] ?? null ) ? $settings['link_hub'] : [];
		$trust = $this->trust->summary( $link_hub );
		$protected = $this->flow_guard->current();
		$protected['railEnabled'] = ! empty( $settings['protected_flow']['rail_enabled'] );
		$phonekey = $this->phonekey->public_data( true );
		if ( \DSA\Apps\Store_App_Service::enabled() && $this->commerce instanceof \Closure ) $this->commerce = ( $this->commerce )();
		$commerce = \DSA\Apps\Store_App_Service::enabled() ? $this->commerce->context( $trust, $protected ) : \DSA\Apps\Store_App_Service::inactive_context();
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];

		return [
			'version' => 1,
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'phonekey' => $phonekey,
			'protectedFlow' => $protected,
			'commerce' => $commerce,
			'ai' => [
				'enabled' => true,
				'canUseCopilot' => current_user_can( 'manage_options' ),
				'mode' => current_user_can( 'manage_options' ) ? 'visitor-insights-and-admin-audit' : 'visitor-insights',
				'popupDurationMs' => max( 2000, min( 15000, (int) ( $permissions['ai_popup_duration_ms'] ?? 3200 ) ) ),
			],
			'secure' => $this->secure_data(),
			'dock' => [
				'phonekey_visible' => $this->phonekey_visible( is_array( $settings['dock'] ?? null ) ? $settings['dock'] : [] ),
				'admin_dashboard' => $this->dashboard_link(),
			],
			'links' => $this->links_admin_data( $link_hub ),
			'nativeData' => $this->native_data( $trust, $phonekey, $commerce ),
		];
	}

	/** Private hydration only; WordPress/Workspace policy owns access. */
	private function dashboard_link(): ?array {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) return null;
		$workspace = \DSA\Access\Workspace_Admin_Service::uses_workspace( wp_get_current_user() );
		if ( ! $workspace && ! current_user_can( 'manage_options' ) ) return null;
		return [ 'label' => __( 'Dashboard', 'dsa' ), 'url' => $workspace ? \DSA\Access\Workspace_Admin_Service::url() : admin_url() ];
	}

	private function secure_data(): array {
		if ( ! current_user_can( 'manage_options' ) || ! ( defined( 'STP_VER' ) || function_exists( 'stp_cfg' ) ) ) return [ 'available' => false, 'links' => [] ];
		$tabs = [ 'events' => 'Events Log', 'alerts' => 'Alerts', 'protections' => 'Protections', 'live' => 'Live Monitor', 'analytics' => 'Analytics', 'auth' => 'Auth Security', 'files' => 'File Scanner', 'settings' => 'Settings' ];
		$links = [];
		foreach ( $tabs as $tab => $label ) $links[] = [ 'label' => $label, 'url' => admin_url( 'admin.php?page=kiwe-secure&tab=' . $tab ) ];
		return [ 'available' => true, 'links' => $links ];
	}

	private function phonekey_visible( array $dock ): bool {
		// Profile opens sign-in for visitors and the current account for users.
		// Its Dock visibility switch is the only launcher control, never a role gate.
		return true;
	}

	private function links_admin_data( array $config ): array {
		// Reuse the existing business editor policy; no technical settings access is granted.
		if ( ! is_user_logged_in() || ! ( current_user_can( 'manage_options' ) || current_user_can( 'kiwe_manage_context' ) ) ) return [ 'canEdit' => false, 'editor' => [] ];
		$raw = is_array( $config['social_links'] ?? null ) ? $config['social_links'] : [];
		$labels = [ 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X', 'youtube' => 'YouTube', 'pinterest' => 'Pinterest', 'linkedin' => 'LinkedIn' ];
		$admin_url = admin_url( 'admin.php?page=kiwe-contact' );
		$socials = [];
		foreach ( $labels as $id => $label ) $socials[] = [ 'id' => $id, 'label' => $label, 'url' => esc_url_raw( $raw[ $id ] ?? '' ), 'editUrl' => add_query_arg( 'link', $id, $admin_url ) ];
		return [ 'canEdit' => true, 'editor' => [ 'socials' => $socials, 'adminUrl' => $admin_url ] ];
	}

	private function native_data( array $trust, array $phonekey, array $commerce ): array {
		$user = is_array( $phonekey['user'] ?? null ) ? $phonekey['user'] : [];
		$cart = is_array( $phonekey['cart'] ?? null ) ? $phonekey['cart'] : [];
		return [
			'profile' => [ 'loggedIn' => ! empty( $user['loggedIn'] ), 'displayName' => sanitize_text_field( $user['displayName'] ?? '' ), 'avatar' => esc_url_raw( $user['avatar'] ?? '' ), 'badgeCount' => (int) ( $user['badgeCount'] ?? 0 ) ],
			'cart' => [ 'count' => (int) ( $cart['count'] ?? 0 ), 'itemCount' => (int) ( $cart['itemCount'] ?? ( $cart['count'] ?? 0 ) ), 'total' => sanitize_text_field( $cart['total'] ?? '' ), 'subtotal' => sanitize_text_field( $cart['subtotal'] ?? '' ), 'discount' => sanitize_text_field( $cart['discount'] ?? '' ) ],
		];
	}
}
