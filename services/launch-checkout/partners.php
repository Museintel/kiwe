<?php

declare(strict_types=1);

final class KiwePartners
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $commission,
        private readonly Closure $start,
    ) {
        $this->db->exec(file_get_contents(__DIR__ . '/partner-schema.sql'));
    }

    private function query(string $sql, array $params): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function transaction(Closure $work, bool $write = true): mixed
    {
        $this->db->exec($write ? 'BEGIN IMMEDIATE' : 'BEGIN DEFERRED');
        try {
            $result = $work();
            $this->db->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    private function requirePartner(string $partnerId): void
    {
        if (!$this->query('SELECT 1 FROM checkout_accounts WHERE id = ? AND partner_enrolled_at IS NOT NULL', [$partnerId])->fetchColumn()) {
            throw new KiweCheckoutError('Join the Partner Program to see your earnings.', 403);
        }
    }

    public function reconcileDeliveries(): array
    {
        $now = time();
        $orders = $this->query('SELECT purchase_id, account_id, start_project_id FROM launch_partner_orders
            WHERE ready_at IS NULL AND start_project_id IS NOT NULL AND check_after <= CAST(? AS INTEGER)
            ORDER BY check_after, confirmed_at, purchase_id LIMIT 4', [$now])->fetchAll();
        $result = ['checked' => 0, 'ready' => 0, 'retry' => 0];
        foreach ($orders as $order) {
            $claim = $this->query('INSERT INTO launch_partner_services (purchase_id, check_after) VALUES (?, ?)
                ON CONFLICT(purchase_id) DO UPDATE SET check_after = excluded.check_after
                WHERE ready_at IS NULL AND check_after <= ?', [$order['purchase_id'], $now + 600, $now]);
            if (!$claim->rowCount()) continue;
            $result['checked']++;
            try {
                $delivery = ($this->start)('purchase-delivery', [
                    'entitlementId' => $order['purchase_id'], 'accountId' => $order['account_id'],
                ]);
                $ready = $delivery['readyAt'] ?? null;
                if (($delivery['projectId'] ?? '') !== $order['start_project_id']
                    || !array_key_exists('readyAt', $delivery)
                    || ($ready !== null && (!is_int($ready) || $ready < 1 || $ready > time()))) {
                    throw new RuntimeException('Start returned an invalid delivery record.');
                }
                if ($ready !== null) {
                    $this->query('UPDATE launch_partner_services SET ready_at = ? WHERE purchase_id = ? AND ready_at IS NULL',
                        [$ready, $order['purchase_id']]);
                    $result['ready']++;
                }
            } catch (Throwable) {
                $result['retry']++;
            }
        }
        return $result;
    }

    private function earnings(string $partnerId, int $now): array
    {
        $payments = $this->query(<<<'SQL'
            WITH orders AS (SELECT * FROM launch_partner_orders WHERE partner_id = ? AND ready_at IS NOT NULL),
            periods AS (
                SELECT referral_id, ready_at, commission, payment_id, payment_refunded AS observed,
                    amount, confirmed_at AS begins, first_term_end AS ends, 1 AS annual
                FROM orders
                UNION ALL
                SELECT o.referral_id, o.ready_at, o.commission, p.payment_id, p.amount_refunded AS observed,
                    p.amount, p.period_start, p.period_end, 0
                FROM orders o JOIN launch_renewal_mandates m ON m.purchase_id = o.purchase_id
                JOIN launch_renewal_payments p ON p.mandate_id = m.id AND p.period_start >= o.first_term_end
                WHERE p.period_end <= ?
            )
            SELECT periods.*,
                MAX(observed, COALESCE((SELECT SUM(r.amount) FROM launch_refunds r WHERE r.payment_id = periods.payment_id), 0)) AS refunded,
                COALESCE((SELECT SUM(d.deducted) FROM launch_disputes d WHERE d.payment_id = periods.payment_id), 0) AS reversed,
                COALESCE((SELECT SUM(d.held) FROM launch_disputes d WHERE d.payment_id = periods.payment_id), 0) AS held
            FROM periods
        SQL, [$partnerId, $now]);
        $credits = [];
        foreach ($payments as $period) {
            if ($period['ready_at'] >= $period['ends']) continue;
            $months = $period['annual'] ? $this->completedMonths($period, $now) : 1;
            $commission = ($period['commission'] ?? $this->commission) * $months;
            $net = max(0, $period['amount'] - $period['refunded'] - $period['reversed']);
            $available = max(0, $net - $period['held']);
            $earned = intdiv($commission * $net, $period['amount']);
            $eligible = intdiv($commission * $available, $period['amount']);
            $referral = $period['referral_id'];
            $begins = $period['begins'];
            $previous = $credits[$referral][$begins] ?? ['earned' => 0, 'eligible' => 0];
            $credits[$referral][$begins] = ['earned' => max($previous['earned'], $earned),
                'eligible' => max($previous['eligible'], $eligible)];
        }
        $totals = [];
        foreach ($credits as $referral => $periods) {
            $totals[$referral] = ['earned' => array_sum(array_column($periods, 'earned')),
                'eligible' => array_sum(array_column($periods, 'eligible'))];
        }
        return $totals;
    }

    private function completedMonths(array $period, int $now): int
    {
        $completed = 0;
        $through = min($now, $period['ends']);
        for ($month = 1; $month <= 12; $month++) {
            $end = kiwe_billing_month_end($period['begins'], $month);
            if ($end > $through) break;
            if ($end > $period['ready_at']) $completed++;
        }
        return $completed;
    }

    private function balance(string $partnerId, array $earnings): array
    {
        $totals = $this->query("SELECT
            COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS approved,
            COALESCE(SUM(CASE WHEN status = 'requested' THEN amount ELSE 0 END), 0) AS requested
            FROM launch_partner_withdrawals WHERE partner_id = ?", [$partnerId])->fetch();
        $earned = array_sum(array_column($earnings, 'earned'));
        $eligible = array_sum(array_column($earnings, 'eligible'));
        $pending = $totals['requested'] + $totals['approved'];
        return [
            'earned' => $earned, 'available' => max(0, $eligible - $totals['paid'] - $pending),
            'pending' => $pending, 'paid' => $totals['paid'], 'held' => $earned - $eligible,
            'adjustmentDue' => max(0, $totals['paid'] + $totals['approved'] - $earned),
        ];
    }

    public function summary(string $partnerId): array
    {
        $this->requirePartner($partnerId);
        return $this->transaction(function () use ($partnerId): array {
            return $this->balance($partnerId, $this->earnings($partnerId, time()));
        }, false);
    }

    public function dashboard(string $partnerId, int $page): array
    {
        $this->requirePartner($partnerId);
        return $this->transaction(function () use ($partnerId, $page): array {
            $earnings = $this->earnings($partnerId, time());
            $clients = $this->query('SELECT referral_id, client_email, domain, ready_at, amount,
                    MAX(payment_refunded, COALESCE((SELECT SUM(amount) FROM launch_refunds r WHERE r.payment_id = o.payment_id), 0)) AS refunded
                FROM launch_partner_orders o WHERE partner_id = ?
                ORDER BY confirmed_at DESC, purchase_id DESC LIMIT 21 OFFSET ?', [$partnerId, ($page - 1) * 20])->fetchAll();
            $more = count($clients) > 20;
            $clients = array_slice($clients, 0, 20);
            foreach ($clients as &$client) {
                $client['earned'] = $earnings[$client['referral_id']]['earned'] ?? 0;
            }
            unset($client);
            return [
                'balance' => $this->balance($partnerId, $earnings),
                'clients' => $clients, 'more' => $more,
                'withdrawals' => $this->query('SELECT id, amount, status, created_at, updated_at FROM launch_partner_withdrawals
                    WHERE partner_id = ? ORDER BY created_at DESC, id DESC LIMIT 20', [$partnerId])->fetchAll(),
            ];
        }, false);
    }

    public function requestWithdrawal(string $partnerId, string $id, int $amount): array
    {
        $this->requirePartner($partnerId);
        $this->validateWithdrawal($id, $amount);
        return $this->transaction(function () use ($partnerId, $id, $amount): array {
            $existing = $this->query('SELECT * FROM launch_partner_withdrawals WHERE id = ?', [$id])->fetch();
            if ($existing) {
                if ($existing['partner_id'] !== $partnerId || $existing['amount'] !== $amount) {
                    throw new KiweCheckoutError('Reload your balance and request a withdrawal again.', 409);
                }
                return $existing;
            }
            $now = time();
            $balance = $this->balance($partnerId, $this->earnings($partnerId, $now));
            if ($amount > $balance['available']) {
                throw new KiweCheckoutError('Your available earnings changed. Review your balance and try again.', 409);
            }
            $this->query('INSERT INTO launch_partner_withdrawals (id, partner_id, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                [$id, $partnerId, $amount, $now, $now]);
            return $this->withdrawal($id);
        });
    }

    public function cancelWithdrawal(string $partnerId, string $id): void
    {
        $this->requirePartner($partnerId);
        $this->transaction(function () use ($partnerId, $id): void {
            $row = $this->withdrawal($id, $partnerId);
            if ($row['status'] === 'cancelled') return;
            if ($row['status'] !== 'requested') throw new KiweCheckoutError('This withdrawal is already under review or closed.', 409);
            $this->query("UPDATE launch_partner_withdrawals SET status = 'cancelled', updated_at = ? WHERE id = ?", [time(), $id]);
        });
    }

    public function withdrawals(string $state, int $page): array
    {
        $selection = match ($state) {
            'pending' => "WHERE status IN ('requested', 'approved') ORDER BY created_at, id",
            'paid' => "WHERE status = 'paid' ORDER BY updated_at DESC, id DESC",
            'all' => 'ORDER BY created_at DESC, id DESC',
            default => throw new KiweCheckoutError('Choose the withdrawal records to review.'),
        };
        $rows = $this->query('SELECT * FROM launch_partner_withdrawals ' . $selection . ' LIMIT 51 OFFSET ?', [($page - 1) * 50])->fetchAll();
        return ['withdrawals' => array_slice($rows, 0, 50), 'nextPage' => count($rows) > 50 ? $page + 1 : null];
    }

    public function reviewWithdrawal(string $id, int $amount, string $decision): array
    {
        $this->validateWithdrawal($id, $amount);
        if (!in_array($decision, ['approve', 'decline'], true)) throw new KiweCheckoutError('Choose approve or decline.');
        return $this->transaction(function () use ($id, $amount, $decision): array {
            $row = $this->withdrawal($id);
            $target = $decision === 'approve' ? 'approved' : 'declined';
            if ($row['amount'] !== $amount || !in_array($row['status'], ['requested', 'approved', $target], true)) {
                throw new KiweCheckoutError('The withdrawal has a different amount or is closed.', 409);
            }
            $now = time();
            if ($target === 'approved') {
                $partner = $row['partner_id'];
                $eligible = array_sum(array_column($this->earnings($partner, $now), 'eligible'));
                $committed = $this->query("SELECT COALESCE(SUM(amount), 0) FROM launch_partner_withdrawals
                    WHERE partner_id = ? AND id <> ? AND status IN ('approved', 'paid')", [$partner, $id])->fetchColumn();
                if ($amount > $eligible - $committed) {
                    throw new KiweCheckoutError('This withdrawal exceeds the earnings available after payment adjustments.', 409);
                }
            }
            if ($row['status'] !== $target) {
                $this->query('UPDATE launch_partner_withdrawals SET status = ?, updated_at = ?, approved_at = COALESCE(approved_at, ?) WHERE id = ?',
                    [$target, $now, $target === 'approved' ? $now : null, $id]);
            }
            return $this->withdrawal($id);
        });
    }

    public function recordPayout(string $id, int $amount, string $reference): array
    {
        $this->validateWithdrawal($id, $amount);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,79}$/D', $reference)) {
            throw new KiweCheckoutError('Enter the confirmed transfer reference.');
        }
        return $this->transaction(function () use ($id, $amount, $reference): array {
            $row = $this->withdrawal($id);
            if ($row['amount'] !== $amount) throw new KiweCheckoutError('The payout amount differs from the approved withdrawal.', 409);
            if ($row['status'] === 'paid' && strcasecmp($row['payout_reference'], $reference) === 0) return $row;
            if ($row['status'] !== 'approved') throw new KiweCheckoutError('Only an approved withdrawal can receive a payout record.', 409);
            if ($this->query('SELECT 1 FROM launch_partner_withdrawals WHERE payout_reference = ?', [$reference])->fetchColumn()) {
                throw new KiweCheckoutError('This transfer is already recorded.', 409);
            }
            $this->query("UPDATE launch_partner_withdrawals SET status = 'paid', payout_reference = ?, updated_at = ? WHERE id = ?",
                [$reference, time(), $id]);
            return $this->withdrawal($id);
        });
    }

    private function validateWithdrawal(string $id, int $amount): void
    {
        if (!preg_match('/^wd_[a-f0-9]{32}$/D', $id) || $amount < 1) {
            throw new KiweCheckoutError('Review the withdrawal reference and amount.');
        }
    }

    private function withdrawal(string $id, ?string $partnerId = null): array
    {
        $row = $this->query('SELECT * FROM launch_partner_withdrawals WHERE id = ?', [$id])->fetch();
        if (!$row || ($partnerId !== null && $row['partner_id'] !== $partnerId)) {
            throw new KiweCheckoutError('This withdrawal is unavailable.', 404);
        }
        return $row;
    }
}
