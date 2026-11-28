<?php
namespace DSA\Access;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Retained native Guest record/permission owner; optional operations live in the Guest App. */
class Guest_Contribution_Service {
 public const META = 'kiwe_guest_application_v1';
 public const SETTINGS_OPTION = 'kiwe_guest_contribution_settings';
 protected const POST_META='_kiwe_guest_submission';
	public const INTENTS = [ 'post', 'product', 'referral' ];
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

	public static function grants( array $application ): array {
		if ( 'approved' !== ( $application['status'] ?? '' ) ) return [];
		// Older approvals granted the requested contribution types.
		$grants = $application['grants'] ?? array_intersect( [ 'post', 'product' ], (array) ( $application['intents'] ?? [] ) );
		return array_values( array_intersect( self::INTENTS, (array) $grants ) );
	}

	public static function has_submission_grant( int $user_id ): bool {
		return (bool) array_intersect( [ 'post', 'product' ], self::grants( self::application( $user_id ) ) );
	}

	public static function enabled_intents(): array {
		$settings = self::settings();
		return array_keys( array_filter( [
			'post' => $settings['postsEnabled'], 'product' => $settings['productsEnabled'], 'referral' => $settings['referralsEnabled'],
		] ) );
	}

	public static function can_refer( int $user_id ): bool {
		if ( $user_id < 1 || ! self::settings()['referralsEnabled'] ) return false;
		$application = self::application( $user_id );
		return (int) ( $application['referralSite'] ?? 0 ) === get_current_blog_id()
			&& in_array( 'referral', self::grants( $application ), true )
			&& ! Team_Workspace_Service::suspended( $user_id )
			&& \DSA\PhoneKey\PhoneKey_Bridge::account_verified( $user_id );
	}

	public static function settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$commerce = \DSA\Apps\Store_App_Service::enabled();
		$enabled = \DSA\Apps\Guest_App_Service::enabled();
		$posts = $enabled && ! empty( $stored['posts_enabled'] );
		$products = $enabled && $commerce && ! empty( $stored['products_enabled'] );
		$referrals = $enabled && ! empty( $stored['referrals_enabled'] );

		return [
			'postsEnabled'      => $posts,
			'productsEnabled'   => $products,
			'commerceAvailable' => $commerce,
			'referralsEnabled'  => $referrals,
			'anyEnabled'        => $posts || $products || $referrals,
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
			'intents'           => array_values( array_intersect( self::INTENTS, (array) ( $application['intents'] ?? [] ) ) ),
			'grants'            => self::grants( $application ),
			'commerceAvailable' => ! empty( $features['commerceAvailable'] ),
			'postsAvailable'    => ! empty( $features['postsEnabled'] ),
			'productsAvailable' => ! empty( $features['productsEnabled'] ),
			'referralsAvailable' => ! empty( $features['referralsEnabled'] ),
			'canRefer'          => ! empty( $features['referralsEnabled'] ) && $verified && (int) ( $application['referralSite'] ?? 0 ) === get_current_blog_id()
				&& in_array( 'referral', self::grants( $application ), true ) && ! Team_Workspace_Service::suspended( $user_id ) && class_exists( Guest_Referral_Service::class ),
			'applicationsEnabled' => ! empty( $features['anyEnabled'] ),
			'canSubmitPosts'    => in_array( 'post', self::grants( $application ), true ) && in_array( 'contributor', $roles, true ) && ! empty( $features['postsEnabled'] ),
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

	protected static function has_admin_area_access( int $user_id, array $roles, bool $existing_guest = false ): bool {
		if ( $user_id <= 0 ) return false;
		if ( is_multisite() && is_super_admin( $user_id ) ) return true;
		if ( array_intersect( [ Site_Owner_Service::ROLE, 'administrator', 'shop_manager', 'editor', 'author' ], $roles ) ) return true;
		if ( ! $existing_guest && in_array( 'contributor', $roles, true ) ) return true;
		return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'edit_posts' ) || user_can( $user_id, 'edit_products' )
			|| ( ! $existing_guest && user_can( $user_id, 'kiwe_guest_submit' ) );
	}

	protected static function status_label( string $status ): string {
		return [ 'pending' => __( 'Pending approval', 'dsa' ), 'approved' => __( 'Guest', 'dsa' ), 'denied' => __( 'Denied', 'dsa' ), 'revoked' => __( 'Guest access revoked', 'dsa' ) ][ $status ] ?? __( 'Apply for Guest', 'dsa' );
	}


 public function __call(string $name,array $arguments) {
  if ( ! in_array($name,['register','routes','apply','menu','render','handle_settings','handle_decision','handle_submission','workspace','decide_application','save_workspace_settings','review_submission','submit_workspace','guest_role_label'],true) ) throw new \BadMethodCallException('Unknown Guest operation.');
  if ( ! \DSA\Apps\Guest_App_Service::enabled() ) return new \WP_Error('kiwe_guest_app_unavailable','Install and turn on the Guest App to use this workspace.',['status'=>403]);
  return (new Guest_Engine_Service())->$name(...$arguments);
 }
}
