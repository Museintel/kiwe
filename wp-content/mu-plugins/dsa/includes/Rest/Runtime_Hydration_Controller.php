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
		private Commerce_Context_Service $commerce
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
		$phonekey = $this->phonekey->public_data();
		$commerce = $this->commerce->context( $trust, $protected );
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
				'admin_dashboard' => current_user_can( 'manage_options' ) && ! empty( $settings['dock']['admin_dashboard_link_enabled'] )
					? [ 'label' => __( 'Dashboard', 'dsa' ), 'url' => admin_url() ] : null,
			],
			'links' => $this->links_admin_data( $link_hub ),
			'nativeData' => $this->native_data( $trust, $phonekey, $commerce ),
		];
	}

	private function secure_data(): array {
		if ( ! current_user_can( 'manage_options' ) || ! ( defined( 'STP_VER' ) || function_exists( 'stp_cfg' ) ) ) return [ 'available' => false, 'links' => [] ];
		$tabs = [ 'events' => 'Events Log', 'alerts' => 'Alerts', 'protections' => 'Protections', 'live' => 'Live Monitor', 'analytics' => 'Analytics', 'auth' => 'Auth Security', 'files' => 'File Scanner', 'settings' => 'Settings' ];
		$links = [];
		foreach ( $tabs as $tab => $label ) $links[] = [ 'label' => $label, 'url' => admin_url( 'admin.php?page=kiwe-secure&tab=' . $tab ) ];
		return [ 'available' => true, 'links' => $links ];
	}

	private function phonekey_visible( array $dock ): bool {
		$scope = sanitize_key( $dock['phonekey_visibility'] ?? 'all' );
		if ( 'visitors' === $scope ) return ! is_user_logged_in();
		if ( 'users' === $scope ) return is_user_logged_in();
		if ( 'admins' === $scope ) return current_user_can( 'manage_options' );
		if ( 'customers' === $scope ) {
			if ( ! is_user_logged_in() ) return false;
			return array_intersect( [ 'customer', 'subscriber' ], (array) wp_get_current_user()->roles ) || current_user_can( 'manage_woocommerce' );
		}
		return true;
	}

	private function links_admin_data( array $config ): array {
		if ( ! current_user_can( 'manage_options' ) ) return [ 'canEdit' => false, 'editor' => [] ];
		$raw = is_array( $config['social_links'] ?? null ) ? $config['social_links'] : [];
		$labels = [ 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X', 'youtube' => 'YouTube', 'pinterest' => 'Pinterest', 'linkedin' => 'LinkedIn' ];
		$socials = [];
		foreach ( $labels as $id => $label ) $socials[] = [ 'id' => $id, 'label' => $label, 'url' => esc_url_raw( $raw[ $id ] ?? '' ) ];
		$categories = [];
		$terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) $categories[] = [ 'id' => (int) $term->term_id, 'name' => sanitize_text_field( $term->name ) ];
		}
		return [
			'canEdit' => true,
			'editor' => [
				'siteScore' => '' === trim( (string) ( $config['site_score'] ?? '' ) ) ? '' : max( 0, min( 100, (int) $config['site_score'] ) ),
				'shopLabel' => sanitize_text_field( $config['shop_label'] ?? __( 'Shop', 'dsa' ) ),
				'shopUrl' => esc_url_raw( $config['shop_url'] ?? '' ),
				'postsTitle' => sanitize_text_field( $config['posts_title'] ?? '' ),
				'postsCategory' => (int) ( $config['posts_category'] ?? 0 ),
				'categories' => $categories,
				'sslProvider' => sanitize_text_field( $config['ssl_provider'] ?? '' ),
				'paymentProvider' => sanitize_text_field( $config['payment_provider'] ?? '' ),
				'reviewSource' => 'google' === ( $config['review_source'] ?? 'manual' ) ? 'google' : 'manual',
				'googlePlaceId' => sanitize_text_field( $config['google_place_id'] ?? '' ),
				'hasGoogleApiKey' => ! empty( $config['google_api_key'] ),
				'testimonials' => sanitize_textarea_field( $config['testimonials'] ?? '' ),
				'socials' => $socials,
				'adminUrl' => admin_url( 'admin.php?page=dsa-settings' ),
			],
		];
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
