<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/boot.php';

try {
    $root = getenv('KIWE_LAUNCH_CHECKOUT_ROOT') ?: __DIR__;
    $state = kiwe_checkout_boot($root);
    if (!$state) throw new KiweCheckoutError('Launch checkout is disabled or its providers are unconfigured.', 503);
    $checkout = $state['checkout'];
    $value = $argv[2] ?? '';
    $result = match ($argv[1] ?? '') {
        'domain' => ['domain' => kiwe_checkout_domain($value), ...$checkout->domainAvailability($value)],
        'enrol-partner' => ['account' => $checkout->enrolPartner($value)],
        'quote' => kiwe_checkout_operator_quote($checkout, $value),
        default => throw new KiweCheckoutError('Use domain, enrol-partner, or quote.'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (KiweCheckoutError $error) {
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Kiwe Launch checkout is unavailable.\n");
    exit(1);
}

function kiwe_checkout_operator_quote(KiweCheckout $checkout, string $accountId): array
{
    if (!preg_match('/^launch:[a-f0-9]{32}$/D', $accountId)) throw new KiweCheckoutError('Enter a Launch account ID.');
    $draft = $checkout->draft($accountId);
    if (!$draft) throw new KiweCheckoutError('This account has no saved checkout.', 404);
    return ['id' => $draft['id'], 'revision' => $draft['revision'], 'quote' => $draft['quote']];
}
