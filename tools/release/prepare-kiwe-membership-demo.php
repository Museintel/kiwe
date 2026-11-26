<?php
/** Seed the private membership walkthrough without creating orders or talking to Razorpay. */
declare(strict_types=1);

use DSA\Apps\Store_App_Service as Store;
use DSA\Commerce\Subscription_Product_Service as Terms;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit(1);
$host = wp_parse_url( home_url(), PHP_URL_HOST );
$path = wp_parse_url( home_url(), PHP_URL_PATH );
$loopback = wp_get_environment_type() === 'local' && '127.0.0.1' === $host && 'kiwe_checkout_lab' === DB_NAME;
$plum = wp_get_environment_type() === 'staging' && 'plum-giraffe-539524.hostingersite.com' === $host
	&& preg_match( '~^/kiwe-membership-rehearsal-[a-f0-9]{12}$~D', $path ?? '' )
	&& preg_match( '/^k502_[a-f0-9]{12}_$/D', $GLOBALS['wpdb']->prefix );
if ( ( ! $loopback && ! $plum ) || ! current_user_can( 'manage_woocommerce' )
	|| ! Store::enabled() || ! Store::supports_memberships() || 'INR' !== get_woocommerce_currency() ) {
	WP_CLI::error( 'Use the isolated INR WooCommerce membership rehearsal as its administrator.' );
}

$existing = static function ( string $sku, string $type, int $parent = 0 ): ?WC_Product {
	$id = wc_get_product_id_by_sku( $sku );
	if ( ! $id ) return null;
	$product = wc_get_product( $id );
	if ( ! $product || $type !== $product->get_type() || $parent !== $product->get_parent_id()
		|| 'membership-demo-v1' !== $product->get_meta( '_kiwe_demo_fixture' ) ) {
		WP_CLI::error( 'A rehearsal SKU belongs to an unrelated product.' );
	}
	return $product;
};
$variation_specs = [ 3=>300, 6=>540, 12=>960 ];
$parent = $existing( 'kiwe-demo-cardio', 'variable' ) ?? new WC_Product_Variable();
$parent->set_name( 'Demo Cardio Membership' );
$parent->set_description( 'Test catalog. Choose a 3, 6 or 12 month term. One Cardio plan per member.' );
$parent->set_sku( 'kiwe-demo-cardio' );
$parent->set_status( 'publish' );
$parent->update_meta_data( '_kiwe_demo_fixture', 'membership-demo-v1' );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Term' );
$attribute->set_options( array_map( static fn ( $months ) => $months . ' months', array_keys( $variation_specs ) ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$parent->set_attributes( [ $attribute ] );
$parent->save();

$ids = [];
$configure = static function ( WC_Product $product, string $sku, int $price, int $months, string $group ): int {
	$product->set_sku( $sku );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product->set_tax_status( 'none' );
	$product->set_sold_individually( true );
	$product->set_regular_price( (string) $price );
	$product->set_price( (string) $price );
	$product->update_meta_data( '_kiwe_demo_fixture', 'membership-demo-v1' );
	Terms::write( $product, [ 'renewalMinor'=>$price * 100, 'intervalMonths'=>$months, 'firstTermMonths'=>$months, 'membershipGroup'=>$group ] );
	return $product->save();
};
foreach ( $variation_specs as $months=>$price ) {
	$sku = 'kiwe-demo-cardio-' . $months;
	$variation = $existing( $sku, 'variation', $parent->get_id() ) ?? new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_attributes( [ 'term'=>$months . ' months' ] );
	$ids[ $months ] = $configure( $variation, $sku, $price, $months, 'demo-cardio' );
}
WC_Product_Variable::sync( $parent );

$alternative = $existing( 'kiwe-demo-fitness', 'simple' ) ?? new WC_Product_Simple();
$alternative->set_name( 'Demo Fitness Membership' );
$alternative->set_description( 'Test catalog. An alternative to Cardio. Choose either Fitness or Cardio for this membership group.' );
$alternative_id = $configure( $alternative, 'kiwe-demo-fitness', 360, 3, 'demo-cardio' );

$swim = $existing( 'kiwe-demo-swim', 'simple' ) ?? new WC_Product_Simple();
$swim->set_name( 'Demo Swimming Membership' );
$swim->set_description( 'Test catalog. Add Swimming alongside Cardio or Fitness. Use a separate checkout for independent billing controls.' );
$swim_id = $configure( $swim, 'kiwe-demo-swim', 150, 3, 'demo-swim' );

echo wp_json_encode( [
	'demo'=>true, 'cardio'=>$parent->get_id(), 'terms'=>$ids, 'fitness'=>$alternative_id, 'swimming'=>$swim_id,
	'testPlanKeys'=>[ '30000:3','54000:6','96000:12','36000:3','15000:3','45000:3','51000:3' ],
] ), PHP_EOL;
