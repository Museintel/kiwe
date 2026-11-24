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

echo json_encode(['ok' => true, 'php' => PHP_VERSION, 'accountId' => $buyer['id'], 'draftRevision' => $draft['revision']], JSON_THROW_ON_ERROR), PHP_EOL;
