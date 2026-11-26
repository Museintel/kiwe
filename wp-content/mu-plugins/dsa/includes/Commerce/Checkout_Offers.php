<?php
namespace DSA\Commerce;

use DSA\Apps\Store_App_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Customer-visible WooCommerce offers and benefits earned through verified membership receipts. */
final class Checkout_Offers {
	public const QUERY = 'kiwe_checkout_offers';
	public const LISTED = '_kiwe_checkout_offer_listed';
	public const MEMBER = '_kiwe_checkout_offer_membership';
	private static ?array $listed = null;

	public function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', [ $this, 'validate_membership' ], 25, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_membership_offers' ], 10 );
		add_action( 'woocommerce_coupon_options', [ $this, 'editor' ], 10, 2 );
		add_action( 'woocommerce_coupon_options_save', [ $this, 'save_editor' ], 10, 2 );
		add_filter( 'bricks/setup/control_options', [ $this, 'query_options' ] );
		add_filter( 'bricks/query/run', [ $this, 'run_query' ], 20, 2 );
		add_filter( 'bricks/query/loop_object_id', [ $this, 'loop_id' ], 20, 3 );
		add_filter( 'bricks/dynamic_tags_list', [ $this, 'tags' ], 25 );
		add_filter( 'bricks/dynamic_data/render_tag', [ $this, 'render_tag' ], 25 );
		add_filter( 'bricks/dynamic_data/render_content', [ $this, 'render_content' ], 25 );
	}

	public static function settings( \WC_Coupon $coupon ): array {
		return [
			'checkoutListed' => 'yes' === $coupon->get_meta( self::LISTED, true, 'edit' ),
			'membershipProductId' => (int) $coupon->get_meta( self::MEMBER, true, 'edit' ),
		];
	}

	public static function can_edit( int $id ): bool {
		return $id > 0 && 'shop_coupon' === get_post_type( $id ) && current_user_can( 'edit_post', $id )
			&& ! Store_Recovery_Gate_Service::is_paused()
			&& ! \DSA\Access\Team_Workspace_Service::suspended( get_current_user_id() );
	}

	public static function set_settings( \WC_Coupon $coupon, bool $listed, int $membership_id ): void {
		if ( ! self::can_edit( $coupon->get_id() ) ) throw new \DomainException( __( 'Your role cannot edit this coupon.', 'dsa' ) );
		if ( $membership_id && ! self::membership_product( $membership_id ) ) {
			throw new \DomainException( __( 'Select a published Kiwe subscription product or a recurring variation.', 'dsa' ) );
		}
		if ( $listed && $coupon->get_email_restrictions() ) {
			throw new \DomainException( __( 'Remove customer email restrictions before listing this coupon at checkout.', 'dsa' ) );
		}
		$coupon->update_meta_data( self::LISTED, $listed ? 'yes' : 'no' );
		if ( $membership_id ) $coupon->update_meta_data( self::MEMBER, $membership_id );
		else $coupon->delete_meta_data( self::MEMBER );
		$coupon->save_meta_data();
		self::$listed = null;
	}

	private static function membership_product( int $id ): ?\WC_Product {
		if ( ! $id || ! Store_App_Service::supports_memberships() ) return null;
		$product = wc_get_product( $id );
		if ( ! $product || 'publish' !== $product->get_status() || '' !== get_post_field( 'post_password', $product->get_id(), 'raw' ) ) return null;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent || 'publish' !== $parent->get_status() || '' !== get_post_field( 'post_password', $parent->get_id(), 'raw' ) ) return null;
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return is_array( Subscription_Product_Service::terms( $product ) ) ? $product : null;
		}
		foreach ( $product->get_visible_children() as $child ) {
			$variation = wc_get_product( $child );
			if ( $variation && is_array( Subscription_Product_Service::terms( $variation ) ) ) return $product;
		}
		return null;
	}

	public function editor( int $id, \WC_Coupon $coupon ): void {
		if ( ! self::can_edit( $id ) ) return;
		$settings = self::settings( $coupon );
		woocommerce_wp_checkbox( [
			'id' => 'kiwe_checkout_offer_listed', 'label' => __( 'Show in checkout offers', 'dsa' ),
			'value' => $settings['checkoutListed'] ? 'yes' : 'no',
			'description' => __( 'Show this coupon in the Bricks Kiwe Checkout offers loop.', 'dsa' ),
		] );
		$product = $settings['membershipProductId'] ? wc_get_product( $settings['membershipProductId'] ) : null;
		?>
		<p class="form-field">
			<label for="kiwe_checkout_offer_membership"><?php esc_html_e( 'Kiwe membership product', 'dsa' ); ?></label>
			<input type="hidden" name="kiwe_checkout_offer_membership" value="">
			<select id="kiwe_checkout_offer_membership" name="kiwe_checkout_offer_membership" class="wc-product-search"
				style="width: 50%" data-placeholder="<?php esc_attr_e( 'Search for a subscription or variation', 'dsa' ); ?>"
				data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
				<option value=""></option>
				<?php if ( $settings['membershipProductId'] ) : ?>
					<option value="<?php echo esc_attr( $settings['membershipProductId'] ); ?>" selected>
						<?php echo esc_html( $product ? $product->get_formatted_name() : '#' . $settings['membershipProductId'] ); ?>
					</option>
				<?php endif; ?>
			</select>
			<span class="description"><?php esc_html_e( 'Applies automatically through the paid membership term. Leave blank for a regular coupon.', 'dsa' ); ?></span>
		</p>
		<?php
	}

	public function save_editor( int $id, \WC_Coupon $coupon ): void {
		if ( ! self::can_edit( $id ) || ! isset( $_POST['kiwe_checkout_offer_membership'] ) || ! is_string( $_POST['kiwe_checkout_offer_membership'] ) ) return;
		try {
			$value = wp_unslash( $_POST['kiwe_checkout_offer_membership'] );
			if ( '' !== $value && ! ctype_digit( $value ) ) throw new \DomainException( __( 'Enter a valid membership product ID.', 'dsa' ) );
			self::set_settings( $coupon, 'yes' === ( $_POST['kiwe_checkout_offer_listed'] ?? '' ), (int) $value );
		} catch ( \DomainException $error ) {
			\WC_Admin_Meta_Boxes::add_error( $error->getMessage() );
		}
	}

	public function validate_membership( bool $valid, \WC_Coupon $coupon ): bool {
		$id = self::settings( $coupon )['membershipProductId'];
		return $valid && ( ! $id || isset( Membership_Directory::paid_product_ids( get_current_user_id() )[ $id ] ) );
	}

	/** Woo still validates restrictions and calculates actual discounts. Discard lost access before totals. */
	public function apply_membership_offers( \WC_Cart $cart ): void {
		if ( ! wc_coupons_enabled() ) return;
		$applied = $cart->get_coupons();
		$discounts = new \WC_Discounts( $cart );
		$codes = [];
		$exclusive = false;
		foreach ( $applied as $code => $coupon ) {
			if ( self::settings( $coupon )['membershipProductId'] && true !== $discounts->is_coupon_valid( $coupon ) ) continue;
			$codes[] = $code;
			$exclusive = $exclusive || $coupon->get_individual_use();
		}
		$owned = $cart->is_empty() ? [] : Membership_Directory::paid_product_ids( get_current_user_id() );
		if ( $owned && ! $exclusive ) {
			$ids = self::coupon_ids( self::MEMBER, array_keys( $owned ) );
			foreach ( $ids as $id ) {
				$coupon = new \WC_Coupon( $id );
				$code = $coupon->get_code();
				if ( in_array( $code, $codes, true ) || ( $codes && $coupon->get_individual_use() )
					|| true !== $discounts->is_coupon_valid( $coupon ) ) continue;
				$codes[] = $code;
				if ( $coupon->get_individual_use() ) break;
			}
		}
		if ( $codes !== $cart->get_applied_coupons() ) $cart->set_applied_coupons( $codes );
	}

	private static function coupon_ids( string $key, string|array $value ): array {
		return get_posts( [
			'post_type'=>'shop_coupon', 'post_status'=>'publish', 'has_password'=>false,
			'posts_per_page'=>-1, 'fields'=>'ids', 'no_found_rows'=>true, 'orderby'=>'ID', 'order'=>'ASC',
			'meta_query'=>[ [ 'key'=>$key, 'value'=>$value, 'compare'=>is_array( $value ) ? 'IN' : '=' ] ],
		] );
	}

	private static function listed(): array {
		if ( null !== self::$listed ) return self::$listed;
		self::$listed = [];
		if ( ! wc_coupons_enabled() ) return [];
		foreach ( self::coupon_ids( self::LISTED, 'yes' ) as $id ) {
			$coupon = new \WC_Coupon( $id );
			$expires = $coupon->get_date_expires();
			if ( ! $coupon->get_id() || $coupon->get_email_restrictions() || ( $expires && $expires->getTimestamp() <= time() )
				|| ( $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) ) continue;
			$member = self::settings( $coupon )['membershipProductId'];
			$product = $member ? self::membership_product( $member ) : null;
			if ( ! $member || $product ) self::$listed[$id] = [ $coupon, $product ];
		}
		return self::$listed;
	}

	/** Sent in the current shopper's no-store checkout review. */
	public static function review( \WC_Cart $cart ): array {
		$states = [];
		$in_cart = [];
		foreach ( $cart->get_cart() as $item ) {
			$in_cart[(int) $item['product_id']] = true;
			if ( ! empty( $item['variation_id'] ) ) $in_cart[(int) $item['variation_id']] = true;
		}
		foreach ( self::listed() as $id => [ $coupon, $product ] ) {
			$code = $coupon->get_code();
			if ( $cart->has_discount( $code ) ) {
				$state = [ 'state'=>'applied' ];
			} elseif ( ! $product ) {
				$state = [ 'state'=>'apply', 'code'=>$code ];
			} else {
				$plan = $product->get_id();
				$owned = Membership_Directory::paid_product_ids( get_current_user_id() );
				if ( isset( $owned[$plan] ) ) $state = [ 'state'=>'unavailable' ];
				elseif ( isset( $in_cart[$plan] ) ) $state = [ 'state'=>'in-cart' ];
				elseif ( $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() ) {
					$state = [ 'state'=>'join', 'productId'=>$plan ];
				} else {
					$state = [ 'state'=>'choose', 'url'=>$product->get_permalink() ];
				}
			}
			$states[$id] = $state;
		}
		return $states;
	}

	public function query_options( array $options ): array {
		$options['queryTypes'][self::QUERY] = __( 'Kiwe Checkout offers', 'dsa' );
		return $options;
	}

	public function run_query( $results, $query ) {
		if ( self::QUERY !== ( $query->object_type ?? '' ) ) return $results;
		// The checkout is private. The loop itself contains only explicitly listed public fields.
		return array_map( static function ( $id, $offer ): array {
			[ $coupon, $product ] = $offer;
			return [
				'offerId'=>$id, 'title'=>$product ? $product->get_name() : $coupon->get_code(),
				'description'=>$coupon->get_description(),
			];
		}, array_keys( self::listed() ), self::listed() );
	}

	public function loop_id( $id, $object, $query ) {
		return self::QUERY === ( $query->object_type ?? '' ) ? (int) ( $object['offerId'] ?? 0 ) : $id;
	}

	public function tags( array $tags ): array {
		foreach ( [
			'kiwe_checkout_offer_id'=>__( 'Checkout offer ID', 'dsa' ),
			'kiwe_checkout_offer_title'=>__( 'Checkout offer title', 'dsa' ),
			'kiwe_checkout_offer_description'=>__( 'Checkout offer description', 'dsa' ),
		] as $tag => $label ) $tags[] = [ 'name'=>'{' . $tag . '}', 'label'=>$label, 'group'=>'Kiwe' ];
		return $tags;
	}

	public function render_tag( $tag ) {
		if ( ! is_string( $tag ) ) return $tag;
		$key = match ( trim( $tag, '{}' ) ) {
			'kiwe_checkout_offer_id'=>'offerId',
			'kiwe_checkout_offer_title'=>'title',
			'kiwe_checkout_offer_description'=>'description',
			default=>null,
		};
		if ( ! $key ) return $tag;
		$object = class_exists( \Bricks\Query::class ) ? \Bricks\Query::get_loop_object() : null;
		return is_array( $object ) && isset( $object['offerId'] ) ? esc_html( wp_strip_all_tags( (string) ( $object[$key] ?? '' ) ) ) : '';
	}

	public function render_content( $content ) {
		if ( ! is_string( $content ) || ! str_contains( $content, '{kiwe_checkout_offer_' ) ) return $content;
		return preg_replace_callback( '/\{kiwe_checkout_offer_(?:id|title|description)\}/', fn( $match ) => $this->render_tag( $match[0] ), $content );
	}
}
