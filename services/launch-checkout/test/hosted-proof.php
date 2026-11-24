<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$root = getenv('KIWE_LAUNCH_CHECKOUT_ROOT');
if (!is_string($root) || !str_starts_with(basename($root), 'checkout-fixture')) exit(1);
require $root . '/boot.php';
$state = kiwe_checkout_boot($root);
if (!$state || !$state['test']) exit(1);
$checkout = $state['checkout'];

function kiwe_checkout_expect(bool $success, string $check): void
{
    if (!$success) throw new RuntimeException($check);
}

function kiwe_checkout_fixture_buyer(KiweCheckout $checkout, string $mail, string $email, ?string $referral = null): array
{
    $checkout->emailLink('signup', $email, $referral, '127.0.0.1');
    $messages = file($mail, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $body = json_decode($messages[array_key_last($messages)], true, flags: JSON_THROW_ON_ERROR)['body'];
    kiwe_checkout_expect((bool) preg_match('/#verify=([a-f0-9]{64})/', $body, $found), 'Expected a one-time verification link.');
    $digest = kiwe_checkout_token($found[1]);
    $account = $checkout->setPassword($digest, 'Checkout synthetic test phrase 51', null);
    kiwe_checkout_expect($checkout->linkInfo($digest) === null, 'The code was consumed.');
    return $account;
}

$file = $root . '/data/mail.jsonl';
$partner = kiwe_checkout_fixture_buyer($checkout, $file, 'partner@example.invalid');
kiwe_checkout_expect(!$partner['partner'], 'The buyer has yet to opt into the Partner Program.');
$partner = $checkout->enrolPartner($partner['email']);
$link = $checkout->newReferral($partner, [
    'email' => 'buyer@example.invalid', 'whatsapp' => '+919876500002', 'domain' => 'eligible.in', 'consent' => 'yes',
]);
$buyer = kiwe_checkout_fixture_buyer($checkout, $file, 'buyer@example.invalid', kiwe_checkout_token(substr($link, -64)));
$draft = $checkout->saveDraft($buyer, [
    'revision' => '0', 'mode' => 'register', 'name' => 'Buyer Fixture', 'phone' => '+919876500003',
    'whatsapp' => '+919876500002', 'domain' => 'eligible.in', 'billing_address' => '17 Synthetic Lane',
    'city' => 'Mumbai', 'region' => 'Maharashtra', 'postcode' => '400001', 'country' => 'India',
], '127.0.0.1');
kiwe_checkout_expect($draft['quote']['upfrontMinor'] === $checkout->plan['referred']['upfrontMinor'], 'Expected the verified referral price.');
kiwe_checkout_expect($draft['quote']['registryIdentityRequired'], 'Registry verification is retained.');

$providerRecord = json_decode(file_get_contents($root . '/registrar-receipt.json'), true, flags: JSON_THROW_ON_ERROR);
$sample = kiwe_checkout_domain_result($providerRecord, $providerRecord[0]['domain']);
$taken = kiwe_checkout_domain_result($providerRecord, $providerRecord[1]['domain']);
kiwe_checkout_expect($sample === ['status' => 'eligible', 'verificationRequired' => true], 'Live registrar .in receipt is understood.');
kiwe_checkout_expect($taken === ['status' => 'taken'], 'Live registrar .com receipt is understood.');

require __DIR__ . '/billing-fixture.php';
$gatewayFile = $root . '/data/gateway.json';
file_put_contents($gatewayFile, '{"links":{},"payments":{},"subscriptions":{}}');
$charges = 0;
$startCallback = static function (string $action, array $data) use ($buyer, $draft, &$charges): array {
    kiwe_checkout_expect($data['accountId'] === $buyer['id'], 'Start keeps the verified account ID.');
    if ($action === 'purchases') {
        kiwe_checkout_expect($data['clientOrder']['paidMinor'] === $draft['quote']['upfrontMinor'], 'Start receives the paid quote.');
        $charges++;
        return ['projectId' => '00000000-1221-4111-8111-000000000001'];
    }
    return ['ticket' => str_repeat('a', 43), 'action' => 'http://127.0.0.1:8598/projects/open'];
};
$secret = 'Kiwe checkout isolated webhook fixture secret';
$testCredentials = [
    'merchant' => 'acc_KiweTestFixture', 'webhookSecret' => $secret,
    'plans' => ['direct' => 'plan_DirectFixture', 'referred' => 'plan_ReferralFixture'],
    'mode' => 'test', 'keyId' => 'rzp_test_Fixture', 'keySecret' => str_repeat('t', 24),
    'startOrigin' => 'https://start.kiwelaunch.com', 'startToken' => str_repeat('t', 43),
];
$rejected = false;
try {
    kiwe_checkout_billing($testCredentials, $checkout, $root . '/data/checkout.sqlite', 'https://kiwelaunch.com', false);
} catch (RuntimeException $error) {
    $rejected = $error->getMessage() === 'Kiwe billing is not configured for its declared provider mode.';
}
kiwe_checkout_expect($rejected, 'Razorpay Test Mode cannot create live Start entitlements.');
$billing = new KiweBilling($root . '/data/checkout.sqlite', $checkout, $state['origin'], 'http://127.0.0.1:8598',
    'acc_KiweTestFixture', $secret, ['direct' => 'plan_DirectFixture', 'referred' => 'plan_ReferralFixture'], 120,
    static fn (string $method, string $path, array $payload = []): array => kiwe_test_gateway($root, $method, $path, $payload),
    $startCallback);
$billing->begin($buyer, $draft['revision'], '127.0.0.1');
$gateway = json_decode(file_get_contents($gatewayFile), true, flags: JSON_THROW_ON_ERROR);
$linkId = array_key_first($gateway['links']);
$payment = ['id' => 'pay_HostedFixture', 'amount' => $draft['quote']['upfrontMinor'], 'currency' => 'INR',
    'status' => 'captured', 'amount_refunded' => 0, 'order_id' => 'order_HostedFixture'];
$gateway['payments'][$payment['id']] = $payment;
$gateway['links'][$linkId]['status'] = 'paid';
$gateway['links'][$linkId]['amount_paid'] = $payment['amount'];
$gateway['links'][$linkId]['order_id'] = $payment['order_id'];
file_put_contents($gatewayFile, json_encode($gateway, JSON_THROW_ON_ERROR));
$event = json_encode(['account_id' => 'acc_KiweTestFixture', 'event' => 'payment_link.paid', 'created_at' => time(), 'payload' => [
    'payment_link' => ['entity' => $gateway['links'][$linkId]], 'payment' => ['entity' => $payment],
]], JSON_THROW_ON_ERROR);
$billing->ingest($event, hash_hmac('sha256', $event, $secret), 'hosted-fixture');
$delivery = $billing->deliverPending();
kiwe_checkout_expect($delivery === ['delivered' => 1, 'retry' => 0, 'errors' => []], 'One payment receives a Start project.');
$billingState = $billing->status($buyer['id']);
kiwe_checkout_expect($charges === 1 && strlen($billing->handoff($buyer['id'])['ticket']) === 43, 'Start handoff reuses the receipt.');
$billing->renew($buyer['id'], '127.0.0.1', $draft['quote']['renewalMinor'], $billingState['firstTermEnd'], 120);
$renewal = $billing->status($buyer['id'])['mandate'];
kiwe_checkout_expect($renewal['startsAt'] === $billingState['firstTermEnd'] && $renewal['status'] === 'created',
    'Renewal approval starts as the first year ends.');

echo json_encode(['ok' => true, 'php' => PHP_VERSION, 'accountId' => $buyer['id'], 'draftRevision' => $draft['revision']], JSON_THROW_ON_ERROR), PHP_EOL;
