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
$loseCancellation = false;
$cancelled = 0;
$gateway = static function (string $method, string $path, array $body = []) use (
    &$orders, &$payments, &$reads, &$providerPlans, &$subscriptions, &$invoices, &$canSchedule, &$loseApproval, &$loseCancellation, &$cancelled
): array {
    if ($method === 'GET') {
        $reads++;
        return $orders[$path] ?? $payments[$path] ?? $providerPlans[$path] ?? $subscriptions[$path] ?? $invoices[$path]
            ?? throw new LogicException('Unexpected provider read.');
    }
    if (!$canSchedule) throw new LogicException('The paid order importer must never charge.');
    if ($method === 'POST' && $path === '/subscriptions') {
        $id = 'sub_Website' . (count($subscriptions) + 1);
        $subscription = $body + ['id' => $id, 'status' => 'created', 'paid_count' => 0, 'short_url' => 'https://rzp.io/rzp/' . $id];
        $subscriptions[$path . '/' . $id] = $subscription;
        if ($loseApproval) throw new KiweCheckoutError('Simulated connection loss after creating approval.', 503);
        return $subscription;
    }
    if ($method === 'POST' && str_ends_with($path, '/cancel')) {
        $route = substr($path, 0, -strlen('/cancel'));
        $cancelled++;
        $subscriptions[$route]['status'] = 'cancelled';
        if ($loseCancellation) throw new KiweCheckoutError('Simulated connection loss after cancellation.', 503);
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
$settings['plans']['freelancerPass'] = 'plan_AnnualFixture';
$settings['plans']['passWebsitePlans'] = ['kiwe-ecommerce-v1' => 'plan_EcommercePassFixture'];
$providerPlans['/plans/plan_EcommercePassFixture'] = ['id' => 'plan_EcommercePassFixture', 'period' => 'monthly', 'interval' => 1,
    'item' => ['amount' => $plans['kiwe-ecommerce-v1']['referred']['renewalMinor'], 'currency' => 'INR']];
$providerPlans['/plans/plan_AnnualFixture'] = ['id' => 'plan_AnnualFixture', 'period' => 'yearly', 'interval' => 1,
    'item' => ['amount' => $plans['kiwe-freelancer-annual-v1']['priceMinor'], 'currency' => 'INR']];
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

[$portfolio, $commerce, $invalid, $wrongTotal, $pass] = $native;
unset($pass['domain']);
$passCharge = $payments['/payments/' . $pass['paymentId']];
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

expectWooDenied(fn () => $billing->importWooPurchase($pass), 409);
$billing->reservePassOrder($buyer, $pass['orderId']);
expectWooDenied(fn () => $billing->reservePassOrder($buyer, $pass['orderId'] + 1), 409);
expectWooDenied(fn () => $billing->importWooPurchase([...$pass, 'domain' => $portfolio['domain']]), 409);
$passId = $billing->importWooPurchase($pass);
expectWoo($billing->importWooPurchase($pass) === $passId && $billing->freelancerPass($buyer)['active'],
    'A verified pass activates one account benefit and reconciles exactly once.');
expectWoo(!$billing->freelancerPass($other) && !$billing->deliver($buyer, $passId) && !$billing->deliverPending()['delivered']
    && count($billing->websitePurchases($buyer)) === 2, 'A pass cannot be read by another buyer or delivered as a website.');
expectWooDenied(fn () => $billing->handoff($buyer, $passId), 409);

$passState = $billing->status($buyer, $passId);
$passStart = $passState['firstTermEnd'];
$passAmount = $passState['quote']['renewalMinor'];
expectWoo($passAmount === 149900 && $passState['renewalInterval'] === 'yearly' && $passState['renewalCycles'] === 9,
    'The pass uses annual billing at the exact catalogue price.');
expectWooDenied(fn () => $billing->renew($buyer, 'fixture', $passAmount, $passStart, 120, $passId), 409);
$providerPlans['/plans/plan_AnnualFixture']['period'] = 'monthly';
expectWooDenied(fn () => $billing->renew($buyer, 'fixture', $passAmount, $passStart, 9, $passId), 503);
$providerPlans['/plans/plan_AnnualFixture']['period'] = 'yearly';
$billing->renew($buyer, 'fixture', $passAmount, $passStart, 9, $passId);
$annual = $subscriptions['/subscriptions/sub_Website3'];
expectWoo($annual['plan_id'] === 'plan_AnnualFixture' && $annual['start_at'] === $passStart && $annual['total_count'] === 9,
    'Annual renewals begin after the prepaid year and stay on the same pass order.');

$annualPayment = [...$renewal, 'id' => 'pay_AnnualFixture', 'invoice_id' => 'inv_AnnualFixture',
    'order_id' => 'order_AnnualFixture', 'amount' => $passAmount];
$yearEnd = kiwe_billing_month_end($passStart, 12);
$invoicePath = '/invoices/' . $annualPayment['invoice_id'];
$invoices[$invoicePath] = ['id' => $annualPayment['invoice_id'], 'payment_id' => $annualPayment['id'],
    'order_id' => $annualPayment['order_id'], 'subscription_id' => $annual['id'], 'currency' => 'INR', 'status' => 'paid',
    'amount' => $passAmount, 'amount_paid' => $passAmount, 'billing_start' => $passStart,
    'billing_end' => kiwe_billing_month_end($passStart, 1)];
$annualEvent = ['subscription' => ['entity' => [...$annual, 'status' => 'active']], 'payment' => ['entity' => $annualPayment]];
expectWooDenied(fn () => $send('subscription.charged', 'pass-year-one', $annualEvent), 409);
$invoices[$invoicePath]['billing_end'] = $yearEnd;
$send('subscription.charged', 'pass-year-one', $annualEvent);
$send('subscription.charged', 'pass-year-one', $annualEvent);
expectWoo(count($billing->status($buyer, $passId)['renewals']) === 1
    && $billing->status($buyer, $passId)['paidThrough'] === $yearEnd
    && $billing->freelancerPass($buyer)['paidThrough'] === $passStart, 'Only a paid annual invoice extends future coverage once.');
$billing->cancelRenewal($buyer, $passId);
expectWoo($billing->freelancerPass($buyer)['active'] && $billing->status($buyer, $passId)['mandate']['status'] === 'cancelled',
    'Ending automatic renewal preserves paid access.');

$dispute = ['id' => 'disp_PassFixture', 'payment_id' => $passCharge['id'], 'currency' => 'INR',
    'amount' => $passAmount, 'amount_deducted' => 0];
$send('payment.dispute.created', 'pass-dispute', ['dispute' => ['entity' => $dispute]]);
expectWoo(!$billing->freelancerPass($buyer)['active'], 'A disputed current payment stops pass eligibility.');
$send('payment.dispute.won', 'pass-dispute-resolved', ['dispute' => ['entity' => $dispute]]);
expectWoo($billing->freelancerPass($buyer)['active'], 'Resolving the dispute restores paid coverage.');
$passRefund = ['id' => 'rfnd_PassFixture', 'payment_id' => $passCharge['id'], 'amount' => $passAmount,
    'currency' => 'INR', 'status' => 'processed'];
$send('refund.processed', 'pass-charge-refund', ['refund' => ['entity' => $passRefund]]);
expectWoo(!$billing->freelancerPass($buyer)['active'] && $billing->status($buyer, $initial)['projectId'] === $projectA,
    'A refund disables current pass coverage without changing website purchases or granting a future year early.');

$expired = [...$pass, 'accountId' => $other, 'orderId' => 505, 'providerOrderId' => 'order_ExpiredFixture',
    'paymentId' => 'pay_ExpiredFixture', 'paidAt' => kiwe_billing_month_end($now, -13)];
$orders['/orders/' . $expired['providerOrderId']] = [...$orders['/orders/' . $pass['providerOrderId']],
    'id' => $expired['providerOrderId'], 'receipt' => '505', 'notes' => ['woocommerce_order_id' => '505']];
$payments['/payments/' . $expired['paymentId']] = [...$passCharge, 'id' => $expired['paymentId'], 'order_id' => $expired['providerOrderId'],
    'created_at' => $expired['paidAt']];
$billing->reservePassOrder($other, $expired['orderId']);
$billing->importWooPurchase($expired);
expectWoo(!$billing->freelancerPass($other)['active'] && $billing->freelancerPass($other)['paidThrough'] < $now,
    'An expired prepaid year no longer gives pass eligibility.');
$billing->reconcileRenewalRates();
$unrelatedState = $billing->status($buyer, $second)['mandate']['status'];

$rateBuyer = 'launch:' . str_repeat('c', 32);
$accounts->execute([$rateBuyer, 'renewal-buyer@example.invalid', 'fixture', time()]);
$nativeRateOrder = static function (array $base, int $id, int $paidAt) use (&$orders, &$payments, $rateBuyer): array {
    $native = [...$base, 'orderId' => $id, 'paidAt' => $paidAt, 'accountId' => $rateBuyer,
        'providerOrderId' => 'order_RateFixture' . $id, 'paymentId' => 'pay_RateFixture' . $id];
    $orders['/orders/' . $native['providerOrderId']] = [...$orders['/orders/' . $base['providerOrderId']],
        'id' => $native['providerOrderId'], 'receipt' => (string) $id, 'notes' => ['woocommerce_order_id' => (string) $id]];
    $payments['/payments/' . $native['paymentId']] = [...$payments['/payments/' . $base['paymentId']],
        'id' => $native['paymentId'], 'order_id' => $native['providerOrderId'], 'created_at' => $paidAt, 'status' => 'captured', 'amount_refunded' => 0];
    return $native;
};
$rateOrder = $nativeRateOrder($commerce, 506, kiwe_billing_month_end($now, -6));
$rateSite = $billing->importWooPurchase($rateOrder);
$direct = $billing->status($rateBuyer, $rateSite);
$rateStart = $direct['renewalStart'];
$directAmount = $direct['renewalAmount'];
$billing->renew($rateBuyer, 'fixture', $directAmount, $rateStart, $direct['renewalCycles'], $rateSite);
$oldMandate = $billing->status($rateBuyer, $rateSite)['mandate'];
expectWoo($oldMandate['amount'] === $directAmount && $oldMandate['url'], 'The buyer can approve the direct monthly price.');

$ratePass = $nativeRateOrder($pass, 507, $now);
$billing->reservePassOrder($rateBuyer, 507);
$billing->importWooPurchase($ratePass);
$eligible = $billing->status($rateBuyer, $rateSite);
$passThrough = $billing->freelancerPass($rateBuyer)['paidThrough'];
$cycles = $eligible['renewalCycles'];
expectWoo($eligible['renewalPassPrice'] && $eligible['renewalAmount'] === 69900 && $eligible['renewalStart'] === $rateStart
    && kiwe_billing_month_end($rateStart, $cycles - 1) < $passThrough && kiwe_billing_month_end($rateStart, $cycles) >= $passThrough,
    'The eligible quote covers exactly the scheduled starts within the paid Pass year.');
expectWoo($eligible['mandate']['stoppingForPrice'] && !$eligible['mandate']['url'],
    'Acquiring a Pass must close an existing higher-rate approval before reauthorising.');
expectWooDenied(fn () => $billing->renew($rateBuyer, 'fixture', 69900, $rateStart, $cycles, $rateSite), 409);

$countBefore = $cancelled;
$loseCancellation = true;
expectWoo($billing->reconcileRenewalRates() === ['stopped' => 0, 'pending' => 1, 'awaitingRecovery' => 0],
    'A lost cancellation response must leave the old approval pending.');
$db->exec('UPDATE launch_website_renewal_stops SET retry_after = 0');
$loseCancellation = false;
expectWoo($billing->reconcileRenewalRates() === ['stopped' => 1, 'pending' => 0, 'awaitingRecovery' => 0] && $cancelled === $countBefore + 1,
    'Read the provider before a retry and avoid repeating a confirmed cancellation.');
expectWooDenied(fn () => $billing->renew($rateBuyer, 'fixture', 69900, $rateStart, $cycles + 1, $rateSite), 409);
$billing->renew($rateBuyer, 'fixture', 69900, $rateStart, $cycles, $rateSite);
$discountedMandate = end($subscriptions);
expectWoo($discountedMandate['plan_id'] === 'plan_EcommercePassFixture' && $discountedMandate['total_count'] === $cycles,
    'The new consent and provider approval use the bounded Pass rate.');

$siteCharge = [...$renewal, 'id' => 'pay_NewRateFixture', 'invoice_id' => 'inv_NewRateFixture', 'order_id' => 'order_NewRateFixture', 'amount' => 69900];
$siteEnd = kiwe_billing_month_end($rateStart, 1);
$invoices['/invoices/' . $siteCharge['invoice_id']] = ['id' => $siteCharge['invoice_id'], 'payment_id' => $siteCharge['id'],
    'order_id' => $siteCharge['order_id'], 'subscription_id' => $discountedMandate['id'], 'currency' => 'INR', 'status' => 'paid',
    'amount' => 69900, 'amount_paid' => 69900, 'billing_start' => $rateStart, 'billing_end' => $siteEnd];
$send('subscription.charged', 'website-discount-cycle', [
    'subscription' => ['entity' => [...$discountedMandate, 'status' => 'active']], 'payment' => ['entity' => $siteCharge],
]);
$sitePassRefund = [...$passRefund, 'id' => 'rfnd_RateFixture', 'payment_id' => $ratePass['paymentId']];
$send('refund.processed', 'website-rate-pass-refunded', ['refund' => ['entity' => $sitePassRefund]]);
$fallback = $billing->status($rateBuyer, $rateSite);
expectWoo(!$fallback['renewalPassPrice'] && $fallback['renewalAmount'] === $directAmount && $fallback['mandate']['stoppingForPrice']
    && $fallback['paidThrough'] === $siteEnd && $fallback['renewalStart'] === $siteEnd,
    'A refund changes future renewals without reclaiming a paid site period.');
expectWooDenied(fn () => $billing->renew($rateBuyer, 'fixture', $directAmount, $siteEnd, $fallback['renewalCycles'], $rateSite), 409);
$subscriptions['/subscriptions/' . $discountedMandate['id']]['paid_count'] = 2;
expectWoo($billing->reconcileRenewalRates() === ['stopped' => 0, 'pending' => 1, 'awaitingRecovery' => 0],
    'A cancelled approval with a missing paid cycle must block replacement to prevent overlapping charges.');
expectWooDenied(fn () => $billing->renew($rateBuyer, 'fixture', $directAmount, $siteEnd, $fallback['renewalCycles'], $rateSite), 409);
$lateSiteCharge = [...$siteCharge, 'id' => 'pay_RateLateFixture', 'invoice_id' => 'inv_RateLateFixture', 'order_id' => 'order_RateLateFixture'];
$siteEndAfterLatePayment = kiwe_billing_month_end($rateStart, 2);
$invoices['/invoices/' . $lateSiteCharge['invoice_id']] = [...$invoices['/invoices/' . $siteCharge['invoice_id']],
    'id' => $lateSiteCharge['invoice_id'], 'payment_id' => $lateSiteCharge['id'], 'order_id' => $lateSiteCharge['order_id'],
    'billing_start' => $siteEnd, 'billing_end' => $siteEndAfterLatePayment];
$send('subscription.charged', 'website-discount-late-cycle', [
    'subscription' => ['entity' => $subscriptions['/subscriptions/' . $discountedMandate['id']]], 'payment' => ['entity' => $lateSiteCharge],
]);
$finalRate = $billing->status($rateBuyer, $rateSite);
expectWoo(!$finalRate['mandate']['stoppingForPrice'] && $finalRate['renewalStart'] === $siteEndAfterLatePayment
    && $billing->status($buyer, $second)['mandate']['status'] === $unrelatedState,
    'A late paid cycle extends the site term and releases only its reconciled approval.');
echo json_encode(['projects' => [$projectA, $projectB], 'buyer' => $buyer], JSON_THROW_ON_ERROR), PHP_EOL;
