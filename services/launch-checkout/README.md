# Kiwe Launch checkout

The Batch 2 checkout candidate is the private enrolment workflow behind the public Kiwe Client offer. The web entry is `public/kiwelaunch.com/checkout/`. Launch owns the verified buyer and commercial draft. Start receives the same durable `launch:` account ID after a verified payment in Batch 3.

## Buyer and partner flow

Buyers verify a link sent to their email, then set a password. The link expires after 30 minutes and is consumed when the password is saved. Buyers can sign in or reset that password with a fresh link. Session identifiers rotate after sign-in and a password reset invalidates earlier sessions.

A verified buyer can opt into the Partner Program. They enter the client's email, WhatsApp and preferred domain with the client's permission. The private referral link expires after 30 days if unused. A partner can revoke an unused link or see when the corresponding email was verified. The link contains an opaque token in the fragment. Checkout binds it to the matching verified email before pre-filling contact details or showing the partner price. Partners share links themselves. Commission, enrolled paying clients and withdrawals belong to the Partner dashboard in Batch 4.

Hostinger checks new standard `.in` names. The form preserves the entered details when a domain is taken, premium or restricted. A buyer can explicitly confirm ownership of a domain they already have. That selection records the buyer's claim for later DNS verification. Where the registry requires identity verification, the quote carries that requirement.

Checkout saves contact and billing details privately with the domain and the server-calculated price. Both direct and referred prices come from the canonical Client plan. The quote expires after 15 minutes. Its stable ID, revision and pricing can be read through the private Kiwe MCP tool. Before charging in Batch 3, re-check domain eligibility, bind the confirmed quote to the payment, and send the confirmed entitlement to Start once.

## Provider configuration

Deploy service files outside the public document root, default `~/.kiwe-launch-checkout/`. The route enables checkout when its private `settings.php` sets `enabled` to true and supplies an authorised Hostinger Mail API `mailbox` and `mailToken`, plus a Hostinger API `registrarToken`. Live origin defaults to `https://kiwelaunch.com`. Use PHP 8.2 or later with PDO SQLite, cURL and Argon2id. [Mail send API](https://github.com/hostinger/mail-api-php-sdk/blob/main/docs/Api/SendApi.md) · [Domain availability API](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/DomainsAvailabilityApi.md)

Prepare private mode 0700 directories `data/` and `data/sessions/`, and generate a 32-byte random `data/rate-key` at mode 0600. Derive `client-plan.json` from the Start source in the release checkout.

```sh
node --input-type=module -e "import { clientPlan } from './services/start-projects/src/client-plan.mjs'; process.stdout.write(JSON.stringify(clientPlan))"
```

Place that output in the private runtime. Keep provider credentials and the database there. Public enrolment should open once the real sender and registrar credentials have passed an isolated rehearsal.

## Programmatic operations

Set `KIWE_LAUNCH_CHECKOUT_ROOT` on the private Launch office MCP process. Its `kiwe_launch_check_domain`, `kiwe_launch_enrol_partner` and `kiwe_launch_checkout_quote` tools use the same checkout service. The quote read excludes contact and billing fields. Operators can use `php operator.php` from the service directory through the existing approved SSH access.

Run `node services/launch-checkout/test/checkout.mjs` using the same PHP and Playwright environment as the [Launch site checks](../launch-office/README.md#qualification-and-release). The test uses an isolated SQLite database and captured fixture email. The private hosted proof runs the same store on Hostinger and parses a current real availability response from the existing Hostinger MCP. Live verification delivery and automatic registrar access require their own runtime credentials.
