<?php

declare(strict_types=1);

namespace DSA\Apps {
	// Activate the candidate service in isolation from the lab's installed Kiwe.
	final class Guest_App_Service { public static function enabled(): bool { return true; } }
	final class Store_App_Service { public static function enabled(): bool { return ! empty( $GLOBALS['test_guest_store'] ); } }
}

namespace {
	use DSA\Access\Guest_Contribution_Service as Guest;
	use DSA\Access\Guest_Engine_Service as Engine;
	use DSA\Access\Guest_Referral_Service as Referrals;
	use Automattic\WooCommerce\Utilities\OrderUtil;

	if ( PHP_SAPI !== 'cli' || basename( dirname( $argv[1] ?? '' ) ) !== 'wordpress-checkout-lab' ) exit( 1 );
	$site = $argv[1];
	$hpos = $argv[2] ?? '';
	if ( ! in_array( $hpos, [ 'yes', 'no' ], true ) ) exit( 1 );
	define( 'WPMU_PLUGIN_DIR', $site . '/missing-mu-guest-test' );
	require $site . '/wp-includes/plugin.php';
	add_filter( 'pre_wp_mail', '__return_true' );
	add_filter( 'pre_http_request', static fn () => new WP_Error( 'fixture', 'External requests are disabled.' ) );
	add_filter( 'pre_option_active_plugins', static fn () => [ 'woocommerce/woocommerce.php' ] );
	add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', static fn () => $hpos );
	add_filter( 'pre_option_template', static fn () => 'twentytwentyfive' );
	add_filter( 'pre_option_stylesheet', static fn () => 'twentytwentyfive' );
	require $site . '/wp-load.php';
	if ( 'local' !== wp_get_environment_type() || 'kiwe_checkout_lab' !== DB_NAME ) exit( 2 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	require_once ABSPATH . 'wp-admin/includes/post.php';
	spl_autoload_register( static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'DSA\\' ) ) return;
		$file = dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/' . str_replace( '\\', '/', substr( $class, 4 ) ) . '.php';
		if ( is_file( $file ) ) require_once $file;
	} );

	function proof( bool $ok, string $message ): void {
		if ( ! $ok ) throw new RuntimeException( $message );
	}

	function make_account( string $role ): int {
		$id = wp_insert_user( [ 'user_login'=>'guest-test-' . wp_generate_password( 12, false ),
			'user_pass'=>wp_generate_password( 20 ), 'role'=>$role ] );
		proof( is_int( $id ) && $id > 0, 'Create native test account.' );
		$GLOBALS['test_guests_created'][] = $id;
		update_user_meta( $id, 'pk_verified_at', time() );
		return $id;
	}

	$GLOBALS['test_guest_store'] = true;
	$GLOBALS['test_guests_created'] = [];
	$option = get_option( Guest::SETTINGS_OPTION, null );
	$product = null;
	update_option( Guest::SETTINGS_OPTION, [ 'posts_enabled'=>true, 'products_enabled'=>true, 'referrals_enabled'=>true ] );
	Referrals::register();
	try {
		$manager = make_account( 'administrator' );
		$admin = new WP_User( $manager );
		foreach ( [ 'kiwe_manage_guest_applications', 'edit_shop_orders', 'edit_others_shop_orders', 'edit_private_shop_orders', 'edit_published_shop_orders' ] as $cap ) $admin->add_cap( $cap );
		$partner = make_account( 'customer' );
		$writer = make_account( 'customer' );
		$viewer = make_account( 'customer' );
		wp_set_current_user( $manager );
		$service = new Engine();
		foreach ( [ $partner, $writer ] as $id ) update_user_meta( $id, Guest::META, [ 'status'=>'pending', 'intents'=>[ 'post', 'product', 'referral' ] ] );
		$record = $service->workspace( [ 'view'=>'applications', 'id'=>$partner ] )['items'][0];
		proof( is_wp_error( $service->decide_application( $partner, 'approved', '', 'stale', [ 'referral' ] ) ), 'Stale approval must fail.' );
		$mcp = \DSA\MCP\Guest_Provider::operations();
		$decision = [ 'id'=>$partner, 'decision'=>'approved', 'grants'=>[ 'referral' ], 'expected'=>$record['expected'] ];
		$write = $mcp['mcp.guest.decide'];
		proof( $write['permission']( $decision ) && 'required' === $write['approval'], 'MCP uses the manager’s permission and requires reviewed write approval.' );
		$proposal = $write['prepare']( $decision );
		proof( $proposal['needsApproval'] && 'pending' === Guest::application( $partner )['status'], 'Preparing an MCP review does not grant permissions.' );
		$approved = json_decode( $write['execute']( $decision, $proposal['nativeExpected'] )['resultJson'], true, flags:JSON_THROW_ON_ERROR );
		proof( is_array( $approved ) && $approved['grants'] === [ 'referral' ] && get_userdata( $partner )->roles === [ 'customer' ],
			'Referral-only approval must leave the customer role and grant no contribution workspace.' );
		$approvedWriter = $service->decide_application( $writer, 'approved', '', null, [ 'product', 'referral' ] );
		proof( in_array( 'contributor', $approvedWriter['roles'], true ) && ! in_array( 'post', $approvedWriter['grants'], true ),
			'Product proposals and referrals may be granted together without article submission.' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Guest product fixture' );
		$product->set_regular_price( '200' );
		$product->set_status( 'publish' );
		$product->save();
		wp_update_post( [ 'ID'=>$product->get_id(), 'post_author'=>$writer ] );
		update_post_meta( $product->get_id(), '_kiwe_guest_submission', [ 'createdAt'=>gmdate( 'c' ) ] );
		$subject = [ 'id'=>$product->get_id(), 'url'=>get_permalink( $product->get_id() ) ];
		$link = Referrals::url( $partner, $subject );
		proof( is_string( $link ) && null === Referrals::url( $viewer, $subject ), 'Only an approved referrer can create a share link.' );
		parse_str( parse_url( $link, PHP_URL_QUERY ), $params );
		$cookie = new ReflectionMethod( Referrals::class, 'consent_cookie' );
		$signed = $cookie->invoke( null, $params['kiwe_ref'], time() + 3600 );
		$_COOKIE['kiwe_guest_referral'] = $signed . '0';
		Referrals::attribute_registration( $viewer );
		$meta = '_kiwe_guest_referrer_' . get_current_blog_id();
		proof( ! get_user_meta( $viewer, $meta, true ), 'A changed consent cookie cannot register a referral.' );
		$_COOKIE['kiwe_guest_referral'] = $signed;
		Referrals::attribute_registration( $partner );
		proof( ! get_user_meta( $partner, $meta, true ), 'A referral cannot credit its own account.' );
		wp_set_current_user( 0 );
		$buyer = make_account( 'customer' );
		unset( $_COOKIE['kiwe_guest_referral'] );
		proof( (int) get_user_meta( $buyer, $meta, true ) === $partner, 'A native signup remembers the consented referrer after the cookie ends.' );

		{
			proof( OrderUtil::custom_orders_table_usage_is_enabled() === ( 'yes' === $hpos ), 'Use the requested native Woo order store.' );
			$order = wc_create_order( [ 'customer_id'=>$buyer ] );
			proof( $order instanceof WC_Order, 'Create a native fixture order.' );
			$refund = null;
			try {
				$order->add_product( $product );
				$order->set_currency( 'INR' );
				$order->calculate_totals();
				if ( 'yes' === $hpos ) do_action( 'woocommerce_store_api_checkout_update_order_meta', $order );
				else do_action( 'woocommerce_checkout_create_order', $order, [] );
				$order->save();
				wp_set_current_user( $manager );
				proof( [] === Referrals::report( $partner )['orders'], 'Unpaid checkout cannot appear in Guest analytics.' );
				$order->set_date_paid( time() );
				$order->set_status( 'processing' );
				$order->save();
				$analytics = $mcp['mcp.guest.analytics'];
				$own = json_decode( $analytics['execute']( [ 'id'=>$partner ] )['resultJson'], true, flags:JSON_THROW_ON_ERROR );
				proof( $own['registeredAccounts'] === 1 && $own['orders'][0]['id'] === $order->get_id()
					&& $own['orders'][0]['referred'] && 'INR' === $own['orders'][0]['currency'], 'The native order query credits the right referral in each Woo storage mode.' );
				$productProof = Referrals::report( $writer )['orders'][0];
				proof( ! $productProof['referred'] && null === $productProof['total'] && $productProof['products'][0]['name'] === 'Guest product fixture',
					'The product author report reads only the author’s contributed line.' );
				$line = array_key_first( $order->get_items() );
				$refund = wc_create_refund( [ 'order_id'=>$order->get_id(), 'amount'=>10, 'line_items'=>[ $line=>[ 'qty'=>0, 'refund_total'=>10, 'refund_tax'=>[] ] ] ] );
				proof( $refund instanceof WC_Order_Refund && (float) Referrals::report( $partner )['orders'][0]['refunded'] === 10.0
					&& (float) Referrals::report( $writer )['orders'][0]['products'][0]['refunded'] === 10.0, 'Reports separate Woo refund amounts for the order and its attributed product.' );
				$deny = static fn ( array $granted, array $required, array $args ): array =>
					'edit_shop_order' === ( $args[0] ?? '' ) && (int) ( $args[2] ?? 0 ) === $order->get_id() ? [] : $granted;
				add_filter( 'user_has_cap', $deny, PHP_INT_MAX, 3 );
				proof( [] === Referrals::report( $partner )['orders'], 'The report respects a denial on a specific WooCommerce order.' );
				remove_filter( 'user_has_cap', $deny, PHP_INT_MAX );
				wp_set_current_user( $viewer );
				proof( null === Referrals::report( $partner ) && ! $analytics['permission']( [ 'id'=>$partner ] ), 'A buyer cannot read other Guest analytics in WordPress or MCP.' );
			} finally {
				if ( $refund instanceof WC_Order_Refund ) $refund->delete( true );
				$order->delete( true );
			}
		}
		wp_set_current_user( $manager );
		$revoked = $service->decide_application( $partner, 'revoked', '', $approved['expected'], [] );
		proof( 'revoked' === $revoked['status'] && null === Referrals::url( $partner, $subject ), 'Revocation immediately disables old referral credentials.' );
		$_COOKIE['kiwe_guest_referral'] = $signed;
		Referrals::attribute_registration( $viewer );
		proof( ! get_user_meta( $viewer, $meta, true ), 'A previously consented cookie stops crediting future signups after revocation.' );
		$GLOBALS['test_guest_store'] = false;
		proof( ! Referrals::report( $partner )['ordersAvailable'], 'The account report still works with Store off.' );
		echo "WordPress Guest approval, signup and Woo order attribution passed with HPOS {$hpos}.\n";
	} finally {
		unset( $_COOKIE['kiwe_guest_referral'] );
		if ( null === $option ) delete_option( Guest::SETTINGS_OPTION );
		else update_option( Guest::SETTINGS_OPTION, $option );
		if ( $product instanceof WC_Product ) $product->delete( true );
		foreach ( array_reverse( $GLOBALS['test_guests_created'] ) as $id ) wp_delete_user( $id );
	}
}
