<?php

declare(strict_types=1);

final class KiweRegistration
{
    public function __construct(
        private readonly Closure $request,
        private readonly int $paymentMethod,
        private readonly int $firstYearMaxMinor,
        private readonly int $renewalMaxMinor,
    ) {}

    public function prepare(string $domain, int $contact, string $email): string
    {
        $profile = $this->call('GET', '/domains/v1/whois/' . $contact);
        $profileEmail = $profile['whois_details']['email'] ?? null;
        if (($profile['id'] ?? null) !== $contact || ($profile['tld'] ?? null) !== 'in'
            || !is_string($profileEmail) || strcasecmp($profileEmail, $email) !== 0) {
            throw new KiweCheckoutError('The registrar contact must match the verified buyer’s email and .in extension.', 409);
        }
        $portfolio = $this->call('GET', '/domains/v1/portfolio');
        if (!array_is_list($portfolio)) {
            throw new KiweCheckoutError('Hostinger could not confirm the domain portfolio.', 503);
        }
        foreach ($portfolio as $entry) {
            if (is_string($entry['domain'] ?? null) && strcasecmp($entry['domain'], $domain) === 0) {
                throw new KiweCheckoutError('Hostinger already has an order or registration for this domain. Review it before proceeding.', 409);
            }
        }
        $catalogue = $this->call('GET', '/billing/v1/catalog?category=DOMAIN&name=.IN%2A');
        $matches = [];
        foreach ($catalogue as $item) {
            if (($item['name'] ?? null) !== '.IN Domain' || ($item['category'] ?? null) !== 'DOMAIN') continue;
            foreach ($item['prices'] ?? [] as $price) {
                if (($price['currency'] ?? null) === 'INR' && ($price['period'] ?? null) === 1
                    && ($price['period_unit'] ?? null) === 'year') $matches[] = $price;
            }
        }
        $year = $matches[0] ?? [];
        $first = $year['first_period_price'] ?? null;
        $renewal = $year['price'] ?? null;
        $item = $year['id'] ?? null;
        if (count($matches) !== 1 || !is_string($item) || !preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $item)
            || !is_int($first) || $first < 0 || !is_int($renewal) || $renewal < 1
            || ($first ?: $renewal) > $this->firstYearMaxMinor || $renewal > $this->renewalMaxMinor) {
            throw new KiweCheckoutError('The current one-year .in catalogue price needs a registration budget review.', 409);
        }
        return $item;
    }

    public function purchase(string $domain, int $contact, string $item): void
    {
        $this->call('POST', '/domains/v1/portfolio', [
            'domain' => $domain, 'item_id' => $item, 'payment_method_id' => $this->paymentMethod,
            'domain_contacts' => ['owner_id' => $contact, 'admin_id' => $contact, 'billing_id' => $contact, 'tech_id' => $contact],
        ]);
    }

    public function active(string $domain, int $contact, int $requestedAt): bool
    {
        $record = $this->call('GET', '/domains/v1/portfolio/' . rawurlencode($domain), [], true);
        if (!$record) return false;
        $registered = is_string($record['registered_at'] ?? null) ? strtotime($record['registered_at']) : false;
        $expires = is_string($record['expires_at'] ?? null) ? strtotime($record['expires_at']) : false;
        if (($record['domain'] ?? null) !== $domain || ($record['domain_contacts']['owner_id'] ?? null) !== $contact
            || !$registered || $registered < $requestedAt - 60) {
            throw new KiweCheckoutError('Hostinger returned a different registration. Review the domain order in hPanel.', 409);
        }
        $status = $record['status'] ?? '';
        $channels = $record['contact_verification'] ?? [];
        if (!is_string($status) || strcasecmp($status, 'active') !== 0 || !$expires || $expires <= time() || !is_array($channels)) {
            return false;
        }
        foreach ($channels as $channel) {
            if ($channel !== null && (!is_array($channel) || ($channel['status'] ?? null) !== 'verified')) return false;
        }
        return true;
    }

    private function call(string $method, string $path, array $body = [], bool $allowMissing = false): array
    {
        $record = ($this->request)($method, $path, $body, $allowMissing);
        return $record['data'] ?? $record;
    }
}

function kiwe_registration_provider(array $settings, bool $test): ?KiweRegistration
{
    $config = $settings['registration'] ?? null;
    if (!is_array($config) || ($config['enabled'] ?? false) !== true) return null;
    foreach (['paymentMethodId', 'firstYearMaxMinor', 'renewalMaxMinor'] as $key) {
        if (!is_int($config[$key] ?? null) || $config[$key] < 1) {
            throw new KiweCheckoutError('Set the registrar payment method and maximum catalogue costs first.', 503);
        }
    }
    $request = $test ? Closure::fromCallable($config['request'])
        : static function (string $method, string $path, array $body, bool $allowMissing) use ($settings): array {
            return kiwe_billing_json_request($method, 'https://developers.hostinger.com/api' . $path,
                'Bearer ' . $settings['registrarToken'], $body, $allowMissing);
        };
    return new KiweRegistration($request, $config['paymentMethodId'], $config['firstYearMaxMinor'], $config['renewalMaxMinor']);
}
