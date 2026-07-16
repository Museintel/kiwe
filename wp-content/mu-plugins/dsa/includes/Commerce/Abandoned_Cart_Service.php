<?php

namespace DSA\Commerce;

use DSA\Communications\Channel_Service;
use DSA\Diagnostics\Runtime_Profiler;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Abandoned_Cart_Service {
	private const DB_VERSION = '1';
	private const DB_OPTION = 'dsa_abandoned_cart_db_version';
	private const CRON_HOOK = 'dsa_abandoned_cart_maintenance';

	private $settings;
	private $analytics;
	private $channels;
	private $capturing = false;
	private string $request_capture_signature = '';

	public function __construct( Settings $settings, Store_Analytics_Service $analytics, Channel_Service $channels ) {
		$this->settings = $settings;
		$this->analytics = $analytics;
		$this->channels = $channels;
	}

	public function register(): void {
		add_action( 'init', [ $this, 'maybe_install' ], 9 );
		add_action( 'init', [ $this, 'maybe_schedule' ], 20 );
		add_action( self::CRON_HOOK, [ $this, 'maintenance' ] );
		add_action( 'template_redirect', [ $this, 'maybe_restore_cart' ], 2 );
		add_action( 'template_redirect', [ $this, 'capture_browsing_cart' ], 90 );
		add_action( 'woocommerce_add_to_cart', [ $this, 'capture_after_cart_change' ], 90 );
		add_action( 'woocommerce_after_cart_item_quantity_update', [ $this, 'capture_after_cart_change' ], 90 );
		add_action( 'woocommerce_cart_item_removed', [ $this, 'capture_after_cart_change' ], 90 );
		add_action( 'woocommerce_cart_emptied', [ $this, 'mark_current_cart_cleared' ], 90 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'mark_order_converted' ], 90, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'mark_store_api_order_converted' ], 90 );
		add_action( 'dsa_phonekey_authenticated', [ $this, 'link_phonekey_identity' ], 20, 2 );
	}

	public function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
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
			PRIMARY KEY (id),
			KEY visitor_hash (visitor_hash),
			KEY customer_hash (customer_hash),
			KEY user_id (user_id),
			KEY status_activity (status,last_activity_at),
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
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public function maintenance(): void {
		if ( ! empty( $this->config()['enabled'] ) ) $this->refresh_abandoned_statuses();
		$this->purge_old_rows();
	}

	public function capture_browsing_cart(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$this->capture_current_cart( 'browse' );
	}

	public function capture_after_cart_change(): void {
		$this->capture_current_cart( 'cart_change' );
	}

	public function capture_current_cart( string $reason = '' ): void {
		if ( $this->capturing || empty( $this->config()['enabled'] ) || ! $this->woocommerce_cart_ready() ) {
			return;
		}

		$this->capturing = true;
		$cart = WC()->cart;

		if ( $cart->is_empty() ) {
			$this->mark_current_cart_cleared();
			$this->capturing = false;
			return;
		}

		$this->maybe_install();
		$identity = $this->analytics->current_identity();
		$items = $this->cart_items_payload( $cart );
		$cart_hash = hash( 'sha256', wp_json_encode( $items ) );
		$existing = $this->find_open_cart( $identity );
		$signature = $cart_hash;
		if ( $signature === $this->request_capture_signature ) {
			$this->capturing = false;
			return;
		}
		$this->request_capture_signature = $signature;
		$now = current_time( 'mysql' );
		$total = method_exists( $cart, 'get_total' ) ? (float) $cart->get_total( 'edit' ) : (float) $cart->get_cart_contents_total();
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

		global $wpdb;

		if ( $existing ) {
			$same_cart = hash_equals( (string) ( $existing['cart_hash'] ?? '' ), $cart_hash );
			$last_activity = (int) get_gmt_from_date( (string) ( $existing['last_activity_at'] ?? '' ), 'U' );
			$heartbeat = max( MINUTE_IN_SECONDS, (int) ( $this->config()['heartbeat_minutes'] ?? 5 ) * MINUTE_IN_SECONDS );
			if ( 'browse' === $reason && $same_cart && $last_activity > 0 && ( time() - $last_activity ) < $heartbeat ) {
				Runtime_Profiler::mark( 'abandoned_cart.write_skipped', [ 'reason' => 'unchanged_within_heartbeat' ] );
				$this->capturing = false;
				return;
			}

			if ( 'abandoned' === $existing['status'] ) {
				$data['recovered_at'] = $now;
			}

			if ( 'browse' === $reason && $same_cart ) {
				$data = array_intersect_key( $data, array_flip( [ 'visitor_hash', 'customer_hash', 'user_id', 'contact_hash', 'contact_type', 'phonekey_verified', 'status', 'last_activity_at', 'updated_at', 'recovered_at' ] ) );
			}
			$profile = Runtime_Profiler::start();
			$wpdb->update( $this->carts_table(), $data, [ 'id' => (int) $existing['id'] ] );
			Runtime_Profiler::finish( 'abandoned_cart.update', $profile );
		} else {
			$data['first_seen_at'] = $now;
			$data['created_at'] = $now;
			$profile = Runtime_Profiler::start();
			$wpdb->insert( $this->carts_table(), $data );
			Runtime_Profiler::finish( 'abandoned_cart.insert', $profile );
		}

		$this->capturing = false;
	}

	public function mark_current_cart_cleared(): void {
		if ( empty( $this->config()['enabled'] ) ) {
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
		if ( ! $user_id || empty( $this->config()['enabled'] ) ) {
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
		$this->maybe_install();
		$minutes = max( 15, absint( $this->config()['inactivity_minutes'] ?? 60 ) );
		$before = wp_date( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ), wp_timezone() );
		$now = current_time( 'mysql' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->carts_table()} SET status='abandoned', abandoned_at=COALESCE(abandoned_at,%s), updated_at=%s WHERE status='active' AND item_count > 0 AND last_activity_at < %s",
				$now,
				$now,
				$before
			)
		);
	}

	public function analytics_summary( int $days = 30 ): array {
		global $wpdb;
		$this->refresh_abandoned_statuses();
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
		$this->refresh_abandoned_statuses();
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
		$this->maybe_install();
		$limit = max( 1, min( 250, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->logs_table()} ORDER BY created_at DESC LIMIT %d", $limit ), ARRAY_A ) ?: [];
	}

	public function send_reminder( int $cart_id, string $channel ) {
		global $wpdb;
		$this->refresh_abandoned_statuses();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->carts_table()} WHERE id=%d LIMIT 1", $cart_id ), ARRAY_A );
		$channel = sanitize_key( $channel );

		if ( ! $row || 'abandoned' !== $row['status'] ) {
			return new \WP_Error( 'dsa_cart_not_abandoned', __( 'That cart is no longer abandoned.', 'dsa' ) );
		}

		if ( ! $this->channels->available( $channel ) ) {
			return new \WP_Error( 'dsa_cart_channel_unavailable', __( 'That reminder channel is not configured.', 'dsa' ) );
		}

		$config = $this->config();
		$max = max( 1, absint( $config['max_reminders'] ?? 3 ) );

		if ( (int) $row['reminder_count'] >= $max ) {
			return new \WP_Error( 'dsa_cart_reminder_limit', __( 'This cart reached its reminder limit.', 'dsa' ) );
		}

		$cooldown = max( 1, absint( $config['cooldown_hours'] ?? 24 ) );

		$last_reminder = ! empty( $row['last_reminder_at'] ) ? \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $row['last_reminder_at'], wp_timezone() ) : false;

		if ( $last_reminder && $last_reminder->getTimestamp() > time() - ( $cooldown * HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'dsa_cart_reminder_cooldown', __( 'This cart is still inside the reminder cooldown.', 'dsa' ) );
		}

		$contacts = $this->contact_channels_for_user( (int) $row['user_id'] );
		$recipient = (string) ( $contacts[ $channel ]['value'] ?? '' );

		if ( '' === $recipient ) {
			return new \WP_Error( 'dsa_cart_contact_missing', __( 'No verified contact is available for that channel.', 'dsa' ) );
		}

		$token = $this->issue_recovery_token( (int) $row['id'] );
		$recovery_url = add_query_arg( 'dsa_cart_recover', $token, home_url( '/' ) );
		$vars = [
			'{site_name}'    => get_bloginfo( 'name' ),
			'{item_count}'   => (string) (int) $row['item_count'],
			'{cart_total}'   => $this->money( (float) $row['cart_total'], (string) $row['currency'] ),
			'{recovery_url}' => esc_url_raw( $recovery_url ),
		];
		$subject = strtr( (string) ( $config['email_subject'] ?? '' ), $vars );
		$template_key = 'email' === $channel ? 'email_message' : $channel . '_message';
		$message = strtr( (string) ( $config[ $template_key ] ?? $config['email_message'] ?? '' ), $vars );
		$result = $this->channels->send(
			$channel,
			$recipient,
			$subject,
			$message,
			[ 'cart_id' => (int) $row['id'], 'recovery_url' => esc_url_raw( $recovery_url ) ]
		);

		$recipient_hash = hash_hmac( 'sha256', strtolower( trim( $recipient ) ), wp_salt( 'auth' ) );
		$status = is_wp_error( $result ) ? 'failed' : 'sent';
		$log_message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Reminder accepted by the configured channel.', 'dsa' );
		$wpdb->insert(
			$this->logs_table(),
			[
				'cart_id'        => (int) $row['id'],
				'channel'        => $channel,
				'recipient_hash' => $recipient_hash,
				'status'         => $status,
				'message'        => substr( sanitize_text_field( $log_message ), 0, 255 ),
				'sent_by'        => get_current_user_id(),
				'created_at'     => current_time( 'mysql' ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$this->carts_table()} SET reminder_count=reminder_count+1,last_reminder_at=%s,updated_at=%s WHERE id=%d", $now, $now, (int) $row['id'] )
		);

		return true;
	}

	public function maybe_restore_cart(): void {
		$token = sanitize_text_field( wp_unslash( $_GET['dsa_cart_recover'] ?? '' ) );

		if ( '' === $token || ! $this->woocommerce_cart_ready() ) {
			return;
		}

		global $wpdb;
		$hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->carts_table()} WHERE recovery_token_hash=%s AND recovery_expires_at >= %s LIMIT 1", $hash, current_time( 'mysql' ) ),
			ARRAY_A
		);

		if ( ! $row || in_array( $row['status'], [ 'converted', 'cleared' ], true ) ) {
			wp_safe_redirect( add_query_arg( 'dsa-cart-recovery', 'expired', function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) ) );
			exit;
		}

		$items = json_decode( (string) $row['items'], true );

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				$this->restore_cart_item( is_array( $item ) ? $item : [] );
			}
		}

		$now = current_time( 'mysql' );
		$wpdb->update(
			$this->carts_table(),
			[
				'status'                => 'active',
				'recovered_at'          => $now,
				'last_activity_at'      => $now,
				'recovery_token_hash'   => '',
				'recovery_expires_at'   => null,
				'updated_at'            => $now,
			],
			[ 'id' => (int) $row['id'] ]
		);

		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}

		wp_safe_redirect( add_query_arg( 'dsa-cart-recovery', 'restored', function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) ) );
		exit;
	}

	public function config(): array {
		$all = $this->settings->all();
		$defaults = $this->settings->defaults();
		return wp_parse_args( $all['abandoned_cart'] ?? [], $defaults['abandoned_cart'] ?? [] );
	}

	private function convert_cart_for_order( $order_or_id ): void {
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

		if ( ! empty( $identity['visitor_hash'] ) ) {
			$parts[] = 'visitor_hash=%s';
			$params[] = (string) $identity['visitor_hash'];
		}

		if ( ! $parts ) {
			return [];
		}

		$sql = "SELECT * FROM {$this->carts_table()} WHERE status IN ('active','abandoned') AND (" . implode( ' OR ', $parts ) . ') ORDER BY id DESC LIMIT 1';
		return $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
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

	private function hydrate_admin_row( array $row ): array {
		$items = json_decode( (string) ( $row['items'] ?? '' ), true );
		$items = is_array( $items ) ? $items : [];
		$contacts = $this->contact_channels_for_user( (int) ( $row['user_id'] ?? 0 ) );
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
		$out = [
			'email'    => [ 'value' => '', 'masked' => '' ],
			'sms'      => [ 'value' => '', 'masked' => '' ],
			'whatsapp' => [ 'value' => '', 'masked' => '' ],
		];

		if ( ! $user_id ) {
			return $out;
		}

		$user = get_user_by( 'id', $user_id );
		$email = $user ? sanitize_email( (string) $user->user_email ) : '';

		if ( ! is_email( $email ) ) {
			$email = sanitize_email( (string) get_user_meta( $user_id, 'billing_email', true ) );
		}

		if ( is_email( $email ) ) {
			$out['email'] = [ 'value' => $email, 'masked' => $this->mask_email( $email ) ];
		}

		$phone = (string) get_user_meta( $user_id, 'billing_phone', true );

		if ( '' === trim( $phone ) ) {
			$phone = $this->verified_phonekey_phone( $user_id );
		}

		$phone = function_exists( 'pk_normalize_phone' ) ? (string) pk_normalize_phone( $phone ) : preg_replace( '/[^0-9+]/', '', $phone );

		if ( strlen( preg_replace( '/\D/', '', $phone ) ) >= 7 ) {
			$masked = $this->mask_phone( $phone );
			$out['sms'] = [ 'value' => $phone, 'masked' => $masked ];
			$out['whatsapp'] = [ 'value' => $phone, 'masked' => $masked ];
		}

		return $out;
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

	private function issue_recovery_token( int $cart_id ): string {
		global $wpdb;
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$expires = wp_date( 'Y-m-d H:i:s', time() + ( max( 1, absint( $this->config()['recovery_link_days'] ?? 7 ) ) * DAY_IN_SECONDS ), wp_timezone() );
		$wpdb->update(
			$this->carts_table(),
			[ 'recovery_token_hash' => $hash, 'recovery_expires_at' => $expires, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $cart_id ]
		);
		return $token;
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
		$days = max( 7, absint( $this->config()['retention_days'] ?? 90 ) );
		$before = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ), wp_timezone() );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->logs_table()} WHERE created_at < %s", $before ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->carts_table()} WHERE updated_at < %s AND status IN ('converted','cleared','abandoned')", $before ) );
	}

	private function woocommerce_cart_ready(): bool {
		return function_exists( 'WC' ) && WC() && WC()->cart && WC()->session;
	}

	private function money( float $amount, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount, [ 'currency' => $currency ?: get_woocommerce_currency() ] ) );
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
