<?php

namespace DSA\Notifications;

use DSA\Commerce\Store_Analytics_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Event_Notification_Service {
	private const INBOX_META = 'dsa_admin_notification_inbox';
	private $preferences;
	private $push;
	private $analytics;

	public function __construct( Notification_Preference_Service $preferences, Push_Service $push, ?Store_Analytics_Service $analytics = null ) {
		$this->preferences = $preferences;
		$this->push        = $push;
		$this->analytics   = $analytics;
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'queue_visitor_summary' ], 45 );
		add_action( 'dsa_analytics_visit_recorded', [ $this, 'queue_live_visitor' ], 10, 1 );
		add_action( 'dsa_analytics_activity_recorded', [ $this, 'queue_visitor_activity' ], 10, 1 );
		add_action( 'woocommerce_new_order', [ $this, 'queue_order' ], 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'queue_order' ], 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'queue_order' ], 20, 1 );
		add_action( 'comment_post', [ $this, 'queue_comment' ], 20, 3 );
		add_action( 'dsa_admin_notification_event', [ $this, 'dispatch' ], 10, 2 );
	}

	public function queue_order( $order_id ): void {
		$order_id = absint( $order_id );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( $order && in_array( $order->get_status(), [ 'checkout-draft', 'auto-draft', 'draft' ], true ) ) return;
		$this->queue( 'new_order', $order_id );
	}

	public function queue_comment( $comment_id, $approved, $comment_data ): void {
		$comment_type = sanitize_key( (string) ( is_array( $comment_data ) ? ( $comment_data['comment_type'] ?? 'comment' ) : 'comment' ) );
		if ( ! in_array( $comment_type, [ '', 'comment', 'review' ], true ) ) return;
		$this->queue( 'new_comment', absint( $comment_id ) );
	}

	public function queue_visitor_summary(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! $this->analytics ) {
			return;
		}

		$key = 'dsa_admin_visitor_summary_' . gmdate( 'YmdH' );
		if ( get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, HOUR_IN_SECONDS );
		$this->dispatch_visitor_summary();
	}

	public function queue_live_visitor( array $event ): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$visitor_hash = sanitize_text_field( (string) ( $event['visitor_hash'] ?? '' ) );
		if ( '' === $visitor_hash ) {
			return;
		}

		$user_id = absint( $event['user_id'] ?? 0 );
		if ( $this->is_staff_user( $user_id ) ) {
			return;
		}

		$context = sanitize_key( (string) ( $event['context'] ?? 'content' ) );
		$post_id = absint( $event['post_id'] ?? 0 );
		$post_title = sanitize_text_field( (string) ( $event['post_title'] ?? '' ) );
		$key = 'dsa_admin_live_state_' . md5( $visitor_hash );
		$state = get_transient( $key );
		$state = is_array( $state ) ? $state : [];
		$signature = $context . '|' . $post_id;
		$now = time();
		$last_seen = absint( $state['last_seen'] ?? 0 );
		$last_signature = (string) ( $state['signature'] ?? '' );

		if ( $signature === $last_signature && $last_seen && ( $now - $last_seen ) < 30 * MINUTE_IN_SECONDS ) {
			return;
		}

		$is_revisit = $last_seen && ( $now - $last_seen ) >= 30 * MINUTE_IN_SECONDS;
		set_transient( $key, [ 'last_seen' => $now, 'signature' => $signature ], 12 * HOUR_IN_SECONDS );

		$user = $user_id ? get_userdata( $user_id ) : false;
		$known = $user && $user->exists();
		$title = $is_revisit ? __( 'Visitor returned.', 'dsa' ) : ( $last_seen ? __( 'Visitor moved through the site.', 'dsa' ) : ( $known ? __( 'Identified visitor arrived.', 'dsa' ) : __( 'New visitor on site.', 'dsa' ) ) );
		$name = $known ? sanitize_text_field( $user->display_name ?: $user->user_login ) : '';
		$location = $this->visitor_context_label( $context, $post_title );
		$message = $known && '' !== $name
			? sprintf( __( '%1$s is %2$s.', 'dsa' ), $name, $location )
			: sprintf( __( 'A visitor is %s.', 'dsa' ), $location );

		$item = [
			'id'          => 'visitor-live-' . substr( md5( $visitor_hash . '|' . time() ), 0, 12 ),
			'type'        => 'admin_live_visitor',
			'kicker'      => __( 'Live visitor', 'dsa' ),
			'title'       => $title,
			'message'     => $message,
			'actionLabel' => __( 'View', 'dsa' ),
			'actionUrl'   => esc_url_raw( admin_url( 'admin.php?page=kiwe-analytics&tab=funnel&days=1' ) ),
			'createdAt'   => time(),
		];

		$this->store_inbox( $this->admin_user_ids( 'manage_options' ), $item );
	}

	public function queue_visitor_activity( array $event ): void {
		$type = sanitize_key( (string) ( $event['event_type'] ?? '' ) );
		$allowed = [ 'cart_add', 'wishlist_add', 'bookmark_add', 'user_login', 'user_register' ];

		if ( ! in_array( $type, $allowed, true ) ) {
			return;
		}

		$user_id = absint( $event['user_id'] ?? 0 );
		if ( $this->is_staff_user( $user_id ) ) {
			return;
		}

		$visitor_hash = sanitize_text_field( (string) ( $event['visitor_hash'] ?? '' ) );
		$product_id = absint( $event['variation_id'] ?? 0 ) ?: absint( $event['product_id'] ?? 0 );
		$key = 'dsa_admin_activity_' . md5( $visitor_hash . '|' . $type . '|' . $product_id );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 30 * MINUTE_IN_SECONDS );

		$user = $user_id ? get_userdata( $user_id ) : false;
		$name = $user && $user->exists() ? sanitize_text_field( $user->display_name ?: $user->user_login ) : '';
		$product = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$product_title = $product && is_object( $product ) ? wp_strip_all_tags( $product->get_name() ) : '';
		if ( '' === $product_title ) {
			$product_title = sanitize_text_field( (string) ( $event['object_title'] ?? '' ) );
		}
		$actor = '' !== $name ? $name : __( 'A visitor', 'dsa' );

		if ( 'cart_add' === $type ) {
			$title = __( 'Product added to cart.', 'dsa' );
			$message = $product_title ? sprintf( __( '%1$s added %2$s to the cart.', 'dsa' ), $actor, $product_title ) : sprintf( __( '%s added a product to the cart.', 'dsa' ), $actor );
		} elseif ( 'wishlist_add' === $type || 'bookmark_add' === $type ) {
			$title = 'wishlist_add' === $type ? __( 'Product wishlisted.', 'dsa' ) : __( 'Content bookmarked.', 'dsa' );
			$message = $product_title ? sprintf( __( '%1$s saved %2$s.', 'dsa' ), $actor, $product_title ) : sprintf( __( '%s saved an item.', 'dsa' ), $actor );
		} else {
			$title = __( 'Visitor identified.', 'dsa' );
			$message = '' !== $name ? sprintf( __( '%s is now identified.', 'dsa' ), $name ) : __( 'A visitor converted into an identified user.', 'dsa' );
		}

		$this->store_inbox(
			$this->admin_user_ids( 'manage_options' ),
			[
				'id'          => 'visitor-activity-' . substr( md5( $visitor_hash . '|' . $type . '|' . $product_id . '|' . time() ), 0, 12 ),
				'type'        => 'admin_live_visitor',
				'kicker'      => __( 'Live visitor', 'dsa' ),
				'title'       => $title,
				'message'     => $message,
				'actionLabel' => __( 'View', 'dsa' ),
				'actionUrl'   => esc_url_raw( admin_url( 'admin.php?page=kiwe-analytics&tab=funnel&days=1' ) ),
				'createdAt'   => time(),
			]
		);
	}

	public function dispatch( string $event, int $object_id ): void {
		if ( 'new_order' === $event ) {
			$this->dispatch_order( $object_id );
			return;
		}
		if ( 'new_comment' === $event ) {
			$this->dispatch_comment( $object_id );
		}
	}

	public function pull_current_user(): array {
		$user_id = get_current_user_id();
		if ( ! $user_id || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'moderate_comments' ) ) ) return [];
		$items = get_user_meta( $user_id, self::INBOX_META, true );
		$items = is_array( $items ) ? $items : [];
		$pending = [];
		$now = time();
		foreach ( $items as &$item ) {
			if ( ! is_array( $item ) || ! empty( $item['acknowledgedAt'] ) ) continue;
			$item['deliveredAt'] = $now;
			$pending[] = $item;
		}
		unset( $item );
		update_user_meta( $user_id, self::INBOX_META, array_slice( $items, 0, 40 ) );
		return array_slice( $pending, 0, 20 );
	}

	public function acknowledge_current_user( string $event_id ): bool {
		$user_id = get_current_user_id();
		$event_id = sanitize_text_field( $event_id );
		if ( ! $user_id || '' === $event_id ) return false;
		$items = get_user_meta( $user_id, self::INBOX_META, true );
		$items = is_array( $items ) ? $items : [];
		$changed = false;
		foreach ( $items as &$item ) {
			if ( ! is_array( $item ) || (string) ( $item['id'] ?? '' ) !== $event_id ) continue;
			$item['acknowledgedAt'] = time();
			$changed = true;
		}
		unset( $item );
		if ( $changed ) update_user_meta( $user_id, self::INBOX_META, array_slice( $items, 0, 40 ) );
		return $changed;
	}

	private function queue( string $event, int $object_id ): void {
		if ( ! $object_id ) return;
		$key = 'dsa_admin_alert_' . sanitize_key( $event ) . '_' . $object_id;
		if ( get_transient( $key ) ) return;
		set_transient( $key, 1, HOUR_IN_SECONDS );
		$scheduled = wp_schedule_single_event( time() + 3, 'dsa_admin_notification_event', [ $event, $object_id ] );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			$this->dispatch( $event, $object_id );
		}
	}

	private function dispatch_order( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) return;
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;
		if ( in_array( $order->get_status(), [ 'checkout-draft', 'auto-draft', 'draft' ], true ) ) return;
		$inbox_user_ids = $this->preferences->audience_user_ids_for_topic( '', 'admin_new_order' );
		$push_user_ids = $this->preferences->audience_user_ids_for_topic( 'app', 'admin_new_order' );
		$order_number = sanitize_text_field( (string) $order->get_order_number() );
		$url = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		$event_id = 'order-' . $order_id;
		$inbox = [
			'id' => $event_id,
			'type' => 'admin_new_order',
			'kicker' => __( 'New order', 'dsa' ),
			'title' => sprintf( __( 'Order #%s needs your attention.', 'dsa' ), $order_number ),
			'message' => __( 'Open the order in WordPress to review payment, products, and fulfilment.', 'dsa' ),
			'actionUrl' => esc_url_raw( $url ),
			'createdAt' => time(),
		];
		$this->store_inbox( $inbox_user_ids, $inbox );
		$this->push->send_to_users(
			$push_user_ids,
			sprintf( __( 'New order #%s', 'dsa' ), $order_number ),
			__( 'A new order arrived. Tap to review payment and fulfilment.', 'dsa' ),
			$url,
			[
				'eventId'   => $event_id,
				'eventType' => 'admin_new_order',
				'kicker'    => __( 'New order', 'dsa' ),
				'aiTitle'   => sprintf( __( 'Order #%s needs your attention.', 'dsa' ), $order_number ),
				'aiMessage' => __( 'Open the order in WordPress to review payment, products, and fulfilment.', 'dsa' ),
			]
		);
	}

	private function dispatch_comment( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) return;
		$inbox_user_ids = $this->preferences->audience_user_ids_for_topic( '', 'admin_new_comment' );
		$push_user_ids = $this->preferences->audience_user_ids_for_topic( 'app', 'admin_new_comment' );
		$post_title = get_the_title( (int) $comment->comment_post_ID );
		$status = wp_get_comment_status( $comment_id );
		$pending = 'unapproved' === $status || 'hold' === $status;
		$title = $pending ? __( 'New comment needs approval', 'dsa' ) : __( 'New comment received', 'dsa' );
		$body = $post_title
			? sprintf( __( 'A comment arrived on "%s". Tap to review it.', 'dsa' ), wp_strip_all_tags( $post_title ) )
			: __( 'A comment arrived. Tap to review it.', 'dsa' );
		$url = admin_url( 'comment.php?action=editcomment&c=' . $comment_id );
		$event_id = 'comment-' . $comment_id;
		$inbox = [
			'id' => $event_id,
			'type' => 'admin_new_comment',
			'kicker' => $pending ? __( 'Approval needed', 'dsa' ) : __( 'New comment', 'dsa' ),
			'title' => $title,
			'message' => $body,
			'actionUrl' => esc_url_raw( $url ),
			'createdAt' => time(),
		];
		$this->store_inbox( $inbox_user_ids, $inbox );
		$this->push->send_to_users(
			$push_user_ids,
			$title,
			$body,
			$url,
			[
				'eventId'   => $event_id,
				'eventType' => 'admin_new_comment',
				'kicker'    => $pending ? __( 'Approval needed', 'dsa' ) : __( 'New comment', 'dsa' ),
				'aiTitle'   => $title,
				'aiMessage' => $body,
			]
		);
	}

	private function dispatch_visitor_summary(): void {
		if ( ! $this->analytics ) {
			return;
		}

		$timezone  = wp_timezone();
		$today_at  = new \DateTimeImmutable( 'today', $timezone );
		$yesterday_at = $today_at->modify( '-1 day' );
		$now       = time();
		$visitors  = $this->analytics->visitor_count_between( $today_at->getTimestamp(), $now );
		$yesterday = $this->analytics->visitor_count_between( $yesterday_at->getTimestamp(), $today_at->getTimestamp() );

		if ( $visitors < 1 ) {
			return;
		}

		$delta = 0;
		if ( $yesterday > 0 ) {
			$delta = round( ( ( $visitors - $yesterday ) / $yesterday ) * 100, 1 );
		}

		if ( $yesterday < 1 ) {
			$trend = __( 'No visitors were recorded yesterday.', 'dsa' );
		} else {
			$trend = $delta > 0
				? sprintf( __( '%s%% more than yesterday.', 'dsa' ), $delta )
				: ( $delta < 0 ? sprintf( __( '%s%% less than yesterday.', 'dsa' ), abs( $delta ) ) : __( 'Same as yesterday.', 'dsa' ) );
		}

		$item = [
			'id'        => 'visitor-summary-' . gmdate( 'Ymd' ),
			'type'      => 'admin_visitor_summary',
			'kicker'    => __( 'Visitor activity', 'dsa' ),
			'title'     => sprintf( _n( '%d visitor today.', '%d visitors today.', $visitors, 'dsa' ), $visitors ),
			'message'   => $trend,
			'actionLabel' => __( 'View', 'dsa' ),
			'actionUrl' => esc_url_raw( admin_url( 'admin.php?page=kiwe-analytics&tab=funnel&days=1' ) ),
			'createdAt' => time(),
		];

		$this->store_inbox( $this->admin_user_ids( 'manage_options' ), $item );
	}

	private function admin_user_ids( string $capability ): array {
		$users = get_users(
			[
				'fields' => 'ID',
				'number' => 100,
			]
		);
		$ids = [];

		foreach ( is_array( $users ) ? $users : [] as $user_id ) {
			$user_id = absint( $user_id );
			if ( $user_id && user_can( $user_id, $capability ) ) {
				$ids[] = $user_id;
			}
		}

		return $ids;
	}

	private function visitor_context_label( string $context, string $post_title = '' ): string {
		$labels = [
			'home'     => __( 'on the home page', 'dsa' ),
			'content'  => $post_title ? sprintf( __( 'reading %s', 'dsa' ), $post_title ) : __( 'browsing the site', 'dsa' ),
			'shop'     => __( 'on the shop page', 'dsa' ),
			'product'  => $post_title ? sprintf( __( 'checking %s', 'dsa' ), $post_title ) : __( 'checking a product', 'dsa' ),
			'cart'     => __( 'viewing the cart', 'dsa' ),
			'checkout' => __( 'on the checkout page', 'dsa' ),
			'account'  => __( 'in the account area', 'dsa' ),
		];

		return $labels[ $context ] ?? $labels['content'];
	}

	private function is_staff_user( int $user_id ): bool {
		return $user_id > 0 && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'edit_others_posts' ) );
	}

	private function store_inbox( array $user_ids, array $item ): void {
		foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) ), 0, 100 ) as $user_id ) {
			$items = get_user_meta( $user_id, self::INBOX_META, true );
			$items = is_array( $items ) ? $items : [];
			$items = array_values( array_filter( $items, static function ( $existing ) use ( $item ): bool {
				if ( ! is_array( $existing ) ) {
					return true;
				}

				if ( 'admin_live_visitor' === (string) ( $item['type'] ?? '' ) && 'admin_live_visitor' === (string) ( $existing['type'] ?? '' ) ) {
					return false;
				}

				return (string) ( $existing['id'] ?? '' ) !== (string) ( $item['id'] ?? '' );
			} ) );
			array_unshift( $items, $item );
			update_user_meta( $user_id, self::INBOX_META, array_slice( $items, 0, 40 ) );
		}
	}
}
