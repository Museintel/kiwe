# Kiwe Razorpay, checkout and memberships

25 November 2026 · Version 1.x

## Architecture decision

Keep WooCommerce as the commerce engine. It owns product and variation prices, cart and customer sessions, tax, shipping, coupons, stock, orders, payment status and refunds. Kiwe owns the storefront, Start and Bricks authoring contracts, the checkout sheet and the presentation of native checkout data. Keep Razorpay's official WooCommerce plugin as the gateway. The Razorpay App in Kiwe gives authorized store managers and MCP clients local configuration status. The site administrator or Kiwe owner configures payments in native WooCommerce settings. Provision the official gateway for eligible new sites with payments disabled until the site's merchant configuration is ready. [WooCommerce Store API checkout](https://developer.woocommerce.com/docs/apis/store-api/resources-endpoints/checkout/) · [Razorpay's WooCommerce integration](https://razorpay.com/app-store/woocommerce/)

This choice retains the transaction and operational contracts current Kiwe stores already use. For a faster experience, start with the requests, assets and steps on the shopper's path. Use compatible HPOS on stores where plugin and recovery checks pass, keep public pages cacheable, and keep personalized checkout, cart and account responses private. Measure server work, assets, input readiness and the provider handoff independently. Revisit an alternate commerce engine only if representative measurements identify a remaining WooCommerce bottleneck and the replacement can preserve the same inventory, tax, fulfillment, refund and recovery behavior. [HPOS technical documentation](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/) · [WooCommerce store speed guidance](https://woocommerce.com/posts/speed-up-your-woocommerce-store/)

The reviewed WordPress.org Razorpay 4.8.9 archive matched all 440 published file hashes. It declares HPOS and Checkout Blocks support and its header lists WooCommerce 10.6.2 as the tested version. The standard Woo gateway returns a same-origin WooCommerce order-pay URL, then its receipt loads Razorpay Checkout. It also enqueues the SDK on the initial checkout route. Kiwe's existing Store gateway asset service removes a duplicate SDK on a qualified receipt path with a native fallback. The reviewed receipt builds its payment arguments internally. Per-order UPI and card selection needs a supported vendor integration before it can ship. [Download and release details](https://wordpress.org/plugins/woo-razorpay/) · [WordPress.org archived source](https://plugins.trac.wordpress.org/browser/woo-razorpay/tags/4.8.9/woo-razorpay.php)

## Checkout experience

1. Use the accepted Kiwe checkout draft to show the shopper's address and contact details alongside an itemized WooCommerce order summary. Edit or add an address through the existing sheet. Keep the native checkout form reachable through the same page. A successful save uses the Woo session, updates the native fields and recalculates shipping and taxes in WooCommerce. Saved-cart capture continues through its existing checkout draft hook.
2. Provide one clear place for WooCommerce coupons, delivery and the final total. Show UPI, card and COD choices that the gateway, cart, merchant and device can actually use. Check COD rules again at order submission. The first candidate adds the address edit entry, using the classic WooCommerce form. The native coupon, totals and payment controls remain the purchase path.
3. For direct payment, verify a supported Razorpay checkout configuration per selected method. Pass the exact WooCommerce order, amount and currency into the provider flow. The shopper chooses the method on the Kiwe page, then completes bank or app authorization through Razorpay's supported UI. Keep card data in the provider-owned UI. Preserve native order-pay as the recovery route if the provider, payment method or device needs it. Verify the signed payment and reconcile status on the same order after a timeout, retry, reload or delayed notification. [Razorpay payment-method configuration](https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/configure-payment-methods) · [WooCommerce Checkout Block payment flow](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/checkout-flow-and-events/)
4. Rehearse a cold phone visit, slow or interrupted draft save, native form fallback, validation failure, back navigation, return from the provider, duplicate submission, failed or expired payment, paid callback, refund and COD. Validate pricing and stock before each payable attempt. The buyer can resume a saved draft and pending order when connected.

For an authored classic checkout, use [the native checkout-details link](#checkout-details-attribute) at the address summary.

### Checkout details attribute

Use the link on the real, editable WooCommerce checkout page, where the native checkout form contains the #customer_details destination. Kiwe opens the checkout sheet and returns the saved details to the same form.

~~~html
<a href="#customer_details" data-kiwe-checkout-details>Edit checkout details</a>
~~~

## Membership and recurring products

WooCommerce core supplies product and order primitives. WooCommerce Subscriptions supplies recurring products, renewal orders and billing state. Razorpay documents a separate Razorpay Subscriptions for WooCommerce companion for that workflow. WooCommerce Memberships adds member access and benefits when the store needs them. Kiwe can also own gym-specific access and attendance state through a membership App. Keep each membership grant linked to the verified native account, product, subscription, paid period and entitlement, with replay-safe changes for activation, expiration, cancellation and refunds. [Razorpay subscription plugin and dependencies](https://razorpay.com/docs/payments/subscriptions/plugins/woocommerce) · [WooCommerce Subscriptions and Memberships integration](https://woocommerce.com/document/woocommerce-memberships-subscriptions-integration/)

- In Start, record whether the website needs ecommerce, membership, or both. Existing content or service sites can keep both capabilities off. The human selects the desired capabilities. The provisioning request proposes the matching App set. Installed capability and verified readiness control which flows are available.
- Keep site activity and the public design brief in the native WordPress Design Context. Start and MCP read the saved capabilities and approved public facts. A generator can propose a plan comparison, joining page, member account and product catalogue that fit those facts. A design request can suggest an App without installing or enabling payment methods.
- In Kiwe's product editor, keep simple and variable Woo products. Expose simple and variable subscription products when the paid subscription stack is qualified. A separate membership plan binding grants specific access after the eligible native payment state. Check mixed physical and subscription carts against the Woo extension and merchant settings before offering a shared checkout.
- Keep the member-access and payment boundaries portable. Use a native WooCommerce adapter first, keyed by stable local account, product, order, subscription and provider references. Cover paid-through dates, failed renewal, grace, recovery, pause, expiry, cancellation at term end, refund and ownership. Qualify the supported billing intervals and upgrade rules on the exact Razorpay companion and WooCommerce extensions before enabling recurring memberships. [WooCommerce subscription gateway compatibility](https://woocommerce.com/document/subscriptions/payment-gateways/)

## Scaling and moving to another runtime

WordPress, PHP and MySQL remain the first operating tier. Choose a different runtime when consistent measurements show that the site's latency, traffic peaks, workload or operating cost warrant it. Use edge caching for public pages, tune PHP workers and persistent object caching, qualify HPOS and database indexes, and move bounded background work off checkout. Measure visits, carts and concurrent purchases separately. A language change alone does not remove shipping, database, payment-provider or mobile network delays. [WordPress performance guide](https://developer.wordpress.org/advanced-administration/performance/optimization/) · [WooCommerce scaling guide](https://developer.woocommerce.com/2024/08/22/scaling-with-woocommerce/)

The next tier can move public rendering, media or search while keeping WordPress and WooCommerce as the authoring and transaction systems. Kiwe Start, the user-facing dashboard and MCP should speak the same documented site and commerce operations across tiers. Keep the WordPress adapter concrete today. Add another adapter when there is a qualified second implementation. [WordPress REST API](https://developer.wordpress.org/rest-api/)

An **Upscale** action should prepare a destination, migrate and verify a copy, and present the exact changes and cutover for review. Keep the Kiwe project and tenant identity, site and page routes, customer and role mappings, catalogue and stock, order and payment history, verified membership periods, public content, media, accepted Seam source and Design Context. Keep an encrypted native recovery copy and a versioned export of equivalent records for a new runtime. The destination can use its own database schema. Check which identities, payment mandates and saved methods can move under the same merchant and provider, and collect fresh consent or re-link where the provider requires it. [Current Kiwe recovery runbook](../CORE-FLEET-RELEASE.md#verification-and-recovery) · [Razorpay tokenized saved cards](https://razorpay.com/docs/payments/payment-methods/cards/features/saved-cards/)

During cutover, reconcile the last order, stock and payment changes from the old site, verify the new site with the same customer and admin tasks, and keep one authoritative writer. Include tested recovery for new transactions after the switch. Present the same Kiwe dashboard and capabilities at the destination as each adapter qualifies. The operational goal is one guided upgrade experience with continuity for the client.

## Local qualification

The isolated Kiwe Launch fixture used WordPress 7.1.5, Twenty Twenty-Five, WooCommerce 11.3.1 and the Razorpay 4.8.9 archive. PHP served loopback traffic without a page cache. Each value is the median of 11 sequential HTML requests after three warmups. Checkout used a guest cart with one virtual product.

| Active commerce plugins | Home HTML | Classic checkout HTML | Razorpay SDK referenced on checkout |
| --- | ---: | ---: | --- |
| Kiwe with WooCommerce inactive | 391 ms | — | — |
| WooCommerce | 785 ms | 755 ms | No |
| WooCommerce and Razorpay | 821 ms | 808 ms | Yes |

This measures response completion through the local server. The comparison excludes downloading or running external scripts, payment processing, cold mobile traffic and concurrent load. The home HTML contained no Razorpay SDK reference in all three runs. The timings identify a useful next measurement on an actual storefront. They leave production conversion, provider latency and the impact of a different commerce engine open.

Fifteen native checks verified the App and MCP status, permission gates, settings link and omission of keys. A 390 px touch viewport verified first-tap editing, a real WooCommerce draft save with native field synchronization, one checkout details read and zero extra cart reads per edit. A stalled request and an injected 503 still led to the native checkout form, and the native anchor worked with JavaScript off. The synthetic cash-on-delivery purchase reached the WooCommerce receipt, and native order readback confirmed the saved details and ₹199 processing order. The browser fixture blocked external requests. Provider settlement, Bricks templates and the public Kiwe Launch site need their own qualification.

## Delivery and qualification

| Batch | Acceptance |
| --- | --- |
| 1 | Shared source candidate verified locally. Adds Razorpay readiness in Kiwe Apps and permission-scoped MCP, classic checkout details editing and a measured baseline. |
| 2 | Build the native address summary, coupon and payment method choices. Qualify the official plugin's supported method-specific handoff on the lab. Tune avoidable SDK, cart and checkout requests using comparable traces. |
| 3 | Deliver Start capability selection, the WordPress Design Context projection, native product editing and the Woo subscription adapter. Qualify the membership lifecycle and both commerce-only and mixed sites. |
| 4 | Use an isolated WordPress Kiwe Launch copy to rehearse storefront checkout as a first-party pilot. Plan the reviewed public migration with marketing routes, contact and partner forms, assets, redirects and recovery preserved. Keep Launch's Client plan billing service and its existing checkout, webhook and Start contracts available through the migration. |
| 5 | Qualify the signed Core and Store releases on Plum Giraffe, then roll out the matching version across eligible Kiwe sites. Verify Razorpay and saved-cart behavior on the owner-reviewed Dharwad Pedha and National Chikki checkouts, including mobile, low bandwidth and recovery. |

The public Atelick reference redirected a guest without a cart to its shopping bag. Use the founder's description as the interaction reference. Kiwe's visual design follows its own checkout styles.
