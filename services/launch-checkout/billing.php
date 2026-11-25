<?php

declare(strict_types=1);

require_once __DIR__ . '/billing-providers.php';

final class KiweBilling
{
    private PDO $db;

    public function __construct(
        string $database,
        private readonly KiweCheckout $checkout,
        private readonly string $origin,
        public readonly string $startOrigin,
        private readonly string $merchant,
        private readonly string $webhookSecret,
        private readonly array $renewalPlans,
        public readonly int $cycles,
        private readonly Closure $razorpay,
        private readonly Closure $start,
    ) {
        umask(0077);
        $this->db = new PDO('sqlite:' . $database, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA busy_timeout = 1500');
        $this->db->exec(file_get_contents(__DIR__ . '/billing-schema.sql'));
    }

    public function partners(): KiwePartners
    {
        require_once __DIR__ . '/partners.php';
        return new KiwePartners($this->db, $this->checkout->plan['monthlyPartnerCommissionMinor'], $this->start);
    }

    private function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        foreach ($params as $offset => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($offset + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }

    private function row(string $sql, array $params): ?array
    {
        return $this->query($sql, $params)->fetch() ?: null;
    }

    private function transaction(Closure $work): mixed
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

    private function active(string $accountId): ?array
    {
        return $this->row('SELECT * FROM launch_payments WHERE account_id = ? AND retired_at IS NULL', [$accountId]);
    }

    private function purchase(string $id): ?array
    {
        return $this->row('SELECT * FROM launch_payments WHERE id = ?', [$id]);
    }

    private function mandate(string $purchase): ?array
    {
        return $this->row('SELECT * FROM launch_renewal_mandates WHERE purchase_id = ? AND retired_at IS NULL', [$purchase]);
    }

    private function quote(array $purchase): array
    {
        return json_decode($purchase['quote'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function refunded(string $paymentId, int $observed = 0): int
    {
        $total = $this->row('SELECT COALESCE(SUM(amount), 0) AS total FROM launch_refunds WHERE payment_id = ?', [$paymentId]);
        return max($observed, (int) $total['total']);
    }

    private function nextRenewalStart(array $purchase): int
    {
        $row = $this->row('SELECT MAX(r.period_end) AS period_end FROM launch_renewal_payments r
            JOIN launch_renewal_mandates m ON m.id = r.mandate_id
            WHERE m.purchase_id = ? AND r.amount_refunded < r.amount
            AND COALESCE((SELECT SUM(amount) FROM launch_refunds WHERE payment_id = r.payment_id), 0) < r.amount', [$purchase['id']]);
        $paidUntil = max($purchase['first_term_end'], (int) ($row['period_end'] ?? 0));
        if ($paidUntil > time() + 1800) return $paidUntil;
        return (new DateTimeImmutable('@' . (time() + 1800)))->setTimezone(new DateTimeZone('Asia/Kolkata'))->modify('tomorrow')->getTimestamp();
    }

    public function status(string $accountId): ?array
    {
        $purchase = $this->active($accountId);
        if (!$purchase) return null;
        $quote = $this->quote($purchase);
        $paid = $purchase['payment_id'] !== null;
        $refunded = $paid ? $this->refunded($purchase['payment_id'], $purchase['payment_refunded']) : 0;
        $mandate = $this->mandate($purchase['id']);
        $periods = $this->query('SELECT r.* FROM launch_renewal_payments r JOIN launch_renewal_mandates m ON m.id = r.mandate_id
            WHERE m.purchase_id = ? ORDER BY r.period_start DESC LIMIT 12', [$purchase['id']])->fetchAll();
        $periods = array_map(fn (array $row): array => [
            'amount' => $row['amount'], 'start' => $row['period_start'], 'end' => $row['period_end'],
            'refunded' => $this->refunded($row['payment_id'], $row['amount_refunded']),
        ], $periods);
        return [
            'id' => $purchase['id'], 'paid' => $paid, 'refunded' => $refunded, 'quote' => $quote,
            'linkUrl' => !$paid && $purchase['link_expires_at'] > time() ? $purchase['link_url'] : null,
            'firstTermEnd' => $purchase['first_term_end'], 'projectId' => $purchase['start_project_id'],
            'renewalStart' => $paid ? $this->nextRenewalStart($purchase) : null,
            'mandate' => $mandate ? [
                'status' => $mandate['status'], 'id' => $mandate['id'],
                'url' => $mandate['status'] === 'created' ? $mandate['authorization_url'] : null,
                'cycles' => $mandate['authorised_cycles'],
                'startsAt' => $mandate['start_at'],
            ] : null,
            'renewals' => $periods,
        ];
    }

    public function begin(array $account, int $revision, string $network): string
    {
        $this->checkout->limit('billing-network', $network, 30, 3600);
        $this->checkout->limit('billing-account', $account['id'], 12, 3600);
        $purchase = $this->active($account['id']);
        if (!$purchase) {
            $draft = $this->checkout->draft($account['id']);
            $referral = $this->checkout->referralFor($account['id'])['id'] ?? null;
            $quote = $draft['quote'] ?? [];
            $terms = $this->checkout->plan[$referral ? 'referred' : 'direct'];
            if (!$draft || $draft['revision'] !== $revision || $quote['expiresAt'] <= time()
                || $quote['referralId'] !== $referral || $quote['planId'] !== $this->checkout->plan['id']
                || $quote['currency'] !== $this->checkout->plan['currency']
                || $quote['upfrontMinor'] !== $terms['upfrontMinor'] || $quote['renewalMinor'] !== $terms['renewalMinor']) {
                throw new KiweCheckoutError('Refresh your checkout details and price before paying.', 409);
            }
            if ($quote['domain']['mode'] === 'register' && $this->checkout->domainAvailability($quote['domain']['name'])['status'] !== 'eligible') {
                throw new KiweCheckoutError('The registrar could not confirm this domain. Choose a new domain and save again.', 409);
            }
            $purchase = $this->transaction(function () use ($account, $draft, $quote): array {
                $existing = $this->active($account['id']);
                if ($existing) return $existing;
                $current = $this->row('SELECT id, revision FROM checkout_drafts WHERE account_id = ?', [$account['id']]);
                $referral = $this->row('SELECT id FROM checkout_referrals WHERE buyer_id = ? AND revoked_at IS NULL', [$account['id']]);
                if (($current['id'] ?? null) !== $draft['id'] || ($current['revision'] ?? null) !== $draft['revision']
                    || ($referral['id'] ?? null) !== $quote['referralId'] || $quote['expiresAt'] <= time()) {
                    throw new KiweCheckoutError('Your checkout changed. Review it and try again.', 409);
                }
                if ($referral) $quote['partnerCommissionMinor'] = $this->checkout->plan['monthlyPartnerCommissionMinor'];
                $id = 'kiwe_' . bin2hex(random_bytes(16));
                $this->query('INSERT INTO launch_payments (id, account_id, draft_id, draft_revision, quote, consent_at, link_expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $account['id'], $draft['id'], $draft['revision'],
                    json_encode($quote, JSON_THROW_ON_ERROR), time(), time() + 1800]);
                return $this->purchase($id);
            });
        }
        if ($purchase['payment_id'] || $purchase['link_expires_at'] <= time()) {
            throw new KiweCheckoutError('Check the saved payment status to continue.', 409);
        }
        if (!$purchase['link_id']) {
            $link = $this->findLink($purchase['id']);
            if (!$link) {
                if ($purchase['link_expires_at'] < time() + 900) throw new KiweCheckoutError('Check the saved payment status to continue.', 409);
                $quote = $this->quote($purchase);
                $link = ($this->razorpay)('POST', '/payment_links', [
                    'amount' => $quote['upfrontMinor'], 'currency' => $quote['currency'], 'reference_id' => $purchase['id'],
                    'accept_partial' => false, 'description' => 'Kiwe Client — first 12 months',
                    'notify' => ['sms' => false, 'email' => false], 'reminder_enable' => false,
                    'expire_by' => $purchase['link_expires_at'],
                    'callback_url' => $this->origin . '/checkout/', 'callback_method' => 'get',
                ]);
            }
            $this->transaction(fn () => $this->saveLink($purchase, $link));
            if (($link['status'] ?? '') !== 'created') throw new KiweCheckoutError('Check the saved payment status to continue.', 409);
            $purchase = $this->purchase($purchase['id']);
        }
        return kiwe_billing_hosted_url($purchase['link_url'])
            ?? throw new KiweCheckoutError('Razorpay did not return a payment link.', 503);
    }

    private function findLink(string $reference): ?array
    {
        $reply = ($this->razorpay)('GET', '/payment_links?reference_id=' . rawurlencode($reference));
        $links = $reply['payment_links'] ?? null;
        if (!is_array($links) || count($links) > 1 || ($links && !is_array($links[0] ?? null))) {
            throw new KiweCheckoutError('Razorpay could not confirm this payment link.', 503);
        }
        return $links[0] ?? null;
    }

    private function saveLink(array $purchase, array $link): void
    {
        $quote = $this->quote($purchase);
        $id = kiwe_billing_id($link['id'] ?? null, 'plink');
        if (!$id || ($link['reference_id'] ?? '') !== $purchase['id']
            || ($link['amount'] ?? null) !== $quote['upfrontMinor'] || ($link['currency'] ?? '') !== $quote['currency']
            || ($link['accept_partial'] ?? null) !== false || ($link['expire_by'] ?? null) !== $purchase['link_expires_at']
            || ($purchase['link_id'] && $purchase['link_id'] !== $id)) {
            throw new KiweCheckoutError('The Razorpay link does not match the checkout.', 409);
        }
        $url = kiwe_billing_hosted_url($link['short_url'] ?? null);
        $this->query('UPDATE launch_payments SET link_id = COALESCE(link_id, ?), link_url = COALESCE(link_url, ?)
            WHERE id = ? AND (link_id IS NULL OR link_id = ?)', [$id, $url, $purchase['id'], $id]);
        if (in_array($link['status'] ?? '', ['expired', 'cancelled'], true) && ($link['amount_paid'] ?? null) === 0) {
            $this->query('UPDATE launch_payments SET retired_at = COALESCE(retired_at, ?) WHERE id = ? AND payment_id IS NULL',
                [time(), $purchase['id']]);
        }
    }

    private function acceptFirstYear(array $purchase, array $link, array $payment): void
    {
        $this->saveLink($purchase, $link);
        $quote = $this->quote($purchase);
        $paymentId = kiwe_billing_id($payment['id'] ?? null, 'pay');
        $order = kiwe_billing_id($link['order_id'] ?? null, 'order');
        if (!$paymentId || !$order || ($payment['order_id'] ?? '') !== $order
            || ($link['status'] ?? '') !== 'paid' || ($link['amount_paid'] ?? null) !== $quote['upfrontMinor']
            || !$this->validPayment($payment, $quote['upfrontMinor'], $quote['currency'])
            || ($purchase['payment_id'] && $purchase['payment_id'] !== $paymentId)) {
            throw new KiweCheckoutError('Razorpay has not confirmed the exact first-year payment.', 409);
        }
        $confirmed = time();
        $this->query('UPDATE launch_payments SET reviewed_at = CASE WHEN payment_id IS NULL THEN 0 ELSE reviewed_at END,
            payment_id = ?, confirmed_at = COALESCE(confirmed_at, ?),
            first_term_end = COALESCE(first_term_end, ?), payment_refunded = MAX(payment_refunded, ?) WHERE id = ?',
            [$paymentId, $confirmed, kiwe_billing_month_end($confirmed, 12), $payment['amount_refunded'] ?? 0, $purchase['id']]);
    }

    private function validPayment(array $payment, int $amount, string $currency): bool
    {
        $refund = $payment['amount_refunded'] ?? 0;
        return in_array($payment['status'] ?? '', ['captured', 'refunded'], true)
            && ($payment['amount'] ?? null) === $amount && ($payment['currency'] ?? '') === $currency
            && is_int($refund) && $refund >= 0 && $refund <= $amount
            && (($payment['status'] ?? '') !== 'refunded' || $refund === $amount);
    }

    public function refresh(string $accountId): ?array
    {
        $purchase = $this->active($accountId);
        if (!$purchase) return null;
        if (!$purchase['payment_id']) {
            $link = $purchase['link_id'] ? ($this->razorpay)('GET', '/payment_links/' . $purchase['link_id'])
                : $this->findLink($purchase['id']);
            if (!$link && !$purchase['link_id'] && $purchase['link_expires_at'] <= time()) {
                $this->query('UPDATE launch_payments SET retired_at = ? WHERE id = ? AND link_id IS NULL AND payment_id IS NULL',
                    [time(), $purchase['id']]);
            } elseif ($link) {
                $this->transaction(fn () => $this->saveLink($purchase, $link));
                if (($link['status'] ?? '') === 'paid' && ($order = kiwe_billing_id($link['order_id'] ?? null, 'order'))) {
                    $quote = $this->quote($purchase);
                    $payments = ($this->razorpay)('GET', '/orders/' . $order . '/payments');
                    $matching = array_filter($payments['items'] ?? [], fn ($row): bool =>
                        is_array($row) && $this->validPayment($row, $quote['upfrontMinor'], $quote['currency']));
                    if (count($matching) !== 1) throw new KiweCheckoutError('Razorpay is still confirming the payment.', 409);
                    $this->transaction(fn () => $this->acceptFirstYear($this->purchase($purchase['id']), $link, reset($matching)));
                }
            }
        }
        if ($mandate = $this->mandate($purchase['id'])) {
            if ($mandate['subscription_id']) {
                $subscription = ($this->razorpay)('GET', '/subscriptions/' . $mandate['subscription_id']);
                $this->transaction(fn () => $this->acceptSubscription($mandate, $subscription, time()));
            }
        }
        return $this->status($accountId);
    }

    public function deliver(string $accountId): ?string
    {
        $purchase = $this->active($accountId);
        if (!$purchase || !$purchase['payment_id']) return null;
        $quote = $this->quote($purchase);
        if ($this->refunded($purchase['payment_id'], $purchase['payment_refunded']) >= $quote['upfrontMinor']) return $purchase['start_project_id'];
        if ($purchase['start_project_id']) return $purchase['start_project_id'];
        $payment = ($this->razorpay)('GET', '/payments/' . $purchase['payment_id']);
        if (($payment['id'] ?? '') !== $purchase['payment_id'] || !$this->validPayment($payment, $quote['upfrontMinor'], $quote['currency'])) {
            throw new KiweCheckoutError('Razorpay is still reconciling your payment.', 503);
        }
        $this->query('UPDATE launch_payments SET payment_refunded = MAX(payment_refunded, ?) WHERE id = ?',
            [$payment['amount_refunded'] ?? 0, $purchase['id']]);
        $purchase = $this->purchase($purchase['id']);
        if ($this->refunded($purchase['payment_id'], $purchase['payment_refunded']) >= $quote['upfrontMinor']) return $purchase['start_project_id'];
        if ($purchase['start_project_id']) return $purchase['start_project_id'];
        $receipt = [
            'entitlementId' => $purchase['id'], 'purchaseId' => $purchase['payment_id'], 'accountId' => $accountId,
            'accountType' => 'client', 'planId' => $quote['planId'], 'hostingIncluded' => true,
            'clientOrder' => [
                'currency' => $quote['currency'], 'paidMinor' => $quote['upfrontMinor'], 'renewalMinor' => $quote['renewalMinor'],
                'referralId' => $quote['referralId'], 'domain' => $quote['domain'],
            ],
        ];
        $result = ($this->start)('purchases', $receipt);
        $project = $result['projectId'] ?? null;
        if (!is_string($project) || !preg_match('/^[a-f0-9-]{36}$/D', $project)) {
            throw new KiweCheckoutError('Start is still opening your project. Try again shortly.', 503);
        }
        $this->query('UPDATE launch_payments SET start_project_id = ? WHERE id = ? AND start_project_id IS NULL', [$project, $purchase['id']]);
        return $this->purchase($purchase['id'])['start_project_id'];
    }

    public function handoff(string $accountId): array
    {
        if (!$this->deliver($accountId)) throw new KiweCheckoutError('Start opens after the first-year payment is confirmed.', 409);
        $purchase = $this->active($accountId);
        $reply = ($this->start)('handoffs', ['entitlementId' => $purchase['id'], 'accountId' => $accountId]);
        $action = $this->startOrigin . '/projects/open';
        if (($reply['action'] ?? '') !== $action || !is_string($reply['ticket'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $reply['ticket'])) {
            throw new KiweCheckoutError('Start did not confirm a sign-in ticket.', 503);
        }
        return ['ticket' => $reply['ticket'], 'action' => $action];
    }

    private function domainPurchase(string $purchaseId, string $domain, string $mode = 'register'): array
    {
        if (!preg_match('/^kiwe_[a-f0-9]{32}$/D', $purchaseId)) {
            throw new KiweCheckoutError('Enter a confirmed Kiwe purchase ID.');
        }
        $purchase = $this->purchase($purchaseId);
        $quote = $purchase ? $this->quote($purchase) : [];
        $selection = ['mode' => $mode, 'name' => $domain];
        if ($mode === 'register') $selection['category'] = 'standard';
        if (!$purchase || $purchase['retired_at'] || !$purchase['confirmed_at'] || !$purchase['start_project_id']
            || ($mode === 'register' && $purchase['first_term_end'] <= time())
            || ($quote['planId'] ?? null) !== $this->checkout->plan['id'] || ($quote['domain'] ?? null) !== $selection) {
            throw new KiweCheckoutError('Use the exact domain and a confirmed purchase that was delivered to Start.', 409);
        }
        $dispute = $this->row('SELECT COALESCE(SUM(deducted + held), 0) AS withheld FROM launch_disputes WHERE payment_id = ?',
            [$purchase['payment_id']]);
        if ($this->refunded($purchase['payment_id'], $purchase['payment_refunded']) > 0 || $dispute['withheld'] > 0) {
            throw new KiweCheckoutError('Domain setup is on hold while Launch reviews the payment.', 409);
        }
        return $purchase;
    }

    public function reviewDomainMove(string $purchaseId, string $domain, string $sourceUrl, string $recoverySha256): array
    {
        return $this->domainMove($purchaseId, $domain, $sourceUrl, $recoverySha256);
    }

    public function activateDomainMove(
        string $purchaseId, string $domain, string $sourceUrl, string $recoverySha256, string $finalRecoverySha256, bool $sourceFrozen
    ): array {
        if (!$sourceFrozen || !preg_match('/^[a-f0-9]{64}$/D', $finalRecoverySha256)) {
            throw new KiweCheckoutError('Confirm that writes at the temporary site are paused and provide the verified final-copy digest.', 400);
        }
        return $this->domainMove($purchaseId, $domain, $sourceUrl, $recoverySha256, $finalRecoverySha256);
    }

    private function domainMove(
        string $purchaseId, string $domain, string $sourceUrl, string $recoverySha256, ?string $finalRecoverySha256 = null
    ): array
    {
        if (!preg_match('~^https://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.hostingersite\.com$~D', $sourceUrl)
            || !preg_match('/^[a-f0-9]{64}$/D', $recoverySha256)) {
            throw new KiweCheckoutError('Provide the exact temporary site and the verified recovery digest.', 400);
        }
        $purchase = $this->domainPurchase($purchaseId, $domain, 'owned');
        $payload = [
            'entitlementId' => $purchaseId, 'accountId' => $purchase['account_id'], 'purchaseId' => $purchase['payment_id'],
            'domain' => $domain, 'sourceUrl' => $sourceUrl, 'recoverySha256' => $recoverySha256,
        ];
        if ($finalRecoverySha256 !== null) {
            $payload['finalRecoverySha256'] = $finalRecoverySha256;
            $payload['sourceFrozen'] = true;
        }
        $reply = ($this->start)($finalRecoverySha256 === null ? 'domain-moves' : 'domain-moves/activate', $payload);
        $target = $reply['domainMove']['targetUrl'] ?? null;
        $status = $reply['domainMove']['status'] ?? null;
        $allowed = $finalRecoverySha256 === null ? ['awaiting_wordpress', 'wordpress_verified', 'key_verified', 'activating', 'active'] : ['active'];
        if (($reply['projectId'] ?? null) !== $purchase['start_project_id'] || $target !== 'https://' . $domain
            || !in_array($status, $allowed, true)) {
            throw new KiweCheckoutError('Start could not confirm this domain move.', 503);
        }
        return ['projectId' => $purchase['start_project_id'], 'domainMove' => ['status' => $status, 'targetUrl' => $target]];
    }

    public function registerDomain(KiweRegistration $registrar, string $purchaseId, string $domain, int $contact): array
    {
        $purchase = $this->domainPurchase($purchaseId, $domain);
        $attempt = $this->row('SELECT contact_id FROM launch_domain_orders WHERE purchase_id = ?', [$purchaseId]);
        if ($attempt) {
            if ($attempt['contact_id'] !== $contact) throw new KiweCheckoutError('Review the original registrar contact in hPanel.', 409);
            return $this->reconcileDomain($registrar, $purchaseId, $domain);
        }
        $account = $this->row('SELECT email FROM checkout_accounts WHERE id = ?', [$purchase['account_id']]);
        $item = $registrar->prepare($domain, $contact, $account['email']);
        if (($this->checkout->domainAvailability($domain)['status'] ?? null) !== 'eligible') {
            throw new KiweCheckoutError('Hostinger must confirm the domain is still available and eligible before purchase.', 409);
        }
        $payment = ($this->razorpay)('GET', '/payments/' . $purchase['payment_id']);
        $quote = $this->quote($purchase);
        if (($payment['id'] ?? null) !== $purchase['payment_id'] || ($payment['status'] ?? null) !== 'captured'
            || !$this->validPayment($payment, $quote['upfrontMinor'], $quote['currency'])
            || ($payment['amount_refunded'] ?? 0) !== 0) {
            throw new KiweCheckoutError('Razorpay must confirm the original captured payment before registration.', 409);
        }
        $claimed = $this->transaction(function () use ($purchaseId, $domain, $contact): bool {
            $this->domainPurchase($purchaseId, $domain);
            return $this->query('INSERT INTO launch_domain_orders(purchase_id, domain, contact_id, requested_at)
                VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING', [$purchaseId, $domain, $contact, time()])->rowCount() === 1;
        });
        if (!$claimed) {
            throw new KiweCheckoutError('A request already exists for this domain. Review or reconcile the original request.', 409);
        }
        try {
            $registrar->purchase($domain, $contact, $item);
        } catch (Throwable) {
            throw new KiweCheckoutError('The registrar response was inconclusive. Check hPanel and reconcile this order before any further purchase.', 503);
        }
        return $this->reconcileDomain($registrar, $purchaseId, $domain);
    }

    public function reconcileDomain(KiweRegistration $registrar, string $purchaseId, string $domain): array
    {
        $purchase = $this->domainPurchase($purchaseId, $domain);
        $attempt = $this->row('SELECT * FROM launch_domain_orders WHERE purchase_id = ? AND domain = ?', [$purchaseId, $domain]);
        if (!$attempt) throw new KiweCheckoutError('This purchase has no saved registration request.', 404);
        $result = ['domain' => $domain, 'state' => 'awaiting_registration'];
        if ($attempt['completed_at']) return [...$result, 'state' => 'registered'];
        if (!$registrar->active($domain, $attempt['contact_id'], $attempt['requested_at'])) return $result;
        $this->domainPurchase($purchaseId, $domain);
        $receipt = ($this->start)('domain-registration', [
            'entitlementId' => $purchaseId, 'accountId' => $purchase['account_id'], 'purchaseId' => $purchase['payment_id'],
            'domain' => $domain,
        ]);
        if (($receipt['projectId'] ?? null) !== $purchase['start_project_id']) {
            throw new KiweCheckoutError('Start did not confirm this registration. Reconcile the saved request again.', 503);
        }
        $this->query('UPDATE launch_domain_orders SET completed_at = ? WHERE purchase_id = ? AND completed_at IS NULL', [time(), $purchaseId]);
        return [...$result, 'state' => 'registered'];
    }

    public function renew(string $accountId, string $network, int $quotedAmount, int $quotedStart, int $quotedCycles): string
    {
        $this->checkout->limit('renewal-network', $network, 30, 3600);
        $this->checkout->limit('renewal-account', $accountId, 12, 3600);
        $purchase = $this->active($accountId);
        if (!$purchase || !$purchase['payment_id']) {
            throw new KiweCheckoutError('Renewal approval opens after the first-year payment is confirmed.', 409);
        }
        $quote = $this->quote($purchase);
        if ($quotedAmount !== $quote['renewalMinor'] || $quotedStart !== $this->nextRenewalStart($purchase) || $quotedCycles !== $this->cycles) {
            throw new KiweCheckoutError('Review the updated renewal terms before approving them.', 409);
        }
        if ($this->refunded($purchase['payment_id'], $purchase['payment_refunded']) >= $quote['upfrontMinor']) {
            throw new KiweCheckoutError('This first-year payment was refunded.', 409);
        }
        $mandate = $this->mandate($purchase['id']);
        if ($mandate) {
            if ($mandate['status'] === 'created' && ($url = kiwe_billing_hosted_url($mandate['authorization_url']))) return $url;
            if (!self::terminalMandate($mandate['status'])) {
                throw new KiweCheckoutError('Check the status of your existing renewal approval.', 409);
            }
        }
        $planId = $this->renewalPlans[$quote['referralId'] ? 'referred' : 'direct'];
        $plan = ($this->razorpay)('GET', '/plans/' . $planId);
        if (($plan['id'] ?? '') !== $planId || ($plan['period'] ?? '') !== 'monthly' || ($plan['interval'] ?? 0) !== 1
            || ($plan['item']['amount'] ?? null) !== $quote['renewalMinor'] || ($plan['item']['currency'] ?? '') !== $quote['currency']) {
            throw new KiweCheckoutError('The Razorpay renewal plan needs to be checked.', 503);
        }
        $reference = $this->transaction(function () use ($purchase, $planId, $quote, $quotedStart): string {
            $previous = $this->mandate($purchase['id']);
            if ($previous && !self::terminalMandate($previous['status'])) {
                throw new KiweCheckoutError('A renewal approval is already being created.', 409);
            }
            if ($this->refunded($purchase['payment_id'], $purchase['payment_refunded']) >= $quote['upfrontMinor']) {
                throw new KiweCheckoutError('This first-year payment was refunded.', 409);
            }
            if ($quotedStart !== $this->nextRenewalStart($purchase)) {
                throw new KiweCheckoutError('Review the updated renewal date before approving it.', 409);
            }
            if ($previous) $this->query('UPDATE launch_renewal_mandates SET retired_at = ? WHERE id = ?', [time(), $previous['id']]);
            $id = 'mandate_' . bin2hex(random_bytes(12));
            $this->query('INSERT INTO launch_renewal_mandates (id, purchase_id, plan_id, amount, start_at, authorised_cycles, consent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $purchase['id'], $planId, $quote['renewalMinor'], $quotedStart, $this->cycles, time()]);
            return $id;
        });
        $reply = ($this->razorpay)('POST', '/subscriptions', [
            'plan_id' => $planId, 'quantity' => 1, 'total_count' => $this->cycles, 'customer_notify' => false,
            'start_at' => $quotedStart, 'expire_by' => min(time() + 7 * 86400, $quotedStart - 900),
            'notes' => ['kiwe_mandate' => $reference],
        ]);
        $this->transaction(fn () => $this->acceptSubscription($this->mandate($purchase['id']), $reply, time()));
        return kiwe_billing_hosted_url($reply['short_url'] ?? null)
            ?? throw new KiweCheckoutError('Razorpay did not return a renewal approval link.', 503);
    }

    private function acceptSubscription(array $mandate, array $subscription, int $occurred): void
    {
        $id = kiwe_billing_id($subscription['id'] ?? null, 'sub');
        $status = $subscription['status'] ?? '';
        if (!$id || ($mandate['subscription_id'] && $mandate['subscription_id'] !== $id)
            || ($subscription['notes']['kiwe_mandate'] ?? '') !== $mandate['id']
            || ($subscription['plan_id'] ?? '') !== $mandate['plan_id'] || ($subscription['start_at'] ?? null) !== $mandate['start_at']
            || ($subscription['quantity'] ?? null) !== 1 || ($subscription['total_count'] ?? null) !== $mandate['authorised_cycles']
            || !in_array($status, ['created', 'authenticated', 'active', 'pending', 'halted', 'paused', 'cancelled', 'completed', 'expired'], true)) {
            throw new KiweCheckoutError('The renewal approval does not match the Kiwe plan.', 409);
        }
        $this->query("UPDATE launch_renewal_mandates SET subscription_id = ?, authorization_url = COALESCE(?, authorization_url),
            status = CASE WHEN status IN ('cancelled', 'completed', 'expired') THEN status
                WHEN ? IN ('cancelled', 'completed', 'expired') OR status_at <= ? THEN ? ELSE status END, status_at = MAX(status_at, ?) WHERE id = ?",
            [$id, kiwe_billing_hosted_url($subscription['short_url'] ?? null), $status,
                $occurred, $status, $occurred, $mandate['id']]);
    }

    public function recoverMandate(string $accountId, string $subscriptionId): void
    {
        $purchase = $this->active($accountId);
        $mandate = $purchase ? $this->mandate($purchase['id']) : null;
        if (!$mandate || !kiwe_billing_id($subscriptionId, 'sub')) throw new KiweCheckoutError('Enter the matching Razorpay subscription.', 422);
        $subscription = ($this->razorpay)('GET', '/subscriptions/' . $subscriptionId);
        $this->transaction(fn () => $this->acceptSubscription($mandate, $subscription, time()));
    }

    public static function terminalMandate(string $status): bool
    {
        return in_array($status, ['cancelled', 'completed', 'expired'], true);
    }

    public function cancelRenewal(string $accountId): void
    {
        $purchase = $this->active($accountId);
        $mandate = $purchase ? $this->mandate($purchase['id']) : null;
        if (!$mandate || !$mandate['subscription_id']) throw new KiweCheckoutError('Check the renewal approval first.', 409);
        if (self::terminalMandate($mandate['status'])) return;
        $subscription = ($this->razorpay)('POST', '/subscriptions/' . $mandate['subscription_id'] . '/cancel', ['cancel_at_cycle_end' => false]);
        if (($subscription['status'] ?? '') !== 'cancelled') throw new KiweCheckoutError('Razorpay is still confirming the cancellation.', 503);
        $this->transaction(fn () => $this->acceptSubscription($mandate, $subscription, time()));
    }

    private function subscriptionMandate(array $subscription): ?array
    {
        return $this->row('SELECT * FROM launch_renewal_mandates WHERE id = ? OR subscription_id = ?',
            [$subscription['notes']['kiwe_mandate'] ?? '', kiwe_billing_id($subscription['id'] ?? null, 'sub')]);
    }

    private function acceptRenewal(array $mandate, string $subscriptionId, array $payment, array $invoice): void
    {
        $start = $invoice['billing_start'] ?? null;
        $end = $invoice['billing_end'] ?? null;
        $paymentId = kiwe_billing_id($payment['id'] ?? null, 'pay');
        $orderId = kiwe_billing_id($payment['order_id'] ?? null, 'order');
        if (!$paymentId || !$orderId || ($invoice['id'] ?? null) !== ($payment['invoice_id'] ?? null)
            || ($invoice['payment_id'] ?? null) !== $paymentId || ($invoice['order_id'] ?? null) !== $orderId
            || ($invoice['subscription_id'] ?? null) !== $subscriptionId
            || ($invoice['status'] ?? null) !== 'paid' || ($invoice['currency'] ?? null) !== 'INR'
            || ($invoice['amount'] ?? null) !== $mandate['amount'] || ($invoice['amount_paid'] ?? null) !== $mandate['amount']
            || !$this->validPayment($payment, $mandate['amount'], 'INR')
            || !is_int($start) || !is_int($end) || $start < $mandate['start_at'] || $end <= $start || $end - $start > 32 * 86400) {
            throw new KiweCheckoutError('The paid renewal invoice does not match the approved amount or period.', 409);
        }

        $recorded = $this->row('SELECT payment_id, mandate_id, period_start, period_end FROM launch_renewal_payments
            WHERE payment_id = ? OR (mandate_id = ? AND period_start = ?)', [$paymentId, $mandate['id'], $start]);
        if ($recorded && ($recorded['payment_id'] !== $paymentId || $recorded['mandate_id'] !== $mandate['id']
            || $recorded['period_start'] !== $start || $recorded['period_end'] !== $end)) {
            throw new KiweCheckoutError('A different payment or period is already recorded for this renewal.', 409);
        }

        $this->query('INSERT INTO launch_renewal_payments VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(payment_id) DO UPDATE SET amount_refunded = MAX(amount_refunded, excluded.amount_refunded)',
            [$paymentId, $mandate['id'], $mandate['amount'], $payment['amount_refunded'] ?? 0, $start, $end]);
    }

    private function acceptDispute(string $event, array $dispute, int $time): void
    {
        $phase = match ($event) {
            'payment.dispute.created', 'payment.dispute.under_review', 'payment.dispute.action_required' => 'pending',
            'payment.dispute.won', 'payment.dispute.closed' => 'resolved',
            'payment.dispute.lost' => 'lost',
            default => null,
        };
        if (!$phase) return;
        $id = kiwe_billing_id($dispute['id'] ?? null, 'disp');
        $payment = kiwe_billing_id($dispute['payment_id'] ?? null, 'pay');
        $amount = $dispute['amount'] ?? null;
        $deducted = $dispute['amount_deducted'] ?? null;
        if (!$id || !$payment || ($dispute['currency'] ?? '') !== 'INR' || !is_int($amount) || $amount < 1
            || !is_int($deducted) || $deducted < 0 || $deducted > $amount) {
            throw new KiweCheckoutError('Invalid payment dispute details.', 422);
        }
        $held = $phase === 'pending' ? $amount - $deducted : 0;
        if ($phase === 'lost' && $deducted === 0) $deducted = $amount;
        $this->query('INSERT INTO launch_disputes VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(dispute_id) DO UPDATE SET deducted = excluded.deducted, held = excluded.held,
                terminal = excluded.terminal, occurred_at = excluded.occurred_at
            WHERE launch_disputes.payment_id = excluded.payment_id AND (excluded.occurred_at > launch_disputes.occurred_at
                OR (excluded.occurred_at = launch_disputes.occurred_at AND excluded.terminal > launch_disputes.terminal))',
            [$id, $payment, $deducted, $held, (int) ($phase !== 'pending'), $time]);
    }

    public function ingest(string $raw, string $signature, string $eventId): void
    {
        if (!preg_match('/^[a-fA-F0-9]{64}$/D', $signature)
            || !hash_equals(hash_hmac('sha256', $raw, $this->webhookSecret), strtolower($signature))) {
            throw new KiweCheckoutError('Invalid webhook signature.', 401);
        }
        $event = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $name = $event['event'] ?? '';
        if (($event['account_id'] ?? '') !== $this->merchant) throw new KiweCheckoutError('Invalid merchant.', 403);
        if (!is_string($name)) throw new KiweCheckoutError('Invalid billing event.', 422);
        $hash = hash('sha256', $raw);
        if ($eventId === '') $eventId = $hash;
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $eventId)) throw new KiweCheckoutError('Invalid event identifier.', 422);
        $time = $event['created_at'] ?? null;
        if (!is_int($time) || $time <= 0 || $time > time() + 300) throw new KiweCheckoutError('Invalid event date.', 422);
        if ($this->receivedEvent($eventId, $hash)) return;

        $payload = $event['payload'] ?? [];
        $invoice = [];
        if ($name === 'subscription.charged' && $this->subscriptionMandate($payload['subscription']['entity'] ?? [])) {
            $id = kiwe_billing_id($payload['payment']['entity']['invoice_id'] ?? null, 'inv');
            if (!$id) throw new KiweCheckoutError('The renewal payment did not identify its invoice.', 409);
            // Razorpay delivers a current subscription snapshot, which can have advanced past this payment.
            // Read the invoice before opening the SQLite write transaction.
            $invoice = ($this->razorpay)('GET', '/invoices/' . $id, [], 2);
        }

        $this->transaction(function () use ($payload, $name, $hash, $eventId, $time, $invoice): void {
            if ($this->receivedEvent($eventId, $hash)) return;
            $this->applyEvent($name, $payload, $time, $invoice);
            $this->query('INSERT INTO launch_billing_events VALUES (?, ?, ?)', [$eventId, $hash, time()]);
            $this->query('DELETE FROM launch_billing_events WHERE received_at < ?', [time() - 90 * 86400]);
        });
    }

    private function receivedEvent(string $eventId, string $digest): bool
    {
        $known = $this->row('SELECT digest FROM launch_billing_events WHERE id = ?', [$eventId]);
        if ($known && $known['digest'] !== $digest) throw new KiweCheckoutError('Conflicting billing event.', 409);
        return $known !== null;
    }

    private function applyEvent(string $event, array $payload, int $time, array $invoice): void
    {
        if (in_array($event, ['payment_link.paid', 'payment_link.expired', 'payment_link.cancelled'], true)) {
            $link = $payload['payment_link']['entity'] ?? [];
            $purchase = $this->purchase($link['reference_id'] ?? '');
            if (!$purchase) return;
            if ($event === 'payment_link.paid') $this->acceptFirstYear($purchase, $link, $payload['payment']['entity'] ?? []);
            else $this->saveLink($purchase, $link);
        } elseif (str_starts_with($event, 'subscription.')) {
            $subscription = $payload['subscription']['entity'] ?? [];
            $mandate = $this->subscriptionMandate($subscription);
            if (!$mandate) return;
            $this->acceptSubscription($mandate, $subscription, $time);
            if ($event === 'subscription.charged') $this->acceptRenewal($mandate, $subscription['id'], $payload['payment']['entity'] ?? [], $invoice);
        } elseif (str_starts_with($event, 'payment.dispute.')) {
            $this->acceptDispute($event, $payload['dispute']['entity'] ?? [], $time);
        } elseif ($event === 'refund.processed') {
            $refund = $payload['refund']['entity'] ?? [];
            $id = kiwe_billing_id($refund['id'] ?? null, 'rfnd');
            $payment = kiwe_billing_id($refund['payment_id'] ?? null, 'pay');
            $amount = $refund['amount'] ?? null;
            if (!$id || !$payment || !is_int($amount) || $amount < 1 || ($refund['status'] ?? '') !== 'processed'
                || ($refund['currency'] ?? '') !== 'INR') throw new KiweCheckoutError('Invalid refund details.', 422);
            $this->query('INSERT INTO launch_refunds VALUES (?, ?, ?) ON CONFLICT(refund_id) DO NOTHING', [$id, $payment, $amount]);
            $snapshot = $payload['payment']['entity'] ?? [];
            $total = $snapshot['amount_refunded'] ?? null;
            if (($snapshot['id'] ?? '') === $payment && ($snapshot['currency'] ?? '') === 'INR' && is_int($total)
                && $total >= $amount && $total <= ($snapshot['amount'] ?? 0)) {
                $this->query('UPDATE launch_payments SET payment_refunded = MAX(payment_refunded, ?) WHERE payment_id = ?', [$total, $payment]);
                $this->query('UPDATE launch_renewal_payments SET amount_refunded = MAX(amount_refunded, ?) WHERE payment_id = ?', [$total, $payment]);
            }
        }
    }

    public function deliverPending(): array
    {
        $pending = $this->query('SELECT id, account_id, payment_id FROM launch_payments WHERE start_project_id IS NULL
            AND retired_at IS NULL AND reviewed_at < ? AND payment_refunded < json_extract(quote, \'$.upfrontMinor\')
            AND COALESCE((SELECT SUM(amount) FROM launch_refunds WHERE payment_id = launch_payments.payment_id), 0)
                < json_extract(quote, \'$.upfrontMinor\') ORDER BY reviewed_at LIMIT 2', [time() - 180])->fetchAll();
        $delivered = 0;
        $retry = 0;
        $errors = [];
        foreach ($pending as $row) {
            if ($this->query('UPDATE launch_payments SET reviewed_at = ? WHERE id = ? AND reviewed_at < ?',
                [time(), $row['id'], time() - 180])->rowCount() !== 1) continue;
            try {
                if (!$row['payment_id']) $this->refresh($row['account_id']);
                if ($this->deliver($row['account_id'])) $delivered++;
            } catch (Throwable $error) {
                $retry++;
                $errors[] = $error instanceof KiweCheckoutError ? $error->getMessage() : 'Launch billing could not reconcile this record.';
            }
        }
        return ['delivered' => $delivered, 'retry' => $retry, 'errors' => $errors];
    }
}
