# Kiwe Launch checkout

The checkout candidate handles private enrolment and billing for the public Kiwe Client offer. The web entry is `public/kiwelaunch.com/checkout/`. Launch owns the verified buyer, quote and payment record. Start receives the same durable `launch:` account ID after payment confirmation.

## Buyer and partner flow

Buyers verify a link sent to their email, then set a password. The link expires after 30 minutes and is consumed when the password is saved. Buyers can sign in or reset that password with a fresh link. Session identifiers rotate after sign-in and a password reset invalidates earlier sessions.

A verified buyer can opt into the Partner Program. They enter the client's email, WhatsApp and preferred domain with the client's permission. The private referral link expires after 30 days if unused. A partner can revoke an unused link or see when the corresponding email was verified. The link contains an opaque token in the fragment. Checkout binds it to the matching verified email before pre-filling contact details or showing the partner price. Partners share links themselves.

Hostinger checks new standard `.in` names. The form preserves the entered details when a domain is taken, premium or restricted. A buyer can explicitly confirm ownership of a domain they already have. That selection records the buyer's claim for later DNS verification. Where the registry requires identity verification, the quote carries that requirement.

Checkout saves contact and billing details privately with the domain and the server-calculated price. Both direct and referred prices come from the canonical Client plan. The quote expires after 15 minutes. Its stable ID, revision and pricing can be read through the private Kiwe MCP tool. The payment step rechecks domain eligibility, locks the quote and sends a confirmed entitlement to Start once.

## First year and monthly renewals

Launch rechecks the saved quote when the buyer confirms the first-year amount. It reserves a unique reference before creating a 30-minute Razorpay Standard Payment Link. A retry looks up that reference before requesting a link. A confirmed expired or cancelled unpaid link lets the buyer review a fresh quote. The browser return only opens the checkout status page. A captured payment must match the link, order, currency and complete amount.

When Launch confirms the payment, the first term runs for 12 calendar months in India time. Checks first look for any processed full refund. Launch submits the same entitlement and payment ID to Start on each delivery attempt. Start returns a one-time browser handoff for the same verified Launch account. Subsequent logins reopen that project from Launch. The closed Key pilot can serve the new path when its separate Launch purchase credential is configured.

Renewal requires a separate buyer approval for the exact amount, start date and number of payments. Razorpay begins monthly billing at the end of the prepaid term. The default mandate is up to 120 monthly payments. A new approval after cancellation or expiry begins at the end of the latest paid term or on a new disclosed date if service has lapsed. Each payment and processed refund is recorded by provider ID. Failed or halted renewals surface in checkout. Existing paid periods remain recorded when the buyer cancels automatic renewal.

A Razorpay subscription request with an uncertain outcome keeps its reservation. The private recovery operation verifies the matching subscription ID with Razorpay before accepting it. Manage refunds through Razorpay. Launch records processed refunds and blocks an undelivered first-year entitlement after a full refund. Project data stays under its existing owner.

## Partner earnings and withdrawals

The signed-in Partner area lists the partner's paid referrals, their setup status and earned commission. The plan sets ₹100 per completed paid month. Launch captures the commission rate on the payment record. Earlier records for the same plan use its canonical rate. The first eligible month is the paid billing month that includes verified managed-site setup in Start. Launch recognizes commission after that month ends. The prepaid year uses calendar anniversaries in India time and pays at most 12 monthly commissions. Later months use the billing period of each captured renewal.

Commission uses the net collected amount in paise. A partial refund or lost chargeback reduces the commission proportionally. An open dispute holds the affected share from withdrawal. A reversal after payout can offset future earnings. The dashboard shows net earnings, available balance, pending and paid withdrawals, and any active holds or adjustments.

Selecting **Withdraw** requests the displayed available amount. The request reserves it immediately and retries reuse the request ID. The partner can cancel before approval. The operator reviews the exact amount against the current balance, then records a cleared external transfer with its unique reference. For a manual transfer, use the withdrawal ID for reconciliation and confirm the destination and transfer through the approved payment channel. Confirm a transfer has not cleared before declining an approved request. The candidate stores the confirmed payout record in Launch and uses a separate payment channel for the transfer itself.

Launch records the setup date from Start's private `purchase-delivery` lookup for the exact entitlement and buyer. Start uses the verified managed WordPress site and its existing connection proof. Run `php operator.php reconcile-partner-deliveries` regularly after enabling checkout and provisioning. Each pass checks up to four due referrals. The service claims each pending check before calling Start. The Partner area reads local records on page load.

## Provider configuration

Deploy service files outside the public document root, default `~/.kiwe-launch-checkout/`. The route enables checkout when its private `settings.php` sets `enabled` to true and supplies an authorised Hostinger Mail API `mailbox` and `mailToken`, plus a Hostinger API `registrarToken`. Live origin defaults to `https://kiwelaunch.com`. Use PHP 8.2 or later with PDO SQLite, cURL and Argon2id. [Mail send API](https://github.com/hostinger/mail-api-php-sdk/blob/main/docs/Api/SendApi.md) · [Domain availability API](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/DomainsAvailabilityApi.md)

Prepare private mode 0700 directories `data/` and `data/sessions/`, and generate a 32-byte random `data/rate-key` at mode 0600. Derive `client-plan.json` from the Start source in the release checkout.

```sh
node --input-type=module -e "import { clientPlan } from './services/start-projects/src/client-plan.mjs'; process.stdout.write(JSON.stringify(clientPlan))"
```

Place that output in the private runtime. Keep provider credentials and the database there. Public enrolment should open once the real sender and registrar credentials have passed an isolated rehearsal.

To enable production billing, configure a private `billing` array with `mode: live`, `merchant`, `keyId`, `keySecret`, `webhookSecret`, `startOrigin`, `startToken`, and `plans.direct` and `plans.referred`. The two Razorpay plans must match the current monthly amounts in INR. The optional `cycles` sets the disclosed mandate length.

Razorpay `mode: test` is available in the isolated CLI runtime with separate test keys, a signing secret, a separate store and an isolated loopback Start service. A provider webhook rehearsal needs its own HTTPS receiver. The production checkout accepts live Razorpay keys and uses its dedicated Start purchase credential to deliver orders.

Configure Razorpay to send Payment Link paid, expired and cancelled events, Subscription status and charged events, processed refunds and dispute lifecycle events to `/launch-api/razorpay.php`. The endpoint validates the raw body and merchant before writing a minimal local record. An enabled deployment runs `php operator.php reconcile-billing` to check pending provider state and retry confirmed Start delivery outside the webhook response. Checkout can also check the payment with the buyer present. [Razorpay Payment Links](https://razorpay.com/docs/api/payments/payment-links/) · [Razorpay Subscriptions](https://razorpay.com/docs/api/payments/subscriptions/create-subscription/) · [Razorpay dispute events](https://razorpay.com/docs/webhooks/disputes/) · [Razorpay webhook verification](https://razorpay.com/docs/webhooks/validate-test/)

## Programmatic operations

Set `KIWE_LAUNCH_CHECKOUT_ROOT` on the private Launch office MCP process. Its `kiwe_launch_check_domain`, `kiwe_launch_enrol_partner`, `kiwe_launch_checkout_quote`, `kiwe_launch_billing_status`, `kiwe_launch_reconcile_billing` and `kiwe_launch_recover_renewal` tools use the same checkout service. The reads exclude contact fields, payment links and handoff credentials. Operators can use `php operator.php` from the service directory through the existing approved SSH access.

Partner tools in that private MCP process are `kiwe_launch_partner_summary`, `kiwe_launch_reconcile_partner_deliveries`, `kiwe_launch_withdrawals`, `kiwe_launch_review_withdrawal` and `kiwe_launch_record_payout`. They use the same ledger as the dashboard. The withdrawal read can page through pending, paid or all records. The tool results use Launch account and withdrawal IDs and omit client contact and bank details.

## Included domain registration

Paid registration is disabled by default. The private `registration` settings require `enabled`, an explicit Hostinger `paymentMethodId`, and limits `firstYearMaxMinor` and `renewalMaxMinor` in INR paise. The limits apply to catalogue prices. Review the account's full charges before enabling the command. On 25 November 2026 the account catalogue listed a standard one-year `.in` at ₹549 initially and ₹899 for annual renewal.

Use `php operator.php register-domain PURCHASE_ID EXACT_DOMAIN WHOIS_PROFILE_ID` once the buyer's Launch payment is captured and delivered to Start and the correct buyer WHOIS profile is ready. Launch checks the verified buyer email on that profile, current eligibility, existing Hostinger orders, price limits, and payment and dispute status. After claiming the exact purchase and domain, the command can submit one paid request. The registry may ask for phone, email or identity verification. Complete those steps with Hostinger and keep sensitive documents in the provider's official process. [Hostinger purchase API](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/DomainsPortfolioApi.md#purchasenewdomainv1) · [Hostinger .in requirements](https://www.hostinger.com/support/requirements-to-register-in-domains-at-hostinger/)

Use `php operator.php reconcile-domain PURCHASE_ID EXACT_DOMAIN` or the private `kiwe_launch_reconcile_domain` tool to check a saved registration. Preserve the attempt after an interrupted request or a pending payment. Reconcile it with hPanel before any recovery action. Launch confirms the exact active registration and owner contact before notifying Start. Reconciliation handles a lost Start response through the same receipt. Customer-facing tools expose the domain's progress only.

For a client-owned domain, use `kiwe_launch_review_domain_move` or `php operator.php review-domain-move PURCHASE_ID EXACT_DOMAIN TEMPORARY_URL RECOVERY_SHA256` after the operator has verified the restored WordPress copy and recovery archive. Launch checks the confirmed owned-domain order and sends the review to its existing project in Start. The buyer then approves WordPress at the final address. [Reviewed move and approval](../start-projects/README.md#reviewed-domain-move)

## Qualification and recovery

Run `node services/launch-checkout/test/checkout.mjs` using the same PHP and Playwright environment as the [Launch site checks](../launch-office/README.md#qualification-and-release). The browser suite uses an isolated SQLite database, captured fixture email, simulated Razorpay responses and an isolated Start server. It covers partner isolation, exact-month accrual, request replays, dispute holds, payout recording and a refund after payout. The Hostinger PHP proof in `test/hosted-proof.php` uses the same PHP store and synthetic Start and transfer receipts. It covers a full first year and a monthly renewal, a chargeback and duplicate transfer reference rejection. Live mail, registrar access, Razorpay Test Mode and the external payout process require their respective rehearsals before use.

The PHP proof also checks the registration payment gate, matching registrar contact, catalogue cap, lost request recovery, verification hold and durable receipt. Keep `launch_domain_orders` and the Start domain setup records through code rollback. Reconcile any intervening registrar and billing state before re-enabling purchase. The domain purchase and renewal lifecycle still need real provider qualification.

The partner and dispute tables are additive in the existing private checkout database. Keep a SQLite-consistent backup before replacing checkout source. Preserve the database and confirmed external payout records during code rollback. Before re-enabling withdrawals after an outage or rollback, reconcile intervening provider refunds, disputes and external payouts with the retained records.
