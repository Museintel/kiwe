<?php
namespace DSA\Onboarding;

use DSA\SEO\SEO_Defaults_Service;

if (!defined('ABSPATH')) exit;

/** Core business goal and native record privacy; no output, rewrite, import or scheduled hooks. */
class SEO_Context_Service {
	public static function goal(): array {
		$context = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
		$seo = is_array( $context ) && is_array( $context['seo'] ?? null ) ? $context['seo'] : [];
		$labels = [ 'buy'=>'Sales', 'contact'=>'Enquiries', 'book'=>'Bookings', 'visit'=>'Visits', 'subscribe'=>'Subscriptions', 'donate'=>'Donations', 'read'=>'Readership' ];
		$key = is_string( $seo['primaryGoal'] ?? null ) && isset( $labels[$seo['primaryGoal']] ) ? $seo['primaryGoal'] : '';
		return [ 'key'=>$key, 'label'=>$labels[$key] ?? 'Choose your SEO goal', 'searchIntent'=>\DSA\Access\Task_Workspace_UI::text( $seo['searchIntent'] ?? '', 240 ), 'proofPoints'=>\DSA\Access\Task_Workspace_UI::text( $seo['proofPoints'] ?? '', 1600 ) ];
	}

	public static function goal_hash(): string { return hash( 'sha256', wp_json_encode( self::goal() ) ); }

	public static function goal_url(): string { return add_query_arg( [ 'page'=>'kiwe-story', 'field'=>'seo.primaryGoal' ], admin_url( 'admin.php' ) ); }

	public static function page_noindex( int $id ): bool {
		return self::object_noindex( $id );
	}

	public static function object_noindex( int $id ): bool {
		if ( $id < 1 ) return false;
		if ( ! \DSA\Apps\SEO_App_Service::enabled() && '' === \DSA\Apps\SEO_App_Service::external_owner() ) return Native_Indexing_Service::noindex( $id );
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$robots = (array) get_post_meta( $id, 'rank_math_robots', true );
			if ( in_array( 'noindex', $robots, true ) ) return true;
			if ( in_array( 'index', $robots, true ) ) return false;
		}
		if ( \DSA\Apps\SEO_App_Service::enabled() ) {
			$explicit = self::kiwe_robots( $id );
			if ( in_array( 'noindex', $explicit, true ) ) return true;
			if ( in_array( 'index', $explicit, true ) ) return false;
			if ( defined( 'BRICKS_DB_PAGE_SETTINGS' ) && class_exists( '\\Bricks\\Database' ) && empty( \Bricks\Database::$global_settings['disableSeo'] ) ) {
				$page = get_post_meta( $id, BRICKS_DB_PAGE_SETTINGS, true );
				$bricks = is_array( $page ) ? SEO_Defaults_Service::validate_robots( $page['metaRobots'] ?? [] ) : [];
				if ( is_array( $bricks ) && $bricks ) $explicit = $bricks;
			}
			if ( in_array( 'noindex', $explicit, true ) ) return true;
			if ( in_array( 'index', $explicit, true ) ) return false;
			$scope = 'postTypes'; $key = (string) get_post_type( $id );
			if ( 'page' === $key && 'page' === get_option( 'show_on_front' ) && $id === (int) get_option( 'page_on_front' ) ) { $scope = 'home'; $key = ''; }
			elseif ( 'page' === $key && $id === (int) get_option( 'page_for_posts' ) ) { $scope = 'blog'; $key = ''; }
			if ( in_array( 'noindex', SEO_Defaults_Service::robots_for( $scope, $key, $explicit ), true ) ) return true;
		}
		return 'page' === get_post_type( $id ) && 'secondary' === get_post_meta( $id, Design_Context_Profile_Service::PAGE_VISIBILITY_META, true );
	}

	public static function indexing_owner_supported(): bool { return defined( 'RANK_MATH_VERSION' ) || '' === \DSA\Apps\SEO_App_Service::external_owner(); }

	private static function kiwe_robots( int $id ): array {
		return array_values( array_intersect( array_map( 'sanitize_key', (array) get_post_meta( $id, '_kiwe_seo_robots', true ) ), Native_Indexing_Service::ROBOTS ) );
	}

	public static function set_page_indexing( int $id, bool $noindex ) {
		if ( 'page' !== get_post_type( $id ) ) return new \WP_Error( 'kiwe_page_indexing', 'Only pages are supported.' );
		return self::set_object_indexing( $id, $noindex );
	}

	public static function set_object_indexing( int $id, bool $noindex ) {
		if ( ! self::indexing_owner_supported() ) return new \WP_Error( 'kiwe_object_indexing_owner', 'This site uses another SEO editor. Ask your designer to change indexing there.' );
		$type = get_post_type( $id );
		$object = $type && ! in_array( $type, [ 'post', 'page' ], true ) ? get_post_type_object( $type ) : null;
		if ( ! $type || ( ! in_array( $type, [ 'post', 'page' ], true ) && ( ! $object || empty( $object->publicly_queryable ) ) ) ) return new \WP_Error( 'kiwe_object_indexing', 'Choose a public content record.' );
		$visibility = $noindex ? 'secondary' : 'primary';
		if ( 'page' === $type ) update_post_meta( $id, Design_Context_Profile_Service::PAGE_VISIBILITY_META, $visibility );
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$robots = array_values( array_diff( (array) get_post_meta( $id, 'rank_math_robots', true ), [ '', 'index','noindex' ] ) );
			$robots[] = $noindex ? 'noindex' : 'index';
			update_post_meta( $id, 'rank_math_robots', $robots );
			if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) ) \RankMath\Sitemap\Cache::invalidate_storage( $type );
		} else {
			$robots = array_values( array_diff( self::kiwe_robots( $id ), [ 'index', 'noindex' ] ) );
			$robots[] = $noindex ? 'noindex' : 'index';
			update_post_meta( $id, '_kiwe_seo_robots', $robots );
		}
		do_action( 'litespeed_purge_post', $id );
		if ( self::object_noindex( $id ) !== $noindex || ( 'page' === $type && get_post_meta( $id, Design_Context_Profile_Service::PAGE_VISIBILITY_META, true ) !== $visibility ) ) return new \WP_Error( 'kiwe_object_indexing', 'Could not save the indexing setting. Please reload and review.' );
		return true;
	}

	public static function term_noindex( int $id ): bool {
		if ( $id < 1 ) return false;
		if ( ! \DSA\Apps\SEO_App_Service::enabled() && '' === \DSA\Apps\SEO_App_Service::external_owner() ) return Native_Indexing_Service::noindex( $id, true );
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$robots = (array) get_term_meta( $id, 'rank_math_robots', true );
			if ( in_array( 'noindex', $robots, true ) ) return true;
			if ( in_array( 'index', $robots, true ) ) return false;
		}
		if ( \DSA\Apps\SEO_App_Service::enabled() ) {
			$explicit = (array) get_term_meta( $id, '_kiwe_seo_robots', true );
			if ( in_array( 'noindex', $explicit, true ) ) return true;
			if ( in_array( 'index', $explicit, true ) ) return false;
			$term = get_term( $id );
			if ( ! empty( SEO_Defaults_Service::settings()['policies']['noindexEmptyTerms'] ) && $term && ! is_wp_error( $term ) && 0 === (int) ( $term->count ?? 0 ) ) return true;
			if ( $term && ! is_wp_error( $term ) && in_array( 'noindex', SEO_Defaults_Service::robots_for( 'taxonomies', $term->taxonomy, $explicit ), true ) ) return true;
		}
		return 'secondary' === (string) get_term_meta( $id, \DSA\Access\Taxonomy_App_Service::VISIBILITY_META, true );
	}

	public static function set_term_indexing( int $id, bool $noindex ) {
		$term = get_term( $id );
		if ( ! $term || is_wp_error( $term ) || ! current_user_can( 'edit_term', $id ) ) return new \WP_Error( 'kiwe_term_indexing_forbidden', __( 'Your account cannot change search visibility.', 'dsa' ), [ 'status'=>403 ] );
		if ( ! self::indexing_owner_supported() ) return new \WP_Error( 'kiwe_term_indexing_owner', __( 'Manage this archive’s search visibility in your active SEO plugin.', 'dsa' ), [ 'status'=>403 ] );
		update_term_meta( $id, \DSA\Access\Taxonomy_App_Service::VISIBILITY_META, $noindex ? 'secondary' : 'primary' );
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$robots = array_values( array_diff( (array) get_term_meta( $id, 'rank_math_robots', true ), [ '', 'index', 'noindex' ] ) );
			$robots[] = $noindex ? 'noindex' : 'index';
			update_term_meta( $id, 'rank_math_robots', $robots );
			if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) ) \RankMath\Sitemap\Cache::invalidate_storage( $term->taxonomy );
		} else {
			$robots = array_values( array_diff( (array) get_term_meta( $id, '_kiwe_seo_robots', true ), [ '', 'index', 'noindex' ] ) );
			$robots[] = $noindex ? 'noindex' : 'index';
			update_term_meta( $id, '_kiwe_seo_robots', $robots );
		}
		return self::term_noindex( $id ) === $noindex && ( 'secondary' === (string) get_term_meta( $id, \DSA\Access\Taxonomy_App_Service::VISIBILITY_META, true ) ) === $noindex ? true : new \WP_Error( 'kiwe_term_indexing_readback', __( 'Search visibility could not be confirmed. Refresh and review.', 'dsa' ), [ 'status'=>409 ] );
	}
}
