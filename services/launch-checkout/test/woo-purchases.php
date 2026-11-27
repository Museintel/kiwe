<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !str_starts_with(basename($argv[1] ?? ''), 'kiwe-woo-purchases-')) exit(1);
require __DIR__ . '/../boot.php';
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

$orders = $payments = [];
$reads = 0;
$gateway = static function (string $method, string $path) use (&$orders, &$payments, &$reads): array {
    if ($method !== 'GET') throw new LogicException('The paid order importer must never charge.');
    $reads++;
    return $orders[$path] ?? $payments[$path] ?? throw new LogicException('Unexpected provider read.');
};
$start = static fn (string $action, array $body): array => kiwe_test_start($root, $origin, $token, $action, $body);
$billing = new KiweBilling($database, $checkout, 'http://127.0.0.1:8250', $origin, 'acc_Fixture', str_repeat('s', 32), [], 120, $gateway, $start, $plans);
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
echo json_encode(['projects' => [$projectA, $projectB], 'buyer' => $buyer], JSON_THROW_ON_ERROR), PHP_EOL;
