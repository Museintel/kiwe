<?php
namespace DSA\Apps;

use DSA\Access\Site_Owner_Service;
use DSA\Access\Team_Workspace_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Connection details for the separately maintained Shiprocket WooCommerce plugin. */
final class Shiprocket_App_Service {
	public const PLUGIN = 'shiprocket/class-shiprocket-woocommerce-shipping.php';
	public const METHOD = 'shiprocket_woocommerce_shipping';
	public const OPTION = 'woocommerce_shiprocket_woocommerce_shipping_settings';

	public static function allowed(): bool {
		return Store_App_Service::available() && current_user_can( 'manage_woocommerce' )
			&& ! Team_Workspace_Service::suspended( get_current_user_id() );
	}

	public static function realtime_configured(): bool {
		if ( ! defined( 'SHIPROCKET_WC_RATE_URL' ) ) return false;
		$settings = get_option( self::OPTION, [] );
		return 'yes' === ( $settings['realtime_enabled'] ?? '' ) && ! empty( $settings['integration_id'] );
	}

	public static function status(): array {
		if ( ! self::allowed() ) throw new \RuntimeException( 'Reviewing Shiprocket setup requires store permission.' );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed = is_readable( WP_PLUGIN_DIR . '/' . self::PLUGIN );
		$active = $installed && is_plugin_active( self::PLUGIN );
		$settings = $active ? get_option( self::OPTION, [] ) : [];
		return [
			'storeEnabled'=>Store_App_Service::enabled(),
			'pluginInstalled'=>$installed,
			'pluginActive'=>$active,
			'accountConfigured'=>! empty( $settings['integration_id'] ),
			'realtimeRatesConfigured'=>self::realtime_configured(),
			'usesShippingZones'=>'yes' === ( $settings['zone_wise_enabled'] ?? '' ),
		];
	}

	public static function card(): array {
		$state = self::status();
		$status = match ( true ) {
			! $state['pluginInstalled'] => __( 'Plugin needed', 'dsa' ),
			! $state['pluginActive'] => __( 'Plugin inactive', 'dsa' ),
			! $state['storeEnabled'] => __( 'Kiwe Store off', 'dsa' ),
			! $state['accountConfigured'] => __( 'Connect Shiprocket', 'dsa' ),
			! $state['realtimeRatesConfigured'] => __( 'Live rates off', 'dsa' ),
			default => __( 'Live rates configured', 'dsa' ),
		};
		$url = '';
		$can_configure = Site_Owner_Service::enabled() ? Site_Owner_Service::is_owner() : current_user_can( 'manage_options' );
		if ( $can_configure ) {
			if ( $state['pluginActive'] ) {
				$url = admin_url( 'admin.php?page=wc-settings&tab=shipping&section=' . self::METHOD );
			} elseif ( ! $state['pluginInstalled'] && current_user_can( 'install_plugins' ) ) {
				$url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=shiprocket' );
			} elseif ( $state['pluginInstalled'] && current_user_can( 'activate_plugins' ) ) {
				$url = admin_url( 'plugins.php?plugin_status=inactive' );
			}
		}
		return [
			'id'=>'shiprocket', 'enabled'=>$state['storeEnabled'] && $state['realtimeRatesConfigured'],
			'installed'=>$state['pluginInstalled'], 'canChange'=>false, 'blocked'=>false,
			'status'=>$status, 'url'=>$url,
			'description'=>__( 'Connect the official Shiprocket plugin and review courier rates, delivery estimates and PIN-code checks.', 'dsa' ),
		];
	}
}
