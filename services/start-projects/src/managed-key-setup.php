<?php

if (!defined('WP_CLI') || !WP_CLI) exit(1);
$origin = untrailingslashit(home_url());
$code = getenv('KIWE_MANAGED_KEY_CODE');
if ($origin !== getenv('KIWE_MANAGED_SITE_ORIGIN') || !is_string($code) || ($code !== '' && !preg_match('/^[A-Za-z0-9_-]{43}$/D', $code))) {
    WP_CLI::error('The managed Key setup does not match this site.');
}
if (!class_exists('\DSA\Runtime\Package_Manifest') || !class_exists('\DSA\PhoneKey\PhoneKey_Core_Loader')
    || empty(\DSA\Runtime\Package_Manifest::verify()['valid'])) {
    WP_CLI::error('Install the verified Kiwe package before connecting Key.');
}
if (!function_exists('pk_website_setup_connect')) {
    (new \DSA\PhoneKey\PhoneKey_Core_Loader())->register();
}
$status = pk_website_setup_connect(pk_website_setup_is_configured(pk_settings()) ? '' : $code);
if ($status !== 'verified') WP_CLI::error('Key could not verify this WordPress site.');

if (getenv('KIWE_MANAGED_ENABLE_AUTH') === 'yes') {
    $settings = get_option(DSA_OPTION_SETTINGS, []);
    if (!is_array($settings)) WP_CLI::error('Review the existing Kiwe settings before enabling Auth.');
    if (empty($settings['phonekey']['enabled'])) {
        $settings['phonekey']['enabled'] = true;
        if (!update_option(DSA_OPTION_SETTINGS, $settings)) WP_CLI::error('WordPress could not enable Kiwe Auth.');
    }
}
echo "verified\n";
