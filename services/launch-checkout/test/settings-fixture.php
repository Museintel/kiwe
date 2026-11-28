<?php

declare(strict_types=1);

$settings = [
    'enabled' => true,
    'test' => true,
    'origin' => getenv('KIWE_CHECKOUT_TEST_ORIGIN') ?: 'http://127.0.0.1:8597',
    'mailer' => static function (string $email, string $purpose, string $body): void {
        $row = json_encode(['email' => $email, 'purpose' => $purpose, 'body' => $body], JSON_THROW_ON_ERROR);
        if (file_put_contents(__DIR__ . '/data/mail.jsonl', $row . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Fixture mail could not be saved.');
        }
    },
    'registrar' => static function (string $name, string $extension): array {
        $domain = $name . '.' . $extension;
        return kiwe_checkout_domain_result([[
            'domain' => $domain,
            'is_available' => $name !== 'taken',
            'is_premium' => $name === 'premium',
            'restriction' => $name === 'restricted' ? 'unsupported_restriction' : 'identity_verification_required',
        ]], $domain);
    },
];
if ($start = getenv('KIWE_BILLING_TEST_START_ORIGIN')) {
    require_once __DIR__ . '/billing-fixture.php';
    $settings['billing'] = [
        'startOrigin' => $start,
        'merchant' => 'acc_KiweTestFixture',
        'webhookSecret' => 'Kiwe checkout isolated webhook fixture secret',
        'plans' => ['direct' => 'plan_DirectFixture', 'referred' => 'plan_ReferralFixture'],
        'cycles' => 120,
        'razorpay' => static fn (string $method, string $path, array $data = []): array =>
            kiwe_test_gateway(__DIR__, $method, $path, $data),
        'start' => static fn (string $action, array $data): array =>
            kiwe_test_start(__DIR__, $start, getenv('KIWE_BILLING_TEST_START_TOKEN'), $action, $data),
    ];
    if (is_file(__DIR__ . '/launch-catalogue.json')) {
        $settings['nativeWoo'] = true;
        $settings['billing']['plans']['websitePlans'] = [
            'kiwe-portfolio-v1' => 'plan_PortfolioFixture',
            'kiwe-ecommerce-v1' => 'plan_EcommerceFixture',
        ];
        $settings['billing']['plans']['freelancerPass'] = 'plan_AnnualFixture';
        $settings['billing']['plans']['passWebsitePlans'] = ['kiwe-portfolio-v1' => 'plan_PortfolioPassFixture'];
    }
}
return $settings;
