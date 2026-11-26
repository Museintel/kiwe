<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** An optional account limit on the WooCommerce parent product. */
final class Purchase_Limit_Service {
	public const META = '_kiwe_once_per_account';
	private const RESERVATION = '_kiwe_once_per_account_reserved';
	private array $paid = [];
	private string $checkout_lock = '';
	private static string $replacing = '';

	public static function allow_term_check( string $cart_key, callable $check ): bool {
		$previous = self::$replacing;
		self::$replacing = $cart_key;
		try {
			return $check();
		} finally {
			self::$replacing = $previous;
		}
	}

	public static function family( \WC_Product $product ): int {
		return $product->get_parent_id() ?: $product->get_id();
	}

	public static function enabled( int $id ): bool {
		return 'yes' === get_post_meta( $id, self::META, true );
	}

	public static function write( \WC_Product $product, bool $enabled ): void {
		if ( $enabled ) $product->update_meta_data( self::META, 'yes' );
		else $product->delete_meta_data( self::META );
	}

	public function register(): void {
		add_filter( 'woocommerce_is_sold_individually', [ $this, 'sold_individually' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add' ], 25, 4 );
		add_filter( 'woocommerce_update_cart_validation', [ $this, 'validate_quantity' ], 25, 4 );
		add_action( 'woocommerce_check_cart_items', [ $this, 'check_cart' ] );
		add_action( 'woocommerce_store_api_cart_errors', [ $this, 'store_cart_errors' ], 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout' ], 90, 2 );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'record_classic_reservation' ], 10 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'guard_store_order' ], 10 );
		add_filter( 'woocommerce_order_needs_payment', [ $this, 'guard_order_payment' ], 30, 2 );
		add_action( 'shutdown', [ $this, 'release_lock' ] );
	}

	public function sold_individually( bool $sold, \WC_Product $product ): bool {
		return $sold || self::enabled( self::family( $product ) );
	}

	/** WooCommerce CRUD handles both native order storage implementations. */
	private function families( int $customer, array $statuses, int $exclude = 0 ): array {
		if ( ! $customer ) return [];
		$page = 1;
		$products = [];
		do {
			$orders = wc_get_orders( [
				'type'=>'shop_order', 'customer_id'=>$customer, 'status'=>$statuses,
				'limit'=>50, 'page'=>$page++, 'parent'=>0, 'orderby'=>'ID', 'order'=>'DESC',
			] );
			foreach ( $orders as $order ) {
				if ( $order->get_id() === $exclude || $order->get_customer_id() !== $customer ) continue;
				if ( $order->has_status( 'pending' ) && 'yes' !== $order->get_meta( self::RESERVATION ) ) continue;
				foreach ( $order->get_items() as $item ) {
					$id = $item->get_product_id();
					if ( $id > 0 ) $products[$id] = true;
				}
			}
		} while ( count( $orders ) === 50 );
		return array_keys( $products );
	}

	public function purchased( int $customer ): array {
		return $this->paid[$customer] ??= $this->families( $customer, wc_get_is_paid_statuses() );
	}

	public function validate_add( bool $passed, int $product_id, int $quantity, int $variation = 0 ): bool {
		if ( ! $passed ) return false;
		$product = wc_get_product( $variation ?: $product_id );
		if ( ! $product ) return $passed;
		$id = self::family( $product );
		$customer = get_current_user_id();
		if ( self::enabled( $id ) ) {
			$message = $this->purchase_error( $id, $customer );
			if ( '' !== $message ) return $this->notice( $message );
			foreach ( WC()->cart?->get_cart() ?? [] as $key => $line ) {
				if ( $key !== self::$replacing && self::family( $line['data'] ) === $id ) $quantity += (int) $line['quantity'];
			}
			if ( $quantity > 1 ) return $this->notice( $this->quantity_message() );
		}
		if ( $customer && is_array( $terms = Subscription_Product_Service::terms( $product ) ) ) {
			try {
				Subscription_Quote_Service::assert_groups_available( $customer, [ [ 'productId'=>$id, 'membershipGroup'=>$terms['membershipGroup'] ?? '' ] ] );
			} catch ( \DomainException $error ) {
				return $this->notice( $error->getMessage() );
			}
		}
		return true;
	}

	public function validate_quantity( bool $passed, string $key, array $line, int $quantity ): bool {
		if ( ! $passed || $quantity < 1 ) return $passed;
		$id = self::family( $line['data'] );
		if ( ! self::enabled( $id ) ) return true;
		foreach ( WC()->cart?->get_cart() ?? [] as $other_key => $other ) {
			if ( $other_key !== $key && self::family( $other['data'] ) === $id ) $quantity += (int) $other['quantity'];
		}
		return $quantity <= 1 || $this->notice( $this->quantity_message() );
	}

	private function quantity_message(): string {
		return __( 'This product is limited to one purchase per account, including its variations.', 'dsa' );
	}

	private function purchase_error( int $id, int $customer ): string {
		if ( ! $customer ) return __( 'Sign in to buy this product. It can be purchased once per account.', 'dsa' );
		if ( ! in_array( $id, $this->purchased( $customer ), true ) ) return '';
		return __( 'You already bought this product. Remove it from your cart to continue with other items.', 'dsa' );
	}

	private function notice( string $message ): bool {
		if ( ! wc_has_notice( $message, 'error' ) ) wc_add_notice( $message, 'error' );
		return false;
	}

	private function limited_counts( \WC_Cart $cart ): array {
		$counts = [];
		foreach ( $cart->get_cart() as $line ) {
			$id = self::family( $line['data'] );
			if ( self::enabled( $id ) ) $counts[$id] = ( $counts[$id] ?? 0 ) + (int) $line['quantity'];
		}
		return $counts;
	}

	public function store_cart_errors( \WP_Error $errors, \WC_Cart $cart ): void {
		foreach ( $this->limited_counts( $cart ) as $id => $count ) {
			$message = $count > 1 ? $this->quantity_message() : $this->purchase_error( $id, get_current_user_id() );
			if ( '' !== $message ) $errors->add( 'kiwe_purchase_limit', $message );
		}
	}

	public function review_error( \WC_Cart $cart ): string {
		$errors = new \WP_Error();
		$this->store_cart_errors( $errors, $cart );
		return $errors->get_error_message();
	}

	public function check_cart(): void {
		$errors = new \WP_Error();
		$this->store_cart_errors( $errors, WC()->cart );
		foreach ( $errors->get_error_messages() as $message ) $this->notice( $message );
	}

	public function validate_checkout( array $data, \WP_Error $errors ): void {
		if ( $errors->has_errors() || ! ( $counts = $this->limited_counts( WC()->cart ) ) ) return;
		$this->store_cart_errors( $errors, WC()->cart );
		if ( $errors->has_errors() ) return;
		$customer = get_current_user_id();
		$resume = wc_get_order( WC()->session ? WC()->session->get( 'order_awaiting_payment' ) : 0 );
		$reuse = $resume && $resume->get_customer_id() === $customer && $resume->has_status( [ 'pending', 'failed' ] )
			&& $resume->has_cart_hash( WC()->cart->get_cart_hash() ) ? $resume->get_id() : 0;
		try {
			$this->reserve( $customer, array_keys( $counts ), $reuse );
		} catch ( \RuntimeException $error ) {
			$errors->add( 'kiwe_purchase_limit', $error->getMessage() );
		}
	}

	public function guard_store_order( \WC_Order $order ): void {
		$counts = $this->order_counts( $order );
		if ( ! $counts ) return;
		try {
			if ( max( $counts ) > 1 ) throw new \RuntimeException( $this->quantity_message() );
			if ( ! get_current_user_id() || get_current_user_id() !== $order->get_customer_id() ) {
				throw new \RuntimeException( __( 'Sign in to the account buying this product.', 'dsa' ) );
			}
			$this->reserve( $order->get_customer_id(), array_keys( $counts ), $order->get_id() );
			$this->record_classic_reservation( $order );
			$order->save_meta_data();
		} catch ( \RuntimeException $error ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'kiwe_purchase_limit', $error->getMessage(), 409 );
		}
	}

	public function record_classic_reservation( \WC_Order $order ): void {
		if ( '' !== $this->checkout_lock && $this->order_counts( $order ) ) $order->update_meta_data( self::RESERVATION, 'yes' );
	}

	private function order_counts( \WC_Order $order ): array {
		$counts = [];
		foreach ( $order->get_items() as $item ) {
			$id = $item->get_product_id();
			if ( self::enabled( $id ) ) $counts[$id] = ( $counts[$id] ?? 0 ) + $item->get_quantity();
		}
		return $counts;
	}

	public function guard_order_payment( bool $needs_payment, \WC_Order $order ): bool {
		if ( ! $needs_payment || ! is_checkout_pay_page() || $order->get_parent_id() > 0
			|| ! ( $counts = $this->order_counts( $order ) ) ) return $needs_payment;
		$customer = $order->get_customer_id();
		if ( ! $customer || $customer !== get_current_user_id() || max( $counts ) > 1 ) return false;
		// Rendering the pay page must not acquire a write lock.
		if ( ! isset( $_POST['woocommerce_pay'] ) ) return ! array_intersect( array_keys( $counts ), $this->purchased( $customer ) );
		try {
			$this->reserve( $customer, array_keys( $counts ), $order->get_id() );
			return true;
		} catch ( \RuntimeException $error ) {
			$this->notice( $error->getMessage() );
			return false;
		}
	}

	private function reserve( int $customer, array $products, int $exclude ): void {
		$lock = $this->checkout_lock ?: Subscription_Billing_Service::lock( 'once-per-account', $customer );
		$this->checkout_lock = $lock;
		$existing = $this->families( $customer, array_merge( wc_get_is_paid_statuses(), [ 'pending', 'on-hold' ] ), $exclude );
		if ( array_intersect( $products, $existing ) ) {
			$this->release_lock();
			throw new \RuntimeException( __( 'You already bought this product or have an order awaiting payment. Check your orders before buying again.', 'dsa' ) );
		}
	}

	public function release_lock(): void {
		if ( '' === $this->checkout_lock ) return;
		Subscription_Billing_Service::unlock( $this->checkout_lock );
		$this->checkout_lock = '';
	}
}
