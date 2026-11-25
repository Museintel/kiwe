<?php
namespace DSA\Apps;

use DSA\Access\Site_Owner_Service;
use DSA\Access\Team_Workspace_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Kiwe's private connection view for the native Razorpay WooCommerce gateway. */
final class Razorpay_App_Service {
	public const PLUGIN = 'woo-razorpay/woo-razorpay.php';
	private const OPTION = 'woocommerce_razorpay_settings';

	public static function allowed(): bool {
		return Store_App_Service::available()
			&& current_user_can( 'manage_woocommerce' )
			&& ! Team_Workspace_Service::suspended( get_current_user_id() );
	}

	public static function status(): array {
		if ( ! self::allowed() ) throw new \RuntimeException( 'Reviewing Razorpay setup requires store permission.' );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed = is_readable( WP_PLUGIN_DIR . '/' . self::PLUGIN );
		$active = $installed && is_plugin_active( self::PLUGIN );
		$settings = $active ? get_option( self::OPTION, [] ) : [];
		$key_id = is_array( $settings ) && is_string( $settings['key_id'] ?? null ) ? $settings['key_id'] : '';
		$has_secret = is_array( $settings ) && is_string( $settings['key_secret'] ?? null ) && '' !== trim( $settings['key_secret'] );
		$mode = 'unconfigured';
		if ( $has_secret && preg_match( '/^rzp_(test|live)_[A-Za-z0-9]+$/D', $key_id, $match ) ) $mode = $match[1];

		return [
			'storeEnabled' => Store_App_Service::enabled(),
			'gatewayInstalled' => $installed,
			'gatewayActive' => $active,
			'gatewayEnabled' => $active && 'yes' === ( $settings['enabled'] ?? '' ),
			'mode' => $mode,
			'checkoutExperienceEnabled' => class_exists( \DSA\Commerce\Checkout_Experience_Service::class ) && \DSA\Commerce\Checkout_Experience_Service::enabled(),
			'standardMethodChoices' => class_exists( \DSA\Commerce\Razorpay_Checkout_Service::class ) && \DSA\Commerce\Razorpay_Checkout_Service::can_choose(),
			'kiweSubscriptionQuotes' => Store_App_Service::enabled() && class_exists( \DSA\Commerce\Subscription_Quote_Service::class ),
			'kiweSubscriptionPilot' => class_exists( \DSA\Commerce\Subscription_Billing_Service::class ) && \DSA\Commerce\Subscription_Billing_Service::available(),
			'kiweSubscriptionCheckoutPilot' => class_exists( \DSA\Commerce\Subscription_Checkout_Service::class ) && \DSA\Commerce\Subscription_Checkout_Service::enabled(),
		];
	}

	public static function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=razorpay' );
	}

	public static function card(): array {
		$state = self::status();
		$ready = $state['storeEnabled'] && $state['gatewayEnabled'] && 'unconfigured' !== $state['mode'];
		$status = match ( true ) {
			! $state['gatewayInstalled'] => __( 'Plugin needed', 'dsa' ),
			! $state['gatewayActive'] => __( 'Plugin inactive', 'dsa' ),
			! $state['storeEnabled'] => __( 'Kiwe Store off', 'dsa' ),
			'unconfigured' === $state['mode'] => __( 'Set up payments', 'dsa' ),
			! $state['gatewayEnabled'] => __( 'Payments disabled', 'dsa' ),
			'test' === $state['mode'] => __( 'Test Mode configured', 'dsa' ),
			default => __( 'Live Mode configured', 'dsa' ),
		};
		$url = '';
		$can_configure = Site_Owner_Service::enabled() ? Site_Owner_Service::is_owner() : current_user_can( 'manage_options' );
		if ( $can_configure ) {
			if ( $state['gatewayActive'] ) {
				$url = self::settings_url();
			} elseif ( ! $state['gatewayInstalled'] && current_user_can( 'install_plugins' ) ) {
				$url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=woo-razorpay' );
			} elseif ( $state['gatewayInstalled'] && current_user_can( 'activate_plugins' ) ) {
				$url = admin_url( 'plugins.php?plugin_status=inactive' );
			}
		}

		return [
			'id' => 'razorpay',
			'enabled' => $ready,
			'installed' => $state['gatewayInstalled'],
			'canChange' => false,
			'blocked' => false,
			'externalOwner' => 'woocommerce',
			'status' => $status,
			'description' => __( 'Review the setup of the official Razorpay gateway for this store.', 'dsa' ),
			'url' => $url,
		];
	}
}
