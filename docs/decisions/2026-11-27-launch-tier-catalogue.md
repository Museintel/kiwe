# Kiwe Launch catalogue and account entitlements

27 November 2026

Kiwe Launch will offer Portfolio, Ecommerce, Operations and Premium for one website each. The Freelancer Pass is an annual account benefit. Website prices include managed hosting and one eligible standard `.in` domain and its renewals for the active term. The first 12 months are billed upfront. Monthly renewals begin after that term. Premium can run marketing on a site with Store off.

| Offer | Direct first term | Direct renewal | Eligible first term | Eligible renewal |
| --- | ---: | ---: | ---: | ---: |
| Portfolio | ₹5,748 | ₹479/month | ₹5,148 | ₹429/month |
| Ecommerce | ₹9,588 | ₹799/month | ₹8,388 | ₹699/month |
| Operations | ₹13,188 | ₹1,099/month | ₹11,988 | ₹999/month |
| Premium | ₹17,988 | ₹1,499/month | ₹16,788 | ₹1,399/month |

The ₹1,499 annual pass applies future eligible pricing automatically. A paid pass covers no site by itself. A buyer's own site earns no referral commission. An independently verified, paid referral earns ₹25 per eligible Portfolio month or ₹100 per eligible month on the other website plans. A single eligible site can save up to ₹1,200 per year. Expiry, refunds, a lost dispute and new payment consent affect eligibility on future quotes and renewals. Respect the existing paid-through period.

## Authority

`services/start-projects/src/client-plan.mjs` is the catalogue and provisioning policy. The previous Client plan stays valid for its existing purchase receipts and Razorpay Test Mode work. New orders use explicit versioned plan IDs. Start verifies the exact amount and domain in its trusted purchase receipt, rejects stacked pricing sources and accepts a completed purchase only once. One portfolio purchase provisions services mode automatically. Ecommerce and Operations restrict setup to Store modes. Premium can select services or a Store mode. The Freelancer Pass cannot create a hosting project.

The WooCommerce store should own the order and provider payment for the new Bricks checkout. A paid website order must correlate to its proven buyer and exact Start entitlement. The existing single-purchase Launch payment flow needs a transition to purchase-level ownership before the new site offers are payable. Keep existing consent, refund, dispute and commission records available through that transition. An order must never create a second charge or duplicate projects and commission on retry.

The shared Guest approval workspace authorizes wholesale, referral and product or post proposal grants individually. A frontend-only grant does not give Dashboard access. Bind private discounts to a verified account and an active entitlement. Show savings in a public Bricks card and suppress the private coupon code in buyer projections. Approved referrals are site-specific and should reuse Link DSA entry and consent. Report attributed sales and sign-ups in the authorised Guest view in Kiwe and MCP. POS, multi-location stock and attendance remain roadmap work.

## Implementation status

- Start serves the versioned catalogue by web and MCP and supports the plan-specific receipt and setup boundaries. Existing plan consumers can still read the original plan.
- The Launch homepage now serves the approved plan comparison from 191 editable Bricks elements. The five native virtual products are drafts. The CTA opens the existing demo form while the new billing path is being qualified. The Plans and Partner routes match the new offer.
- The owner's ₹8,400 Test Mode product and enhanced Checkout are available to WordPress store managers. The public homepage and restricted Razorpay checkout rendered successfully. Payment submission remains with the owner. The old static root is retained outside the public directory.
- The private billing candidate accepts verified WooCommerce orders for direct first-year plans. Two independently verified captures for one buyer delivered two projects to the isolated Start service. Receipt replay, buyer ownership, amount validation and legacy billing were exercised. Automatic native collection and the production deployment remain pending.
- Signed-in billing lists each imported website by domain. Renewal consent, Razorpay plan and invoice checks, cancellation, refund readback and private MCP recovery are scoped to the selected purchase. Isolated tests cover two websites for one buyer, another account's denied access, a lost provider response and one site's cancellation and refund. Production Razorpay plan mapping and hosted provider qualification remain pending.

## Three remaining launch batches

1. Connect WooCommerce checkout, the verified buyer and site quote to purchase-level billing. The candidate handles renewal consent and refunds per website. Add the annual Freelancer Pass and connect the selected website's billing to the native journey.
2. Finish private pass pricing and role-scoped Guest permissions, wholesale orders, referral attribution and the partner ledger UI and MCP access.
3. Qualify outgoing mail and the joined provider, checkout, Start and hosting journeys on the representative site. Activate public purchases for the verified offers. Publish paid Operations after the wholesale workflow passes. POS and attendance remain separate roadmap work.
