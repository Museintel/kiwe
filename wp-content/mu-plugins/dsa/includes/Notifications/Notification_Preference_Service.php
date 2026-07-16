<?php

namespace DSA\Notifications;

use DSA\Settings;
use DSA\Trust\Trust_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notification_Preference_Service {
	private const USER_META = 'dsa_notification_preferences';
	private const TRANSIENT_PREFIX = 'dsa_notification_preferences_';
	private const DB_VERSION = '1';
	private const DB_VERSION_OPTION = 'dsa_notification_preferences_db_version';

	private $settings;
	private $trust;

	public function __construct( Settings $settings, Trust_Service $trust ) {
		$this->settings = $settings;
		$this->trust    = $trust;
	}

	public function register(): void {
		add_action( 'init', [ $this, 'maybe_install' ], 3 );
		add_filter( 'woocommerce_product_add_to_cart_text', [ $this, 'loop_button_text' ], 30, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_args', [ $this, 'loop_button_args' ], 30, 2 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_single_notify_button' ], 31 );
	}

	public function public_config(): array {
		$permissions = $this->settings->get( 'permissions', [] );
		$permissions = is_array( $permissions ) ? $permissions : [];
		$link_hub    = $this->settings->get( 'link_hub', [] );

		return [
			'enabled'                  => ! empty( $permissions['notification_preferences_enabled'] ),
			'browserPermissionEnabled' => ! empty( $permissions['notifications_enabled'] ),
			'passiveOrderEnabled'      => ! empty( $permissions['notification_order_prompt_enabled'] ),
			'ctaLabel'                 => $this->cta_label(),
			'ctaColor'                 => $this->cta_color(),
			'commerce'                 => $this->woo_available(),
			'channels'                 => $this->channels(),
			'topics'                   => $this->topics(),
			'productCategories'        => $this->categories( 'product_cat' ),
			'postCategories'           => $this->categories( 'category' ),
			'currentProduct'           => $this->current_product(),
			'trustBadges'              => $this->trust->health_data( is_array( $link_hub ) ? $link_hub : [] ),
		];
	}

	public function preferences( string $visitor_id, bool $standalone = false ): array {
		$logged_in = is_user_logged_in();
		$stored = $logged_in ? get_user_meta( get_current_user_id(), self::USER_META, true ) : get_transient( $this->transient_key( $visitor_id ) );
		if ( $logged_in && ! is_array( $stored ) ) {
			$guest = get_transient( $this->transient_key( $visitor_id ) );
			if ( is_array( $guest ) ) {
				$stored = $guest;
				update_user_meta( get_current_user_id(), self::USER_META, $stored );
			}
		}
		$preferences = $this->sanitize_preferences( is_array( $stored ) ? $stored : [] );
		if ( $logged_in && ( ! empty( $preferences['topics'] ) || ! empty( $preferences['channels'] ) ) ) {
			$this->upsert_audience_record( $visitor_id, $preferences, array_merge( $preferences, [ 'standalone' => $standalone || ! empty( $preferences['standalone'] ) ] ) );
		}
		return $preferences;
	}

	public function save( array $payload ): array {
		$visitor_id  = sanitize_text_field( (string) ( $payload['visitorId'] ?? '' ) );
		$preferences = $this->sanitize_preferences( $payload );
		$preferences['updatedAt'] = time();

		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::USER_META, $preferences );
		} else {
			set_transient( $this->transient_key( $visitor_id ), $preferences, 30 * DAY_IN_SECONDS );
		}

		$this->upsert_audience_record( $visitor_id, $preferences, $payload );

		$identity_channels = array_intersect( [ 'email', 'sms', 'whatsapp' ], $preferences['channels'] );

		return [
			'ok'            => true,
			'preferences'   => $preferences,
			'needsIdentity' => ! is_user_logged_in() && ! empty( $identity_channels ),
			'loggedIn'      => is_user_logged_in(),
		];
	}

	public function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $this->table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_hash char(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			phonekey_verified tinyint(1) NOT NULL DEFAULT 0,
			is_app tinyint(1) NOT NULL DEFAULT 0,
			browser_permission varchar(20) NOT NULL DEFAULT 'default',
			topics longtext NULL,
			channels longtext NULL,
			product_categories longtext NULL,
			post_categories longtext NULL,
			product_ids longtext NULL,
			context varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY visitor_hash (visitor_hash),
			KEY user_id (user_id),
			KEY app_identity (is_app,user_id),
			KEY updated_at (updated_at)
		) {$charset};" );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public function audience_summary(): array {
		global $wpdb;
		$this->maybe_install();
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY updated_at DESC LIMIT 2000", ARRAY_A );
		$summary = [
			'total' => 0,
			'appUsers' => 0,
			'appAnonymous' => 0,
			'registered' => 0,
			'phonekeyVerified' => 0,
			'channels' => [ 'app' => 0, 'email' => 0, 'whatsapp' => 0, 'sms' => 0 ],
			'registeredChannels' => [ 'app' => 0, 'email' => 0, 'whatsapp' => 0, 'sms' => 0 ],
			'topics' => [],
			'registeredTopics' => [],
			'rows' => [],
		];
		$channel_users = [ 'app' => [], 'email' => [], 'whatsapp' => [], 'sms' => [] ];
		$topic_users = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$summary['total']++;
			$user_id = absint( $row['user_id'] ?? 0 );
			$is_app = ! empty( $row['is_app'] );
			$channels = $this->decode_list( $row['channels'] ?? '' );
			$topics = $this->decode_list( $row['topics'] ?? '' );
			if ( $user_id ) $summary['registered']++;
			if ( ! empty( $row['phonekey_verified'] ) ) $summary['phonekeyVerified']++;
			if ( $is_app && $user_id ) $summary['appUsers']++;
			if ( $is_app && ! $user_id ) $summary['appAnonymous']++;
			foreach ( $channels as $channel ) {
				if ( isset( $summary['channels'][ $channel ] ) ) $summary['channels'][ $channel ]++;
				if ( $user_id && isset( $channel_users[ $channel ] ) ) $channel_users[ $channel ][ $user_id ] = true;
			}
			foreach ( $topics as $topic ) {
				$summary['topics'][ $topic ] = (int) ( $summary['topics'][ $topic ] ?? 0 ) + 1;
				if ( $user_id ) $topic_users[ $topic ][ $user_id ] = true;
			}
			if ( count( $summary['rows'] ) < 100 ) {
				$user = $user_id ? get_userdata( $user_id ) : false;
				$summary['rows'][] = [
					'visitor' => substr( sanitize_text_field( (string) ( $row['visitor_hash'] ?? '' ) ), 0, 10 ),
					'userId' => $user_id,
					'userName' => $user ? $user->display_name : '',
					'isApp' => $is_app,
					'phonekeyVerified' => ! empty( $row['phonekey_verified'] ),
					'browserPermission' => sanitize_key( (string) ( $row['browser_permission'] ?? 'default' ) ),
					'channels' => $channels,
					'topics' => $topics,
					'productCategories' => $this->decode_ids( $row['product_categories'] ?? '' ),
					'postCategories' => $this->decode_ids( $row['post_categories'] ?? '' ),
					'productIds' => $this->decode_ids( $row['product_ids'] ?? '' ),
					'updatedAt' => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
				];
			}
		}

		ksort( $summary['topics'] );
		foreach ( $channel_users as $channel => $users ) $summary['registeredChannels'][ $channel ] = count( $users );
		foreach ( $topic_users as $topic => $users ) $summary['registeredTopics'][ $topic ] = count( $users );
		ksort( $summary['registeredTopics'] );
		return $summary;
	}

	public function audience_user_ids( string $channel ): array {
		global $wpdb;
		$this->maybe_install();
		$rows = $wpdb->get_results( "SELECT user_id, channels, browser_permission FROM {$this->table()} WHERE user_id > 0 ORDER BY updated_at DESC", ARRAY_A );
		$ids = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( ! in_array( $channel, $this->decode_list( $row['channels'] ?? '' ), true ) ) continue;
			if ( 'app' === $channel && 'granted' !== sanitize_key( (string) ( $row['browser_permission'] ?? '' ) ) ) continue;
			$ids[] = absint( $row['user_id'] ?? 0 );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public function audience_user_ids_for_topic( string $channel, string $topic ): array {
		global $wpdb;
		$this->maybe_install();
		$channel = sanitize_key( $channel );
		$topic   = sanitize_key( $topic );
		$rows = $wpdb->get_results( "SELECT user_id, channels, topics, browser_permission FROM {$this->table()} WHERE user_id > 0 ORDER BY updated_at DESC", ARRAY_A );
		$ids = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			if ( 'admin_new_order' === $topic && ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) continue;
			if ( 'admin_new_comment' === $topic && ! user_can( $user_id, 'moderate_comments' ) && ! user_can( $user_id, 'manage_options' ) ) continue;
			if ( in_array( $topic, [ 'admin_visitor_summary', 'admin_live_visitor' ], true ) && ! user_can( $user_id, 'manage_options' ) ) continue;
			if ( '' !== $channel && ! in_array( $channel, $this->decode_list( $row['channels'] ?? '' ), true ) ) continue;
			if ( ! in_array( $topic, $this->decode_list( $row['topics'] ?? '' ), true ) ) continue;
			if ( 'app' === $channel && 'granted' !== sanitize_key( (string) ( $row['browser_permission'] ?? '' ) ) ) continue;
			$ids[] = $user_id;
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public function contact_for_user( int $user_id, string $channel ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) return '';
		if ( 'email' === $channel ) return sanitize_email( $user->user_email );
		$phone = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_phone', true ) );
		if ( '' === $phone && function_exists( 'pk_factor' ) && function_exists( 'pk_decrypt' ) ) {
			$factor = pk_factor( $user_id, 'phone' );
			$phone = is_array( $factor ) ? sanitize_text_field( (string) pk_decrypt( $factor['factor_value'] ?? '' ) ) : '';
		}
		return $phone;
	}

	public function loop_button_text( string $text, $product ): string {
		$context = $this->product_notification_context( $product );
		return $context ? $this->cta_label() : $text;
	}

	public function loop_button_args( array $args, $product ): array {
		$context = $this->product_notification_context( $product );

		if ( ! $context ) {
			return $args;
		}

		$args['class'] = trim( (string) ( $args['class'] ?? 'button' ) . ' dsa-notify-me-button dsa-notify-me-button--' . $this->cta_color() );
		$args['attributes'] = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : [];
		$args['attributes']['data-dsa-notify-product'] = (string) absint( $context['productId'] );
		$args['attributes']['data-dsa-notification-topic'] = sanitize_key( $context['topic'] );
		$args['attributes']['data-dsa-notification-reason'] = sanitize_key( $context['reason'] );
		$args['attributes']['aria-label'] = sprintf( __( 'Notify me about %s', 'dsa' ), $context['title'] );

		return $args;
	}

	public function render_single_notify_button(): void {
		global $product;
		$context = $this->product_notification_context( $product );

		if ( ! $context ) {
			return;
		}

		printf(
			'<button type="button" class="button dsa-notify-me-button dsa-notify-me-button--%1$s" data-dsa-notify-product="%2$d" data-dsa-notification-topic="%3$s" data-dsa-notification-reason="%4$s">%5$s</button>',
			esc_attr( $this->cta_color() ),
			(int) $context['productId'],
			esc_attr( $context['topic'] ),
			esc_attr( $context['reason'] ),
			esc_html( $this->cta_label() )
		);
	}

	private function sanitize_preferences( array $input ): array {
		$allowed_topics = array_column( $this->topics(), 'id' );
		$allowed_channels = array_column( array_filter( $this->channels(), static function ( array $channel ): bool { return ! empty( $channel['available'] ); } ), 'id' );
		$topics = isset( $input['topics'] ) && is_array( $input['topics'] ) ? $input['topics'] : [];
		$channels = isset( $input['channels'] ) && is_array( $input['channels'] ) ? $input['channels'] : [];

		return [
			'topics'            => array_values( array_intersect( $allowed_topics, array_map( 'sanitize_key', $topics ) ) ),
			'channels'          => array_values( array_intersect( $allowed_channels, array_map( 'sanitize_key', $channels ) ) ),
			'productCategories' => $this->sanitize_ids( $input['productCategories'] ?? [] ),
			'postCategories'    => $this->sanitize_ids( $input['postCategories'] ?? [] ),
			'productIds'        => $this->sanitize_ids( $input['productIds'] ?? [] ),
			'context'           => substr( sanitize_key( (string) ( $input['context'] ?? '' ) ), 0, 40 ),
			'standalone'        => ! empty( $input['standalone'] ),
			'browserPermission' => in_array( sanitize_key( (string) ( $input['browserPermission'] ?? 'default' ) ), [ 'default', 'granted', 'denied', 'unsupported' ], true ) ? sanitize_key( (string) ( $input['browserPermission'] ?? 'default' ) ) : 'default',
			'updatedAt'         => max( 0, (int) ( $input['updatedAt'] ?? 0 ) ),
		];
	}

	private function upsert_audience_record( string $visitor_id, array $preferences, array $payload ): void {
		global $wpdb;
		$this->maybe_install();
		$user_id = get_current_user_id();
		$hash = $this->visitor_hash( $visitor_id, $user_id );
		if ( '' === $hash ) return;
		$now = current_time( 'mysql' );
		$data = [
			'visitor_hash' => $hash,
			'user_id' => $user_id,
			'phonekey_verified' => $user_id && function_exists( 'pk_account_verified' ) && pk_account_verified( $user_id ) ? 1 : 0,
			'is_app' => ! empty( $payload['standalone'] ) ? 1 : 0,
			'browser_permission' => sanitize_key( (string) ( $preferences['browserPermission'] ?? 'default' ) ),
			'topics' => wp_json_encode( $preferences['topics'] ),
			'channels' => wp_json_encode( $preferences['channels'] ),
			'product_categories' => wp_json_encode( $preferences['productCategories'] ),
			'post_categories' => wp_json_encode( $preferences['postCategories'] ),
			'product_ids' => wp_json_encode( $preferences['productIds'] ),
			'context' => sanitize_key( (string) ( $preferences['context'] ?? '' ) ),
			'updated_at' => $now,
		];
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE visitor_hash = %s", $hash ) );
		if ( $existing ) {
			$wpdb->update( $this->table(), $data, [ 'id' => absint( $existing ) ] );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $this->table(), $data );
		}
	}

	private function decode_list( $value ): array {
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? array_values( array_filter( array_map( 'sanitize_key', $decoded ) ) ) : [];
	}

	private function decode_ids( $value ): array {
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? array_values( array_unique( array_filter( array_map( 'absint', $decoded ) ) ) ) : [];
	}

	private function visitor_hash( string $visitor_id, int $user_id = 0 ): string {
		$identity = '' !== $visitor_id ? $visitor_id : ( $user_id ? 'user:' . $user_id : $this->client_anchor() );
		return hash_hmac( 'sha256', $identity, wp_salt( 'auth' ) );
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsa_notification_preferences';
	}

	private function sanitize_ids( $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : [] ) ) ) );
	}

	private function transient_key( string $visitor_id ): string {
		$identity = '' !== $visitor_id ? $visitor_id : $this->client_anchor();
		return self::TRANSIENT_PREFIX . substr( hash_hmac( 'sha256', $identity, wp_salt( 'auth' ) ), 0, 32 );
	}

	private function client_anchor(): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		return $ip . '|' . substr( $agent, 0, 180 );
	}

	private function channels(): array {
		$email = $this->settings->get( 'email', [] );
		$recovery = $this->settings->get( 'abandoned_cart', [] );
		$provider_channels = is_array( $recovery ) && isset( $recovery['channels'] ) && is_array( $recovery['channels'] ) ? $recovery['channels'] : [];

		return [
			[ 'id' => 'app', 'label' => __( 'App', 'dsa' ), 'description' => __( 'Offline browser notifications on this device.', 'dsa' ), 'available' => true ],
			[ 'id' => 'email', 'label' => __( 'Email', 'dsa' ), 'description' => ! empty( $email['enabled'] ) ? __( 'Useful updates in your inbox.', 'dsa' ) : __( 'Save email preferences now; delivery begins when site email is configured.', 'dsa' ), 'available' => true ],
			[ 'id' => 'whatsapp', 'label' => __( 'WhatsApp', 'dsa' ), 'description' => __( 'Updates through the configured WhatsApp provider.', 'dsa' ), 'available' => $this->channel_ready( $provider_channels['whatsapp'] ?? [] ) ],
			[ 'id' => 'sms', 'label' => __( 'SMS', 'dsa' ), 'description' => __( 'Text messages through the configured SMS provider.', 'dsa' ), 'available' => $this->channel_ready( $provider_channels['sms'] ?? [] ) ],
		];
	}

	private function channel_ready( $channel ): bool {
		return is_array( $channel ) && ! empty( $channel['enabled'] ) && ! empty( $channel['webhook_url'] );
	}

	private function topics(): array {
		$admin_topics = [];
		if ( is_user_logged_in() && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			$admin_topics[] = [ 'id' => 'admin_new_order', 'label' => __( 'New store orders', 'dsa' ), 'description' => __( 'Private owner alerts when a WooCommerce order arrives.', 'dsa' ), 'audience' => 'admin' ];
		}
		if ( is_user_logged_in() && ( current_user_can( 'moderate_comments' ) || current_user_can( 'manage_options' ) ) ) {
			$admin_topics[] = [ 'id' => 'admin_new_comment', 'label' => __( 'New comments', 'dsa' ), 'description' => __( 'Private owner alerts when a comment needs attention.', 'dsa' ), 'audience' => 'admin' ];
		}
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$admin_topics[] = [ 'id' => 'admin_live_visitor', 'label' => __( 'Live visitors', 'dsa' ), 'description' => __( 'Private owner alerts when a new or returning visitor is recorded while you have Kiwe open.', 'dsa' ), 'audience' => 'admin' ];
			$admin_topics[] = [ 'id' => 'admin_visitor_summary', 'label' => __( 'Visitor summary', 'dsa' ), 'description' => __( 'Private owner insight showing today visitors versus yesterday.', 'dsa' ), 'audience' => 'admin' ];
		}

		if ( ! $this->woo_available() ) {
			return array_merge( [
				[ 'id' => 'newsletter', 'label' => __( 'Newsletter', 'dsa' ), 'description' => __( 'Occasional highlights selected by the publisher.', 'dsa' ) ],
				[ 'id' => 'new_post', 'label' => __( 'New posts', 'dsa' ), 'description' => __( 'Fresh stories from categories you follow.', 'dsa' ) ],
			], $admin_topics );
		}

		return array_merge( [
			[ 'id' => 'new_offer', 'label' => __( 'Offers and coupons', 'dsa' ), 'description' => __( 'New savings worth knowing about.', 'dsa' ) ],
			[ 'id' => 'price_change', 'label' => __( 'Sale and price changes', 'dsa' ), 'description' => __( 'Price movement for products you care about.', 'dsa' ) ],
			[ 'id' => 'stock_update', 'label' => __( 'Stock updates', 'dsa' ), 'description' => __( 'Know when unavailable products return.', 'dsa' ) ],
			[ 'id' => 'new_product', 'label' => __( 'New products', 'dsa' ), 'description' => __( 'New arrivals from selected categories.', 'dsa' ) ],
			[ 'id' => 'order_status', 'label' => __( 'Order status', 'dsa' ), 'description' => __( 'Progress after checkout and fulfilment updates.', 'dsa' ) ],
		], $admin_topics );
	}

	private function categories( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true, 'number' => 60, 'orderby' => 'name' ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}

		return array_map(
			static function ( $term ): array {
				return [ 'id' => (int) $term->term_id, 'label' => sanitize_text_field( $term->name ) ];
			},
			$terms
		);
	}

	private function current_product(): array {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$context = $this->product_notification_context( wc_get_product( get_queried_object_id() ) );
		return $context ?: [];
	}

	private function product_notification_context( $product ): array {
		if ( empty( $this->public_config_without_product()['enabled'] ) || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return [];
		}

		$reason = '';
		$topic = '';
		if ( method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) {
			$reason = 'out_of_stock';
			$topic = 'stock_update';
		} elseif ( method_exists( $product, 'get_price' ) && '' === (string) $product->get_price() ) {
			$reason = 'price_unavailable';
			$topic = 'price_change';
		}

		if ( '' === $reason ) {
			return [];
		}

		return [
			'productId' => (int) $product->get_id(),
			'title'     => wp_strip_all_tags( method_exists( $product, 'get_name' ) ? $product->get_name() : __( 'this product', 'dsa' ) ),
			'reason'    => $reason,
			'topic'     => $topic,
		];
	}

	private function public_config_without_product(): array {
		$permissions = $this->settings->get( 'permissions', [] );
		return [ 'enabled' => ! empty( $permissions['notification_preferences_enabled'] ) ];
	}

	private function cta_label(): string {
		$permissions = $this->settings->get( 'permissions', [] );
		return sanitize_text_field( is_array( $permissions ) ? ( $permissions['notification_cta_label'] ?? __( 'Notify me', 'dsa' ) ) : __( 'Notify me', 'dsa' ) );
	}

	private function cta_color(): string {
		$permissions = $this->settings->get( 'permissions', [] );
		return is_array( $permissions ) && 'hover' === sanitize_key( $permissions['notification_cta_color'] ?? 'active' ) ? 'hover' : 'active';
	}

	private function woo_available(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}
}
