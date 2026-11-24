<?php

declare(strict_types=1);

function kiwe_checkout_http(string $url, string $bearer, array $input, int $expectedStatus): string
{
    $request = curl_init($url);
    $body = '';
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($input, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearer, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 32000) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    try {
        if (curl_exec($request) !== true || curl_getinfo($request, CURLINFO_RESPONSE_CODE) !== $expectedStatus) {
            throw new KiweCheckoutError('The provider could not respond. Please try again in a moment.', 503);
        }
        return $body;
    } finally {
        curl_close($request);
    }
}

function kiwe_checkout_send_email(string $mailbox, string $token, string $email, string $purpose, string $line): void
{
    $subject = $purpose === 'signup' ? 'Verify your email for Kiwe Launch' : 'Reset your Kiwe Launch password';
    kiwe_checkout_http('https://api.mail.hostinger.com/api/v1/mailboxes/' . rawurlencode($mailbox) . '/send', $token, [
        'to' => [$email],
        'displayName' => 'Kiwe Launch',
        'subject' => $subject,
        'text' => $line,
    ], 204);
}

function kiwe_checkout_domain_result(array $records, string $domain): array
{
    foreach ($records as $record) {
        if (!is_array($record) || !is_string($record['domain'] ?? null) || strtolower($record['domain']) !== $domain) continue;
        $available = $record['is_available'] ?? $record['isAvailable'] ?? null;
        $premium = $record['is_premium'] ?? $record['isPremium'] ?? null;
        $restriction = $record['restriction'] ?? null;
        if (!is_bool($available) || !is_bool($premium) || ($restriction !== null && !is_string($restriction))) break;
        if ($premium) return ['status' => 'premium'];
        if (!$available) return ['status' => 'taken'];
        if ($restriction && $restriction !== 'identity_verification_required') return ['status' => 'restricted'];
        return ['status' => 'eligible', 'verificationRequired' => $restriction === 'identity_verification_required'];
    }
    throw new KiweCheckoutError('Hostinger did not confirm this domain. Please check again.', 503);
}

function kiwe_checkout_hostinger_domain(string $token, string $name, string $extension): array
{
    $body = kiwe_checkout_http('https://developers.hostinger.com/api/domains/v1/availability', $token, [
        'domain' => $name, 'tlds' => [$extension], 'with_alternatives' => false,
    ], 200);
    $rows = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($rows) || !array_is_list($rows)) {
        throw new KiweCheckoutError('Hostinger did not confirm this domain. Please check again.', 503);
    }
    return kiwe_checkout_domain_result($rows, $name . '.' . $extension);
}
