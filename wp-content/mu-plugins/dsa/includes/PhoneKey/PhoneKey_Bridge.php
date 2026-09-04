<?php

namespace DSA\PhoneKey;

use DSA\Commerce\Cart_Payload_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhoneKey_Bridge {
	private $cart_payload;

	public function __construct( Cart_Payload_Service $cart_payload ) {
		$this->cart_payload = $cart_payload;
	}

	public function is_available(): bool {
		return function_exists( 'pk_account_verified' ) || defined( 'PK_STAGE3_LOADED' );
	}

	public static function account_verified( int $user_id ): bool {
		return $user_id > 0 && ( function_exists( 'pk_account_verified' ) ? (bool) pk_account_verified( $user_id ) : (bool) get_user_meta( $user_id, 'pk_verified_at', true ) );
	}

	public static function verified_factors( int $user_id ): array {
		return [
			'email' => $user_id > 0 && function_exists( 'pk_factor_verified' ) && pk_factor_verified( $user_id, 'email' ),
			'phone' => $user_id > 0 && function_exists( 'pk_factor_verified' ) && pk_factor_verified( $user_id, 'phone' ),
		];
	}

	public static function provider_ready(): bool {
		return function_exists( 'pk_phone_provider_ready' ) && pk_phone_provider_ready();
	}

	public function notification_ready(): bool {
		return function_exists( 'pk_whatsapp_notification_ready' ) && pk_whatsapp_notification_ready();
	}

	public function send_notification( string $phone, string $message, string $purpose, array $context = [] ) {
		if ( ! $this->notification_ready() || ! function_exists( 'pk_send_whatsapp_message' ) ) {
			return new \WP_Error( 'phonekey_whatsapp_unavailable', __( 'Key.kiwe WhatsApp is not configured.', 'dsa' ) );
		}

		return pk_send_whatsapp_message( $phone, $message, $purpose, $context );
	}

	public function report_fallback( string $phone, string $request_id, bool $accepted ): void {
		if ( function_exists( 'pk_report_whatsapp_fallback_event' ) ) {
			pk_report_whatsapp_fallback_event( $phone, $request_id, $accepted );
		}
	}

	public function public_data(): array {
		return [
			'available' => $this->is_available(),
			'restUrl'   => defined( 'PK_NS' ) ? esc_url_raw( get_rest_url( null, PK_NS . '/' ) ) : '',
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'config'    => $this->config_data(),
			'user'      => $this->user_data(),
			'cart'      => $this->cart_data(),
			'account'   => $this->account_links(),
			'loginUrl'  => wp_login_url( home_url( $this->request_uri() ) ),
			'logoutUrl' => wp_logout_url( home_url( '/' ) ),
			'resetPasswordUrl' => wp_lostpassword_url(),
		];
	}

	public function boot_data(): array {
		$has_commerce = function_exists( 'WC' ) || class_exists( 'WooCommerce' );

		return [
			'available' => $this->is_available(),
			'restUrl'   => defined( 'PK_NS' ) ? esc_url_raw( get_rest_url( null, PK_NS . '/' ) ) : '',
			'nonce'     => '',
			'config'    => $this->config_data(),
			'user'      => [
				'loggedIn'        => false,
				'badgeCount'      => 0,
				'completionItems' => [],
			],
			'cart'      => [
				'available'       => $has_commerce,
				'count'           => 0,
				'itemCount'       => 0,
				'total'           => '',
				'cartUrl'         => $has_commerce && function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
				'checkoutUrl'     => $has_commerce && function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
				'items'           => [],
				'recommendations' => [],
				'upsells'         => [],
			],
			'account'   => [],
			'loginUrl'  => wp_login_url( home_url( '/' ) ),
			'logoutUrl' => '',
			'resetPasswordUrl' => wp_lostpassword_url(),
		];
	}

	private function config_data(): array {
		if ( ! function_exists( 'pk_settings' ) ) {
			return [
				'identifierMode' => 'email_or_phone',
				'appIdentifierMode' => 'email_or_phone',
				'phoneReady'     => false,
				'purchaseGateEnabled' => false,
			];
		}

		$settings = pk_settings();

		return [
			'identifierMode' => sanitize_key( $settings['identifier_mode'] ?? 'email_or_phone' ),
			'appIdentifierMode' => sanitize_key( $settings['app_identifier_mode'] ?? ( $settings['identifier_mode'] ?? 'email_or_phone' ) ),
			'phoneReady'     => function_exists( 'pk_phone_provider_ready' ) ? (bool) pk_phone_provider_ready() : false,
			'purchaseGateEnabled' => (bool) apply_filters( 'dsa_purchase_identity_gate_enabled', false ),
		];
	}

	private function user_data(): array {
		$purchase_gate = $this->purchase_gate_state();
		if ( ! is_user_logged_in() ) {
			return [
				'loggedIn'         => false,
				'badgeCount'       => 1,
				'completionItems'  => [ __( 'Sign in to save your profile.', 'dsa' ) ],
				'purchaseAllowed'  => ! empty( $purchase_gate['allowed'] ),
				'purchaseGateState'=> sanitize_key( (string) ( $purchase_gate['state'] ?? 'signup_required' ) ),
				'purchaseGateMessage' => sanitize_text_field( (string) ( $purchase_gate['message'] ?? '' ) ),
			];
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		$first   = get_user_meta( $user_id, 'first_name', true );
		$last    = get_user_meta( $user_id, 'last_name', true );
		$avatar  = $this->avatar_url( $user_id );
		$phone   = function_exists( 'pk_user_phone' ) ? (string) pk_user_phone( $user_id ) : '';
		$identity_verified = self::account_verified( $user_id );
		$security = function_exists( 'pk_security_completion' )
			? (array) pk_security_completion( $user_id )
			: [
				'state' => $identity_verified ? 'verified' : 'unverified',
				'complete' => $identity_verified,
				'identityVerified' => $identity_verified,
				'emailVerified' => $identity_verified,
				'phoneVerified' => false,
				'passkeyEnrolled' => false,
			];
		$verified = ! empty( $security['complete'] );
		$guest_application = \DSA\Access\Guest_Contribution_Service::profile_state( $user_id );

		$completion = [];

		if ( ! $first ) {
			$completion[] = __( 'Add first name', 'dsa' );
		}

		if ( ! $last ) {
			$completion[] = __( 'Add last name', 'dsa' );
		}

		if ( ! $identity_verified ) {
			$completion[] = __( 'Verify account', 'dsa' );
		}
		if ( $identity_verified && empty( $security['emailVerified'] ) ) {
			$completion[] = __( 'Verify recovery email', 'dsa' );
		}
		if ( $identity_verified && empty( $security['phoneVerified'] ) ) {
			$completion[] = __( 'Verify phone number', 'dsa' );
		}
		if ( $identity_verified && empty( $security['passkeyEnrolled'] ) ) {
			$completion[] = __( 'Set up passkey', 'dsa' );
		}

		$is_privileged = function_exists( 'pk_is_privileged' ) && pk_is_privileged( $user_id );
		$privileged_enrollment_complete = ! $is_privileged || ( function_exists( 'pk_admin_enrollment_complete' ) && pk_admin_enrollment_complete( $user_id ) );

		return [
			'loggedIn'        => true,
			'isAdmin'         => current_user_can( 'manage_options' ),
			'isPrivileged'    => $is_privileged,
			'privilegedEnrollmentComplete' => $privileged_enrollment_complete,
			'privilegedEnrollmentRequired' => $is_privileged && ! $privileged_enrollment_complete,
			'canManageOrders'  => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ),
			'canModerate'      => current_user_can( 'moderate_comments' ) || current_user_can( 'manage_options' ),
			'id'              => $user_id,
			'displayName'     => $user ? $user->display_name : '',
			'userLogin'       => $user ? $user->user_login : '',
			'publicProfileEditable' => \DSA\Onboarding\User_Profile_Service::eligible( $user_id ),
			'publicProfile' => \DSA\Onboarding\User_Profile_Service::eligible( $user_id ) ? \DSA\Onboarding\User_Profile_Service::public_fields( $user_id ) : [],
			'firstName'       => (string) $first,
			'lastName'        => (string) $last,
			'email'           => $user ? $user->user_email : '',
			'phone'           => $phone,
			'avatar'          => $avatar,
			'verified'        => $verified,
			'identityVerified'=> $identity_verified,
			'verificationState'=> sanitize_key( $security['state'] ?? ( $verified ? 'verified' : 'unverified' ) ),
			'emailVerified'   => ! empty( $security['emailVerified'] ),
			'phoneVerified'   => ! empty( $security['phoneVerified'] ),
			'passkeyEnrolled' => ! empty( $security['passkeyEnrolled'] ),
			'guestApplication'=> $guest_application,
			'purchaseAllowed' => ! empty( $purchase_gate['allowed'] ),
			'purchaseGateState'=> sanitize_key( (string) ( $purchase_gate['state'] ?? 'allowed' ) ),
			'purchaseGateMessage' => sanitize_text_field( (string) ( $purchase_gate['message'] ?? '' ) ),
			'badgeCount'      => count( $completion ),
			'completionItems' => $completion,
			'editProfileUrl'  => function_exists( 'wc_get_endpoint_url' ) && function_exists( 'wc_get_page_permalink' )
				? wc_get_endpoint_url( 'edit-account', '', wc_get_page_permalink( 'myaccount' ) )
				: admin_url( 'profile.php' ),
			'verifyUrl'       => function_exists( 'wc_get_page_permalink' )
				? wc_get_page_permalink( 'myaccount' )
				: admin_url( 'profile.php' ),
		];
	}

	private function purchase_gate_state(): array {
		$state = apply_filters( 'dsa_purchase_identity_gate_state', [] );
		return is_array( $state ) ? $state : [];
	}

	private function avatar_url( int $user_id ): string {
		$attachment_id = (int) get_user_meta( $user_id, 'kiwe_avatar_id', true );

		if ( $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

			if ( $url ) {
				return $url;
			}
		}

		return get_avatar_url( $user_id, [ 'size' => 128 ] );
	}

	private function cart_data(): array {
		return $this->cart_payload->cart_data();
	}

	private function account_links(): array {
		if ( ! function_exists( 'wc_get_account_menu_items' ) || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return [];
		}

		$links = [];

		foreach ( wc_get_account_menu_items() as $endpoint => $label ) {
			if ( in_array( $endpoint, [ 'dashboard', 'edit-account', 'customer-logout' ], true ) ) {
				continue;
			}

			$links[] = [
				'id'    => sanitize_key( $endpoint ),
				'label' => wp_strip_all_tags( $label ),
				'url'   => wc_get_account_endpoint_url( $endpoint ),
			];
		}

		return $links;
	}

	private function request_uri(): string {
		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		return '/' . ltrim( $uri, '/' );
	}
}
