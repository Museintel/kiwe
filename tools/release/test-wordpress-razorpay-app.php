<?php

declare(strict_types=1);

// Run against a disposable, seeded WooCommerce lab with the official Razorpay ZIP installed.
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('KIWE_CHECKOUT_LAB');
if (!is_string($root) || !is_file($root . '/lab.json') || !is_file($root . '/fixture-inputs.json')) {
    throw new RuntimeException('A dedicated checkout fixture is required.');
}
require $root . '/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use DSA\Apps\Razorpay_App_Service as App;
use DSA\Apps\Store_App_Service;
use DSA\Commerce\MCP_Store_Provider;

if (wp_get_environment_type() !== 'local' || !str_starts_with(home_url('/'), 'http://127.0.0.1:')
    || DB_NAME !== 'kiwe_checkout_lab' || !is_readable(WP_PLUGIN_DIR . '/' . App::PLUGIN)) {
    throw new RuntimeException('The Razorpay proof requires the isolated loopback WordPress fixture.');
}

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$admin = get_user_by('login', 'checkout-owner');
$check($admin instanceof WP_User && user_can($admin, 'manage_woocommerce'), 'The native WooCommerce manager is ready.');
wp_set_current_user($admin->ID);
$check(App::allowed() && Store_App_Service::enabled(), 'The WooCommerce manager can read the Razorpay App.');

$plugins = get_option('active_plugins', []);
$option = get_option('woocommerce_razorpay_settings', []);
$testKey = 'rzp_test_KiweFixture';
$testSecret = 'fixture-secret-for-visibility-check';
$staff = 0;
try {
    update_option('active_plugins', array_values(array_diff($plugins, [App::PLUGIN])));
    $state = App::status();
    $check($state['gatewayInstalled'] && !$state['gatewayActive'] && !$state['gatewayEnabled'], 'An installed inactive plugin stays inactive.');
    $check(App::card()['status'] === 'Plugin inactive', 'Apps shows the actual vendor activation state.');

    update_option('active_plugins', array_values(array_unique([...$plugins, App::PLUGIN])));
    update_option('woocommerce_razorpay_settings', ['enabled'=>'yes', 'key_id'=>$testKey, 'key_secret'=>'']);
    $check(App::status()['mode'] === 'unconfigured', 'An identifier alone does not claim a configured gateway.');
    update_option('woocommerce_razorpay_settings', ['enabled'=>'yes', 'key_id'=>$testKey, 'key_secret'=>$testSecret]);

    $operation = MCP_Store_Provider::operations()['store.razorpay.status'];
    $check($operation['permission']() && $operation['discoverPermission'](), 'MCP discovers readiness for the WooCommerce manager.');
    $state = $operation['execute']();
    $check($state['mode'] === 'test' && $state['gatewayActive'] && $state['gatewayEnabled'], 'Native option readback confirms Test Mode setup.');
    $check($state['kiweSubscriptionQuotes'] && is_bool($state['standardMethodChoices']),
        'Kiwe reports its own subscription quote capability and the checkout method setting.');
    $card = App::card();
    $check($card['id'] === 'razorpay' && $card['status'] === 'Test Mode configured' && $card['canChange'] === false
        && str_contains($card['url'], 'tab=checkout&section=razorpay'), 'The App opens the native Razorpay settings.');
    $serialized = wp_json_encode([$state, $card]);
    $check(!str_contains($serialized, $testKey) && !str_contains($serialized, $testSecret), 'The card and MCP response omit merchant keys.');

    update_option('woocommerce_razorpay_settings', ['enabled'=>'no', 'key_id'=>$testKey, 'key_secret'=>$testSecret]);
    $check(App::card()['status'] === 'Payments disabled' && App::status()['mode'] === 'test',
        'Disabling the Woo gateway preserves its stored mode.');

    $staff = wp_insert_user([
        'user_login' => 'checkout-manager-' . wp_generate_password(10, false),
        'user_pass' => wp_generate_password(32),
        'role' => 'shop_manager',
    ]);
    $check(is_int($staff) && $staff > 0, 'The fixture has a WooCommerce shop manager.');
    wp_set_current_user($staff);
    $canReview = current_user_can('manage_woocommerce');
    $check($operation['permission']() === $canReview && (!$canReview || App::card()['url'] === ''),
        'Status honors existing Store permissions and the settings link stays with administrators.');

    wp_set_current_user(0);
    $check(!App::allowed() && !$operation['permission']() && !$operation['discoverPermission'](), 'Guests cannot discover or query the payment App.');
    $denied = false;
    try { $operation['execute'](); } catch (RuntimeException) { $denied = true; }
    $check($denied, 'The native status read independently enforces manager permissions.');
} finally {
    update_option('active_plugins', $plugins);
    update_option('woocommerce_razorpay_settings', $option);
    if (is_int($staff) && $staff > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($staff);
    }
    wp_set_current_user(0);
}

echo wp_json_encode(['passed'=>true,'checks'=>$checks,'wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION]), PHP_EOL;
