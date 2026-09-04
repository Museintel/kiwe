<?php

namespace DSA\Notifications;

use DSA\Commerce\Store_Analytics_Service;
use DSA\Communications\Channel_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Event_Notification_Service {
	private const INBOX_META = 'dsa_admin_notification_inbox';
	private $preferences;
	private $push;
	private $analytics;
	private $channels;
	private bool $router_registered = false;
	private bool $sources_registered = false;

	public function __construct( Notification_Preference_Service $preferences, Push_Service $push, ?Store_Analytics_Service $analytics = null, ?Channel_Service $channels = null ) {
		$this->preferences = $preferences;
		$this->push        = $push;
		$this->analytics   = $analytics;
		$this->channels    = $channels;
	}

	public function register(): void {
		$this->register_router();
		if ( $this->sources_registered ) return;
		$this->sources_registered = true;
		add_action( 'template_redirect', [ $this, 'queue_visitor_summary' ], 45 );
		add_action( 'dsa_analytics_visit_recorded', [ $this, 'queue_live_visitor' ], 10, 1 );
		add_action( 'dsa_analytics_activity_recorded', [ $this, 'queue_visitor_activity' ], 10, 1 );
		add_action( 'woocommerce_new_order', [ $this, 'queue_order' ], 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'queue_order' ], 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'queue_order' ], 20, 1 );
		add_action( 'comment_post', [ $this, 'queue_comment' ], 20, 3 );
		add_action( 'kiwe_guest_application_submitted', [ $this, 'queue_guest_application' ], 10, 2 );
		add_action( 'kiwe_guest_application_decided', [ $this, 'queue_guest_decision' ], 10, 3 );
		add_action( 'kiwe_guest_submission_received', [ $this, 'queue_guest_submission' ], 10, 2 );
		add_action( 'transition_post_status', [ $this, 'queue_author_status' ], 30, 3 );
		add_action( 'dsa_admin_notification_event', [ $this, 'dispatch' ], 10, 2 );
	}

	/**
	 * Register the one credential-blind notification ingress used by every Kiwe
	 * subsystem. Sources publish a topic plus presentation-safe event data;
	 * this service owns audience, preferences, inbox, push and gateway delivery.
	 */
	public function register_router(): void {
		if ( $this->router_registered ) return;
		$this->router_registered = true;
		add_action( 'kiwe_notification_event', [ $this, 'dispatch_event' ], 10, 2 );
	}

	/** Shared event contract for current and future Kiwe notification sources. */
	public function dispatch_event( string $topic, array $event ): void {
		$topic = sanitize_key( $topic );
		if ( '' === $topic || ! $this->preferences->enabled() ) return;

		$title = sanitize_text_field( (string) ( $event['title'] ?? '' ) );
		$message = sanitize_textarea_field( (string) ( $event['message'] ?? '' ) );
		if ( '' === $title || '' === $message ) return;

		$url = esc_url_raw( (string) ( $event['url'] ?? '' ) );
		$event_id = sanitize_text_field( (string) ( $event['id'] ?? '' ) );
		if ( '' === $event_id ) $event_id = $topic . '-' . substr( hash( 'sha256', $title . '|' . $message . '|' . microtime( true ) ), 0, 20 );
		$eligible = $this->preferences->audience_user_ids_for_topic( '', $topic );
		$explicit = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $event['userIds'] ?? [] ) ) ) ) );
		if ( $explicit ) $eligible = array_values( array_intersect( $eligible, $explicit ) );
		$eligible = array_slice( $eligible, 0, 100 );
		if ( ! $eligible ) return;

		$kicker = sanitize_text_field( (string) ( $event['kicker'] ?? __( 'Notification', 'dsa' ) ) );
		$action_label = sanitize_text_field( (string) ( $event['actionLabel'] ?? __( 'Open', 'dsa' ) ) );
		$item = [
			'id'          => $event_id,
			'type'        => $topic,
			'kicker'      => $kicker,
			'title'       => $title,
			'message'     => $message,
			'actionLabel' => $action_label,
			'actionUrl'   => $url,
			'createdAt'   => max( 1, absint( $event['createdAt'] ?? time() ) ),
		];
		if ( isset( $event['severity'] ) ) $item['severity'] = sanitize_key( (string) $event['severity'] );
		$this->store_inbox( $eligible, $item );

		$app_users = array_values( array_intersect( $eligible, $this->preferences->audience_user_ids_for_topic( 'app', $topic ) ) );
		if ( $app_users ) {
			$this->push->send_to_users( $app_users, $title, $message, $url, [ 'eventId' => $event_id, 'eventType' => $topic, 'kicker' => $kicker ] );
		}

		$this->deliver_channels( $topic, $title, $message, $url, $eligible );
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

	public function queue_guest_application( int $user_id, array $application ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) return;
		$title = __( 'New Guest application', 'dsa' );
		$message = sprintf( __( '%s applied to contribute. Review the verified account and requested work.', 'dsa' ), sanitize_text_field( $user->display_name ?: $user->user_login ) );
		$url = admin_url( 'admin.php?page=kiwe-guests' );
		$this->dispatch_role_event( 'admin_guest_application', 'guest-application-' . $user_id . '-' . time(), $title, $message, $url );
	}

	public function queue_guest_decision( int $user_id, string $decision, array $application ): void {
		$title = 'approved' === $decision ? __( 'Your Guest application was approved', 'dsa' ) : __( 'Your Guest application was reviewed', 'dsa' );
		$message = 'approved' === $decision
			? __( 'You can now open the protected Guest workspace and send an article for editorial review.', 'dsa' )
			: ( (string) ( $application['reason'] ?? '' ) ?: __( 'The publisher did not approve this application.', 'dsa' ) );
		$url = 'approved' === $decision ? admin_url( 'admin.php?page=kiwe-guests' ) : home_url( '/' );
		$this->dispatch_to_user( $user_id, 'guest_post_status', 'guest-decision-' . $user_id . '-' . time(), $title, $message, $url );
	}

	public function queue_guest_submission( int $post_id, int $user_id ): void {
		$type_label = 'product' === get_post_type( $post_id ) ? __( 'product proposal', 'dsa' ) : __( 'article', 'dsa' );
		$title = sprintf( __( 'Guest %s ready for review', 'dsa' ), $type_label );
		$message = sprintf( __( '“%s” was submitted through the protected Guest workspace.', 'dsa' ), wp_strip_all_tags( get_the_title( $post_id ) ) );
		$this->dispatch_role_event( 'admin_guest_submission', 'guest-post-' . $post_id, $title, $message, get_edit_post_link( $post_id, 'raw' ) ?: admin_url( 'edit.php?post_status=pending&post_type=post' ) );
	}

	public function queue_author_status( string $new_status, string $old_status, $post ): void {
		if ( $new_status === $old_status || ! $post instanceof \WP_Post || 'post' !== $post->post_type || get_current_user_id() === (int) $post->post_author ) return;
		$user = get_userdata( (int) $post->post_author );
		if ( ! $user ) return;
		$topic = in_array( 'contributor', (array) $user->roles, true ) ? 'guest_post_status' : ( in_array( 'author', (array) $user->roles, true ) ? 'editorial_post_status' : '' );
		if ( '' === $topic ) return;
		$status = get_post_status_object( $new_status );
		$title = __( 'Article status updated', 'dsa' );
		$message = sprintf( __( '“%1$s” is now %2$s.', 'dsa' ), wp_strip_all_tags( get_the_title( $post ) ), $status ? strtolower( $status->label ) : sanitize_key( $new_status ) );
		$this->dispatch_to_user( (int) $post->post_author, $topic, 'post-status-' . $post->ID . '-' . $new_status, $title, $message, 'publish' === $new_status ? get_permalink( $post ) : home_url( '/' ) );
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

		$this->dispatch_event( 'admin_live_visitor', [ 'id' => (string) $item['id'], 'kicker' => (string) $item['kicker'], 'title' => $title, 'message' => $message, 'actionLabel' => (string) $item['actionLabel'], 'url' => (string) $item['actionUrl'] ] );
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

		$this->dispatch_event( 'admin_live_visitor', [
			'id' => 'visitor-activity-' . substr( md5( $visitor_hash . '|' . $type . '|' . $product_id . '|' . time() ), 0, 12 ),
			'kicker' => __( 'Live visitor', 'dsa' ),
			'title' => $title,
			'message' => $message,
			'actionLabel' => __( 'View', 'dsa' ),
			'url' => admin_url( 'admin.php?page=kiwe-analytics&tab=funnel&days=1' ),
		] );
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
		$this->dispatch_event( 'admin_new_order', [
			'id' => $event_id,
			'kicker' => (string) $inbox['kicker'],
			'title' => (string) $inbox['title'],
			'message' => (string) $inbox['message'],
			'actionLabel' => __( 'Review', 'dsa' ),
			'url' => $url,
		] );
	}

	private function dispatch_comment( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) return;
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
		$this->dispatch_event( 'admin_new_comment', [
			'id' => $event_id,
			'kicker' => (string) $inbox['kicker'],
			'title' => $title,
			'message' => $body,
			'actionLabel' => __( 'Review', 'dsa' ),
			'url' => $url,
		] );
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

		$this->dispatch_event( 'admin_visitor_summary', [ 'id' => (string) $item['id'], 'kicker' => (string) $item['kicker'], 'title' => (string) $item['title'], 'message' => (string) $item['message'], 'actionLabel' => (string) $item['actionLabel'], 'url' => (string) $item['actionUrl'] ] );
	}

	private function deliver_channels( string $topic, string $title, string $message, string $url, array $eligible_user_ids = [] ): void {
		if ( ! $this->channels ) {
			return;
		}

		$purpose = sanitize_key( $topic );
		$delivery_message = trim( $title . "\n\n" . $message . ( $url ? "\n\n" . $url : '' ) );
		foreach ( [ 'whatsapp', 'email', 'sms' ] as $channel ) {
			if ( ! $this->channels->available_for_campaign( $channel ) ) {
				continue;
			}
			$audience = $this->preferences->audience_user_ids_for_topic( $channel, $topic );
			if ( $eligible_user_ids ) $audience = array_values( array_intersect( $audience, $eligible_user_ids ) );
			foreach ( array_slice( $audience, 0, 100 ) as $user_id ) {
				$recipient = $this->preferences->contact_for_user( (int) $user_id, $channel );
				if ( '' === $recipient ) {
					continue;
				}
				$context = [ 'purpose' => $purpose, 'user_id' => (int) $user_id ];
				if ( 'whatsapp' === $channel && $this->preferences->user_accepts( (int) $user_id, 'email', $topic ) ) {
					$context['fallback_email'] = $this->preferences->contact_for_user( (int) $user_id, 'email' );
					$context['fallback_email_allowed'] = true;
				}
				$this->channels->send(
					$channel,
					$recipient,
					$title,
					'email' === $channel ? $message . ( $url ? "\n\n" . $url : '' ) : $delivery_message,
					$context
				);
			}
		}
	}

	private function dispatch_role_event( string $topic, string $event_id, string $title, string $message, string $url ): void {
		$this->dispatch_event( $topic, [ 'id' => $event_id, 'kicker' => __( 'Action needed', 'dsa' ), 'title' => $title, 'message' => $message, 'actionLabel' => __( 'Review', 'dsa' ), 'url' => $url ] );
	}

	private function dispatch_to_user( int $user_id, string $topic, string $event_id, string $title, string $message, string $url ): void {
		if ( $user_id < 1 ) return;
		$this->dispatch_event( $topic, [ 'id' => $event_id, 'userIds' => [ $user_id ], 'kicker' => __( 'Account update', 'dsa' ), 'title' => $title, 'message' => $message, 'actionLabel' => __( 'Open', 'dsa' ), 'url' => $url ] );
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
