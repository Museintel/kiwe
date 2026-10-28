<?php

namespace DSA\Access;

use DSA\Notifications\Admin_Event_Notification_Service;
use DSA\Notifications\Notification_Preference_Service;
use DSA\Notifications\Notification_Campaign_Service;
use DSA\Notifications\New_Post_Notification_Service;
use DSA\Utilities\Origin_Checker;
use DSA\Site\Site_Identity_Service;
use DSA\Design\Seam_Token_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native React adapter for the shared notification inbox and preferences. */
final class Notification_App_Service {
	public const PAGE = 'kiwe-notifications';
	private Notification_Preference_Service $preferences;
	private Admin_Event_Notification_Service $notifications;
	private Notification_Campaign_Service $campaigns;
	private ?New_Post_Notification_Service $post_alerts;
	private ?\DSA\Commerce\Abandoned_Cart_Service $carts;

	public function __construct( Notification_Preference_Service $preferences, Admin_Event_Notification_Service $notifications, Notification_Campaign_Service $campaigns, ?New_Post_Notification_Service $post_alerts = null, ?\DSA\Commerce\Abandoned_Cart_Service $carts = null ) {
		$this->preferences = $preferences;
		$this->notifications = $notifications;
		$this->campaigns = $campaigns;
		$this->post_alerts = $post_alerts;
		$this->carts = $carts;
	}

	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }

	public function register(): void {
		if ( ! self::enabled() ) return;
		( new Campaign_Workspace_Service( $this->preferences, $this->campaigns, $this->post_alerts, $this->carts ) )->register();
		if ( $this->carts ) ( new Checkout_Policy_Workspace_Service( $this->carts ) )->register();
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( is_admin() ) add_action( 'load-toplevel_page_' . self::PAGE, [ $this, 'document' ], 1 );
	}

	private function can_use(): bool { return is_user_logged_in() && current_user_can( 'read' ) && current_user_can( 'kiwe_use_notifications' ) && ! Team_Workspace_Service::suspended( get_current_user_id() ) && ( ! is_multisite() || is_user_member_of_blog( get_current_user_id() ) ); }

	public function permission( \WP_REST_Request $request ): bool {
		if ( ! self::enabled() || ! $this->can_use() || ! in_array( $request->get_method(), [ 'GET', 'POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}
	public function campaign_permission( \WP_REST_Request $request ): bool {
		return $this->permission( $request ) && current_user_can( 'kiwe_manage_notification_policy' );
	}

	public function routes(): void {
		if ( ! self::enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/notifications', [ 'methods' => 'GET', 'callback' => [ $this, 'read' ], 'permission_callback' => [ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/notifications/history', [ 'methods' => 'GET', 'callback' => [ $this, 'history' ], 'permission_callback' => [ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/notifications/preferences', [ 'methods' => 'POST', 'callback' => [ $this, 'save' ], 'permission_callback' => [ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/notifications/seen', [ 'methods'=>'POST', 'callback'=>[ $this, 'seen' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/notifications/acknowledge', [ 'methods' => 'POST', 'callback' => [ $this, 'acknowledge' ], 'permission_callback' => [ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/notifications/site-emails', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'read_site_emails' ], 'permission_callback' => [ $this, 'permission' ] ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'save_site_emails' ], 'permission_callback' => [ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/notifications/campaigns', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read_campaigns' ], 'permission_callback'=>[ $this, 'campaign_permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'start_campaign' ], 'permission_callback'=>[ $this, 'campaign_permission' ] ],
		] );
	}

	private function response( $value, int $status = 200 ): \WP_REST_Response {
		if ( is_wp_error( $value ) ) return Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? $status ) );
		return Task_Workspace_UI::response( $value, $status );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_notifications_forbidden', __( 'Notifications are unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		$snapshot = $this->preferences->workspace_snapshot();
		$snapshot['config']['canManageSiteEmails'] = WordPress_Email_Settings::allowed();
		$snapshot['config']['canManageCampaigns'] = current_user_can( 'kiwe_manage_notification_policy' );
		$snapshot['notifications'] = $this->notifications->workspace_inbox( array_column( $snapshot['config']['topics'], 'id' ) );
		$snapshot['config']['inboxUnreadCount'] = Admin_Event_Notification_Service::unread_count();
		$snapshot['visitIds'] = $this->notifications->visit_ids();
		return $this->response( $snapshot );
	}

	public function read_campaigns( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->campaign_permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_campaign_forbidden', 'Campaigns are unavailable for your account.', [ 'status'=>403 ] ), 403 );
		$channel = sanitize_key( (string) ( $request->get_param( 'channel' ) ?: 'email' ) );
		if ( ! in_array( $channel, [ 'email','whatsapp','sms','app' ], true ) ) return $this->response( new \WP_Error( 'kiwe_campaign_channel', 'Choose a valid channel.', [ 'status'=>400 ] ), 400 );
		return $this->response( [ 'preview'=>$this->campaigns->preview( $channel ), 'delivery'=>$this->campaigns->status(), 'postAlerts'=>$this->post_alerts ? [ 'audience'=>$this->preferences->post_alert_summary(), 'delivery'=>$this->post_alerts->status() ] : null ] );
	}

	public function start_campaign( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->campaign_permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_campaign_forbidden', 'Campaigns are unavailable for your account.', [ 'status'=>403 ] ), 403 );
		$body = $request->get_json_params();
		if ( is_array( $body ) && 'resume' === ( $body['operation'] ?? '' ) && is_string( $body['jobId'] ?? null ) ) return $this->response( $this->campaigns->resume( $body['jobId'] ) );
		if ( is_array( $body ) && 'resume_post' === ( $body['operation'] ?? '' ) && $this->post_alerts && is_numeric( $body['postId'] ?? null ) ) return $this->response( $this->post_alerts->resume( absint( $body['postId'] ) ) );
		if ( ! is_array( $body ) || true !== ( $body['confirmed'] ?? null ) || ! is_string( $body['channel'] ?? null ) || ! is_string( $body['subject'] ?? null ) || ! is_string( $body['message'] ?? null ) || ! is_string( $body['expected'] ?? null ) ) return $this->response( new \WP_Error( 'kiwe_campaign_input', 'Review the audience and confirm this campaign.', [ 'status'=>400 ] ), 400 );
		return $this->response( $this->campaigns->start( $body['channel'], $body['subject'], $body['message'], $body['expected'] ) );
	}

	public function history( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_notifications_forbidden', __( 'Notification history is unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		$snapshot = $this->preferences->workspace_snapshot();
		$page = max( 1, min( 2, absint( $request->get_param( 'page' ) ?: 1 ) ) );
		return $this->response( $this->notifications->workspace_history( array_column( $snapshot['config']['topics'], 'id' ), $page ) );
	}

	public function read_site_emails( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_notifications_forbidden', __( 'Site email settings are unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		return $this->response( ( new WordPress_Email_Settings() )->read() );
	}

	public function save_site_emails( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_notifications_forbidden', __( 'Site email settings are unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		$body = $request->get_json_params();
		return $this->response( ( new WordPress_Email_Settings() )->save( is_array( $body ) ? $body : [] ) );
	}

	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_notifications_forbidden', __( 'Notification preferences are unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		$body = $request->get_json_params(); $body = is_array( $body ) ? $body : [];
		return $this->response( $this->preferences->save_workspace( $body ) );
	}

	public function seen( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_notifications_forbidden', 'Your visit could not be confirmed.', [ 'status'=>403 ] ), 403 );
		$body = $request->get_json_params();
		$ok = is_array( $body ) && is_array( $body['ids'] ?? null ) && $this->notifications->acknowledge_visit( $body['ids'] );
		return $ok ? $this->response( [ 'seen'=>true, 'unreadCount'=>Admin_Event_Notification_Service::unread_count() ] ) : $this->response( new \WP_Error( 'kiwe_notifications_changed', 'Your badge could not be updated. Refresh to try again.', [ 'status'=>409 ] ), 409 );
	}

	public function acknowledge( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( new \WP_Error( 'kiwe_notifications_forbidden', __( 'Notifications are unavailable for your account.', 'dsa' ), [ 'status' => 403 ] ), 403 );
		$body = $request->get_json_params();
		$id = is_array( $body ) && is_string( $body['id'] ?? null ) ? $body['id'] : '';
		$snapshot = $this->preferences->workspace_snapshot();
		$visible = $this->notifications->workspace_inbox( array_column( $snapshot['config']['topics'], 'id' ) );
		if ( ! in_array( $id, array_column( $visible, 'id' ), true ) ) return $this->response( new \WP_Error( 'kiwe_notification_missing', __( 'This notification is no longer in your inbox. Refresh the inbox.', 'dsa' ), [ 'status'=>409 ] ) );
		$ok = $this->notifications->acknowledge_current_user( $id );
		return $ok ? $this->response( [ 'acknowledged'=>true,'unreadCount'=>Admin_Event_Notification_Service::unread_count() ] ) : $this->response( new \WP_Error( 'kiwe_notification_changed', __( 'The inbox changed. Refresh before trying again.', 'dsa' ), [ 'status'=>409 ] ) );
	}

	public function document(): void {
		if ( ! self::enabled() || ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! $this->can_use() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) ) return;
		if ( \DSA\Apps\Required_App_Service::allowed( 'security' ) && '1' === ( $_GET['notification_settings'] ?? '' ) ) {
			wp_safe_redirect( \DSA\Apps\Required_App_Service::url( 'security' ) ); exit;
		}
		nocache_headers(); header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true ); header( 'X-Robots-Tag: noindex, nofollow', true ); header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		$admin_notices = Workspace_Notice_UI::capture_admin_notices();
		$notice_count = Workspace_Notice_UI::notice_count( $admin_notices );
		wp_enqueue_style( 'kiwe-notification-app', DSA_URL . 'assets/css/notification-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_style( 'kiwe-notification-notices', DSA_URL . 'assets/css/workspace-notices.css', [ 'kiwe-notification-app' ], DSA_VERSION );
		wp_enqueue_script( 'kiwe-notification-app', DSA_URL . 'assets/js/notification-app.js', [ 'wp-element' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-notification-app', 'KIWE_NOTIFICATION_APP', [ 'endpoint' => rest_url( 'dsa/v1/workspace/notifications' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'home' => Workspace_Admin_Service::url(), 'campaignEndpoint'=>rest_url( 'dsa/v1/workspace/campaigns' ), 'userGroupsEndpoint'=>rest_url( 'dsa/v1/workspace/user-groups' ), 'campaignUrl'=>admin_url( 'admin.php?page=kiwe-notifications&view=campaigns' ), ...Workspace_Chrome_Service::projection( $notice_count ), 'policyUrl'=>\DSA\Apps\Required_App_Service::allowed( 'security' ) ? \DSA\Apps\Required_App_Service::url( 'security' ) : '' ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( __( 'Notifications', 'dsa' ) . ' · ' . get_bloginfo( 'name' ) ); ?></title><?php wp_print_styles( [ 'kiwe-notification-app', 'kiwe-notification-notices' ] ); ?></head><body class="kiwe-notification-app"><?php if ( $notice_count ) : ?><section class="wf-system-notices" id="website-alerts" aria-label="<?php esc_attr_e( 'Website alerts', 'dsa' ); ?>" tabindex="-1"><div class="wf-system-notices-head"><h2><?php echo esc_html( sprintf( _n( '%d website alert', '%d website alerts', $notice_count, 'dsa' ), $notice_count ) ); ?></h2><a class="wf-system-notices-close" href="#work-main"><?php esc_html_e( 'Close alerts', 'dsa' ); ?></a></div><?php echo $admin_notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted admin_notices callbacks, as in wp-admin. ?></section><?php endif; ?><div id="kiwe-notification-app"><p><?php esc_html_e( 'Opening your notifications…', 'dsa' ); ?></p><noscript><?php esc_html_e( 'This workspace needs JavaScript. Switch off the experiment to restore the native notification screen.', 'dsa' ); ?></noscript></div><?php wp_print_scripts( [ 'wp-element', 'kiwe-notification-app' ] ); ?></body></html><?php exit;
	}
}
