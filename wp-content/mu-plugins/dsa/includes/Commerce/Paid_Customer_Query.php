<?php

namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private, paginated account directory over Woo's authoritative order storage. */
final class Paid_Customer_Query {

	public static function users( array $args ): array {
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! current_user_can( 'list_users' ) || ! current_user_can( 'edit_users' ) || ! function_exists( 'wc_get_is_paid_statuses' ) ) return [];
		global $wpdb;
		$statuses = array_values( array_filter( array_map( static fn( $status ) => 'wc-' . sanitize_key( preg_replace( '/^wc-/', '', $status ) ), wc_get_is_paid_statuses() ) ) );
		if ( ! $statuses ) return [];
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			$table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$where = $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$table} kiwe_purchase WHERE kiwe_purchase.customer_id={$wpdb->users}.ID AND kiwe_purchase.type='shop_order' AND kiwe_purchase.status IN ({$placeholders}))", $statuses );
		} else {
			$where = $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} kiwe_buyer INNER JOIN {$wpdb->posts} kiwe_purchase ON kiwe_purchase.ID=kiwe_buyer.post_id WHERE kiwe_buyer.meta_key='_customer_user' AND kiwe_buyer.meta_value=CAST({$wpdb->users}.ID AS CHAR) AND kiwe_purchase.post_type='shop_order' AND kiwe_purchase.post_status IN ({$placeholders}))", $statuses );
		}
		// Order changes do not invalidate WordPress's user-query cache. Never serve
		// yesterday's buyer classification or widen this to a global users filter.
		$args['cache_results'] = false;
		$args['kiwe_paid_customer_scope'] = 'native-paid-v1';
		$restrict = static function ( $query ) use ( $where ): void {
			if ( 'native-paid-v1' === ( $query->query_vars['kiwe_paid_customer_scope'] ?? null ) ) $query->query_where .= $where;
		};
		add_action( 'pre_user_query', $restrict );
		try { return get_users( $args ); }
		finally { remove_action( 'pre_user_query', $restrict ); }
	}
}
