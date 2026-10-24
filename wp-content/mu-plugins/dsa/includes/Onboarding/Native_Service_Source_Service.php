<?php

namespace DSA\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/** An opt-in WordPress owner for new-site service records and categories. */
final class Native_Service_Source_Service {
	public const POST_TYPE = 'kiwe_service';
	public const TAXONOMY = 'kiwe_service_category';

	public function register(): void {
		add_action( 'init', [ $this, 'register_if_selected' ], 6 );
	}

	public function register_if_selected(): void {
		$stored = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
		if ( is_array( $stored ) && self::POST_TYPE === ( $stored['services']['sourcePostType'] ?? '' ) ) $this->register_types();
	}

	/** No registration or rewrite work occurs on sites that have not opted in. */
	public function register_types(): bool {
		if ( post_type_exists( self::POST_TYPE ) || taxonomy_exists( self::TAXONOMY ) ) return false;
		$taxonomy = register_taxonomy( self::TAXONOMY, [ self::POST_TYPE ], [
			'labels'=>[ 'name'=>__( 'Service categories', 'dsa' ), 'singular_name'=>__( 'Service category', 'dsa' ) ],
			'public'=>true, 'show_ui'=>true, 'show_in_rest'=>true, 'hierarchical'=>true,
			'rewrite'=>[ 'slug'=>'service-category', 'with_front'=>false ],
			'capabilities'=>[ 'manage_terms'=>'manage_kiwe_service_category', 'edit_terms'=>'edit_kiwe_service_category', 'delete_terms'=>'delete_kiwe_service_category', 'assign_terms'=>'assign_kiwe_service_category' ],
		] );
		if ( is_wp_error( $taxonomy ) ) return false;
		$post = register_post_type( self::POST_TYPE, [
			'labels'=>[ 'name'=>__( 'Services', 'dsa' ), 'singular_name'=>__( 'Service', 'dsa' ) ],
			'public'=>true, 'show_ui'=>true, 'show_in_menu'=>false, 'show_in_rest'=>true,
			'hierarchical'=>true, 'has_archive'=>false, 'rewrite'=>[ 'slug'=>'service', 'with_front'=>false ],
			'capability_type'=>[ 'kiwe_service', 'kiwe_services' ], 'map_meta_cap'=>true,
			'supports'=>[ 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'page-attributes' ],
			'taxonomies'=>[ self::TAXONOMY ],
		] );
		return ! is_wp_error( $post ) && post_type_exists( self::POST_TYPE ) && taxonomy_exists( self::TAXONOMY );
	}

	/** Existing plans and configured external sources require a separate reviewed conversion. */
	public static function can_activate(): bool {
		if ( ! current_user_can( 'kiwe_manage_context' ) || ! function_exists( 'post_type_exists' ) || ! function_exists( 'taxonomy_exists' ) ) return false;
		$stored = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
		$services = is_array( $stored ) ? (array) ( $stored['services'] ?? [] ) : [];
		if ( ! empty( $services['sourcePostType'] ) || ! empty( $services['items'] ) || post_type_exists( self::POST_TYPE ) || taxonomy_exists( self::TAXONOMY ) ) return false;
		// An existing service collection belongs to its present developer until reviewed.
		return ! post_type_exists( 'service' ) && ! post_type_exists( 'services' );
	}

	public function activate( string $expected ) {
		if ( ! self::can_activate() ) return new \WP_Error( 'kiwe_service_source_unavailable', 'Review the existing service source or plans before starting public services.', [ 'status'=>409 ] );
		$stored = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
		$stored = is_array( $stored ) ? $stored : [];
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! hash_equals( hash( 'sha256', wp_json_encode( ( new Design_Context_Profile_Service() )->current() ) ), $expected ) ) return new \WP_Error( 'kiwe_service_source_stale', 'Website details changed. Reload before starting public services.', [ 'status'=>409 ] );
		if ( ! $this->register_types() ) return new \WP_Error( 'kiwe_service_source_conflict', 'The native service source could not be registered.', [ 'status'=>409 ] );
		$stored['services'] = array_replace( [ 'enabled'=>true, 'sourcePostType'=>self::POST_TYPE, 'useForNavigation'=>false, 'items'=>[] ], (array) ( $stored['services'] ?? [] ), [ 'enabled'=>true, 'sourcePostType'=>self::POST_TYPE ] );
		update_option( Design_Context_Profile_Service::OPTION_PROFILE, $stored, false );
		$actual = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
		if ( ! is_array( $actual ) || self::POST_TYPE !== ( $actual['services']['sourcePostType'] ?? '' ) ) return new \WP_Error( 'kiwe_service_source_unconfirmed', 'The service source could not be confirmed. Reload before retrying.', [ 'status'=>409 ] );
		flush_rewrite_rules( false ); // One explicit transition, never on a public request.
		return [ 'source'=>self::POST_TYPE, 'taxonomy'=>self::TAXONOMY ];
	}
}
