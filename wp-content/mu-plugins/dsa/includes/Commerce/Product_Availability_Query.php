<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Adds filters to Bricks' native query so filtering happens before pagination. */
final class Product_Availability_Query {
	private array $membership_lines = [];

	public function __construct( private Purchase_Limit_Service $purchases ) {}

	public function register(): void {
		foreach ( [ 'div', 'block', 'container', 'section', 'posts', 'woocommerce-products' ] as $element ) {
			add_filter( 'bricks/elements/' . $element . '/controls', [ self::class, 'controls' ], 25 );
		}
		add_filter( 'bricks/posts/query_vars', [ $this, 'query_vars' ], 30, 4 );
		add_filter( 'posts_where', [ $this, 'where' ], 10, 2 );
	}

	public static function controls( array $controls ): array {
		$controls['kiweProductBilling'] = [
			'tab'=>'content', 'type'=>'select', 'label'=>__( 'Kiwe product billing', 'dsa' ),
			'options'=>[ 'one_time'=>__( 'One-time products', 'dsa' ), 'subscriptions'=>__( 'Subscriptions', 'dsa' ), 'both'=>__( 'Both', 'dsa' ) ],
			'placeholder'=>__( 'Use the normal query', 'dsa' ),
			'description'=>__( 'Select Products as the post type. This also hides products the signed-in shopper cannot buy again.', 'dsa' ),
		];
		return $controls;
	}

	public function query_vars( array $vars, array $settings, string $element_id = '', string $element_name = '' ): array {
		$billing = $settings['kiweProductBilling'] ?? $settings['query']['kiweProductBilling'] ?? '';
		$types = (array) ( $vars['post_type'] ?? ( 'woocommerce-products' === $element_name ? 'product' : '' ) );
		if ( ! in_array( $billing, [ 'one_time', 'subscriptions', 'both' ], true ) || $types !== [ 'product' ] ) return $vars;
		$vars['kiwe_product_billing'] = $billing;
		$vars['suppress_filters'] = false;
		if ( get_current_user_id() ) {
			// The selected cards reveal the current account's purchase eligibility.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
			do_action( 'litespeed_control_set_nocache', 'Personalized Kiwe product query' );
			if ( ! headers_sent() ) nocache_headers();
		}
		return $vars;
	}

	public function where( string $where, \WP_Query $query ): string {
		$billing = $query->get( 'kiwe_product_billing' );
		if ( ! in_array( $billing, [ 'one_time', 'subscriptions', 'both' ], true ) ) return $where;
		global $wpdb;
		$parent = $wpdb->posts . '.ID';
		$subscription = $this->terms_exist( $parent );
		if ( 'subscriptions' === $billing ) $where .= " AND $subscription";
		if ( 'one_time' === $billing ) $where .= " AND NOT $subscription";
		$customer = get_current_user_id();
		if ( ! $customer ) return $where;

		$bought = $this->purchases->purchased( $customer );
		if ( $bought ) {
			$ids = implode( ',', array_map( 'absint', $bought ) );
			$limited = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} kiwe_limit WHERE kiwe_limit.post_id = $parent AND kiwe_limit.meta_key = %s AND kiwe_limit.meta_value = 'yes')", Purchase_Limit_Service::META );
			$where .= " AND NOT ($parent IN ($ids) AND $limited)";
		}
		if ( 'one_time' === $billing ) return $where;

		$lines = $this->membership_lines[$customer] ??= Subscription_Quote_Service::blocked_membership_lines( $customer );
		$ids = array_values( array_filter( array_map( 'absint', array_column( $lines, 'productId' ) ) ) );
		if ( $ids ) {
			$list = implode( ',', array_unique( $ids ) );
			$where .= " AND NOT ($parent IN ($list) AND $subscription)";
		}
		$groups = array_unique( array_filter( array_column( $lines, 'membershipGroup' ), 'is_string' ) );
		foreach ( $groups as $group ) {
			if ( '' !== $group ) $where .= ' AND NOT ' . $this->terms_exist( $parent, $group );
		}
		return $where;
	}

	private function terms_exist( string $parent, string $group = '' ): string {
		global $wpdb;
		$terms = $wpdb->prepare( "kiwe_terms.meta_key = %s AND kiwe_terms.meta_value <> ''", Subscription_Product_Service::META );
		if ( '' !== $group ) {
			$terms .= $wpdb->prepare( ' AND kiwe_terms.meta_value LIKE %s', '%' . $wpdb->esc_like( serialize( 'membershipGroup' ) . serialize( $group ) ) . '%' );
		}
		return "EXISTS (SELECT 1 FROM {$wpdb->postmeta} kiwe_terms WHERE $terms
			AND (kiwe_terms.post_id = $parent OR kiwe_terms.post_id IN
			(SELECT kiwe_variation.ID FROM {$wpdb->posts} kiwe_variation WHERE kiwe_variation.post_parent = $parent
			AND kiwe_variation.post_type = 'product_variation' AND kiwe_variation.post_status = 'publish')))";
	}
}
