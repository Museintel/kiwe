<?php

declare(strict_types=1);

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$root = getenv('KIWE_LAUNCH_CHECKOUT_ROOT') ?: dirname(__DIR__, 4) . '/.kiwe-launch-checkout';
try {
    if (!is_readable($root . '/boot.php')) throw new RuntimeException('Billing is unavailable.');
    require $root . '/boot.php';
    $billing = (kiwe_checkout_boot($root) ?? [])['billing'] ?? null;
    if (!$billing) throw new RuntimeException('Billing is unavailable.');
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) {
        http_response_code(413);
        exit;
    }
    $billing->ingest($raw, $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '', $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '');
    echo '{"received":true}';
} catch (KiweCheckoutError $error) {
    http_response_code($error->status);
} catch (JsonException) {
    http_response_code(400);
} catch (Throwable) {
    error_log('Kiwe Launch could not receive a billing event.');
    http_response_code(503);
}
