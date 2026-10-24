<?php
namespace DSA\Access;

use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Opt-in React team surface over the existing team authority; no new storage. */
final class Team_App_Service {
	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }
	public function register(): void {
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( is_admin() ) add_action( 'load-toplevel_page_' . Team_Workspace_Service::PAGE, [ $this, 'document' ] );
	}
	public function routes(): void {
		if ( ! self::enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/team', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'listing' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'create' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/team/(?P<id>[1-9][0-9]*)', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'save' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		if ( ! self::enabled() || ! is_user_logged_in() || ! current_user_can( 'kiwe_manage_team' ) || ! in_array( $request->get_method(), [ 'GET', 'POST' ], true ) ) return false;
		// Require the private dashboard's WordPress nonce on reads as well as writes.
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}
	private function response( $value ): \WP_REST_Response {
		if ( is_wp_error( $value ) ) return Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? 400 ) );
		return Task_Workspace_UI::response( $value );
	}
	private function denied(): \WP_REST_Response {
		return $this->response( new \WP_Error( 'kiwe_team_forbidden', __( 'Team management is unavailable. Sign in again or return to your workspace.', 'dsa' ), [ 'status'=>403 ] ) );
	}
	public function listing( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->denied();
		$service = new Team_Workspace_Service();
		$list = $service->listing( $request->get_query_params() );
		$people = [];
		foreach ( $list['users'] as $user ) {
			$person = $service->member( (int) $user->ID );
			if ( ! is_wp_error( $person ) ) $people[] = $person;
		}
		$activity = Team_Workspace_Service::activity_map( array_column( $people, 'id' ) );
		foreach ( $people as &$person ) if ( isset( $person['security'] ) ) $person['security']['lastActivity'] = $activity[ $person['id'] ] ?? '';
		unset( $person );
		$account_policy = $service->account_policy();
		$roles = Team_Workspace_Service::role_catalog();
		return $this->response( [ 'people'=>$people, 'navigation'=>$list['navigation'], 'hasNext'=>$list['has_next'], 'canAccounts'=>current_user_can( 'list_users' ) && current_user_can( 'edit_users' ), 'accountPolicy'=>$account_policy, 'roleChoices'=>$roles, 'commerce'=>class_exists( 'WC_Customer' ) ] );
	}
	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->denied();
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || 'invite' !== ( $body['operation'] ?? '' ) ) return $this->response( new \WP_Error( 'kiwe_account_invite', __( 'Choose the account invitation task.', 'dsa' ), [ 'status'=>400 ] ) );
		return $this->response( ( new Team_Workspace_Service() )->create_account( $body ) );
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->denied();
		$query = $request->get_query_params();
		if ( isset( $query['account_task'] ) ) {
			if ( 'remove' !== $query['account_task'] ) return $this->response( new \WP_Error( 'kiwe_account_task', 'Choose an available account task.', [ 'status'=>400 ] ) );
			$search = $query['recipient_search'] ?? '';
			if ( ! is_string( $search ) ) return $this->response( new \WP_Error( 'kiwe_account_task', 'Enter a recipient name to search.', [ 'status'=>400 ] ) );
			return $this->response( ( new Team_Workspace_Service() )->removal_plan( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), $search ) );
		}
		$section = $query['profile_section'] ?? null;
		if ( null !== $section ) return $this->response( ( new Team_Workspace_Service() )->account_section( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_string( $section ) ? $section : '' ) );
		$id = Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 );
		$person = ( new Team_Workspace_Service() )->member( $id );
		if ( ! is_wp_error( $person ) && isset( $person['security'] ) ) $person['security']['lastActivity'] = Team_Workspace_Service::activity_map( [ $id ] )[ $id ] ?? '';
		return $this->response( $person );
	}
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->denied();
		$id = Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 );
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = [];
		if ( 'profile' === ( $body['operation'] ?? '' ) ) return $this->response( ( new Team_Workspace_Service() )->save_account_section( $id, $body ) );
		if ( 'role' === ( $body['operation'] ?? '' ) ) return $this->response( ( new Team_Workspace_Service() )->save_account_role( $id, $body ) );
		if ( 'password_reset' === ( $body['operation'] ?? '' ) ) return $this->response( ( new Team_Workspace_Service() )->send_password_reset( $id, $body ) );
		if ( 'set_password' === ( $body['operation'] ?? '' ) ) return $this->response( ( new Team_Workspace_Service() )->set_password( $id, $body ) );
		if ( 'verify_email' === ( $body['operation'] ?? '' ) ) return $this->response( ( new Team_Workspace_Service() )->send_verification( $id, $body ) );
		if ( 'suspend' === ( $body['operation'] ?? '' ) ) return $this->response( ( new Team_Workspace_Service() )->set_suspension( $id, $body ) );
		if ( 'login_email' === ( $body['operation'] ?? '' ) ) return $this->response( ( new Team_Workspace_Service() )->save_login_email( $id, $body ) );
		if ( 'remove_account' === ( $body['operation'] ?? '' ) ) return $this->response( ( new Team_Workspace_Service() )->remove_account( $id, $body ) );
		$input = [];
		foreach ( [ 'expected', 'team_member', 'team_title', 'team_order' ] as $key ) $input[ $key ] = $body[ $key ] ?? null;
		$service = new Team_Workspace_Service();
		$result = $service->save_member( $id, $input );
		if ( is_wp_error( $result ) ) return $this->response( $result );
		$person = $service->member( $id );
		if ( is_wp_error( $person ) ) return $this->response( $person );
		// Never advance the UI to a fingerprint for unseen concurrent team edits.
		if ( $person['selected'] !== ( '1' === $input['team_member'] ) || $person['title'] !== sanitize_text_field( $input['team_title'] ) || $person['order'] !== (int) $input['team_order'] ) return $this->response( new \WP_Error( 'kiwe_team_unconfirmed', __( 'These details changed during the save. Reload and review this person.', 'dsa' ), [ 'status'=>409 ] ) );
		return $this->response( $person );
	}
	public function document(): void {
		if ( ! self::enabled() || ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() || ! current_user_can( 'kiwe_manage_team' ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) return;
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		wp_enqueue_style( 'kiwe-team-app', DSA_URL . 'assets/css/team-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_script( 'kiwe-team-app', DSA_URL . 'assets/js/team-app.js', [ 'wp-element' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-team-app', 'KIWE_TEAM_APP', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/team' ), 'nonce'=>wp_create_nonce( 'wp_rest' ), 'userGroupsEndpoint'=>rest_url( 'dsa/v1/workspace/user-groups' ), 'home'=>Workspace_Admin_Service::url(), 'initialAppFilter'=>'app_users'===($_GET['team_app']??'')?'app_users':'', 'initialAccount'=>is_scalar($_GET['team_account']??null)?max(0,(int)$_GET['team_account']):0, 'initialView'=>in_array( $_GET['team_view'] ?? '', [ 'accounts','customers','all','members' ], true ) ? $_GET['team_view'] : 'all', ...Workspace_Chrome_Service::projection() ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Your team · Kiwe', 'dsa' ); ?></title><?php wp_print_styles( 'kiwe-team-app' ); ?></head><body class="kiwe-team-app"><div id="kiwe-team-app"><p><?php esc_html_e( 'Opening your team…', 'dsa' ); ?></p><noscript><?php esc_html_e( 'This experimental team screen needs JavaScript. Ask your site owner to switch off the experiment to restore the existing team editor.', 'dsa' ); ?></noscript></div><?php wp_print_scripts( [ 'wp-element', 'kiwe-team-app' ] ); ?></body></html><?php
		exit;
	}
}
