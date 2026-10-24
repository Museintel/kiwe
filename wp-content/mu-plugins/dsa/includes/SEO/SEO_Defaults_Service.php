<?php

namespace DSA\SEO;

use DSA\Access\Task_Workspace_UI as UI;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__.'/SEO_Defaults_Model.php';

/** Kiwe-only defaults; active external SEO providers retain their native authority. */
final class SEO_Defaults_Service {
	public const OPTION = 'kiwe_seo_defaults_v1';
	public const SCHEMA = 1;
	public const ROBOTS = [ 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ];
	public const VARIABLES = [ 'title', 'sitename', 'sep', 'excerpt', 'category', 'term', 'term_description', 'name', 'date', 'currentyear', 'page', 'tagline' ];

	public static function external_owner(): string { return \DSA\Apps\SEO_App_Service::external_owner(); }
	public static function enabled(): bool { return \DSA\Apps\SEO_App_Service::enabled(); }

	public static function defaults(): array { return SEO_Defaults_Model::defaults(); }
	/** Reads only one small native option, with WordPress's request/object caching. */
	public static function settings(): array {
		static $previous = null, $resolved = null;
		$stored = get_option( self::OPTION, [] );
		if ( null !== $resolved && $stored === $previous ) return $resolved;
		$clean = is_array( $stored ) && $stored ? self::validate( $stored ) : [];
		$previous = $stored;
		return $resolved = is_wp_error( $clean ) ? self::defaults() : array_replace_recursive( self::defaults(), $clean );
	}

	/** Publication identity enables News discovery without another client switch. */
	public static function news_automatic(): bool {
		$profile = get_option( \DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE, [] );
		return is_array( $profile ) && \DSA\Bricks\Design_Context_Binding_Service::activities($profile)['publication'];
	}
	public static function news(): array {
		// Explicit imported News settings remain recoverable for older installations.
		if ( ! self::news_automatic() ) return self::settings()['news'];
		$locale = strtolower( str_replace( '_', '-', (string) get_option( 'WPLANG', '' ) ) );
		if ( '' === $locale ) $locale = 'en-us';
		$language = str_starts_with( $locale, 'zh' ) ? ( preg_match( '/^zh-(tw|hk|hant)(-|$)/', $locale ) ? 'zh-tw' : 'zh-cn' ) : explode( '-', $locale )[0];
		if ( ! preg_match( '/^(?:[a-z]{2,3}|zh-cn|zh-tw)$/D', $language ) ) $language = '';
		return [ 'enabled'=>true, 'publication'=>mb_substr( trim( wp_strip_all_tags( (string) get_option( 'blogname', '' ) ) ), 0, 200 ), 'language'=>$language ];
	}

	public static function expected(): string { return hash( 'sha256', (string) wp_json_encode( get_option( self::OPTION, [] ) ) ); }

	public function register(): void { add_action( 'rest_api_init', [ $this, 'routes' ] ); }
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/seo/defaults', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'write' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		return ( current_user_can( 'kiwe_manage_ai_preferences' ) || current_user_can( 'manage_options' ) ) && in_array( $request->get_method(), [ 'GET', 'POST' ], true ) && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request() && ( 'GET' === $request->get_method() || true === Origin_Checker::mutation_allowed( $request ) );
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return UI::failure( new \WP_Error( 'kiwe_seo_defaults_forbidden', 'Website SEO defaults are unavailable for your account.' ), 403 );
		$types = []; foreach ( get_post_types( [ 'public'=>true ], 'objects' ) as $name=>$type ) if ( 'attachment' !== $name ) $types[] = [ 'id'=>$name, 'label'=>$type->labels->name ];
		$taxonomies = []; foreach ( get_taxonomies( [ 'public'=>true ], 'objects' ) as $name=>$taxonomy ) $taxonomies[] = [ 'id'=>$name, 'label'=>$taxonomy->labels->name ];
		return UI::response( [ 'schema'=>1, 'owner'=>self::external_owner() ?: 'kiwe', 'editable'=>'' === self::external_owner(), 'enabled'=>self::enabled(), 'settings'=>self::settings(), 'variables'=>self::VARIABLES, 'postTypes'=>$types, 'taxonomies'=>$taxonomies, 'sitemapUrl'=>home_url( '/wp-sitemap.xml' ), 'newsUrl'=>home_url( '/news-sitemap.xml' ), 'newsAutomatic'=>self::news_automatic(), 'newsConfiguration'=>self::news(), 'identityUrl'=>current_user_can( 'kiwe_manage_context' ) ? admin_url( 'admin.php?page=kiwe-identity' ) : '', 'expected'=>self::expected() ] );
	}
	public function write( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return UI::failure( new \WP_Error( 'kiwe_seo_defaults_forbidden', 'Website SEO defaults are unavailable for your account.' ), 403 );
		if ( '' !== self::external_owner() ) return UI::failure( new \WP_Error( 'kiwe_seo_defaults_owner', 'Change website defaults in the active SEO provider. Reviewed handover is a separate operation.' ), 409 );
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_string( $body['expected'] ?? null ) || ! hash_equals( self::expected(), $body['expected'] ) ) return UI::failure( new \WP_Error( 'kiwe_seo_defaults_stale', 'Website defaults changed. Refresh before saving.' ), 409 );
		$clean = self::validate( $body['settings'] ?? null );
		if ( is_wp_error( $clean ) ) return UI::failure( $clean, 400 );
		if ( ! SEO_Settings_Handover_Service::compare_defaults( get_option( self::OPTION, null ), $clean ) ) return UI::failure( new \WP_Error( 'kiwe_seo_defaults_save', 'The defaults changed during saving. Refresh before retrying.' ), 409 );
		do_action( 'kiwe_seo_defaults_changed' );
		do_action( 'litespeed_purge_all' );
		return $this->read( $request );
	}

	/** Refuse malformed/unknown state instead of silently dropping effective source behavior. */
	public static function validate( $input ) {
		$value = SEO_Defaults_Model::validate( $input );
		return false === $value ? new \WP_Error( 'kiwe_seo_defaults_invalid', 'Review the SEO defaults, supported template variables and sitemap limits.' ) : $value;
	}
	public static function validate_robots( $robots ) { return SEO_Defaults_Model::validate_robots( $robots ); }
	public static function valid_template( string $value ): bool { return SEO_Defaults_Model::valid_template( $value ); }
	public static function expand( string $value, array $variables, int $limit = 320 ): string {
		if ( ! self::valid_template( $value ) ) return '';
		$replace = []; foreach ( self::VARIABLES as $key ) $replace['%' . $key . '%'] = (string) ( $variables[$key] ?? '' );
		$value = strtr( $value, $replace );
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) ), 0, $limit );
	}
	public static function url( string $url ): string {
		if ( strlen( $url ) > 2048 || ! preg_match( '#^https?://#i', $url ) ) return '';
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'], $parts['pass'] ) || isset( $parts['user'] ) ) return '';
		return (string) esc_url_raw( $url );
	}
	public static function rule( string $scope, string $key = '' ): array {
		$s = self::settings();
		$r = '' === $key ? ( $s[$scope] ?? [] ) : ( $s[$scope][$key] ?? [] );
		return is_array( $r ) ? $r : [];
	}
	public static function robots_for( string $scope, string $key, $explicit = [] ): array {
		$rule = self::rule( $scope, $key );
		$base = array_key_exists( 'robots', $rule ) ? $rule['robots'] : self::settings()['globalRobots'];
		$r = self::validate_robots( $explicit );
		return is_array( $r ) && $r ? $r : $base;
	}
}
