<?php
namespace DSA\Access;

use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Fixed-size new-record counters. Native records and permissions remain their owners. */
final class Workspace_Activity_Service {
	public const OPTION = 'kiwe_workspace_activity_v1';
	public const OWN = 'dsa_workspace_activity_own';
	public const SEEN = 'dsa_workspace_activity_seen';
	private const SECTIONS = [ 'users', 'orders', 'saved-carts', 'posts', 'pages', 'products', 'services', 'templates', 'media', 'feedback', 'guest-applications', 'guest-posts', 'guest-products', 'campaigns', 'app' ];
	private bool $store_hooks_registered = false;

	public static function user_meta_key( string $name ): string { return $name . '_b' . get_current_blog_id(); }

	public function register(): void {
		add_action( 'user_register', [ $this, 'user_created' ], 40, 1 );
		add_action( 'transition_post_status', [ $this, 'post_created' ], 40, 3 );
		add_action( 'add_attachment', [ $this, 'attachment_created' ], 40, 1 );
		add_action( 'comment_post', [ $this, 'comment_created' ], 40, 3 );
		add_action( 'kiwe_discussion_comment_committed', [ $this, 'comment_committed' ], 40 );
		if ( function_exists( 'WC' ) ) $this->register_store_hooks();
		else add_action( 'plugins_loaded', [ $this, 'register_store_hooks' ], 30 );
		add_action( 'kiwe_guest_application_submitted', [ $this, 'guest_application' ], 40, 2 );
		add_action( 'kiwe_guest_submission_received', [ $this, 'guest_submission' ], 40, 2 );
		add_action( 'kiwe_campaign_created', [ $this, 'campaign_created' ], 40, 1 );
		add_action( 'kiwe_campaign_finished', [ $this, 'campaign_finished' ], 40, 1 );
		add_action( 'kiwe_app_first_observed', [ $this, 'app_observed' ], 40, 1 );
		add_filter( 'rest_post_dispatch', [ $this, 'response_snapshot' ], 20, 3 );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}
	public function register_store_hooks(): void {
		if ( $this->store_hooks_registered || ! \DSA\Apps\Store_App_Service::enabled() ) return;
		$this->store_hooks_registered = true;
		add_action( 'woocommerce_new_order', [ $this, 'order_created' ], 40, 1 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'order_transition' ], 40, 4 );
		add_action( 'dsa_saved_cart_abandoned', [ $this, 'cart_abandoned' ], 40, 1 );
	}
	public static function section_for_url( string $url ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$page = is_string( $q['page'] ?? null ) ? $q['page'] : '';
		if ( 'kiwe-store' === $page ) return ( $q['task'] ?? '' ) === 'saved-carts' ? 'saved-carts' : ( in_array( $q['area'] ?? '', [ 'orders', 'products', 'saved-carts' ], true ) ? $q['area'] : '' );
		if ( 'kiwe-team' === $page ) return 'accounts' === ( $q['team_view'] ?? '' ) ? 'users' : '';
		if ( 'kiwe-guests' === $page ) return 'daily' === ( $q['area'] ?? '' ) || ! isset( $q['area'] ) ? 'guests' : '';
		if ( 'kiwe-notifications' === $page && 'campaigns' === ( $q['view'] ?? '' ) ) return 'campaigns';
		if ( 'kiwe-app-settings' === $page && 'activity' === ( $q['app_view'] ?? '' ) ) return 'app';
		return [ 'kiwe-posts'=>'posts', 'kiwe-pages'=>'pages', 'kiwe-services'=>'services', 'kiwe-templates'=>'templates', 'kiwe-context-library'=>'media', 'kiwe-feedback'=>'feedback', 'kiwe-forms'=>'forms' ][ $page ] ?? '';
	}
	private static function admitted(): array {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) || Team_Workspace_Service::suspended( get_current_user_id() ) || ( is_multisite() && ! is_user_member_of_blog( get_current_user_id() ) ) ) return [];
		$out = [];
		foreach ( ( new Workspace_Admin_Service() )->task_catalog() as $task ) {
			$section = self::section_for_url( (string) ( $task['url'] ?? '' ) );
			if ( $section && ( 'users' !== $section || current_user_can( 'list_users' ) ) && ( 'forms' !== $section || Form_Workspace_Service::allowed() ) && ( 'guests' !== $section || self::guest_sections() ) && ( ! in_array( $section, [ 'orders','saved-carts' ], true ) || current_user_can( 'edit_others_shop_orders' ) ) ) $out[ $section ] = true;
		}
		return $out;
	}
	private static function realm( string $section ): string {
		if ( 'guests' === $section ) return implode( '+', self::guest_sections() );
		if ( in_array( $section, [ 'users', 'orders', 'saved-carts', 'forms', 'campaigns', 'app' ], true ) ) return 'all';
		if ( 'feedback' === $section ) return current_user_can( 'moderate_comments' ) ? 'all' : 'own';
		$type = [ 'posts'=>'post', 'pages'=>'page', 'products'=>'product', 'templates'=>'bricks_template', 'media'=>'attachment' ][ $section ] ?? Analytics_Workspace_Service::service_type();
		$object = $type ? get_post_type_object( $type ) : null;
		return $object && current_user_can( $object->cap->edit_others_posts ) ? 'all' : 'own';
	}
	private static function guest_sections(): array {
		if ( ! \DSA\Apps\Guest_App_Service::enabled() ) return [];
		$out = [];
		if ( current_user_can( 'kiwe_manage_guest_applications' ) ) $out[] = 'guest-applications';
		if ( current_user_can( 'edit_posts' ) && current_user_can( 'edit_others_posts' ) ) $out[] = 'guest-posts';
		if ( \DSA\Apps\Store_App_Service::enabled() && current_user_can( 'edit_products' ) && current_user_can( 'edit_others_products' ) ) $out[] = 'guest-products';
		return $out;
	}
	private static function state( $raw ): array {
		return is_array( $raw ) && 1 === ( $raw['schema'] ?? null ) && is_string( $raw['epoch'] ?? null ) ? $raw : [ 'schema'=>1, 'epoch'=>wp_generate_uuid4(), 'totals'=>[], 'keys'=>[] ];
	}
	private static function global_state(): array {
		$raw = get_option( self::OPTION, null );
		// A read never creates an option. The stable empty epoch also permits a first visit.
		return is_array( $raw ) ? self::state( $raw ) : [ 'schema'=>1, 'epoch'=>'empty', 'totals'=>[], 'keys'=>[] ];
	}
	private function increment( string $section, string $key, int $owner = 0 ): void {
		if ( ! in_array( $section, self::SECTIONS, true ) || '' === $key ) return;
		$this->write_increment( $section, $key, 0 );
		if ( $owner > 0 ) $this->write_increment( $section, $key, $owner );
	}
	private function write_increment( string $section, string $key, int $owner ): void {
		global $wpdb;
		$key = substr( hash( 'sha256', $section . '|' . $key ), 0, 32 );
		for ( $try = 0; $try < 3; $try++ ) {
			$raw = $owner ? get_user_meta( $owner, self::user_meta_key( self::OWN ), true ) : get_option( self::OPTION, null );
			$state = self::state( $raw );
			$keys = (array) ( $state['keys'][ $section ] ?? [] );
			if ( in_array( $key, $keys, true ) ) return;
			$keys[] = $key; $state['keys'][ $section ] = array_slice( $keys, -64 );
			$state['totals'][ $section ] = min( 2147483647, max( 0, (int) ( $state['totals'][ $section ] ?? 0 ) ) + 1 );
			if ( $owner ) {
				if ( ! is_array( $raw ) ? add_user_meta( $owner, self::user_meta_key( self::OWN ), $state, true ) : update_user_meta( $owner, self::user_meta_key( self::OWN ), $state, $raw ) ) return;
			} elseif ( null === $raw ) {
				if ( add_option( self::OPTION, $state, '', false ) ) return;
			} else {
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize( $state ), self::OPTION, maybe_serialize( $raw ) ) );
				wp_cache_delete( self::OPTION, 'options' );
				if ( 1 === $changed ) return;
			}
		}
	}
	public function user_created( int $id ): void { if ( $id > 0 ) $this->increment( 'users', 'user:' . $id ); }
	public function post_created( string $new, string $old, $post ): void {
		if ( 'product' === ( $post->post_type ?? '' ) && ! \DSA\Apps\Store_App_Service::enabled() ) return;
		if ( ! $post instanceof \WP_Post || ! in_array( $old, [ 'new', 'auto-draft' ], true ) || in_array( $new, [ 'auto-draft', 'trash' ], true ) ) return;
		$section = [ 'post'=>'posts', 'page'=>'pages', 'product'=>'products', 'attachment'=>'media', 'bricks_template'=>'templates' ][ $post->post_type ] ?? '';
		if ( ! $section && $post->post_type === Analytics_Workspace_Service::service_type() ) $section = 'services';
		if ( $section ) $this->increment( $section, 'post:' . $post->ID, (int) $post->post_author );
	}
	public function attachment_created( int $id ): void { $post = get_post( $id ); if ( $post ) $this->post_created( 'inherit', 'new', $post ); }
	public function comment_created( int $id, $approved, array $data ): void {
		if ( 'composer' === ( $data['comment_meta']['_kiwe_discussion_source_v1'] ?? '' ) ) return;
		$this->record_comment( $id, $approved, $data );
	}
	public function comment_committed( int $id ): void {
		$comment = get_comment( $id ); if ( $comment ) $this->record_comment( $id, $comment->comment_approved, $comment->to_array() );
	}
	private function record_comment( int $id, $approved, array $data ): void {
		if ( in_array( (string) $approved, [ 'spam', 'trash' ], true ) || ! in_array( $data['comment_type'] ?? '', [ '', 'comment', 'review' ], true ) ) return;
		$post = get_post( (int) ( $data['comment_post_ID'] ?? 0 ) );
		$this->increment( 'feedback', 'comment:' . $id, $post ? (int) $post->post_author : 0 );
	}
	public function order_created( $id ): void {
		$id = is_object( $id ) && method_exists( $id, 'get_id' ) ? $id->get_id() : absint( $id );
		$order = $id && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
		if ( $order && ! in_array( $order->get_status(), [ 'checkout-draft', 'auto-draft', 'draft' ], true ) ) $this->increment( 'orders', 'order:' . $id );
	}
	public function order_transition( $id, $old, $new, $order = null ): void { if ( in_array( $old, [ 'checkout-draft', 'auto-draft', 'draft' ], true ) ) $this->order_created( $id ); }
	public function cart_abandoned( int $id ): void { if ( $id > 0 ) $this->increment( 'saved-carts', 'cart:' . $id ); }
	public function app_observed( int $id ): void { if ( $id > 0 ) $this->increment( 'app', 'app:' . $id ); }
	public function guest_application( int $id, array $application ): void {
		if ( $id > 0 && 'pending' === ( $application['status'] ?? '' ) && is_string( $application['appliedAt'] ?? null ) ) $this->increment( 'guest-applications', 'application:' . $id . ':' . $application['appliedAt'] );
	}
	public function guest_submission( int $id, int $owner ): void {
		$post = get_post( $id );
		if ( $post && 'pending' === $post->post_status && (int) $post->post_author === $owner && Guest_Contribution_Service::is_submission( $id ) && in_array( $post->post_type, [ 'post','product' ], true ) ) $this->increment( 'product' === $post->post_type ? 'guest-products' : 'guest-posts', 'submission:' . $id );
	}
	public function campaign_created( string $id ): void { if ( preg_match( '/^c-[a-f0-9]{16}$/D', $id ) ) $this->increment( 'campaigns', 'draft:' . $id ); }
	public function campaign_finished( array $job ): void {
		if ( is_string( $job['id'] ?? null ) && preg_match( '/^[a-z0-9]{1,40}$/D', $job['id'] ) && in_array( $job['status'] ?? '', [ 'complete','cancelled' ], true ) ) $this->increment( 'campaigns', 'result:' . $job['id'] );
	}
	private static function signature( array $snapshot ): string {
		return hash_hmac( 'sha256', wp_json_encode( [ get_current_blog_id(), get_current_user_id(), $snapshot ] ), wp_salt( 'nonce' ) );
	}
	public static function snapshots(): array {
		$allowed = self::admitted(); if ( ! $allowed ) return [];
		$global = self::global_state(); $own = get_user_meta( get_current_user_id(), self::user_meta_key( self::OWN ), true );
		$seen = get_user_meta( get_current_user_id(), self::user_meta_key( self::SEEN ), true ); $out = [];
		foreach ( array_keys( $allowed ) as $section ) {
			$realm = self::realm( $section ); $state = 'all' === $realm ? $global : ( is_array( $own ) ? self::state( $own ) : [ 'epoch'=>'empty', 'totals'=>[] ] );
			if ( 'guests' === $section ) { $state = $global; $state['totals']['guests'] = array_sum( array_map( static fn( $key ) => max( 0, (int) ( $global['totals'][ $key ] ?? 0 ) ), self::guest_sections() ) ); }
			if ( 'forms' === $section ) {
				$last = $seen['forms:all'] ?? []; $native = Form_Workspace_Service::activity( is_array( $last ) ? $last : [] );
				if ( ! $native ) continue;
				$snapshot = [ 'section'=>$section, 'realm'=>$realm, 'epoch'=>$native['epoch'], 'ceiling'=>$native['ceiling'], 'issued'=>time() ];
				$out[ $section ] = [ ...$snapshot, 'token'=>self::signature( $snapshot ), 'count'=>$native['count'] ]; continue;
			}
			$ceiling = max( 0, (int) ( $state['totals'][ $section ] ?? 0 ) ); $last = $seen[ $section . ':' . $realm ] ?? [];
			$visited = ( $last['epoch'] ?? '' ) === $state['epoch'] ? min( $ceiling, max( 0, (int) ( $last['ceiling'] ?? 0 ) ) ) : 0;
			$snapshot = [ 'section'=>$section, 'realm'=>$realm, 'epoch'=>$state['epoch'], 'ceiling'=>$ceiling, 'issued'=>time() ];
			$out[ $section ] = [ ...$snapshot, 'token'=>self::signature( $snapshot ), 'count'=>$ceiling - $visited ];
		}
		return $out;
	}
	public static function tasks( array $tasks ): array {
		$states = self::snapshots();
		foreach ( $tasks as &$task ) { $section = self::section_for_url( (string) ( $task['url'] ?? '' ) ); $task['activitySection'] = $section; if ( isset( $states[ $section ] ) ) $task['newCount'] = $states[ $section ]['count']; }
		return $tasks;
	}
	public function response_snapshot( $response, $server, $request ) {
		if ( 'GET' !== $request->get_method() || ! str_starts_with( $request->get_route(), '/dsa/v1/workspace/' ) || ! $response instanceof \WP_REST_Response || $response->get_status() >= 300 ) return $response;
		// A native inbox may capture its ceiling before reading rows. Do not move that ceiling after rendering data.
		if ( isset( $response->get_headers()['X-Kiwe-Activity'] ) ) return $response;
		return self::attach_snapshots( $response, self::snapshots() );
	}
	public static function attach_snapshots( \WP_REST_Response $response, array $snapshots ): \WP_REST_Response {
		if ( $snapshots ) $response->header( 'X-Kiwe-Activity', base64_encode( wp_json_encode( [ 'actor'=>get_current_user_id(), 'endpoint'=>rest_url( 'dsa/v1/workspace/activity/seen' ), 'nonce'=>wp_create_nonce( 'wp_rest' ), 'snapshots'=>$snapshots ] ) ) );
		return $response;
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/activity', [ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'read_permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/activity/seen', [ 'methods'=>'POST', 'callback'=>[ $this, 'visit' ], 'permission_callback'=>[ $this, 'permission' ] ] );
	}
	public function read_permission( \WP_REST_Request $request ): bool {
		return 'GET' === $request->get_method() && ! empty( self::admitted() ) && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request();
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->read_permission( $request ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_activity_denied', 'Open this workspace with your current account.' ), 403 );
		return Task_Workspace_UI::response( [ 'actor'=>get_current_user_id(), 'snapshots'=>self::snapshots(), 'unreadCount'=>\DSA\Notifications\Admin_Event_Notification_Service::unread_count() ] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		return 'POST' === $request->get_method() && ! empty( self::admitted() ) && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && true === Origin_Checker::mutation_allowed( $request );
	}
	public function visit( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_activity_denied', 'This visit could not be confirmed.' ), 403 );
		$b = $request->get_json_params(); $keys = [ 'section', 'realm', 'epoch', 'ceiling', 'issued' ];
		if ( ! is_array( $b ) || ! is_string( $b['section'] ?? null ) || ! is_string( $b['realm'] ?? null ) || ! is_string( $b['epoch'] ?? null ) || strlen( $b['epoch'] ) > 64 || ! is_string( $b['token'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $b['token'] ) || array_diff( $keys, array_keys( $b ) ) || ! is_int( $b['ceiling'] ) || $b['ceiling'] < 0 || ! is_int( $b['issued'] ) || $b['issued'] < time() - 600 || $b['issued'] > time() + 30 ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_activity_invalid', 'Refresh this space before updating its badge.' ), 400 );
		$snapshot = []; foreach ( $keys as $name ) $snapshot[ $name ] = $b[ $name ]; $current = self::snapshots()[ $b['section'] ] ?? null;
		if ( ! $current || $current['realm'] !== $b['realm'] || $current['epoch'] !== $b['epoch'] || $b['ceiling'] > $current['ceiling'] || ! hash_equals( self::signature( $snapshot ), $b['token'] ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_activity_stale', 'The account or space changed. Refresh before updating its badge.' ), 409 );
		for ( $try = 0; $try < 3; $try++ ) {
			$raw = get_user_meta( get_current_user_id(), self::user_meta_key( self::SEEN ), true ); $seen = is_array( $raw ) ? $raw : []; $key = $b['section'] . ':' . $b['realm'];
			$last = $seen[ $key ] ?? []; $ceiling = ( $last['epoch'] ?? '' ) === $b['epoch'] ? max( $b['ceiling'], (int) ( $last['ceiling'] ?? 0 ) ) : $b['ceiling'];
			$seen[ $key ] = [ 'epoch'=>$b['epoch'], 'ceiling'=>$ceiling ];
			if ( $seen === $raw || ( ! is_array( $raw ) ? add_user_meta( get_current_user_id(), self::user_meta_key( self::SEEN ), $seen, true ) : update_user_meta( get_current_user_id(), self::user_meta_key( self::SEEN ), $seen, $raw ) ) ) return Task_Workspace_UI::response( [ 'seen'=>true ] );
		}
		return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_activity_busy', 'The badge could not be updated. Refresh to try again.' ), 409 );
	}
}
