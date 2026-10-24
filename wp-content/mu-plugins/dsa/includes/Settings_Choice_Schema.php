<?php
namespace DSA;

if ( ! defined( 'ABSPATH' ) ) exit;

/** The existing technical Settings form's non-secret app-choice validators. */
final class Settings_Choice_Schema {

	public static function schema_geo( $input,array $current ): array {
		if ( ! is_array( $input ) ) return $current;
		$input = wp_unslash( $input );
		return [ 'enabled'=>! empty( $input['enabled'] ),'woo_product'=>! empty( $input['woo_product'] ),'breadcrumb'=>! empty( $input['breadcrumb'] ),'webpage'=>! empty( $input['webpage'] ),'registry_hints'=>! empty( $input['registry_hints'] ) ];
	}
	public static function metrics( $input,array $current ): array {
		if ( ! is_array( $input ) ) return $current;
		$input = wp_unslash( $input );
		return [ 'enabled'=>! empty( $input['enabled'] ),'retention_days'=>max( 1,min( 90,absint( $input['retention_days'] ?? 14 ) ) ) ];
	}
	public static function permissions( $input,array $current ): array {
		if ( ! is_array( $input ) ) return $current;
		$input = wp_unslash( $input );
		return [
			'enabled'=>! empty( $input['enabled'] ),
			'retention_days'=>max( 1,min( 90,absint( $input['retention_days'] ?? 30 ) ) ),
			'cooldown_hours'=>max( 1,min( 720,absint( $input['cooldown_hours'] ?? 24 ) ) ),
			'max_asks_per_session'=>max( 1,min( 5,absint( $input['max_asks_per_session'] ?? 1 ) ) ),
			'pwa_enabled'=>true,'offline_editorial_enabled'=>! empty( $input['offline_editorial_enabled'] ),
			'pwa_min_home_views'=>max( 0,min( 20,absint( $input['pwa_min_home_views'] ?? 1 ) ) ),
			'pwa_min_dock_opens'=>max( 0,min( 20,absint( $input['pwa_min_dock_opens'] ?? 1 ) ) ),
			'pwa_min_transition_completes'=>max( 0,min( 50,absint( $input['pwa_min_transition_completes'] ?? 1 ) ) ),
			'pwa_min_game_completes'=>max( 0,min( 20,absint( $input['pwa_min_game_completes'] ?? 0 ) ) ),
			'pwa_title'=>sanitize_text_field( $input['pwa_title'] ?? 'Install this appsite?' ),
			'pwa_message'=>sanitize_text_field( $input['pwa_message'] ?? 'Kiwe will open your browser install prompt now.' ),
			'notifications_enabled'=>! empty( $input['notifications_enabled'] ),
			'notifications_title'=>sanitize_text_field( $input['notifications_title'] ?? 'Turn on browser notifications?' ),
			'notifications_message'=>sanitize_text_field( $input['notifications_message'] ?? 'Get useful order, account, and store updates when you choose.' ),
			'notification_preferences_enabled'=>! empty( $input['notification_preferences_enabled'] ),
			'notification_order_prompt_enabled'=>! empty( $input['notification_order_prompt_enabled'] ),
			'notification_cta_label'=>sanitize_text_field( $input['notification_cta_label'] ?? 'Notify me' ),
			'notification_cta_color'=>'hover' === sanitize_key( $input['notification_cta_color'] ?? 'active' ) ? 'hover' : 'active',
			'ai_popup_duration_ms'=>max( 2000,min( 15000,(int) round( (float) ( $input['ai_popup_duration_seconds'] ?? 3.2 ) * 1000 ) ) ),
			'location_enabled'=>! empty( $input['location_enabled'] ),'camera_enabled'=>! empty( $input['camera_enabled'] ),
		];
	}
	public static function app( $input,array $current ): array {
		if ( ! is_array( $input ) ) return $current;
		$input = wp_unslash( $input ); $labels = isset( $input['button_labels'] ) && is_array( $input['button_labels'] ) ? $input['button_labels'] : [];
		return [
			'welcome_message'=>sanitize_text_field( $input['welcome_message'] ?? 'Welcome to Our Appsite' ),'pwa_pitch'=>sanitize_text_field( $input['pwa_pitch'] ?? 'Try our app. No app store required.' ),
			'home_timing'=>in_array($input['home_timing']??'',['before_load','after_load'],true)?$input['home_timing']:($current['home_timing']??'before_load'),
			'home_enabled'=>array_key_exists('home_enabled',$input)?!empty($input['home_enabled']):($current['home_enabled']??!empty($current['idle_enabled'])),
			'show_install_actions'=>array_key_exists('show_install_actions',$input)?!empty($input['show_install_actions']):($current['show_install_actions']??true),
			'ios_url'=>esc_url_raw( $input['ios_url'] ?? '' ),'playstore_url'=>esc_url_raw( $input['playstore_url'] ?? '' ),'android_url'=>esc_url_raw( $input['android_url'] ?? '' ),'pc_url'=>esc_url_raw( $input['pc_url'] ?? '' ),
			'idle_enabled'=>array_key_exists('idle_enabled',$input)?!empty($input['idle_enabled']):!empty($current['idle_enabled']),'idle_delay_ms'=>max( 10000,min( 1800000,absint( $input['idle_delay_seconds'] ?? 10 ) * 1000 ) ),
			'button_labels'=>[ 'playstore'=>sanitize_text_field( $labels['playstore'] ?? 'Play Store' ),'android'=>sanitize_text_field( $labels['android'] ?? 'Android' ),'pc'=>sanitize_text_field( $labels['pc'] ?? 'PC' ) ],
		];
	}
}
