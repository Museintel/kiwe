<?php

namespace DSA\Access;

use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Onboarding\User_Profile_Service;
use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\Secure\SecureTrack_Event_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** A bounded public-team editor over existing WordPress users and Kiwe user metadata. */
final class Team_Workspace_Service {

	public const PAGE = 'kiwe-team';
	public const ACTION = 'kiwe_team_save';
	private const PAGE_SIZE = 12;
	private const MAX_PAGE = 10000;
	private const STAFF_CAPABILITIES = [ 'manage_options', 'edit_posts', 'edit_pages', 'edit_products', 'manage_woocommerce' ];
	private const ACCOUNT_ROLES = [ 'subscriber', 'customer', 'author', 'editor', 'shop_manager', 'kiwe_web_designer', 'administrator' ];
	private const MANAGEABLE_ACCOUNT_ROLES = [ 'subscriber', 'kiwe_user', 'customer', 'author', 'editor', 'contributor', 'shop_manager', 'kiwe_web_designer', 'administrator' ];
	public const SUSPENDED_META = '_kiwe_account_suspended';
	private const ACCOUNT_ROLE_DESCRIPTIONS = [
		'subscriber'    => 'Can sign in and manage their own profile.',
		'customer'      => 'Can use the customer account and order history.',
		'author'        => 'Can write, publish and manage their own posts.',
		'editor'        => 'Can review and manage posts from every writer.',
		'shop_manager'  => 'Can manage products, orders and store tasks.',
		'kiwe_web_designer' => 'Can design pages and native Bricks templates.',
		'administrator' => 'Can manage the client workspace and business settings.',
	];

	public function register(): void {
		if ( defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE ) ( new Team_App_Service() )->register();
		add_action( 'admin_menu', [ $this, 'menu' ], 32 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_save' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( $args, [ 'page'=>self::PAGE ] ), admin_url( 'admin.php' ) );
	}

	public function menu(): void {
		if ( ! current_user_can( 'kiwe_manage_team' ) ) return;
		add_menu_page( __( 'Teams', 'dsa' ), __( 'Teams', 'dsa' ), 'kiwe_manage_team', self::PAGE, [ $this, 'render' ], 'dashicons-groups', 32 );
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook || ! current_user_can( 'kiwe_manage_team' ) ) return;
		Task_Workspace_UI::native_form_assets();
		wp_enqueue_style( 'kiwe-team-workspace', DSA_URL . 'assets/css/team-workspace.css', [ 'kiwe-native-forms' ], DSA_VERSION );
	}

	private function can_manage_member( int $user_id ): bool {
		if ( ! current_user_can( 'kiwe_manage_team' ) || $user_id < 1 || ! get_userdata( $user_id ) ) return false;
		// Keep this explicit even if an integration replaces WordPress's edit_user mapping.
		if ( Site_Owner_Service::is_owner( $user_id ) && ! Site_Owner_Service::is_owner() ) return false;
		return current_user_can( 'edit_user', $user_id ) && User_Profile_Service::eligible( $user_id );
	}
	private function can_manage_account( int $id ): bool {
		return self::can_review_account_for( get_current_user_id(), $id );
	}
	/** The same object boundary protects account cards and private arrival notifications. */
	public static function can_review_account_for( int $actor, int $id ): bool {
		if ( $actor < 1 || ! user_can( $actor, 'kiwe_manage_team' ) || ! user_can( $actor, 'list_users' ) || ! user_can( $actor, 'edit_users' ) || ! get_userdata( $id ) ) return false;
		if ( is_multisite() && ! is_user_member_of_blog( $id ) ) return false;
		if ( Site_Owner_Service::is_owner( $id ) && ! Site_Owner_Service::is_owner( $actor ) ) return false;
		return user_can( $actor, 'edit_user', $id );
	}

	/** Normalize navigation independently of submitted metadata or arbitrary redirect URLs. */
	public static function role_catalog(): array {
		if ( ! current_user_can( 'list_users' ) ) return [];
		$counts = count_users( 'time',get_current_blog_id() )['avail_roles'] ?? [];
		$owner = ! Site_Owner_Service::is_owner() && Site_Owner_Service::owner_id() ? get_userdata( Site_Owner_Service::owner_id() ) : null;
		$roles=[]; foreach ( wp_roles()->roles as $id=>$definition ) {
			if ( 'kiwe_super_admin' === $id && ! Site_Owner_Service::is_owner() ) continue;
			$count=max(0,(int)($counts[$id]??0)-( $owner && in_array($id,$owner->roles,true) ? 1:0 ));
			$roles[]=[ 'id'=>$id,'label'=>translate_user_role($definition['name']),'count'=>$count ];
		}
		return $roles;
	}
	private function navigation( array $input ): array {
		$search = is_string( $input['team_search'] ?? null ) ? sanitize_text_field( $input['team_search'] ) : '';
		$search = wp_html_excerpt( str_replace( '*', '', $search ), 80, '' );
		$page = is_scalar( $input['team_page'] ?? null ) ? (int) $input['team_page'] : 1;
		$sort = in_array( $input['team_sort'] ?? '', [ 'name_asc','name_desc','joined_desc','joined_asc' ], true ) ? $input['team_sort'] : ( in_array( $input['team_view'] ?? '', [ 'accounts','customers' ], true ) ? 'joined_desc' : 'name_asc' );
		return [
			'team_view' => in_array( $input['team_view'] ?? '', [ 'members','accounts','customers' ], true ) ? $input['team_view'] : 'all',
			'team_search' => $search,
			'team_page' => max( 1, min( self::MAX_PAGE, $page ) ),
			'team_sort' => $sort,
			'team_role' => is_string( $input['team_role'] ?? null ) && isset( wp_roles()->roles[ $input['team_role'] ] ) ? $input['team_role'] : '',
			'team_app' => 'app_users' === ($input['team_app'] ?? '') && class_exists(App_Workspace_Service::class) && App_Workspace_Service::audience_allowed() ? 'app_users' : '',
			'team_account' => is_scalar($input['team_account']??null) ? max(0,(int)$input['team_account']) : 0,
		];
	}

	/** One bounded query, then effective permission checks; never load the complete user catalog. */
	public function listing( array $input = [] ): array {
		$nav = $this->navigation( $input );
		$result = [ 'navigation'=>$nav, 'users'=>[], 'has_next'=>false ];
		if ( ! current_user_can( 'kiwe_manage_team' ) ) return $result;
		$args = [
			'number' => self::PAGE_SIZE + 1,
			'offset' => ( $nav['team_page'] - 1 ) * self::PAGE_SIZE,
			'count_total' => false,
			'orderby' => str_starts_with( $nav['team_sort'], 'joined_' ) ? [ 'user_registered'=>str_ends_with( $nav['team_sort'], '_asc' ) ? 'ASC' : 'DESC', 'ID'=>str_ends_with( $nav['team_sort'], '_asc' ) ? 'ASC' : 'DESC' ] : [ 'display_name'=>str_ends_with( $nav['team_sort'], '_asc' ) ? 'ASC' : 'DESC', 'ID'=>'ASC' ],
			'capability__in' => self::STAFF_CAPABILITIES,
			'blog_id' => get_current_blog_id(),
		];
		if ( Site_Owner_Service::owner_id() && ! Site_Owner_Service::is_owner() ) $args['exclude'] = [ Site_Owner_Service::owner_id() ];
		$accounts = in_array( $nav['team_view'], [ 'accounts','customers' ], true );
		if ( $accounts ) {
			if ( ! current_user_can( 'list_users' ) || ! current_user_can( 'edit_users' ) || ( 'customers' === $nav['team_view'] && ! ( \DSA\Apps\Store_App_Service::enabled() && class_exists( 'WC_Customer' ) ) ) ) return $result;
			unset( $args['capability__in'] );
			if ( 'customers' === $nav['team_view'] ) $args['role__in'] = [ 'customer' ];
			if ( $nav['team_role'] ) $args['role'] = $nav['team_role'];
			if ( $nav['team_app'] ) $args['meta_query'] = [ [ 'key'=>\DSA\PWA\App_Activity_Service::meta_key(\DSA\PWA\App_Activity_Service::FIRST_USE_META), 'compare'=>'EXISTS' ] ];
		}
		if ( '' !== $nav['team_search'] ) {
			$args['search'] = '*' . $nav['team_search'] . '*';
			$args['search_columns'] = [ 'display_name' ];
		}
		if ( 'members' === $nav['team_view'] ) {
			$args['meta_key'] = Design_Context_Profile_Service::USER_META_TEAM_MEMBER;
			$args['meta_value'] = '1';
		}
		$users = get_users( $args );
		$result['has_next'] = count( $users ) > self::PAGE_SIZE && $nav['team_page'] < self::MAX_PAGE;
		foreach ( array_slice( $users, 0, self::PAGE_SIZE ) as $user ) {
			if ( ! $user instanceof \WP_User || ! ( $accounts ? $this->can_manage_account( (int) $user->ID ) : $this->can_manage_member( (int) $user->ID ) ) ) continue;
			if ( 'members' === $nav['team_view'] && ! User_Profile_Service::is_team_member( (int) $user->ID ) ) continue;
			$result['users'][] = $user;
		}
		return $result;
	}

	public static function fingerprint( int $id ): string {
		return hash( 'sha256', wp_json_encode( self::state( $id ) ) );
	}

	private static function state( int $id ): array {
		return [ $id, (string) get_user_meta( $id, Design_Context_Profile_Service::USER_META_TEAM_MEMBER, true ), (string) get_user_meta( $id, Design_Context_Profile_Service::USER_META_TEAM_TITLE, true ), (string) get_user_meta( $id, Design_Context_Profile_Service::USER_META_TEAM_ORDER, true ) ];
	}

	/** Explicit private card projection. Never serialize WP_User or arbitrary user metadata. */
	public function member( int $id ) {
		if ( ! $this->can_manage_member( $id ) && ! $this->can_manage_account( $id ) ) return new \WP_Error( 'kiwe_team_forbidden', __( 'This person is unavailable for team management.', 'dsa' ), [ 'status'=>403 ] );
		$user = get_userdata( $id );
		$state = self::state( $id );
		$roles = [];
		foreach ( $user->roles as $role ) {
			$definition = wp_roles()->roles[ $role ] ?? null;
			if ( $definition ) $roles[] = [ 'id'=>$role, 'label'=>translate_user_role( $definition['name'] ), 'source'=>in_array( $role, [ 'customer', 'shop_manager' ], true ) ? 'WooCommerce' : 'WordPress' ];
		}
		$public = User_Profile_Service::public_fields( $id );
		$profile = [
			'wordpress' => [
				'firstName'    => sanitize_text_field( (string) get_user_meta( $id, 'first_name', true ) ),
				'lastName'     => sanitize_text_field( (string) get_user_meta( $id, 'last_name', true ) ),
				'nickname'     => sanitize_text_field( (string) get_user_meta( $id, 'nickname', true ) ),
				'email'        => sanitize_email( (string) ( $user->user_email ?? '' ) ),
				'website'      => $public['website'],
				'bio'          => $public['bio'],
				'locale'       => function_exists( 'get_user_locale' ) ? sanitize_text_field( (string) get_user_locale( $id ) ) : sanitize_text_field( (string) ( $user->locale ?? get_locale() ) ),
				'username'     => sanitize_user( (string) ( $user->user_login ?? '' ), true ),
				'registered'   => sanitize_text_field( (string) ( $user->user_registered ?? '' ) ),
			],
			'kiwe' => [
				'teamTitle'   => (string) $state[2],
				'teamMember'  => '1' === $state[1],
				'teamOrder'   => max( 0, min( 9999, (int) $state[3] ) ),
				'founderTitle'=> sanitize_text_field( (string) get_user_meta( $id, Design_Context_Profile_Service::USER_META_FOUNDER_TITLE, true ) ),
				'linkedin'    => $public['linkedin'],
				'facebook'    => $public['facebook'],
				'avatarUrl'   => ( $avatar_id = absint( get_user_meta( $id, Design_Context_Profile_Service::USER_META_AVATAR_ID, true ) ) ) ? esc_url_raw( (string) wp_get_attachment_url( $avatar_id ) ) : '',
			],
			'woocommerce' => [
				'billing'  => self::commerce_address( $id, 'billing' ),
				'shipping' => self::commerce_address( $id, 'shipping' ),
			],
		];
		$result = [ 'id'=>$id, 'name'=>$user->display_name, 'roles'=>$roles, 'selected'=>'1' === $state[1], 'title'=>$state[2], 'order'=>max( 0, min( 9999, (int) $state[3] ) ), 'profile'=>$profile, 'account'=>$this->account_control( $id ), 'expected'=>hash( 'sha256', wp_json_encode( $state ) ) ];
		if ( $this->can_manage_account( $id ) ) { $result['security'] = $this->security_summary( $id ); if(class_exists(\DSA\PWA\App_Activity_Service::class))$result['app'] = \DSA\PWA\App_Activity_Service::user_summary($id); }
		if ( ! User_Profile_Service::eligible( $id ) ) { $result['canTeam'] = false; $result['selected'] = false; }
		return $result;
	}
	private function security_summary( int $id ): array {
		$phonekey = PhoneKey_Bridge::security_completion( $id );
		$joined_by_key = (bool) get_user_meta( $id, 'pk_created_by_phonekey', true );
		$join_source = $joined_by_key ? ( get_user_meta( $id, 'pk_phone_last4', true ) ? 'PhoneKey phone' : 'PhoneKey email' ) : ( get_user_meta( $id, '_kiwe_account_join_source', true ) === 'workspace_invite' ? 'Kiwe invitation' : 'WordPress or import' );
		return [
			'joinSource'=>$join_source,
			'verification'=>$phonekey ? (string) $phonekey['state'] : 'unavailable',
			'emailVerified'=>$phonekey ? (bool) $phonekey['emailVerified'] : null,
			'phoneVerified'=>$phonekey ? (bool) $phonekey['phoneVerified'] : null,
			'passkeyEnrolled'=>$phonekey ? (bool) $phonekey['passkeyEnrolled'] : null,
			'suspended'=>self::suspended( $id ),
			'lastActivity'=>'',
		];
	}
	public static function suspended( int $id ): bool { return $id > 0 && '1' === (string) get_user_meta( $id, self::SUSPENDED_META, true ); }
	/** Reuse SecureTrack's optional session clock without copying its telemetry. */
	public static function activity_map( array $ids ): array {
		return SecureTrack_Event_Service::user_last_activity_map( $ids );
	}

	/** Read the installed Woo customer object when available, with a metadata fallback for non-commerce sites. */
	private static function commerce_address( int $user_id, string $type ): array {
		if ( ! \DSA\Apps\Store_App_Service::enabled() ) return [];
		$fields = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ];
		$data = [];
		$customer = null;
		if ( ( \DSA\Apps\Store_App_Service::enabled() && class_exists( 'WC_Customer' ) ) ) {
			try { $customer = new \WC_Customer( $user_id ); } catch ( \Throwable $e ) { $customer = null; }
		}
		foreach ( $fields as $field ) {
			$value = null;
			$getter = 'get_' . $type . '_' . $field;
			if ( $customer instanceof \WC_Customer && is_callable( [ $customer, $getter ] ) ) $value = $customer->{$getter}();
			if ( null === $value ) $value = get_user_meta( $user_id, $type . '_' . $field, true );
			$data[ $field ] = 'email' === $field ? sanitize_email( (string) $value ) : sanitize_text_field( (string) $value );
		}
		return $data;
	}

	private function account_error( string $message, int $status = 400 ): \WP_Error { return new \WP_Error( 'kiwe_account_profile', $message, [ 'status'=>$status ] ); }

	/** WordPress roles remain authoritative; Kiwe exposes only the bounded client roles. */
	public function account_policy(): array {
		$can_accounts = current_user_can( 'kiwe_manage_team' ) && current_user_can( 'list_users' ) && current_user_can( 'edit_users' );
		$roles = [];
		if ( $can_accounts ) {
			$registered = (array) wp_roles()->roles;
			foreach ( self::ACCOUNT_ROLES as $role ) {
				if ( in_array( $role, [ 'customer','shop_manager' ], true ) && ! ( \DSA\Apps\Store_App_Service::enabled() && class_exists( 'WC_Customer' ) ) ) continue;
				if ( ! isset( $registered[ $role ] ) ) continue;
				$roles[] = [
					'id'          => $role,
					'label'       => 'administrator' === $role ? __( 'Client administrator', 'dsa' ) : translate_user_role( (string) $registered[ $role ]['name'] ),
					'description' => __( self::ACCOUNT_ROLE_DESCRIPTIONS[ $role ], 'dsa' ),
				];
			}
		}
		return [
			'canCreate'      => $can_accounts && current_user_can( 'create_users' ) && ! empty( $roles ),
			'canChangeRoles' => $can_accounts && current_user_can( 'promote_users' ) && ! empty( $roles ),
			'roles'          => $roles,
		];
	}

	private function role_allowed( string $role ): bool {
		foreach ( $this->account_policy()['roles'] as $choice ) if ( $choice['id'] === $role ) return true;
		return false;
	}

	public static function role_fingerprint( int $id ): string {
		$user = get_userdata( $id );
		return hash( 'sha256', wp_json_encode( [ $id, $user ? array_values( (array) $user->roles ) : [], Site_Owner_Service::owner_id() ] ) );
	}

	/** Recovery and login-address edits operate only on ordinary, editable native accounts. */
	private function can_manage_identity( int $id ): bool {
		if ( ! $this->can_manage_account( $id ) || $id === get_current_user_id() || Site_Owner_Service::is_owner( $id ) ) return false;
		$user = get_userdata( $id );
		$roles = $user ? array_values( (array) $user->roles ) : [];
		if ( 1 !== count( $roles ) || ! in_array( $roles[0], self::MANAGEABLE_ACCOUNT_ROLES, true ) || 'kiwe_user' === $roles[0] ) return false;
		return 'administrator' !== $roles[0] || Site_Owner_Service::is_owner();
	}

	/** Network-wide identity removal needs its own operator workflow. */
	private function can_remove_account( int $id ): bool {
		if ( is_multisite() || ! $this->can_manage_account( $id ) || $id === get_current_user_id() || Site_Owner_Service::is_owner( $id ) ) return false;
		if ( ! current_user_can( 'delete_users' ) || ! current_user_can( 'delete_user', $id ) ) return false;
		$user = get_userdata( $id );
		$roles = $user ? array_values( (array) $user->roles ) : [];
		if ( 1 !== count( $roles ) || ! in_array( $roles[0], self::MANAGEABLE_ACCOUNT_ROLES, true ) || 'kiwe_user' === $roles[0] ) return false;
		return 'administrator' !== $roles[0] || Site_Owner_Service::is_owner();
	}

	private function recipient_allowed( int $target, int $recipient ): bool {
		if ( $recipient < 1 || $recipient === $target || ! current_user_can( 'edit_user', $recipient ) ) return false;
		$user = get_userdata( $recipient );
		if ( ! $user || ( is_multisite() && ! is_user_member_of_blog( $recipient ) ) ) return false;
		$roles = array_values( (array) $user->roles );
		return [ 'administrator' ] === $roles || ( Site_Owner_Service::is_owner() && Site_Owner_Service::is_owner( $recipient ) );
	}

	private function account_has_orders( int $id ) {
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! function_exists( 'wc_get_orders' ) ) return false;
		try { $orders = wc_get_orders( [ 'customer_id'=>$id, 'limit'=>1, 'return'=>'ids' ] ); }
		catch ( \Throwable $error ) { return $this->account_error( 'Order ownership could not be checked. No account was removed.', 409 ); }
		if ( ! is_array( $orders ) ) return $this->account_error( 'Order ownership could not be checked. No account was removed.', 409 );
		return ! empty( $orders );
	}

	/** On-demand, bounded choices. No full user inventory is sent to the browser. */
	public function removal_plan( int $id, string $search = '' ) {
		if ( ! $this->can_remove_account( $id ) ) return $this->account_error( 'Account removal is unavailable here. Network membership is managed by the technical owner.', 403 );
		$user = get_userdata( $id );
		$has_orders = $this->account_has_orders( $id );
		if ( is_wp_error( $has_orders ) ) return $has_orders;
		$search = wp_html_excerpt( str_replace( '*', '', sanitize_text_field( $search ) ), 80, '' );
		$choices = [];
		$add = function ( $candidate ) use ( $id, &$choices ): void {
			if ( ! $candidate instanceof \WP_User || ! $this->recipient_allowed( $id, (int) $candidate->ID ) || isset( $choices[ $candidate->ID ] ) || count( $choices ) >= self::PAGE_SIZE ) return;
			$choices[ $candidate->ID ] = [ 'id'=>(int) $candidate->ID, 'label'=>sanitize_text_field( (string) $candidate->display_name ) . ' (' . sanitize_user( (string) $candidate->user_login, true ) . ')' ];
		};
		$current = get_userdata( get_current_user_id() );
		if ( ! $has_orders && $current && ( '' === $search || stripos( $current->display_name . ' ' . $current->user_login, $search ) !== false ) ) $add( $current );
		if ( ! $has_orders ) {
			$args = [ 'role'=>'administrator', 'number'=>self::PAGE_SIZE, 'count_total'=>false, 'orderby'=>'display_name', 'order'=>'ASC', 'blog_id'=>get_current_blog_id() ];
			if ( '' !== $search ) { $args['search'] = '*' . $search . '*'; $args['search_columns'] = [ 'display_name','user_login' ]; }
			foreach ( get_users( $args ) as $candidate ) $add( $candidate );
		}
		return [
			'id'=>$id,
			'username'=>(string) $user->user_login,
			'name'=>sanitize_text_field( (string) $user->display_name ),
			'hasOrders'=>$has_orders,
			'blockedReason'=>$has_orders ? 'This customer has WooCommerce orders. Review order ownership and privacy before removing the account.' : '',
			'recipients'=>array_values( $choices ),
			'expected'=>self::identity_fingerprint( $id ),
		];
	}

	/** Always reassign WordPress content; never expose the core delete-content branch. */
	public function remove_account( int $id, array $input ) {
		if ( ! $this->can_remove_account( $id ) ) return $this->account_error( 'This account cannot be removed here.', 403 );
		if ( array_diff( array_keys( $input ), [ 'operation','expected','reassignTo','confirmLogin','confirmTransfer' ] ) ) return $this->account_error( 'Submit only the account-removal fields.' );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::identity_fingerprint( $id ), $input['expected'] ) ) return $this->account_error( 'This account changed. Reload its removal plan before continuing.', 409 );
		$user = get_userdata( $id );
		if ( ! is_string( $input['confirmLogin'] ?? null ) || $input['confirmLogin'] !== (string) $user->user_login || true !== ( $input['confirmTransfer'] ?? null ) ) return $this->account_error( 'Confirm the exact username and content transfer before removing this account.' );
		$recipient = $input['reassignTo'] ?? null;
		if ( ! is_int( $recipient ) || ! $this->recipient_allowed( $id, $recipient ) ) return $this->account_error( 'Choose an available client Administrator to receive the content.' );
		$has_orders = $this->account_has_orders( $id );
		if ( is_wp_error( $has_orders ) ) return $has_orders;
		if ( $has_orders ) return $this->account_error( 'WooCommerce orders now belong to this account. No account was removed.', 409 );
		if ( ! function_exists( 'wp_delete_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
		try { $removed = wp_delete_user( $id, $recipient ); }
		catch ( \Throwable $error ) { return $this->account_error( 'WordPress could not confirm account removal. Reload the directory before retrying.', 409 ); }
		if ( true !== $removed || get_userdata( $id ) ) return $this->account_error( 'WordPress could not confirm account removal. Reload the directory before retrying.', 409 );
		return [ 'removed'=>true, 'id'=>$id, 'reassignedTo'=>$recipient ];
	}

	public static function identity_fingerprint( int $id ): string {
		$user = get_userdata( $id );
		return hash( 'sha256', wp_json_encode( [ $id, $user ? (string) $user->user_email : '', $user ? array_values( (array) $user->roles ) : [], Site_Owner_Service::owner_id(), self::suspended( $id ), $user ? (string) $user->user_pass : '' ] ) );
	}

	private function can_change_account_role( int $id ): bool {
		if ( ! $this->can_manage_account( $id ) || ! current_user_can( 'promote_users' ) || ! current_user_can( 'promote_user', $id ) ) return false;
		if ( $id === get_current_user_id() || Site_Owner_Service::is_owner( $id ) ) return false;
		$user = get_userdata( $id );
		return $user && 1 === count( $user->roles ) && in_array( (string) reset( $user->roles ), self::MANAGEABLE_ACCOUNT_ROLES, true );
	}

	private function account_control( int $id ): array {
		$user = get_userdata( $id );
		$roles = $user ? array_values( (array) $user->roles ) : [];
		return [
			'canChangeRole' => $this->can_change_account_role( $id ),
			'canChangeEmail'=> $this->can_manage_identity( $id ),
			'canSendReset'  => $this->can_manage_identity( $id ),
			'canSetPassword'=> $this->can_manage_identity( $id ),
			'canVerify'     => $this->can_manage_identity( $id ) && PhoneKey_Bridge::can_send_email_verification(),
			'canSuspend'    => $this->can_manage_identity( $id ),
			'canRemove'     => $this->can_remove_account( $id ),
			'removalMode'   => is_multisite() ? 'network_owner' : 'single_site',
			'role'          => 1 === count( $roles ) ? sanitize_key( (string) $roles[0] ) : '',
			'expected'      => self::role_fingerprint( $id ),
			'identityExpected' => self::identity_fingerprint( $id ),
		];
	}

	/** Use WordPress's own recovery mail; never reveal a reset key or URL in the response. */
	public function send_verification( int $id, array $input ) {
		if ( ! $this->can_manage_identity( $id ) || ! PhoneKey_Bridge::can_send_email_verification() ) return $this->account_error( 'Verification is unavailable for this account.', 403 );
		if ( array_diff( array_keys( $input ), [ 'operation','expected' ] ) || ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::identity_fingerprint( $id ), $input['expected'] ) ) return $this->account_error( 'This account changed. Reload it before requesting verification.', 409 );
		if ( PhoneKey_Bridge::email_verified( $id ) ) return $this->account_error( 'This account email is already verified.', 409 );
		if ( get_transient( 'kiwe_account_verify_' . $id ) ) return $this->account_error( 'A verification link was recently requested. Wait before sending another.', 429 );
		$user = get_userdata( $id ); if ( ! $user || ! is_email( (string) $user->user_email ) ) return $this->account_error( 'This account needs a valid login email.', 409 );
		$sent = PhoneKey_Bridge::send_email_verification( $id, (string) $user->user_email );
		if ( empty( $sent['accepted'] ) ) return $this->account_error( 'The verification email was not accepted by site mail. Review delivery before retrying.', 409 );
		set_transient( 'kiwe_account_verify_' . $id, 1, 15 * MINUTE_IN_SECONDS );
		return [ 'notificationRequested'=>true, 'person'=>$this->member( $id ) ];
	}
	public function set_suspension( int $id, array $input ) {
		if ( ! $this->can_manage_identity( $id ) ) return $this->account_error( 'This account cannot be banned here.', 403 );
		if ( array_diff( array_keys( $input ), [ 'operation','expected','suspended' ] ) || ! is_bool( $input['suspended'] ?? null ) || ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::identity_fingerprint( $id ), $input['expected'] ) ) return $this->account_error( 'This account changed. Reload before changing access.', 409 );
		$blocked = $input['suspended'];
		if ( $blocked !== self::suspended( $id ) ) {
			$written = $blocked ? update_user_meta( $id, self::SUSPENDED_META, '1' ) : delete_user_meta( $id, self::SUSPENDED_META );
			if ( ! $written || self::suspended( $id ) !== $blocked ) return $this->account_error( 'The account access change could not be confirmed.', 409 );
			if ( $blocked ) \WP_Session_Tokens::get_instance( $id )->destroy_all();
			SecureTrack_Event_Service::log_account_action( $blocked ? 'account_banned' : 'account_unbanned', $id );
			do_action( 'kiwe_account_access_changed', $id, $blocked, get_current_user_id() );
		}
		return $this->member( $id );
	}
	/** Use WordPress's own recovery mail; never reveal a reset key or URL in the response. */
	public function send_password_reset( int $id, array $input ) {
		if ( ! $this->can_manage_identity( $id ) ) return $this->account_error( 'This account cannot receive a reset from this workspace.', 403 );
		if ( array_diff( array_keys( $input ), [ 'operation','expected' ] ) ) return $this->account_error( 'Submit only the password-reset task fields.' );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::identity_fingerprint( $id ), $input['expected'] ) ) return $this->account_error( 'The account identity changed. Reload this person before sending.', 409 );
		$limit = 'kiwe_account_reset_' . $id;
		if ( get_transient( $limit ) ) return $this->account_error( 'A reset was recently requested for this account. Wait before sending another.', 429 );
		$user = get_userdata( $id );
		if ( ! $user || ! is_email( (string) $user->user_email ) ) return $this->account_error( 'This account needs a valid login email before a reset can be sent.', 409 );
		try { $result = retrieve_password( (string) $user->user_email ); }
		catch ( \Throwable $error ) { return $this->account_error( 'WordPress could not request the reset email. Review site mail delivery.', 409 ); }
		if ( is_wp_error( $result ) || true !== $result ) return $this->account_error( 'WordPress could not request the reset email. Review site mail delivery.', 409 );
		set_transient( $limit, 1, 900 );
		$person = $this->member( $id );
		return is_wp_error( $person ) ? $person : [ 'person'=>$person, 'notificationRequested'=>true ];
	}

	/** An explicit administrator action; the secret is never returned or logged. */
	public function set_password( int $id, array $input ) {
		if ( ! $this->can_manage_identity( $id ) ) return $this->account_error( 'This account password cannot be changed here.', 403 );
		if ( array_diff( array_keys( $input ), [ 'operation','expected','password','confirmPassword' ] ) || ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::identity_fingerprint( $id ), $input['expected'] ) ) return $this->account_error( 'This account changed. Reload it before changing the password.', 409 );
		if ( ! is_string( $input['password'] ?? null ) || ! is_string( $input['confirmPassword'] ?? null ) || $input['password'] !== $input['confirmPassword'] || strlen( $input['password'] ) < 12 || strlen( $input['password'] ) > 128 || preg_match( '/[\x00-\x1f\x7f]/', $input['password'] ) ) return $this->account_error( 'Enter the same new password twice, using 12 to 128 characters without control characters.' );
		try { wp_set_password( $input['password'], $id ); }
		catch ( \Throwable $error ) { return $this->account_error( 'WordPress could not confirm the password change. Reload this account.', 409 ); }
		$after = get_userdata( $id );
		if ( ! $after || ! wp_check_password( $input['password'], (string) $after->user_pass, $id ) ) return $this->account_error( 'The password change could not be verified. Reload this account.', 409 );
		\WP_Session_Tokens::get_instance( $id )->destroy_all();
		delete_transient( 'kiwe_account_reset_' . $id );
		SecureTrack_Event_Service::log_account_action( 'account_password_changed', $id );
		return [ 'changed'=>true, 'person'=>$this->member( $id ) ];
	}

	/** The native user email is both login and recovery address; billing email stays separate. */
	public function save_login_email( int $id, array $input ) {
		if ( ! $this->can_manage_identity( $id ) ) return $this->account_error( 'This login email cannot be changed here.', 403 );
		if ( array_diff( array_keys( $input ), [ 'operation','expected','email','confirmEmail' ] ) ) return $this->account_error( 'Submit only the login-email task fields.' );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::identity_fingerprint( $id ), $input['expected'] ) ) return $this->account_error( 'The account identity changed. Reload this person before saving.', 409 );
		if ( ! is_string( $input['email'] ?? null ) || ! is_string( $input['confirmEmail'] ?? null ) || $input['email'] !== $input['confirmEmail'] ) return $this->account_error( 'Enter the same new login email twice.' );
		$email = sanitize_email( trim( $input['email'] ) );
		if ( ! $email || ! is_email( $email ) || strlen( $email ) > 254 ) return $this->account_error( 'Enter a valid login email.' );
		$user = get_userdata( $id );
		if ( 0 === strcasecmp( $email, (string) $user->user_email ) ) return $this->account_error( 'This account already uses that login email.' );
		$existing = email_exists( $email );
		if ( $existing && (int) $existing !== $id ) return $this->account_error( 'Another account already uses this email.', 409 );
		try { $saved = wp_update_user( wp_slash( [ 'ID'=>$id, 'user_email'=>$email ] ) ); }
		catch ( \Throwable $error ) { return $this->account_error( 'The login email could not be confirmed. Reload this person.', 409 ); }
		$after = get_userdata( $id );
		if ( is_wp_error( $saved ) || (int) $saved !== $id || ! $after || strcasecmp( $email, (string) $after->user_email ) ) return $this->account_error( 'The login email could not be confirmed. Reload this person.', 409 );
		delete_transient( 'kiwe_account_reset_' . $id );
		return $this->member( $id );
	}

	/** Create a native account with a generated secret; the password is never returned to the client. */
	public function create_account( array $input ) {
		$policy = $this->account_policy();
		if ( ! $policy['canCreate'] ) return $this->account_error( 'Account invitations are unavailable for this role.', 403 );
		if ( array_diff( array_keys( $input ), [ 'operation','email','firstName','lastName','role' ] ) ) return $this->account_error( 'Submit only the account invitation fields.' );
		foreach ( [ 'email','firstName','lastName','role' ] as $field ) if ( ! is_string( $input[ $field ] ?? null ) ) return $this->account_error( 'Complete the account invitation fields.' );
		$email = sanitize_email( $input['email'] );
		$first = sanitize_text_field( $input['firstName'] );
		$last = sanitize_text_field( $input['lastName'] );
		$role = sanitize_key( $input['role'] );
		if ( ! $email || ! is_email( $email ) || strlen( $email ) > 254 ) return $this->account_error( 'Enter a valid account email.' );
		if ( email_exists( $email ) ) return $this->account_error( 'An account already uses this email. Find that person instead.', 409 );
		if ( ! $this->role_allowed( $role ) ) return $this->account_error( 'Choose an available account role.' );
		foreach ( [ $first,$last ] as $name ) { $length = preg_match_all( '/./us', $name ); if ( false === $length || $length > 100 ) return $this->account_error( 'Use names of at most 100 characters.' ); }

		$local = strstr( $email, '@', true );
		$base = sanitize_user( strtolower( is_string( $local ) ? $local : '' ), true );
		if ( '' === $base ) $base = 'user';
		$login = $base;
		for ( $suffix = 2; username_exists( $login ) && $suffix < 1000; $suffix++ ) $login = substr( $base, 0, 54 ) . '-' . $suffix;
		if ( username_exists( $login ) ) return $this->account_error( 'A unique username could not be prepared. Ask the site owner for help.', 409 );
		$display = trim( $first . ' ' . $last );
		if ( '' === $display ) $display = $email;
		$id = wp_insert_user( wp_slash( [
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'first_name'   => $first,
			'last_name'    => $last,
			'nickname'     => $display,
			'display_name' => $display,
			'role'         => $role,
		] ) );
		if ( is_wp_error( $id ) || (int) $id < 1 ) return $this->account_error( is_wp_error( $id ) ? $id->get_error_message() : 'The account could not be created.', 409 );
		$id = (int) $id;
		$user = get_userdata( $id );
		if ( ! $user || sanitize_email( (string) $user->user_email ) !== $email || [ $role ] !== array_values( (array) $user->roles ) ) return $this->account_error( 'The account was created but its setup could not be confirmed. Reload the directory before continuing.', 409 );
		update_user_meta( $id, '_kiwe_account_join_source', 'workspace_invite' );

		$notification = false;
		try {
			if ( function_exists( 'wp_new_user_notification' ) ) { wp_new_user_notification( $id, null, 'user' ); $notification = true; }
		} catch ( \Throwable $error ) { $notification = false; }
		$person = $this->member( $id );
		if ( is_wp_error( $person ) ) return $this->account_error( 'The account exists but cannot be opened in this directory. Reload before continuing.', 409 );
		return [
			'person'                => $person,
			'login'                 => sanitize_user( (string) $user->user_login, true ),
			'notificationRequested' => $notification,
		];
	}

	/** Change one native role with optimistic state checking; ownership is never assignable here. */
	public function save_account_role( int $id, array $input ) {
		if ( ! $this->can_change_account_role( $id ) ) return $this->account_error( 'This account role cannot be changed here.', 403 );
		if ( array_diff( array_keys( $input ), [ 'operation','role','expected' ] ) ) return $this->account_error( 'Submit only the role change fields.' );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( self::role_fingerprint( $id ), $input['expected'] ) ) return $this->account_error( 'This account role changed. Reload the person before saving.', 409 );
		$role = is_string( $input['role'] ?? null ) ? sanitize_key( $input['role'] ) : '';
		if ( ! $this->role_allowed( $role ) ) return $this->account_error( 'Choose an available account role.' );
		$user = get_userdata( $id );
		if ( [ $role ] !== array_values( (array) $user->roles ) ) $user->set_role( $role );
		$after = get_userdata( $id );
		if ( ! $after || [ $role ] !== array_values( (array) $after->roles ) ) return $this->account_error( 'The role change could not be confirmed. Reload this person before retrying.', 409 );
		return $this->member( $id );
	}

	/** Private, explicit sections. Login identity, roles and plugin metadata are not writable here. */
	public function account_section( int $id, string $section ) {
		if ( ! $this->can_manage_account( $id ) ) return $this->account_error( 'This account is unavailable.', 403 );
		$user = get_userdata( $id ); $fields = []; $values = [];
		if ( 'name' === $section ) {
			$fields = [ 'display_name'=>'Public display name', 'first_name'=>'First name', 'last_name'=>'Last name', 'nickname'=>'Nickname', 'description'=>'Biography', 'user_url'=>'Website' ];
			foreach ( $fields as $key=>$label ) $values[$key] = (string) ( in_array( $key, [ 'display_name','description','user_url' ], true ) ? $user->{$key} : get_user_meta( $id, $key, true ) );
		} elseif ( 'public' === $section && User_Profile_Service::eligible( $id ) ) {
			$fields = [ Design_Context_Profile_Service::USER_META_FOUNDER_TITLE=>'Founder title', Design_Context_Profile_Service::USER_META_LINKEDIN=>'LinkedIn', 'facebook'=>'Facebook' ];
			foreach ( $fields as $key=>$label ) $values[$key] = (string) get_user_meta( $id, $key, true );
		} elseif ( in_array( $section, [ 'billing','shipping' ], true ) && ( \DSA\Apps\Store_App_Service::enabled() && class_exists( 'WC_Customer' ) ) && function_exists( 'WC' ) && WC()->countries ) {
			$fields = [ 'first_name'=>'First name', 'last_name'=>'Last name', 'company'=>'Company', 'address_1'=>'Address line 1', 'address_2'=>'Address line 2', 'city'=>'City', 'state'=>'State / region', 'postcode'=>'Postcode', 'country'=>'Country', 'phone'=>'Phone' ];
			if ( 'billing' === $section ) $fields['email'] = 'Billing email';
			try { $customer = new \WC_Customer( $id ); foreach ( $fields as $key=>$label ) { $getter = 'get_'.$section.'_'.$key; $setter = 'set_'.$section.'_'.$key; if ( ! is_callable( [ $customer,$getter ] ) || ! is_callable( [ $customer,$setter ] ) ) { unset( $fields[$key] ); continue; } $values[$key] = (string) $customer->{$getter}( 'edit' ); } } catch ( \Throwable $error ) { return $this->account_error( 'Customer details could not be read.', 409 ); }
		} else return $this->account_error( 'This profile section is unavailable.', 404 );
		$out = [ 'id'=>$id, 'section'=>$section, 'fields'=>$fields, 'values'=>$values ];
		// Include roles so a role change invalidates an already-open editor.
		$out['expected'] = hash( 'sha256', wp_json_encode( [ $id,$section,$fields,$values,$user->roles ] ) );
		if ( in_array( $section, [ 'billing','shipping' ], true ) ) $out['countries'] = WC()->countries->get_countries();
		return $out;
	}

	public function save_account_section( int $id, array $input ) {
		$section = $input['section'] ?? null;
		if ( ! is_string( $section ) ) return $this->account_error( 'Choose a profile section.' );
		$current = $this->account_section( $id, $section ); if ( is_wp_error( $current ) ) return $current;
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $current['expected'], $input['expected'] ) ) return $this->account_error( 'These account details changed. Reload and review before saving.', 409 );
		$values = $input['values'] ?? null;
		if ( ! is_array( $values ) || array_diff_key( $values, $current['fields'] ) || array_diff_key( $current['fields'], $values ) ) return $this->account_error( 'Submit only the fields in this profile section.' );
		foreach ( $values as $key=>&$value ) {
			if ( ! is_string( $value ) || strlen( $value ) > ( 'description' === $key ? 12000 : 1000 ) ) return $this->account_error( 'A profile value is invalid or too long.' );
			$value = 'description' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			if ( in_array( $key, [ 'user_url','facebook',Design_Context_Profile_Service::USER_META_LINKEDIN ], true ) && '' !== $value ) {
				$url = esc_url_raw( $value, [ 'http','https' ] ); if ( ! $url || ! preg_match( '~^https?://~i', $url ) ) return $this->account_error( 'Enter a full HTTP or HTTPS public link.' ); $value = $url;
			}
			if ( 'email' === $key && '' !== $value ) { if ( ! is_email( $value ) ) return $this->account_error( 'Enter a valid billing email.' ); $value = sanitize_email( $value ); }
		}
		unset( $value );
		if ( 'name' === $section && ( '' === $values['display_name'] || '' === $values['nickname'] ) ) return $this->account_error( 'Display name and nickname are required.' );
		if ( in_array( $section, [ 'billing','shipping' ], true ) ) {
			$values['country'] = strtoupper( $values['country'] );
			if ( $values['country'] && ! isset( $current['countries'][$values['country']] ) ) return $this->account_error( 'Choose a country WooCommerce supports.' );
			$states = $values['country'] ? WC()->countries->get_states( $values['country'] ) : [];
			if ( $values['state'] && is_array( $states ) && $states && ! isset( $states[$values['state']] ) ) return $this->account_error( 'Enter the state code used by WooCommerce for this country.' );
		}
		$changes = array_diff_assoc( $values, $current['values'] );
		try {
			if ( $changes && 'name' === $section ) {
				$result = wp_update_user( wp_slash( array_merge( [ 'ID'=>$id ], $changes ) ) );
				if ( is_wp_error( $result ) || (int) $result !== $id ) return $this->account_error( 'Account details could not be confirmed. Reload before retrying.', 409 );
			} elseif ( $changes && 'public' === $section ) {
				foreach ( $changes as $key=>$value ) { if ( ! current_user_can( 'edit_user_meta', $id, $key ) ) return $this->account_error( 'This public field is unavailable.', 403 ); }
				foreach ( $changes as $key=>$value ) update_user_meta( $id, $key, wp_slash( $value ) );
			} elseif ( $changes ) {
				$customer = new \WC_Customer( $id );
				foreach ( $changes as $key=>$value ) { $setter = 'set_'.$section.'_'.$key; $customer->{$setter}( $value ); }
				$customer->save();
			}
		} catch ( \Throwable $error ) { return $this->account_error( 'The save could not be confirmed. Reload and review this account before retrying.', 409 ); }
		$after = $this->account_section( $id, $section );
		if ( is_wp_error( $after ) ) return $after;
		foreach ( $values as $key=>$value ) if ( $after['values'][$key] !== $value ) return $this->account_error( 'Some account details were not saved as requested. Reload and review them.', 409 );
		return $after;
	}

	/** One person per save: missing/other-page users and unrelated submitted fields are untouched. */
	public function save_member( int $user_id, array $input ) {
		if ( ! $this->can_manage_member( $user_id ) ) return new \WP_Error( 'kiwe_team_forbidden', __( 'This person is unavailable for team management.', 'dsa' ), [ 'status'=>403 ] );
		if ( array_key_exists( 'expected', $input ) && ( ! is_string( $input['expected'] ) || ! hash_equals( self::fingerprint( $user_id ), $input['expected'] ) ) ) return new \WP_Error( 'kiwe_team_stale', __( 'These team details changed since the form opened. Refresh and review this person before saving.', 'dsa' ), [ 'status'=>409 ] );
		foreach ( [ 'team_member', 'team_title', 'team_order' ] as $field ) {
			if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) ) return new \WP_Error( 'kiwe_team_fields', __( 'Complete the team membership, title and display order fields.', 'dsa' ), [ 'status'=>400 ] );
		}
		if ( ! in_array( $input['team_member'], [ '0', '1' ], true ) ) return new \WP_Error( 'kiwe_team_membership', __( 'Choose whether this person appears on the team.', 'dsa' ), [ 'status'=>400 ] );
		$title = sanitize_text_field( $input['team_title'] );
		$length = preg_match_all( '/./us', $title );
		if ( false === $length || $length > 200 ) return new \WP_Error( 'kiwe_team_title', __( 'Use a public title of at most 200 characters.', 'dsa' ), [ 'status'=>400 ] );
		if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,3})$/D', $input['team_order'] ) ) return new \WP_Error( 'kiwe_team_order', __( 'Use a display order from 0 to 9999.', 'dsa' ), [ 'status'=>400 ] );

		// Selection is written last so a new member is not published when a detail write fails.
		$changes = [
			Design_Context_Profile_Service::USER_META_TEAM_TITLE => $title,
			Design_Context_Profile_Service::USER_META_TEAM_ORDER => (string) (int) $input['team_order'],
			Design_Context_Profile_Service::USER_META_TEAM_MEMBER => $input['team_member'],
		];
		foreach ( $changes as $key => $value ) {
			if ( (string) get_user_meta( $user_id, $key, true ) === $value ) continue;
			// The metadata API returns false for unchanged data too; read back the native value.
			update_user_meta( $user_id, $key, $value );
			if ( (string) get_user_meta( $user_id, $key, true ) !== $value ) {
				return new \WP_Error( 'kiwe_team_save', __( 'Some team details could not be saved. Other fields may have changed. Open Teams and review this person before trying again.', 'dsa' ), [ 'status'=>500 ] );
			}
		}
		foreach ( $changes as $key => $value ) {
			if ( (string) get_user_meta( $user_id, $key, true ) !== $value ) return new \WP_Error( 'kiwe_team_unconfirmed', __( 'Team details changed during the save. Refresh and review this person.', 'dsa' ), [ 'status'=>409 ] );
		}
		return true;
	}

	public function handle_save(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( esc_html__( 'Submit the team form to save changes.', 'dsa' ), '', [ 'response'=>405 ] );
		$input = (array) wp_unslash( $_POST );
		$id = is_string( $input['user_id'] ?? null ) && preg_match( '/^[1-9][0-9]*$/D', $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$nonce = is_string( $input['kiwe_team_nonce'] ?? null ) ? $input['kiwe_team_nonce'] : '';
		if ( ! $id || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $id ) ) wp_die( esc_html__( 'The team form has expired. Open Teams and try again.', 'dsa' ), '', [ 'response'=>403 ] );
		$result = $this->save_member( $id, array_merge( [ 'expected'=>null ], $input ) );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', [ 'response'=>(int) ( $result->get_error_data()['status'] ?? 400 ), 'back_link'=>true ] );
		$receipt = str_replace( '-', '', wp_generate_uuid4() );
		if ( ! set_transient( 'kiwe_team_saved_' . get_current_user_id() . '_' . $receipt, $id, 120 ) ) wp_die( esc_html__( 'The team details were saved, but the receipt could not be recorded. Open Teams to review the current details.', 'dsa' ), '', [ 'response'=>500 ] );
		wp_safe_redirect( self::url( array_merge( $this->navigation( $input ), [ 'team_saved'=>$receipt ] ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'kiwe_manage_team' ) ) wp_die( esc_html__( 'Team management is unavailable.', 'dsa' ), '', [ 'response'=>403 ] );
		$input = (array) wp_unslash( $_GET );
		$listing = $this->listing( $input );
		$nav = $listing['navigation'];
		?>
		<div class="wrap kiwe-team-workspace kiwe-tasks kiwe-native-workspace">
			<header class="kiwe-team-workspace__intro"><p class="kiwe-team-workspace__eyebrow"><?php esc_html_e( 'Your people', 'dsa' ); ?></p><h1><?php esc_html_e( 'Teams', 'dsa' ); ?></h1><p><?php esc_html_e( 'Choose who appears on your website and describe what they do. Team titles are public job titles; account access stays in Users.', 'dsa' ); ?></p></header>
			<?php if ( $this->saved_receipt( $input['team_saved'] ?? '' ) ) : ?><div class="notice notice-success" role="status"><p><?php esc_html_e( 'Team details saved. Designs connected to your team use these details.', 'dsa' ); ?></p></div><?php endif; ?>
			<section class="kiwe-app-card kiwe-team-workspace__toolbar" aria-label="<?php esc_attr_e( 'Find people', 'dsa' ); ?>">
				<nav class="kiwe-team-workspace__views" aria-label="<?php esc_attr_e( 'Team views', 'dsa' ); ?>">
					<?php foreach ( [ 'all'=>__( 'All eligible people', 'dsa' ), 'members'=>__( 'On the team', 'dsa' ) ] as $view => $label ) : ?><a href="<?php echo esc_url( self::url( [ 'team_view'=>$view, 'team_search'=>$nav['team_search'] ] ) ); ?>" <?php if ( $view === $nav['team_view'] ) echo 'aria-current="page"'; ?>><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
				</nav>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="kiwe-team-workspace__search" role="search"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><input type="hidden" name="team_view" value="<?php echo esc_attr( $nav['team_view'] ); ?>"><label for="kiwe-team-search"><?php esc_html_e( 'Find by public name', 'dsa' ); ?></label><div><input id="kiwe-team-search" name="team_search" type="search" maxlength="80" value="<?php echo esc_attr( $nav['team_search'] ); ?>"><button class="button" type="submit"><?php esc_html_e( 'Search', 'dsa' ); ?></button></div></form>
			</section>
			<p class="kiwe-team-workspace__help"><?php esc_html_e( 'Only existing staff accounts you can manage appear here. Selected names, biographies, portraits and approved public links join your Design Context. Lower display numbers appear first.', 'dsa' ); ?></p>
			<?php if ( ! $listing['users'] ) : ?>
				<div class="kiwe-app-card kiwe-team-workspace__empty"><h2><?php esc_html_e( 'No people in this view', 'dsa' ); ?></h2><p><?php esc_html_e( 'Try another name or switch to All eligible people. Your site owner can help with staff accounts.', 'dsa' ); ?></p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'All eligible people', 'dsa' ); ?></a></div>
			<?php else : ?><div class="kiwe-team-workspace__grid"><?php foreach ( $listing['users'] as $user ) $this->render_person( $user, $nav ); ?></div><?php endif; ?>
			<nav class="kiwe-team-workspace__pagination" aria-label="<?php esc_attr_e( 'People pages', 'dsa' ); ?>">
				<?php if ( $nav['team_page'] > 1 ) : ?><a class="button" href="<?php echo esc_url( self::url( array_replace( $nav, [ 'team_page'=>$nav['team_page'] - 1 ] ) ) ); ?>"><?php esc_html_e( 'Previous', 'dsa' ); ?></a><?php endif; ?>
				<span><?php echo esc_html( sprintf( __( 'Page %d', 'dsa' ), $nav['team_page'] ) ); ?></span>
				<?php if ( $listing['has_next'] ) : ?><a class="button" href="<?php echo esc_url( self::url( array_replace( $nav, [ 'team_page'=>$nav['team_page'] + 1 ] ) ) ); ?>"><?php esc_html_e( 'Next', 'dsa' ); ?></a><?php endif; ?>
			</nav>
		</div>
		<?php
	}

	private function saved_receipt( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value ) ) return false;
		$key = 'kiwe_team_saved_' . get_current_user_id() . '_' . $value;
		$id = (int) get_transient( $key );
		delete_transient( $key );
		return $id > 0 && $this->can_manage_member( $id );
	}

	private function render_person( \WP_User $user, array $nav ): void {
		$id = (int) $user->ID;
		$member = User_Profile_Service::is_team_member( $id );
		$title = (string) get_user_meta( $id, Design_Context_Profile_Service::USER_META_TEAM_TITLE, true );
		$order = max( 0, min( 9999, (int) get_user_meta( $id, Design_Context_Profile_Service::USER_META_TEAM_ORDER, true ) ) );
		$prefix = 'kiwe-team-' . $id;
		?>
		<article class="kiwe-app-card kiwe-team-workspace__card" aria-labelledby="<?php echo esc_attr( $prefix . '-name' ); ?>">
			<header><span class="kiwe-team-workspace__initial" aria-hidden="true"><?php echo esc_html( wp_html_excerpt( $user->display_name, 1, '' ) ); ?></span><div><h2 id="<?php echo esc_attr( $prefix . '-name' ); ?>"><?php echo esc_html( $user->display_name ); ?></h2><span class="kiwe-team-workspace__badge<?php echo $member ? ' is-member' : ''; ?>"><?php echo esc_html( $member ? __( 'On the team', 'dsa' ) : __( 'Available to add', 'dsa' ) ); ?></span></div></header>
			<form method="post" data-kiwe-native-form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Team details for %s', 'dsa' ), $user->display_name ) ); ?>">
				<input type="hidden" name="expected" value="<?php echo esc_attr( self::fingerprint( $id ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>"><input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $id ); ?>"><?php wp_nonce_field( self::ACTION . '_' . $id, 'kiwe_team_nonce' ); ?>
				<?php foreach ( $nav as $key => $value ) : ?><input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>"><?php endforeach; ?>
				<label for="<?php echo esc_attr( $prefix . '-member' ); ?>"><?php esc_html_e( 'Website team', 'dsa' ); ?></label><select id="<?php echo esc_attr( $prefix . '-member' ); ?>" name="team_member"><option value="0" <?php selected( $member, false ); ?>><?php esc_html_e( 'Keep off the team', 'dsa' ); ?></option><option value="1" <?php selected( $member, true ); ?>><?php esc_html_e( 'Show on the team', 'dsa' ); ?></option></select>
				<label for="<?php echo esc_attr( $prefix . '-title' ); ?>"><?php esc_html_e( 'Public job title', 'dsa' ); ?></label><input id="<?php echo esc_attr( $prefix . '-title' ); ?>" name="team_title" maxlength="200" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'For example, Studio manager', 'dsa' ); ?>">
				<label for="<?php echo esc_attr( $prefix . '-order' ); ?>"><?php esc_html_e( 'Display order', 'dsa' ); ?></label><input id="<?php echo esc_attr( $prefix . '-order' ); ?>" name="team_order" type="number" min="0" max="9999" step="1" required value="<?php echo esc_attr( (string) $order ); ?>">
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Save team details', 'dsa' ); ?></button>
				<?php Task_Workspace_UI::native_form_feedback(); ?>
			</form>
		</article>
		<?php
	}
}
