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
	private const DB_VERSION = '2';
	private const DB_VERSION_OPTION = 'dsa_notification_preferences_db_version';

	private $settings;
	private $trust;
	private bool $store_hooks_registered = false;

	public function __construct( Settings $settings, Trust_Service $trust ) {
		$this->settings = $settings;
		$this->trust    = $trust;
	}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_install' ], 25 );
		if ( function_exists( 'WC' ) ) $this->register_store_hooks();
		else add_action( 'plugins_loaded', [ $this, 'register_store_hooks' ], 30 );
	}

	public function register_store_hooks(): void {
		if ( $this->store_hooks_registered || ! \DSA\Apps\Store_App_Service::enabled() ) return;
		$this->store_hooks_registered = true;
		add_filter( 'woocommerce_product_add_to_cart_text', [ $this, 'loop_button_text' ], 30, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_args', [ $this, 'loop_button_args' ], 30, 2 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_single_notify_button' ], 31 );
	}

	/** The inbox and personal choices are core; gateways retain their own readiness. */
	public function enabled(): bool { return true; }

	public function public_config(): array {
		return $this->config( false );
	}

	/** Current identity catalog belongs in the existing private preference response. */
	public function private_config(): array {
		return $this->config( true );
	}

	private function config( bool $personal ): array {
		$permissions = $this->settings->get( 'permissions', [] );
		$permissions = is_array( $permissions ) ? $permissions : [];
		$link_hub    = $this->settings->get( 'link_hub', [] );

		return [
			'enabled'                  => $this->enabled(),
			'browserPermissionEnabled' => ! empty( $permissions['notifications_enabled'] ),
			'passiveOrderEnabled'      => ! empty( $permissions['notification_order_prompt_enabled'] ),
			'ctaLabel'                 => $this->cta_label(),
			'ctaColor'                 => $this->cta_color(),
			'commerce'                 => $this->woo_available(),
			'channels'                 => $this->channels( $personal ),
			'topics'                   => $this->topics( $personal ),
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

	/** Small workspace projection: no audience sync, taxonomy inventory or trust probes on GET. */
	public function workspace_snapshot(): array {
		$stored = get_user_meta( get_current_user_id(), self::USER_META, true );
		$preferences = $this->sanitize_preferences( is_array( $stored ) ? $stored : [] );
		return [ 'config'=>[ 'enabled'=>$this->enabled(), 'topics'=>$this->topics(), 'channels'=>$this->channels() ], 'preferences'=>$preferences, 'expected'=>hash( 'sha256', wp_json_encode( $preferences ) ) ];
	}

	public function save_workspace( array $input ) {
		$before = $this->workspace_snapshot();
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $before['expected'], $input['expected'] ) ) return new \WP_Error( 'kiwe_notifications_stale', 'Your preferences changed elsewhere. Refresh before saving.', [ 'status'=>409 ] );
		foreach ( [ 'topics','channels','topicChannels' ] as $key ) if ( ! is_array( $input[ $key ] ?? null ) || count( $input[ $key ] ) > 100 ) return new \WP_Error( 'kiwe_notifications_input', 'Choose valid notification preferences.', [ 'status'=>400 ] );
		if ( isset( $input['inboxTopics'] ) && ( ! is_array( $input['inboxTopics'] ) || count( $input['inboxTopics'] )>100 || count( array_filter( $input['inboxTopics'], 'is_string' ) ) !== count( $input['inboxTopics'] ) ) ) return new \WP_Error( 'kiwe_notifications_input', 'Choose valid inbox updates.', [ 'status'=>400 ] );
		foreach ( [ 'topics','channels' ] as $key ) if ( count( array_filter( $input[ $key ], 'is_string' ) ) !== count( $input[ $key ] ) ) return new \WP_Error( 'kiwe_notifications_input', 'Choose valid notification preferences.', [ 'status'=>400 ] );
		foreach ( $input['topicChannels'] as $selected ) if ( ! is_array( $selected ) || count( $selected ) > 4 || count( array_filter( $selected, 'is_string' ) ) !== count( $selected ) ) return new \WP_Error( 'kiwe_notifications_input', 'Choose valid delivery channels.', [ 'status'=>400 ] );
		$next = array_replace( $before['preferences'], array_intersect_key( $input, array_flip( [ 'topics','channels','topicChannels','inboxTopics' ] ) ) );
		$next['visitorId'] = 'admin:user:' . get_current_user_id();
		$next['context'] = 'wordpress_admin';
		$result = $this->save( $next );
		$after = $this->workspace_snapshot();
		if ( $after['preferences'] !== $result['preferences'] ) return new \WP_Error( 'kiwe_notifications_unconfirmed', 'Your preference save could not be confirmed. Refresh and review.', [ 'status'=>409 ] );
		if ( ! $result['audienceSaved'] ) return new \WP_Error( 'kiwe_notifications_delivery', 'Your choices were saved, but delivery setup could not be confirmed. Refresh, review your choices and save again.', [ 'status'=>409 ] );
		return $after;
	}

	public function save( array $payload ): array {
		// Older DSA clients do not know per-event overrides; preserve those choices.
		if ( is_user_logged_in() && ! array_key_exists( 'topicChannels', $payload ) ) {
			$prior = get_user_meta( get_current_user_id(), self::USER_META, true );
			$payload['topicChannels'] = is_array( $prior ) ? ( $prior['topicChannels'] ?? [] ) : [];
		}
		$visitor_id  = sanitize_text_field( (string) ( $payload['visitorId'] ?? '' ) );
		$preferences = $this->sanitize_preferences( $payload );
		$preferences['updatedAt'] = time();
		$prior = is_user_logged_in() ? get_user_meta( get_current_user_id(), self::USER_META, true ) : get_transient( $this->transient_key( $visitor_id ) );
		$persisted = $this->retain_store_choices( $preferences, is_array( $prior ) ? $prior : [] );

		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::USER_META, $persisted );
		} else {
			set_transient( $this->transient_key( $visitor_id ), $persisted, 30 * DAY_IN_SECONDS );
		}

		$audience_saved = $this->upsert_audience_record( $visitor_id, $persisted, $payload );

		$identity_channels = array_intersect( [ 'email', 'sms', 'whatsapp' ], $preferences['channels'] );

		return [
			'ok'            => true,
			'preferences'   => $preferences,
			'needsIdentity' => ! is_user_logged_in() && ! empty( $identity_channels ),
			'loggedIn'      => is_user_logged_in(),
			'audienceSaved' => $audience_saved,
		];
	}

	public function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		if ( ! ( is_admin() || PHP_SAPI === 'cli' ) || ! current_user_can( 'kiwe_manage_notification_policy' ) ) return;

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
			app_installed_at datetime NULL,
			app_first_opened_at datetime NULL,
			app_last_opened_at datetime NULL,
			app_platform varchar(16) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY visitor_hash (visitor_hash),
			KEY user_id (user_id),
			KEY app_identity (is_app,user_id),
			KEY app_used (is_app,app_last_opened_at,id),
			KEY updated_at (updated_at)
		) {$charset};" );
		$columns = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name IN (%s,%s,%s,%s)', $table, 'app_installed_at', 'app_first_opened_at', 'app_last_opened_at', 'app_platform' ) );
		if ( 4 === $columns ) update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
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

	/**
	 * A bounded, explicit marketing audience. Both the saved topic and channel
	 * must still be present in the user's own current preference record. The
	 * audience table is only a discovery index, never consent authority.
	 */
	public function campaign_audience( string $channel, string $topic ): array {
		global $wpdb;
		$channel = sanitize_key( $channel );
		$topic = sanitize_key( $topic );
		if ( in_array( $topic, self::STORE_TOPICS, true ) && !$this->woo_available() ) return [ 'ids'=>[], 'limited'=>false, 'sampled'=>false ];
		if ( ! $this->enabled() || ! in_array( $channel, [ 'app', 'email', 'whatsapp', 'sms' ], true ) || ! in_array( $topic, [ 'newsletter', 'new_offer' ], true ) ) return [ 'ids'=>[], 'limited'=>false, 'sampled'=>false ];
		$this->maybe_install();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id FROM {$this->table()} WHERE user_id > 0 AND topics LIKE %s ORDER BY updated_at DESC, id DESC LIMIT 201",
			'%' . $wpdb->esc_like( '"' . $topic . '"' ) . '%'
		), ARRAY_A );
		$ids = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			if ( ! $user_id || isset( $ids[ $user_id ] ) || ! $this->user_accepts( $user_id, $channel, $topic ) ) continue;
			if ( 'app' === $channel ) {
				$stored = get_user_meta( $user_id, self::USER_META, true );
				if ( 'granted' !== sanitize_key( (string) ( is_array( $stored ) ? ( $stored['browserPermission'] ?? '' ) : '' ) ) ) continue;
			}
			if ( 'app' !== $channel && '' === $this->contact_for_user( $user_id, $channel ) ) continue;
			$ids[ $user_id ] = true;
			if ( count( $ids ) > 100 ) break;
		}
		return [ 'ids'=>array_slice( array_map( 'intval', array_keys( $ids ) ), 0, 100 ), 'limited'=>count( $ids ) > 100, 'sampled'=>is_array( $rows ) && count( $rows ) > 200 ];
	}

	/** A primary-key cursor avoids a full audience load during publication. */
	public function post_alert_ceiling(): int {
		global $wpdb;
		$this->maybe_install();
		return max( 0, (int) $wpdb->get_var( "SELECT MAX(id) FROM {$this->table()}" ) );
	}

	public function post_alert_rows_after( int $cursor, int $ceiling, int $limit = 25 ): array {
		global $wpdb;
		$this->maybe_install();
		$limit = max( 1, min( 25, $limit ) );
		if ( $ceiling < 1 || $cursor >= $ceiling ) return [];
		$table = $this->table();
		$topic = '%' . $wpdb->esc_like( '"new_post"' ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id,p.user_id,p.visitor_hash FROM {$table} p WHERE p.id > %d AND p.id <= %d AND p.topics LIKE %s AND (p.user_id=0 OR NOT EXISTS (SELECT 1 FROM {$table} earlier WHERE earlier.user_id=p.user_id AND earlier.user_id>0 AND earlier.id<p.id AND earlier.topics LIKE %s)) ORDER BY p.id ASC LIMIT %d",
			max( 0, $cursor ), $ceiling, $topic, $topic, $limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	public function post_alert_inbox_user_for_row( array $row, array $category_ids ): int {
		$id = absint( $row['user_id'] ?? 0 );
		if ( !$id || !$this->inbox_accepts( $id, 'new_post' ) ) return 0;
		$stored = get_user_meta( $id, self::USER_META, true );
		$selected = array_map( 'absint', (array) ( is_array( $stored ) ? ( $stored['postCategories'] ?? [] ) : [] ) );
		return $selected && !array_intersect( $selected, array_map( 'absint', $category_ids ) ) ? 0 : $id;
	}

	/** Re-read current consent; the table row is only an indexed discovery key. */
	public function post_alert_channels_for_row( array $row, array $category_ids = [], bool $filter_categories = true ): array {
		if ( ! $this->enabled() ) return [];
		$user_id = absint( $row['user_id'] ?? 0 );
		$stored = [];
		if ( $user_id ) {
			if ( ! $this->topic_allowed_for_user( $user_id, 'new_post' ) ) return [];
			$stored = get_user_meta( $user_id, self::USER_META, true );
		} else {
			$hash = strtolower( (string) ( $row['visitor_hash'] ?? '' ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) return [];
			$stored = get_transient( self::TRANSIENT_PREFIX . substr( $hash, 0, 32 ) );
			if ( ! is_array( $stored ) || time() - absint( $stored['updatedAt'] ?? 0 ) > 30 * DAY_IN_SECONDS ) return [];
		}
		if ( ! is_array( $stored ) || ! in_array( 'new_post', (array) ( $stored['topics'] ?? [] ), true ) ) return [];
		$selected = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $stored['postCategories'] ?? [] ) ) ) ) );
		if ( $filter_categories && $selected && ! array_intersect( $selected, array_map( 'absint', $category_ids ) ) ) return [];
		$channels = $this->delivery_channels( $stored, 'new_post' );
		if ( 'granted' !== sanitize_key( (string) ( $stored['browserPermission'] ?? '' ) ) ) $channels = array_diff( $channels, [ 'app' ] );
		if ( ! $user_id ) return in_array( 'app', $channels, true ) ? [ 'app' ] : [];
		return array_values( array_filter( $channels, function ( string $channel ) use ( $user_id ): bool {
			return 'app' === $channel || '' !== $this->contact_for_user( $user_id, $channel );
		} ) );
	}

	/** Aggregate only; no account/contact list is exposed to the client. */
	public function post_alert_summary(): array {
		global $wpdb;
		if ( ! $this->enabled() ) return [ 'enabled'=>false, 'counts'=>array_fill_keys( [ 'app','email','whatsapp','sms' ], 0 ), 'sampled'=>false ];
		$this->maybe_install();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id,visitor_hash FROM {$this->table()} WHERE topics LIKE %s ORDER BY updated_at DESC,id DESC LIMIT 201",
			'%' . $wpdb->esc_like( '"new_post"' ) . '%'
		), ARRAY_A );
		$counts = array_fill_keys( [ 'app','email','whatsapp','sms' ], 0 );
		$seen = [];
		foreach ( is_array( $rows ) ? array_slice( $rows, 0, 200 ) : [] as $row ) {
			$key = absint( $row['user_id'] ?? 0 ) ? 'u:' . absint( $row['user_id'] ) : 'v:' . (string) ( $row['visitor_hash'] ?? '' );
			if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;
			foreach ( $this->post_alert_channels_for_row( $row, [], false ) as $channel ) ++$counts[ $channel ];
		}
		return [ 'enabled'=>true, 'counts'=>$counts, 'sampled'=>is_array( $rows ) && count( $rows ) > 200 ];
	}

	public function audience_user_ids_for_topic( string $channel, string $topic, array $explicit = [] ): array {
		global $wpdb;
		$this->maybe_install();
		$channel = sanitize_key( $channel );
		$topic   = sanitize_key( $topic );
		$ids_filter = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $explicit ) ) ) ), 0, 100 );
		$where = $ids_filter ? ' AND user_id IN (' . implode( ',', $ids_filter ) . ')' : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, channels, topics, browser_permission FROM {$this->table()} WHERE user_id > 0{$where} AND topics LIKE %s ORDER BY updated_at DESC LIMIT 500", '%' . $wpdb->esc_like( '"' . $topic . '"' ) . '%' ), ARRAY_A );
		$ids = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			if ( 'securetrack_incident' === $topic && ! user_can( $user_id, 'kiwe_manage_notification_policy' ) ) continue;
			if ( 'admin_guest_application' === $topic && ! user_can( $user_id, 'kiwe_manage_guest_applications' ) ) continue;
			if ( 'admin_guest_submission' === $topic && ! user_can( $user_id, 'edit_others_posts' ) ) continue;
			if ( 'admin_guest_product_submission' === $topic && ( ! $this->woo_available() || ! user_can( $user_id, 'edit_products' ) || ! user_can( $user_id, 'edit_others_products' ) ) ) continue;
			if ( 'guest_post_status' === $topic && [] === \DSA\Access\Guest_Contribution_Service::application( $user_id ) ) continue;
			if ( 'guest_product_status' === $topic && ( ! $this->woo_available() || [] === \DSA\Access\Guest_Contribution_Service::application( $user_id ) ) ) continue;
			$audience_user = get_userdata( $user_id );
			if ( 'editorial_post_status' === $topic && ( ! $audience_user || ! in_array( 'author', (array) $audience_user->roles, true ) ) ) continue;
			if ( 'admin_new_order' === $topic && ! user_can( $user_id, 'edit_shop_orders' ) ) continue;
			if ( 'admin_new_comment' === $topic && ! user_can( $user_id, 'moderate_comments' ) && ! user_can( $user_id, 'manage_options' ) ) continue;
			if ( in_array( $topic, [ 'admin_visitor_summary', 'admin_live_visitor' ], true ) && ! user_can( $user_id, 'manage_options' ) && ! user_can( $user_id, 'kiwe_manage_notification_policy' ) ) continue;
			if ( ! $this->topic_allowed_for_user( $user_id, $topic ) ) continue;
			$stored = get_user_meta( $user_id, self::USER_META, true );
			if ( '' !== $channel && ( ! is_array( $stored ) || ! in_array( $topic, (array) ( $stored['topics'] ?? [] ), true ) ) ) continue;
			$delivery = is_array( $stored ) ? $stored : [ 'channels'=>$this->decode_list( $row['channels'] ?? '' ) ];
			if ( '' !== $channel && ! in_array( $channel, $this->delivery_channels( $delivery, $topic ), true ) ) continue;
			if ( ! in_array( $topic, $this->decode_list( $row['topics'] ?? '' ), true ) ) continue;
			if ( 'app' === $channel && 'granted' !== sanitize_key( (string) ( $row['browser_permission'] ?? '' ) ) ) continue;
			$ids[] = $user_id;
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public function personal_topic( string $topic ): bool {
		return in_array( $topic, [ 'account_update','guest_post_status','guest_product_status','editorial_post_status','order_status','cart_reminder' ], true );
	}

	/** Inbox entitlement is distinct from consent to send to a gateway. */
	public function default_inbox_topic( string $topic ): bool {
		return true; // Inbox availability is core; the role and event own relevance.
	}
	public function topic_allowed_for_user( int $id, string $topic ): bool {
		$user = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $user || ! user_can( $user, 'read' ) || \DSA\Access\Team_Workspace_Service::suspended( $id ) || ( is_multisite() && ! is_user_member_of_blog( $id ) ) ) return false;
		switch ( $topic ) {
			case 'admin_new_account': return user_can( $user, 'kiwe_manage_team' ) && user_can( $user, 'list_users' ) && user_can( $user, 'edit_users' );
			case 'admin_new_order': return $this->woo_available() && user_can( $user, 'edit_shop_orders' );
			case 'admin_abandoned_cart': return $this->woo_available() && user_can( $user, 'edit_shop_orders' ) && user_can( $user, 'edit_others_shop_orders' );
			case 'admin_new_comment': return user_can( $user, 'moderate_comments' );
			case 'admin_guest_application': return user_can( $user, 'kiwe_manage_guest_applications' );
			case 'admin_guest_submission': return user_can( $user, 'edit_others_posts' );
			case 'admin_guest_product_submission': return $this->woo_available() && user_can( $user, 'edit_products' ) && user_can( $user, 'edit_others_products' );
			case 'securetrack_incident': case 'admin_live_visitor': case 'admin_visitor_summary': return user_can( $user, 'kiwe_manage_notification_policy' );
			case 'guest_product_status': if ( ! $this->woo_available() ) return false;
			case 'guest_post_status': return [] !== \DSA\Access\Guest_Contribution_Service::application( $id ) || in_array( 'contributor', (array) $user->roles, true );
			case 'editorial_post_status': return in_array( 'author', (array) $user->roles, true );
			case 'order_status': case 'new_offer': case 'price_change': case 'stock_update': case 'new_product': case 'cart_reminder': return $this->woo_available();
			case 'account_update': case 'newsletter': case 'new_post': return true;
		}
		// Additional server-owned topics must declare their own explicit entitlement.
		return true === apply_filters( 'dsa_notification_topic_allowed_for_user', false, $topic, $id, $user );
	}
	public function inbox_accepts( int $id, string $topic ): bool {
		return $this->topic_allowed_for_user( $id, $topic );
	}
	public function inbox_user_ids_for_topic( string $topic, array $explicit = [] ): array {
		if ( $this->personal_topic( $topic ) && ! $explicit ) return [];
		if ( $explicit ) $candidates = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $explicit ) ) ) ), 0, 100 );
		elseif ( str_starts_with( $topic, 'admin_' ) || 'securetrack_incident' === $topic ) {
			$candidates = get_users( [ 'role__in'=>[ 'administrator','kiwe_super_admin','shop_manager','editor' ], 'number'=>100,'fields'=>'ID','orderby'=>'ID','order'=>'ASC' ] );
			$owner = \DSA\Access\Site_Owner_Service::owner_id(); if ( $owner ) array_unshift( $candidates, $owner );
		} else $candidates = get_users( [ 'capability'=>'read', 'number'=>100, 'fields'=>'ID', 'orderby'=>'ID', 'order'=>'ASC' ] );
		return array_values( array_filter( array_unique( array_map( 'absint', $candidates ) ), fn( $id ) => $this->inbox_accepts( $id, $topic ) ) );
	}

	public function contact_for_user( int $user_id, string $channel ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) return '';
		if ( 'email' === $channel ) return sanitize_email( $user->user_email );
		$phone = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_phone', true ) );
		if ( '' === $phone && function_exists( 'pk_factor' ) && function_exists( 'pk_decrypt' ) ) {
			$factor = pk_factor( $user_id, 'phone' );
			$phone = is_array( $factor ) && 'verified' === ( $factor['status'] ?? '' ) ? sanitize_text_field( (string) pk_decrypt( $factor['factor_value'] ?? '' ) ) : '';
		}
		return function_exists( 'pk_normalize_phone' ) ? (string) pk_normalize_phone( $phone ) : preg_replace( '/[^0-9+]/', '', $phone );
	}

	public function user_accepts( int $user_id, string $channel, string $topic = '' ): bool {
		if ( $user_id < 1 || ( '' !== $topic && ! $this->topic_allowed_for_user( $user_id, sanitize_key( $topic ) ) ) ) {
			return false;
		}

		$stored = get_user_meta( $user_id, self::USER_META, true );
		// This may run while another actor publishes. Do not sanitize against the sender's role.
		$preferences = is_array( $stored ) ? $stored : [];
		if ( ! in_array( sanitize_key( $channel ), $this->delivery_channels( $preferences, $topic ), true ) ) {
			return false;
		}

		return '' === $topic || in_array( sanitize_key( $topic ), (array) ( $preferences['topics'] ?? [] ), true );
	}

	/** Anonymous campaign identities remain private exact-device PWA identities, never user accounts. */
	public function campaign_visitors( string $topic ): array {
		if ( in_array( $topic, self::STORE_TOPICS, true ) && !$this->woo_available() ) return ['hashes'=>[],'limited'=>false];
		global $wpdb;
		if ( ! $this->enabled() || ! in_array($topic,['newsletter','new_offer','new_post'],true) ) return ['hashes'=>[],'limited'=>false];
		$this->maybe_install();
		$rows=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT visitor_hash FROM {$this->table()} WHERE user_id=0 AND browser_permission='granted' AND topics LIKE %s ORDER BY visitor_hash ASC LIMIT 501",'%'.$wpdb->esc_like('"'.$topic.'"').'%'));
		$hashes=[];foreach(array_slice((array)$rows,0,500)as$hash)if($this->campaign_visitor_accepts((string)$hash,$topic))$hashes[]=(string)$hash;
		return ['hashes'=>$hashes,'limited'=>count((array)$rows)>500];
	}
	public function campaign_visitor_accepts( string $hash, string $topic, array $categories=[] ): bool {
		if ( in_array( $topic, self::STORE_TOPICS, true ) && !$this->woo_available() ) return false;
		if(!preg_match('/^[a-f0-9]{64}$/',$hash))return false;
		$p=get_transient(self::TRANSIENT_PREFIX.substr($hash,0,32));
		if(!is_array($p)||time()-absint($p['updatedAt']??0)>30*DAY_IN_SECONDS||'granted'!==($p['browserPermission']??''))return false;
		if(!in_array($topic,(array)($p['topics']??[]),true)||!in_array('app',$this->delivery_channels($p,$topic),true))return false;
		return 'new_post'!==$topic || empty($p['postCategories']) || (bool)array_intersect($categories,array_map('absint',(array)$p['postCategories']));
	}
	/** Decline only the signed topic/channel. Other subscriptions and account state are preserved. */
	public function decline_campaign( int $id, string $hash, string $topic, string $channel ): bool {
		$key=self::TRANSIENT_PREFIX.substr($hash,0,32);
		$p=$id?get_user_meta($id,self::USER_META,true):get_transient($key);
		if(!is_array($p))return true;
		$channels=$this->delivery_channels($p,$topic);$p['topicChannels'][$topic]=array_values(array_diff($channels,[$channel]));$p['updatedAt']=time();
		if($id){update_user_meta($id,self::USER_META,$p);return get_user_meta($id,self::USER_META,true)===$p;}
		set_transient($key,$p,30*DAY_IN_SECONDS);return get_transient($key)===$p;
	}

	private function delivery_channels( array $preferences, string $topic ): array {
		$overrides = is_array( $preferences['topicChannels'] ?? null ) ? $preferences['topicChannels'] : [];
		$selected = '' !== $topic && array_key_exists( $topic, $overrides ) ? $overrides[ $topic ] : ( $preferences['channels'] ?? [] );
		return is_array( $selected ) ? array_values( array_intersect( [ 'app','email','whatsapp','sms' ], $selected ) ) : [];
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

	private const STORE_TOPICS = [ 'new_offer','price_change','stock_update','new_product','order_status','cart_reminder','admin_new_order','admin_abandoned_cart','admin_guest_product_submission','guest_product_status' ];
	/** Keep existing dormant consent; off clients cannot create new Store subscriptions. */
	private function retain_store_choices( array $preferences, array $prior ): array {
		if ( $this->woo_available() ) return $preferences;
		$dormant = array_values( array_intersect( self::STORE_TOPICS, array_filter( (array) ( $prior['topics'] ?? [] ), 'is_string' ) ) );
		$preferences['topics'] = array_values( array_unique( array_merge( $preferences['topics'], $dormant ) ) );
		foreach ( self::STORE_TOPICS as $topic ) if ( isset( $prior['topicChannels'][$topic] ) && is_array( $prior['topicChannels'][$topic] ) ) $preferences['topicChannels'][$topic] = array_values( array_intersect( [ 'app','email','sms','whatsapp' ], array_filter( $prior['topicChannels'][$topic], 'is_string' ) ) );
		foreach ( [ 'productCategories','productIds' ] as $key ) $preferences[$key] = $this->sanitize_ids( $prior[$key] ?? [] );
		return $preferences;
	}

	private function sanitize_preferences( array $input ): array {
		$allowed_topics = array_column( $this->topics(), 'id' );
		$allowed_channels = array_column( array_filter( $this->channels(), static function ( array $channel ): bool { return ! empty( $channel['available'] ); } ), 'id' );
		$topics = isset( $input['topics'] ) && is_array( $input['topics'] ) ? $input['topics'] : [];
		$channels = isset( $input['channels'] ) && is_array( $input['channels'] ) ? $input['channels'] : [];
		$overrides = [];
		foreach ( array_slice( is_array( $input['topicChannels'] ?? null ) ? $input['topicChannels'] : [], 0, 100, true ) as $topic=>$selected ) {
			if ( in_array( $topic, $allowed_topics, true ) && is_array( $selected ) ) $overrides[ $topic ] = array_values( array_intersect( $allowed_channels, array_filter( $selected, 'is_string' ) ) );
		}

		// Compatibility projection only. Old clients/backups cannot disable the core inbox.
		$inbox = $allowed_topics;
		return [
			'inboxTopics'       => array_values( array_intersect( $allowed_topics, array_filter( $inbox, 'is_string' ) ) ),
			'topicChannels'     => $overrides,
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

	private function upsert_audience_record( string $visitor_id, array $preferences, array $payload ): bool {
		global $wpdb;
		$this->maybe_install();
		$user_id = get_current_user_id();
		$hash = $this->visitor_hash( $visitor_id, $user_id );
		if ( '' === $hash ) return false;
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,is_app FROM {$this->table()} WHERE visitor_hash = %s", $hash ), ARRAY_A );
		$now = current_time( 'mysql' );
		$data = [
			'visitor_hash' => $hash,
			'user_id' => $user_id,
			'phonekey_verified' => $user_id && function_exists( 'pk_account_verified' ) && pk_account_verified( $user_id ) ? 1 : 0,
			'is_app' => ! empty( $payload['standalone'] ) || ! empty( $existing['is_app'] ) ? 1 : 0,
			'browser_permission' => sanitize_key( (string) ( $preferences['browserPermission'] ?? 'default' ) ),
			'topics' => wp_json_encode( array_values( array_unique( array_merge( $preferences['topics'], $preferences['inboxTopics'] ) ) ) ),
			'channels' => wp_json_encode( $preferences['channels'] ),
			'product_categories' => wp_json_encode( $preferences['productCategories'] ),
			'post_categories' => wp_json_encode( $preferences['postCategories'] ),
			'product_ids' => wp_json_encode( $preferences['productIds'] ),
			'context' => sanitize_key( (string) ( $preferences['context'] ?? '' ) ),
			'updated_at' => $now,
		];
		if ( $existing ) {
			return false !== $wpdb->update( $this->table(), $data, [ 'id' => absint( $existing['id'] ) ] );
		} else {
			$data['created_at'] = $now;
			return false !== $wpdb->insert( $this->table(), $data );
		}
	}

	/** Functional app lifecycle, separate from optional page-view analytics and delivery consent. */
	public function record_app_activity( array $payload ): array {
		if ( ! $this->settings->get('enabled',true) ) return [ 'ok'=>false,'status'=>403,'message'=>'Kiwe is unavailable.' ];
		if ( array_diff(array_keys($payload),['event','visitorId','platform']) || !is_string($payload['visitorId']??null) || !preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$payload['visitorId']) || !in_array($payload['event']??'',['installed','opened'],true) || !in_array($payload['platform']??'',['ios','android','desktop','unknown'],true) ) return [ 'ok'=>false,'status'=>400,'message'=>'A valid app lifecycle event is required.' ];
		// Never install or inspect schema on a visitor lifecycle request.
		if ( self::DB_VERSION !== get_option(self::DB_VERSION_OPTION) ) return [ 'ok'=>false,'status'=>503,'message'=>'App observations are waiting for the site owner to finish setup.' ];
		global $wpdb;
		$visitor=$payload['visitorId'];$user=get_current_user_id();$hash=$this->visitor_hash($visitor,$user);$opened='opened'===$payload['event'];$now=gmdate('Y-m-d H:i:s');
		$row=$wpdb->get_row($wpdb->prepare("SELECT id,user_id,app_installed_at,app_first_opened_at,app_last_opened_at FROM {$this->table()} WHERE visitor_hash=%s",$hash),ARRAY_A);
		// Repeated foreground events do not rewrite preferences or the general activity clock.
		$recent=$row&&$opened&&!empty($row['app_last_opened_at'])&&strtotime($row['app_last_opened_at'].' UTC')>time()-300;
		if($row&&(int)$row['user_id']===$user&&($recent||(!$opened&&!empty($row['app_installed_at'])))){
			if($opened&&$user)\DSA\PWA\App_Activity_Service::record_account_use($user,$now);
			return ['ok'=>true,'recorded'=>true];
		}
		// Snapshot reads do not synchronize twice; the existing owner performs one index write.
		$stored=$user?get_user_meta($user,self::USER_META,true):get_transient($this->transient_key($visitor));
		$preferences=$this->retain_store_choices($this->sanitize_preferences(is_array($stored)?$stored:[]),is_array($stored)?$stored:[]);
		if(!$this->upsert_audience_record($visitor,$preferences,['standalone'=>true]))return ['ok'=>false,'status'=>503,'message'=>'App activity could not be recorded.'];
		$row=$wpdb->get_row($wpdb->prepare("SELECT id,app_installed_at,app_first_opened_at,app_last_opened_at FROM {$this->table()} WHERE visitor_hash=%s",$hash),ARRAY_A);
		if(!$row)return ['ok'=>false,'status'=>503,'message'=>'App activity could not be recorded.'];
		$set=$opened?'app_first_opened_at=%s,app_last_opened_at=%s':'app_installed_at=%s';
		$args=$opened?[$now,$now,$payload['platform'],(int)$row['id']]:[$now,$payload['platform'],(int)$row['id']];
		$first=$wpdb->query($wpdb->prepare("UPDATE {$this->table()} SET {$set},app_platform=%s,is_app=1 WHERE id=%d AND app_installed_at IS NULL AND app_first_opened_at IS NULL",...$args));
		if(false===$first)return ['ok'=>false,'status'=>503,'message'=>'App activity could not be recorded.'];
		if(!$first){
			if($opened){$cutoff=gmdate('Y-m-d H:i:s',time()-300);$write=$wpdb->query($wpdb->prepare("UPDATE {$this->table()} SET app_first_opened_at=COALESCE(app_first_opened_at,%s),app_last_opened_at=%s,app_platform=%s,is_app=1 WHERE id=%d AND (app_last_opened_at IS NULL OR app_last_opened_at<=%s)",$now,$now,$payload['platform'],(int)$row['id'],$cutoff));}
			else $write=$wpdb->query($wpdb->prepare("UPDATE {$this->table()} SET app_installed_at=%s,app_platform=%s,is_app=1 WHERE id=%d AND app_installed_at IS NULL",$now,$payload['platform'],(int)$row['id']));
			if(false===$write)return ['ok'=>false,'status'=>503,'message'=>'App activity could not be recorded.'];
		}
		if($opened&&get_current_user_id())\DSA\PWA\App_Activity_Service::record_account_use(get_current_user_id(),$now);
		if($first)do_action('kiwe_app_first_observed',(int)$row['id']);
		return ['ok'=>true,'recorded'=>true];
	}

	public static function audience_table(): string { global $wpdb; return $wpdb->prefix.'dsa_notification_preferences'; }

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
		return self::audience_table();
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

	private function channels( bool $personal = true ): array {
		$email = $this->settings->get( 'email', [] );
		$permissions = $this->settings->get( 'permissions', [] );
		$recovery = $this->settings->get( 'abandoned_cart', [] );
		$provider_channels = is_array( $recovery ) && isset( $recovery['channels'] ) && is_array( $recovery['channels'] ) ? $recovery['channels'] : [];

		$channels = [
			[ 'id' => 'app', 'label' => __( 'Browser / PWA', 'dsa' ), 'description' => __( 'Optional push on devices where you explicitly grant permission.', 'dsa' ), 'available' => true ],
			[ 'id' => 'email', 'label' => __( 'Email', 'dsa' ), 'description' => ! empty( $email['enabled'] ) ? __( 'Useful updates in your inbox.', 'dsa' ) : __( 'Email delivery has not been configured by the site owner.', 'dsa' ), 'available' => ! empty( $email['enabled'] ) ],
			[ 'id' => 'whatsapp', 'label' => __( 'WhatsApp', 'dsa' ), 'description' => __( 'Consent-aware updates through Kiwe Key.kiwe.', 'dsa' ), 'available' => ( function_exists( 'pk_whatsapp_notification_ready' ) && pk_whatsapp_notification_ready() ) || $this->channel_ready( $provider_channels['whatsapp'] ?? [] ) ],
			[ 'id' => 'sms', 'label' => __( 'SMS', 'dsa' ), 'description' => __( 'Text messages through the configured SMS provider.', 'dsa' ), 'available' => $this->channel_ready( $provider_channels['sms'] ?? [] ) ],
		];
		return (array) apply_filters( 'dsa_notification_channel_catalog', $channels, $personal ? get_current_user_id() : 0 );
	}

	private function channel_ready( $channel ): bool {
		return is_array( $channel ) && ! empty( $channel['enabled'] ) && ! empty( $channel['webhook_url'] );
	}

	private function topics( bool $personal = true ): array {
		$admin_topics = [];
		$user_id = $personal ? get_current_user_id() : 0;
		$user = $user_id ? get_userdata( $user_id ) : false;
		$roles = $user ? (array) $user->roles : [];
		if ( $user_id && current_user_can( 'read' ) ) $admin_topics[] = [ 'id'=>'account_update','label'=>__( 'Your account', 'dsa' ),'description'=>__( 'Registration, verification, role and password changes for your account.', 'dsa' ) ];
		if ( $user_id && current_user_can( 'kiwe_manage_guest_applications' ) ) {
			$admin_topics[] = [ 'id' => 'admin_guest_application', 'label' => __( 'Guest applications', 'dsa' ), 'description' => __( 'A verified person applied to contribute.', 'dsa' ), 'audience' => 'admin' ];
		}
		if ( $user_id && current_user_can( 'edit_others_posts' ) ) {
			$admin_topics[] = [ 'id' => 'admin_guest_submission', 'label' => __( 'Guest submissions', 'dsa' ), 'description' => __( 'A Guest submitted an article for editorial review.', 'dsa' ), 'audience' => 'admin' ];
		}
		if ( $user_id && $this->woo_available() && current_user_can( 'edit_products' ) && current_user_can( 'edit_others_products' ) ) {
			$admin_topics[] = [ 'id' => 'admin_guest_product_submission', 'label' => __( 'Guest product proposals', 'dsa' ), 'description' => __( 'A Guest submitted a product for store review.', 'dsa' ), 'audience' => 'admin' ];
		}
		if ( $user_id && ( in_array( 'contributor', $roles, true ) || [] !== \DSA\Access\Guest_Contribution_Service::application( $user_id ) ) ) {
			$admin_topics[] = [ 'id' => 'guest_post_status', 'label' => __( 'Guest contribution decisions', 'dsa' ), 'description' => __( 'Approval, denial and publishing updates for your Guest work.', 'dsa' ) ];
			if ( $this->woo_available() ) $admin_topics[] = [ 'id' => 'guest_product_status', 'label' => __( 'Your product proposals', 'dsa' ), 'description' => __( 'Publishing and review-status updates for your product proposals.', 'dsa' ) ];
		}
		if ( $user_id && in_array( 'author', $roles, true ) ) {
			$admin_topics[] = [ 'id' => 'editorial_post_status', 'label' => __( 'Article status', 'dsa' ), 'description' => __( 'Editorial status changes to your articles.', 'dsa' ) ];
		}
		if ( $user_id && $this->topic_allowed_for_user( $user_id, 'admin_new_account' ) ) $admin_topics[] = [ 'id'=>'admin_new_account', 'label'=>__( 'New users', 'dsa' ), 'description'=>__( 'New accounts arrive in Daily → Users. Verification and account access stay private.', 'dsa' ), 'audience'=>'admin' ];
		if ( $this->woo_available() && $user_id && current_user_can( 'edit_shop_orders' ) ) {
			$admin_topics[] = [ 'id' => 'admin_new_order', 'label' => __( 'New store orders', 'dsa' ), 'description' => __( 'Private owner alerts when a WooCommerce order arrives.', 'dsa' ), 'audience' => 'admin' ];
			if ( current_user_can( 'edit_others_shop_orders' ) ) $admin_topics[] = [ 'id'=>'admin_abandoned_cart', 'label'=>__( 'New abandoned carts', 'dsa' ), 'description'=>__( 'A shopper left a cart waiting. Review it in Daily saved carts.', 'dsa' ), 'audience'=>'admin' ];
		}
		if ( $user_id && ( current_user_can( 'moderate_comments' ) || current_user_can( 'manage_options' ) ) ) {
			$admin_topics[] = [ 'id' => 'admin_new_comment', 'label' => __( 'New comments', 'dsa' ), 'description' => __( 'Private owner alerts when a comment needs attention.', 'dsa' ), 'audience' => 'admin' ];
		}
		if ( $user_id && ( current_user_can( 'manage_options' ) || current_user_can( 'kiwe_manage_notification_policy' ) ) ) {
			$admin_topics[] = [ 'id' => 'securetrack_incident', 'label' => __( 'SecureTrack incidents', 'dsa' ), 'description' => __( 'Security incidents that meet the site-wide severity and flood-control policy.', 'dsa' ), 'audience' => 'admin' ];
			$admin_topics[] = [ 'id' => 'admin_live_visitor', 'label' => __( 'Live visitors', 'dsa' ), 'description' => __( 'Private owner alerts when a new or returning visitor is recorded while you have Kiwe open.', 'dsa' ), 'audience' => 'admin' ];
			$admin_topics[] = [ 'id' => 'admin_visitor_summary', 'label' => __( 'Visitor summary', 'dsa' ), 'description' => __( 'Private owner insight showing today visitors versus yesterday.', 'dsa' ), 'audience' => 'admin' ];
		}

		$publishing_topics = [
			[ 'id' => 'newsletter', 'label' => __( 'Newsletter', 'dsa' ), 'description' => __( 'Occasional highlights selected by the publisher.', 'dsa' ) ],
			[ 'id' => 'new_post', 'label' => __( 'New posts', 'dsa' ), 'description' => __( 'Fresh stories from categories you follow.', 'dsa' ) ],
		];
		$commerce_topics = $this->woo_available() ? [
			[ 'id' => 'new_offer', 'label' => __( 'Offers and coupons', 'dsa' ), 'description' => __( 'New savings worth knowing about.', 'dsa' ) ],
			[ 'id' => 'price_change', 'label' => __( 'Sale and price changes', 'dsa' ), 'description' => __( 'Price movement for products you care about.', 'dsa' ) ],
			[ 'id' => 'stock_update', 'label' => __( 'Stock updates', 'dsa' ), 'description' => __( 'Know when unavailable products return.', 'dsa' ) ],
			[ 'id' => 'new_product', 'label' => __( 'New products', 'dsa' ), 'description' => __( 'New arrivals from selected categories.', 'dsa' ) ],
			[ 'id' => 'order_status', 'label' => __( 'Order status', 'dsa' ), 'description' => __( 'Progress after checkout and fulfilment updates.', 'dsa' ) ],
			[ 'id' => 'cart_reminder', 'label' => __( 'Saved cart reminders', 'dsa' ), 'description' => __( 'A limited reminder when a cart you started is still waiting.', 'dsa' ) ],
		] : [];
		$topics = (array) apply_filters( 'dsa_notification_topic_catalog', array_merge( $publishing_topics, $commerce_topics, $admin_topics ), $user_id );
		// The same entitlement protects display and saved choices, including filtered catalogs.
		$public_topics = array_column( array_merge( $publishing_topics, $commerce_topics ), 'id' );
		return array_values( array_filter( $topics, function ( $topic ) use ( $user_id, $public_topics ): bool {
			if ( ! is_array( $topic ) || ! is_string( $topic['id'] ?? null ) || $topic['id'] !== sanitize_key( $topic['id'] ) ) return false;
			if ( $user_id ) return $this->topic_allowed_for_user( $user_id, $topic['id'] );
			if ( in_array( $topic['id'], $public_topics, true ) ) return true;
			return true === apply_filters( 'dsa_notification_topic_allowed_for_user', false, $topic['id'], 0, false );
		} ) );
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
		return [ 'enabled' => true ];
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
		return \DSA\Apps\Store_App_Service::enabled();
	}
}
