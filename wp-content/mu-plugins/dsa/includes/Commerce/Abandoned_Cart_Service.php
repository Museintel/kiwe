<?php

namespace DSA\Commerce;

use DSA\Communications\Channel_Service;
use DSA\Diagnostics\Runtime_Profiler;
use DSA\Notifications\Notification_Preference_Service;
use DSA\Security\Secret_Store;
use DSA\Settings;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Abandoned_Cart_Service {
	private const DB_VERSION = '4';
	private const DB_OPTION = 'dsa_abandoned_cart_db_version';
	private const CRON_HOOK = 'dsa_abandoned_cart_maintenance';

	private $settings;
	private $analytics;
	private $channels;
	private $preferences;
	private $capturing = false;
	private string $request_capture_signature = '';
	private array $customer_labels = [];
	private array $checkout_contact = [];
	private function recovery_paused(): bool {
		return class_exists( Store_Recovery_Gate_Service::class ) && Store_Recovery_Gate_Service::is_paused();
	}

	public function __construct( Settings $settings, Store_Analytics_Service $analytics, Channel_Service $channels ) {
		$this->settings = $settings;
		$this->analytics = $analytics;
		$this->channels = $channels;
	}

	public function set_preferences( Notification_Preference_Service $preferences ): void {
		$this->preferences = $preferences;
	}

	public function register(): void {
		if ( ! \DSA\Apps\Store_App_Service::enabled() ) return;
		if ( $this->recovery_paused() ) return;
		add_action( self::CRON_HOOK, [ $this, 'maintenance' ] );
		add_action( 'admin_init', [ $this, 'prepare_core_capture' ], 30 );
		add_action( 'template_redirect', [ $this, 'maybe_restore_cart' ], 2 );
		add_action( 'template_redirect', [ $this, 'capture_browsing_cart' ], 90 );
		add_action( 'woocommerce_add_to_cart', [ $this, 'capture_after_cart_change' ], 90 );
		add_action( 'woocommerce_after_cart_item_quantity_update', [ $this, 'capture_after_cart_change' ], 90 );
		add_action( 'woocommerce_cart_item_removed', [ $this, 'capture_after_cart_change' ], 90 );
		add_action( 'woocommerce_after_calculate_totals', [ $this, 'capture_after_cart_change' ], 90 );
		add_action( 'dsa_checkout_draft_saved', [ $this, 'capture_checkout_details' ], 90 );
		add_action( 'woocommerce_checkout_update_order_review', [ $this, 'capture_checkout_review' ], 90, 1 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', [ $this, 'capture_checkout_details' ], 90, 0 );
		add_action( 'woocommerce_cart_emptied', [ $this, 'mark_current_cart_cleared' ], 90 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'mark_order_converted' ], 90, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'mark_store_api_order_converted' ], 90 );
		add_action( 'dsa_phonekey_authenticated', [ $this, 'link_phonekey_identity' ], 20, 2 );
	}

	public function maybe_install(): void {
		if ( $this->recovery_paused() ) return;
		// Schema work is an explicit reviewed setup action, never a visitor request.
		if ( ! $this->woo_available() || ! current_user_can( 'kiwe_manage_context' ) || ! current_user_can( 'edit_others_shop_orders' ) ) return;
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION && $this->schema_exists() ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$carts = $this->carts_table();
		$logs = $this->logs_table();
		$sql = "CREATE TABLE {$carts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_hash char(64) NOT NULL DEFAULT '',
			customer_hash char(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			contact_hash char(64) NOT NULL DEFAULT '',
			contact_type varchar(24) NOT NULL DEFAULT '',
			contact_email_enc text NULL,
			phonekey_verified tinyint(1) NOT NULL DEFAULT 0,
			cart_hash char(64) NOT NULL DEFAULT '',
			items longtext NULL,
			item_count int(11) unsigned NOT NULL DEFAULT 0,
			cart_total decimal(18,4) NOT NULL DEFAULT 0,
			currency varchar(12) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			first_seen_at datetime NOT NULL,
			last_activity_at datetime NOT NULL,
			abandoned_at datetime NULL,
			recovered_at datetime NULL,
			converted_at datetime NULL,
			cleared_at datetime NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reminder_count int(11) unsigned NOT NULL DEFAULT 0,
			last_reminder_at datetime NULL,
			recovery_token_hash char(64) NOT NULL DEFAULT '',
			recovery_expires_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			contact_phone_enc text NULL,
			checkout_started_at datetime NULL,
			checkout_details_at datetime NULL,
			PRIMARY KEY (id),
			KEY visitor_hash (visitor_hash),
			KEY customer_hash (customer_hash),
			KEY user_id (user_id),
			KEY status_activity (status,last_activity_at),
			KEY status_updated (status,updated_at),
			KEY recovery_token_hash (recovery_token_hash),
			KEY order_id (order_id)
		) {$charset};
		CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			cart_id bigint(20) unsigned NOT NULL,
			channel varchar(20) NOT NULL DEFAULT '',
			recipient_hash char(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			message varchar(255) NOT NULL DEFAULT '',
			sent_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY cart_id (cart_id),
			KEY channel_status (channel,status),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		if ( ! $this->schema_exists() ) return;
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}
	/** Fresh installs and upgrades prepare their core owner on an authorized private request only. */
	public function prepare_core_capture(): void {
		if ( $this->recovery_paused() || ! $this->woo_available() || ! current_user_can( 'kiwe_manage_context' ) || ! current_user_can( 'edit_others_shop_orders' ) ) return;
		if ( ! $this->ready() || ! wp_next_scheduled( self::CRON_HOOK ) ) $this->configure_runtime();
	}

	/** Inspect schema only during explicit setup, never on a visitor or archive read. */
	private function schema_exists(): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->logs_table() ) ) ) === $this->logs_table()
			&& (bool) $wpdb->get_var( "SHOW INDEX FROM {$this->carts_table()} WHERE Key_name='status_updated'" )
			&& 3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name IN (%s,%s,%s)', $this->carts_table(), 'contact_phone_enc', 'checkout_started_at', 'checkout_details_at' ) );
	}

public function ready(): bool {
		return $this->woo_available() && self::DB_VERSION === get_option( self::DB_OPTION );
	}

	private function woo_available(): bool { return \DSA\Apps\Store_App_Service::enabled() && class_exists( 'WC_Order' ); }

	/** Called only after a reviewed settings save; old records remain intact when paused. */
	public function configure_runtime(): bool {
		if ( $this->recovery_paused() ) return false;
		if ( ! $this->woo_available() ) return false;
		$this->maybe_install();
		if ( ! $this->ready() ) return false;
		$this->maybe_schedule();
		return (bool) wp_next_scheduled( self::CRON_HOOK );
	}

	public function maybe_schedule(): void {
		if ( ! $this->woo_available() ) return;
		if ( $this->recovery_paused() ) return;
		if ( $this->ready() && ! wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
	}

	public function maintenance(): void {
		if ( ! $this->woo_available() ) return;
		if ( $this->recovery_paused() ) return;
		if ( ! $this->ready() ) return;
		$this->refresh_abandoned_statuses();
		$this->send_due_automatic_reminders();
		$this->purge_old_rows();
	}

	public function capture_browsing_cart(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$checkout = function_exists( 'is_checkout' ) && is_checkout() && ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) && ! ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() );
		$this->capture_current_cart( $checkout ? 'checkout_page' : 'browse' );
	}

	public function capture_after_cart_change(): void {
		$this->capture_current_cart( 'cart_change' );
	}
	public function capture_checkout_details(): void { $this->capture_current_cart( 'checkout_details' ); }
	/** Native Woo calls this after its own AJAX nonce gate. Capture only bounded individual contact fields. */
	public function capture_checkout_review( $posted ): void {
		if ( $this->recovery_paused() || ! $this->ready() || ! is_string( $posted ) || strlen( $posted ) > 16384 ) return;
		parse_str( $posted, $fields );
		foreach ( [ 'email', 'phone' ] as $kind ) {
			$value = $fields[ 'billing_' . $kind ] ?? null;
			if ( ! is_string( $value ) || strlen( $value ) > ( 'email' === $kind ? 254 : 40 ) ) continue;
			$this->checkout_contact[ $kind ] = 'email' === $kind ? ( is_email( trim( $value ) ) ? strtolower( sanitize_email( $value ) ) : '' ) : $this->contact_phone( $value );
		}
		if ( $this->checkout_contact ) $this->capture_checkout_details();
	}

	public function capture_current_cart( string $reason = '' ): void {
		if ( $this->recovery_paused() ) return;
		if ( $this->capturing || ! $this->ready() || ! $this->woocommerce_cart_ready() ) {
			return;
		}

		$this->capturing = true;
		$cart = WC()->cart;

		if ( $cart->is_empty() ) {
			$this->mark_current_cart_cleared();
			$this->capturing = false;
			return;
		}

		if ( count( $cart->get_cart() ) > 100 ) { $this->capturing = false; return; }
		$identity = $this->analytics->current_identity();
		$items = $this->cart_items_payload( $cart );
		$cart_hash = hash( 'sha256', wp_json_encode( $items ) );
		$existing = $this->find_open_cart( $identity );
		$email = $this->current_checkout_email();
		$phone = $this->current_checkout_phone();
		$total = method_exists( $cart, 'get_total' ) ? (float) $cart->get_total( 'edit' ) : (float) $cart->get_cart_contents_total();
		$signature = hash( 'sha256', wp_json_encode( [ $cart_hash, $reason, $identity['customer_hash'] ?? '', $email, $phone, $total ] ) );
		if ( $signature === $this->request_capture_signature ) {
			$this->capturing = false;
			return;
		}
		$this->request_capture_signature = $signature;
		$now = current_time( 'mysql' );
		$data = [
			'visitor_hash'     => (string) ( $identity['visitor_hash'] ?? '' ),
			'customer_hash'    => (string) ( $identity['customer_hash'] ?? '' ),
			'user_id'          => absint( $identity['user_id'] ?? 0 ),
			'contact_hash'     => (string) ( $identity['contact_hash'] ?? '' ),
			'contact_type'     => sanitize_key( (string) ( $identity['contact_type'] ?? '' ) ),
			'phonekey_verified'=> ! empty( $identity['phonekey_verified'] ) ? 1 : 0,
			'cart_hash'        => $cart_hash,
			'items'            => wp_json_encode( $items ),
			'item_count'       => (int) $cart->get_cart_contents_count(),
			'cart_total'       => max( 0, $total ),
			'currency'         => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'status'           => 'active',
			'last_activity_at' => $now,
			'updated_at'       => $now,
		];
		// Saved checkout identity is private operational data; it never grants reminder consent.
		if ( is_email( $email ) ) {
			$data['contact_email_enc'] = Secret_Store::encrypt( $email );
			if ( '' === (string) $data['contact_hash'] ) {
				$data['contact_hash'] = hash_hmac( 'sha256', 'email:' . $email, wp_salt( 'auth' ) );
				$data['contact_type'] = 'email';
			}
		}
		if ( $phone ) $data['contact_phone_enc'] = Secret_Store::encrypt( $phone );
		if ( array_key_exists( 'email', $this->checkout_contact ) && ! $email ) $data['contact_email_enc'] = null;
		if ( array_key_exists( 'phone', $this->checkout_contact ) && ! $phone ) $data['contact_phone_enc'] = null;
		if ( ! $data['user_id'] && $this->checkout_contact ) {
			$data['contact_type'] = $email ? 'email' : ( $phone ? 'phone' : '' );
			$data['contact_hash'] = $email || $phone ? hash_hmac( 'sha256', $data['contact_type'] . ':' . ( $email ?: $phone ), wp_salt( 'auth' ) ) : '';
		}
		if ( 'checkout_page' === $reason && empty( $existing['checkout_started_at'] ) ) $data['checkout_started_at'] = $now;
		if ( 'checkout_details' === $reason && empty( $existing['checkout_details_at'] ) ) $data['checkout_details_at'] = $now;

		global $wpdb;

		if ( $existing ) {
			$same_cart = hash_equals( (string) ( $existing['cart_hash'] ?? '' ), $cart_hash );
			$last_activity = (int) get_gmt_from_date( (string) ( $existing['last_activity_at'] ?? '' ), 'U' );
			$heartbeat = max( MINUTE_IN_SECONDS, (int) ( $this->config()['heartbeat_minutes'] ?? 5 ) * MINUTE_IN_SECONDS );
			$contact_changed = ( ( $email || array_key_exists( 'email', $this->checkout_contact ) ) && $email !== Secret_Store::decrypt( (string) ( $existing['contact_email_enc'] ?? '' ) ) ) || ( ( $phone || array_key_exists( 'phone', $this->checkout_contact ) ) && $phone !== Secret_Store::decrypt( (string) ( $existing['contact_phone_enc'] ?? '' ) ) );
			$stage_changed = isset( $data['checkout_started_at'] ) || isset( $data['checkout_details_at'] );
			$total_changed = abs( (float) ( $existing['cart_total'] ?? 0 ) - $total ) > 0.0001;
			$identity_changed = (int) ( $existing['user_id'] ?? 0 ) !== $data['user_id'] || (string) ( $existing['customer_hash'] ?? '' ) !== $data['customer_hash'];
			if ( $same_cart && ! $contact_changed && ! $stage_changed && ! $total_changed && ! $identity_changed && $last_activity > 0 && ( time() - $last_activity ) < $heartbeat ) {
				Runtime_Profiler::mark( 'abandoned_cart.write_skipped', [ 'reason' => 'unchanged_within_heartbeat' ] );
				$this->remember_cart( (int) $existing['id'], $data['customer_hash'] );
				$this->capturing = false;
				return;
			}

			if ( 'abandoned' === $existing['status'] ) {
				$data['recovered_at'] = $now;
			}

			if ( 'browse' === $reason && $same_cart ) {
				$data = array_intersect_key( $data, array_flip( [ 'visitor_hash', 'customer_hash', 'user_id', 'contact_hash', 'contact_type', 'contact_email_enc', 'contact_phone_enc', 'phonekey_verified', 'status', 'last_activity_at', 'updated_at', 'recovered_at' ] ) );
			}
			$profile = Runtime_Profiler::start();
			$written = $wpdb->update( $this->carts_table(), $data, [ 'id' => (int) $existing['id'], 'customer_hash'=>(string) $existing['customer_hash'], 'user_id'=>(int) $existing['user_id'], 'status'=>$existing['status'] ] );
			if ( false !== $written ) $this->remember_cart( (int) $existing['id'], $data['customer_hash'] );
			Runtime_Profiler::finish( 'abandoned_cart.update', $profile );
		} else {
			$data['first_seen_at'] = $now;
			$data['created_at'] = $now;
			$profile = Runtime_Profiler::start();
			if ( $wpdb->insert( $this->carts_table(), $data ) ) $this->remember_cart( (int) $wpdb->insert_id, $data['customer_hash'] );
			Runtime_Profiler::finish( 'abandoned_cart.insert', $profile );
		}

		$this->capturing = false;
	}

	public function mark_current_cart_cleared(): void {
		if ( $this->recovery_paused() || ! $this->ready() || ! $this->woocommerce_cart_ready() ) return;
		$identity = $this->analytics->current_identity();
		$row = $this->find_open_cart( $identity );

		if ( ! $row ) {
			return;
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->update(
			$this->carts_table(),
			[ 'status' => 'cleared', 'cleared_at' => $now, 'updated_at' => $now ],
			[ 'id' => (int) $row['id'] ]
		);
	}

	public function mark_order_converted( int $order_id, $posted_data = null, $order = null ): void {
		$this->convert_cart_for_order( $order ?: $order_id );
	}

	public function mark_store_api_order_converted( $order ): void {
		$this->convert_cart_for_order( $order );
	}

	public function link_phonekey_identity( int $user_id, string $method = '' ): void {
		if ( ! $user_id || ! $this->ready() ) {
			return;
		}

		$identity = $this->analytics->identity_for_user( $user_id );
		$row = $this->find_open_cart( $identity );

		if ( ! $row ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			$this->carts_table(),
			[
				'user_id'           => $user_id,
				'customer_hash'      => (string) ( $identity['customer_hash'] ?? '' ),
				'contact_hash'       => (string) ( $identity['contact_hash'] ?? '' ),
				'contact_type'       => sanitize_key( (string) ( $identity['contact_type'] ?? '' ) ),
				'phonekey_verified'  => 1,
				'updated_at'         => current_time( 'mysql' ),
			],
			[ 'id' => (int) $row['id'] ]
		);
	}

	public function refresh_abandoned_statuses(): int {
		global $wpdb;
		if ( $this->recovery_paused() || ! $this->ready() ) return 0;
		$minutes = max( 15, absint( $this->config()['inactivity_minutes'] ?? 60 ) );
		$before = wp_date( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ), wp_timezone() );
		$now = current_time( 'mysql' );

		// Background only, at most 25 conditional claims. A concurrent cart edit
		// or worker wins natively; only its successful first transition emits.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,abandoned_at,last_activity_at FROM {$this->carts_table()} WHERE status='active' AND item_count > 0 AND last_activity_at < %s ORDER BY last_activity_at ASC,id ASC LIMIT 25", $before ), ARRAY_A ) ?: [];
		$changed = 0;
		foreach ( $rows as $row ) {
			$claim = $wpdb->query( $wpdb->prepare( "UPDATE {$this->carts_table()} SET status='abandoned',abandoned_at=COALESCE(abandoned_at,%s),updated_at=%s WHERE id=%d AND status='active' AND item_count>0 AND last_activity_at=%s AND last_activity_at<%s", $now, $now, (int) $row['id'], $row['last_activity_at'], $before ) );
			if ( 1 !== $claim ) continue;
			$changed++;
			if ( empty( $row['abandoned_at'] ) ) do_action( 'dsa_saved_cart_abandoned', (int) $row['id'] );
		}
		return $changed;
	}

	public function analytics_summary( int $days = 30 ): array {
		global $wpdb;
		if ( ! $this->ready() ) return array_fill_keys( [ 'carts','active','abandoned','identified','reminded','recovered','converted','recovered_revenue' ], 0 );
		$days = max( 0, min( 365, $days ) );
		$where = '1=1';
		$params = [];

		if ( $days > 0 ) {
			$where .= ' AND created_at >= %s';
			$params[] = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ), wp_timezone() );
		}

		$sql = "SELECT COUNT(*) AS carts,
			SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active,
			SUM(CASE WHEN status='abandoned' THEN 1 ELSE 0 END) AS abandoned,
			SUM(CASE WHEN user_id > 0 THEN 1 ELSE 0 END) AS identified,
			SUM(CASE WHEN reminder_count > 0 THEN 1 ELSE 0 END) AS reminded,
			SUM(CASE WHEN recovered_at IS NOT NULL THEN 1 ELSE 0 END) AS recovered,
			SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) AS converted,
			COALESCE(SUM(CASE WHEN status='converted' AND recovered_at IS NOT NULL THEN cart_total ELSE 0 END),0) AS recovered_revenue
			FROM {$this->carts_table()} WHERE {$where}";
		$row = $params ? $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_row( $sql, ARRAY_A );

		return [
			'carts'             => (int) ( $row['carts'] ?? 0 ),
			'active'            => (int) ( $row['active'] ?? 0 ),
			'abandoned'         => (int) ( $row['abandoned'] ?? 0 ),
			'identified'        => (int) ( $row['identified'] ?? 0 ),
			'reminded'          => (int) ( $row['reminded'] ?? 0 ),
			'recovered'         => (int) ( $row['recovered'] ?? 0 ),
			'converted'         => (int) ( $row['converted'] ?? 0 ),
			'recovered_revenue' => (float) ( $row['recovered_revenue'] ?? 0 ),
		];
	}

	public function reminder_rows( string $status = 'abandoned', int $limit = 100 ): array {
		global $wpdb;
		if ( ! $this->ready() ) return [];
		$status = in_array( $status, [ 'abandoned', 'active', 'converted', 'cleared' ], true ) ? $status : 'abandoned';
		$limit = max( 1, min( 250, $limit ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->carts_table()} WHERE status=%s ORDER BY last_activity_at DESC LIMIT %d", $status, $limit ),
			ARRAY_A
		);

		return array_map( [ $this, 'hydrate_admin_row' ], is_array( $rows ) ? $rows : [] );
	}

	public function delivery_logs( int $limit = 100 ): array {
		global $wpdb;
		if ( ! $this->ready() ) return [];
		$limit = max( 1, min( 250, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->logs_table()} ORDER BY created_at DESC LIMIT %d", $limit ), ARRAY_A ) ?: [];
	}

private function row( int $id ): array {
		global $wpdb;
		if ( ! $this->ready() ) return [];
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->carts_table()} WHERE id=%d LIMIT 1", $id ), ARRAY_A ) ?: [];
	}

	private function attempt_lock( int $id ): string {
		global $wpdb;
		return 'kiwe_cart_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $wpdb->prefix . ':' . $id ), 0, 40 );
	}

	private function unknown_attempt( int $id ): array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT id,channel,status,created_at FROM {$this->logs_table()} WHERE cart_id=%d AND status='unknown' ORDER BY id DESC LIMIT 1", $id ), ARRAY_A ) ?: [];
	}

	private function reminder_reason( array $row, string $channel, bool $automated = false ): string {
		if ( $this->recovery_paused() ) return Store_Recovery_Gate_Service::reason();
		if ( ! $automated && empty( $this->config()['manual_reminders_enabled'] ) ) return 'Manual reminders are paused.';
		if ( ! in_array( $channel, [ 'email','sms','whatsapp' ], true ) ) return 'Choose an existing reminder channel.';
		if ( 'abandoned' !== ( $row['status'] ?? '' ) ) return 'This cart is no longer abandoned.';
		$id = absint( $row['user_id'] ?? 0 );
		if ( ! $id || ! get_userdata( $id ) ) return 'The shopper needs a verified account and cart-reminder opt-in.';
		if ( \DSA\Access\Team_Workspace_Service::suspended( $id ) ) return 'The account is blocked.';
		if ( ! $this->preferences || ! $this->preferences->user_accepts( $id, $channel, 'cart_reminder' ) ) return 'The shopper has not opted in on this channel.';
		if ( '' === \DSA\PhoneKey\PhoneKey_Bridge::campaign_contact( $id, $channel ) ) return 'A verified contact is required on this channel.';
		if ( ! ( $automated ? $this->channels->available_for_campaign( $channel ) : $this->channels->available( $channel ) ) ) return 'This channel needs provider setup.';
		$items = json_decode( (string) ( $row['items'] ?? '' ), true );
		if ( ! is_array( $items ) || count( $items ) > 100 || strlen( (string) $row['items'] ) > 262144 ) return 'This cart needs the native store view.';
		$config = $this->config();
		if ( (int) $row['reminder_count'] >= max( 1, min( 5, (int) ( $config['max_reminders'] ?? 3 ) ) ) ) return 'This cart reached its reminder attempt limit.';
		$last = ! empty( $row['last_reminder_at'] ) ? \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $row['last_reminder_at'], wp_timezone() ) : false;
		if ( $last && $last->getTimestamp() >= time() - max( 1, (int) ( $config['cooldown_hours'] ?? 24 ) ) * HOUR_IN_SECONDS ) return 'This cart is inside its reminder cooldown.';
		return '';
	}

	public function review_record( int $id ) {
		global $wpdb;
		if ( ! current_user_can( 'edit_others_shop_orders' ) ) return new \WP_Error( 'dsa_cart_access', 'Saved carts require order-management access.', [ 'status'=>403 ] );
		$row = $this->row( $id );
		if ( ! $row ) return new \WP_Error( 'dsa_cart_missing', 'That saved cart is unavailable. Refresh the view.', [ 'status'=>404 ] );
		$unknown = $this->unknown_attempt( $id );
		$reach = []; $contacts = [];
		foreach ( [ 'email','sms','whatsapp' ] as $channel ) {
			$reason = $unknown ? 'Review the uncertain attempt before another reminder.' : $this->reminder_reason( $row, $channel );
			$contact = \DSA\PhoneKey\PhoneKey_Bridge::campaign_contact( (int) $row['user_id'], $channel );
			$contacts[$channel] = hash_hmac( 'sha256', $channel . ':' . $contact, wp_salt( 'auth' ) );
			$reach[] = [ 'channel'=>$channel, 'allowed'=>'' === $reason, 'reason'=>$reason, 'contact'=>'' === $contact ? '' : ( 'email' === $channel ? $this->mask_email( $contact ) : $this->mask_phone( $contact ) ) ];
		}
		$items = json_decode( (string) $row['items'], true ); $items = is_array( $items ) ? $items : [];
		$out = [ 'id'=>$id, ...$this->customer_summary( $row ), ...$this->journey( $row ), 'status'=>$row['status'], 'lastActivity'=>$row['last_activity_at'], 'itemCount'=>(int) $row['item_count'], 'total'=>$this->money( (float) $row['cart_total'], $row['currency'] ), 'attempts'=>(int) $row['reminder_count'], 'lastReminder'=>$row['last_reminder_at'] ?: '', 'orderId'=>(int) $row['order_id'], 'items'=>array_map( static fn( $item ) => [ 'name'=>sanitize_text_field( (string) ( $item['name'] ?? '' ) ), 'quantity'=>max( 0, (int) ( $item['quantity'] ?? 0 ) ) ], array_slice( $items, 0, 100 ) ), 'itemsTruncated'=>count( $items ) > 100, 'reach'=>$reach, 'unknown'=>$unknown ? [ 'id'=>(int) $unknown['id'], 'channel'=>$unknown['channel'], 'createdAt'=>$unknown['created_at'] ] : null, 'canSend'=>current_user_can( 'kiwe_manage_notification_policy' ), 'logs'=>$wpdb->get_results( $wpdb->prepare( "SELECT id,channel,status,created_at FROM {$this->logs_table()} WHERE cart_id=%d ORDER BY id DESC LIMIT 10", $id ), ARRAY_A ) ?: [] ];
		if ( $this->recovery_paused() ) { $out['canSend'] = false; $out['recovery'] = Store_Recovery_Gate_Service::projection(); }
		$out['expected'] = hash_hmac( 'sha256', serialize( [ $row,$out,$contacts,$this->config() ] ), wp_salt( 'nonce' ) );
		return $out;
	}

	public function workspace_rows( array $input = [] ): array {
		global $wpdb;
		$status = in_array( $input['status'] ?? '', [ 'abandoned','active','converted','cleared' ], true ) ? $input['status'] : 'abandoned';
		$page = max( 1, min( 1000, (int) ( $input['page'] ?? 1 ) ) );
		$id = absint( $input['id'] ?? 0 );
		$out = [ 'ready'=>$this->ready(), 'enabled'=>true, 'status'=>$status, 'page'=>$page, 'hasNext'=>false, 'items'=>[] ];
		if ( ! current_user_can( 'edit_others_shop_orders' ) || ! $out['ready'] ) return $out;
		$direction = 'oldest' === ( $input['sort'] ?? '' ) ? 'ASC' : 'DESC';
		$sql = $id ? $wpdb->prepare( "SELECT id,user_id,contact_type,contact_email_enc,contact_phone_enc,checkout_started_at,checkout_details_at,status,last_activity_at,item_count,cart_total,currency,reminder_count FROM {$this->carts_table()} WHERE id=%d AND status=%s LIMIT 1", $id,$status ) : $wpdb->prepare( "SELECT id,user_id,contact_type,contact_email_enc,contact_phone_enc,checkout_started_at,checkout_details_at,status,last_activity_at,item_count,cart_total,currency,reminder_count FROM {$this->carts_table()} WHERE status=%s ORDER BY last_activity_at {$direction},id {$direction} LIMIT 21 OFFSET %d", $status,( $page - 1 ) * 20 );
		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: []; $out['hasNext'] = count( $rows ) > 20;
		$out['items'] = array_map( fn( $r ) => [ 'id'=>(int) $r['id'], ...$this->customer_summary( $r ), ...$this->journey( $r ), 'status'=>$r['status'], 'lastActivity'=>$r['last_activity_at'], 'itemCount'=>(int) $r['item_count'], 'total'=>$this->money( (float) $r['cart_total'],$r['currency'] ), 'attempts'=>(int) $r['reminder_count'] ], array_slice( $rows,0,20 ) );
		return $out;
	}

	public function resolve_attempt( int $id, int $attempt, string $expected, string $outcome ) {
		global $wpdb;
		if ( ! current_user_can( 'edit_others_shop_orders' ) || ! current_user_can( 'kiwe_manage_notification_policy' ) ) return new \WP_Error( 'dsa_cart_access','Reminder review is unavailable.',[ 'status'=>403 ] );
		$lock = $this->attempt_lock( $id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)',$lock ) ) ) return new \WP_Error( 'dsa_cart_busy','Another cart task is running. Refresh and review.',[ 'status'=>409 ] );
		try {
			$current = $this->review_record( $id );
			if ( is_wp_error( $current ) || ! hash_equals( $current['expected'],$expected ) || ( $current['unknown']['id'] ?? 0 ) !== $attempt || ! in_array( $outcome,[ 'sent','failed' ],true ) ) return new \WP_Error( 'dsa_cart_stale','The cart or uncertain attempt changed. Reload before reviewing.',[ 'status'=>409 ] );
			$written = $wpdb->update( $this->logs_table(),[ 'status'=>$outcome,'message'=>'Outcome reviewed by the site operator.' ],[ 'id'=>$attempt,'cart_id'=>$id,'status'=>'unknown' ] );
			if ( 1 !== $written || $this->unknown_attempt( $id ) ) return new \WP_Error( 'dsa_cart_review_uncertain','The review save is uncertain. Reload before another action.',[ 'status'=>409 ] );
			return $this->review_record( $id );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)',$lock ) ); }
	}

	public function send_reminder( int $cart_id, string $channel, bool $automated = false, string $expected = '' ) {
		if ( $this->recovery_paused() ) return Store_Recovery_Gate_Service::error();
		global $wpdb;
		if ( ! $this->ready() || ( ! $automated && ( ! current_user_can( 'edit_others_shop_orders' ) || ! current_user_can( 'kiwe_manage_notification_policy' ) ) ) ) return new \WP_Error( 'dsa_cart_access','This reminder is unavailable.',[ 'status'=>403 ] );
		$lock = $this->attempt_lock( $cart_id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)',$lock ) ) ) return new \WP_Error( 'dsa_cart_busy','Another cart task is running. Refresh and review.',[ 'status'=>409 ] );
		try {
			$row = $this->row( $cart_id ); $channel = sanitize_key( $channel );
			if ( ! $row || $this->unknown_attempt( $cart_id ) ) return new \WP_Error( 'dsa_cart_review','This cart is unavailable or has an uncertain attempt. Review it first.',[ 'status'=>409 ] );
			if ( ! $automated && '' !== $expected ) { $review = $this->review_record( $cart_id ); if ( is_wp_error( $review ) || ! hash_equals( $review['expected'],$expected ) ) return new \WP_Error( 'dsa_cart_stale','The cart or its reachable audience changed. Review again.',[ 'status'=>409 ] ); }
			$reason = $this->reminder_reason( $row,$channel,$automated );
			if ( '' !== $reason ) return new \WP_Error( 'dsa_cart_not_reachable',$reason,[ 'status'=>409 ] );
			$config = $this->config();
			$recipient = \DSA\PhoneKey\PhoneKey_Bridge::campaign_contact( (int) $row['user_id'],$channel );
			$now = current_time( 'mysql' );
			$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
			$token_hash = hash_hmac( 'sha256',$token,wp_salt( 'auth' ) );
			$expiry = wp_date( 'Y-m-d H:i:s',time() + max( 1,min( 30,(int) ( $config['recovery_link_days'] ?? 7 ) ) ) * DAY_IN_SECONDS,wp_timezone() );
			// Persist the uncertain receipt first. A crash at any following point cannot silently replay it.
			$created = $wpdb->insert( $this->logs_table(),[ 'cart_id'=>$cart_id,'channel'=>$channel,'recipient_hash'=>hash_hmac( 'sha256',strtolower( trim( $recipient ) ),wp_salt( 'auth' ) ),'status'=>'unknown','message'=>'Delivery outcome requires review.','sent_by'=>$automated ? 0 : get_current_user_id(),'created_at'=>$now ] );
			$attempt = (int) $wpdb->insert_id;
			if ( 1 !== $created || (int) ( $this->unknown_attempt( $cart_id )['id'] ?? 0 ) !== $attempt ) return new \WP_Error( 'dsa_cart_reservation','The reminder attempt could not be reserved. No delivery was attempted.',[ 'status'=>409 ] );
			$reserved = $wpdb->query( $wpdb->prepare( "UPDATE {$this->carts_table()} SET reminder_count=reminder_count+1,last_reminder_at=%s,recovery_token_hash=%s,recovery_expires_at=%s,updated_at=%s WHERE id=%d AND status='abandoned' AND cart_hash=%s AND reminder_count=%d AND updated_at=%s",$now,$token_hash,$expiry,$now,$cart_id,$row['cart_hash'],(int) $row['reminder_count'],$row['updated_at'] ) );
			$fresh = $this->row( $cart_id );
			if ( 1 !== $reserved || (int) ( $fresh['reminder_count'] ?? 0 ) !== (int) $row['reminder_count'] + 1 || ! hash_equals( $token_hash,(string) ( $fresh['recovery_token_hash'] ?? '' ) ) ) return new \WP_Error( 'dsa_cart_reservation','The cart changed or its reservation is uncertain. No delivery was attempted. Review the attempt.',[ 'status'=>409 ] );
			// Recheck live authority immediately before I/O, without resetting the reserved budget.
			$latest_config = $this->config();
			if ( 'abandoned' !== $fresh['status'] || (int) $row['user_id'] !== (int) $fresh['user_id'] || $latest_config !== $config || ! ( $automated ? $this->channels->available_for_campaign( $channel ) : $this->channels->available( $channel ) ) || ! hash_equals( (string) $row['cart_hash'],(string) $fresh['cart_hash'] ) || ! $this->preferences->user_accepts( (int) $fresh['user_id'],$channel,'cart_reminder' ) || \DSA\Access\Team_Workspace_Service::suspended( (int) $fresh['user_id'] ) || ! hash_equals( $recipient,\DSA\PhoneKey\PhoneKey_Bridge::campaign_contact( (int) $fresh['user_id'],$channel ) ) ) {
				$wpdb->update( $this->logs_table(),[ 'status'=>'failed','message'=>'Audience or cart changed before delivery.' ],[ 'id'=>$attempt,'status'=>'unknown' ] );
				return new \WP_Error( 'dsa_cart_changed','The cart or reachable account changed. No message was sent.',[ 'status'=>409 ] );
			}
			$recovery_url = add_query_arg( 'dsa_cart_recover',$token,home_url( '/' ) );
			$vars = [ '{site_name}'=>get_bloginfo( 'name' ),'{item_count}'=>(string) (int) $row['item_count'],'{cart_items}'=>$this->cart_items_text( $row ),'{cart_total}'=>$this->money( (float) $row['cart_total'],$row['currency'] ),'{recovery_url}'=>esc_url_raw( $recovery_url ),'{site_logo_url}'=>esc_url_raw( Site_Identity_Service::logo_url() ) ];
			$subject = strtr( (string) ( $config['email_subject'] ?? '' ),$vars );
			$message = strtr( (string) ( $config['email' === $channel ? 'email_message' : $channel . '_message'] ?? '' ),$vars );
			$context = [ 'cart_id'=>$cart_id,'purpose'=>$automated ? 'abandoned_cart_automation' : 'abandoned_cart_reminder','recovery_url'=>esc_url_raw( $recovery_url ) ];
			if ( 'email' === $channel ) { $context['headers'] = [ 'Content-Type: text/html; charset=UTF-8' ]; $message = $this->render_recovery_email( $row,$subject,$message,$recovery_url ); }
			// Deliberately no implicit cross-channel fallback: only the reviewed route is authorized.
			try { $result = $this->channels->send( $channel,$recipient,$subject,$message,$context ); } catch ( \Throwable $e ) { $result = false; }
			$accepted = true === $result || ( 'whatsapp' === $channel && is_array( $result ) && true === ( $result['ok'] ?? null ) && 'phonekey_whatsapp' === ( $result['provider'] ?? '' ) && is_string( $result['request_id'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $result['request_id'] ) );
			if ( $accepted ) {
				$written = $wpdb->update( $this->logs_table(),[ 'status'=>'sent','message'=>'Reminder accepted by the configured channel.' ],[ 'id'=>$attempt,'status'=>'unknown' ] );
				if ( 1 === $written && ! $this->unknown_attempt( $cart_id ) ) return true;
			}
			return new \WP_Error( 'dsa_cart_delivery_unknown','The provider outcome is uncertain. Review this attempt before another reminder.',[ 'status'=>409 ] );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)',$lock ) ); }
	}

	public function send_due_automatic_reminders(): int {
		if ( $this->recovery_paused() ) return 0;
		$config = $this->config();
		if ( ! $this->ready() || ( empty( $config['automatic_email_enabled'] ) && empty( $config['automatic_whatsapp_enabled'] ) ) ) return 0;
		global $wpdb;
		$max = max( 1,min( 5,(int) ( $config['max_reminders'] ?? 3 ) ) );
		$before = wp_date( 'Y-m-d H:i:s',time() - max( 1,(int) ( $config['cooldown_hours'] ?? 24 ) ) * HOUR_IN_SECONDS,wp_timezone() );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->carts_table()} c WHERE status='abandoned' AND item_count > 0 AND user_id > 0 AND reminder_count < %d AND (last_reminder_at IS NULL OR last_reminder_at < %s) AND NOT EXISTS (SELECT 1 FROM {$this->logs_table()} l WHERE l.cart_id=c.id AND l.status='unknown') ORDER BY last_activity_at ASC LIMIT 50",$max,$before ),ARRAY_A ) ?: [];
		$sent = 0; $attempted = 0; $started = microtime( true );
		foreach ( $rows as $row ) {
			if ( $attempted >= 3 || microtime( true ) - $started > 10 ) break;
			foreach ( [ 'whatsapp','email' ] as $channel ) {
				if ( empty( $config['automatic_' . $channel . '_enabled'] ) || '' !== $this->reminder_reason( $row,$channel,true ) ) continue;
				++$attempted;
				if ( true === $this->send_reminder( (int) $row['id'],$channel,true ) ) ++$sent;
				break;
			}
		}
		return $sent;
	}

	public function send_due_automatic_email_reminders(): int {
		return $this->send_due_automatic_reminders();
	}


	public function maybe_restore_cart(): void {
		$token = sanitize_text_field( wp_unslash( $_GET['dsa_cart_recover'] ?? '' ) );
		if ( '' === $token || ! $this->ready() || ! $this->woocommerce_cart_ready() ) return;
		$restored = $this->recover_token( $token );
		wp_safe_redirect( add_query_arg( 'dsa-cart-recovery', $restored ? 'restored' : 'expired', function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) ) );
		exit;
	}

	/** Same native cart journey, exposed separately so signed-link consumption is verifiable. */
	public function recover_token( string $token ): bool {
		if ( $this->recovery_paused() ) return false;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/D',$token ) || ! $this->ready() || ! $this->woocommerce_cart_ready() ) return false;
		global $wpdb;
		$hash = hash_hmac( 'sha256',$token,wp_salt( 'auth' ) ); $now = current_time( 'mysql' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->carts_table()} WHERE recovery_token_hash=%s AND recovery_expires_at >= %s LIMIT 1",$hash,$now ),ARRAY_A );
		if ( ! $row || ! in_array( $row['status'],[ 'active','abandoned' ],true ) || strlen( (string) $row['items'] ) > 262144 ) return false;
		$items = json_decode( (string) $row['items'],true ); if ( ! is_array( $items ) || count( $items ) > 100 ) return false;
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->carts_table()} SET status='active',recovered_at=%s,last_activity_at=%s,recovery_token_hash='',recovery_expires_at=NULL,updated_at=%s WHERE id=%d AND recovery_token_hash=%s AND recovery_expires_at >= %s AND status IN ('active','abandoned')",$now,$now,$now,(int) $row['id'],$hash,$now ) );
		if ( 1 !== $claimed ) return false;
		foreach ( $items as $item ) $this->restore_cart_item( is_array( $item ) ? $item : [] );
		WC()->cart->calculate_totals(); return true;
	}

	public function config(): array {
		$all = $this->settings->all();
		$defaults = $this->settings->defaults();
		return array_replace( wp_parse_args( $all['abandoned_cart'] ?? [], $defaults['abandoned_cart'] ?? [] ), [ 'enabled'=>true ] );
	}

	private function convert_cart_for_order( $order_or_id ): void {
		if ( ! $this->ready() ) return;
		$order = is_object( $order_or_id ) ? $order_or_id : ( function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_or_id ) ) : null );

		if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$identity = $this->analytics->current_identity();
		$row = $this->find_open_cart( $identity );

		if ( ! $row ) {
			return;
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->update(
			$this->carts_table(),
			[
				'status'       => 'converted',
				'converted_at' => $now,
				'order_id'     => (int) $order->get_id(),
				'cart_total'   => method_exists( $order, 'get_total' ) ? max( 0, (float) $order->get_total() ) : (float) $row['cart_total'],
				'updated_at'   => $now,
			],
			[ 'id' => (int) $row['id'] ]
		);
	}

	private function find_open_cart( array $identity ): array {
		global $wpdb;
		$session = function_exists( 'WC' ) && WC() ? WC()->session : null;
		$anchor = $session ? $session->get( 'kiwe_saved_cart', [] ) : [];
		if ( is_array( $anchor ) && ! empty( $anchor['id'] ) && is_string( $anchor['customer'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $anchor['customer'] ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->carts_table()} WHERE id=%d AND customer_hash=%s AND user_id IN (0,%d) AND status IN ('active','abandoned') LIMIT 1", absint( $anchor['id'] ), $anchor['customer'], absint( $identity['user_id'] ?? 0 ) ), ARRAY_A );
			if ( $row ) return $row;
		}
		$parts = [];
		$params = [];

		if ( ! empty( $identity['user_id'] ) ) {
			$parts[] = 'user_id=%d';
			$params[] = absint( $identity['user_id'] );
		}

		if ( ! empty( $identity['customer_hash'] ) ) {
			$parts[] = 'customer_hash=%s';
			$params[] = (string) $identity['customer_hash'];
		}

		// A shared IP is not a shopper identity. Never merge distinct Woo sessions/accounts.
		if ( ! $parts && ! empty( $identity['visitor_hash'] ) ) {
			$parts[] = 'visitor_hash=%s';
			$params[] = (string) $identity['visitor_hash'];
		}

		if ( ! $parts ) {
			return [];
		}

		$sql = "SELECT * FROM {$this->carts_table()} WHERE status IN ('active','abandoned') AND (" . implode( ' OR ', $parts ) . ') ORDER BY id DESC LIMIT 1';
		return $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
	}
	private function remember_cart( int $id, string $customer ): void {
		if ( $id > 0 && $this->woocommerce_cart_ready() ) WC()->session->set( 'kiwe_saved_cart', [ 'id'=>$id, 'customer'=>$customer ] );
	}

	private function cart_items_payload( $cart ): array {
		$items = [];

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null;
			$items[] = [
				'product_id'   => absint( $cart_item['product_id'] ?? 0 ),
				'variation_id' => absint( $cart_item['variation_id'] ?? 0 ),
				'quantity'     => max( 1, absint( $cart_item['quantity'] ?? 1 ) ),
				'variation'    => array_map( 'sanitize_text_field', is_array( $cart_item['variation'] ?? null ) ? $cart_item['variation'] : [] ),
				'name'         => $product && method_exists( $product, 'get_name' ) ? wp_strip_all_tags( $product->get_name() ) : '',
			];
		}

		return $items;
	}

	private function cart_items_text( array $row ): string {
		$items = json_decode( (string) ( $row['items'] ?? '' ), true );
		$items = is_array( $items ) ? $items : [];
		$lines = [];

		foreach ( array_slice( $items, 0, 8 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$name = trim( wp_strip_all_tags( (string) ( $item['name'] ?? '' ) ) );
			if ( '' === $name ) {
				$product_id = absint( $item['product_id'] ?? 0 );
				$name = $product_id ? sprintf( __( 'Product #%d', 'dsa' ), $product_id ) : __( 'Cart item', 'dsa' );
			}

			$lines[] = sprintf( '- %1$s x %2$d', $name, max( 1, absint( $item['quantity'] ?? 1 ) ) );
		}

		if ( count( $items ) > 8 ) {
			$lines[] = sprintf( __( '- %d more item(s)', 'dsa' ), count( $items ) - 8 );
		}

		if ( ! $lines ) {
			return sprintf( _n( '%d item', '%d items', max( 1, (int) ( $row['item_count'] ?? 1 ) ), 'dsa' ), max( 1, (int) ( $row['item_count'] ?? 1 ) ) );
		}

		return implode( "\n", $lines );
	}

	private function cart_items_html( array $row ): string {
		$items = json_decode( (string) ( $row['items'] ?? '' ), true );
		$items = is_array( $items ) ? $items : [];
		$html = '';

		foreach ( array_slice( $items, 0, 8 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$name = trim( wp_strip_all_tags( (string) ( $item['name'] ?? '' ) ) );
			if ( '' === $name ) {
				$product_id = absint( $item['product_id'] ?? 0 );
				$name = $product_id ? sprintf( __( 'Product #%d', 'dsa' ), $product_id ) : __( 'Cart item', 'dsa' );
			}

			$html .= '<li style="display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-top:1px solid #eef0f3;"><span>' . esc_html( $name ) . '</span><strong>' . esc_html( sprintf( __( 'Qty %d', 'dsa' ), max( 1, absint( $item['quantity'] ?? 1 ) ) ) ) . '</strong></li>';
		}

		if ( count( $items ) > 8 ) {
			$html .= '<li style="display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-top:1px solid #eef0f3;"><span>' . esc_html( sprintf( __( '%d more item(s)', 'dsa' ), count( $items ) - 8 ) ) . '</span><strong>+</strong></li>';
		}

		if ( '' === $html ) {
			$html = '<li style="display:flex;justify-content:space-between;gap:16px;padding:12px 0;"><span>' . esc_html( sprintf( _n( '%d item', '%d items', max( 1, (int) ( $row['item_count'] ?? 1 ) ), 'dsa' ), max( 1, (int) ( $row['item_count'] ?? 1 ) ) ) ) . '</span><strong>' . esc_html( $this->money( (float) ( $row['cart_total'] ?? 0 ), (string) ( $row['currency'] ?? '' ) ) ) . '</strong></li>';
		}

		return '<ul style="list-style:none;margin:0;padding:0;">' . $html . '</ul>';
	}

	private function render_recovery_email( array $row, string $subject, string $message, string $recovery_url ): string {
		$site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$logo_url = Site_Identity_Service::logo_url();
		$total = $this->money( (float) ( $row['cart_total'] ?? 0 ), (string) ( $row['currency'] ?? '' ) );
		$message_html = wpautop( esc_html( $message ) );
		$logo = $logo_url
			? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $site_name ) . '" style="display:block;max-width:180px;max-height:72px;width:auto;height:auto;">'
			: '<strong style="font-size:20px;line-height:1.2;color:#111827;">' . esc_html( $site_name ) . '</strong>';

		return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( wp_strip_all_tags( $subject ) ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f7f7f8;color:#1f2937;font-family:Arial,Helvetica,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f7f8;margin:0;padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #e5e7eb;">'
			. '<tr><td style="padding:28px 28px 10px;">' . $logo . '</td></tr>'
			. '<tr><td style="padding:8px 28px 0;"><h1 style="margin:0;color:#111827;font-size:28px;line-height:1.12;">' . esc_html( wp_strip_all_tags( $subject ) ) . '</h1></td></tr>'
			. '<tr><td style="padding:16px 28px 8px;font-size:16px;line-height:1.55;color:#374151;">' . $message_html . '</td></tr>'
			. '<tr><td style="padding:8px 28px;"><div style="border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;">'
			. '<div style="padding:12px 16px;background:#f9fafb;color:#6b7280;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">' . esc_html__( 'Your cart', 'dsa' ) . '</div>'
			. '<div style="padding:0 16px;">' . $this->cart_items_html( $row ) . '</div>'
			. '<div style="display:flex;justify-content:space-between;gap:16px;padding:14px 16px;border-top:1px solid #e5e7eb;font-size:16px;"><span>' . esc_html__( 'Cart total', 'dsa' ) . '</span><strong>' . esc_html( $total ) . '</strong></div>'
			. '</div></td></tr>'
			. '<tr><td style="padding:22px 28px 30px;"><a href="' . esc_url( $recovery_url ) . '" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;border-radius:999px;padding:14px 22px;font-weight:700;">' . esc_html__( 'Restore my cart', 'dsa' ) . '</a></td></tr>'
			. '</table>'
			. '<p style="max-width:640px;margin:12px auto 0;color:#6b7280;font-size:12px;line-height:1.5;">' . esc_html__( 'You are receiving this because this site saved a checkout cart for your verified account. If you already completed checkout, you can ignore this email.', 'dsa' ) . '</p>'
			. '</td></tr></table>'
			. '</body></html>';
	}

	private function hydrate_admin_row( array $row ): array {
		$items = json_decode( (string) ( $row['items'] ?? '' ), true );
		$items = is_array( $items ) ? $items : [];
		$contacts = $this->contact_channels_for_row( $row );
		$names = array_filter( array_map( static function ( $item ) {
			return is_array( $item ) ? sanitize_text_field( (string) ( $item['name'] ?? '' ) ) : '';
		}, $items ) );

		$row['item_names'] = $names;
		$row['contact_channels'] = $contacts;
		$row['can_email'] = $this->channels->available( 'email' ) && ! empty( $contacts['email']['value'] );
		$row['can_sms'] = $this->channels->available( 'sms' ) && ! empty( $contacts['sms']['value'] );
		$row['can_whatsapp'] = $this->channels->available( 'whatsapp' ) && ! empty( $contacts['whatsapp']['value'] );
		$row['display_total'] = $this->money( (float) ( $row['cart_total'] ?? 0 ), (string) ( $row['currency'] ?? '' ) );
		return $row;
	}

private function contact_channels_for_user( int $user_id ): array {
		$out = [];
		foreach ( [ 'email','sms','whatsapp' ] as $channel ) {
			$contact = \DSA\PhoneKey\PhoneKey_Bridge::campaign_contact( $user_id,$channel );
			$out[$channel] = [ 'value'=>$contact,'masked'=>'' === $contact ? '' : ( 'email' === $channel ? $this->mask_email( $contact ) : $this->mask_phone( $contact ) ) ];
		}
		return $out;
	}

	private function contact_channels_for_row( array $row ): array {
		// A captured billing address is not verified marketing consent, including guest checkout.
		return $this->contact_channels_for_user( (int) ( $row['user_id'] ?? 0 ) );
	}

	private function current_checkout_email(): string {
		if ( array_key_exists( 'email', $this->checkout_contact ) ) return $this->checkout_contact['email'];
		$email = '';
		if ( function_exists( 'WC' ) && WC() && WC()->customer && method_exists( WC()->customer, 'get_billing_email' ) ) {
			$email = (string) WC()->customer->get_billing_email();
		}

		if ( function_exists( 'pk_normalize_email' ) ) {
			$email = (string) pk_normalize_email( $email );
		} else {
			$email = strtolower( sanitize_email( $email ) );
		}

		return is_email( $email ) ? $email : '';
	}
	private function current_checkout_phone(): string {
		if ( array_key_exists( 'phone', $this->checkout_contact ) ) return $this->checkout_contact['phone'];
		$customer = function_exists( 'WC' ) && WC() ? WC()->customer : null;
		return $customer && method_exists( $customer, 'get_billing_phone' ) ? $this->contact_phone( (string) $customer->get_billing_phone() ) : '';
	}
	private function contact_phone( string $value ): string {
		$value = preg_replace( '/[\s().-]+/', '', trim( $value ) );
		return preg_match( '/^\+?[0-9]{7,15}$/D', $value ) ? $value : '';
	}

	private function verified_phonekey_phone( int $user_id ): string {
		if ( ! function_exists( 'pk_factor' ) || ! function_exists( 'pk_decrypt' ) ) {
			return '';
		}

		$factor = pk_factor( $user_id, 'phone' );

		if ( ! is_array( $factor ) || 'verified' !== ( $factor['status'] ?? '' ) ) {
			return '';
		}

		$phone = pk_decrypt( (string) ( $factor['factor_value'] ?? '' ) );

		if ( '' !== $phone ) {
			return $phone;
		}

		$meta = function_exists( 'pk_meta_decode' ) ? pk_meta_decode( (string) ( $factor['meta'] ?? '' ) ) : json_decode( (string) ( $factor['meta'] ?? '' ), true );
		return is_array( $meta ) ? sanitize_text_field( (string) ( $meta['phone'] ?? '' ) ) : '';
	}



	private function restore_cart_item( array $item ): void {
		$product_id = absint( $item['product_id'] ?? 0 );
		$variation_id = absint( $item['variation_id'] ?? 0 );
		$quantity = max( 1, absint( $item['quantity'] ?? 1 ) );
		$variation = is_array( $item['variation'] ?? null ) ? array_map( 'sanitize_text_field', $item['variation'] ) : [];

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $variation_id ?: $product_id );

		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $key => $current ) {
			if ( absint( $current['product_id'] ?? 0 ) === $product_id && absint( $current['variation_id'] ?? 0 ) === $variation_id ) {
				WC()->cart->set_quantity( $key, max( (int) $current['quantity'], $quantity ), false );
				return;
			}
		}

		WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
	}

private function purge_old_rows(): void {
		global $wpdb;
		if ( ! $this->ready() ) return;
		$before = wp_date( 'Y-m-d H:i:s',time() - max( 7,min( 365,(int) ( $this->config()['retention_days'] ?? 90 ) ) ) * DAY_IN_SECONDS,wp_timezone() );
		$started = microtime( true );
		// Unresolved delivery receipts stay available for review; never retire their latch alone.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->logs_table()} WHERE created_at < %s AND status IN ('sent','failed') ORDER BY created_at ASC LIMIT 100",$before ) );
		$cursor = get_option( 'dsa_abandoned_cart_retention_cursor', [] );
		$cursor = is_array( $cursor ) ? array_intersect_key( $cursor, array_flip( [ 'converted','cleared','abandoned' ] ) ) : [];
		foreach ( [ 'converted','cleared','abandoned' ] as $status ) {
			if ( microtime( true ) - $started > 3 ) break;
			$position = $cursor[$status] ?? [];
			$at = is_string( $position['at'] ?? null ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $position['at'] ) ? $position['at'] : '1970-01-01 00:00:00';
			$id = max( 0, (int) ( $position['id'] ?? 0 ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,updated_at FROM {$this->carts_table()} WHERE status=%s AND updated_at < %s AND (updated_at > %s OR (updated_at=%s AND id>%d)) ORDER BY updated_at ASC,id ASC LIMIT 50", $status, $before, $at, $at, $id ), ARRAY_A );
			if ( ! is_array( $rows ) ) continue;
			if ( ! $rows ) { unset( $cursor[$status] ); continue; }
			$ids = implode( ',', array_map( static fn( $row ) => absint( $row['id'] ), $rows ) );
			// Recheck after selection; remaining children or new activity keep their native parent.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE c FROM {$this->carts_table()} c WHERE c.id IN ({$ids}) AND c.status=%s AND c.updated_at < %s AND NOT EXISTS (SELECT 1 FROM {$this->logs_table()} l WHERE l.cart_id=c.id)", $status, $before ) );
			if ( false !== $deleted ) { $last = end( $rows ); $cursor[$status] = [ 'at'=>$last['updated_at'],'id'=>(int) $last['id'] ]; }
		}
		update_option( 'dsa_abandoned_cart_retention_cursor', $cursor, false );
	}

	private function woocommerce_cart_ready(): bool {
		return function_exists( 'WC' ) && WC() && WC()->cart && WC()->session;
	}

	/** Private order-management identity, independent of reminder eligibility. */
	private function customer_label( array $row ): string {
		return $this->customer_summary( $row )['customer'];
	}
	private function customer_summary( array $row ): array {
		$unknown = [ 'customer'=>__( 'Unknown', 'dsa' ), 'contactKnown'=>false ];
		if ( ! current_user_can( 'edit_others_shop_orders' ) ) return $unknown;
		$id = absint( $row['user_id'] ?? 0 );
		$key = hash( 'sha256', wp_json_encode( [ $id, $row['contact_type'] ?? '', $row['contact_email_enc'] ?? '', $row['contact_phone_enc'] ?? '' ] ) );
		if ( isset( $this->customer_labels[ $key ] ) ) return $this->customer_labels[ $key ];
		$user = $id ? get_userdata( $id ) : false;
		if ( $id && ! $user ) return $unknown;
		$email = ''; $phone = ''; $fallback = '';
		if ( $user ) {
			$email = sanitize_email( (string) $user->user_email );
			if ( preg_match( '/(?:@|\.)(?:invalid|local|localhost)$/i', $email ) ) $email = '';
			$phone_first = in_array( $row['contact_type'] ?? '', [ 'phone','sms','whatsapp' ], true ) || (bool) get_user_meta( $id, 'pk_phone_last4', true );
			$phone = $phone_first || ! $email ? $this->verified_phonekey_phone( $id ) : '';
			$fallback = (string) $user->display_name;
		}
		if ( ! $email && ! empty( $row['contact_email_enc'] ) ) {
			$email = sanitize_email( Secret_Store::decrypt( (string) $row['contact_email_enc'] ) );
		}
		if ( preg_match( '/(?:@|\.)(?:invalid|local|localhost)$/i', $email ) || ! is_email( $email ) ) $email = '';
		if ( ! $phone && ! empty( $row['contact_phone_enc'] ) ) $phone = $this->contact_phone( Secret_Store::decrypt( (string) $row['contact_phone_enc'] ) );
		$label = ! empty( $phone_first ) && $phone ? $phone : ( $email ?: ( $phone ?: $fallback ) );
		$out = [ 'customer'=>sanitize_text_field( $label ) ?: $unknown['customer'], 'contactKnown'=>'' !== $email || '' !== $phone ];
		$this->customer_labels[ $key ] = $out;
		return $out;
	}
	/** Actual checkout endpoint and checkout-sheet progress remain distinct, with no retrospective guess. */
	private function journey( array $row ): array {
		$page = (string) ( $row['checkout_started_at'] ?? '' ); $details = (string) ( $row['checkout_details_at'] ?? '' );
		$status = $row['status'] ?? '';
		$label = 'converted' === $status ? __( 'Order placed', 'dsa' ) : ( 'cleared' === $status ? __( 'Cart cleared', 'dsa' ) : ( $page ? ( 'abandoned' === $status ? __( 'Left at checkout', 'dsa' ) : __( 'Reached checkout', 'dsa' ) ) : ( $details ? __( 'Checkout details entered', 'dsa' ) : __( 'Checkout visit not recorded', 'dsa' ) ) ) );
		return [ 'checkoutReachedAt'=>$page, 'checkoutDetailsAt'=>$details, 'journeyLabel'=>$label ];
	}

	private function money( float $amount, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount, [ 'currency' => $currency ?: get_woocommerce_currency() ] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return number_format_i18n( $amount, 2 ) . ( $currency ? ' ' . $currency : '' );
	}

	private function mask_email( string $email ): string {
		$parts = explode( '@', $email, 2 );
		return substr( $parts[0], 0, 1 ) . '***@' . ( $parts[1] ?? '' );
	}

	private function mask_phone( string $phone ): string {
		$digits = preg_replace( '/\D/', '', $phone );
		return '***' . substr( $digits, -4 );
	}

	private function carts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsa_abandoned_carts';
	}

	private function logs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsa_abandoned_cart_reminders';
	}
}
