<?php

declare(strict_types=1);

return [
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
