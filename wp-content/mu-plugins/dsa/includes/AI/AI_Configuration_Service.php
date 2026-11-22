<?php
namespace DSA\AI;
use DSA\Apps\AI_App_Service;
use DSA\Security\Secret_Store;
use DSA\Settings;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Editing the original AI settings; provider credentials are write-only. */
final class AI_Configuration_Service {
	public const PROVIDERS = [ 'none','wordpress_ai_client','openai_compatible','gemini','groq','xai' ];
	public const FLAGS = [ 'studio_enabled','token_saver_enabled','prefer_companion_context','bricks_editor_companion_enabled','allow_browser_handoff_export','companion_enabled','log_prompts' ];
	public const LIMITS = [ 'max_native_tokens'=>[200,12000], 'max_native_context_bytes'=>[10000,200000], 'max_context_cards'=>[4,40], 'max_review_bytes'=>[20000,500000], 'cache_ttl_seconds'=>[30,3600] ];
	public const OWNER_FIELDS = [ 'allowNativeGeneration', 'seoAllowed', 'securetrackAllowed', 'requestLimits', 'tokenLimits', 'configuration' ];
	public static function owner_allowed(): bool {
		return AI_App_Service::enabled() && current_user_can( AI_Feature_Policy::CAPABILITY ) && ( current_user_can( 'manage_options' ) || current_user_can( 'kiwe_manage_context' ) );
	}

	/** One fingerprint for both the WordPress editor and a reviewed MCP plan. */
	public static function owner_snapshot( array $ai, Settings $settings ): array {
		$allowance = new AI_Allowance_Service( $settings );
		$provider = ( new AI_Provider_Service( $settings ) )->status();
		$controls = [
			'allowNativeGeneration'=>! empty( $ai['allow_native_generation'] ),
			'seoAllowed'=>! empty( $ai['seo_enabled'] ),
			'securetrackAllowed'=>! empty( $ai['securetrack_brief_enabled'] ),
			'requestLimits'=>$allowance->limits(),
			'tokenLimits'=>$allowance->token_limits(),
		];
		$sealed = (string) ( $ai['native_api_key'] ?? '' );
		$view = $controls + [
			'configuration'=>self::projection( $ai ),
			'keyStored'=>'' !== $sealed,
			'provider'=>[
				'id'=>(string) ( $provider['provider'] ?? 'none' ), 'model'=>(string) ( $provider['model'] ?? '' ),
				'enabled'=>! empty( $provider['enabled'] ), 'configured'=>! empty( $provider['configured'] ),
				'keyStatus'=>sanitize_key( (string) ( $provider['keyStatus']['status'] ?? 'empty' ) ),
			],
		];
		return $view + [ 'expected'=>hash( 'sha256', wp_json_encode( $view + [ 'keyProof'=>hash_hmac( 'sha256', $sealed, wp_salt( 'nonce' ) ) ] ) ) ];
	}

	public static function patch_owner( array $ai, array $changes ): array|\WP_Error {
		if ( ! $changes || array_diff( array_keys( $changes ), self::OWNER_FIELDS ) ) return new \WP_Error( 'kiwe_ai_owner_value', 'Choose a valid website AI control.' );
		foreach ( $changes as $key=>$value ) {
			if ( 'configuration' === $key ) {
				if ( ! is_array( $value ) ) return new \WP_Error( 'kiwe_ai_owner_value', 'Choose supported AI settings.' );
				$ai = self::patch( $value, $ai );
				if ( is_wp_error( $ai ) ) return $ai;
				continue;
			}
			if ( in_array( $key, [ 'requestLimits', 'tokenLimits' ], true ) ) {
				$windows = [ 'minute', 'day', 'month' ];
				if ( ! is_array( $value ) || count( $value ) !== count( $windows ) || array_diff( $windows, array_keys( $value ) ) ||
					count( array_filter( $value, static fn( $n ) => is_int( $n ) && $n >= 0 && $n <= 1000000000 ) ) !== count( $windows ) ) {
					return new \WP_Error( 'kiwe_ai_owner_value', 'Enter whole-number allowance limits.' );
				}
				if ( 'requestLimits' === $key ) $ai['request_allowance'] = AI_Allowance_Service::sanitize_limits( $value );
				else $ai['token_allowance'] = AI_Allowance_Service::sanitize_token_limits( $value );
				continue;
			}
			if ( ! is_bool( $value ) ) return new \WP_Error( 'kiwe_ai_owner_value', 'Choose on or off.' );
			$setting = match ( $key ) {
				'allowNativeGeneration'=>'allow_native_generation',
				'seoAllowed'=>'seo_enabled',
				'securetrackAllowed'=>'securetrack_brief_enabled',
			};
			$ai[$setting] = $value;
		}
		return $ai;
	}

	public static function save_owner( array $changes, string $expected ): array|\WP_Error {
		if ( ! self::owner_allowed() ) return new \WP_Error( 'kiwe_ai_owner_forbidden', 'Website AI setup is unavailable for your account.' );
		$raw = get_option( DSA_OPTION_SETTINGS, null );
		if ( ! is_array( $raw ) ) return new \WP_Error( 'kiwe_ai_owner_settings', 'Website settings are unavailable. Refresh and review.' );
		$ai = is_array( $raw['ai'] ?? null ) ? $raw['ai'] : [];
		$settings = new Settings();
		if ( ! hash_equals( self::owner_snapshot( $ai, $settings )['expected'], $expected ) ) return new \WP_Error( 'kiwe_ai_owner_changed', 'Website AI settings changed. Refresh before saving.' );
		$next = self::patch_owner( $ai, $changes );
		if ( is_wp_error( $next ) ) return $next;
		if ( ! $settings->compare_and_update_selected( $raw, [ 'ai'=>$next ] ) ) return new \WP_Error( 'kiwe_ai_owner_changed', 'Settings changed or the save could not be confirmed. Refresh before retrying.' );
		$stored = get_option( DSA_OPTION_SETTINGS, null );
		if ( ! is_array( $stored ) || ( $stored['ai'] ?? null ) !== $next ) return new \WP_Error( 'kiwe_ai_owner_save', 'The setting could not be confirmed. Refresh and review.' );
		return self::owner_snapshot( $next, $settings );
	}

	public static function projection( array $ai ): array {
		$all = array_replace( ( new Settings() )->defaults()['ai'] ?? [],$ai );
		return array_intersect_key( $all,array_flip( [ ...self::FLAGS,...array_keys( self::LIMITS ),'native_provider','native_model','native_base_url','studio_mode','companion_modes' ] ) );
	}
	public static function patch( array $input,array $ai ) {
		$keys = [ ...array_keys( self::projection( $ai ) ),'native_api_key','remove_api_key' ];
		if ( array_diff( array_keys( $input ),$keys ) ) return new \WP_Error( 'kiwe_ai_configuration','Use the supported AI settings.' );
		foreach ( $input as $key=>$value ) {
			if ( in_array( $key,[ ...self::FLAGS,'remove_api_key' ],true ) ) { if ( ! is_bool( $value ) ) return new \WP_Error( 'kiwe_ai_configuration','Choose on or off.' ); }
			elseif ( isset( self::LIMITS[$key] ) ) { if ( ! is_int( $value ) || $value<self::LIMITS[$key][0] || $value>self::LIMITS[$key][1] ) return new \WP_Error( 'kiwe_ai_configuration','Choose a valid AI limit.' ); }
			elseif ( 'native_provider' === $key ) { if ( ! in_array( $value,self::PROVIDERS,true ) ) return new \WP_Error( 'kiwe_ai_configuration','Choose a supported provider.' ); }
			elseif ( 'native_model' === $key ) { if ( ! is_string( $value ) || strlen( $value )>160 || ( '' !== $value && ! preg_match( '#^[a-zA-Z0-9._:/-]+$#D',$value ) ) ) return new \WP_Error( 'kiwe_ai_configuration','Enter a supported model identifier.' ); }
			elseif ( 'native_base_url' === $key ) {
				if ( ! is_string( $value ) || strlen( $value )>500 ) return new \WP_Error( 'kiwe_ai_configuration','Enter a public HTTPS provider address.' );
				$url = parse_url( $value );
				if ( '' !== $value && ( ! is_array( $url ) || ( $url['scheme'] ?? '' ) !== 'https' || array_intersect( array_keys( $url ),[ 'user','pass','fragment','query' ] ) || ! wp_http_validate_url( $value ) ) ) return new \WP_Error( 'kiwe_ai_configuration','Use a public HTTPS provider address without embedded credentials.' );
			}
			elseif ( 'native_api_key' === $key ) { if ( ! is_string( $value ) || strlen( $value )>4096 || preg_match( '/[\x00-\x20\x7f]/',$value ) ) return new \WP_Error( 'kiwe_ai_configuration','Enter a valid provider key.' ); }
			elseif ( 'studio_mode' === $key ) { if ( ! in_array( $value,[ 'native','browser_companion','browser_only' ],true ) ) return new \WP_Error( 'kiwe_ai_configuration','Choose a Studio mode.' ); }
			elseif ( 'companion_modes' === $key ) { if ( ! is_array( $value ) || array_diff( array_keys( $value ),[ 'website','theme','combined','dynamic','audit','staging','security' ] ) ) return new \WP_Error( 'kiwe_ai_configuration','Choose supported companion modes.' ); foreach ( $value as $mode ) if ( ! is_bool( $mode ) ) return new \WP_Error( 'kiwe_ai_configuration','Choose on or off for each mode.' ); }
		}
		$key = $input['native_api_key'] ?? ''; unset( $input['native_api_key'] );
		$remove = $input['remove_api_key'] ?? false; unset( $input['remove_api_key'] );
		if ( $remove && '' !== $key ) return new \WP_Error( 'kiwe_ai_configuration','Choose either a replacement key or removal.' );
		$ai = array_replace( $ai,$input );
		if ( $remove ) $ai['native_api_key'] = '';
		elseif ( '' !== $key ) { $sealed=Secret_Store::encrypt( $key ); if ( '' === $sealed ) return new \WP_Error( 'kiwe_ai_configuration','The provider key could not be protected. No change was saved.' ); $ai['native_api_key']=$sealed; }
		return $ai;
	}
}
