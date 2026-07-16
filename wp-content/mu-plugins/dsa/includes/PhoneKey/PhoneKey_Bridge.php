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
			];
		}

		$settings = pk_settings();

		return [
			'identifierMode' => sanitize_key( $settings['identifier_mode'] ?? 'email_or_phone' ),
			'appIdentifierMode' => sanitize_key( $settings['app_identifier_mode'] ?? ( $settings['identifier_mode'] ?? 'email_or_phone' ) ),
			'phoneReady'     => function_exists( 'pk_phone_provider_ready' ) ? (bool) pk_phone_provider_ready() : false,
		];
	}

	private function user_data(): array {
		if ( ! is_user_logged_in() ) {
			return [
				'loggedIn'         => false,
				'badgeCount'       => 1,
				'completionItems'  => [ __( 'Sign in to save your profile.', 'dsa' ) ],
			];
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		$first   = get_user_meta( $user_id, 'first_name', true );
		$last    = get_user_meta( $user_id, 'last_name', true );
		$avatar  = $this->avatar_url( $user_id );
		$verified = function_exists( 'pk_account_verified' )
			? (bool) pk_account_verified( $user_id )
			: (bool) get_user_meta( $user_id, 'pk_verified_at', true );

		$completion = [];

		if ( ! $first ) {
			$completion[] = __( 'Add first name', 'dsa' );
		}

		if ( ! $last ) {
			$completion[] = __( 'Add last name', 'dsa' );
		}

		if ( ! $verified ) {
			$completion[] = __( 'Verify account', 'dsa' );
		}

		return [
			'loggedIn'        => true,
			'isAdmin'         => current_user_can( 'manage_options' ),
			'canManageOrders'  => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ),
			'canModerate'      => current_user_can( 'moderate_comments' ) || current_user_can( 'manage_options' ),
			'id'              => $user_id,
			'displayName'     => $user ? $user->display_name : '',
			'userLogin'       => $user ? $user->user_login : '',
			'firstName'       => (string) $first,
			'lastName'        => (string) $last,
			'email'           => $user ? $user->user_email : '',
			'avatar'          => $avatar,
			'verified'        => $verified,
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
