<?php
namespace DSA\AI;
use DSA\Security\Secret_Store;
use DSA\Settings;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Editing the original AI settings; provider credentials are write-only. */
final class AI_Configuration_Service {
	public const PROVIDERS = [ 'none','wordpress_ai_client','openai_compatible','gemini','groq','xai' ];
	public const FLAGS = [ 'studio_enabled','token_saver_enabled','prefer_companion_context','bricks_editor_companion_enabled','allow_browser_handoff_export','companion_enabled','log_prompts' ];
	public const LIMITS = [ 'max_native_tokens'=>[200,12000], 'max_native_context_bytes'=>[10000,200000], 'max_context_cards'=>[4,40], 'max_review_bytes'=>[20000,500000], 'cache_ttl_seconds'=>[30,3600] ];
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
