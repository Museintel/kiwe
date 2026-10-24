<?php
declare(strict_types=1);
namespace DSA\Onboarding {
	final class Design_Context_Profile_Service {
		public const OPTION_PROFILE = 'kiwe_seam_design_context_v1';
		public function current(): array { return (array) \get_option( self::OPTION_PROFILE, [] ); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
	final class WP_Error { public function __construct( public string $code, public string $message, public array $data = [] ) {} }
	$GLOBALS['opts'] = []; $GLOBALS['types'] = []; $GLOBALS['taxes'] = []; $GLOBALS['cap'] = true; $GLOBALS['flushes'] = 0; $GLOBALS['checks'] = 0;
	function add_action( ...$args ): void {}
	function __( string $value, string $domain ): string { return $value; }
	function get_option( string $key, $default = false ) { return $GLOBALS['opts'][ $key ] ?? $default; }
	function update_option( string $key, $value, bool $autoload = false ): bool { $GLOBALS['opts'][ $key ] = $value; return true; }
	function current_user_can( string $cap ): bool { return $GLOBALS['cap'] && 'kiwe_manage_context' === $cap; }
	function post_type_exists( string $key ): bool { return isset( $GLOBALS['types'][ $key ] ); }
	function taxonomy_exists( string $key ): bool { return isset( $GLOBALS['taxes'][ $key ] ); }
	function register_post_type( string $key, array $args ): object { $GLOBALS['types'][ $key ] = $args; return (object) $args; }
	function register_taxonomy( string $key, array $types, array $args ): object { $GLOBALS['taxes'][ $key ] = [ 'types'=>$types, 'args'=>$args ]; return (object) $args; }
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function wp_json_encode( $value ): string { return (string) json_encode( $value ); }
	function flush_rewrite_rules( bool $hard = true ): void { $GLOBALS['flushes']++; }
	function check( bool $okay, string $name ): void { $GLOBALS['checks']++; if ( ! $okay ) throw new \RuntimeException( $name ); }
	require_once __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Onboarding/Native_Service_Source_Service.php';
	use DSA\Onboarding\Native_Service_Source_Service as Source;
	$source = new Source();
	check( Source::can_activate(), 'An empty new site can choose native services.' );
	$GLOBALS['opts']['kiwe_seam_design_context_v1'] = [ 'services'=>[ 'items'=>[ [ 'title'=>'Existing plan' ] ] ] ];
	check( ! Source::can_activate(), 'Existing plans are never converted implicitly.' );
	$GLOBALS['opts'] = [ 'kiwe_seam_design_context_v1'=>[ 'services'=>[ 'sourcePostType'=>'external_service', 'items'=>[] ] ] ];
	check( ! Source::can_activate(), 'External source cannot be replaced.' );
	$GLOBALS['opts'] = []; $GLOBALS['types']['service'] = [];
	check( ! Source::can_activate(), 'A preexisting service collection blocks a duplicate.' );
	$GLOBALS['types'] = []; $GLOBALS['cap'] = false;
	check( ! Source::can_activate(), 'Lower role cannot start a public collection.' );
	$GLOBALS['cap'] = true;
	check( is_wp_error( $source->activate( str_repeat( '0', 64 ) ) ) && !$GLOBALS['types'] && !$GLOBALS['opts'], 'Stale activation cannot register a source or write settings.' );
	$expected = hash( 'sha256', wp_json_encode( [] ) );
	$result = $source->activate( $expected );
	check( ! is_wp_error( $result ) && $result['source'] === Source::POST_TYPE && $GLOBALS['flushes'] === 1, 'Activation registers and flushes rewrites only on the explicit transition.' );
	check( get_option( 'kiwe_seam_design_context_v1' )['services']['sourcePostType'] === Source::POST_TYPE && !$GLOBALS['opts']['kiwe_seam_design_context_v1']['services']['items'], 'WordPress profile selects the native source without creating content.' );
	check( $GLOBALS['types'][Source::POST_TYPE]['show_in_rest'] && !$GLOBALS['types'][Source::POST_TYPE]['show_in_menu'] && $GLOBALS['taxes'][Source::TAXONOMY]['args']['hierarchical'], 'Native post and category stay REST-readable, hierarchical and out of the default client menu.' );
	check( ! Source::can_activate(), 'Selected source cannot be activated twice.' );
	$GLOBALS['types'] = []; $GLOBALS['taxes'] = []; $source->register_if_selected();
	check( post_type_exists( Source::POST_TYPE ) && taxonomy_exists( Source::TAXONOMY ) && $GLOBALS['flushes'] === 1, 'Selected source registers on later requests without repeated rewrite flush.' );
	$GLOBALS['opts'] = []; $GLOBALS['types'] = []; $GLOBALS['taxes'] = []; $source->register_if_selected();
	check( !$GLOBALS['types'] && !$GLOBALS['taxes'], 'Unselected site pays no custom-type registration cost.' );
	echo $GLOBALS['checks'] . " native service source checks passed.\n";
}
