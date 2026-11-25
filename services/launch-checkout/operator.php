<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/boot.php';

try {
    $root = getenv('KIWE_LAUNCH_CHECKOUT_ROOT') ?: __DIR__;
    $state = kiwe_checkout_boot($root);
    if (!$state) throw new KiweCheckoutError('Launch checkout is disabled or its providers are unconfigured.', 503);
    $checkout = $state['checkout'];
    $billing = $state['billing'];
    $value = $argv[2] ?? '';
    $result = match ($argv[1] ?? '') {
        'domain' => ['domain' => kiwe_checkout_domain($value), ...$checkout->domainAvailability($value)],
        'enrol-partner' => ['account' => $checkout->enrolPartner($value)],
        'quote' => kiwe_checkout_operator_quote($checkout, $value),
        'billing-status' => ['billing' => kiwe_checkout_operator_billing($billing, $value)],
        'reconcile-billing' => $billing?->deliverPending() ?? throw new KiweCheckoutError('Launch billing is unavailable.', 503),
        'register-domain', 'reconcile-domain' => kiwe_checkout_operator_domain($state, $argv[1], $value, $argv[3] ?? '', $argv[4] ?? ''),
        'review-domain-move' => ($billing ?? throw new KiweCheckoutError('Launch billing is unavailable.', 503))
            ->reviewDomainMove($value, kiwe_checkout_domain($argv[3] ?? ''), $argv[4] ?? '', $argv[5] ?? ''),
        'recover-renewal' => kiwe_checkout_operator_recover($billing, $value, $argv[3] ?? ''),
        'partner-summary' => ['balance' => kiwe_checkout_operator_partners($billing)->summary($value)],
        'reconcile-partner-deliveries' => kiwe_checkout_operator_partners($billing)->reconcileDeliveries(),
        'withdrawals' => kiwe_checkout_operator_partners($billing)->withdrawals($value ?: 'pending', kiwe_checkout_operator_page($argv[3] ?? '1')),
        'review-withdrawal' => ['withdrawal' => kiwe_checkout_operator_partners($billing)
            ->reviewWithdrawal($value, kiwe_checkout_operator_amount($argv[3] ?? ''), $argv[4] ?? '')],
        'record-payout' => ['withdrawal' => kiwe_checkout_operator_partners($billing)
            ->recordPayout($value, kiwe_checkout_operator_amount($argv[3] ?? ''), $argv[4] ?? '')],
        default => throw new KiweCheckoutError('Unknown Launch operation.'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (KiweCheckoutError $error) {
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Kiwe Launch checkout is unavailable.\n");
    exit(1);
}

function kiwe_checkout_operator_domain(array $state, string $action, string $purchase, string $domain, string $contact): array
{
    $billing = $state['billing'] ?? throw new KiweCheckoutError('Launch billing is unavailable.', 503);
    $registrar = ($state['registrar'])()
        ?? throw new KiweCheckoutError('Paid domain registration is disabled.', 503);
    $domain = kiwe_checkout_domain($domain);
    if ($action === 'reconcile-domain') return $billing->reconcileDomain($registrar, $purchase, $domain);
    $id = filter_var($contact, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new KiweCheckoutError('Provide the approved .in WHOIS profile ID for the buyer.');
    return $billing->registerDomain($registrar, $purchase, $domain, $id);
}

function kiwe_checkout_operator_quote(KiweCheckout $checkout, string $accountId): array
{
    if (!preg_match('/^launch:[a-f0-9]{32}$/D', $accountId)) throw new KiweCheckoutError('Enter a Launch account ID.');
    $draft = $checkout->draft($accountId);
    if (!$draft) throw new KiweCheckoutError('This account has no saved checkout.', 404);
    return ['id' => $draft['id'], 'revision' => $draft['revision'], 'quote' => $draft['quote']];
}

function kiwe_checkout_operator_billing(?KiweBilling $billing, string $accountId): ?array
{
    if (!$billing) throw new KiweCheckoutError('Launch billing is unavailable.', 503);
    if (!preg_match('/^launch:[a-f0-9]{32}$/D', $accountId)) throw new KiweCheckoutError('Enter a Launch account ID.');
    $status = $billing->status($accountId);
    if ($status) {
        unset($status['linkUrl']);
        unset($status['mandate']['url']);
    }
    return $status;
}

function kiwe_checkout_operator_recover(?KiweBilling $billing, string $account, string $subscription): array
{
    if (!$billing) throw new KiweCheckoutError('Launch billing is unavailable.', 503);
    if (!preg_match('/^launch:[a-f0-9]{32}$/D', $account)) throw new KiweCheckoutError('Enter a Launch account ID.');
    $billing->recoverMandate($account, $subscription);
    return ['billing' => kiwe_checkout_operator_billing($billing, $account)];
}

function kiwe_checkout_operator_partners(?KiweBilling $billing): KiwePartners
{
    return $billing?->partners() ?? throw new KiweCheckoutError('Launch partner earnings are unavailable.', 503);
}

function kiwe_checkout_operator_amount(string $amount): int
{
    $minor = filter_var($amount, FILTER_VALIDATE_INT);
    if ($minor === false || $minor < 1) throw new KiweCheckoutError('Enter the exact positive amount in paise.');
    return $minor;
}

function kiwe_checkout_operator_page(string $page): int
{
    $number = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, 50) - 1]]);
    if ($number === false) throw new KiweCheckoutError('Enter a valid withdrawal page.');
    return $number;
}
