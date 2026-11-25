<?php

declare(strict_types=1);

use DSA\Apps\Store_App_Service as Store;
use DSA\Commerce\Subscription_Product_Service as Products;

$lab = getenv('KIWE_CHECKOUT_LAB');
$selected = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !$lab || !is_file($lab . '/lab.json')
    || !in_array($selected, ['services','ecommerce','membership','both'], true)) exit(2);
if ('services' === $selected) {
    $noPlugins = getenv('KIWE_TEST_EMPTY_PLUGIN_DIRECTORY');
    if (!$noPlugins || realpath($noPlugins) !== realpath($lab) . DIRECTORY_SEPARATOR . 'managed-portfolio-plugins'
        || count(scandir($noPlugins)) !== 2) exit(2);
    define('WP_PLUGIN_DIR', $noPlugins);
}
require $lab . '/site/wp-load.php';
if (DB_NAME !== 'kiwe_checkout_lab' || wp_get_environment_type() !== 'local' || home_url('/') !== 'http://127.0.0.1:64549/') exit(2);
define('WP_CLI', true);

$saved = get_option(Store::OPTION, null);
$billingOption = '_unused';
if (class_exists(Products::class)) $billingOption = Products::OPTION;
$savedBilling = get_option($billingOption, null);
$checks = [];
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks[] = $label;
};
try {
    $owner = get_user_by('login', 'checkout-owner');
    $check($owner instanceof WP_User, 'The isolated site has a native owner.');
    delete_option(Store::OPTION);
    wp_set_current_user(0);
    $check(is_wp_error(Store::provision_setup($selected)), 'An unprivileged caller cannot select a managed stack.');
    wp_set_current_user($owner->ID);
    $check(is_wp_error(Store::provision_setup('unknown')), 'Unknown setups are refused.');
    $setup = Store::provision_setup($selected);
    if (is_wp_error($setup)) throw new RuntimeException($setup->get_error_message());
    $check(true === $setup && Store::managed_setup() === $selected, 'The selection reads back through the native Store owner.');
    $check(true === Store::provision_setup($selected), 'Retrying the same installation is safe.');
    $commerce = 'services' !== $selected;
    $subscriptions = in_array($selected, ['membership','both'], true);
    $check(function_exists('WC') === $commerce && Store::enabled() === $commerce, 'The chosen native plugins are present only for a commerce or membership stack.');
    if (!$commerce) {
        $check(!is_file(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php') && !Store::requested(), 'The services stack starts without WooCommerce files and Store is off.');
    } else {
        $check(('1' === (string) get_option($billingOption)) === $subscriptions
            && Store::supports_memberships() === $subscriptions, 'The chosen setup configures recurring product authoring.');
    }
    $change = Store::change(!$commerce, Store::expected());
    $check(is_wp_error($change) && Store::managed_setup() === $selected && !Store::card()['canChange'], 'An established managed stack cannot be switched by the old dashboard toggle.');
    $check(is_wp_error(Store::provision_setup($commerce ? 'services' : 'both')), 'A different mode requires a new reviewed migration.');
} finally {
    if (null === $saved) delete_option(Store::OPTION);
    else update_option(Store::OPTION,$saved,false);
    if (null === $savedBilling) delete_option($billingOption);
    else update_option($billingOption,$savedBilling,false);
}
echo wp_json_encode(['passed'=>true, 'mode'=>$selected, 'woocommerce'=>function_exists('WC'), 'checks'=>$checks]), PHP_EOL;
