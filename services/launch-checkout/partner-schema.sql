CREATE TABLE IF NOT EXISTS launch_partner_services (
    purchase_id TEXT PRIMARY KEY REFERENCES launch_payments(id),
    ready_at INTEGER,
    check_after INTEGER NOT NULL DEFAULT 0
) STRICT;

CREATE VIEW IF NOT EXISTS launch_partner_orders AS
SELECT r.partner_id, r.id AS referral_id, r.client_email, p.account_id, p.id AS purchase_id,
    p.payment_id, p.payment_refunded, p.start_project_id, p.confirmed_at, p.first_term_end,
    json_extract(p.quote, '$.domain.name') AS domain,
    json_extract(p.quote, '$.upfrontMinor') AS amount,
    json_extract(p.quote, '$.partnerCommissionMinor') AS commission,
    s.ready_at, COALESCE(s.check_after, 0) AS check_after
FROM checkout_referrals r
JOIN launch_payments p ON p.account_id = r.buyer_id AND json_extract(p.quote, '$.referralId') = r.id
LEFT JOIN launch_partner_services s ON s.purchase_id = p.id
WHERE r.revoked_at IS NULL AND p.retired_at IS NULL AND p.payment_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS launch_partner_withdrawals (
    id TEXT PRIMARY KEY,
    partner_id TEXT NOT NULL REFERENCES checkout_accounts(id),
    amount INTEGER NOT NULL CHECK(amount > 0),
    status TEXT NOT NULL DEFAULT 'requested' CHECK(status IN ('requested', 'approved', 'paid', 'declined', 'cancelled')),
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    approved_at INTEGER,
    payout_reference TEXT COLLATE NOCASE UNIQUE,
    CHECK((status = 'paid') = (payout_reference IS NOT NULL))
) STRICT;
CREATE INDEX IF NOT EXISTS launch_partner_withdrawals_account ON launch_partner_withdrawals (partner_id, created_at, id);
CREATE INDEX IF NOT EXISTS launch_partner_withdrawals_queue ON launch_partner_withdrawals (status, created_at, id);
