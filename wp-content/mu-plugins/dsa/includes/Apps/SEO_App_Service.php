<?php
namespace DSA\Apps;

use DSA\Access\Task_Workspace_UI as UI;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Local app admission. No catalogue request, SEO engine construction or visitor writes. */
final class SEO_App_Service {

	public const OPTION = 'kiwe_seo_workspace_enabled_v1';
	public const CAPABILITY = 'kiwe_manage_ai_preferences';
	public const ACTION = 'kiwe_seo_app';
	private bool $resolved = false;

	public static function external_owner(): string {
		foreach ( [ 'RANK_MATH_VERSION'=>'rank_math', 'WPSEO_VERSION'=>'yoast', 'AIOSEO_VERSION'=>'aioseo', 'SEOPRESS_VERSION'=>'seopress', 'THE_SEO_FRAMEWORK_VERSION'=>'seo_framework' ] as $constant=>$owner ) if ( defined( $constant ) ) return $owner;
		return '';
	}
	public static function requested(): bool {
		// Existing Kiwe sites retain their choice. A new external-owner site defaults off.
		$stored = get_option( self::OPTION, null );
		return null === $stored ? '' === self::external_owner() : in_array( $stored, [ true, 1, '1' ], true );
	}
	public static function enabled(): bool { return Runtime_Registry::installed('seo') && '' === self::external_owner() && self::requested(); }
	public static function allowed(): bool { return current_user_can( self::CAPABILITY ); }
	public static function url(): string { return admin_url( 'admin.php?page=kiwe-seo-assistant' ); }
	public static function home_url(): string { return admin_url( 'admin.php?page=kiwe-workspace&area=apps' ); }
	public static function expected(): string { return hash( 'sha256', (string) wp_json_encode( [ get_option( self::OPTION, null ), self::external_owner(), Runtime_Registry::installed('seo'), Runtime_Registry::provider('seo')['version'] ?? '' ] ) ); }

	public function register( callable $start ): void {
		// MU boot precedes normal plugins: detect the actual SEO owner only after they load.
		add_action( 'plugins_loaded', function () use ( $start ): void {
			if ( $this->resolved ) return;
			$this->resolved = true;
			if ( ! self::enabled() ) {
				// Individual search privacy remains Core; another provider keeps full ownership.
				( new \DSA\Onboarding\Native_Indexing_Service() )->register();
				return;
			}
			$start();
		}, 30 );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_init', [ $this, 'refresh_rewrites' ], 100 );
	}
	/** Rebuild after the next complete init, never with the old engine's rules in memory. */
	public function refresh_rewrites(): void {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ! self::allowed() ) return;
		$owner = self::external_owner();
		$marker = self::enabled() ? '2' : ( $owner ? 'external:' . $owner : 'core' );
		if ( $marker === get_option( 'kiwe_native_sitemap_rewrite_v1', '' ) ) return;
		flush_rewrite_rules( false );
		update_option( 'kiwe_native_sitemap_rewrite_v1', $marker, false );
	}

	public static function card(): array {
		$owner = self::external_owner();
		$names = [ 'rank_math'=>'Rank Math', 'yoast'=>'Yoast SEO', 'aioseo'=>'All in One SEO', 'seopress'=>'SEOPress', 'seo_framework'=>'The SEO Framework' ];
		$enabled = self::enabled();
		return [ 'id'=>'seo', 'enabled'=>$enabled, 'canChange'=>self::allowed(), 'blocked'=>!Runtime_Registry::installed('seo'), 'installed'=>Runtime_Registry::installed('seo'), 'manageUrl'=>current_user_can('install_plugins') || current_user_can('update_plugins') ? Library_Service::url('seo') : '', 'externalOwner'=>$owner,
			'status'=>$owner ? 'Managed by ' . ( $names[$owner] ?? 'another SEO plugin' ) : ( $enabled ? 'On' : 'Off' ),
			'description'=>$owner ? 'Your existing SEO provider stays in charge. A reviewed handover is needed before Kiwe SEO can take over.' : (!Runtime_Registry::installed('seo') ? 'Add Kiwe SEO for search tools and discovery. Your full Design Context stays available in Core.' : ( $enabled ? 'Search details, sitemaps, structured data and SEO reviews.' : 'Turn on to use Kiwe SEO. Your saved SEO details stay on the website.' )),
			'url'=>$enabled ? self::url() : '', 'endpoint'=>self::allowed() ? rest_url( 'dsa/v1/workspace/apps/seo' ) : '',
			'expected'=>self::allowed() ? self::expected() : '', 'action'=>admin_url( 'admin-post.php' ), 'nonce'=>self::allowed() ? wp_create_nonce( self::ACTION ) : '',
		];
	}

	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/apps/seo', [ 'methods'=>'POST', 'callback'=>[ $this, 'save' ], 'permission_callback'=>[ $this, 'permission' ] ] );
	}
	/** Native fallback for Home; the interactive card reuses this same projection. */
	public static function render_card( array $task ): void { App_Card_Service::render( $task ); }

	public function permission( \WP_REST_Request $request ): bool {
		return self::allowed() && 'POST' === $request->get_method() && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request() && true === Origin_Checker::mutation_allowed( $request );
	}
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return UI::failure( new \WP_Error( 'kiwe_seo_app_forbidden', 'Your website manager controls this app.' ), 403 );
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_diff( array_keys( $body ), [ 'enabled', 'expected' ] ) || ! is_bool( $body['enabled'] ?? null ) || ! is_string( $body['expected'] ?? null ) ) return UI::failure( new \WP_Error( 'kiwe_seo_app_value', 'Choose whether Kiwe SEO should be on.' ), 400 );
		$result = self::change( $body['enabled'], $body['expected'] );
		return is_wp_error( $result ) ? UI::failure( $result, 409 ) : UI::response( [ 'app'=>self::card(), 'reload'=>true ] );
	}

	/** One existing native option; preserve defaults, records, provider settings and job history. */
	public static function change( bool $enabled, string $expected ) {
		if ( ! self::allowed() ) return new \WP_Error( 'kiwe_seo_app_forbidden', 'Your website manager controls this app.' );
		global $wpdb;
		$lock = 'kiwe-seo-app-' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) return new \WP_Error( 'kiwe_seo_app_busy', 'Another app change is finishing. Refresh before trying again.' );
		try {
			wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
			if ( ! hash_equals( self::expected(), $expected ) ) return new \WP_Error( 'kiwe_seo_app_stale', 'The app state changed. Refresh before saving.' );
			if ( $enabled && !Runtime_Registry::installed('seo') ) return new \WP_Error( 'kiwe_seo_app_package', 'Install and activate Kiwe SEO before turning it on.' );
			if ( $enabled && '' !== self::external_owner() ) return new \WP_Error( 'kiwe_seo_app_owner', 'Your existing SEO provider is active. Complete its reviewed handover before enabling Kiwe SEO.' );
			$value = $enabled ? '1' : '0';
			update_option( self::OPTION, $value, false );
			if ( get_option( self::OPTION, null ) !== $value ) return new \WP_Error( 'kiwe_seo_app_save', 'The app change could not be confirmed. Refresh and review.' );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		// Native rebuild/purge only for the explicit manager action, never normal rendering.
		update_option( 'kiwe_native_sitemap_rewrite_v1', 'pending', false );
		if ( class_exists( '\WPO_Page_Cache', false ) ) \WPO_Page_Cache::instance()->purge();
		do_action( 'litespeed_purge_all' );
		return true;
	}

	public function handle(): void {
		if ( ! self::allowed() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! Origin_Checker::is_same_site_request() ) wp_die( 'Your website manager controls this app.', '', [ 'response'=>403 ] );
		check_admin_referer( self::ACTION );
		$input = wp_unslash( $_POST );
		if ( ! is_string( $input['enabled'] ?? null ) || ! in_array( $input['enabled'], [ '0', '1' ], true ) || ! is_string( $input['expected'] ?? null ) ) wp_die( 'Choose a valid app state.', '', [ 'response'=>400 ] );
		$result = self::change( '1' === $input['enabled'], $input['expected'] );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', [ 'response'=>409, 'back_link'=>true ] );
		wp_safe_redirect( self::home_url() ); exit;
	}
}
