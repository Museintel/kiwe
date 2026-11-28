<?php

declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/registration.php';

function kiwe_checkout_billing(mixed $config, KiweCheckout $checkout, string $database, string $origin, bool $test, array $nativePlans = []): ?KiweBilling
{
    if (!is_array($config)) return null;
    $startOrigin = $config['startOrigin'] ?? '';
    $cycles = $config['cycles'] ?? 120;
    $plans = $config['plans'] ?? [];
    $merchant = kiwe_billing_id($config['merchant'] ?? null, 'acc');
    $secret = $config['webhookSecret'] ?? '';
    if (!$merchant || !is_string($secret) || strlen($secret) < 32 || !is_int($cycles) || $cycles < 1 || $cycles > 1200
        || !is_array($plans) || !kiwe_billing_id($plans['direct'] ?? null, 'plan')
        || !kiwe_billing_id($plans['referred'] ?? null, 'plan') || !is_array($plans['websitePlans'] ?? [])) {
        throw new RuntimeException('Kiwe billing credentials or plans are invalid.');
    }
    foreach ($plans['websitePlans'] ?? [] as $catalogueId => $providerId) {
        if (($nativePlans[$catalogueId]['hostingIncluded'] ?? false) !== true || !kiwe_billing_id($providerId, 'plan')) {
            throw new RuntimeException('A website renewal plan is not mapped to the Launch catalogue.');
        }
    }
    if (isset($plans['freelancerPass']) && !kiwe_billing_id($plans['freelancerPass'], 'plan')) {
        throw new RuntimeException('The Freelancer Pass renewal plan is invalid.');
    }
    $razorpay = null;
    if ($test) {
        if (!preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D', $startOrigin)) {
            throw new RuntimeException('Invalid isolated Start origin.');
        }
        if (isset($config['razorpay'])) $razorpay = Closure::fromCallable($config['razorpay']);
        $start = Closure::fromCallable($config['start']);
    } else {
        if ($startOrigin !== 'https://start.kiwelaunch.com' || strlen($config['startToken'] ?? '') < 43) {
            throw new RuntimeException('Kiwe billing cannot confirm its Start connection.');
        }
        $start = kiwe_billing_start($startOrigin, $config['startToken']);
    }
    if (!$razorpay) {
        $keyId = $config['keyId'] ?? '';
        $mode = $test ? 'test' : 'live';
        if (($config['mode'] ?? '') !== $mode
            || !preg_match('/^rzp_' . $mode . '_[A-Za-z0-9]+$/D', $keyId)
            || strlen($config['keySecret'] ?? '') < 16 || !function_exists('curl_init')) {
            throw new RuntimeException('Kiwe billing is not configured for its declared provider mode.');
        }
        $razorpay = kiwe_billing_gateway($keyId, $config['keySecret']);
    }
    return new KiweBilling($database, $checkout, $origin, $startOrigin, $merchant, $secret, $plans, $cycles, $razorpay, $start, $nativePlans);
}

function kiwe_checkout_native_plans(string $root): array
{
    $path = $root . '/launch-catalogue.json';
    if (!is_file($path)) return [];
    $catalogue = json_decode(file_get_contents($path, length: 65536), true, flags: JSON_THROW_ON_ERROR);
    $plans = [];
    $websitePlans = $catalogue['websitePlans'] ?? [];
    if (!is_array($websitePlans) || !$websitePlans) throw new RuntimeException('The website catalogue is missing its plans.');
    foreach ($websitePlans as $plan) {
        $id = $plan['id'] ?? '';
        $sku = $plan['sku'] ?? '';
        $renewal = $plan['direct']['renewalMinor'] ?? null;
        $eligible = $plan['referred']['renewalMinor'] ?? null;
        if (!is_string($id) || !preg_match('/^kiwe-[a-z]+-v[1-9][0-9]*$/D', $id) || isset($plans[$id])
            || !is_string($sku) || !preg_match('/^KIWE-[A-Z]+-FIRST-12-MONTHS$/D', $sku)
            || ($plan['currency'] ?? '') !== 'INR' || ($plan['hostingIncluded'] ?? false) !== true
            || ($plan['domain']['includedExtension'] ?? '') !== 'in' || ($plan['firstTermMonths'] ?? null) !== 12
            || ($plan['renewalIntervalMonths'] ?? null) !== 1 || !is_int($renewal) || $renewal < 1 || $renewal > 10000000
            || ($plan['direct']['upfrontMinor'] ?? null) !== $renewal * 12
            || !is_int($eligible) || $eligible < 1 || $eligible >= $renewal
            || ($plan['referred']['upfrontMinor'] ?? null) !== $eligible * 12) {
            throw new RuntimeException('The website catalogue has invalid billing terms.');
        }
        $plans[$id] = $plan;
    }
    if (isset($catalogue['freelancerPass'])) {
        $pass = $catalogue['freelancerPass'];
        $amount = $pass['priceMinor'] ?? null;
        if (($pass['id'] ?? '') !== 'kiwe-freelancer-annual-v1' || ($pass['sku'] ?? '') !== 'KIWE-FREELANCER-ANNUAL'
            || ($pass['currency'] ?? '') !== 'INR' || ($pass['hostingIncluded'] ?? true) !== false
            || ($pass['intervalMonths'] ?? null) !== 12 || !is_int($amount) || $amount < 1 || $amount > 10000000) {
            throw new RuntimeException('The Freelancer Pass has invalid billing terms.');
        }
        $plans[$pass['id']] = $pass + ['direct' => ['upfrontMinor' => $amount, 'renewalMinor' => $amount]];
    }
    return $plans;
}

function kiwe_checkout_boot(string $root): ?array
{
    if (!is_readable($root . '/settings.php')) return null;
    $settings = require $root . '/settings.php';
    if (!is_array($settings) || ($settings['enabled'] ?? false) !== true) return null;
    $origin = $settings['origin'] ?? 'https://kiwelaunch.com';
    $test = ($settings['test'] ?? false) === true;
    if ($test && !in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        throw new RuntimeException('Checkout test transports require an isolated CLI runtime.');
    }
    if ($test ? !preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D', $origin) : $origin !== 'https://kiwelaunch.com') {
        throw new RuntimeException('Invalid Launch checkout origin.');
    }
    $storage = $root . '/data';
    $secret = is_readable($storage . '/rate-key') ? file_get_contents($storage . '/rate-key') : '';
    $plan = json_decode(file_get_contents($root . '/client-plan.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($plan['currency'] ?? '') !== 'INR' || ($plan['hostingIncluded'] ?? false) !== true
        || !isset($plan['direct']['upfrontMinor'], $plan['direct']['renewalMinor'],
            $plan['referred']['upfrontMinor'], $plan['referred']['renewalMinor'], $plan['domain']['includedExtension'])
        || !is_int($plan['monthlyPartnerCommissionMinor'] ?? null) || $plan['monthlyPartnerCommissionMinor'] < 1) {
        throw new RuntimeException('The canonical Kiwe plan is unavailable.');
    }
    if ($test) {
        $mailer = $settings['mailer'];
        $registrar = $settings['registrar'];
    } else {
        $mailbox = $settings['mailbox'] ?? '';
        $mailToken = $settings['mailToken'] ?? '';
        $registrarToken = $settings['registrarToken'] ?? '';
        if (!preg_match('/^AC[A-Za-z0-9_-]{5,100}$/D', $mailbox)
            || strlen($mailToken) < 16 || strlen($registrarToken) < 16 || !function_exists('curl_init')) {
            return null;
        }
        $mailer = static fn (string $email, string $purpose, string $body) => kiwe_checkout_send_email($mailbox, $mailToken, $email, $purpose, $body);
        $registrar = static fn (string $name, string $extension): array => kiwe_checkout_hostinger_domain($registrarToken, $name, $extension);
    }
    $database = $storage . '/checkout.sqlite';
    $checkout = new KiweCheckout($database, $plan, $origin, $secret, $mailer, $registrar);
    $nativePlans = kiwe_checkout_native_plans($root);
    return [
        'origin' => $origin, 'test' => $test, 'sessions' => $storage . '/sessions',
        'checkout' => $checkout, 'billing' => kiwe_checkout_billing($settings['billing'] ?? null, $checkout, $database, $origin, $test, $nativePlans),
        'nativeWoo' => ($settings['nativeWoo'] ?? false) === true && $nativePlans
            ? ['plans' => $nativePlans, 'key' => hash_hmac('sha256', 'kiwe-launch-native-order-v1', $secret, true)] : null,
        'registrar' => PHP_SAPI === 'cli' ? static fn (): ?KiweRegistration => kiwe_registration_provider($settings, $test) : null,
    ];
}
