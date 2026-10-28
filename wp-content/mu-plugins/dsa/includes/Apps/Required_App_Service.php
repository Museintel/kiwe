<?php
namespace DSA\Apps;

use DSA\Access\Site_Owner_Service;
use DSA\Access\Team_Workspace_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Included Core capabilities are deliberately outside the optional installer contract. */
final class Required_App_Service {
	public const IDS = [ 'auth', 'security' ];
	public static function allowed( string $id ): bool {
		if ( ! in_array( $id, self::IDS, true ) || ! is_user_logged_in() || Team_Workspace_Service::suspended( get_current_user_id() ) || ( is_multisite() && ! is_user_member_of_blog( get_current_user_id() ) ) ) return false;
		if ( self::technical() ) return current_user_can( 'manage_options' );
		if ( 'security' === $id ) return current_user_can( 'kiwe_manage_notification_policy' );
		return 'administrator' === \DSA\Access\WordPress_Role_Access_Service::role_for( wp_get_current_user() ) && current_user_can( 'kiwe_manage_context' );
	}
	public static function capability( string $id ): string { return self::technical() ? 'manage_options' : ( 'auth' === $id ? 'kiwe_manage_context' : 'kiwe_manage_notification_policy' ); }
	public static function technical(): bool { return Site_Owner_Service::is_owner() || ( ! Site_Owner_Service::enabled() && current_user_can( 'manage_options' ) ) || ( is_multisite() && is_super_admin() ); }
	public static function url( string $id ): string { return in_array( $id, self::IDS, true ) ? admin_url( 'admin.php?page=kiwe-' . $id . '-app' ) : ''; }
	public static function card( string $id ): array {
		return [ 'id'=>$id, 'enabled'=>true, 'installed'=>true, 'included'=>true, 'url'=>self::allowed( $id ) ? self::url( $id ) : '', 'description'=>'auth' === $id ? __( 'Subscription, sign-in, verification and trusted access', 'dsa' ) : __( 'Protection, privacy, security alerts and retention', 'dsa' ), 'status'=>__( 'Included with Kiwe', 'dsa' ), 'canChange'=>false, 'externalOwner'=>false, 'blocked'=>false, 'action'=>'', 'expected'=>'', 'nonce'=>'' ];
	}
}
