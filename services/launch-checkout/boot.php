<?php

declare(strict_types=1);

require_once __DIR__ . '/store.php';

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
            $plan['referred']['upfrontMinor'], $plan['referred']['renewalMinor'], $plan['domain']['includedExtension'])) {
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
    return [
        'origin' => $origin, 'test' => $test, 'sessions' => $storage . '/sessions',
        'checkout' => new KiweCheckout($storage . '/checkout.sqlite', $plan, $origin, $secret, $mailer, $registrar),
    ];
}
