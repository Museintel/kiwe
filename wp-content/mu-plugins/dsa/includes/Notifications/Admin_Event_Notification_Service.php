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
	private bool $store_sources_registered = false;

	public function __construct( Notification_Preference_Service $preferences, Push_Service $push, Store_Analytics_Service|\Closure|null $analytics = null, ?Channel_Service $channels = null ) {
		$this->preferences = $preferences;
		$this->push        = $push;
		$this->analytics   = $analytics;
		$this->channels    = $channels;
	}

	public function register( bool $visitor_sources = false ): void {
		$this->register_router();
		if ( $this->sources_registered ) return;
		$this->sources_registered = true;
		// Core inbox events do not opt a site into visitor tracking.
		if ( $visitor_sources ) {
			add_action( 'template_redirect', [ $this, 'queue_visitor_summary' ], 45 );
			add_action( 'dsa_analytics_visit_recorded', [ $this, 'queue_live_visitor' ], 10, 1 );
			add_action( 'dsa_analytics_activity_recorded', [ $this, 'queue_visitor_activity' ], 10, 1 );
		}
		if ( function_exists( 'WC' ) ) $this->register_store_sources();
		else add_action( 'plugins_loaded', [ $this, 'register_store_sources' ], 30 );
		add_action( 'comment_post', [ $this, 'queue_comment' ], 20, 3 );
		add_action( 'user_register', [ $this, 'account_registered' ], 30, 1 );
		add_action( 'kiwe_identity_factor_verified', [ $this, 'account_verified' ], 30, 2 );
		add_action( 'set_user_role', [ $this, 'account_role_changed' ], 30, 2 );
		add_action( 'after_password_reset', [ $this, 'account_password_changed' ], 30, 1 );
		add_action( 'kiwe_guest_application_submitted', [ $this, 'queue_guest_application' ], 10, 2 );
		add_action( 'kiwe_guest_application_decided', [ $this, 'queue_guest_decision' ], 10, 3 );
		add_action( 'kiwe_guest_submission_received', [ $this, 'queue_guest_submission' ], 10, 2 );
		add_action( 'transition_post_status', [ $this, 'queue_author_status' ], 30, 3 );
		add_action( 'dsa_admin_notification_event', [ $this, 'dispatch' ], 10, 2 );
	}

	public function register_store_sources(): void {
		if ( $this->store_sources_registered || ! \DSA\Apps\Store_App_Service::enabled() ) return;
		$this->store_sources_registered = true;
		add_action( 'woocommerce_new_order', [ $this, 'queue_order' ], 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'queue_order' ], 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'queue_order' ], 20, 1 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'order_status_changed' ], 20, 4 );
		add_action( 'dsa_saved_cart_abandoned', [ $this, 'queue_saved_cart' ], 20, 1 );
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
		$explicit = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $event['userIds'] ?? [] ) ) ) ) ), 0, 100 );
		if ( $this->preferences->personal_topic( $topic ) && ! $explicit ) return;
		$eligible = ! empty( $event['inboxOnly'] ) ? [] : $this->preferences->audience_user_ids_for_topic( '', $topic, $explicit );
		$native = $this->preferences->inbox_user_ids_for_topic( $topic, $explicit );
		$eligible = array_slice( array_values( array_unique( array_merge( $native, $eligible ) ) ), 0, 100 );
		if ( 'admin_new_account' === $topic ) {
			$object = absint( $event['objectId'] ?? 0 );
			$can_review = static fn( $actor ) => \DSA\Access\Team_Workspace_Service::can_review_account_for( (int) $actor, $object );
			$native = array_values( array_filter( $native, $can_review ) );
			$eligible = array_values( array_filter( $eligible, $can_review ) );
		}
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
		if ( isset( $event['objectId'] ) ) $item['objectId'] = absint( $event['objectId'] );
		if ( isset( $event['severity'] ) ) $item['severity'] = sanitize_key( (string) $event['severity'] );
		$this->store_inbox( $native, $item );
		if ( ! empty( $event['inboxOnly'] ) ) return;

		$app_users = array_values( array_intersect( $eligible, $this->preferences->audience_user_ids_for_topic( 'app', $topic, $explicit ) ) );
		if ( $app_users ) {
			$push_result = $this->push->send_to_users( $app_users, $title, $message, $url, [ 'eventId' => $event_id, 'eventType' => $topic, 'kicker' => $kicker ] );
			$this->record_push_results( $event_id, $app_users, is_array( $push_result ) ? $push_result : [] );
		}

		$this->deliver_channels( $event_id, $topic, $title, $message, $url, $eligible );
	}

	private function account_notice( int $id, string $kind, string $title, string $message ): void {
		if ( ! $this->preferences->topic_allowed_for_user( $id, 'account_update' ) ) return;
		$this->dispatch_event( 'account_update', [ 'id'=>'account-' . $id . '-' . $kind . '-' . time(), 'userIds'=>[ $id ],'title'=>$title,'message'=>$message,'url'=>home_url( '/?kiwe-screen=profile' ),'actionLabel'=>__( 'Open your account', 'dsa' ) ] );
	}
	public function account_registered( int $id ): void {
		$this->account_notice( $id, 'joined', __( 'Welcome to your account', 'dsa' ), __( 'Your account is ready. Choose the updates and delivery methods that suit you.', 'dsa' ) );
		$user = get_userdata( $id ); if ( ! $user ) return;
		$this->dispatch_event( 'admin_new_account', [ 'id'=>'account-joined-' . $id, 'objectId'=>$id, 'kicker'=>__( 'New user', 'dsa' ), 'title'=>sprintf( __( '%s joined your website', 'dsa' ), sanitize_text_field( $user->display_name ?: $user->user_login ) ), 'message'=>__( 'Review this account’s verification, role and app activity in Daily → Users.', 'dsa' ), 'actionLabel'=>__( 'Review user', 'dsa' ), 'url'=>\DSA\Access\Team_Workspace_Service::url( [ 'team_view'=>'accounts', 'team_account'=>$id ] ) ] );
	}
	public function account_verified( int $id, string $factor = 'identity' ): void { $this->account_notice( $id, 'verified-' . sanitize_key( $factor ), __( 'Verification completed', 'dsa' ), __( 'Your account verification was updated. Review your account for the current status.', 'dsa' ) ); }
	public function account_role_changed( int $id, string $role ): void { $this->account_notice( $id, 'role', __( 'Your access was updated', 'dsa' ), __( 'Your website role changed. Open your account to review your current access.', 'dsa' ) ); }
	public function account_password_changed( $user ): void { if ( $user instanceof \WP_User ) $this->account_notice( (int) $user->ID, 'password', __( 'Your password changed', 'dsa' ), __( 'Your account password was reset. Contact the site owner if you did not request this.', 'dsa' ) ); }

	public function order_status_changed( $id, $from, $to, $order = null ): void {
		if ( in_array( $from, [ 'checkout-draft','auto-draft','draft' ], true ) ) $this->queue_order( $id );
	}

	public function queue_order( $order_id ): void {
		$order_id = is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ? absint( $order_id->get_id() ) : absint( $order_id );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( $order && in_array( $order->get_status(), [ 'checkout-draft', 'auto-draft', 'draft' ], true ) ) return;
		if ( ! $order ) return;
		delete_transient( 'kiwe_notification_recent_orders' );
		$this->queue( 'new_order', $order_id );
	}

	public function queue_comment( $comment_id, $approved, $comment_data ): void {
		$comment_type = sanitize_key( (string) ( is_array( $comment_data ) ? ( $comment_data['comment_type'] ?? 'comment' ) : 'comment' ) );
		if ( ! in_array( $comment_type, [ '', 'comment', 'review' ], true ) ) return;
		$this->queue( 'new_comment', absint( $comment_id ) );
	}

	public function queue_saved_cart( int $id ): void { $this->queue( 'abandoned_cart', $id ); }

	private function dispatch_saved_cart( int $id ): void {
		global $wpdb;
		if ( \DSA\Commerce\Store_Recovery_Gate_Service::is_paused() ) return;
		if ( ! function_exists( 'WC' ) || ! get_option( 'dsa_abandoned_cart_db_version' ) ) return;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,abandoned_at FROM {$wpdb->prefix}dsa_abandoned_carts WHERE id=%d", $id ), ARRAY_A );
		if ( ! $row || 'abandoned' !== $row['status'] ) return;
		$this->dispatch_event( 'admin_abandoned_cart', [ 'id'=>'abandoned-cart-' . $id, 'objectId'=>$id, 'createdAt'=>max( 1, (int) get_gmt_from_date( (string) $row['abandoned_at'], 'U' ) ), 'kicker'=>__( 'Saved cart', 'dsa' ), 'title'=>__( 'A shopper left a cart waiting', 'dsa' ), 'message'=>__( 'Review the saved cart in Daily. A reminder can only use the shopper’s verified, opted-in delivery choices.', 'dsa' ), 'actionLabel'=>__( 'Review cart', 'dsa' ), 'url'=>add_query_arg( 'cart', $id, \DSA\Access\Commerce_App_Service::url( 'saved-carts' ) ) ] );
	}

	public function queue_guest_application( int $user_id, array $application ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) return;
		$title = __( 'New Guest application', 'dsa' );
		$message = sprintf( __( '%s applied to contribute. Review the verified account and requested work.', 'dsa' ), sanitize_text_field( $user->display_name ?: $user->user_login ) );
		$url = add_query_arg( [ 'page' => 'kiwe-guests', 'view' => 'applications', 'id' => $user_id ], admin_url( 'admin.php' ) );
		$this->dispatch_role_event( 'admin_guest_application', 'guest-application-' . $user_id . '-' . time(), $title, $message, $url );
	}

	public function queue_guest_decision( int $user_id, string $decision, array $application ): void {
		$title = 'approved' === $decision ? __( 'Your Guest application was approved', 'dsa' ) : __( 'Your Guest application was reviewed', 'dsa' );
		$message = 'approved' === $decision
			? __( 'You can now open the protected Guest workspace and send an article for editorial review.', 'dsa' )
			: ( (string) ( $application['reason'] ?? '' ) ?: __( 'The publisher did not approve this application.', 'dsa' ) );
		$url = 'approved' === $decision ? add_query_arg( [ 'page' => 'kiwe-guests', 'view' => 'mine' ], admin_url( 'admin.php' ) ) : home_url( '/' );
		$this->dispatch_to_user( $user_id, 'guest_post_status', 'guest-decision-' . $user_id . '-' . time(), $title, $message, $url );
	}

	public function queue_guest_submission( int $post_id, int $user_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, [ 'post', 'product' ], true ) || 'pending' !== $post->post_status || (int) $post->post_author !== $user_id || ! \DSA\Access\Guest_Contribution_Service::is_submission( $post_id ) ) return;
		$product = 'product' === $post->post_type;
		if ( $product && ! function_exists( 'WC' ) && ! class_exists( 'WooCommerce' ) ) return;
		$type_label = $product ? __( 'product proposal', 'dsa' ) : __( 'article', 'dsa' );
		$title = sprintf( __( 'Guest %s ready for review', 'dsa' ), $type_label );
		$message = sprintf( __( '“%s” was submitted through the protected Guest workspace.', 'dsa' ), wp_strip_all_tags( get_the_title( $post_id ) ) );
		// Build the review route for the recipient; the submitting Guest cannot obtain an edit link.
		$review_url = add_query_arg( [ 'page' => 'kiwe-guests', 'view' => 'review', 'id' => $post_id ], admin_url( 'admin.php' ) );
		$this->dispatch_role_event( $product ? 'admin_guest_product_submission' : 'admin_guest_submission', 'guest-post-' . $post_id, $title, $message, $review_url );
	}

	public function queue_author_status( string $new_status, string $old_status, $post ): void {
		if ( $new_status === $old_status || 'new' === $old_status || ! $post instanceof \WP_Post || ! in_array( $post->post_type, [ 'post', 'product' ], true ) || get_current_user_id() === (int) $post->post_author ) return;
		$user = get_userdata( (int) $post->post_author );
		if ( ! $user ) return;
		$product = 'product' === $post->post_type;
		$guest = \DSA\Access\Guest_Contribution_Service::is_submission( (int) $post->ID ) || ( ! $product && in_array( 'contributor', (array) $user->roles, true ) );
		if ( $product && ( ! $guest || ( ! function_exists( 'WC' ) && ! class_exists( 'WooCommerce' ) ) ) ) return;
		$topic = $guest ? ( $product ? 'guest_product_status' : 'guest_post_status' ) : ( ! $product && in_array( 'author', (array) $user->roles, true ) ? 'editorial_post_status' : '' );
		if ( '' === $topic ) return;
		$status = get_post_status_object( $new_status );
		$title = $product ? __( 'Product proposal status updated', 'dsa' ) : __( 'Article status updated', 'dsa' );
		$message = sprintf( __( '“%1$s” is now %2$s.', 'dsa' ), wp_strip_all_tags( get_the_title( $post ) ), $status ? strtolower( $status->label ) : sanitize_key( $new_status ) );
		$guest_url = add_query_arg( [ 'page' => 'kiwe-guests', 'view' => 'mine', 'id' => (int) $post->ID ], admin_url( 'admin.php' ) );
		$this->dispatch_to_user( (int) $post->post_author, $topic, 'post-status-' . $post->ID . '-' . $new_status, $title, $message, 'publish' === $new_status ? get_permalink( $post ) : ( $guest && user_can( (int) $post->post_author, 'kiwe_guest_submit' ) ? $guest_url : home_url( '/' ) ) );
	}

	public function queue_visitor_summary(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( $this->analytics instanceof \Closure ) $this->analytics = ( $this->analytics )();
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
		if ( 'abandoned_cart' === $event ) { $this->dispatch_saved_cart( $object_id ); return; }
		if ( 'new_order' === $event ) {
			$this->dispatch_order( $object_id );
			return;
		}
		if ( 'new_comment' === $event ) {
			$this->dispatch_comment( $object_id );
		}
	}

	/** Read the current user's bounded inbox without marking delivery or writing metadata. */
	public function workspace_inbox( array $allowed_topics ): array {
		$this->reconcile_recent_orders_for_current_user();
		return $this->workspace_items( $allowed_topics, true, 1 )['items'];
	}

	/** Read acknowledged and pending items on demand; never mark them delivered or read. */
	public function workspace_history( array $allowed_topics, int $page = 1 ): array {
		return $this->workspace_items( $allowed_topics, true, $page );
	}

	public function pull_current_user(): array {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) || ! current_user_can( 'kiwe_use_notifications' ) ) return [];
		$this->reconcile_recent_orders_for_current_user();
		$stored = get_user_meta( get_current_user_id(), self::INBOX_META, true ); $pending = [];
		foreach ( array_slice( is_array( $stored ) ? $stored : [], 0, 40 ) as $item ) {
			if ( ! is_array( $item ) || ! $this->visible_item( $item ) ) continue;
			$pending[] = $this->project_workspace_item( $item );
		}
		return array_slice( $pending, 0, 20 );
	}

	/** Bounded, inbox-only recovery for orders missed before event hooks were enabled. */
	public function reconcile_recent_orders_for_current_user(): void {
		$id = get_current_user_id();
		if ( ! function_exists( 'wc_get_orders' ) || ! $this->preferences->inbox_accepts( $id, 'admin_new_order' ) ) return;
		$orders = get_transient( 'kiwe_notification_recent_orders' );
		if ( ! is_array( $orders ) ) {
			$orders = []; $rows = wc_get_orders( [ 'limit'=>10,'date_created'=>'>=' . ( time()-7*DAY_IN_SECONDS ),'status'=>array_values( array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-checkout-draft' ] ) ),'orderby'=>'date','order'=>'DESC','return'=>'objects' ] );
			foreach ( $rows as $order ) $orders[] = [ 'id'=>$order->get_id(),'number'=>sanitize_text_field( (string) $order->get_order_number() ),'createdAt'=>$order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ];
			set_transient( 'kiwe_notification_recent_orders', $orders, 5*MINUTE_IN_SECONDS );
		}
		foreach ( array_reverse( array_slice( $orders, 0, 10 ) ) as $order ) {
			if ( ! user_can( $id, 'edit_shop_order', (int) $order['id'] ) ) continue;
			$this->store_inbox( [ $id ], [ 'id'=>'order-' . $order['id'],'type'=>'admin_new_order','title'=>sprintf( __( 'Order #%s needs your attention.', 'dsa' ), $order['number'] ),'message'=>__( 'Review this recent order’s payment and fulfilment.', 'dsa' ),'actionLabel'=>__( 'Review order', 'dsa' ),'actionUrl'=>admin_url( 'admin.php?page=kiwe-store&area=orders&search=' . (int) $order['id'] ),'objectId'=>(int) $order['id'],'createdAt'=>(int) $order['createdAt'] ] );
		}
	}

	private function visible_item( array $item ): bool {
		$topic = sanitize_key( (string) ( $item['type'] ?? '' ) ); $id = get_current_user_id();
		if ( ! $this->preferences->topic_allowed_for_user( $id, $topic ) ) return false;
		$object = absint( $item['objectId'] ?? 0 );
		if ( 'admin_new_account' === $topic ) return \DSA\Access\Team_Workspace_Service::can_review_account_for( $id, $object );
		if ( ! $object && in_array( $topic, [ 'admin_new_order','order_status' ], true ) && preg_match( '/^order-(\d+)(?:-|$)/', (string) ( $item['id'] ?? '' ), $match ) ) $object = (int) $match[1];
		if ( 'admin_new_order' === $topic ) return $object > 0 && function_exists( 'wc_get_order' ) && wc_get_order( $object ) && user_can( $id, 'edit_shop_order', $object );
		if ( 'order_status' === $topic ) { $order = $object && function_exists( 'wc_get_order' ) ? wc_get_order( $object ) : false; return $order && (int) $order->get_user_id() === $id; }
		if ( 'new_post' === $topic && $object ) { $post = get_post( $object ); return $post && 'publish' === $post->post_status && '' === $post->post_password; }
		return true;
	}

	/** Shared private chrome reads only the existing bounded authorized metadata. */
	public static function unread_count(): int {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) || ! current_user_can( 'kiwe_use_notifications' ) ) return 0;
		$preferences = new Notification_Preference_Service( new \DSA\Settings(), new \DSA\Trust\Trust_Service() );
		$items = get_user_meta( get_current_user_id(), self::INBOX_META, true ); $count = 0;
		foreach ( array_slice( is_array( $items ) ? $items : [], 0, 40 ) as $item ) if ( is_array( $item ) && empty( $item['acknowledgedAt'] ) && $preferences->topic_allowed_for_user( get_current_user_id(), sanitize_key( (string) ( $item['type'] ?? '' ) ) ) && ( 'admin_new_account' !== ( $item['type'] ?? '' ) || \DSA\Access\Team_Workspace_Service::can_review_account_for( get_current_user_id(), absint( $item['objectId'] ?? 0 ) ) ) ) $count++;
		return $count;
	}

	/** Snapshot of the notices actually available to this account; GET remains read-only. */
	public function visit_ids(): array {
		$items = get_user_meta( get_current_user_id(), self::INBOX_META, true ); $ids = [];
		foreach ( array_slice( is_array( $items ) ? $items : [], 0, 40 ) as $item ) if ( is_array( $item ) && $this->visible_item( $item ) && is_string( $item['id'] ?? null ) ) $ids[] = $item['id'];
		return $ids;
	}
	public function acknowledge_visit( array $ids ): bool {
		if ( count( $ids ) > 40 || array_filter( $ids, static fn( $id ) => ! is_string( $id ) || '' === $id || strlen( $id ) > 200 ) ) return false;
		$user = get_current_user_id(); if ( ! $user || ! current_user_can( 'read' ) || ! current_user_can( 'kiwe_use_notifications' ) || \DSA\Access\Team_Workspace_Service::suspended( $user ) || ( is_multisite() && ! is_user_member_of_blog( $user ) ) ) return false;
		for ( $try = 0; $try < 3; $try++ ) {
			$raw = get_user_meta( $user, self::INBOX_META, true ); $items = is_array( $raw ) ? $raw : []; $changed = false;
			foreach ( $items as &$item ) if ( is_array( $item ) && empty( $item['acknowledgedAt'] ) && ( ! $this->visible_item( $item ) || in_array( (string) ( $item['id'] ?? '' ), $ids, true ) ) ) { $item['acknowledgedAt'] = time(); $changed = true; }
			unset( $item );
			if ( ! $changed || false !== update_user_meta( $user, self::INBOX_META, array_slice( $items, 0, 40 ), $raw ) ) return true;
		}
		return false;
	}

	public function acknowledge_current_user( string $event_id ): bool {
		$user_id = get_current_user_id();
		$event_id = sanitize_text_field( $event_id );
		if ( ! $user_id || '' === $event_id ) return false;
		$items = get_user_meta( $user_id, self::INBOX_META, true );
		$items = is_array( $items ) ? $items : [];
		$before = $items;
		$changed = false;
		foreach ( $items as &$item ) {
			if ( ! is_array( $item ) || (string) ( $item['id'] ?? '' ) !== $event_id || ! $this->visible_item( $item ) ) continue;
			if ( ! empty( $item['acknowledgedAt'] ) ) return true;
			$item['acknowledgedAt'] = time();
			$changed = true;
		}
		unset( $item );
		// A concurrently arriving notification must not be overwritten by acknowledgement.
		if ( $changed ) return false !== update_user_meta( $user_id, self::INBOX_META, array_slice( $items, 0, 40 ), $before );
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
		$url = admin_url( 'admin.php?page=kiwe-store&area=orders&search=' . $order_id );
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
			'objectId' => $order_id,
			'createdAt' => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time(),
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
		if ( $this->analytics instanceof \Closure ) $this->analytics = ( $this->analytics )();
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

	private function deliver_channels( string $event_id, string $topic, string $title, string $message, string $url, array $eligible_user_ids = [] ): void {
		if ( ! $this->channels ) {
			return;
		}

		$purpose = sanitize_key( $topic );
		$delivery_message = trim( $title . "\n\n" . $message . ( $url ? "\n\n" . $url : '' ) );
		foreach ( [ 'whatsapp', 'email', 'sms' ] as $channel ) {
			$audience = $this->preferences->audience_user_ids_for_topic( $channel, $topic, $eligible_user_ids );
			if ( $eligible_user_ids ) $audience = array_values( array_intersect( $audience, $eligible_user_ids ) );
			$audience = array_slice( $audience, 0, 100 );
			if ( ! $this->channels->available_for_campaign( $channel ) ) {
				foreach ( $audience as $user_id ) {
					$this->record_delivery( (int) $user_id, $event_id, $channel, 'unavailable', __( 'This channel is no longer configured.', 'dsa' ) );
				}
				continue;
			}
			foreach ( $audience as $user_id ) {
				$recipient = $this->preferences->contact_for_user( (int) $user_id, $channel );
				if ( '' === $recipient ) {
					$this->record_delivery( (int) $user_id, $event_id, $channel, 'failed', __( 'No verified contact is available for this channel.', 'dsa' ) );
					continue;
				}
				$context = [ 'purpose' => $purpose, 'user_id' => (int) $user_id ];
				if ( 'whatsapp' === $channel && $this->preferences->user_accepts( (int) $user_id, 'email', $topic ) ) {
					$context['fallback_email'] = $this->preferences->contact_for_user( (int) $user_id, 'email' );
					$context['fallback_email_allowed'] = true;
				}
				$result = $this->channels->send(
					$channel,
					$recipient,
					$title,
					'email' === $channel ? $message . ( $url ? "\n\n" . $url : '' ) : $delivery_message,
					$context
				);
				if ( is_wp_error( $result ) ) {
					$this->record_delivery( (int) $user_id, $event_id, $channel, 'failed', __( 'The configured service did not accept this update.', 'dsa' ) );
				} elseif ( is_array( $result ) && 'email_fallback' === sanitize_key( (string) ( $result['provider'] ?? '' ) ) ) {
					$this->record_delivery( (int) $user_id, $event_id, $channel, 'fallback', __( 'WhatsApp failed; the update was handed to email instead.', 'dsa' ) );
				} elseif ( false === $result ) {
					$this->record_delivery( (int) $user_id, $event_id, $channel, 'failed', __( 'The provider did not accept this update.', 'dsa' ) );
				} else {
					$this->record_delivery( (int) $user_id, $event_id, $channel, 'accepted', $this->accepted_delivery_label( $channel ) );
				}
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

	private function workspace_items( array $allowed_topics, bool $include_acknowledged, int $page ): array {
		$page = max( 1, min( 2, $page ) );
		if ( ! is_user_logged_in() || ! current_user_can( 'kiwe_use_notifications' ) ) return [ 'items' => [], 'page' => $page, 'hasNext' => false ];
		$allowed_topics = array_values( array_unique( array_filter( array_map( 'sanitize_key', $allowed_topics ) ) ) );
		$stored = get_user_meta( get_current_user_id(), self::INBOX_META, true );
		$visible = [];
		foreach ( array_slice( is_array( $stored ) ? $stored : [], 0, 40 ) as $item ) {
			if ( ! is_array( $item ) || ! in_array( sanitize_key( (string) ( $item['type'] ?? '' ) ), $allowed_topics, true ) || ! $this->visible_item( $item ) ) continue;
			if ( ! $include_acknowledged && ! empty( $item['acknowledgedAt'] ) ) continue;
			$visible[] = $item;
		}
		$offset = ( $page - 1 ) * 20;
		$items = array_slice( $visible, $offset, 21 );
		$has_next = count( $items ) > 20;
		return [ 'items' => array_map( [ $this, 'project_workspace_item' ], array_slice( $items, 0, 20 ) ), 'page' => $page, 'hasNext' => $has_next ];
	}

	private function project_workspace_item( array $item ): array {
		// Read the current native order state only after visible_item admitted this account.
		$record_status = '';
		if ( in_array( $item['type'] ?? '', [ 'admin_new_order', 'order_status' ], true ) && function_exists( 'wc_get_order' ) ) {
			$object = absint( $item['objectId'] ?? 0 );
			if ( ! $object && preg_match( '/^order-(\d+)(?:-|$)/', (string) ( $item['id'] ?? '' ), $match ) ) $object = (int) $match[1];
			$order = $object ? wc_get_order( $object ) : false;
			if ( $order ) $record_status = sanitize_key( $order->get_status() );
		}
		$deliveries = [];
		foreach ( array_slice( is_array( $item['deliveries'] ?? null ) ? $item['deliveries'] : [], 0, 4 ) as $delivery ) {
			if ( ! is_array( $delivery ) ) continue;
			$channel = sanitize_key( (string) ( $delivery['channel'] ?? '' ) );
			$status = sanitize_key( (string) ( $delivery['status'] ?? '' ) );
			if ( ! in_array( $channel, [ 'app', 'email', 'whatsapp', 'sms' ], true ) || ! in_array( $status, [ 'accepted', 'partial', 'fallback', 'failed', 'unavailable' ], true ) ) continue;
			$deliveries[] = [
				'channel' => $channel,
				'status' => $status,
				'detail' => substr( sanitize_text_field( (string) ( $delivery['detail'] ?? '' ) ), 0, 180 ),
				'attemptedAt' => absint( $delivery['attemptedAt'] ?? 0 ),
			];
		}
		return [
			'id' => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
			'type' => sanitize_key( (string) ( $item['type'] ?? '' ) ),
			'recordStatus' => $record_status,
			'title' => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			'message' => sanitize_textarea_field( (string) ( $item['message'] ?? '' ) ),
			'actionUrl' => esc_url_raw( (string) ( $item['actionUrl'] ?? '' ) ),
			'actionLabel' => sanitize_text_field( (string) ( $item['actionLabel'] ?? __( 'Open', 'dsa' ) ) ),
			'kicker' => sanitize_text_field( (string) ( $item['kicker'] ?? __( 'Update', 'dsa' ) ) ),
			'createdAt' => absint( $item['createdAt'] ?? 0 ),
			'readAt' => absint( $item['acknowledgedAt'] ?? 0 ),
			'deliveries' => $deliveries,
		];
	}

	private function record_push_results( string $event_id, array $user_ids, array $result ): void {
		$users = is_array( $result['users'] ?? null ) ? $result['users'] : [];
		foreach ( $user_ids as $user_id ) {
			$user_result = is_array( $users[ $user_id ] ?? null ) ? $users[ $user_id ] : [];
			$sent = absint( $user_result['sent'] ?? 0 );
			$failed = absint( $user_result['failed'] ?? 0 );
			if ( $sent > 0 && 0 === $failed ) {
				$status = 'accepted';
				$detail = sprintf( _n( 'Browser push accepted for %d device.', 'Browser push accepted for %d devices.', $sent, 'dsa' ), $sent );
			} elseif ( $sent > 0 ) {
				$status = 'partial';
				$detail = sprintf( __( 'Browser push reached %1$d device(s); %2$d failed.', 'dsa' ), $sent, $failed );
			} else {
				$status = 'failed';
				$detail = $failed > 0 ? __( 'Browser push failed for the active device.', 'dsa' ) : __( 'No active browser device accepted this update.', 'dsa' );
			}
			$this->record_delivery( (int) $user_id, $event_id, 'app', $status, $detail );
		}
	}

	private function accepted_delivery_label( string $channel ): string {
		if ( 'email' === $channel ) return __( 'Handed to the configured email service.', 'dsa' );
		if ( 'whatsapp' === $channel ) return __( 'Accepted by the configured WhatsApp service.', 'dsa' );
		return __( 'Accepted by the configured SMS service.', 'dsa' );
	}

	private function record_delivery( int $user_id, string $event_id, string $channel, string $status, string $detail ): bool {
		if ( $user_id < 1 || '' === $event_id ) return false;
		$delivery = [
			'channel' => sanitize_key( $channel ),
			'status' => sanitize_key( $status ),
			'detail' => substr( sanitize_text_field( $detail ), 0, 180 ),
			'attemptedAt' => time(),
		];
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$items = get_user_meta( $user_id, self::INBOX_META, true );
			$items = is_array( $items ) ? $items : [];
			$before = $items;
			$changed = false;
			foreach ( $items as &$item ) {
				if ( ! is_array( $item ) || ! hash_equals( (string) ( $item['id'] ?? '' ), $event_id ) ) continue;
				$existing = is_array( $item['deliveries'] ?? null ) ? $item['deliveries'] : [];
				$existing = array_values( array_filter( $existing, static fn( $entry ): bool => ! is_array( $entry ) || sanitize_key( (string) ( $entry['channel'] ?? '' ) ) !== $delivery['channel'] ) );
				$existing[] = $delivery;
				$item['deliveries'] = array_slice( $existing, -4 );
				$changed = true;
				break;
			}
			unset( $item );
			if ( ! $changed ) return false;
			if ( false !== update_user_meta( $user_id, self::INBOX_META, array_slice( $items, 0, 40 ), $before ) ) return true;
		}
		return false;
	}

	private function store_inbox( array $user_ids, array $item ): void {
		$item['deliveries'] = [];
		foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) ), 0, 100 ) as $user_id ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$raw = get_user_meta( $user_id, self::INBOX_META, true ); $items = is_array( $raw ) ? $raw : [];
				if ( in_array( $item['id'], array_column( $items, 'id' ), true ) ) break; // Preserve read/delivery state on replay.
				$next = array_values( array_filter( $items, static fn( $old ) => is_array( $old ) && !( 'admin_live_visitor' === ( $item['type'] ?? '' ) && 'admin_live_visitor' === ( $old['type'] ?? '' ) ) ) );
				array_unshift( $next, $item ); $next = array_slice( $next, 0, 40 );
				if ( ! is_array( $raw ) ) { if ( add_user_meta( $user_id, self::INBOX_META, $next, true ) ) break; }
				elseif ( update_user_meta( $user_id, self::INBOX_META, $next, $raw ) ) break;
			}
		}
	}
}
