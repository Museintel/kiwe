CREATE TABLE IF NOT EXISTS checkout_accounts (
    id TEXT PRIMARY KEY,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    verified_at INTEGER NOT NULL,
    session_version INTEGER NOT NULL DEFAULT 1,
    partner_enrolled_at INTEGER
) STRICT;

CREATE TABLE IF NOT EXISTS checkout_email_links (
    digest TEXT PRIMARY KEY,
    email TEXT NOT NULL,
    purpose TEXT NOT NULL CHECK(purpose IN ('signup', 'reset')),
    referral_digest TEXT,
    expires_at INTEGER NOT NULL
) STRICT;
CREATE INDEX IF NOT EXISTS checkout_link_expiry ON checkout_email_links (expires_at);

CREATE TABLE IF NOT EXISTS checkout_referrals (
    id TEXT PRIMARY KEY,
    partner_id TEXT NOT NULL REFERENCES checkout_accounts(id),
    digest TEXT NOT NULL UNIQUE,
    client_email TEXT NOT NULL,
    whatsapp TEXT NOT NULL,
    domain TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    buyer_id TEXT UNIQUE REFERENCES checkout_accounts(id),
    revoked_at INTEGER
) STRICT;
CREATE INDEX IF NOT EXISTS checkout_partner_referrals ON checkout_referrals (partner_id, created_at);

CREATE TABLE IF NOT EXISTS checkout_native_partner_approvals (
    account_id TEXT PRIMARY KEY REFERENCES checkout_accounts(id),
    approved_at INTEGER NOT NULL
) STRICT;

CREATE TABLE IF NOT EXISTS checkout_native_referrals (
    referral_id TEXT PRIMARY KEY REFERENCES checkout_referrals(id)
) STRICT;

CREATE TABLE IF NOT EXISTS checkout_drafts (
    account_id TEXT PRIMARY KEY REFERENCES checkout_accounts(id),
    id TEXT NOT NULL UNIQUE,
    revision INTEGER NOT NULL,
    quote_json TEXT NOT NULL,
    profile_json TEXT NOT NULL,
    updated_at INTEGER NOT NULL
) STRICT;

CREATE TABLE IF NOT EXISTS checkout_limits (
    digest TEXT NOT NULL,
    window_end INTEGER NOT NULL,
    count INTEGER NOT NULL,
    PRIMARY KEY (digest, window_end)
) STRICT;
CREATE INDEX IF NOT EXISTS checkout_limit_expiry ON checkout_limits (window_end);
