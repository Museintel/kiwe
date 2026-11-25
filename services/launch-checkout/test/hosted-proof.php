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
$readyAt = null;
$fixtureProject = '00000000-1221-4111-8111-000000000001';
$domainConfirmation = null;
$ownedMove = null;
$startCallback = static function (string $action, array $data) use ($buyer, $draft, $fixtureProject, &$readyAt, &$charges, &$domainConfirmation, &$ownedMove): array {
    if ($ownedMove && $data['accountId'] === $ownedMove['accountId']) {
        if ($action === 'purchases') {
            kiwe_checkout_expect($data['clientOrder']['domain'] === ['mode' => 'owned', 'name' => $ownedMove['domain']], 'Keep the owned domain from checkout.');
            return ['projectId' => $ownedMove['projectId']];
        }
        $activate = $action === 'domain-moves/activate';
        $expected = $ownedMove['receipt'];
        if ($activate) $expected += ['finalRecoverySha256' => $ownedMove['finalRecoverySha256'], 'sourceFrozen' => true];
        kiwe_checkout_expect(($activate || $action === 'domain-moves') && $data === $expected, 'Forward only the exact paid move to Start.');
        if ($activate) $ownedMove['activated'] = true;
        $status = empty($ownedMove['activated']) ? 'awaiting_wordpress' : 'active';
        return ['projectId' => $ownedMove['projectId'], 'domainMove' => ['status' => $status, 'targetUrl' => 'https://' . $ownedMove['domain']]];
    }
    kiwe_checkout_expect($data['accountId'] === $buyer['id'], 'Start keeps the verified account ID.');
    if ($action === 'purchases') {
        kiwe_checkout_expect($data['clientOrder']['paidMinor'] === $draft['quote']['upfrontMinor'], 'Start receives the paid quote.');
        $charges++;
        return ['projectId' => $fixtureProject];
    }
    if ($action === 'purchase-delivery') return ['projectId' => $fixtureProject, 'readyAt' => $readyAt];
    if ($action === 'domain-registration') {
        $domainConfirmation = $data;
        return ['projectId' => $fixtureProject];
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

$registrarCalls = 0;
$chargeAttempts = 0;
$registered = false;
$contact = 451;
$activeOwner = $contact;
$verifiedContact = false;
$registrarFixture = static function (string $method, string $path, array $body, bool $allowMissing) use (
    $buyer, $contact, &$registered, &$activeOwner, &$verifiedContact, &$chargeAttempts, &$registrarCalls
): array {
    $registrarCalls++;
    if ($method === 'GET' && str_starts_with($path, '/domains/v1/whois/')) {
        $id = (int) basename($path);
        return ['data' => ['id' => $id, 'tld' => 'in',
            'whois_details' => ['email' => $id === $contact ? $buyer['email'] : 'different-owner@example.invalid']]];
    }
    if ($method === 'GET' && $path === '/domains/v1/portfolio') return [];
    if ($method === 'GET' && $path === '/billing/v1/catalog?category=DOMAIN&name=.IN%2A') {
        return [['name' => '.IN Domain', 'category' => 'DOMAIN', 'prices' => [
            ['id' => 'fixture-inr-1y', 'currency' => 'INR', 'period' => 1, 'period_unit' => 'year',
                'price' => 89900, 'first_period_price' => 54900],
        ]]];
    }
    if ($method === 'POST' && $path === '/domains/v1/portfolio') {
        $chargeAttempts++;
        kiwe_checkout_expect($body === [
            'domain' => 'eligible.in', 'item_id' => 'fixture-inr-1y', 'payment_method_id' => 123,
            'domain_contacts' => ['owner_id' => $contact, 'admin_id' => $contact, 'billing_id' => $contact, 'tech_id' => $contact],
        ], 'Send the approved domain, exact catalogue item and matched WHOIS contact.');
        throw new RuntimeException('Synthetic loss of the purchase response.');
    }
    if ($method === 'GET' && $path === '/domains/v1/portfolio/eligible.in' && $allowMissing) {
        return $registered ? ['domain' => 'eligible.in', 'status' => 'Active',
            'registered_at' => gmdate(DATE_ATOM), 'expires_at' => gmdate(DATE_ATOM, time() + 3600),
            'domain_contacts' => ['owner_id' => $activeOwner],
            'contact_verification' => ['email' => ['status' => $verifiedContact ? 'verified' : 'pending']]] : [];
    }
    throw new RuntimeException('Unexpected provider operation.');
};
$registrar = new KiweRegistration($registrarFixture, 123, 55000, 90000);
$rejectDomain = static function (Closure $request, int $status, string $check): void {
    try {
        $request();
    } catch (KiweCheckoutError $error) {
        kiwe_checkout_expect($error->status === $status, $check);
        return;
    }
    throw new RuntimeException($check);
};
$purchaseId = $billing->status($buyer['id'])['id'];
$rejectDomain(fn () => $billing->registerDomain($registrar, $purchaseId, 'eligible.in', $contact), 409, 'Unconfirmed purchase is denied.');
kiwe_checkout_expect($registrarCalls === 0, 'Unconfirmed purchases do not reach Hostinger.');
$event = json_encode(['account_id' => 'acc_KiweTestFixture', 'event' => 'payment_link.paid', 'created_at' => time(), 'payload' => [
    'payment_link' => ['entity' => $gateway['links'][$linkId]], 'payment' => ['entity' => $payment],
]], JSON_THROW_ON_ERROR);
$billing->ingest($event, hash_hmac('sha256', $event, $secret), 'hosted-fixture');
$delivery = $billing->deliverPending();
kiwe_checkout_expect($delivery === ['delivered' => 1, 'retry' => 0, 'errors' => []], 'One payment receives a Start project.');
$billingState = $billing->status($buyer['id']);
kiwe_checkout_expect($charges === 1 && strlen($billing->handoff($buyer['id'])['ticket']) === 43, 'Start handoff reuses the receipt.');
$rejectDomain(fn () => $billing->registerDomain($registrar, $purchaseId, 'different.in', $contact), 409, 'Wrong paid domain is denied.');
$rejectDomain(fn () => $billing->registerDomain($registrar, $purchaseId, 'eligible.in', $contact + 1), 409, 'Wrong owner contact is denied.');
$belowBudget = new KiweRegistration($registrarFixture, 123, 54000, 90000);
$rejectDomain(fn () => $billing->registerDomain($belowBudget, $purchaseId, 'eligible.in', $contact), 409, 'Above-budget domain is denied.');
$gateway['payments'][$payment['id']]['amount_refunded'] = 1;
file_put_contents($gatewayFile, json_encode($gateway, JSON_THROW_ON_ERROR));
$rejectDomain(fn () => $billing->registerDomain($registrar, $purchaseId, 'eligible.in', $contact), 409, 'Provider refund blocks a registration.');
$gateway['payments'][$payment['id']]['amount_refunded'] = 0;
file_put_contents($gatewayFile, json_encode($gateway, JSON_THROW_ON_ERROR));
kiwe_checkout_expect($chargeAttempts === 0, 'No ineligible request reached the purchase endpoint.');
$rejectDomain(fn () => $billing->registerDomain($registrar, $purchaseId, 'eligible.in', $contact), 503, 'A lost reply keeps the purchase claim.');
kiwe_checkout_expect($billing->registerDomain($registrar, $purchaseId, 'eligible.in', $contact)['state'] === 'awaiting_registration'
    && $chargeAttempts === 1, 'Retrying a claimed purchase polls the original domain and never purchases again.');
$registered = true;
$activeOwner = $contact + 1;
$rejectDomain(fn () => $billing->reconcileDomain($registrar, $purchaseId, 'eligible.in'), 409, 'A different registrar owner cannot confirm this order.');
$activeOwner = $contact;
kiwe_checkout_expect($billing->reconcileDomain($registrar, $purchaseId, 'eligible.in')['state'] === 'awaiting_registration'
    && $domainConfirmation === null, 'Owner verification remains pending.');
$verifiedContact = true;
kiwe_checkout_expect($billing->reconcileDomain($registrar, $purchaseId, 'eligible.in')['state'] === 'registered'
    && $domainConfirmation === ['entitlementId' => $purchaseId, 'accountId' => $buyer['id'], 'purchaseId' => $payment['id'],
        'domain' => 'eligible.in'], 'An active, matched registration delivers only the facts needed by Start.');
$callsAtCompletion = $registrarCalls;
kiwe_checkout_expect($billing->reconcileDomain($registrar, $purchaseId, 'eligible.in')['state'] === 'registered'
    && $registrarCalls === $callsAtCompletion, 'Completed registrations are durable.');
$billing->renew($buyer['id'], '127.0.0.1', $draft['quote']['renewalMinor'], $billingState['firstTermEnd'], 120);
$renewal = $billing->status($buyer['id'])['mandate'];
kiwe_checkout_expect($renewal['startsAt'] === $billingState['firstTermEnd'] && $renewal['status'] === 'created',
    'Renewal approval starts as the first year ends.');

$first = (new DateTimeImmutable('2024-01-31T12:00:00+05:30'))->getTimestamp();
$last = kiwe_billing_month_end($first, 12);
kiwe_checkout_expect(kiwe_billing_month_end($first, 1) === (new DateTimeImmutable('2024-02-29T12:00:00+05:30'))->getTimestamp(),
    'Service months clamp to the last day of shorter months.');
$db = new PDO('sqlite:' . $root . '/data/checkout.sqlite');
$db->prepare('UPDATE launch_payments SET confirmed_at = ?, first_term_end = ? WHERE id = ?')->execute([$first, $last, $billingState['id']]);
$db->prepare('UPDATE launch_renewal_mandates SET start_at = ? WHERE id = ?')->execute([$last, $renewal['id']]);

$partners = $billing->partners();
$partnerId = $partner['id'];
kiwe_checkout_expect($partners->summary($partnerId)['earned'] === 0, 'A paid referral vests nothing before setup is verified.');
kiwe_checkout_expect($partners->reconcileDeliveries() === ['checked' => 1, 'ready' => 0, 'retry' => 0], 'Await a verified Start service.');
$readyAt = $first + 86400;
$db->exec('UPDATE launch_partner_services SET check_after = 0');
kiwe_checkout_expect($partners->reconcileDeliveries() === ['checked' => 1, 'ready' => 1, 'retry' => 0], 'Verify the exact Start service record.');

$send = static function (string $name, array $payload, string $id) use ($billing, $secret): void {
    $raw = json_encode(['account_id' => 'acc_KiweTestFixture', 'event' => $name, 'created_at' => time(), 'payload' => $payload],
        JSON_THROW_ON_ERROR);
    $billing->ingest($raw, hash_hmac('sha256', $raw, $secret), $id);
};
$gateway = json_decode(file_get_contents($gatewayFile), true, flags: JSON_THROW_ON_ERROR);
$subscription = reset($gateway['subscriptions']);
$subscription['status'] = 'active';
$subscription['start_at'] = $last;
$subscription['current_start'] = $last;
$subscription['current_end'] = kiwe_billing_month_end($last, 1);
$monthPayment = ['id' => 'pay_HostedMonth', 'amount' => $draft['quote']['renewalMinor'], 'currency' => 'INR',
    'status' => 'captured', 'amount_refunded' => 0, 'invoice_id' => 'inv_HostedMonth', 'order_id' => 'order_HostedMonth'];
$gateway['invoices'][$monthPayment['invoice_id']] = [
    'id' => $monthPayment['invoice_id'], 'payment_id' => $monthPayment['id'], 'order_id' => $monthPayment['order_id'],
    'subscription_id' => $subscription['id'], 'status' => 'paid', 'currency' => 'INR',
    'amount' => $monthPayment['amount'], 'amount_paid' => $monthPayment['amount'],
    'billing_start' => $last, 'billing_end' => kiwe_billing_month_end($last, 1),
];
file_put_contents($gatewayFile, json_encode($gateway, JSON_THROW_ON_ERROR));
$cycle = ['subscription' => ['entity' => $subscription], 'payment' => ['entity' => $monthPayment]];
$send('subscription.charged', $cycle, 'hosted-month');
$send('subscription.charged', $cycle, 'hosted-month-replay');
kiwe_checkout_expect($partners->summary($partnerId)['available'] === 130000, 'Twelve paid first-term months and one paid renewal each earn INR 100.');

$firstRequest = 'wd_' . str_repeat('1', 32);
$secondRequest = 'wd_' . str_repeat('2', 32);
$partners->requestWithdrawal($partnerId, $firstRequest, 60000);
$partners->requestWithdrawal($partnerId, $secondRequest, 60000);
$partners->reviewWithdrawal($firstRequest, 60000, 'approve');
$partners->reviewWithdrawal($secondRequest, 60000, 'approve');
$partners->recordPayout($firstRequest, 60000, 'UTR-HOSTED-SYNTHETIC');
$reused = false;
try {
    $partners->recordPayout($secondRequest, 60000, 'utr-hosted-synthetic');
} catch (KiweCheckoutError $error) {
    $reused = $error->status === 409 && $error->getMessage() === 'This transfer is already recorded.';
}
kiwe_checkout_expect($reused, 'One external transfer cannot settle two withdrawals.');
$partners->reviewWithdrawal($secondRequest, 60000, 'decline');
kiwe_checkout_expect($partners->summary($partnerId)['available'] === 70000, 'Declining an unpaid request releases its reservation.');

$dispute = ['id' => 'disp_HostedChargeback', 'payment_id' => $monthPayment['id'], 'currency' => 'INR',
    'amount' => $monthPayment['amount'], 'amount_deducted' => $monthPayment['amount']];
$send('payment.dispute.lost', ['dispute' => ['entity' => $dispute]], 'hosted-chargeback');
kiwe_checkout_expect($partners->summary($partnerId)['available'] === 60000, 'A lost chargeback removes the reversed renewal commission.');
$refund = ['id' => 'rfnd_HostedAnnual', 'payment_id' => $payment['id'], 'currency' => 'INR',
    'amount' => intdiv($payment['amount'], 2), 'status' => 'processed'];
$send('refund.processed', ['refund' => ['entity' => $refund]], 'hosted-annual-refund');
kiwe_checkout_expect($partners->summary($partnerId) === ['earned' => 60000, 'available' => 0, 'pending' => 0,
    'paid' => 60000, 'held' => 0, 'adjustmentDue' => 0], 'Refunded charges reduce the available balance after recorded payouts.');

$ownedBuyer = kiwe_checkout_fixture_buyer($checkout, $file, 'domain-owner@example.invalid');
$ownedDomain = 'client-domain.example';
$temporary = 'https://temporary-fixture.hostingersite.com';
$archiveHash = str_repeat('8', 64);
$ownedDraft = $checkout->saveDraft($ownedBuyer, [
    'revision' => '0', 'mode' => 'owned', 'owns_domain' => 'yes', 'domain' => $ownedDomain, 'name' => 'Domain Owner',
    'phone' => '+919876500004', 'billing_address' => '30 Synthetic Lane', 'city' => 'Mumbai',
    'region' => 'Maharashtra', 'postcode' => '400001', 'country' => 'India',
], '127.0.0.1');
$billing->begin($ownedBuyer, $ownedDraft['revision'], '127.0.0.1');
$ownedPurchase = $billing->status($ownedBuyer['id'])['id'];
$ownedState = json_decode(file_get_contents($gatewayFile), true, flags: JSON_THROW_ON_ERROR);
$ownedLink = end($ownedState['links']);
$ownedPayment = ['id' => 'pay_OwnedDomainFixture', 'amount' => $ownedDraft['quote']['upfrontMinor'], 'currency' => 'INR',
    'status' => 'captured', 'amount_refunded' => 0, 'order_id' => 'order_OwnedDomainFixture'];
$ownedLink = [...$ownedLink, 'status' => 'paid', 'amount_paid' => $ownedPayment['amount'], 'order_id' => $ownedPayment['order_id']];
$ownedState['payments'][$ownedPayment['id']] = $ownedPayment;
$ownedState['links'][$ownedLink['id']] = $ownedLink;
file_put_contents($gatewayFile, json_encode($ownedState, JSON_THROW_ON_ERROR));
$ownedMove = [
    'accountId' => $ownedBuyer['id'], 'domain' => $ownedDomain, 'projectId' => '00000000-1221-4111-8111-000000000002',
    'finalRecoverySha256' => str_repeat('9', 64),
    'receipt' => ['entitlementId' => $ownedPurchase, 'accountId' => $ownedBuyer['id'], 'purchaseId' => $ownedPayment['id'],
        'domain' => $ownedDomain, 'sourceUrl' => $temporary, 'recoverySha256' => $archiveHash],
];
$reviewMove = fn () => $billing->reviewDomainMove($ownedPurchase, $ownedDomain, $temporary, $archiveHash);
$finalHash = $ownedMove['finalRecoverySha256'];
$activateMove = fn () => $billing->activateDomainMove($ownedPurchase, $ownedDomain, $temporary, $archiveHash, $finalHash, true);
$rejectDomain($reviewMove, 409, 'An unpaid checkout cannot review a move.');
$rejectDomain($activateMove, 409, 'An unpaid checkout cannot activate a move.');
$send('payment_link.paid', ['payment_link' => ['entity' => $ownedLink], 'payment' => ['entity' => $ownedPayment]], 'owned-domain-fixture');
$rejectDomain($reviewMove, 409, 'The purchase must reach its existing Start project first.');
kiwe_checkout_expect($billing->deliver($ownedBuyer['id']) === $ownedMove['projectId'], 'Deliver the same owned-domain order.');
kiwe_checkout_expect($reviewMove() === ['projectId' => $ownedMove['projectId'],
    'domainMove' => ['status' => 'awaiting_wordpress', 'targetUrl' => 'https://' . $ownedDomain]], 'Expose only the review status and final address.');
$rejectDomain(fn () => $billing->activateDomainMove($ownedPurchase, $ownedDomain, $temporary, $archiveHash, $finalHash, false),
    400, 'Finalization needs a verified write pause at the temporary site.');
$rejectDomain(fn () => $billing->activateDomainMove($ownedPurchase, $ownedDomain, $temporary, $archiveHash, 'unverified', true),
    400, 'Pass a verified final archive digest.');
$activation = ['projectId' => $ownedMove['projectId'], 'domainMove' => ['status' => 'active', 'targetUrl' => 'https://' . $ownedDomain]];
kiwe_checkout_expect($activateMove() === $activation && $activateMove() === $activation && $reviewMove() === $activation,
    'The same paid project reports the final address after activation and replay.');

echo json_encode(['ok' => true, 'php' => PHP_VERSION, 'accountId' => $buyer['id'], 'partnerAccountId' => $partnerId,
    'draftRevision' => $draft['revision']], JSON_THROW_ON_ERROR), PHP_EOL;
