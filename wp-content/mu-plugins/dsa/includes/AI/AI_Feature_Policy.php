<?php

namespace DSA\AI;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Site preferences for declared client features; provider configuration stays with its owner. */
final class AI_Feature_Policy {
	public const CAPABILITY = 'kiwe_manage_ai_preferences';
	private const FEATURES = [
		'seo' => [ 'permission'=>'seo_enabled', 'option'=>'kiwe_ai_opt_in_seo_v1' ],
		'securetrack' => [ 'permission'=>'securetrack_brief_enabled', 'option'=>'kiwe_ai_opt_in_securetrack_v1' ],
	];
	private const SITE_OPTIONS = [ 'seo'=>'kiwe_seo_workspace_enabled_v1','ai'=>'kiwe_ai_opt_in_global_v1' ];
	public static function site_choices(): array {
		return [ 'seo'=>\DSA\Apps\SEO_App_Service::enabled(),'ai'=>self::yes( get_option( self::SITE_OPTIONS['ai'],'1' ) ) ];
	}
	public static function seo_enabled(): bool { return self::site_choices()['seo']; }
	public static function analytics_enabled( array $ai ): bool {
		return self::seo_enabled() && self::site_choices()['ai'] && self::yes( $ai['allow_native_generation'] ?? false ) && self::choices( $ai )['seo']['enabled'];
	}
	public function set_site_choice( string $key, bool $enabled, string $expected ) {
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( self::SITE_OPTIONS[ $key ] ) ) return new \WP_Error( 'kiwe_ai_preference_forbidden','This website choice is unavailable.' );
		if ( ! hash_equals( hash( 'sha256',wp_json_encode( self::site_choices() ) ),$expected ) ) return new \WP_Error( 'kiwe_ai_preference_stale','Your website choices changed. Refresh and review before saving.' );
		if ( 'seo' === $key ) return \DSA\Apps\SEO_App_Service::change( $enabled, \DSA\Apps\SEO_App_Service::expected() );
		$value = $enabled ? '1' : '0'; $option = self::SITE_OPTIONS[ $key ]; update_option( $option,$value,false );
		return get_option( $option,null ) === $value ? true : new \WP_Error( 'kiwe_ai_preference_save','Your choice could not be confirmed. Refresh and review.' );
	}

	/** Long-running consumers must not keep a revoked preference in the request cache. */
	public static function refresh_cache(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( DSA_OPTION_SETTINGS, 'options' );
		foreach ( self::FEATURES as $spec ) wp_cache_delete( $spec['option'], 'options' );
		foreach ( self::SITE_OPTIONS as $option ) wp_cache_delete( $option,'options' );
	}

	public static function known( string $feature ): bool { return isset( self::FEATURES[ $feature ] ); }

	/** Deliberately accepts only native boolean representations, never truthy malformed state. */
	private static function yes( $value ): bool { return in_array( $value, [ true, 1, '1' ], true ); }

	public static function choices( array $ai ): array {
		$out = [];
		foreach ( self::FEATURES as $feature=>$spec ) {
			$owner = self::yes( $ai[ $spec['permission'] ] ?? false );
			$client = self::yes( get_option( $spec['option'], false ) );
			$out[ $feature ] = [ 'ownerAllowed'=>$owner, 'clientEnabled'=>$client, 'enabled'=>$owner && $client && self::site_choices()['ai'] && ( 'seo' !== $feature || self::seo_enabled() ) ];
		}
		return $out;
	}

	/** One native option write per feature avoids overwriting another feature or technical setting. */
	public function set_preference( string $feature, bool $enabled ) {
		if ( ! current_user_can( self::CAPABILITY ) || ! self::known( $feature ) ) return new \WP_Error( 'kiwe_ai_preference_forbidden', __( 'This AI choice is unavailable for your account.', 'dsa' ) );
		$ai = ( new Settings() )->get( 'ai', [] );
		$choice = self::choices( is_array( $ai ) ? $ai : [] )[ $feature ];
		if ( $enabled && ! $choice['ownerAllowed'] ) return new \WP_Error( 'kiwe_ai_owner_permission', __( 'Your website manager must allow this feature before you turn it on.', 'dsa' ) );
		$option = self::FEATURES[ $feature ]['option'];
		$value = $enabled ? '1' : '0';
		update_option( $option, $value, false );
		if ( $value !== get_option( $option, null ) ) return new \WP_Error( 'kiwe_ai_preference_save', __( 'Your choice could not be confirmed. Refresh and try again.', 'dsa' ) );
		return true;
	}
}
