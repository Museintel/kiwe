<?php
namespace DSA\Commerce;

use DSA\Access\Team_Workspace_Service;
use DSA\Apps\Store_App_Service;
use DSA\Utilities\Content_Visibility;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Grants read access to specific native private pages. WordPress status and edit permissions still apply. */
final class Membership_Content_Service {
	private const FORM = 'kiwe_membership_page';

	public function register(): void {
		add_filter( 'map_meta_cap', [ $this, 'read_capabilities' ], 20, 4 );
		add_action( 'template_redirect', [ $this, 'protect_cache' ], 0 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_page', [ $this, 'add_box' ] );
			add_action( 'save_post_page', [ $this, 'save_page' ] );
		}
	}

	private static function can_manage( \WP_Post $page ): bool {
		return 'page' === $page->post_type && current_user_can( 'publish_pages' ) && current_user_can( 'edit_post', $page->ID )
			&& current_user_can( 'manage_woocommerce' ) && ! Team_Workspace_Service::suspended( get_current_user_id() )
			&& Store_App_Service::enabled() && Store_App_Service::supports_memberships() && ! Store_Recovery_Gate_Service::is_paused();
	}

	public static function can_manage_page( int $id ): bool {
		$page = $id > 0 ? get_post( $id ) : null;
		return $page && self::can_manage( $page );
	}

	public static function settings( int $id ): array {
		$page = $id > 0 ? get_post( $id ) : null;
		if ( ! $page || ! self::can_manage( $page ) ) throw new \DomainException( 'Membership page settings require access to this page and WooCommerce.' );
		$restricted = Content_Visibility::membership_page( $page );
		return [
			'pageId'=>$id, 'memberOnly'=>$restricted,
			'expected'=>hash_hmac( 'sha256', serialize( [ $id, $page->post_status, $restricted, get_current_user_id() ] ), wp_salt( 'nonce' ) ),
		];
	}

	public static function change( int $id, bool $restricted, string $expected ): array {
		return Subscription_Billing_Service::synchronized( 'membership-page', $id, static function () use ( $id, $restricted, $expected ): array {
			$before = self::settings( $id );
			if ( ! hash_equals( $before['expected'], $expected ) ) throw new \DomainException( 'The page access changed. Review it before saving.' );
			if ( $restricted && ! $before['memberOnly'] && ! self::eligible( get_post( $id ) ) ) {
				throw new \DomainException( 'Choose a private page outside your home, blog and WooCommerce routes.' );
			}
			if ( $restricted ) update_post_meta( $id, Content_Visibility::MEMBERSHIP_META, '1' );
			else delete_post_meta( $id, Content_Visibility::MEMBERSHIP_META );
			$after = self::settings( $id );
			if ( $restricted !== $after['memberOnly'] ) throw new \RuntimeException( 'The membership page setting could not be confirmed.' );
			return $after;
		} );
	}

	private static function eligible( \WP_Post $page ): bool {
		return 'private' === $page->post_status && '' === $page->post_password
			&& ! in_array( $page->ID, [
				(int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ),
				wc_get_page_id( 'shop' ), wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ),
			], true );
	}

	public function read_capabilities( array $caps, string $cap, int $id, array $args ): array {
		if ( 'read_post' !== $cap && 'read_page' !== $cap ) return $caps;
		$page = ! empty( $args[0] ) ? get_post( (int) $args[0] ) : null;
		if ( ! $page || 'private' !== $page->post_status || ! Content_Visibility::membership_page( $page ) ) return $caps;
		if ( ! $id || Team_Workspace_Service::suspended( $id ) ) return [ 'do_not_allow' ];
		if ( user_can( $id, 'edit_post', $page->ID ) ) return $caps;
		return Membership_Directory::has_paid_access( $id ) ? [ 'read' ] : [ 'do_not_allow' ];
	}

	public function protect_cache(): void {
		$page = is_page() ? get_queried_object() : null;
		if ( ! $page instanceof \WP_Post || ! Content_Visibility::membership_page( $page ) ) return;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		do_action( 'litespeed_control_set_nocache', 'Kiwe membership page' );
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0', true );
	}

	public function add_box( \WP_Post $page ): void {
		if ( self::can_manage( $page ) && ( Content_Visibility::membership_page( $page ) || self::eligible( $page ) ) ) {
			add_meta_box( self::FORM, __( 'Membership access', 'dsa' ), [ $this, 'render_box' ], 'page', 'side' );
		}
	}

	public function render_box( \WP_Post $page ): void {
		$settings = self::settings( $page->ID );
		wp_nonce_field( self::FORM . '_' . $page->ID, self::FORM . '_nonce' );
		printf( '<input type="hidden" name="kiwe_member_expected" value="%1$s"><label><input type="checkbox" name="kiwe_member_only" value="1"%2$s> %3$s</label>',
			esc_attr( $settings['expected'] ), checked( $settings['memberOnly'], true, false ), esc_html__( 'Allow members with paid access to read this private page', 'dsa' ) );
	}

	public function save_page( int $id ): void {
		$nonce = $_POST[self::FORM . '_nonce'] ?? null;
		$expected = $_POST['kiwe_member_expected'] ?? null;
		if ( ! is_string( $nonce ) || ! is_string( $expected ) || ! wp_verify_nonce( $nonce, self::FORM . '_' . $id ) ) return;
		try {
			self::change( $id, '1' === ( $_POST['kiwe_member_only'] ?? '' ), $expected );
		} catch ( \Exception $error ) {
			wp_die( esc_html( $error->getMessage() ), '', [ 'response'=>409, 'back_link'=>true ] );
		}
	}
}
