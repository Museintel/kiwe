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
- The WooCommerce candidate binds the buyer's verified Launch session, selected domain and catalogue price to a native order before the gateway opens. On local WordPress, a native paid-order event with a simulated Razorpay capture queued the job and delivered one Portfolio project. Replays reused the receipt and another buyer could neither pay that order nor read its purchase. The dedicated Launch account route also passed independent browser sign-in and sign-out.
- The annual Pass uses one native order per verified account and an independently verified annual renewal plan. Coverage has signed-in browser and MCP projections. A Pass has no domain or Start project. Provider charges, real mail delivery and hosted checkout remain to be qualified before public sale.
- Active Pass coverage applies the catalogue's first-year website rate in the signed-in WooCommerce cart. The order freezes the exact buyer, website and approved price. Start receives the Pass pricing source with an empty referral attribution.
- For native monthly renewals, Launch checks paid Pass coverage on the scheduled dates and bounds the eligible approval to that term. A rate change closes the old mandate and reconciles its paid cycles before the buyer approves the new rate. Hosted provider and scheduler verification remain pending.
- The candidate lets operator-approved partners with active Passes issue a private referral. Verified client email and the exact domain control one discounted WooCommerce order. The captured order records its referral in Start and uses the existing partner ledger at the tier's fixed commission.
- The Launch billing candidate applies paid partner coverage to referred website renewals and saves commission on each approved mandate. A buyer's own paid Pass takes priority even at an equal price. Tests cover paid renewal attribution, Pass refunds, provider cancellation readback and the buyer's fresh approval.

## Remaining launch batches

1. Complete role-scoped Guest permissions, wholesale pricing and attribution.
2. Qualify outgoing mail, the authored Bricks checkout, Razorpay payments and renewals for websites and the annual Pass, and Start and hosting on the representative site. Activate public purchases for the verified offers. Publish paid Operations after the wholesale workflow passes. POS and attendance remain separate roadmap work.
