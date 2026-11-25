<?php

declare(strict_types=1);

function kiwe_billing_json_request(string $method, string $url, string $authorization, array $data = [], bool $allowMissing = false, int $timeout = 6): array
{
    $handle = curl_init($url);
    $body = '';
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $authorization, 'Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 262144) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($method === 'POST') $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR);
    curl_setopt_array($handle, $options);
    try {
        $success = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($success && $allowMissing && $status === 404) return [];
        if (!$success || $status < 200 || $status >= 300) {
            throw new KiweCheckoutError('The service could not confirm this step. Check the status and try again.', 503);
        }
        $reply = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($reply)) throw new KiweCheckoutError('The service response was incomplete.', 503);
        return $reply;
    } finally {
        curl_close($handle);
    }
}

function kiwe_billing_gateway(string $keyId, string $keySecret): Closure
{
    $authorization = 'Basic ' . base64_encode($keyId . ':' . $keySecret);
    return static fn (string $method, string $path, array $payload = []): array =>
        kiwe_billing_json_request($method, 'https://api.razorpay.com/v1' . $path, $authorization, $payload);
}

function kiwe_billing_start(string $origin, string $token): Closure
{
    return static fn (string $action, array $payload): array =>
        kiwe_billing_json_request('POST', $origin . '/projects/api/' . $action, 'Bearer ' . $token, $payload,
            timeout: $action === 'domain-moves/activate' ? 28 : 6);
}

function kiwe_billing_id(mixed $id, string $prefix): ?string
{
    return is_string($id) && preg_match('/^' . $prefix . '_[A-Za-z0-9]{4,80}$/D', $id) ? $id : null;
}

function kiwe_billing_hosted_url(mixed $url): ?string
{
    $uri = is_string($url) && strlen($url) <= 250 ? parse_url($url) : false;
    if (!$uri || ($uri['scheme'] ?? '') !== 'https' || !in_array($uri['host'] ?? '', ['rzp.io', 'rzp.in'], true)
        || isset($uri['user']) || isset($uri['pass']) || isset($uri['port']) || isset($uri['query']) || isset($uri['fragment'])
        || !preg_match('~^/[A-Za-z0-9/_-]+$~D', $uri['path'] ?? '')) return null;
    return $url;
}

function kiwe_billing_month_end(int $time, int $months): int
{
    $india = new DateTimeZone('Asia/Kolkata');
    $day = (new DateTimeImmutable('@' . $time))->setTimezone($india);
    $next = $day->modify('first day of this month')->modify('+' . $months . ' months');
    return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), min((int) $day->format('j'), (int) $next->format('t')))
        ->getTimestamp();
}
