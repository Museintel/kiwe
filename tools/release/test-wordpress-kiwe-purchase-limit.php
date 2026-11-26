<?php
declare(strict_types=1);

use DSA\Access\Commerce_App_Service;
use DSA\Commerce\MCP_Store_Authoring;
use DSA\Commerce\Purchase_Limit_Service as Limits;
use DSA\Commerce\Subscription_Product_Service as Subscriptions;
use DSA\Commerce\Subscription_Renewal_Orders as Renewals;

if (PHP_SAPI !== 'cli') exit(1);
$lab = getenv('KIWE_CHECKOUT_LAB');
if (!$lab || !is_file($lab . '/lab.json')) exit(2);
$source = getenv('KIWE_SOURCE_MU');
if ($source && is_file($source . '/dsa.php')) define('WPMU_PLUGIN_DIR', $source);
require $lab . '/site/wp-load.php';
if (wp_get_environment_type() !== 'local' || !str_starts_with(home_url('/'), 'http://127.0.0.1:')
    || DB_NAME !== 'kiwe_checkout_lab' || get_template() !== 'bricks') exit(3);

$verify = static function (bool $valid, string $description): void {
    if (!$valid) throw new RuntimeException($description);
};
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn() => new WP_Error('test_offline', 'Provider requests are disabled in this fixture.'));
$owner = get_user_by('login', 'checkout-owner');
$verify($owner && DSA\Apps\Store_App_Service::enabled(), 'Store fixture must be enabled.');
$slug = 'purchase-limit-' . bin2hex(random_bytes(5));
$users = [];
$products = [];
$orders = [];
try {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (['buyer', 'other'] as $name) {
        $id = wp_insert_user(['user_login'=>$slug . '-' . $name, 'user_pass'=>wp_generate_password(16),
            'user_email'=>$slug . '-' . $name . '@example.invalid', 'role'=>'customer']);
        $verify(is_int($id) && $id > 0, 'Create the isolated customer.');
        $users[] = $id;
    }
    [$buyer, $other] = $users;
    wp_set_current_user($owner->ID);
    $editor = new Commerce_App_Service();
    $save = static function (string $type, string $name, bool $limit) use ($editor, $verify, &$products): WC_Product {
        $record = $editor->create_product_record(['name'=>$name, 'type'=>$type, 'oncePerAccount'=>$limit]);
        if (is_wp_error($record)) throw new RuntimeException('Product draft: ' . $record->get_error_message());
        $verify(is_array($record) && $record['oncePerAccount'] === $limit, 'Draft authoring saves the limit.');
        $id = $record['id'];
        $products[] = $id;
        $result = $editor->save_product_record($id, ['expected'=>$record['expected'], 'name'=>$name,
            'price'=>$type === 'simple' ? '100' : '', 'status'=>'publish', 'categories'=>[(int) get_option('default_product_cat')],
            'oncePerAccount'=>$limit]);
        if (is_wp_error($result)) throw new RuntimeException('Product editor: ' . $result->get_error_message());
        $verify(is_array($result) && $result['oncePerAccount'] === $limit, 'Product editor publishes with the limit.');
        return wc_get_product($id);
    };
    $simple = $save('simple', $slug . ' simple', true);
    $ordinary = $save('simple', $slug . ' ordinary', false);
    $variable = $save('variable', $slug . ' variable', true);
    $recurring = $save('variable', $slug . ' subscriptions', false);
    $paid = $save('variable', $slug . ' paid subscription', false);
    $make_variation = static function (WC_Product $parent, ?array $terms = null) use (&$products): WC_Product_Variation {
        $choice = new WC_Product_Variation();
        $choice->set_parent_id($parent->get_id());
        $choice->set_status('publish');
        $choice->set_regular_price('100');
        $choice->set_virtual(true);
        if ($terms) Subscriptions::write($choice, $terms);
        $products[] = $choice->save();
        return $choice;
    };
    $choice = $make_variation($variable);
    $alternate = $make_variation($variable);
    $member = $make_variation($recurring, ['membershipGroup'=>$slug, 'renewalMinor'=>10000, 'intervalMonths'=>3, 'firstTermMonths'=>3]);
    $paid_member = $make_variation($paid, ['membershipGroup'=>$slug . '-paid', 'renewalMinor'=>10000, 'intervalMonths'=>3, 'firstTermMonths'=>3]);
    $verify(in_array('oncePerAccount', MCP_Store_Authoring::fields('create'), true)
        && in_array('oncePerAccount', MCP_Store_Authoring::fields('publish'), true), 'MCP exposes the same limit.');
    $buy = static function (int $customer, array $items, string $status) use (&$orders, $verify): WC_Order {
        $order = wc_create_order(['customer_id'=>$customer, 'created_via'=>'kiwe-purchase-limit-fixture']);
        $verify($order instanceof WC_Order, 'Create an isolated order.');
        $orders[] = $order->get_id();
        foreach ($items as $item) $order->add_product($item, 1);
        $order->set_status($status);
        $order->calculate_totals();
        $order->save();
        return $order;
    };
    $buy($buyer, [$simple, $ordinary, $choice], 'completed');
    $buy($other, [$simple], 'failed');
    $agreement = $buy($buyer, [$member], 'on-hold');
    $agreement->set_payment_method(Renewals::GATEWAY);
    $agreement->update_meta_data(Renewals::AGREEMENT, ['customerId'=>$buyer, 'providerStatus'=>'created',
        'lines'=>[['productId'=>$recurring->get_id(), 'variationId'=>$member->get_id(), 'membershipGroup'=>$slug]]]);
    $agreement->save();
    $paid_order = $buy($buyer, [$paid_member], 'completed');
    $paid_order->set_payment_method(Renewals::GATEWAY);
    $paid_order->set_transaction_id('pay_' . $slug);
    $paid_order->set_date_paid(time() - MINUTE_IN_SECONDS);
    $paid_order->update_meta_data(Renewals::AGREEMENT, ['customerId'=>$buyer, 'providerStatus'=>'authenticated',
        'lines'=>[['productId'=>$paid->get_id(), 'variationId'=>$paid_member->get_id(), 'membershipGroup'=>$slug . '-paid', 'name'=>'Paid membership']]]);
    $paid_order->update_meta_data(Renewals::CHARGE, ['paymentId'=>'pay_' . $slug, 'amountMinor'=>10000,
        'refundedMinor'=>0, 'startsAt'=>time() - MINUTE_IN_SECONDS, 'endsAt'=>time() + DAY_IN_SECONDS]);
    $paid_order->save();
    $verify(Renewals::paid_period($paid_order)['active'] ?? false, 'The paid fixture has current access.');

    if (!WC()->session) WC()->initialize_session();
    if (!WC()->cart) WC()->initialize_cart();
    $ids = [$simple->get_id(), $ordinary->get_id(), $variable->get_id(), $recurring->get_id(), $paid->get_id()];
    $results = static function (string $billing) use ($ids): array {
        $vars = apply_filters('bricks/posts/query_vars', ['post_type'=>['product'], 'post__in'=>$ids,
            'orderby'=>'post__in', 'post_status'=>'publish', 'fields'=>'ids', 'posts_per_page'=>9], ['query'=>['kiweProductBilling'=>$billing]], 'fixture', 'block');
        return array_map('intval', (new WP_Query($vars))->posts);
    };
    wp_set_current_user(0);
    $verify($results('subscriptions') === array_slice($ids, 3) && $results('one_time') === array_slice($ids, 0, 3), 'Guest loops distinguish native Woo product families.');
    $verify(!apply_filters('woocommerce_add_to_cart_validation', true, $simple->get_id(), 1), 'Limit requires an account at cart entry.');
    wc_clear_notices();
    wp_set_current_user($other);
    $verify($results('both') === $ids, 'Another account keeps all entries.');
    $verify(apply_filters('woocommerce_add_to_cart_validation', true, $simple->get_id(), 1), 'A failed order does not permanently consume the limit.');
    $verify(!apply_filters('woocommerce_add_to_cart_validation', true, $simple->get_id(), 2), 'Restrict the quantity before checkout.');
    wc_clear_notices();
    $cart = WC()->cart;
    $key = $cart->add_to_cart($simple->get_id(), 1);
    $verify(is_string($key) && $key !== '', 'An eligible shopper can add one unit.');
    $verify(!apply_filters('woocommerce_update_cart_validation', true, $key, $cart->get_cart_item($key), 2), 'Cart DSA and Woo quantity changes share the limit.');
    wc_clear_notices();
    $guard = new Limits();
    $errors = new WP_Error();
    $guard->validate_checkout([], $errors);
    $verify(!$errors->has_errors(), 'The first checkout can reserve the limited product.');
    $reserved = $buy($other, [$simple], 'pending');
    $guard->record_classic_reservation($reserved);
    $reserved->save_meta_data();
    $guard->release_lock();
    $guard->validate_checkout([], $errors);
    $verify($errors->has_errors(), 'A previously reserved native pending order blocks duplicate checkout.');
    $cart->empty_cart();
    wp_set_current_user($buyer);
    $remaining = $results('both');
    if ($remaining !== [$ordinary->get_id(), $recurring->get_id()]) {
        throw new RuntimeException('Unexpected filtered product indexes: ' . json_encode([
            'remaining'=>array_keys(array_intersect($ids, $remaining)),
            'purchased'=>array_keys(array_intersect($ids, (new Limits())->purchased($buyer))),
            'blocked'=>array_keys(array_intersect($ids, array_column(DSA\Commerce\Subscription_Quote_Service::blocked_membership_lines($buyer), 'productId'))),
        ]));
    }
    $verify(!apply_filters('woocommerce_add_to_cart_validation', true, $variable->get_id(), 1, $alternate->get_id()), 'Buying one variation consumes the parent limit.');
    wc_clear_notices();
    $verify($results('subscriptions') === [$recurring->get_id()], 'An unpaid approval stays visible and a paid membership is excluded.');
    $verify(!apply_filters('woocommerce_add_to_cart_validation', true, $recurring->get_id(), 1, $member->get_id()), 'An unpaid approval blocks a second checkout.');
    wc_clear_notices();
    $verify(!apply_filters('woocommerce_add_to_cart_validation', true, $paid->get_id(), 1, $paid_member->get_id()), 'An existing paid membership blocks repeat addition.');
    wc_clear_notices();
    $verify(apply_filters('woocommerce_add_to_cart_validation', true, $ordinary->get_id(), 1), 'An ordinary product remains repurchasable.');
    $verify(is_wp_error($editor->product_record($ordinary->get_id())), 'A customer cannot read the product editor.');
    echo json_encode(['passed'=>true, 'store'=>WC_VERSION, 'core'=>DSA_VERSION, 'bricks'=>wp_get_theme('bricks')->get('Version'),
        'coverage'=>['draft', 'editor', 'MCP fields', 'guest', 'failed retry', 'quantity', 'checkout reservation', 'variation family', 'active membership', 'pending approval', 'Bricks filtering', 'product permissions']]), PHP_EOL;
} finally {
    foreach ($orders as $id) if ($order = wc_get_order($id)) $order->delete(true);
    foreach (array_reverse($products) as $id) if ($product = wc_get_product($id)) $product->delete(true);
    foreach ($users as $id) wp_delete_user($id);
}
