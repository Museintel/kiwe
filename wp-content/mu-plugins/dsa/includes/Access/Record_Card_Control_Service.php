<?php

namespace DSA\Access;

use DSA\Onboarding\Context_Selection_Service;
use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Onboarding\SEO_Context_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One private WordPress/SEO/menu authority for content-card switches. */
final class Record_Card_Control_Service {
	public function register(): void {
		if ( ! defined( 'KIWE_REACT_WORKSPACE' ) || true !== KIWE_REACT_WORKSPACE ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/record-controls/(?P<kind>post|product|service)/(?P<id>[1-9][0-9]*)', [
			'methods'=>'POST', 'callback'=>[ $this, 'rest_change' ], 'permission_callback'=>[ $this, 'permission' ],
		] );
	}

	public function permission( \WP_REST_Request $request ): bool {
		return 'POST' === $request->get_method() && is_user_logged_in()
			&& wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' )
			&& Origin_Checker::is_same_site_request() && true === Origin_Checker::mutation_allowed( $request );
	}

	private static function service_type(): string {
		$stored = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
		$type = is_array( $stored ) ? sanitize_key( (string) ( $stored['services']['sourcePostType'] ?? '' ) ) : '';
		return $type && isset( ( new Design_Context_Profile_Service() )->service_post_types()[ $type ] ) ? $type : '';
	}

	private static function record( string $kind, int $id ): ?\WP_Post {
		$type = in_array( $kind, [ 'post','product' ], true ) ? $kind : ( 'service' === $kind ? self::service_type() : '' );
		$post = $type && $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && $type === $post->post_type && in_array( $post->post_status, [ 'publish','draft','pending','private','future' ], true ) ? $post : null;
	}

	/** Projection is derived each time; no duplicate visibility or menu store. */
	public static function project( string $kind, int $id ): ?array {
		$post = self::record( $kind, $id );
		if ( ! $post || ! current_user_can( 'edit_post', $id ) ) return null;
		$object = get_post_type_object( $post->post_type );
		$publish_cap = $object->cap->publish_posts ?? '';
		$can_index = SEO_Context_Service::indexing_owner_supported() && $publish_cap && current_user_can( $publish_cap ) && current_user_can( 'edit_post', $id );
		$public = Context_Selection_Service::public_post( $post );
		$seo_raw = defined( 'RANK_MATH_VERSION' ) ? get_post_meta( $id, 'rank_math_robots', true ) : get_post_meta( $id, '_kiwe_seo_robots', true );
		$expected = hash( 'sha256', wp_json_encode( [ $id, $post->post_type, $post->post_status, $post->post_password, $post->post_modified_gmt, $seo_raw ] ) );
		return [ 'kind'=>$kind, 'id'=>$id, 'noindex'=>SEO_Context_Service::object_noindex( $id ), 'canIndex'=>(bool) $can_index,
			'canMenu'=>current_user_can( 'kiwe_manage_navigation' ) && current_user_can( 'read_post', $id ),
			'public'=>$public, 'menuObject'=>$post->post_type, 'expected'=>$expected ];
	}

	public function change( string $kind, int $id, array $body ) {
		$before = self::project( $kind, $id );
		if ( ! $before ) return new \WP_Error( 'kiwe_record_forbidden', 'This record is unavailable to your role.', [ 'status'=>403 ] );
		if ( ! is_string( $body['expected'] ?? null ) || ! hash_equals( $before['expected'], $body['expected'] ) ) return new \WP_Error( 'kiwe_record_stale', 'This record changed. Reload before saving.', [ 'status'=>409 ] );
		$operation = $body['operation'] ?? '';
		if ( 'index' === $operation ) {
			if ( ! $before['canIndex'] || ! is_bool( $body['noindex'] ?? null ) || array_diff( array_keys( $body ), [ 'operation','expected','noindex' ] ) ) return new \WP_Error( 'kiwe_record_index', 'Your role cannot change this indexing choice.', [ 'status'=>403 ] );
			if ( $before['noindex'] !== $body['noindex'] ) {
				$result = SEO_Context_Service::set_object_indexing( $id, $body['noindex'] );
				if ( is_wp_error( $result ) ) return $result;
			}
			$after = self::project( $kind, $id );
			if ( ! $after || $after['noindex'] !== $body['noindex'] ) return new \WP_Error( 'kiwe_record_unconfirmed', 'The indexing choice could not be confirmed. Reload and review.', [ 'status'=>409 ] );
			return [ 'record'=>$after ];
		}
		if ( 'menu' !== $operation || ! $before['canMenu'] || ( ! $before['public'] && true === ( $body['included'] ?? null ) ) || ! is_bool( $body['included'] ?? null ) || ! is_int( $body['menuId'] ?? null ) || ! is_string( $body['menuExpected'] ?? null ) || array_diff( array_keys( $body ), [ 'operation','expected','included','menuId','menuExpected' ] ) ) return new \WP_Error( 'kiwe_record_menu', 'Choose a configured visitor menu.', [ 'status'=>403 ] );
		$navigation = new Navigation_Workspace_Service();
		$menu = null;
		foreach ( $navigation->dsa_menus() as $candidate ) if ( $candidate['id'] === $body['menuId'] ) $menu = $candidate;
		if ( ! $menu || ! hash_equals( $menu['expected'], $body['menuExpected'] ) ) return new \WP_Error( 'kiwe_record_menu_stale', 'The visitor menu changed. Reload before saving.', [ 'status'=>409 ] );
		$item = null;
		foreach ( $menu['items'] as $entry ) if ( 'post_type' === $entry['type'] && $before['menuObject'] === $entry['object'] && $id === $entry['objectId'] ) $item = $entry;
		if ( $body['included'] !== (bool) $item ) {
			$result = $navigation->change( $menu['id'], [ 'operation'=>$body['included'] ? 'add' : 'remove', 'source_kind'=>$kind, 'source_id'=>$id, 'parent_id'=>0, 'item_id'=>$item['id'] ?? 0, 'expected'=>$menu['expected'] ] );
			if ( is_wp_error( $result ) ) return $result;
		}
		$menus = $navigation->dsa_menus();
		$updated = null;
		foreach ( $menus as $candidate ) if ( $candidate['id'] === $menu['id'] ) $updated = $candidate;
		$has_item = false;
		foreach ( $updated['items'] ?? [] as $entry ) if ( 'post_type' === $entry['type'] && $before['menuObject'] === $entry['object'] && $id === $entry['objectId'] ) $has_item = true;
		if ( ! $updated || $has_item !== $body['included'] ) return new \WP_Error( 'kiwe_record_unconfirmed', 'The visitor menu could not be confirmed. Reload and review.', [ 'status'=>409 ] );
		return [ 'record'=>self::project( $kind, $id ), 'menus'=>$menus ];
	}

	public function rest_change( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_record_forbidden', 'This action is unavailable.', [ 'status'=>403 ] ), 403 );
		$params = $request->get_url_params();
		$body = $request->get_json_params();
		$result = $this->change( (string) ( $params['kind'] ?? '' ), absint( $params['id'] ?? 0 ), is_array( $body ) ? $body : [] );
		return is_wp_error( $result ) ? Task_Workspace_UI::failure( $result, (int) ( $result->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $result );
	}
}
