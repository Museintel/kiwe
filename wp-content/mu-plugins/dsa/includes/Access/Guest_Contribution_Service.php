<?php
namespace DSA\Access;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Retained native Guest record/permission owner; optional operations live in the Guest App. */
class Guest_Contribution_Service {
 public const META = 'kiwe_guest_application_v1';
 public const SETTINGS_OPTION = 'kiwe_guest_contribution_settings';
 protected const POST_META='_kiwe_guest_submission';
	public static function application( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		return is_array( $stored ) ? $stored : [];
	}

	public static function is_submission( int $post_id ): bool {
		$stored = get_post_meta( $post_id, self::POST_META, true );
		return is_array( $stored ) && ! empty( $stored['createdAt'] );
	}

	public static function approved( int $user_id ): bool {
		return 'approved' === sanitize_key( (string) ( self::application( $user_id )['status'] ?? '' ) );
	}

	public static function settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$commerce = \DSA\Apps\Store_App_Service::enabled();
		$posts = \DSA\Apps\Guest_App_Service::enabled() && ! empty( $stored['posts_enabled'] );
		$products = \DSA\Apps\Guest_App_Service::enabled() && $commerce && ! empty( $stored['products_enabled'] );

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

	public static function settings_fingerprint(): string { return hash( 'sha256', wp_json_encode( self::settings() ) ); }
	protected static function verified_contact_label( int $user_id ): string {
		$factors = \DSA\PhoneKey\PhoneKey_Bridge::verified_factors( $user_id );
		$email = ! empty( $factors['email'] );
		$phone = ! empty( $factors['phone'] );
		return $email && $phone ? __( 'Email and phone', 'dsa' ) : ( $phone ? __( 'Phone', 'dsa' ) : __( 'Email', 'dsa' ) );
	}

	protected static function has_admin_area_access( int $user_id, array $roles ): bool {
		if ( $user_id <= 0 ) return false;
		if ( is_multisite() && is_super_admin( $user_id ) ) return true;
		if ( array_intersect( [ Site_Owner_Service::ROLE, 'administrator', 'shop_manager', 'editor', 'author', 'contributor' ], $roles ) ) return true;
		return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'edit_posts' ) || user_can( $user_id, 'edit_products' ) || user_can( $user_id, 'kiwe_guest_submit' );
	}

	protected static function status_label( string $status ): string {
		return [ 'pending' => __( 'Pending approval', 'dsa' ), 'approved' => __( 'Guest', 'dsa' ), 'denied' => __( 'Denied', 'dsa' ) ][ $status ] ?? __( 'Apply for Guest', 'dsa' );
	}


 public function __call(string $name,array $arguments) {
  if ( ! in_array($name,['register','routes','apply','menu','render','handle_settings','handle_decision','handle_submission','workspace','decide_application','save_workspace_settings','review_submission','submit_workspace','guest_role_label'],true) ) throw new \BadMethodCallException('Unknown Guest operation.');
  if ( ! \DSA\Apps\Guest_App_Service::enabled() ) return new \WP_Error('kiwe_guest_app_unavailable','Install and turn on the Guest App to use this workspace.',['status'=>403]);
  return (new Guest_Engine_Service())->$name(...$arguments);
 }
}
