CREATE TABLE IF NOT EXISTS launch_payments (
    id TEXT PRIMARY KEY,
    account_id TEXT NOT NULL REFERENCES checkout_accounts(id),
    draft_id TEXT NOT NULL,
    draft_revision INTEGER NOT NULL,
    quote TEXT NOT NULL,
    consent_at INTEGER NOT NULL,
    link_expires_at INTEGER NOT NULL,
    link_id TEXT UNIQUE,
    link_url TEXT,
    retired_at INTEGER,
    payment_id TEXT UNIQUE,
    payment_refunded INTEGER NOT NULL DEFAULT 0,
    confirmed_at INTEGER,
    first_term_end INTEGER,
    start_project_id TEXT,
    reviewed_at INTEGER NOT NULL DEFAULT 0
) STRICT;
CREATE UNIQUE INDEX IF NOT EXISTS launch_one_open_payment ON launch_payments(account_id) WHERE retired_at IS NULL;

CREATE TABLE IF NOT EXISTS launch_renewal_mandates (
    id TEXT PRIMARY KEY,
    purchase_id TEXT NOT NULL REFERENCES launch_payments(id),
    plan_id TEXT NOT NULL,
    amount INTEGER NOT NULL,
    start_at INTEGER NOT NULL,
    authorised_cycles INTEGER NOT NULL,
    consent_at INTEGER NOT NULL,
    subscription_id TEXT UNIQUE,
    authorization_url TEXT,
    status TEXT NOT NULL DEFAULT 'creating',
    status_at INTEGER NOT NULL DEFAULT 0,
    retired_at INTEGER
) STRICT;
CREATE UNIQUE INDEX IF NOT EXISTS launch_current_mandate ON launch_renewal_mandates(purchase_id) WHERE retired_at IS NULL;

CREATE TABLE IF NOT EXISTS launch_renewal_payments (
    payment_id TEXT PRIMARY KEY,
    mandate_id TEXT NOT NULL REFERENCES launch_renewal_mandates(id),
    amount INTEGER NOT NULL,
    amount_refunded INTEGER NOT NULL,
    period_start INTEGER NOT NULL,
    period_end INTEGER NOT NULL,
    UNIQUE(mandate_id, period_start)
) STRICT;

CREATE TABLE IF NOT EXISTS launch_refunds (
    refund_id TEXT PRIMARY KEY,
    payment_id TEXT NOT NULL,
    amount INTEGER NOT NULL
) STRICT;
CREATE INDEX IF NOT EXISTS launch_refunds_by_payment ON launch_refunds(payment_id);

CREATE TABLE IF NOT EXISTS launch_disputes (
    dispute_id TEXT PRIMARY KEY,
    payment_id TEXT NOT NULL,
    deducted INTEGER NOT NULL CHECK(deducted >= 0),
    held INTEGER NOT NULL CHECK(held >= 0),
    terminal INTEGER NOT NULL CHECK(terminal IN (0, 1)),
    occurred_at INTEGER NOT NULL
) STRICT;
CREATE INDEX IF NOT EXISTS launch_disputes_by_payment ON launch_disputes(payment_id);

CREATE TABLE IF NOT EXISTS launch_billing_events (
    id TEXT PRIMARY KEY,
    digest TEXT NOT NULL,
    received_at INTEGER NOT NULL
) STRICT;
CREATE INDEX IF NOT EXISTS launch_billing_events_by_age ON launch_billing_events(received_at);
