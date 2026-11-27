<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !str_starts_with(basename($argv[1] ?? ''), 'kiwe-woo-purchases-')) exit(1);
require __DIR__ . '/../boot.php';
require __DIR__ . '/../web.php';
require __DIR__ . '/billing-fixture.php';
[$script, $root, $origin] = $argv;
$token = getenv('KIWE_TEST_START_TOKEN');
$database = $root . '/checkout.sqlite';
$plans = kiwe_checkout_native_plans($root);
$legacy = json_decode(file_get_contents($root . '/client-plan.json'), true, flags: JSON_THROW_ON_ERROR);
$checkout = new KiweCheckout($database, $legacy, $origin, str_repeat('x', 32), static fn () => null, static fn () => []);
$db = new PDO('sqlite:' . $database, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(__DIR__ . '/../billing-schema.sql'));
$db->exec('DROP INDEX launch_one_open_legacy_payment; CREATE UNIQUE INDEX launch_one_open_payment ON launch_payments(account_id) WHERE retired_at IS NULL');
$buyer = 'launch:' . str_repeat('a', 32);
$other = 'launch:' . str_repeat('b', 32);
$accounts = $db->prepare('INSERT INTO checkout_accounts (id, email, password_hash, verified_at) VALUES (?, ?, ?, ?)');
$accounts->execute([$buyer, 'buyer@example.invalid', 'fixture', time()]);
$accounts->execute([$other, 'other@example.invalid', 'fixture', time()]);
$previous = 'kiwe_' . str_repeat('1', 32);
$db->prepare('INSERT INTO launch_payments (id, account_id, draft_id, draft_revision, quote, consent_at, link_expires_at, start_project_id) VALUES (?, ?, ?, 1, ?, 1, 1, ?)')
    ->execute([$previous, $buyer, 'draft:prior', json_encode($legacy['direct']), '00000000-1221-4111-8111-000000000001']);

$orders = $payments = $providerPlans = $subscriptions = $invoices = [];
$reads = 0;
$canSchedule = false;
$loseApproval = false;
$gateway = static function (string $method, string $path, array $body = []) use (
    &$orders, &$payments, &$reads, &$providerPlans, &$subscriptions, &$invoices, &$canSchedule, &$loseApproval
): array {
    if ($method === 'GET') {
        $reads++;
        return $orders[$path] ?? $payments[$path] ?? $providerPlans[$path] ?? $subscriptions[$path] ?? $invoices[$path]
            ?? throw new LogicException('Unexpected provider read.');
    }
    if (!$canSchedule) throw new LogicException('The paid order importer must never charge.');
    if ($method === 'POST' && $path === '/subscriptions') {
        $id = 'sub_Website' . (count($subscriptions) + 1);
        $subscription = $body + ['id' => $id, 'status' => 'created', 'short_url' => 'https://rzp.io/rzp/' . $id];
        $subscriptions[$path . '/' . $id] = $subscription;
        if ($loseApproval) throw new KiweCheckoutError('Simulated connection loss after creating approval.', 503);
        return $subscription;
    }
    if ($method === 'POST' && str_ends_with($path, '/cancel')) {
        $route = substr($path, 0, -strlen('/cancel'));
        $subscriptions[$route]['status'] = 'cancelled';
        return $subscriptions[$route];
    }
    throw new LogicException('Unexpected provider write.');
};
$start = static fn (string $action, array $body): array => kiwe_test_start($root, $origin, $token, $action, $body);
$secret = str_repeat('s', 32);
$settings = [
    'startOrigin' => $origin, 'merchant' => 'acc_Fixture', 'webhookSecret' => $secret, 'razorpay' => $gateway, 'start' => $start,
    'plans' => ['direct' => 'plan_DirectFixture', 'referred' => 'plan_ReferralFixture', 'websitePlans' => []],
];
foreach (['kiwe-portfolio-v1' => 'plan_PortfolioFixture', 'kiwe-ecommerce-v1' => 'plan_EcommerceFixture'] as $version => $code) {
    $settings['plans']['websitePlans'][$version] = $code;
    $providerPlans['/plans/' . $code] = ['id' => $code, 'period' => 'monthly', 'interval' => 1,
        'item' => ['amount' => $plans[$version]['direct']['renewalMinor'], 'currency' => 'INR']];
}
$billing = kiwe_checkout_billing($settings, $checkout, $database, 'http://127.0.0.1:8250', true, $plans);
$now = time();

function expectWoo(bool $matches, string $label): void
{
    if (!$matches) throw new RuntimeException($label);
}

function expectWooDenied(callable $action, int $status): void
{
    try { $action(); } catch (KiweCheckoutError $failure) {
        expectWoo($failure->status === $status, 'Unexpected rejection status.');
        return;
    }
    throw new RuntimeException('The invalid purchase was accepted.');
}

$native = [];
foreach (array_values($plans) as $offset => $plan) {
    $id = 500 + $offset;
    $provider = 'order_Fixture' . $id;
    $payment = 'pay_Fixture' . $id;
    $amount = $plan['direct']['upfrontMinor'];
    $orders['/orders/' . $provider] = ['id' => $provider, 'receipt' => (string) $id, 'notes' => ['woocommerce_order_id' => (string) $id],
        'status' => 'paid', 'currency' => 'INR', 'amount' => $amount, 'amount_paid' => $amount, 'amount_due' => 0];
    $payments['/payments/' . $payment] = ['id' => $payment, 'order_id' => $provider, 'amount' => $amount,
        'currency' => 'INR', 'status' => 'captured', 'created_at' => $now - 3, 'amount_refunded' => 0];
    $native[] = ['orderId' => $id, 'accountId' => $buyer, 'planId' => $plan['id'], 'sku' => $plan['sku'],
        'providerOrderId' => $provider, 'paymentId' => $payment, 'paidAt' => $now,
        'domain' => ['mode' => 'owned', 'name' => 'site' . $id . '.example.invalid']];
}

[$portfolio, $commerce, $invalid, $wrongTotal] = $native;
$initial = $billing->importWooPurchase($portfolio);
$second = $billing->importWooPurchase($commerce);
expectWoo($initial !== $second && $billing->status($buyer)['id'] === $previous, 'Legacy and separate native purchases must coexist.');
expectWoo($billing->status($other, $initial) === null, 'Another account could read the purchase.');
expectWooDenied(fn () => $billing->handoff($other, $initial), 409);
$before = $reads;
expectWoo($billing->importWooPurchase($portfolio) === $initial && $reads === $before, 'An exact replay should read the existing receipt.');
expectWooDenied(fn () => $billing->importWooPurchase([...$portfolio, 'accountId' => $other]), 409);
expectWooDenied(fn () => $billing->importWooPurchase([...$invalid, 'sku' => 'wrong']), 400);
expectWooDenied(fn () => $billing->importWooPurchase([...$invalid, 'accountId' => 'launch:' . str_repeat('c', 32)]), 404);
expectWooDenied(fn () => $billing->importWooPurchase([...$invalid, 'providerOrderId' => $portfolio['providerOrderId']]), 409);
$payments['/payments/' . $invalid['paymentId']]['status'] = 'authorized';
expectWooDenied(fn () => $billing->importWooPurchase($invalid), 409);
$payments['/payments/' . $wrongTotal['paymentId']]['amount']--;
expectWooDenied(fn () => $billing->importWooPurchase($wrongTotal), 409);
expectWoo((int) $db->query('SELECT COUNT(*) FROM launch_woo_orders')->fetchColumn() === 2, 'Invalid imports must not leave purchases.');

$firstPass = $billing->deliverPending();
expectWoo($firstPass['delivered'] === 2 && $firstPass['retry'] === 0, 'The worker must deliver both exact purchases.');
$projectA = $billing->status($buyer, $initial)['projectId'];
$projectB = $billing->status($buyer, $second)['projectId'];
expectWoo($projectA && $projectB && $projectA !== $projectB, 'Each website needs a separate Start project.');
expectWoo($billing->deliverPending()['delivered'] === 0 && $billing->deliver($buyer, $second) === $projectB, 'Delivery must be idempotent.');

expectWoo(count($billing->websitePurchases($buyer)) === 2 && !$billing->websitePurchases($other), 'List only the buyer’s native purchases.');
$a = $billing->status($buyer, $initial);
$b = $billing->status($buyer, $second);
$amountA = $a['quote']['renewalMinor'];
$amountB = $b['quote']['renewalMinor'];
$starts = $a['firstTermEnd'];
expectWoo($a['renewalAvailable'] && $b['renewalAvailable'] && $a['renewalStart'] === $starts && $a['paidThrough'] === $starts,
    'First renewal should start when the prepaid term ends.');
expectWoo($billing->refresh($other, $initial) === null, 'An unrelated buyer cannot refresh another purchase.');
expectWooDenied(fn () => $billing->renew($other, 'fixture', $amountA, $starts, 120, $initial), 409);
expectWooDenied(fn () => $billing->renew($buyer, 'fixture', $amountB, $starts, 120, $initial), 409);
expectWooDenied(fn () => $billing->recoverMandate($other, 'sub_Website1', $initial), 422);
expectWooDenied(fn () => $billing->cancelRenewal($other, $initial), 409);

$unmapped = [...$settings, 'plans' => array_diff_key($settings['plans'], ['websitePlans' => true])];
$notReady = kiwe_checkout_billing($unmapped, $checkout, $database, 'http://127.0.0.1:8250', true, $plans);
expectWoo(!$notReady->status($buyer, $initial)['renewalAvailable'], 'Hide approval if the catalogue price has no provider plan.');
expectWooDenied(fn () => $notReady->renew($buyer, 'fixture', $amountA, $starts, 120, $initial), 503);
$providerPlans['/plans/plan_PortfolioFixture']['item']['amount']++;
expectWooDenied(fn () => $billing->renew($buyer, 'fixture', $amountA, $starts, 120, $initial), 503);
$providerPlans['/plans/plan_PortfolioFixture']['item']['amount']--;
expectWoo(!$subscriptions && !$billing->status($buyer, $initial)['mandate'], 'A failed plan verification must not reserve or create a mandate.');

$canSchedule = true;
$_SERVER['REMOTE_ADDR'] = 'fixture';
$_POST = ['renewal_consent' => 'yes', 'renewal_amount' => $amountA, 'renewal_start' => $starts, 'renewal_cycles' => 120];
$approve = fn () => kiwe_checkout_action($checkout, $billing, ['id' => $buyer], 'renewal-consent', $initial);
expectWoo($approve()['redirect'] === 'https://rzp.io/rzp/sub_Website1' && $approve()['redirect'] === 'https://rzp.io/rzp/sub_Website1',
    'Approval and retry must open the same Razorpay mandate.');
expectWoo(count($subscriptions) === 1 && !$billing->status($buyer, $second)['mandate'], 'The other site must stay untouched.');
$loseApproval = true;
expectWooDenied(fn () => $billing->renew($buyer, 'fixture', $amountB, $b['renewalStart'], 120, $second), 503);
$loseApproval = false;
expectWooDenied(fn () => $billing->recoverMandate($buyer, 'sub_Website1', $second), 409);
$billing->recoverMandate($buyer, 'sub_Website2', $second);
$subA = $subscriptions['/subscriptions/sub_Website1'];
$subB = $subscriptions['/subscriptions/sub_Website2'];
expectWoo($subA['plan_id'] === 'plan_PortfolioFixture' && $subB['plan_id'] === 'plan_EcommerceFixture',
    'Each mandate must use its own versioned Razorpay plan.');
expectWoo($billing->refresh($buyer, $second)['id'] === $second, 'A selected receipt refresh must return the same purchase.');

$send = static function (string $name, string $id, array $payload) use ($billing, $secret, $now): void {
    $raw = json_encode(['account_id' => 'acc_Fixture', 'event' => $name, 'created_at' => $now, 'payload' => $payload], JSON_THROW_ON_ERROR);
    $billing->ingest($raw, hash_hmac('sha256', $raw, $secret), $id);
};
$renewal = ['id' => 'pay_WebsiteCycle', 'invoice_id' => 'inv_WebsiteCycle', 'order_id' => 'order_WebsiteCycle',
    'amount' => $amountA, 'currency' => 'INR', 'status' => 'captured', 'amount_refunded' => 0];
$through = kiwe_billing_month_end($starts, 1);
$invoices['/invoices/' . $renewal['invoice_id']] = ['id' => $renewal['invoice_id'], 'payment_id' => $renewal['id'],
    'order_id' => $renewal['order_id'], 'subscription_id' => $subA['id'], 'currency' => 'INR', 'status' => 'paid',
    'amount' => $amountA, 'amount_paid' => $amountA, 'billing_start' => $starts, 'billing_end' => $through];
$send('subscription.charged', 'site-a-month-one', ['subscription' => ['entity' => [...$subA, 'status' => 'active']], 'payment' => ['entity' => $renewal]]);
kiwe_checkout_action($checkout, $billing, ['id' => $buyer], 'cancel-renewal', $initial);
expectWoo($billing->status($buyer, $initial)['mandate']['status'] === 'cancelled' && $billing->status($buyer, $initial)['paidThrough'] === $through
    && $billing->status($buyer, $second)['mandate']['status'] === 'created', 'Cancelling one site preserves the paid term and other mandate.');
$refund = ['id' => 'rfnd_WebsiteCycle', 'payment_id' => $renewal['id'], 'amount' => $amountA, 'currency' => 'INR', 'status' => 'processed'];
$send('refund.processed', 'site-a-month-refund', ['refund' => ['entity' => $refund]]);
expectWoo($billing->status($buyer, $initial)['paidThrough'] === $starts && $billing->status($buyer, $initial)['renewals'][0]['refunded'] === $amountA
    && !$billing->status($buyer, $second)['renewals'], 'A full monthly refund must remove only that site’s paid extension.');

$secondPayment = &$payments['/payments/' . $commerce['paymentId']];
$secondPayment['status'] = 'refunded';
$secondPayment['amount_refunded'] = $secondPayment['amount'];
expectWooDenied(fn () => $billing->renew($buyer, 'fixture', $amountB, $b['renewalStart'], 120, $second), 409);
expectWoo($billing->status($buyer, $second)['refunded'] === $secondPayment['amount']
    && !$billing->status($buyer, $second)['paidThrough'] && !$billing->status($buyer, $initial)['refunded'],
    'Provider readback must record a full first-year refund for only the selected site.');
echo json_encode(['projects' => [$projectA, $projectB], 'buyer' => $buyer], JSON_THROW_ON_ERROR), PHP_EOL;
