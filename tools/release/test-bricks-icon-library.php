<?php
/** Built-in SVG boundaries; optional BRICKS_SOURCE uses the actual native Icon renderer. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
$owner_reads = 0;
function __( $value, $domain = '' ) { return $value; }
function get_option( $key, $default = false ) { $GLOBALS['owner_reads']++; return $default; }
function wp_get_nav_menus() { $GLOBALS['owner_reads']++; return []; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }

$root = dirname( __DIR__, 2 );
$includes = $root . '/wp-content/mu-plugins/dsa/includes/';
require $includes . 'Bricks/Icon_Library_Service.php';
require $includes . 'Bricks/Design_Context_Binding_Service.php';
require $includes . 'Access/Tag_Group_Service.php';
require $includes . 'Bricks/Bricks_Integration.php';

$bridge = ( new ReflectionClass( DSA\Bricks\Bricks_Integration::class ) )->newInstanceWithoutConstructor();
$checks = [];
function check( bool $passed, string $label ): void {
	$GLOBALS['checks'][] = [ 'label' => $label, 'passed' => $passed ];
	if ( ! $passed ) throw new RuntimeException( $label );
}
function bricks_render_dynamic_data( $value ) { return $GLOBALS['bridge']->render_dynamic_content( $value ); }

$definitions = DSA\Bricks\Icon_Library_Service::definitions();
check( count( $definitions ) === 34 && isset( $definitions['kiwe_icon_comments'], $definitions['kiwe_icon_message_circle'], $definitions['kiwe_icon_thumbs_up'], $definitions['kiwe_icon_flag'], $definitions['kiwe_icon_pin'] ) && $owner_reads === 0, 'Fixed icon chooser includes conversation/reaction artwork without data-owner reads' );
$sprite = file_get_contents( $root . '/wp-content/mu-plugins/dsa/assets/icons/lucide/sprite.svg' );
preg_match_all( '/<symbol\b[^>]*\bid="([a-z0-9-]+)"/', $sprite, $symbols );
foreach ( $symbols[1] as $symbol ) {
	$tag = 'kiwe_icon_' . str_replace( '-', '_', $symbol );
	check( isset( $definitions[ $tag ] ), 'Packaged symbol is discoverable: ' . $symbol );
	$svg = DSA\Bricks\Icon_Library_Service::render( $tag );
	check( str_starts_with( $svg, '<svg ' ) && str_ends_with( $svg, '</svg>' ) && str_contains( $svg, 'stroke="currentColor"' ), 'Packaged symbol renders inline: ' . $symbol );
	check( ! preg_match( '/<(?:use|script|foreignObject)\b|(?:href|style|onload)=/', $svg ), 'No external reference or active content: ' . $symbol );
}
$cart = DSA\Bricks\Icon_Library_Service::render( 'kiwe_icon_cart' );
check( $cart === DSA\Bricks\Icon_Library_Service::render( 'kiwe_icon_shopping_bag' ), 'Cart is the exact shared shopping-bag artwork' );
check( DSA\Bricks\Icon_Library_Service::render( 'kiwe_icon_notifications' ) === DSA\Bricks\Icon_Library_Service::render( 'kiwe_icon_bell' ), 'Notifications is the exact bell artwork' );
check( DSA\Bricks\Icon_Library_Service::render( 'kiwe_icon_wishlist' ) === DSA\Bricks\Icon_Library_Service::render( 'kiwe_icon_heart' ), 'Wishlist is the exact heart artwork' );
check( $owner_reads === 0, 'Rendering all icons needs no user, content, option or provider reads' );

foreach ( [ 'image', 'media', 'link', 'url', 'attribute', 'html', 'unsafe' ] as $context ) {
	check( '' === $bridge->render_dynamic_tag( '{kiwe_icon_cart}', null, $context ), 'Non-icon context fails closed: ' . $context );
}
foreach ( [ 'kiwe_icon_unknown', 'kiwe_icon_../sprite', 'kiwe_icon_cart:other', 'kiwe_icon_https://example.test/icon', 'kiwe_icon_cart" onload="alert(1)', 'kiwe_icon_CART' ] as $tag ) {
	check( '' === DSA\Bricks\Icon_Library_Service::render( $tag ), 'Unknown or unsafe icon selector denied: ' . $tag );
}
check( '' === $bridge->render_dynamic_tag( '{kiwe_icon_unknown}' ), 'Unknown owned tag does not leak an unresolved placeholder' );
check( '{other_tag}' === $bridge->render_dynamic_tag( '{other_tag}' ), 'Unowned native tag stays with Bricks' );
check( $cart === $bridge->render_dynamic_tag( '{kiwe_icon_cart}' ), 'Registered tag resolves through the ordinary Bricks hook' );
check( 'Before ' . $cart . ' After' === $bridge->render_dynamic_content( 'Before {kiwe_icon_cart} After' ), 'Native dynamic content resolves inline SVG' );
$tags = $bridge->add_dynamic_tags( [ [ 'name' => '{kiwe_icon_cart}', 'label' => 'Already registered', 'group' => 'Existing' ] ] );
check( count( array_filter( $tags, static fn( $tag ) => '{kiwe_icon_cart}' === $tag['name'] ) ) === 1, 'An existing registration is preserved once' );
check( count( array_filter( $tags, static fn( $tag ) => 'Kiwe icons' === ( $tag['group'] ?? '' ) ) ) === count( $definitions ) - 1, 'Remaining icon choices appear in Kiwe icons' );

// Deliberately corrupt process-local fixture artwork; the package path is never caller-controlled.
$class = new ReflectionClass( DSA\Bricks\Icon_Library_Service::class );
$sprite_property = $class->getProperty( 'sprite' );
$cache_property = $class->getProperty( 'rendered' );
foreach ( [ '<script>alert(1)</script>', '<path d="M0 0" onload="alert(1)"/>', '<image href="https://example.test/private"/>', '<path d="M0 0" style="fill:url(https://example.test)"/>', '<path d="M0 0" fill="url(https://example.test/private)"/>' ] as $unsafe ) {
	$sprite_property->setValue( null, '<symbol id="shopping-bag">' . $unsafe . '</symbol>' );
	$cache_property->setValue( null, [] );
	check( '' === DSA\Bricks\Icon_Library_Service::render( 'kiwe_icon_cart' ), 'Malformed packaged artwork fails closed' );
}
$sprite_property->setValue( null, null );
$cache_property->setValue( null, [] );

$native = [ 'status' => 'not-run', 'reason' => 'Set BRICKS_SOURCE to a local Bricks theme source directory; native lab/browser acceptance is separate.' ];
$bricks_source = getenv( 'BRICKS_SOURCE' );
if ( is_string( $bricks_source ) && '' !== $bricks_source ) {
	require $bricks_source . '/includes/helpers.php';
	require $bricks_source . '/includes/elements/base.php';
	foreach ( [ 'cart', 'notifications', 'wishlist', 'bookmark', 'app_ios', 'app_android' ] as $name ) {
		$rendered = Bricks\Element::render_icon(
			[ 'library' => 'dynamicData', 'dynamicData' => '{kiwe_icon_' . $name . '}' ],
			[ 'id' => 'native-' . $name, 'class' => [ 'brxe-icon', 'authored-icon' ], 'aria-hidden' => 'true' ]
		);
		check( str_contains( $rendered, 'id="native-' . $name . '"' ) && str_contains( $rendered, 'class="brxe-icon authored-icon"' ) && Bricks\Helpers::is_valid_svg( $rendered ), 'Actual native Icon renderer retains authored attributes: ' . $name );
	}
	$native = [ 'status' => 'passed', 'scope' => 'Actual supplied Bricks Element::render_icon/render_svg, with WordPress dynamic-data and escaping adapters stubbed; not browser/save acceptance.' ];
}

echo json_encode( [ 'passed' => true, 'checks' => $checks, 'native' => $native ] ) . PHP_EOL;
