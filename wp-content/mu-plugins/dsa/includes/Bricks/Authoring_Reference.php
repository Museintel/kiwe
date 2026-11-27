<?php
namespace DSA\Bricks;

use DSA\Apps\Store_App_Service;
use DSA\Commerce\Checkout_Experience_Service;
use DSA\Commerce\Product_Availability_Query;

if ( ! defined( 'ABSPATH' ) ) exit;

/** The Bricks usage guide shared by Kiwe Attributes and MCP. */
final class Authoring_Reference {
	public static function catalogue(): array {
		$rows = [
			[ __( 'Icon, Button and HTML attributes', 'dsa' ), 'dsaOpenModule · data-dsa-open-module="cart"', __( 'Set Open DSA screen on a native Icon or add the attribute to a button. The destination follows the site’s Kiwe presentation settings. Use the attribute library below for Saved, Dock visibility, app installation, notifications and theme controls.', 'dsa' ) ],
			[ __( 'Native buttons, links and icons', 'dsa' ), 'kiweContactAction · kiweSocialProfile', __( 'Open the phone, email, WhatsApp, directions or social profile from Kiwe Design Context. The attribute library also covers current-record actions such as Share and Comments.', 'dsa' ) ],
			[ __( 'Filter Search element', 'dsa' ), __( 'Kiwe Search bridge', 'dsa' ), __( 'Connect an authored search trigger to Kiwe Search when the page needs the Kiwe search surface. Use Bricks Query Filters for ordinary filtering on a page.', 'dsa' ) ],
			[ __( 'Native Form element', 'dsa' ), __( 'Kiwe form identity and Contact', 'dsa' ), __( 'Use native fields and styling. Kiwe can associate the signed-in visitor and route approved submissions into the site’s contact or enquiry workflow.', 'dsa' ) ],
			[ __( 'Query Loop → Query type', 'dsa' ), 'kiwe_testimonials', __( 'Select Kiwe community testimonials for owner-selected, approved reviews and comments. Use the Kiwe testimonial tags on children of the loop.', 'dsa' ) ],
			[ __( 'Dynamic data picker', 'dsa' ), '{kiwe_context_*}, {kiwe_service_*}, {kiwe_team_*}, {kiwe_discussion_*}, {kiwe_tag_group_*}, {kiwe_menu_*}, {kiwe_icon_*}', __( 'Bind Design Context, public services and team, community counts, assigned tag groups, saved menus and Kiwe icons. Use the tag list on this page for the exact syntax and names available on this site.', 'dsa' ) ],
			[ __( 'Bricks style controls and template workflow', 'dsa' ), __( 'Kiwe Surface styles, Seam import and Studio', 'dsa' ), __( 'Kiwe adds scoped style controls where a Kiwe interaction needs them. Seam imports editable native elements and popup references. Studio is available when enabled for the editor.', 'dsa' ) ],
		];
		if ( Store_App_Service::enabled() ) {
			$rows[] = [ __( 'Product Up-sells → Content → Type', 'dsa' ), 'upsells, cross_sells, cart_cross_sells', __( 'Use the native Type selector. Existing templates that saved a Kiwe source preset still render. For a native product loop, preserve the linked-product order when migrating from a Kiwe loop.', 'dsa' ) ];
			$rows[] = [ __( 'Products query or layout element with Query Loop', 'dsa' ), 'kiweProductRelation, kiweRelationSource, bestSellersPeriod', __( 'Use native upsell and cross-sell filters for the current product. Use Kiwe Product collection for an explicit source, grouped products, configured companions, offers or approved selected products. Kiwe Bestsellers ranks sales over 7, 30 or 365 days.', 'dsa' ) ];
			$rows[] = [ __( 'Product Add to cart and Mini Cart', 'dsa' ), __( 'Kiwe quantity behavior, compact add controls and offers', 'dsa' ), __( 'Use native Add to cart for a standard product. Kiwe supports cart-aware compact plus controls and older Mini Cart steps, badges and promotions. For a new cart layout on Bricks 2.4 or later, use Cart contents, Cart quantity and Dynamic fragment.', 'dsa' ) ];
			$rows[] = [ __( 'Product text, imagery and offer cards', 'dsa' ), '{woo_product_weight}, {kiwe_product_price_text}, {kiwe_product_image_alt}, {kiwe_pair_*}', __( 'Use native product price and image elements for visible output. Kiwe supplies plain price text for labels, text-only attachment alt, product weight and configured offer pairs. Bricks Image can read the featured image’s attachment alt directly.', 'dsa' ) ];
		}
		if ( class_exists( Product_Availability_Query::class ) && Store_App_Service::enabled() ) {
			$rows[] = [ __( 'Products query → Kiwe product billing', 'dsa' ), 'kiweProductBilling', __( 'Choose One-time products, Subscriptions or Both. Kiwe checks purchase limits and paid membership before pagination.', 'dsa' ) ];
		}
		if ( class_exists( Checkout_Experience_Service::class ) && Store_App_Service::enabled() ) {
			$rows[] = [ __( 'Authored checkout template', 'dsa' ), '{kiwe_checkout_address}, {kiwe_checkout_renewal}, {kiwe_cart_variation_selector}', __( 'Use Bricks Checkout Coupon, Cart contents, Cart quantity, Dynamic fragment, {woo_cart_order_total} and {woo_url:terms}. Add the matching Kiwe checkout attributes for live totals, address edits, payment choices and renewal consent. The variation selector belongs inside a Cart contents row.', 'dsa' ) ];
			$rows[] = [ __( 'Order savings and payment amounts', 'dsa' ), '{kiwe_checkout_savings}, data-kiwe-checkout-show="savings", data-kiwe-checkout-pay-total', __( 'Add the savings tag inside a native text element, mark its enclosing banner to hide it when there are no savings, and mark native payment buttons to show the live amount. Savings include product markdowns and applied WooCommerce discounts on the initial order.', 'dsa' ) ];
			$rows[] = [ __( 'Desktop order summary', 'dsa' ), 'data-kiwe-checkout-layout, data-kiwe-checkout-summary', __( 'Mark the two-column checkout layout and its order summary. The layout stacks on phone and tablet widths.', 'dsa' ) ];
			$rows[] = [ __( 'Delivery options on an authored checkout', 'dsa' ), 'data-kiwe-checkout-shipping', __( 'Put this on a native Bricks Dynamic fragment containing Shipping options. Use the Shipping options controls for styling. The element shows the current WooCommerce rates, including Shiprocket when configured.', 'dsa' ) ];
			$rows[] = [ __( 'Compare delivery rates by payment mode', 'dsa' ), 'data-kiwe-checkout-delivery-mode="prepaid|cod"', __( 'Optional native Bricks buttons outside the shipping fragment. Each button refreshes rates for its payment mode. WooCommerce determines whether the payment method is available.', 'dsa' ) ];
			$rows[] = [ __( 'Product delivery PIN code', 'dsa' ), '[kiwe_shiprocket_delivery]', __( 'Place this in a native Bricks Shortcode element outside the Add to cart form. For a variable product, choose the variation before checking. Shiprocket requires live rates and a product weight.', 'dsa' ) ];
		}
		if ( class_exists( \DSA\Commerce\Checkout_Offers::class ) && Store_App_Service::enabled() ) {
			$rows[] = [ __( 'Query Loop → Kiwe Checkout offers', 'dsa' ), '{kiwe_checkout_offer_title}, {kiwe_checkout_offer_description}, {kiwe_checkout_offer_id}', __( 'Choose which WooCommerce coupons to list using Show in checkout offers. Use a native Accordion or Popup to reveal the list and a native Button on each card.', 'dsa' ) ];
			$rows[] = [ __( 'Offer Button on an authored checkout', 'dsa' ), 'data-kiwe-checkout-offer="{kiwe_checkout_offer_id}"', __( 'Place this on a native Bricks Button inside the checkout offers loop. Style [data-kiwe-offer-state="applied"] for applied offers. The button applies a regular coupon, adds a specific membership plan or links to plan options. Paid members receive the benefit automatically through the end of their paid term.', 'dsa' ) ];
		}
		return $rows;
	}

	public static function render(): void {
		$provider = \Bricks\Integrations\Dynamic_Data\Providers::class;
		$tags = is_callable( [ $provider, 'get_dynamic_tags_list' ] ) ? $provider::get_dynamic_tags_list() : [];
		$styles = Surface_Style_Controls::capabilities( Store_App_Service::enabled() );
		?>
		<h3 id="kiwe-bricks-authoring"><?php esc_html_e( 'Bricks authoring reference', 'dsa' ); ?></h3>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Where in Bricks', 'dsa' ); ?></th><th><?php esc_html_e( 'Control or binding', 'dsa' ); ?></th><th><?php esc_html_e( 'How to use it', 'dsa' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( self::catalogue() as [ $location, $binding, $description ] ) : ?>
					<tr><td><?php echo esc_html( $location ); ?></td><td><code><?php echo esc_html( $binding ); ?></code></td><td><?php echo esc_html( $description ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $tags ) : ?>
			<details>
				<summary><?php esc_html_e( 'Kiwe dynamic tags available on this site', 'dsa' ); ?></summary>
				<ul>
					<?php foreach ( $tags as $tag ) : ?>
						<?php if ( in_array( $tag['group'] ?? '', [ 'Kiwe', 'Kiwe icons' ], true ) ) : ?>
							<li><code><?php echo esc_html( $tag['name'] ); ?></code> <?php echo esc_html( $tag['label'] ); ?></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
		<?php if ( $styles ) : ?>
			<details>
				<summary><?php esc_html_e( 'Kiwe style controls by Bricks element', 'dsa' ); ?></summary>
				<ul>
					<?php foreach ( $styles as $element => $controls ) : ?>
						<li><strong><?php echo esc_html( $element ); ?></strong> <code><?php echo esc_html( implode( ', ', $controls ) ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
		<?php
	}
}
