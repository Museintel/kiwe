<?php

declare(strict_types=1);

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self' https://rzp.io https://rzp.in; frame-ancestors 'none'");

$root = getenv('KIWE_LAUNCH_CHECKOUT_ROOT') ?: dirname(__DIR__, 4) . '/.kiwe-launch-checkout';
$state = null;
try {
    if (is_readable($root . '/boot.php') && is_readable($root . '/web.php')) {
        require $root . '/boot.php';
        $state = kiwe_checkout_boot($root);
    }
} catch (Throwable) {
    error_log('Kiwe Launch account initialization failed.');
}
if (!($state['nativeWoo'] ?? null) || !$state['billing']) {
    http_response_code(503);
    echo 'Kiwe Launch sign-in is temporarily unavailable.';
    return;
}
require $root . '/web.php';
kiwe_checkout_serve($state, $root, true);
