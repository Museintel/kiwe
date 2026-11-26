# Author the checkout review in Bricks

Use a Bricks **Checkout** template. Set one outer Section or Container attribute named <code>data-kiwe-checkout</code>. Build the visible checkout with normal Bricks headings, text, links, buttons and layout controls. The Kiwe Store checkout presentation option uses the value <code>bricks</code> for this layout. Choose this only after the marked template is in place.

| Bricks control | Kiwe binding |
| --- | --- |
| Text element below the address heading | <code>{kiwe_checkout_address}</code> |
| Address button or link | <code>data-kiwe-checkout-details</code> |
| Total text element | Bricks <code>{woo_cart_order_total}</code> with <code>data-kiwe-checkout-value="total"</code> on the element to refresh it after changes. If the Bricks tag is unavailable, use <code>{kiwe_checkout_total}</code>. |
| Renewal terms text element | <code>{kiwe_checkout_renewal}</code> |
| Link to the configured Terms page | Bricks <code>{woo_url:terms}</code>. If your Bricks version lacks that tag, use <code>{kiwe_checkout_terms_url}</code>. |
| Payment button | <code>data-kiwe-checkout-pay</code> with <code>upi</code>, <code>card</code>, <code>netbanking</code>, <code>wallet</code>, <code>razorpay</code>, <code>cod</code>, <code>subscription</code> or <code>free</code> |
| Consent toggle button | <code>data-kiwe-checkout-consent</code> with <code>renewal</code> or <code>terms</code>, and <code>aria-pressed="false"</code> |
| Coupon form | Native Bricks **Checkout Coupon** element with <code>data-kiwe-checkout-coupon</code>, placed in its current location. Enable it under Bricks → Settings → WooCommerce. |
| Status text element | <code>data-kiwe-checkout-status</code> and <code>role="status"</code> |

Check the installed Bricks version and enabled features first. Its advanced modular WooCommerce mode supplies the native cart total tag. Kiwe keeps older authored layouts working and omits the equivalent compatibility tags from the Bricks picker when Bricks supplies them. The shipping or billing address selected in Checkout DSA and the current renewal quote come from the shopper's private checkout session. The editor uses an address placeholder. Saving in Checkout DSA refreshes the review.

Place only the payment buttons the design needs. Kiwe checks WooCommerce for the options available for the current order. Eligible one-time Razorpay methods open through the official gateway. The merchant enables extra Razorpay choices after verifying them with the provider. COD depends on the native WooCommerce settings. Recurring carts use a Razorpay subscription mandate and require separate approval for the current quote.

The page sends the selected gateway and current checkout data to WooCommerce's existing checkout action. WooCommerce owns shipping, totals, coupons, stock validation, terms validation and order creation. The Bricks checkout template renders without the classic billing form. WooCommerce's payment and order-received endpoints continue to use their configured templates.
