<?php

namespace DSA\Access;

use DSA\Utilities\Origin_Checker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Application-gated guest contributions.
 *
 * WordPress' contributor role is retained as the compatibility primitive, but
 * Kiwe never exposes its native editor. Approved guests receive one narrow
 * create-once workspace; submitted posts are immutable to their author.
 */
final class Guest_Contribution_Service {
	public const META = 'kiwe_guest_application_v1';
	public const SETTINGS_OPTION = 'kiwe_guest_contribution_settings';
	private const POST_META = '_kiwe_guest_submission';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'admin_menu', [ $this, 'menu' ], 24 );
		add_action( 'admin_post_kiwe_guest_save_settings', [ $this, 'handle_settings' ] );
		add_action( 'admin_post_kiwe_guest_decide', [ $this, 'handle_decision' ] );
		add_action( 'admin_post_kiwe_guest_submit', [ $this, 'handle_submission' ] );
		add_filter( 'translate_user_role', [ $this, 'guest_role_label' ], 10, 2 );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/account/guest-application',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'apply' ],
				'permission_callback' => static function ( WP_REST_Request $request ) {
					return is_user_logged_in() && Origin_Checker::mutation_allowed( $request );
				},
			]
		);
	}

	public static function application( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		return is_array( $stored ) ? $stored : [];
	}

	public static function approved( int $user_id ): bool {
		return 'approved' === sanitize_key( (string) ( self::application( $user_id )['status'] ?? '' ) );
	}

	public static function settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$commerce = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		$posts = ! empty( $stored['posts_enabled'] );
		$products = $commerce && ! empty( $stored['products_enabled'] );

		return [
			'postsEnabled'      => $posts,
			'productsEnabled'   => $products,
			'commerceAvailable' => $commerce,
			'anyEnabled'        => $posts || $products,
		];
	}

	public static function profile_state( int $user_id ): array {
		$application = self::application( $user_id );
		$user = get_userdata( $user_id );
		$roles = $user ? (array) $user->roles : [];
		$status = sanitize_key( (string) ( $application['status'] ?? '' ) );
		if ( '' === $status && in_array( 'contributor', $roles, true ) ) {
			$status = 'approved';
		}
		$verified = \DSA\PhoneKey\PhoneKey_Bridge::account_verified( $user_id );
		$features = self::settings();
		$has_admin_access = self::has_admin_area_access( $user_id, $roles );

		return [
			'eligible'          => $user_id > 0 && $verified && ! $has_admin_access && ! empty( $features['anyEnabled'] ),
			'identityVerified'  => $verified,
			'hasAdminAccess'    => $has_admin_access,
			'status'            => $status ?: 'none',
			'intents'           => array_values( array_intersect( [ 'post', 'product' ], array_map( 'sanitize_key', (array) ( $application['intents'] ?? [] ) ) ) ),
			'commerceAvailable' => ! empty( $features['commerceAvailable'] ),
			'postsAvailable'    => ! empty( $features['postsEnabled'] ),
			'productsAvailable' => ! empty( $features['productsEnabled'] ),
			'applicationsEnabled' => ! empty( $features['anyEnabled'] ),
			'canSubmitPosts'    => 'approved' === $status && in_array( 'contributor', $roles, true ) && ! empty( $features['postsEnabled'] ),
			'label'             => self::status_label( $status ),
		];
	}

	public function apply( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$state = self::profile_state( $user_id );
		if ( empty( $state['identityVerified'] ) ) {
			return new WP_Error( 'kiwe_guest_verification_required', __( 'Verify your email address or phone number before applying.', 'dsa' ), [ 'status' => 403 ] );
		}
		if ( empty( $state['eligible'] ) || in_array( $state['status'], [ 'pending', 'approved' ], true ) ) {
			return new WP_Error( 'kiwe_guest_application_unavailable', __( 'A Guest application cannot be started for this account.', 'dsa' ), [ 'status' => 409 ] );
		}

		$prior = self::application( $user_id );
		$decided_at = isset( $prior['decidedAt'] ) ? strtotime( (string) $prior['decidedAt'] ) : 0;
		if ( 'denied' === ( $prior['status'] ?? '' ) && $decided_at && ( time() - $decided_at ) < 7 * DAY_IN_SECONDS ) {
			return new WP_Error( 'kiwe_guest_application_cooldown', __( 'This application was recently reviewed. You can apply again after seven days.', 'dsa' ), [ 'status' => 429 ] );
		}

		$allowed = [];
		if ( ! empty( $state['postsAvailable'] ) ) {
			$allowed[] = 'post';
		}
		if ( ! empty( $state['productsAvailable'] ) ) {
			$allowed[] = 'product';
		}
		if ( [] === $allowed ) {
			return new WP_Error( 'kiwe_guest_applications_disabled', __( 'Guest applications are not enabled on this site.', 'dsa' ), [ 'status' => 403 ] );
		}
		$intents = array_values( array_intersect( $allowed, array_map( 'sanitize_key', (array) $request->get_param( 'intents' ) ) ) );
		if ( [] === $intents ) {
			$intents = [ $allowed[0] ];
		}
		$application = [
			'status'    => 'pending',
			'intents'   => $intents,
			'appliedAt' => gmdate( 'c' ),
			'decidedAt' => '',
			'decidedBy' => 0,
			'reason'    => '',
		];
		update_user_meta( $user_id, self::META, $application );
		do_action( 'kiwe_guest_application_submitted', $user_id, $application );

		return new WP_REST_Response( [ 'ok' => true, 'guestApplication' => self::profile_state( $user_id ) ], 200 );
	}

	public function menu(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$count = current_user_can( 'kiwe_manage_guest_applications' ) ? $this->pending_count() : 0;
		$label = __( 'Guest', 'dsa' );
		if ( $count > 0 ) {
			$label .= ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . $count . '</span></span>';
		}
		if ( current_user_can( 'kiwe_manage_guest_applications' ) || current_user_can( 'kiwe_guest_submit' ) ) {
			add_menu_page( __( 'Guest', 'dsa' ), $label, 'read', 'kiwe-guests', [ $this, 'render' ], 'dashicons-welcome-write-blog', 31 );
		}
	}

	public function render(): void {
		if ( current_user_can( 'kiwe_manage_guest_applications' ) ) {
			$this->render_applications();
			return;
		}
		if ( current_user_can( 'kiwe_guest_submit' ) && self::approved( get_current_user_id() ) ) {
			$this->render_workspace();
			return;
		}
		wp_die( esc_html__( 'Guest access is not active for this account.', 'dsa' ), '', [ 'response' => 403 ] );
	}

	public function handle_settings(): void {
		if ( ! current_user_can( 'kiwe_manage_guest_applications' ) ) {
			wp_die( esc_html__( 'You cannot configure Guest contributions.', 'dsa' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'kiwe_guest_save_settings' );
		$commerce = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		update_option(
			self::SETTINGS_OPTION,
			[
				'posts_enabled'    => ! empty( $_POST['posts_enabled'] ),
				'products_enabled' => $commerce && ! empty( $_POST['products_enabled'] ),
			],
			false
		);
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-guests', 'settings-updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_decision(): void {
		if ( ! current_user_can( 'kiwe_manage_guest_applications' ) ) {
			wp_die( esc_html__( 'You cannot review Guest applications.', 'dsa' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'kiwe_guest_decide' );
		$user_id = absint( $_POST['user_id'] ?? 0 );
		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$application = self::application( $user_id );
		$user = get_userdata( $user_id );
		if ( ! $user || 'pending' !== ( $application['status'] ?? '' ) || ! in_array( $decision, [ 'approved', 'denied' ], true ) ) {
			wp_die( esc_html__( 'That application is no longer pending.', 'dsa' ), '', [ 'response' => 409 ] );
		}

		$application['status'] = $decision;
		$application['decidedAt'] = gmdate( 'c' );
		$application['decidedBy'] = get_current_user_id();
		$application['reason'] = 'denied' === $decision ? sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ) : '';
		update_user_meta( $user_id, self::META, $application );
		if ( 'approved' === $decision ) {
			$user->add_role( 'contributor' );
		} else {
			$user->remove_role( 'contributor' );
		}
		do_action( 'kiwe_guest_application_decided', $user_id, $decision, $application );
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-guests', 'updated' => $decision ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_submission(): void {
		if ( ! current_user_can( 'kiwe_guest_submit' ) || ! self::approved( get_current_user_id() ) ) {
			wp_die( esc_html__( 'Approved Guest access is required.', 'dsa' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'kiwe_guest_submit' );
		$user_id = get_current_user_id();
		$key = 'kiwe_guest_submit_' . $user_id;
		if ( get_transient( $key ) ) {
			wp_die( esc_html__( 'Please wait before sending another contribution.', 'dsa' ), '', [ 'response' => 429 ] );
		}
		$type = 'product' === sanitize_key( wp_unslash( $_POST['submission_type'] ?? 'post' ) ) ? 'product' : 'post';
		$application = self::application( $user_id );
		$features = self::settings();
		if ( 'post' === $type && ( empty( $features['postsEnabled'] ) || ! in_array( 'post', (array) ( $application['intents'] ?? [] ), true ) ) ) {
			wp_die( esc_html__( 'Guest posts are not active for this account.', 'dsa' ), '', [ 'response' => 403 ] );
		}
		if ( 'product' === $type && ( empty( $features['productsEnabled'] ) || ! function_exists( 'WC' ) || ! in_array( 'product', (array) ( $application['intents'] ?? [] ), true ) ) ) {
			wp_die( esc_html__( 'Product proposals are not active for this Guest account.', 'dsa' ), '', [ 'response' => 403 ] );
		}
		$title = sanitize_text_field( wp_unslash( $_POST['post_title'] ?? '' ) );
		$content = wp_kses_post( wp_unslash( $_POST['post_content'] ?? '' ) );
		$status = 'draft' === sanitize_key( wp_unslash( $_POST['post_status'] ?? 'pending' ) ) ? 'draft' : 'pending';
		if ( strlen( $title ) < 5 || strlen( wp_strip_all_tags( $content ) ) < ( 'product' === $type ? 20 : 50 ) ) {
			wp_die( esc_html__( 'Add a clear title and enough detail for an editor to review.', 'dsa' ), '', [ 'response' => 400 ] );
		}
		if ( strlen( $content ) > 100000 ) {
			wp_die( esc_html__( 'This contribution is too long.', 'dsa' ), '', [ 'response' => 413 ] );
		}
		$post_id = wp_insert_post(
			[
				'post_type'    => $type,
				'post_author'  => $user_id,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
			],
			true
		);
		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html( $post_id->get_error_message() ), '', [ 'response' => 500 ] );
		}
		update_post_meta( (int) $post_id, self::POST_META, [ 'createdAt' => gmdate( 'c' ), 'immutableForAuthor' => true ] );
		if ( 'product' === $type ) {
			$price = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( wp_unslash( $_POST['regular_price'] ?? '' ) ) : preg_replace( '/[^0-9.]/', '', (string) ( $_POST['regular_price'] ?? '' ) );
			if ( '' !== $price ) {
				update_post_meta( (int) $post_id, '_regular_price', $price );
				update_post_meta( (int) $post_id, '_price', $price );
			}
			update_post_meta( (int) $post_id, '_kiwe_guest_seller_user_id', $user_id );
		}
		set_transient( $key, 1, 15 );
		if ( 'pending' === $status ) {
			do_action( 'kiwe_guest_submission_received', (int) $post_id, $user_id );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe-guests', 'submitted' => $status ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function guest_role_label( string $translation, string $text ): string {
		return 'Contributor' === $text ? __( 'Guest', 'dsa' ) : $translation;
	}

	private function render_applications(): void {
		$users = get_users( [ 'meta_key' => self::META, 'number' => 200, 'orderby' => 'registered', 'order' => 'DESC' ] );
		$features = self::settings();
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Guest applications', 'dsa' ); ?></h1>
		<h2><?php esc_html_e( 'Guest contribution settings', 'dsa' ); ?></h2>
		<p><?php esc_html_e( 'Guest applications remain hidden until at least one contribution type is enabled. Only partially verified people without administrator-area access can apply.', 'dsa' ); ?></p>
		<?php if ( isset( $_GET['settings-updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Guest contribution settings saved.', 'dsa' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_guest_save_settings"><?php wp_nonce_field( 'kiwe_guest_save_settings' ); ?>
		<p><label><input type="checkbox" name="posts_enabled" value="1" <?php checked( ! empty( $features['postsEnabled'] ) ); ?>> <strong><?php esc_html_e( 'Enable Guest posts', 'dsa' ); ?></strong></label></p>
		<?php if ( ! empty( $features['commerceAvailable'] ) ) : ?><p><label><input type="checkbox" name="products_enabled" value="1" <?php checked( ! empty( $features['productsEnabled'] ) ); ?>> <strong><?php esc_html_e( 'Enable product proposals', 'dsa' ); ?></strong></label></p><?php else : ?><p class="description"><?php esc_html_e( 'Product proposals become available here only when WooCommerce is active.', 'dsa' ); ?></p><?php endif; ?>
		<?php submit_button( __( 'Save Guest settings', 'dsa' ), 'primary', 'submit', false ); ?></form><hr>
		<p><?php esc_html_e( 'Approve verified people for the isolated Guest submission workspace. Product proposals remain unpublished until store staff review them; approval never grants order, payout or store-settings access.', 'dsa' ); ?></p>
		<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Application updated.', 'dsa' ); ?></p></div><?php endif; ?>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Person', 'dsa' ); ?></th><th><?php esc_html_e( 'Verified contact', 'dsa' ); ?></th><th><?php esc_html_e( 'Purpose', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th><th><?php esc_html_e( 'Action', 'dsa' ); ?></th></tr></thead><tbody>
		<?php if ( [] === $users ) : ?><tr><td colspan="5"><?php esc_html_e( 'No Guest applications yet.', 'dsa' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $users as $user ) : $application = self::application( (int) $user->ID ); $status = sanitize_key( (string) ( $application['status'] ?? 'pending' ) ); ?>
		<tr><td><strong><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></strong><br><small><?php echo esc_html( $user->user_email ); ?></small></td>
		<td><?php echo esc_html( self::verified_contact_label( (int) $user->ID ) ); ?></td><td><?php echo esc_html( implode( ', ', array_map( [ $this, 'intent_label' ], (array) ( $application['intents'] ?? [] ) ) ) ); ?></td><td><?php echo esc_html( self::status_label( $status ) ); ?></td>
		<td><?php if ( 'pending' === $status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kiwe_guest_decide"><input type="hidden" name="user_id" value="<?php echo (int) $user->ID; ?>"><?php wp_nonce_field( 'kiwe_guest_decide' ); ?><input name="reason" maxlength="180" placeholder="Reason if denied"><button class="button button-primary" name="decision" value="approved"><?php esc_html_e( 'Approve', 'dsa' ); ?></button> <button class="button" name="decision" value="denied"><?php esc_html_e( 'Deny', 'dsa' ); ?></button></form><?php else : ?>—<?php endif; ?></td></tr>
		<?php endforeach; ?></tbody></table></div>
		<?php
	}

	private function render_workspace(): void {
		$application = self::application( get_current_user_id() );
		$features = self::settings();
		$post_enabled = ! empty( $features['postsEnabled'] ) && in_array( 'post', (array) ( $application['intents'] ?? [] ), true );
		$product_enabled = ! empty( $features['productsEnabled'] ) && function_exists( 'WC' ) && in_array( 'product', (array) ( $application['intents'] ?? [] ), true );
		$post_types = post_type_exists( 'product' ) ? [ 'post', 'product' ] : 'post';
		$posts = get_posts( [ 'author' => get_current_user_id(), 'post_type' => $post_types, 'post_status' => [ 'draft', 'pending', 'publish', 'future', 'private' ], 'numberposts' => 50, 'orderby' => 'date', 'order' => 'DESC' ] );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Guest contributions', 'dsa' ); ?></h1><p><?php esc_html_e( 'Create a new contribution once. Drafts and submitted articles are read-only here; an editor owns every later change.', 'dsa' ); ?></p>
		<?php if ( isset( $_GET['submitted'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Your contribution was received.', 'dsa' ); ?></p></div><?php endif; ?>
		<?php if ( $post_enabled || $product_enabled ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px"><input type="hidden" name="action" value="kiwe_guest_submit"><?php wp_nonce_field( 'kiwe_guest_submit' ); ?><?php if ( $post_enabled && $product_enabled ) : ?><p><label><strong><?php esc_html_e( 'Contribution type', 'dsa' ); ?></strong><br><select name="submission_type"><option value="post"><?php esc_html_e( 'Guest article', 'dsa' ); ?></option><option value="product"><?php esc_html_e( 'Product proposal', 'dsa' ); ?></option></select></label></p><?php else : ?><input type="hidden" name="submission_type" value="<?php echo $product_enabled ? 'product' : 'post'; ?>"><?php endif; ?><p><label><strong><?php esc_html_e( 'Title', 'dsa' ); ?></strong><br><input class="large-text" name="post_title" maxlength="240" required></label></p><p><label><strong><?php esc_html_e( 'Article or product details', 'dsa' ); ?></strong><br><textarea class="large-text" name="post_content" rows="16" required></textarea></label></p><?php if ( $product_enabled ) : ?><p><label><strong><?php esc_html_e( 'Proposed price (product only)', 'dsa' ); ?></strong><br><input name="regular_price" inputmode="decimal"></label></p><?php endif; ?><p><button class="button" name="post_status" value="draft"><?php esc_html_e( 'Save read-only draft', 'dsa' ); ?></button> <button class="button button-primary" name="post_status" value="pending"><?php esc_html_e( 'Submit for review', 'dsa' ); ?></button></p></form><?php else : ?><div class="notice notice-info inline"><p><?php esc_html_e( 'Guest submissions are currently paused by the site owner. Your existing submissions remain visible below.', 'dsa' ); ?></p></div><?php endif; ?>
		<h2><?php esc_html_e( 'Your contributions', 'dsa' ); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Title', 'dsa' ); ?></th><th><?php esc_html_e( 'Type', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th><th><?php esc_html_e( 'Sent', 'dsa' ); ?></th></tr></thead><tbody><?php if ( [] === $posts ) : ?><tr><td colspan="4"><?php esc_html_e( 'Nothing submitted yet.', 'dsa' ); ?></td></tr><?php endif; ?><?php foreach ( $posts as $post ) : ?><tr><td><?php echo esc_html( get_the_title( $post ) ); ?></td><td><?php echo esc_html( 'product' === $post->post_type ? __( 'Product proposal', 'dsa' ) : __( 'Guest article', 'dsa' ) ); ?></td><td><?php echo esc_html( get_post_status_object( $post->post_status )->label ?? $post->post_status ); ?></td><td><?php echo esc_html( get_the_date( '', $post ) ); ?></td></tr><?php endforeach; ?></tbody></table></div>
		<?php
	}

	private function pending_count(): int {
		$ids = get_users( [ 'meta_key' => self::META, 'fields' => 'ID', 'number' => 200 ] );
		$count = 0;
		foreach ( (array) $ids as $id ) if ( 'pending' === ( self::application( (int) $id )['status'] ?? '' ) ) $count++;
		return $count;
	}

	private static function verified_contact_label( int $user_id ): string {
		$factors = \DSA\PhoneKey\PhoneKey_Bridge::verified_factors( $user_id );
		$email = ! empty( $factors['email'] );
		$phone = ! empty( $factors['phone'] );
		return $email && $phone ? __( 'Email and phone', 'dsa' ) : ( $phone ? __( 'Phone', 'dsa' ) : __( 'Email', 'dsa' ) );
	}

	private static function has_admin_area_access( int $user_id, array $roles ): bool {
		if ( $user_id <= 0 ) return false;
		if ( is_multisite() && is_super_admin( $user_id ) ) return true;
		if ( array_intersect( [ Site_Owner_Service::ROLE, 'administrator', 'shop_manager', 'editor', 'author', 'contributor' ], $roles ) ) return true;
		return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'edit_posts' ) || user_can( $user_id, 'edit_products' ) || user_can( $user_id, 'kiwe_guest_submit' );
	}

	private static function status_label( string $status ): string {
		return [ 'pending' => __( 'Pending approval', 'dsa' ), 'approved' => __( 'Guest', 'dsa' ), 'denied' => __( 'Denied', 'dsa' ) ][ $status ] ?? __( 'Apply for Guest', 'dsa' );
	}

	private function intent_label( string $intent ): string {
		return 'product' === sanitize_key( $intent ) ? __( 'Sell products', 'dsa' ) : __( 'Guest posts', 'dsa' );
	}
}
