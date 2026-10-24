<?php

namespace DSA\Commerce;

use DSA\Utilities\Atomic_Rate_Limiter;
use DSA\Diagnostics\Runtime_Profiler;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Store_Analytics_Service {
	private const DB_VERSION = '3';
	private const DB_OPTION = 'dsa_store_analytics_db_version';
	private const CLEANUP_HOOK = 'dsa_store_analytics_cleanup';

	protected static $cart_source = 'woocommerce';

	protected $settings;
	private ?Store_Commerce_Analytics_Service $commerce_extension = null;
	private bool $registered = false;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		if ( $this->registered ) return;
		$this->registered = true;
		add_action( 'admin_init', [ $this, 'maybe_install' ], 8 );
		add_action( 'init', [ $this, 'maybe_schedule_cleanup' ], 20 );
		add_action( self::CLEANUP_HOOK, [ $this, 'scheduled_cleanup' ] );
		add_action( 'template_redirect', [ $this, 'record_store_visit' ], 40 );
		add_action( 'wp_login', [ $this, 'record_user_login' ], 20, 2 );
		add_action( 'user_register', [ $this, 'record_user_register' ], 20, 1 );
		add_action( 'dsa_search_performed', [ $this, 'record_search_performed' ], 20, 1 );
		if ( function_exists( 'WC' ) ) $this->register_commerce_hooks();
		else add_action( 'plugins_loaded', [ $this, 'register_commerce_hooks' ], 30 );
		add_action( 'admin_post_dsa_store_analytics_clear', [ $this, 'admin_clear_events' ] );
	}

	/** News reporting has no Woo callbacks; native commerce is resolved after plugins load. */
	public function register_commerce_hooks(): void{
		if ( \DSA\Apps\Store_App_Service::enabled() ) $this->commerce_extension()->register_commerce_hooks();
	}
	public static function mark_cart_source( string $source ): void {
		self::$cart_source = sanitize_key( $source ) ?: 'woocommerce';
	}

	public static function reset_cart_source(): void {
		self::$cart_source = 'woocommerce';
	}

	public function current_identity(): array {
		return $this->customer_identity();
	}

	public function identity_for_user( int $user_id ): array {
		return $this->customer_identity( [ 'user_id' => $user_id ] );
	}

	public function maybe_install(): void {
		if ( ! current_user_can( 'kiwe_manage_context' ) ) return;
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		$table = $this->events_table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(40) NOT NULL DEFAULT '',
			source varchar(40) NOT NULL DEFAULT '',
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			quantity int(11) unsigned NOT NULL DEFAULT 0,
			cart_key varchar(80) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_hash char(64) NOT NULL DEFAULT '',
			visitor_hash char(64) NOT NULL DEFAULT '',
			contact_hash char(64) NOT NULL DEFAULT '',
			contact_type varchar(20) NOT NULL DEFAULT '',
			phonekey_verified tinyint(1) NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_total decimal(18,4) NOT NULL DEFAULT 0,
			context varchar(80) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY product_id (product_id),
			KEY customer_hash (customer_hash),
			KEY visitor_hash (visitor_hash),
			KEY adoption (event_type, created_at, visitor_hash),
			KEY contact_hash (contact_hash),
			KEY order_id (order_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public function record_woocommerce_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void{
		$this->commerce_extension()->record_woocommerce_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data);
	}
	public function record_store_visit(): void {
		if ( ! $this->is_trackable_frontend_request() ) {
			return;
		}

		$context = $this->current_route_context();
		$type    = 'checkout' === $context ? 'checkout_visit' : 'visit';
		$ttl     = 'checkout_visit' === $type ? 20 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;

		$recorded = $this->record_throttled_event(
			$type,
			$ttl,
			[
				'event_type' => $type,
				'source'     => 'site',
				'context'    => $context,
			]
		);

		if ( $recorded ) {
			$post_id = absint( get_queried_object_id() );
			do_action(
				'dsa_analytics_visit_recorded',
				[
					'context'      => $context,
					'event_type'   => $type,
					'visitor_hash' => $this->visitor_hash(),
					'user_id'      => get_current_user_id(),
					'post_id'      => $post_id,
					'post_title'   => $post_id ? wp_strip_all_tags( get_the_title( $post_id ) ) : '',
				]
			);
		}
	}

	public function record_user_login( string $user_login, $user ): void {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;

		$this->record_cart_event(
			[
				'event_type' => 'user_login',
				'source'     => 'wordpress',
				'user_id'    => $user_id,
				'context'    => 'authenticated',
			]
		);
	}

	public function record_user_register( int $user_id ): void {
		$this->record_cart_event(
			[
				'event_type' => 'user_register',
				'source'     => 'wordpress',
				'user_id'    => $user_id,
				'context'    => 'registered',
			]
		);
	}

	public function record_search_performed( array $event ): void {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$query  = sanitize_text_field( (string) ( $event['query'] ?? '' ) );
		$prefix = sanitize_text_field( (string) ( $event['prefix'] ?? '' ) );
		$scope  = sanitize_key( (string) ( $event['scope'] ?? 'all' ) );
		$term   = '' !== $query ? $query : $prefix;
		if ( '' === $term ) return;

		$bucket = 'search_event|' . $this->visitor_hash() . '|' . strtolower( $scope . '|' . $term );
		if ( ! Atomic_Rate_Limiter::allow( $bucket, 1, 5 * MINUTE_IN_SECONDS ) ) return;

		$this->record_cart_event(
			[
				'event_type' => 'search',
				'source'     => $scope,
				'cart_key'   => function_exists( 'mb_substr' ) ? mb_substr( $term, 0, 80 ) : substr( $term, 0, 80 ),
				'quantity'   => absint( $event['total'] ?? 0 ),
				'context'    => '' !== $query ? 'query' : 'alphabet',
			]
		);
	}

	public function search_event_rows( int $days = 30, int $limit = 100 ): array {
		global $wpdb;

		$this->maybe_install();
		$days  = max( 0, min( 365, $days ) );
		$limit = max( 1, min( 250, $limit ) );
		$table = $this->events_table();
		$where = "event_type = 'search' AND cart_key <> ''";
		$params = [];
		if ( $days > 0 ) {
			$where .= ' AND created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		}
		$sql = "SELECT source AS family, cart_key AS term, context, COUNT(*) AS searches, COUNT(DISTINCT NULLIF(visitor_hash, '')) AS visitors, COUNT(DISTINCT NULLIF(user_id, 0)) AS users, MAX(created_at) AS last_searched FROM {$table} WHERE {$where} GROUP BY source, cart_key, context ORDER BY searches DESC, last_searched DESC LIMIT %d";
		$params[] = $limit;
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	public function record_checkout_order_processed( int $order_id, $posted_data = null, $order = null ): void{
		$this->commerce_extension()->record_checkout_order_processed($order_id, $posted_data, $order);
	}
	public function record_store_api_order_processed( $order ): void{
		$this->commerce_extension()->record_store_api_order_processed($order);
	}
	public function record_paid_order( $order_id ): void{
		$this->commerce_extension()->record_paid_order($order_id);
	}
	public function record_order_refund( int $order_id, int $refund_id ): void{
		$this->commerce_extension()->record_order_refund($order_id, $refund_id);
	}
	public function record_cart_event( array $event ): void {
		global $wpdb;

		$this->maybe_install();

		$identity = $this->customer_identity( $event );
		$profile = Runtime_Profiler::start();
		$inserted = $wpdb->insert(
			$this->events_table(),
			[
				'event_type'        => sanitize_key( $event['event_type'] ?? 'cart_add' ),
				'source'            => sanitize_key( $event['source'] ?? 'woocommerce' ),
				'product_id'        => absint( $event['product_id'] ?? 0 ),
				'variation_id'      => absint( $event['variation_id'] ?? 0 ),
				'quantity'          => max( 0, absint( $event['quantity'] ?? 0 ) ),
				'cart_key'          => sanitize_text_field( (string) ( $event['cart_key'] ?? '' ) ),
				'user_id'           => (int) $identity['user_id'],
				'customer_hash'     => (string) $identity['customer_hash'],
				'visitor_hash'      => (string) $identity['visitor_hash'],
				'contact_hash'      => (string) $identity['contact_hash'],
				'contact_type'      => (string) $identity['contact_type'],
				'phonekey_verified' => ! empty( $identity['phonekey_verified'] ) ? 1 : 0,
				'order_id'          => absint( $event['order_id'] ?? 0 ),
				'order_total'       => max( 0, (float) ( $event['order_total'] ?? 0 ) ),
				'context'           => sanitize_key( $event['context'] ?? '' ),
				'created_at'        => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%s' ]
		);
		Runtime_Profiler::finish( 'store_analytics.insert', $profile, false !== $inserted );

		if ( false !== $inserted ) {
			do_action(
				'dsa_analytics_activity_recorded',
				[
					'event_type'   => sanitize_key( $event['event_type'] ?? 'cart_add' ),
					'source'       => sanitize_key( $event['source'] ?? 'woocommerce' ),
					'product_id'   => absint( $event['product_id'] ?? 0 ),
					'variation_id' => absint( $event['variation_id'] ?? 0 ),
					'quantity'     => max( 0, absint( $event['quantity'] ?? 0 ) ),
					'context'      => sanitize_key( $event['context'] ?? '' ),
					'object_title' => sanitize_text_field( (string) ( $event['object_title'] ?? '' ) ),
					'user_id'      => (int) $identity['user_id'],
					'visitor_hash' => (string) $identity['visitor_hash'],
				]
			);
		}
	}

	public function maybe_schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
	}

	public function scheduled_cleanup(): void {
		$days = max( 30, min( 730, (int) apply_filters( 'dsa_store_analytics_retention_days', 180 ) ) );
		$this->purge_events_older_than( $days );
	}

	public function record_adoption_event( string $event_type, string $context = '' ): void {
		$allowed = [
			'pwa_primer_ok',
			'pwa_install_intent',
			'pwa_prompt_accepted',
			'pwa_install_dismissed',
			'pwa_installed',
			'pwa_standalone',
			'notification_prompt',
			'notification_granted',
			'notification_denied',
			'notification_preferences_saved',
		];
		$event_type = sanitize_key( $event_type );

		if ( ! in_array( $event_type, $allowed, true ) ) {
			return;
		}

		$this->record_throttled_event(
			$event_type,
			DAY_IN_SECONDS,
			[
				'event_type' => $event_type,
				'source'     => 0 === strpos( $event_type, 'pwa_' ) ? 'pwa' : 'notifications',
				'context'    => sanitize_key( $context ),
			]
		);
	}

	public function adoption_summary( int $days = 30 ): array {
		global $wpdb;

		$this->maybe_install();
		$days = max( 0, min( 365, $days ) );
		$table = $this->events_table();
		$where = "a.event_type IN ('pwa_primer_ok','pwa_install_intent','pwa_prompt_accepted','pwa_install_dismissed','pwa_installed','pwa_standalone','notification_prompt','notification_granted','notification_denied','notification_preferences_saved')";
		$params = [];

		if ( $days > 0 ) {
			$where .= ' AND a.created_at >= %s';
			$params[] = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ), wp_timezone() );
		}

		$count_sql = "
			SELECT
				COUNT(DISTINCT CASE WHEN a.event_type = 'pwa_primer_ok' THEN a.visitor_hash END) AS primer_ok,
				COUNT(DISTINCT CASE WHEN a.event_type = 'pwa_install_intent' THEN a.visitor_hash END) AS install_intent,
				COUNT(DISTINCT CASE WHEN a.event_type = 'pwa_prompt_accepted' THEN a.visitor_hash END) AS prompt_accepted,
				COUNT(DISTINCT CASE WHEN a.event_type = 'pwa_install_dismissed' THEN a.visitor_hash END) AS install_dismissed,
				COUNT(DISTINCT CASE WHEN a.event_type IN ('pwa_installed','pwa_standalone') THEN a.visitor_hash END) AS confirmed_installs,
				COUNT(DISTINCT CASE WHEN a.event_type = 'pwa_standalone' THEN a.visitor_hash END) AS standalone_launches,
				COUNT(DISTINCT CASE WHEN a.event_type = 'notification_granted' THEN a.visitor_hash END) AS notifications_enabled,
				COUNT(DISTINCT CASE WHEN a.event_type = 'notification_denied' THEN a.visitor_hash END) AS notifications_denied,
				COUNT(DISTINCT CASE WHEN a.event_type = 'notification_preferences_saved' THEN a.visitor_hash END) AS notification_preferences_saved,
				COUNT(DISTINCT CASE WHEN a.event_type IN ('pwa_installed','pwa_standalone') AND COALESCE(identity_events.user_id, 0) > 0 THEN a.visitor_hash END) AS app_users,
				COUNT(DISTINCT CASE WHEN a.event_type IN ('pwa_installed','pwa_standalone') AND COALESCE(identity_events.user_id, 0) = 0 THEN a.visitor_hash END) AS app_anonymous
			FROM {$table} a
			LEFT JOIN (
				SELECT visitor_hash, MAX(user_id) AS user_id
				FROM {$table}
				WHERE visitor_hash <> ''
				GROUP BY visitor_hash
			) identity_events ON identity_events.visitor_hash = a.visitor_hash
			WHERE {$where}
		";
		$counts = empty( $params )
			? $wpdb->get_row( $count_sql, ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( $count_sql, $params ), ARRAY_A );

		$rows_sql = "
			SELECT
				a.visitor_hash,
				MAX(a.created_at) AS last_event_at,
				GROUP_CONCAT(DISTINCT a.event_type ORDER BY a.event_type SEPARATOR ',') AS events,
				GROUP_CONCAT(DISTINCT NULLIF(a.context, '') ORDER BY a.context SEPARATOR ',') AS contexts,
				MAX(COALESCE(identity_events.user_id, 0)) AS user_id,
				MAX(COALESCE(identity_events.phonekey_verified, 0)) AS phonekey_verified
			FROM {$table} a
			LEFT JOIN (
				SELECT
					visitor_hash,
					MAX(user_id) AS user_id,
					MAX(phonekey_verified) AS phonekey_verified
				FROM {$table}
				WHERE visitor_hash <> ''
				GROUP BY visitor_hash
			) identity_events ON identity_events.visitor_hash = a.visitor_hash
			WHERE {$where}
			GROUP BY a.visitor_hash
			ORDER BY last_event_at DESC
			LIMIT 100
		";
		$raw_rows = empty( $params )
			? $wpdb->get_results( $rows_sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $rows_sql, $params ), ARRAY_A );
		$rows = [];

		foreach ( is_array( $raw_rows ) ? $raw_rows : [] as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$user = $user_id ? get_userdata( $user_id ) : false;
			$rows[] = [
				'visitor'         => substr( sanitize_text_field( (string) ( $row['visitor_hash'] ?? '' ) ), 0, 10 ),
				'userId'          => $user_id,
				'userName'        => $user ? $user->display_name : '',
				'userEditUrl'     => $user_id ? get_edit_user_link( $user_id ) : '',
				'phonekeyVerified'=> ! empty( $row['phonekey_verified'] ),
				'events'          => array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $row['events'] ?? '' ) ) ) ) ),
				'contexts'        => array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $row['contexts'] ?? '' ) ) ) ) ),
				'lastEventAt'     => sanitize_text_field( (string) ( $row['last_event_at'] ?? '' ) ),
			];
		}

		return [
			'primerOk'             => (int) ( $counts['primer_ok'] ?? 0 ),
			'installIntent'        => (int) ( $counts['install_intent'] ?? 0 ),
			'promptAccepted'       => (int) ( $counts['prompt_accepted'] ?? 0 ),
			'installDismissed'     => (int) ( $counts['install_dismissed'] ?? 0 ),
			'confirmedInstalls'    => (int) ( $counts['confirmed_installs'] ?? 0 ),
			'standaloneLaunches'   => (int) ( $counts['standalone_launches'] ?? 0 ),
			'notificationsEnabled' => (int) ( $counts['notifications_enabled'] ?? 0 ),
			'notificationsDenied'  => (int) ( $counts['notifications_denied'] ?? 0 ),
			'preferencesSaved'     => (int) ( $counts['notification_preferences_saved'] ?? 0 ),
			'appUsers'             => (int) ( $counts['app_users'] ?? 0 ),
			'appAnonymous'         => (int) ( $counts['app_anonymous'] ?? 0 ),
			'rows'                 => $rows,
		];
	}

	public function summary(): array {
		return [
			'cards' => [
				'today' => $this->range_summary( 1 ),
				'week'  => $this->range_summary( 7 ),
				'month' => $this->range_summary( 30 ),
				'all'   => $this->range_summary( 0 ),
			],
			'funnel' => [
				'today' => $this->funnel_summary( 1 ),
				'week'  => $this->funnel_summary( 7 ),
				'month' => $this->funnel_summary( 30 ),
				'all'   => $this->funnel_summary( 0 ),
			],
			'notes' => [
				__( 'Analytics stores privacy-light events, product IDs, source labels, user IDs when logged in, salted visitor/contact hashes, and order IDs/totals for funnel math. It does not store raw IPs, phones, emails, names, or raw Key.kiwe anchors.', 'dsa' ),
				__( 'Key.kiwe, Woo customer, and checkout contact signals are represented as hashed anchors so abandoned-cart intelligence can grow without exposing identity data.', 'dsa' ),
			],
		];
	}

	public function funnel_summary( int $days = 30 ): array {
		global $wpdb;

		$this->maybe_install();
		$days = max( 0, min( 365, $days ) );
		$table = $this->events_table();
		$where = '1=1';
		$params = [];

		if ( $days > 0 ) {
			$where .= ' AND created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		}

		$sql = "
			SELECT
				COUNT(DISTINCT NULLIF(visitor_hash, '')) AS visitors,
				COUNT(DISTINCT CASE WHEN user_id > 0 OR event_type IN ('user_login', 'user_register') THEN visitor_hash END) AS users,
				COUNT(DISTINCT CASE WHEN contact_hash <> '' THEN visitor_hash END) AS identified,
				COUNT(DISTINCT CASE WHEN event_type = 'cart_add' THEN visitor_hash END) AS cart_visitors,
				COUNT(DISTINCT CASE WHEN event_type = 'checkout_visit' THEN visitor_hash END) AS checkout_visitors,
				COUNT(DISTINCT CASE WHEN event_type = 'purchase' THEN visitor_hash END) AS purchase_visitors,
				COUNT(DISTINCT CASE WHEN event_type = 'purchase' AND order_id > 0 THEN order_id END) AS orders,
				COALESCE(SUM(CASE WHEN event_type = 'purchase' THEN order_total WHEN event_type = 'refund' THEN -order_total ELSE 0 END), 0) AS revenue
			FROM {$table}
			WHERE {$where}
		";
		$row = $params ? $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_row( $sql, ARRAY_A );
		$abandoned = $this->abandoned_cart_count( $days );
		$visitors = (int) ( $row['visitors'] ?? 0 );
		$cart_visitors = (int) ( $row['cart_visitors'] ?? 0 );
		$checkout_visitors = (int) ( $row['checkout_visitors'] ?? 0 );
		$purchase_visitors = (int) ( $row['purchase_visitors'] ?? 0 );

		return [
			'visitors'          => $visitors,
			'users'             => (int) ( $row['users'] ?? 0 ),
			'identified'        => (int) ( $row['identified'] ?? 0 ),
			'cart_visitors'     => $cart_visitors,
			'checkout_visitors' => $checkout_visitors,
			'purchase_visitors' => $purchase_visitors,
			'orders'            => (int) ( $row['orders'] ?? 0 ),
			'revenue'           => $this->price_text( (float) ( $row['revenue'] ?? 0 ) ),
			'abandoned_carts'   => $abandoned,
			'cart_rate'         => $visitors > 0 ? round( ( $cart_visitors / $visitors ) * 100, 1 ) : 0,
			'checkout_rate'     => $cart_visitors > 0 ? round( ( $checkout_visitors / $cart_visitors ) * 100, 1 ) : 0,
			'purchase_rate'     => $checkout_visitors > 0 ? round( ( $purchase_visitors / $checkout_visitors ) * 100, 1 ) : 0,
			'abandon_rate'      => $cart_visitors > 0 ? round( ( $abandoned / $cart_visitors ) * 100, 1 ) : 0,
		];
	}

	public function visitor_count_between( int $start_timestamp, int $end_timestamp ): int {
		global $wpdb;

		$this->maybe_install();
		$start_timestamp = max( 0, $start_timestamp );
		$end_timestamp   = max( $start_timestamp, $end_timestamp );

		if ( ! $start_timestamp || ! $end_timestamp || $end_timestamp <= $start_timestamp ) {
			return 0;
		}

		$table = $this->events_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT NULLIF(visitor_hash, '')) FROM {$table} WHERE created_at >= %s AND created_at < %s",
				wp_date( 'Y-m-d H:i:s', $start_timestamp, wp_timezone() ),
				wp_date( 'Y-m-d H:i:s', $end_timestamp, wp_timezone() )
			)
		);
	}

	public function product_event_rows( int $limit = 40 ): array{
		return $this->commerce_extension()->product_event_rows($limit);
	}
	public function cart_event_rows( int $days = 0, int $limit = 50, string $search = '' ): array{
		return $this->commerce_extension()->cart_event_rows($days, $limit, $search);
	}
	public function purge_events_older_than( int $days ): int {
		global $wpdb;

		$this->maybe_install();
		$days = max( 1, min( 3650, $days ) );
		$table = $this->events_table();
		$before = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $before ) );
	}

	public function co_purchase_rows( int $limit = 40 ): array{
		return $this->commerce_extension()->co_purchase_rows($limit);
	}
	public function co_purchase_product_summary_rows( int $limit = 50 ): array{
		return $this->commerce_extension()->co_purchase_product_summary_rows($limit);
	}
	public function linked_product_rows( int $limit = 60 ): array{
		return $this->commerce_extension()->linked_product_rows($limit);
	}
	public function bestseller_status(): array{
		return $this->commerce_extension()->bestseller_status();
	}
	public function cart_upsell_offers( int $limit = 3 ): array{
		return $this->commerce_extension()->cart_upsell_offers($limit);
	}
	public function cart_item_data_for_upsell( int $product_id, int $trigger_id ): array{
		return $this->commerce_extension()->cart_item_data_for_upsell($product_id, $trigger_id);
	}
	public function claim_cart_upsell_discount( int $product_id, int $trigger_id ){
		return $this->commerce_extension()->claim_cart_upsell_discount($product_id, $trigger_id);
	}
	public function restore_cart_item_discount( $cart_item, $values ){
		return $this->commerce_extension()->restore_cart_item_discount($cart_item, $values);
	}
	public function apply_cart_upsell_discounts( $cart ): void{
		$this->commerce_extension()->apply_cart_upsell_discounts($cart);
	}
	public function virtual_upsell_coupon_data( $coupon_data, $data, $coupon ){
		return $this->commerce_extension()->virtual_upsell_coupon_data($coupon_data, $data, $coupon);
	}
	public function validate_upsell_coupon( $valid, $coupon, $discounts = null ): bool{
		return $this->commerce_extension()->validate_upsell_coupon($valid, $coupon, $discounts);
	}
	public function upsell_coupon_items( array $items, $coupon, $discounts = null ): array{
		return $this->commerce_extension()->upsell_coupon_items($items, $coupon, $discounts);
	}
	public function upsell_coupon_label( string $label, $coupon ): string{
		return $this->commerce_extension()->upsell_coupon_label($label, $coupon);
	}
	public function sync_cart_upsell_coupons( $cart ): void{
		$this->commerce_extension()->sync_cart_upsell_coupons($cart);
	}

	public function render_product_upsell_fields(): void{
		$this->commerce_extension()->render_product_upsell_fields();
	}
	public function save_product_upsell_fields( int $post_id ): void{
		$this->commerce_extension()->save_product_upsell_fields($post_id);
	}
	public function admin_clear_events(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dsa' ) );
		}

		check_admin_referer( 'dsa_store_analytics_clear' );

		global $wpdb;
		$this->maybe_install();
		$table = $this->events_table();
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-analytics', 'cleared' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	protected function range_summary( int $days ): array {
		global $wpdb;

		$this->maybe_install();
		$table = $this->events_table();
		$where = '1=1';
		$params = [];

		if ( $days > 0 ) {
			$where .= ' AND created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		}

		$sql = "
			SELECT COUNT(*) AS events,
				COALESCE(SUM(CASE WHEN event_type = 'cart_add' THEN 1 ELSE 0 END), 0) AS add_events,
				COALESCE(SUM(CASE WHEN event_type = 'cart_update' THEN 1 ELSE 0 END), 0) AS update_events,
				COALESCE(SUM(CASE WHEN event_type = 'cart_remove' THEN 1 ELSE 0 END), 0) AS remove_events,
				COALESCE(SUM(CASE WHEN event_type = 'cart_upsell_claim' THEN 1 ELSE 0 END), 0) AS claim_events,
				COALESCE(SUM(CASE WHEN event_type = 'cart_add' THEN quantity ELSE 0 END), 0) AS quantity,
				COUNT(DISTINCT customer_hash) AS customers,
				COUNT(DISTINCT NULLIF(user_id, 0)) AS logged_in_users,
				COALESCE(SUM(phonekey_verified), 0) AS phonekey_verified_events
			FROM {$table}
			WHERE {$where}
		";
		$row = $params ? $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_row( $sql, ARRAY_A );

		return [
			'events'                   => (int) ( $row['events'] ?? 0 ),
			'add_events'               => (int) ( $row['add_events'] ?? 0 ),
			'update_events'            => (int) ( $row['update_events'] ?? 0 ),
			'remove_events'            => (int) ( $row['remove_events'] ?? 0 ),
			'claim_events'             => (int) ( $row['claim_events'] ?? 0 ),
			'quantity'                 => (int) ( $row['quantity'] ?? 0 ),
			'customers'                => (int) ( $row['customers'] ?? 0 ),
			'logged_in_users'          => (int) ( $row['logged_in_users'] ?? 0 ),
			'phonekey_verified_events' => (int) ( $row['phonekey_verified_events'] ?? 0 ),
		];
	}





	protected function record_throttled_event( string $event_type, int $ttl, array $event ): bool {
		$visitor_hash = $this->visitor_hash();
		$bucket = 'store|' . $event_type . '|' . $visitor_hash . '|' . sanitize_key( (string) ( $event['context'] ?? '' ) ) . '|' . absint( $event['product_id'] ?? get_queried_object_id() );

		if ( ! Atomic_Rate_Limiter::allow( $bucket, 1, max( MINUTE_IN_SECONDS, $ttl ) ) ) {
			return false;
		}
		$this->record_cart_event( $event );
		return true;
	}

	protected function is_trackable_frontend_request(): bool {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		return ! is_feed() && ! is_robots() && ! is_trackback();
	}

	protected function current_route_context(): string {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'shop';
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return 'account';
		}

		return is_front_page() ? 'home' : 'content';
	}

	protected function abandoned_cart_count( int $days ): int {
		global $wpdb;

		$table = $this->events_table();
		$where = "visitor_hash <> ''";
		$params = [];

		if ( $days > 0 ) {
			$where .= ' AND created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		}

		$inactive_before = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$sql = "
			SELECT COUNT(*)
			FROM (
				SELECT visitor_hash
				FROM {$table}
				WHERE {$where}
				GROUP BY visitor_hash
				HAVING SUM(CASE WHEN event_type = 'cart_add' THEN 1 ELSE 0 END) > 0
				AND SUM(CASE WHEN event_type = 'purchase' THEN 1 ELSE 0 END) = 0
				AND MAX(created_at) < %s
			) abandoned
		";
		$params[] = $inactive_before;

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	protected function customer_identity( array $event = [] ): array {
		$user_id = absint( $event['user_id'] ?? get_current_user_id() );
		$raw = $user_id ? 'user:' . $user_id : '';

		if ( ! $raw && \DSA\Apps\Store_App_Service::enabled() && WC() && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			$raw = 'wc:' . (string) WC()->session->get_customer_id();
		}

		if ( ! $raw && function_exists( 'wp_get_session_token' ) ) {
			$raw = 'wp:' . (string) wp_get_session_token();
		}

		if ( ! $raw && function_exists( 'pk_visitor_hash' ) ) {
			$raw = 'pkv:' . (string) pk_visitor_hash();
		}

		if ( ! $raw ) {
			$ip = function_exists( 'pk_ip' ) ? (string) pk_ip() : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
			$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
			$raw = 'anon:' . wp_hash( $ip . '|' . $ua );
		}

		$verified = false;

		if ( $user_id ) {
			$verified = function_exists( 'pk_account_verified' )
				? (bool) pk_account_verified( $user_id )
				: (bool) get_user_meta( $user_id, 'pk_verified_at', true );
		}

		$contact = [
			'hash' => sanitize_text_field( (string) ( $event['contact_hash'] ?? '' ) ),
			'type' => sanitize_key( (string) ( $event['contact_type'] ?? '' ) ),
		];

		if ( '' === $contact['hash'] ) {
			$contact = $this->current_contact_anchor( $user_id );
		}

		return [
			'user_id'           => $user_id,
			'customer_hash'     => hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) ),
			'visitor_hash'      => $this->visitor_hash(),
			'contact_hash'      => $contact['hash'],
			'contact_type'      => $contact['type'],
			'phonekey_verified' => $verified,
		];
	}

	protected function visitor_hash(): string {
		if ( function_exists( 'pk_ip' ) ) {
			$ip = (string) pk_ip();
		} elseif ( function_exists( 'stp_get_ip' ) ) {
			$ip = (string) stp_get_ip();
		} else {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
		}

		return hash_hmac( 'sha256', 'ip:' . $ip, wp_salt( 'auth' ) );
	}

	protected function current_contact_anchor( int $user_id ): array {
		if ( $user_id ) {
			$phone_hash = sanitize_text_field( (string) get_user_meta( $user_id, 'pk_phone_hash', true ) );
			$email_hash = sanitize_text_field( (string) get_user_meta( $user_id, 'pk_email_hash', true ) );

			if ( '' !== $phone_hash ) {
				return [
					'hash' => hash_hmac( 'sha256', 'pk-phone:' . $phone_hash, wp_salt( 'auth' ) ),
					'type' => 'phonekey_phone',
				];
			}

			if ( '' !== $email_hash ) {
				return [
					'hash' => hash_hmac( 'sha256', 'pk-email:' . $email_hash, wp_salt( 'auth' ) ),
					'type' => 'phonekey_email',
				];
			}
		}

		$phone = '';
		$email = '';

		if ( \DSA\Apps\Store_App_Service::enabled() && WC() && WC()->customer ) {
			$phone = method_exists( WC()->customer, 'get_billing_phone' ) ? (string) WC()->customer->get_billing_phone() : '';
			$email = method_exists( WC()->customer, 'get_billing_email' ) ? (string) WC()->customer->get_billing_email() : '';
		}

		if ( '' === $email && $user_id ) {
			$user = get_user_by( 'id', $user_id );
			$email = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
		}

		return $this->contact_anchor( $phone, $email );
	}

	protected function contact_anchor( string $phone, string $email ): array {
		$phone = function_exists( 'pk_normalize_phone' ) ? (string) pk_normalize_phone( $phone ) : preg_replace( '/[^0-9+]/', '', $phone );
		$email = function_exists( 'pk_normalize_email' ) ? (string) pk_normalize_email( $email ) : strtolower( sanitize_email( $email ) );
		$phone_digits = preg_replace( '/[^0-9]/', '', $phone );

		if ( strlen( $phone_digits ) >= 7 ) {
			return [
				'hash' => hash_hmac( 'sha256', 'phone:' . $phone, wp_salt( 'auth' ) ),
				'type' => 'phone',
			];
		}

		if ( '' !== $email && is_email( $email ) ) {
			return [
				'hash' => hash_hmac( 'sha256', 'email:' . $email, wp_salt( 'auth' ) ),
				'type' => 'email',
			];
		}

		return [ 'hash' => '', 'type' => '' ];
	}















	/** Public authoring projection only. Never opens a cart, claims a coupon or quotes checkout totals. */
	public function public_pair_configuration( int $trigger_id ): array{
		return $this->commerce_extension()->public_pair_configuration($trigger_id);
	}







	protected function price_text( float $amount ): string {
		return function_exists( 'wc_price' ) ? $this->money_text( wc_price( $amount ) ) : number_format_i18n( $amount, 2 );
	}

	protected function money_text( string $html ): string {
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, $charset );
	}


	protected function events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'dsa_store_events';
	}
	private function commerce_extension(): Store_Commerce_Analytics_Service {
		if ( ! \DSA\Apps\Store_App_Service::enabled() ) throw new \LogicException('Kiwe Store is not active.');
		return $this->commerce_extension ??= new Store_Commerce_Analytics_Service( $this->settings, $this );
	}

}
