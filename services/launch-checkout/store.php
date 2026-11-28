<?php

declare(strict_types=1);

final class KiweCheckoutError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}

function kiwe_checkout_text(mixed $value, string $label, int $max, bool $required = true): string
{
    if (is_string($value)) {
        $value = trim($value);
        $length = preg_match_all('/./us', $value);
        if ($length !== false && ($value !== '' || !$required) && $length <= $max
            && !preg_match('/[\x00-\x1f\x7f]/', $value)) return $value;
    }
    throw new KiweCheckoutError('Check the ' . $label . ' field.');
}

function kiwe_checkout_email(mixed $value): string
{
    $email = strtolower(kiwe_checkout_text($value, 'email', 254));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new KiweCheckoutError('Enter a valid email address.');
    return $email;
}

function kiwe_checkout_phone(mixed $value, string $label, bool $required): string
{
    $phone = kiwe_checkout_text($value, $label, 25, $required);
    if ($phone === '') return '';
    $number = preg_replace('/[\s().-]/', '', $phone);
    if (!preg_match('/^\+?[0-9]{7,15}$/D', $number)) throw new KiweCheckoutError('Enter a valid ' . $label . '.');
    return $number;
}

function kiwe_checkout_domain(mixed $value): string
{
    $domain = strtolower(kiwe_checkout_text($value, 'domain', 254));
    if (str_ends_with($domain, '.')) $domain = substr($domain, 0, -1);
    $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    if (strlen($domain) > 253 || !preg_match('/^' . $label . '(?:\.' . $label . ')*\.[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $domain)
        || filter_var($domain, FILTER_VALIDATE_IP)) {
        throw new KiweCheckoutError('Enter a domain such as yourbusiness.in.');
    }
    return $domain;
}

function kiwe_checkout_token(mixed $value): string
{
    if (is_string($value)) $value = trim($value);
    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
        throw new KiweCheckoutError('This link or code is invalid or expired.', 400);
    }
    return hash('sha256', $value);
}

require_once __DIR__ . '/providers.php';

final class KiweCheckout
{
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$Zkx3ckYzMjdPZEZGd0E2Zw$yi4VVHTErDQfrdFYDq+Arl7zSc5sduBKxJegldPg6gM';

    private PDO $db;
    private Closure $mailer;
    private Closure $registrar;

    public function __construct(
        string $database,
        public readonly array $plan,
        private readonly string $origin,
        private readonly string $rateSecret,
        callable $mailer,
        callable $registrar,
    ) {
        if (strlen($rateSecret) !== 32 || !in_array('argon2id', password_algos(), true)) {
            throw new RuntimeException('Checkout storage or password hashing is unavailable.');
        }
        umask(0077);
        $this->db = new PDO('sqlite:' . $database, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec(file_get_contents(__DIR__ . '/schema.sql'));
        $this->mailer = Closure::fromCallable($mailer);
        $this->registrar = Closure::fromCallable($registrar);
    }

    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function one(string $sql, array $params): ?array
    {
        return $this->run($sql, $params)->fetch() ?: null;
    }

    private function transaction(callable $work): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $this->db->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    public function limit(string $action, string $key, int $max, int $interval): void
    {
        $now = time();
        $digest = hash_hmac('sha256', $action . "\0" . $key, $this->rateSecret);
        $end = intdiv($now, $interval) * $interval + $interval;
        $count = $this->one('INSERT INTO checkout_limits VALUES (?, ?, 1)
            ON CONFLICT(digest, window_end) DO UPDATE SET count = count + 1 RETURNING count', [$digest, $end]);
        $this->run('DELETE FROM checkout_limits WHERE window_end <= ?', [$now]);
        if ((int) $count['count'] > $max) {
            throw new KiweCheckoutError('Too many attempts. Please wait and try again.', 429);
        }
    }

    public function emailLink(string $purpose, string $email, ?string $referral, string $network, string $route = '/checkout/'): void
    {
        if (!in_array($purpose, ['signup', 'reset'], true)) throw new InvalidArgumentException('Unknown link purpose.');
        $email = kiwe_checkout_email($email);
        $this->limit('mail-network', $network, 12, 3600);
        $this->limit('mail-address', $email, 4, 3600);
        $exists = $this->one('SELECT id FROM checkout_accounts WHERE email = ?', [$email]) !== null;
        if ($exists !== ($purpose === 'reset')) return;
        $code = bin2hex(random_bytes(32));
        $digest = kiwe_checkout_token($code);
        $now = time();
        $this->run('DELETE FROM checkout_email_links WHERE expires_at <= ?', [$now]);
        $this->run('INSERT INTO checkout_email_links VALUES (?, ?, ?, ?, ?)',
            [$digest, $email, $purpose, $purpose === 'signup' ? $referral : null, $now + 1800]);
        $url = $this->origin . $route . '#verify=' . $code;
        try {
            ($this->mailer)($email, $purpose, "Open this Kiwe Launch link within 30 minutes:\n{$url}\n\n"
                . "If your browser asks for a code, copy this line:\n{$code}\n\nIf you did not request this, you may ignore this message.");
        } catch (Throwable $error) {
            $this->run('DELETE FROM checkout_email_links WHERE digest = ?', [$digest]);
            throw $error;
        }
    }

    public function linkInfo(?string $digest): ?array
    {
        if (!$digest) return null;
        return $this->one('SELECT email, purpose, referral_digest FROM checkout_email_links WHERE digest = ? AND expires_at > ?',
            [$digest, time()]);
    }

    private function claimReferral(array $account, ?string $digest): void
    {
        if (!$digest || $this->one('SELECT id FROM checkout_referrals WHERE buyer_id = ?', [$account['id']])) return;
        $this->run('UPDATE checkout_referrals SET buyer_id = ? WHERE digest = ? AND client_email = ?
            AND buyer_id IS NULL AND partner_id <> ? AND expires_at > ? AND revoked_at IS NULL',
            [$account['id'], $digest, $account['email'], $account['id'], time()]);
    }

    public function setPassword(string $digest, string $password, ?string $referral): array
    {
        if (!$this->linkInfo($digest)) throw new KiweCheckoutError('This link or code is invalid or expired.', 400);
        if (strlen($password) > 512 || preg_match_all('/./us', $password) < 12 || str_contains($password, "\0")) {
            throw new KiweCheckoutError('Use a password with at least 12 characters and at most 512 bytes.');
        }
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        return $this->transaction(function () use ($digest, $hash, $referral): array {
            $link = $this->linkInfo($digest);
            if (!$link) throw new KiweCheckoutError('This link or code is invalid or expired.', 400);
            $account = $this->one('SELECT * FROM checkout_accounts WHERE email = ?', [$link['email']]);
            if ($link['purpose'] === 'signup') {
                if ($account) throw new KiweCheckoutError('This email already has an account. Sign in to continue.', 409);
                $accountId = 'launch:' . bin2hex(random_bytes(16));
                $this->run('INSERT INTO checkout_accounts (id, email, password_hash, verified_at) VALUES (?, ?, ?, ?)',
                    [$accountId, $link['email'], $hash, time()]);
            } else {
                if (!$account) throw new KiweCheckoutError('This link or code is invalid or expired.', 400);
                $accountId = $account['id'];
                $this->run('UPDATE checkout_accounts SET password_hash = ?, session_version = session_version + 1 WHERE id = ?',
                    [$hash, $accountId]);
            }
            $this->run('DELETE FROM checkout_email_links WHERE email = ?', [$link['email']]);
            $account = $this->one('SELECT * FROM checkout_accounts WHERE id = ?', [$accountId]);
            $this->claimReferral($account, $link['referral_digest'] ?? $referral);
            return $this->accountView($account);
        });
    }

    private function accountView(array $account): array
    {
        return ['id' => $account['id'], 'email' => $account['email'], 'version' => (int) $account['session_version'],
            'partner' => $account['partner_enrolled_at'] !== null];
    }

    public function account(string $id, int $version): ?array
    {
        $account = $this->one('SELECT * FROM checkout_accounts WHERE id = ? AND session_version = ?', [$id, $version]);
        return $account ? $this->accountView($account) : null;
    }

    public function signIn(string $email, string $password, ?string $referral, string $network): array
    {
        $email = kiwe_checkout_email($email);
        $this->limit('login-network', $network, 25, 900);
        $this->limit('login-address', $email, 8, 900);
        $account = $this->one('SELECT * FROM checkout_accounts WHERE email = ?', [$email]);
        if (strlen($password) > 512 || str_contains($password, "\0")
            || !password_verify($password, $account['password_hash'] ?? self::DUMMY_HASH) || !$account) {
            throw new KiweCheckoutError('Check your email and password, or use the reset link.', 401);
        }
        return $this->transaction(function () use ($account, $referral): array {
            $current = $this->account($account['id'], (int) $account['session_version']);
            if (!$current) throw new KiweCheckoutError('Your sign-in changed. Try again.', 401);
            $this->claimReferral($current, $referral);
            return $current;
        });
    }

    public function enrolPartner(string $email): array
    {
        $account = $this->one('UPDATE checkout_accounts SET partner_enrolled_at = COALESCE(partner_enrolled_at, ?)
            WHERE email = ? RETURNING *', [time(), kiwe_checkout_email($email)]);
        if (!$account) throw new KiweCheckoutError('The partner needs a verified Launch account first.', 404);
        return $this->accountView($account);
    }

    public function referralFor(string $buyer): ?array
    {
        return $this->one('SELECT id, whatsapp, domain FROM checkout_referrals WHERE buyer_id = ? AND revoked_at IS NULL', [$buyer]);
    }

    public function acceptReferral(array $account, string $digest): bool
    {
        return $this->transaction(function () use ($account, $digest): bool {
            $this->claimReferral($account, $digest);
            return $this->one('SELECT id FROM checkout_referrals WHERE buyer_id = ? AND digest = ?',
                [$account['id'], $digest]) !== null;
        });
    }

    public function partnerReferrals(array $account): array
    {
        if (!$account['partner']) throw new KiweCheckoutError('Join the Partner Program to see your links.', 403);
        return $this->run('SELECT id, client_email, domain, expires_at, buyer_id IS NOT NULL AS claimed, revoked_at
            FROM checkout_referrals WHERE partner_id = ? ORDER BY created_at DESC, id DESC LIMIT 20', [$account['id']])->fetchAll();
    }

    public function newReferral(array $account, array $input): string
    {
        if (!$account['partner']) throw new KiweCheckoutError('Join the Partner Program to create a link.', 403);
        $email = kiwe_checkout_email($input['email'] ?? '');
        if ($email === $account['email'] || ($input['consent'] ?? '') !== 'yes') {
            throw new KiweCheckoutError('Add your client’s email and confirm their permission.');
        }
        $whatsapp = kiwe_checkout_phone($input['whatsapp'] ?? '', 'WhatsApp number', true);
        $domain = kiwe_checkout_domain($input['domain'] ?? '');
        $this->limit('referral', $account['id'], 20, 3600);
        return $this->transaction(function () use ($account, $email, $whatsapp, $domain): string {
            $now = time();
            $active = $this->one('SELECT id FROM checkout_referrals WHERE partner_id = ? AND client_email = ?
                AND revoked_at IS NULL AND (buyer_id IS NOT NULL OR expires_at > ?)', [$account['id'], $email, $now]);
            if ($active) throw new KiweCheckoutError('You already have a referral for this client.', 409);
            $code = bin2hex(random_bytes(32));
            $this->run('INSERT INTO checkout_referrals (id, partner_id, digest, client_email, whatsapp, domain, created_at, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)', ['ref:' . bin2hex(random_bytes(16)), $account['id'], kiwe_checkout_token($code),
                $email, $whatsapp, $domain, $now, $now + 30 * 86400]);
            return $this->origin . '/checkout/#ref=' . $code;
        });
    }

    public function revokeReferral(array $account, string $id): void
    {
        if (!$account['partner'] || $this->run('UPDATE checkout_referrals SET revoked_at = ? WHERE id = ?
            AND partner_id = ? AND buyer_id IS NULL AND revoked_at IS NULL', [time(), $id, $account['id']])->rowCount() !== 1) {
            throw new KiweCheckoutError('Only the referring partner can revoke an unused link.', 409);
        }
    }

    public function domainAvailability(string $domain): array
    {
        $name = kiwe_checkout_domain($domain);
        $labels = explode('.', $name);
        if (count($labels) !== 2 || $labels[1] !== $this->plan['domain']['includedExtension']) {
            return ['status' => 'excluded'];
        }
        return ($this->registrar)($labels[0], $labels[1]);
    }

    public function draft(string $buyer): ?array
    {
        $draft = $this->one('SELECT id, revision, quote_json, profile_json FROM checkout_drafts WHERE account_id = ?', [$buyer]);
        if (!$draft) return null;
        return ['id' => $draft['id'], 'revision' => (int) $draft['revision'],
            'quote' => json_decode($draft['quote_json'], true, flags: JSON_THROW_ON_ERROR),
            'profile' => json_decode($draft['profile_json'], true, flags: JSON_THROW_ON_ERROR)];
    }

    public function saveDraft(array $account, array $input, string $network): array
    {
        $domain = kiwe_checkout_domain($input['domain'] ?? '');
        $mode = $input['mode'] ?? '';
        if (!in_array($mode, ['owned', 'register'], true)
            || ($mode === 'owned' && ($input['owns_domain'] ?? '') !== 'yes')) {
            throw new KiweCheckoutError('Choose how you’ll use the domain and confirm ownership if you already have it.');
        }
        $fields = ['name' => ['name', 100], 'billing_address' => ['billing address', 180],
            'city' => ['city', 100], 'region' => ['state or region', 100], 'postcode' => ['postal code', 16],
            'country' => ['country', 80]];
        $profile = [
            'email' => $account['email'],
            'phone' => kiwe_checkout_phone($input['phone'] ?? '', 'phone number', true),
            'whatsapp' => kiwe_checkout_phone($input['whatsapp'] ?? '', 'WhatsApp number', false),
        ];
        foreach ($fields as $name => [$label, $max]) $profile[$name] = kiwe_checkout_text($input[$name] ?? '', $label, $max);
        $revision = filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($revision === false || $revision === null) throw new KiweCheckoutError('Reload your checkout and try again.', 409);
        $selection = ['mode' => $mode, 'name' => $domain];
        $availability = [];
        if ($mode === 'register') {
            $this->limit('quote-network', $network, 36, 3600);
            $this->limit('quote-account', $account['id'], 12, 3600);
            $availability = $this->domainAvailability($domain);
            if ($availability['status'] !== 'eligible') {
                $messages = [
                    'premium' => 'This is a premium domain. Choose a standard .in domain or bring a domain you own.',
                    'taken' => 'This domain is already registered. Confirm you own it or choose a different domain.',
                    'restricted' => 'This domain has registration restrictions. Choose another standard .in domain.',
                    'excluded' => 'The plan includes a standard .in domain. You can also bring a domain you own.',
                ];
                throw new KiweCheckoutError($messages[$availability['status']] ?? 'Hostinger could not confirm availability.', 409);
            }
            $selection['category'] = 'standard';
        }
        return $this->transaction(function () use ($account, $profile, $revision, $selection, $availability): array {
            $previous = $this->draft($account['id']);
            if (($previous['revision'] ?? 0) !== $revision) {
                throw new KiweCheckoutError('Checkout changed in another tab. Reload and try again.', 409);
            }
            $referralId = $this->referralFor($account['id'])['id'] ?? null;
            $price = $this->plan[$referralId ? 'referred' : 'direct'];
            $now = time();
            $quote = [
                'planId' => $this->plan['id'], 'currency' => $this->plan['currency'], 'upfrontMinor' => $price['upfrontMinor'],
                'renewalMinor' => $price['renewalMinor'], 'referralId' => $referralId, 'domain' => $selection,
                'registryIdentityRequired' => $availability['verificationRequired'] ?? false,
                'quotedAt' => $now, 'expiresAt' => $now + 900,
            ];
            $id = $previous['id'] ?? 'draft:' . bin2hex(random_bytes(16));
            $this->run('INSERT INTO checkout_drafts VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(account_id)
                DO UPDATE SET revision = excluded.revision, quote_json = excluded.quote_json,
                profile_json = excluded.profile_json, updated_at = excluded.updated_at',
                [$account['id'], $id, $revision + 1, json_encode($quote, JSON_THROW_ON_ERROR),
                    json_encode($profile, JSON_THROW_ON_ERROR), $now]);
            return $this->draft($account['id']);
        });
    }
}
