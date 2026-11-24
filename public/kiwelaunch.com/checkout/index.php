<?php

declare(strict_types=1);

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$root = getenv('KIWE_LAUNCH_CHECKOUT_ROOT') ?: dirname(__DIR__, 4) . '/.kiwe-launch-checkout';
$state = null;
try {
    if (is_readable($root . '/boot.php') && is_readable($root . '/web.php')) {
        require $root . '/boot.php';
        $state = kiwe_checkout_boot($root);
    }
} catch (Throwable) {
    error_log('Kiwe Launch checkout initialization failed.');
}
if (!$state) {
    http_response_code(503);
    echo '<!doctype html><html lang="en-IN"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kiwe Launch enrolment</title><link rel="stylesheet" href="/assets/launch/site.css?v=2">
    <main class="section wrap"><h1>Kiwe enrolment is unavailable.</h1>
    <p class="hero-text">Ask us about your Appsite and we’ll help you get started.</p>
    <a class="button" href="/#demo">Book a demo</a></main></html>';
    exit;
}
if ($state['billing']) {
    header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self' https://rzp.io https://rzp.in; frame-ancestors 'none'");
}

require $root . '/web.php';
kiwe_checkout_serve($state, $root);
