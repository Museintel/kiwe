<?php
/** One Store slug must have one capability owner in either workspace mode. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'KIWE_REACT_WORKSPACE', true );
	$GLOBALS['native_store_enabled'] = true;
	$GLOBALS['menu_rows'] = [];
	function add_menu_page( ...$args ) { $GLOBALS['menu_rows'][] = [ 'cap' => $args[2], 'slug' => $args[3] ]; }
	function add_submenu_page( ...$args ) {}
}
namespace DSA\Communications { class Channel_Service {} }
namespace DSA\Onboarding {
	class Design_Context_Profile_Service { public function commerce_available(): bool { return true; } }
	class Design_Context_Refinement_Service {}
	class Design_Context_Journey_Service { public const PAGE='kiwe-design-context'; public function render(): void {} }
}
namespace DSA\Access {
	class Commerce_App_Service {
		public const PAGE = 'kiwe-store';
		public static function enabled(): bool { return $GLOBALS['native_store_enabled']; }
		public function menu(): void { if ( self::enabled() ) \add_menu_page( 'Store', 'Store', 'read', self::PAGE ); }
	}
}
namespace {
	require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Onboarding/Onboarding_Service.php';
	$onboarding = new \DSA\Onboarding\Onboarding_Service(
		new \DSA\Onboarding\Design_Context_Profile_Service(),
		new \DSA\Communications\Channel_Service(),
		new \DSA\Onboarding\Design_Context_Refinement_Service()
	);
	( new \DSA\Access\Commerce_App_Service() )->menu();
	$onboarding->menu();
	$store = array_values( array_filter( $GLOBALS['menu_rows'], static fn( $row ) => 'kiwe-store' === $row['slug'] ) );
	if ( count( $store ) !== 1 || 'read' !== $store[0]['cap'] ) throw new \RuntimeException( 'Native Store has a duplicate or restricted menu owner' );
	$GLOBALS['native_store_enabled'] = false;
	$GLOBALS['menu_rows'] = [];
	$onboarding->menu();
	$store = array_values( array_filter( $GLOBALS['menu_rows'], static fn( $row ) => 'kiwe-store' === $row['slug'] ) );
	if ( count( $store ) !== 1 || 'kiwe_manage_context' !== $store[0]['cap'] ) throw new \RuntimeException( 'Legacy Store fallback was lost' );
	echo "Native and legacy Store menu ownership passed.\n";
}
