<?php

namespace DSA\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/** WordPress owns people. Only explicitly selected, eligible staff enter public context. */
final class User_Profile_Service {

	public function register(): void {
		add_filter( 'user_contactmethods', [ $this, 'contact_methods' ] );
		add_action( 'show_user_profile', [ $this, 'render_team_fields' ] );
		add_action( 'edit_user_profile', [ $this, 'render_team_fields' ] );
		add_action( 'user_new_form', [ $this, 'render_new_user_fields' ] );
		add_action( 'personal_options_update', [ $this, 'save_team_fields' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_team_fields' ] );
		add_action( 'user_register', [ $this, 'save_team_fields' ] );
		add_filter( 'manage_users_columns', [ $this, 'user_columns' ] );
		add_filter( 'manage_users_custom_column', [ $this, 'user_column' ], 10, 3 );
	}

	/** Test capabilities, not role names; subscriber/customer read access is insufficient. */
	public static function eligible( int $user_id ): bool {
		if ( ! $user_id || ( is_multisite() && ! is_user_member_of_blog( $user_id ) ) ) return false;
		foreach ( [ 'manage_options', 'edit_posts', 'edit_pages', 'edit_products', 'manage_woocommerce' ] as $capability ) {
			if ( user_can( $user_id, $capability ) ) return true;
		}
		return false;
	}

	public static function is_team_member( int $user_id ): bool {
		return self::eligible( $user_id ) && '1' === (string) get_user_meta( $user_id, Design_Context_Profile_Service::USER_META_TEAM_MEMBER, true );
	}

	public function contact_methods( array $methods ): array {
		// Reuse the existing Kiwe LinkedIn key; never export arbitrary plugin contact fields.
		$methods[ Design_Context_Profile_Service::USER_META_LINKEDIN ] = __( 'LinkedIn (public)', 'dsa' );
		$methods['facebook'] = $methods['facebook'] ?? __( 'Facebook (public)', 'dsa' );
		return $methods;
	}

	public function render_new_user_fields(): void {
		$this->render_team_fields( null );
	}

	public function render_team_fields( $user ): void {
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		if ( ! current_user_can( 'kiwe_manage_team' ) || ( $user_id && ( ! current_user_can( 'edit_user', $user_id ) || ! self::eligible( $user_id ) ) ) ) return;
		$member = $user_id && self::is_team_member( $user_id );
		$title = $user_id ? get_user_meta( $user_id, Design_Context_Profile_Service::USER_META_TEAM_TITLE, true ) : '';
		$eligible_roles = [];
		foreach ( wp_roles()->roles as $slug => $role ) {
			foreach ( [ 'manage_options', 'edit_posts', 'edit_pages', 'edit_products', 'manage_woocommerce' ] as $capability ) {
				if ( ! empty( $role['capabilities'][ $capability ] ) ) { $eligible_roles[] = $slug; break; }
			}
		}
		wp_nonce_field( 'kiwe_team_profile_' . $user_id, 'kiwe_team_nonce' );
		?>
		<h2><?php esc_html_e( 'Public team', 'dsa' ); ?></h2>
		<table class="form-table" data-kiwe-user-team><tbody>
			<tr><th><?php esc_html_e( 'Team membership', 'dsa' ); ?></th><td>
				<label><input type="radio" name="kiwe_team_member" value="0" <?php checked( ! $member ); ?>> <?php esc_html_e( 'Not on the public team', 'dsa' ); ?></label><br>
				<label><input type="radio" name="kiwe_team_member" value="1" <?php checked( $member ); ?>> <?php esc_html_e( 'Show as a team member', 'dsa' ); ?></label>
				<p class="description"><?php esc_html_e( 'Only staff with content or administration access qualify. Public display name, biography, portrait, website and approved social links become design context. Login email, phone, permissions and security settings are never included.', 'dsa' ); ?></p>
			</td></tr>
			<tr><th><label for="kiwe-team-title"><?php esc_html_e( 'Team title', 'dsa' ); ?></label></th><td><input id="kiwe-team-title" class="regular-text" name="kiwe_team_title" maxlength="200" value="<?php echo esc_attr( (string) $title ); ?>"><p class="description"><?php esc_html_e( 'Public job title, not a WordPress permission role. Only administrators can change it.', 'dsa' ); ?></p></td></tr>
		</tbody></table>
		<script>
		(function(){
			const table = document.querySelector('[data-kiwe-user-team]');
			const role = document.querySelector('select[name="role"]');
			if (!table || !role) return;
			const rows = Array.from(table.querySelectorAll('tr'));
			const allowed = <?php echo wp_json_encode( $eligible_roles ); ?>;
			const heading = table.previousElementSibling;
			if (heading && heading.tagName === 'H2') heading.hidden = true;
			const roleRow = role.closest('tr');
			if (roleRow) { roleRow.after(...rows); table.remove(); }
			function sync() {
				const eligible = allowed.includes(role.value);
				rows.forEach(function(row) { row.hidden = !eligible; row.querySelectorAll('input').forEach(function(input) { input.disabled = !eligible; }); });
			}
			role.addEventListener('change', sync);
			sync();
		}());
		</script>
		<?php
	}

	public function save_team_fields( int $user_id ): void {
		if ( ! current_user_can( 'kiwe_manage_team' ) || ! current_user_can( 'edit_user', $user_id ) ) return;
		$nonce = sanitize_text_field( (string) wp_unslash( $_POST['kiwe_team_nonce'] ?? '' ) );
		$nonce_id = 'user_register' === current_filter() ? 0 : $user_id;
		if ( ! wp_verify_nonce( $nonce, 'kiwe_team_profile_' . $nonce_id ) ) return;
		$member = '1' === (string) ( $_POST['kiwe_team_member'] ?? '' ) && self::eligible( $user_id );
		update_user_meta( $user_id, Design_Context_Profile_Service::USER_META_TEAM_MEMBER, $member ? '1' : '0' );
		update_user_meta( $user_id, Design_Context_Profile_Service::USER_META_TEAM_TITLE, substr( sanitize_text_field( (string) wp_unslash( $_POST['kiwe_team_title'] ?? '' ) ), 0, 200 ) );
		if ( ! metadata_exists( 'user', $user_id, Design_Context_Profile_Service::USER_META_TEAM_ORDER ) ) update_user_meta( $user_id, Design_Context_Profile_Service::USER_META_TEAM_ORDER, 0 );
	}

	public function user_columns( array $columns ): array {
		if ( current_user_can( 'kiwe_manage_team' ) ) $columns['kiwe_team'] = __( 'Public team', 'dsa' );
		return $columns;
	}

	public function user_column( string $output, string $column, int $user_id ): string {
		if ( 'kiwe_team' !== $column ) return $output;
		return self::is_team_member( $user_id ) ? esc_html( (string) ( get_user_meta( $user_id, Design_Context_Profile_Service::USER_META_TEAM_TITLE, true ) ?: __( 'Team member', 'dsa' ) ) ) : '—';
	}

	/** Explicit allowlist shared by DSA self-edit and the public team projection. */
	public static function public_fields( int $user_id ): array {
		$user = get_userdata( $user_id );
		return [
			'bio' => $user ? sanitize_textarea_field( (string) $user->description ) : '',
			'website' => $user ? esc_url_raw( (string) $user->user_url, [ 'http', 'https' ] ) : '',
			'linkedin' => esc_url_raw( (string) get_user_meta( $user_id, Design_Context_Profile_Service::USER_META_LINKEDIN, true ), [ 'http', 'https' ] ),
			'facebook' => esc_url_raw( (string) get_user_meta( $user_id, 'facebook', true ), [ 'http', 'https' ] ),
		];
	}

	/** Authenticated self-edit only. Membership/title/role are deliberately not writable here. */
	public static function update_public_fields( int $user_id, array $input ): void {
		if ( get_current_user_id() !== $user_id || ! self::eligible( $user_id ) ) return;
		$update = [ 'ID' => $user_id ];
		if ( array_key_exists( 'bio', $input ) ) $update['description'] = substr( sanitize_textarea_field( (string) $input['bio'] ), 0, 3000 );
		if ( array_key_exists( 'website', $input ) ) $update['user_url'] = esc_url_raw( (string) $input['website'], [ 'http', 'https' ] );
		if ( count( $update ) > 1 ) wp_update_user( $update );
		foreach ( [ 'linkedin' => Design_Context_Profile_Service::USER_META_LINKEDIN, 'facebook' => 'facebook' ] as $field => $key ) {
			if ( array_key_exists( $field, $input ) ) update_user_meta( $user_id, $key, esc_url_raw( (string) $input[ $field ], [ 'http', 'https' ] ) );
		}
	}
}
