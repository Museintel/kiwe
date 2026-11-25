<?php

declare(strict_types=1);

// Uses real WooCommerce orders and an offline provider. Require this script before WordPress
// in the isolated PHP server router, then run test-wordpress-kiwe-shopper-checkout.mjs.
use DSA\Access\Team_Workspace_Service as Team;
use DSA\Commerce\Subscription_Billing_Service as Billing;
use DSA\Commerce\Subscription_Checkout_Service as Checkout;
use DSA\Commerce\Subscription_Product_Service as Products;
use DSA\Commerce\Subscription_Renewal_Orders as Ledger;

$root = getenv('KIWE_CHECKOUT_LAB');
if (!$root || !is_file($root . '/lab.json') || !in_array(PHP_SAPI, ['cli', 'cli-server'], true)) exit(1);
if (PHP_SAPI === 'cli-server' && ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:64549') exit(1);
$state_file = $root . '/shopper-fixture-state.json';

define('KIWE_RAZORPAY_SUBSCRIPTIONS_TEST', true);
define('KIWE_RAZORPAY_SUBSCRIPTIONS_ACCOUNT', 'acc_OfflineShopperFixture');
define('KIWE_RAZORPAY_SUBSCRIPTIONS_WEBHOOK_SECRET', 'wordpress-shopper-fixture-only');
define('KIWE_RAZORPAY_CHECKOUT_TEST_PLANS', ['70000:1'=>'plan_OfflineShopperFixture']);

function fixture_state(): array {
    global $state_file;
    return is_file($state_file) ? json_decode(file_get_contents($state_file), true, flags: JSON_THROW_ON_ERROR) : [];
}

function fixture_save(array $state): void {
    global $state_file;
    if (false === file_put_contents($state_file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX)) {
        throw new RuntimeException('The isolated payment fixture could not be saved.');
    }
}

function fixture_response(array $entity): array {
    return ['body'=>wp_json_encode($entity), 'response'=>['code'=>200], 'headers'=>[]];
}

function fixture_provider(mixed $pre, array $args, string $url): mixed {
    if ('local' !== wp_get_environment_type() || DB_NAME !== 'kiwe_checkout_lab') exit(2);
    $base = 'https://api.razorpay.com/v1/';
    if (!str_starts_with($url, $base)) return new WP_Error('fixture_offline', 'External network is disabled in this fixture.');
    $path = substr($url, strlen($base));
    $state = fixture_state();
    if (($args['method'] ?? '') === 'GET') {
        if ($path === 'plans/plan_OfflineShopperFixture') {
            $minor = !empty($state['wrongPlan']) ? 70001 : 70000;
            return fixture_response(['id'=>'plan_OfflineShopperFixture', 'period'=>'monthly', 'interval'=>1,
                'item'=>['amount'=>$minor, 'currency'=>'INR']]);
        }
        if (isset($state['entities'][$path])) return fixture_response($state['entities'][$path]);
    }
    if ($path === 'subscriptions' && ($args['method'] ?? '') === 'POST' && $state) {
        $submitted = json_decode($args['body'], true, flags: JSON_THROW_ON_ERROR);
        $order = wc_get_order((int) $submitted['notes']['kiwe_order']);
        $index = count($state['requests']) + 1;
        $subscription = [
            'id'=>'sub_OfflineFixture' . $index, 'plan_id'=>$submitted['plan_id'], 'quantity'=>$submitted['quantity'],
            'notes'=>$submitted['notes'], 'start_at'=>$submitted['start_at'], 'total_count'=>$submitted['total_count'],
            'status'=>'created', 'short_url'=>'https://rzp.io/rzp/OfflineFixture' . $index,
        ];
        $state['entities']['subscriptions/' . $subscription['id']] = $subscription;
        $state['requests'][] = [
            'id'=>$subscription['id'], 'orderId'=>$order->get_id(), 'upfront'=>$submitted['addons'][0]['item']['amount'],
            'cycles'=>$submitted['total_count'], 'notified'=>$submitted['customer_notify'],
            'savedBeforeProvider'=>'on-hold' === $order->get_status() && is_array($order->get_meta(Ledger::AGREEMENT)),
        ];
        fixture_save($state);
        if ($order->get_customer_id() === $state['users']['timeout']) {
            return new WP_Error('fixture_lost_response', 'Synthetic timeout after accepting the request.');
        }
        return fixture_response($subscription);
    }
    return new WP_Error('fixture_offline', 'Unexpected Razorpay fixture request.');
}

function fixture_hook(string $hook, callable $callback, int $args = 1): void {
    $GLOBALS['wp_filter'][$hook][10][] = ['function'=>$callback, 'accepted_args'=>$args];
}

fixture_hook('pre_option_woocommerce_razorpay_settings', static fn () => [
    'enabled'=>'yes', 'key_id'=>'rzp_test_OfflineFixture', 'key_secret'=>'offline-fixture-only',
]);
fixture_hook('pre_option_woocommerce_manage_stock', static fn () => 'yes');
if (!empty(fixture_state()['storeOff'])) fixture_hook('pre_option_kiwe_store_app_enabled_v1', static fn () => '0');
fixture_hook('pre_wp_mail', static fn () => true);
fixture_hook('pre_http_request', 'fixture_provider', 3);
if (PHP_SAPI === 'cli-server') return;
require $root . '/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ('local' !== wp_get_environment_type() || DB_NAME !== 'kiwe_checkout_lab' || home_url('/') !== 'http://127.0.0.1:64549/') exit(2);

function fixture_check(mixed $ok, string $description): void {
    if (!$ok) throw new RuntimeException($description);
}

function fixture_publish(\WC_Product $product, ?array $terms = null): int {
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_virtual(true);
    $product->set_tax_status('none');
    if ($terms) Products::write($product, $terms);
    return $product->save();
}

function fixture_payment(array $state, array $request, string $mode): array {
    $id = $request['orderId'];
    $order = wc_get_order($id);
    $agreement = $order->get_meta(Ledger::AGREEMENT);
    $payment = 'pay_OfflineFixture' . $id;
    $invoice = 'inv_OfflineFixture' . $id;
    $provider_order = 'order_OfflineFixture' . $id;
    $state['entities']['payments/' . $payment] = [
        'id'=>$payment, 'invoice_id'=>$invoice, 'order_id'=>$provider_order, 'currency'=>'INR',
        'amount'=>$request['upfront'], 'amount_refunded'=>0, 'status'=>$mode,
    ];
    $state['entities']['invoices/' . $invoice] = [
        'id'=>$invoice, 'subscription_id'=>$request['id'], 'payment_id'=>$payment, 'order_id'=>$provider_order,
        'status'=>$mode === 'captured' ? 'paid' : 'issued', 'amount'=>$request['upfront'], 'amount_paid'=>$request['upfront'],
        'currency'=>'INR', 'paid_at'=>$agreement['createdAt'], 'billing_start'=>null, 'billing_end'=>null,
    ];
    fixture_save($state);
    $body = wp_json_encode(['account_id'=>KIWE_RAZORPAY_SUBSCRIPTIONS_ACCOUNT, 'event'=>'payment.captured',
        'payload'=>['payment'=>['entity'=>['id'=>$payment]]]]);
    $webhook = new WP_REST_Request('POST', '/dsa/v1' . Billing::ROUTE);
    $webhook->set_header('x-razorpay-signature', hash_hmac('sha256', $body, KIWE_RAZORPAY_SUBSCRIPTIONS_WEBHOOK_SECRET));
    $webhook->set_header('content-type', 'application/json');
    $webhook->set_body($body);
    $result = rest_do_request($webhook);
    return ['response'=>$result->get_status(), 'order'=>wc_get_order($id)->get_status()];
}

$state = fixture_state();
$phase = $argv[1] ?? '';
if ($phase === 'seed') {
    fixture_check(!$state && Checkout::enabled() && get_woocommerce_currency() === 'INR', 'The isolated INR checkout requires the Store app, fixture settings and an empty run.');
    $tag = strtolower(wp_generate_password(10, false, false));
    $users = [];
    $cookies = [];
    $nonces = [];
    foreach (['shopper', 'timeout', 'other', 'suspended'] as $name) {
        $id = wp_insert_user(['user_login'=>$name . '_' . $tag, 'user_pass'=>wp_generate_password(32), 'role'=>'customer',
            'user_email'=>$name . '.' . $tag . '@example.invalid']);
        fixture_check(is_int($id), 'Could not create the local checkout user.');
        $users[$name] = $id;
        if ('suspended' === $name) update_user_meta($id, Team::SUSPENDED_META, '1');
        $expires = time() + HOUR_IN_SECONDS;
        $token = WP_Session_Tokens::get_instance($id)->create($expires);
        $cookies[$name] = ['name'=>LOGGED_IN_COOKIE, 'value'=>wp_generate_auth_cookie($id, $expires, 'logged_in', $token),
            'domain'=>'127.0.0.1', 'path'=>'/', 'httpOnly'=>true];
        wp_set_current_user($id);
        $_COOKIE[LOGGED_IN_COOKIE] = $cookies[$name]['value'];
        $nonces[$name] = wp_create_nonce('wp_rest');
    }
    unset($_COOKIE[LOGGED_IN_COOKIE]);
    wp_set_current_user(0);
    $terms = ['renewalMinor'=>70000, 'intervalMonths'=>1, 'firstTermMonths'=>12];
    $member = new WC_Product_Simple();
    $member->set_name('Checkout proof membership');
    $member->set_regular_price('8400');
    $product_id = fixture_publish($member, $terms);
    $simple = new WC_Product_Simple();
    $simple->set_name('Checkout proof ordinary purchase');
    $simple->set_regular_price('199');
    $simple->set_manage_stock(true);
    $simple->set_stock_quantity(5);
    $simple_id = fixture_publish($simple);
    $variable = new WC_Product_Variable();
    $variable->set_name('Checkout proof variable');
    $axis = new WC_Product_Attribute();
    $axis->set_name('Size');
    $axis->set_options(['Small', 'Large']);
    $axis->set_variation(true);
    $variable->set_attributes([$axis]);
    $variable_id = fixture_publish($variable);
    $variant = new WC_Product_Variation();
    $variant->set_parent_id($variable_id);
    $variant->set_attributes(['size'=>'small']);
    $variant->set_regular_price('149');
    $variant_id = fixture_publish($variant);
    $member_variant = new WC_Product_Variation();
    $member_variant->set_parent_id($variable_id);
    $member_variant->set_attributes(['size'=>'large']);
    $member_variant->set_regular_price('8400');
    $member_variant_id = fixture_publish($member_variant, $terms);
    WC_Product_Variable::sync($variable_id);
    $coupon = new WC_Coupon();
    $coupon->set_code('shopper-proof-' . $tag);
    $coupon->set_amount('10');
    $coupon->set_discount_type('fixed_cart');
    $coupon_id = $coupon->save();
    $order = wc_create_order(['status'=>'pending', 'customer_id'=>$users['other'], 'created_via'=>'kiwe-shopper-fixture']);
    fixture_check($order instanceof WC_Order, 'Could not create the local alternate-pay order.');
    $order->set_currency('INR');
    $order->add_product($member, 1);
    $order->calculate_totals();
    $order->save();
    $one_time = wc_create_order(['status'=>'pending', 'customer_id'=>$users['other'], 'created_via'=>'kiwe-shopper-fixture']);
    fixture_check($one_time instanceof WC_Order, 'Could not create a pending one-time checkout.');
    $one_time->set_currency('INR');
    $one_time->add_product($simple, 1);
    $one_time->calculate_totals();
    $one_time->save();
    $previous_checkout = get_option('woocommerce_checkout_page_id');
    $checkout = wp_insert_post(['post_title'=>'Shopper checkout proof', 'post_name'=>'checkout-proof-' . $tag,
        'post_type'=>'page', 'post_content'=>'[woocommerce_checkout]', 'post_status'=>'publish']);
    fixture_check($checkout, 'Could not create the isolated classic checkout page.');
    update_option('woocommerce_checkout_page_id', $checkout);
    $state = ['tag'=>$tag, 'users'=>$users, 'products'=>[$member_variant_id, $variant_id, $variable_id, $simple_id, $product_id],
        'coupon'=>$coupon_id, 'requests'=>[], 'entities'=>[], 'guestEmail'=>'guest.' . $tag . '@example.invalid',
        'checkout'=>$checkout, 'previousCheckout'=>$previous_checkout, 'simple'=>$simple_id];
    fixture_save($state);
    echo wp_json_encode(['origin'=>home_url('/'), 'checkout'=>wc_get_checkout_url(), 'products'=>compact('product_id', 'simple_id', 'variable_id', 'variant_id','member_variant_id'),
        'coupon'=>$coupon->get_code(), 'users'=>$cookies, 'nonces'=>$nonces, 'guestEmail'=>$state['guestEmail'],
        'orderPay'=>$order->get_checkout_payment_url(), 'ordinaryOrderPay'=>$one_time->get_checkout_payment_url()]);
} elseif ($phase === 'wrong-plan' || $phase === 'correct-plan') {
    fixture_check($state, 'The checkout is missing its run state.');
    $state['wrongPlan'] = $phase === 'wrong-plan';
    fixture_save($state);
    echo '{}';
} elseif ($phase === 'store-off' || $phase === 'store-on') {
    $state['storeOff'] = $phase === 'store-off';
    fixture_save($state);
    echo '{}';
} elseif ($phase === 'stock') {
    echo wp_json_encode(wc_get_product($state['simple'])->get_stock_quantity());
} elseif ($phase === 'inspect') {
    $attempts = [];
    foreach ($state['requests'] as $request) {
        $order = wc_get_order($request['orderId']);
        $agreement = $order->get_meta(Ledger::AGREEMENT);
        $attempts[] = $request + [
            'customer'=>array_search($order->get_customer_id(), $state['users'], true), 'url'=>$order->get_view_order_url(),
            'firstCharge'=>$agreement['firstChargeMinor'], 'renewal'=>$agreement['renewalMinor'], 'firstTerm'=>$agreement['firstTermMonths'],
            'total'=>$order->get_total(), 'lines'=>count($order->get_items()), 'coupons'=>count($order->get_coupon_codes()),
            'awaiting'=>$order->has_status('on-hold'), 'ownedConsent'=>!empty($agreement['checkoutConsentAt']),
            'known'=>!empty($agreement['subscriptionId']), 'continued'=>!empty($agreement['authorizationUrl']),
            'settled'=>!empty($order->get_meta(Ledger::CHARGE)), 'expiry'=>!!wp_next_scheduled(Billing::EXPIRY_HOOK, [$order->get_id()]),
        ];
    }
    echo wp_json_encode($attempts);
} elseif ($phase === 'authorize' || $phase === 'capture') {
    fixture_check(!empty($state['requests'][0]), 'There is no checkout to reconcile.');
    echo wp_json_encode(fixture_payment($state, $state['requests'][0], $phase === 'capture' ? 'captured' : 'authorized'));
} elseif ($phase === 'expire') {
    fixture_check(!empty($state['requests'][1]), 'There is no timed-out checkout to recover.');
    $request = $state['requests'][1];
    $order = wc_get_order($request['orderId']);
    $owner = get_user_by('login', 'checkout-owner');
    fixture_check($owner && $order, 'There is no local billing operator.');
    wp_set_current_user($owner->ID);
    $review = Billing::review_reconciliation($order->get_id(), $request['id'], false);
    Billing::recover($order->get_id(), $request['id'], $review['nativeExpected']);
    $state['entities']['subscriptions/' . $request['id']]['status'] = 'expired';
    fixture_save($state);
    $agreement = wc_get_order($order->get_id())->get_meta(Ledger::AGREEMENT);
    $agreement['approvalExpiresAt'] = time() - 30;
    $order->update_meta_data(Ledger::AGREEMENT, $agreement);
    $order->save_meta_data();
    do_action(Billing::EXPIRY_HOOK, $order->get_id());
    $after = wc_get_order($order->get_id());
    echo wp_json_encode(['status'=>$after->get_status(), 'remote'=>$after->get_meta(Ledger::AGREEMENT)['providerStatus']]);
} elseif ($phase === 'cleanup') {
    foreach ($state['users'] ?? [] as $id) {
        foreach (wc_get_orders(['customer_id'=>$id, 'limit'=>-1]) as $order) {
            wp_clear_scheduled_hook(Billing::EXPIRY_HOOK, [$order->get_id()]);
            $order->delete(true);
        }
        wp_delete_user($id);
    }
    if ($state) {
        update_option('woocommerce_checkout_page_id', $state['previousCheckout']);
        wp_delete_post($state['checkout'], true);
        foreach (wc_get_orders(['billing_email'=>$state['guestEmail'], 'limit'=>-1]) as $order) $order->delete(true);
        foreach ($state['products'] as $id) wc_get_product($id)?->delete(true);
        (new WC_Coupon($state['coupon']))->delete(true);
        unlink($state_file);
    }
    echo wp_json_encode(['cleaned'=>!fixture_state()]);
} else {
    exit(3);
}
