<?php

namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One explicit owner; installing an update never silently demotes administrators. */
final class Site_Owner_Service {

	public const OPTION = 'kiwe_access_policy_v1';
	public const ROLE = 'kiwe_super_admin';
	private static bool $transferring = false;

	public static function policy(): array {
		return (array) ( is_multisite() ? get_network_option( null, self::OPTION, [] ) : get_option( self::OPTION, [] ) );
	}

	public static function owner_id(): int { return absint( self::policy()['ownerId'] ?? 0 ); }
	public static function enabled(): bool { return ! empty( self::policy()['enabled'] ) && self::owner_id() > 0; }
	public static function is_owner( ?int $id = null ): bool { return ( $id ?? get_current_user_id() ) === self::owner_id() && self::owner_id() > 0; }

	private static function store( array $policy ): void {
		if ( is_multisite() ) update_network_option( null, self::OPTION, $policy );
		else update_option( self::OPTION, $policy, false );
	}

	public function register(): void {
		add_action( 'init', [ $this, 'register_roles' ], 1 );
		add_action( 'admin_menu', [ $this, 'menu' ], 25 );
		add_action( 'admin_post_kiwe_access_owner', [ $this, 'handle_owner' ] );
		add_action( 'admin_post_kiwe_access_roles', [ $this, 'handle_roles' ] );
		add_filter( 'editable_roles', [ $this, 'editable_roles' ], PHP_INT_MAX );
		add_filter( 'map_meta_cap', [ $this, 'protect_owner' ], PHP_INT_MAX, 4 );
		add_action( 'delete_user', [ $this, 'guard_delete' ], 0 );
		add_action( 'wpmu_delete_user', [ $this, 'guard_delete' ], 0 );
		add_filter( 'update_user_metadata', [ $this, 'protect_membership' ], 0, 5 );
		add_filter( 'add_user_metadata', [ $this, 'protect_membership' ], 0, 5 );
		add_filter( 'delete_user_metadata', [ $this, 'protect_membership' ], 0, 5 );
		add_filter( 'pre_update_site_option_site_admins', [ $this, 'protect_network_owner' ], 10, 2 );
		add_filter( 'user_profile_update_errors', [ $this, 'validate_assignment' ], 10, 3 );
		add_filter( 'rest_pre_insert_user', [ $this, 'validate_rest_assignment' ], 10, 2 );
		add_action( 'wp_login', [ $this, 'sync_verified_role' ], 30, 2 );
		add_action( 'kiwe_identity_factor_verified', [ $this, 'sync_verified_id' ] );
	}

	public function register_roles(): void {
		if ( ! get_role( 'kiwe_user' ) ) add_role( 'kiwe_user', 'User', [ 'read'=>true ] );
		if ( ! get_role( self::ROLE ) ) {
			$admin = get_role( 'administrator' );
			add_role( self::ROLE, 'Super Admin', $admin ? $admin->capabilities : [ 'read'=>true ] );
		}
	}

	public static function allowed_roles(): array {
		$roles = [ 'subscriber', 'kiwe_user', 'customer', 'author', 'editor', 'contributor', 'administrator' ];
		if ( ( new \DSA\Onboarding\Design_Context_Profile_Service() )->commerce_available() ) $roles[] = 'shop_manager';
		return $roles;
	}

	public function editable_roles( array $roles ): array {
		// Ownership can only be assigned via the explicit transfer transaction.
		unset( $roles[ self::ROLE ] );
		if ( ! self::enabled() ) return $roles;
		return array_intersect_key( $roles, array_fill_keys( self::allowed_roles(), true ) );
	}

	public function protect_owner( array $caps, string $cap, int $actor, array $args ): array {
		if ( ! self::enabled() || self::$transferring ) return $caps;
		$target = absint( $args[0] ?? 0 );
		if ( $target === self::owner_id() ) {
			if ( in_array( $cap, [ 'delete_user', 'remove_user', 'promote_user' ], true ) ) return [ 'do_not_allow' ];
			if ( in_array( $cap, [ 'edit_user', 'edit_user_meta', 'delete_user_meta', 'add_user_meta', 'create_app_password', 'delete_app_passwords', 'edit_app_password' ], true ) && ! self::is_owner( $actor ) ) return [ 'do_not_allow' ];
		}
		return $caps;
	}

	public function guard_delete( int $id ): void {
		if ( self::enabled() && $id === self::owner_id() ) wp_die( esc_html__( 'Transfer Super Admin ownership before deleting this account.', 'dsa' ), '', [ 'response'=>403 ] );
	}

	public function protect_membership( $check, $id, $key, $value = null, $previous = null ) {
		if ( self::$transferring || ! self::enabled() || ! preg_match( '/(?:^|_)capabilities$/', (string) $key ) ) return $check;
		if ( (int) $id === self::owner_id() ) return false;
		if ( is_array( $value ) && ! empty( $value[ self::ROLE ] ) ) return false;
		if ( is_array( $value ) && ! empty( $value['contributor'] ) && ! Guest_Contribution_Service::approved( (int) $id ) ) return false;
		if ( ! self::is_owner() && get_current_user_id() ) {
			if ( ! is_array( $value ) || array_diff( array_keys( array_filter( $value ) ), self::allowed_roles() ) ) return false;
		}
		return $check;
	}

	public function protect_network_owner( $value, $old ) {
		if ( ! self::enabled() || self::$transferring ) return $value;
		$user = get_userdata( self::owner_id() );
		return $user ? [ $user->user_login ] : $old;
	}

	public function validate_assignment( $errors, $update, $user ): void {
		if ( ! self::enabled() ) return;
		$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		if ( $role && ! in_array( $role, self::allowed_roles(), true ) && ! ( self::is_owner( (int) ( $user->ID ?? 0 ) ) && self::ROLE === $role ) ) $errors->add( 'kiwe_role', __( 'That role cannot be assigned. Use an approved role or the ownership transfer form.', 'dsa' ) );
		if ( 'contributor' === $role && ! Guest_Contribution_Service::approved( (int) ( $user->ID ?? 0 ) ) ) $errors->add( 'kiwe_guest_application', __( 'Guest access is assigned only by approving a verified Guest application.', 'dsa' ) );
	}

	public function validate_rest_assignment( $user, $request ) {
		if ( ! self::enabled() || is_wp_error( $user ) ) return $user;
		foreach ( (array) ( $request['roles'] ?? [] ) as $role ) {
			if ( ! in_array( $role, self::allowed_roles(), true ) ) return new \WP_Error( 'kiwe_role', 'That role cannot be assigned through this endpoint.', [ 'status'=>403 ] );
			if ( 'contributor' === $role && ! Guest_Contribution_Service::approved( (int) ( $user->ID ?? 0 ) ) ) return new \WP_Error( 'kiwe_guest_application', 'Guest access requires an approved verified application.', [ 'status'=>403 ] );
		}
		return $user;
	}

	public function sync_verified_role( string $login, \WP_User $user ): void { $this->sync_verified_id( (int) $user->ID ); }
	public function sync_verified_id( int $id ): void {
		if ( ! self::enabled() || ! \DSA\PhoneKey\PhoneKey_Bridge::account_verified( $id ) ) return;
		$user = get_userdata( $id );
		if ( $user && [ 'subscriber' ] === array_values( $user->roles ) ) $user->set_role( 'kiwe_user' );
	}

	public function menu(): void {
		add_submenu_page( 'users.php', 'Access & ownership', 'Access & ownership', self::enabled() ? 'kiwe_manage_ownership' : 'manage_options', 'kiwe-access', [ $this, 'render' ] );
	}

	private function authorized(): bool {
		return self::enabled() ? self::is_owner() : current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() );
	}

	public function render(): void {
		if ( ! $this->authorized() ) wp_die( 'Owner access required.', '', [ 'response'=>403 ] );
		$owner = get_userdata( self::owner_id() );
		$policy = self::policy();
		?>
		<div class="wrap"><h1>Access &amp; ownership</h1>
		<p><?php echo $owner ? 'Super Admin: <strong>' . esc_html( $owner->user_login ) . '</strong>. Client access policy is active.' : 'Choose the single full-access owner before enabling the simplified client workspace. Existing access stays unchanged until activation.'; ?></p>
		<?php if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>Access policy updated.</p></div>'; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="kiwe_access_owner"><?php wp_nonce_field( 'kiwe_access_owner' ); ?>
		<p><label>Owner username <input name="owner_login" required autocomplete="off" value="<?php echo esc_attr( $owner ? '' : wp_get_current_user()->user_login ); ?>"></label></p>
		<?php if ( $owner ) : ?><p><label>Your WordPress password <input type="password" name="owner_password" autocomplete="current-password" required></label></p><?php endif; ?>
		<p><label><input type="checkbox" name="confirm_owner" value="1" required> <?php echo $owner ? 'Transfer full control to this existing Administrator. My account becomes a client Administrator; no account is deleted.' : 'Make this account the sole Super Admin and enable the restricted client roles. No account or content is deleted.'; ?></label></p>
		<?php submit_button( $owner ? 'Transfer ownership' : 'Enable client workspace' ); ?></form>
		<?php if ( $owner ) : ?>
		<h2>Plugin access for client Administrators</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="kiwe_access_roles"><?php wp_nonce_field( 'kiwe_access_roles' ); ?>
		<p><label>Rank Math <select name="rank_math"><?php foreach ( [ 'inline'=>'Post editing controls only', 'dashboard'=>'Dashboard and post editing controls', 'off'=>'No Rank Math controls' ] as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $policy['rankMath'] ?? 'inline', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
		<h2>Retire extra roles safely</h2><p>Map each occupied extra role first. Accounts and posts are preserved, and previous role definitions and assignments are recorded for recovery. Unoccupied extra roles can be retired immediately. Plugins may recreate their roles, but those roles remain unavailable for assignment.</p>
		<?php foreach ( wp_roles()->roles as $slug=>$role ) : if ( in_array( $slug, array_merge( self::allowed_roles(), [ self::ROLE, 'shop_manager' ] ), true ) ) continue; $ids = get_users( [ 'role'=>$slug, 'fields'=>'ID' ] ); ?>
		<p><label><?php echo esc_html( $role['name'] . ' (' . count( $ids ) . ')' ); ?> <select name="retire[<?php echo esc_attr( $slug ); ?>]"><option value="">Keep pending</option><?php foreach ( self::allowed_roles() as $replacement ) : ?><option value="<?php echo esc_attr( $replacement ); ?>"><?php echo esc_html( $replacement ); ?></option><?php endforeach; ?><?php if ( ! $ids ) : ?><option value="__unused">Retire unused role</option><?php endif; ?></select></label></p>
		<?php endforeach; submit_button( 'Save access policy' ); ?></form>
		<h2>Effective access check</h2><p>Read-only checks against existing WordPress accounts. These use native capability checks, not menu visibility, and do not sign in as another user.</p>
		<table class="widefat striped"><thead><tr><th>Role</th><th>Technical settings</th><th>Create posts</th><th>Edit others’ posts</th><th>Edit pages</th><th>Manage users</th><th>Business details</th></tr></thead><tbody>
		<?php foreach ( array_merge( [ self::ROLE ], self::allowed_roles() ) as $slug ) :
			$sample = self::ROLE === $slug ? $owner : ( get_users( [ 'role'=>$slug, 'number'=>1 ] )[0] ?? null );
			echo '<tr><td>' . esc_html( self::ROLE === $slug ? 'Super Admin' : ( 'kiwe_user' === $slug ? 'User' : ucfirst( $slug ) ) ) . '</td>';
			foreach ( [ 'manage_options','create_posts','edit_others_posts','edit_pages','edit_users','kiwe_manage_context' ] as $cap ) {
				// edit_pages also opens the native list; object edits remain owner-only.
				$allowed = $sample && ( 'edit_pages' === $cap ? self::is_owner( (int) $sample->ID ) : user_can( $sample, $cap ) );
				echo '<td>' . ( $sample ? ( $allowed ? 'Yes' : 'No' ) : 'No account' ) . '</td>';
			}
			echo '</tr>';
		endforeach; ?></tbody></table><?php endif; ?></div>
		<?php
	}

	public function handle_owner(): void {
		check_admin_referer( 'kiwe_access_owner' );
		if ( ! $this->authorized() || empty( $_POST['confirm_owner'] ) ) wp_die( 'Owner authorization required.', '', [ 'response'=>403 ] );
		$target = get_user_by( 'login', sanitize_user( wp_unslash( $_POST['owner_login'] ?? '' ), true ) );
		$old_id = self::owner_id();
		if ( ! $target || ( $old_id && ! in_array( 'administrator', $target->roles, true ) ) || ( ! $old_id && $target->ID !== get_current_user_id() ) ) wp_die( 'Initial setup must use your signed-in administrator. Transfers require an existing Administrator.', '', [ 'response'=>400 ] );
		if ( $old_id && ! wp_check_password( wp_unslash( $_POST['owner_password'] ?? '' ), wp_get_current_user()->user_pass, get_current_user_id() ) ) wp_die( 'Password confirmation failed.', '', [ 'response'=>403 ] );
		if ( is_multisite() && ! $old_id && get_super_admins() !== [ $target->user_login ] ) wp_die( 'Before activation, use Network Admin to leave exactly this one native Super Admin. No other network owner will be silently demoted.', '', [ 'response'=>409 ] );
		if ( ! $this->ownership_lock( true ) ) wp_die( 'An ownership change is already running. Wait for it to finish.', '', [ 'response'=>409 ] );
		// Recheck authority after acquiring the cross-site lock.
		if ( $old_id !== self::owner_id() || ! $this->authorized() ) { $this->ownership_lock( false ); wp_die( 'Ownership changed. Reload this page.', '', [ 'response'=>409 ] ); }
		$policy = self::policy();
		$target_roles = $target->roles;
		$network_owners = is_multisite() ? get_super_admins() : [];
		$failed = false;
		self::$transferring = true;
		try {
			$target->set_role( self::ROLE );
			if ( ! in_array( self::ROLE, get_userdata( $target->ID )->roles, true ) ) throw new \RuntimeException( 'Could not assign the owner role.' );
			if ( is_multisite() ) {
				grant_super_admin( $target->ID );
				update_site_option( 'site_admins', [ $target->user_login ] );
			}
			self::store( array_replace( $policy, [ 'enabled'=>true, 'ownerId'=>(int) $target->ID, 'previousOwnerId'=>$old_id, 'rankMath'=>$policy['rankMath'] ?? 'inline', 'updatedAt'=>gmdate( 'c' ) ] ) );
			if ( self::owner_id() !== (int) $target->ID ) throw new \RuntimeException( 'Could not persist ownership.' );
			if ( $old_id && $old_id !== (int) $target->ID ) get_userdata( $old_id )->set_role( 'administrator' );
			update_option( 'default_role', 'subscriber' );
		} catch ( \Throwable $error ) {
			// Recover prior authority before returning an error; never leave the site ownerless.
			self::store( $policy );
			if ( $old_id && ( $previous_owner = get_userdata( $old_id ) ) ) $previous_owner->set_role( self::ROLE );
			$target->set_role( '' );
			foreach ( $target_roles as $role ) $target->add_role( $role );
			if ( is_multisite() ) update_site_option( 'site_admins', $network_owners );
			$failed = true;
		} finally { self::$transferring = false; $this->ownership_lock( false ); }
		if ( $failed ) wp_die( 'Ownership could not be changed. The prior assignment has been restored.', '', [ 'response'=>500 ] );
		wp_safe_redirect( admin_url( 'users.php' ) ); exit;
	}

	/** add_option uses the unique option-name index; network sites share the main-site lock. */
	private function ownership_lock( bool $acquire ): bool {
		$switched = is_multisite() && get_current_blog_id() !== get_main_site_id();
		if ( $switched ) switch_to_blog( get_main_site_id() );
		try {
			return $acquire ? add_option( 'kiwe_owner_transfer_lock', time(), '', false ) : delete_option( 'kiwe_owner_transfer_lock' );
		} finally { if ( $switched ) restore_current_blog(); }
	}

	public function handle_roles(): void {
		check_admin_referer( 'kiwe_access_roles' );
		if ( ! self::is_owner() ) wp_die( 'Owner access required.', '', [ 'response'=>403 ] );
		$policy = self::policy();
		$mode = sanitize_key( $_POST['rank_math'] ?? 'inline' );
		$policy['rankMath'] = in_array( $mode, [ 'inline', 'dashboard', 'off' ], true ) ? $mode : 'inline';
		foreach ( (array) ( $_POST['retire'] ?? [] ) as $slug=>$replacement ) {
			$slug = sanitize_key( $slug ); $replacement = sanitize_key( $replacement );
			if ( ! $replacement || in_array( $slug, array_merge( self::allowed_roles(), [ self::ROLE, 'shop_manager' ] ), true ) ) continue;
			$role = get_role( $slug ); if ( ! $role ) continue;
			$users = get_users( [ 'role'=>$slug ] );
			if ( '__unused' === $replacement && $users ) continue;
			if ( '__unused' !== $replacement && ( ! in_array( $replacement, self::allowed_roles(), true ) || ! get_role( $replacement ) ) ) continue;
			$record = [ 'definition'=>wp_roles()->roles[ $slug ], 'assignments'=>[], 'retiredAt'=>gmdate( 'c' ) ];
			foreach ( $users as $user ) {
				if ( self::is_owner( $user->ID ) ) continue 2;
				$record['assignments'][ $user->ID ] = $user->roles;
			}
			$policy['retiredRoles'][ $slug ] = $record;
			self::store( $policy ); // Recovery evidence precedes each mutation.
			foreach ( $users as $user ) { $user->remove_role( $slug ); $user->add_role( $replacement ); }
			remove_role( $slug );
		}
		self::store( $policy );
		wp_safe_redirect( admin_url( 'users.php?page=kiwe-access&updated=1' ) ); exit;
	}
}
