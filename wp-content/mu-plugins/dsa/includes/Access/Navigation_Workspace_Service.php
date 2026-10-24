<?php

namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Narrow native menu tasks without granting theme, builder or generic menu-item writes. */
final class Navigation_Workspace_Service {
	public const PAGE = 'kiwe-navigation';
	public const ACTION = 'kiwe_navigation_change';
	public const ITEM_LIMIT = 200;
	public const GROUP_LIMIT = 10;
	public const MAX_VISIBLE_ITEMS = 200;
	public const DEFAULT_VISIBLE_ITEMS = 12;
	private string $screen = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 34 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}
	/** Bounded menu projection used by the private React DSA Menu screen. */
	public function menu_list(): array {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return [];
		$menus = wp_get_nav_menus( [ 'number'=>101, 'orderby'=>'name', 'order'=>'ASC' ] );
		$dsa_ids = $this->dsa_menu_ids();
		$limits = $this->group_limits();
		$locations = function_exists( 'get_nav_menu_locations' ) ? (array) get_nav_menu_locations() : [];
		$registered = function_exists( 'get_registered_nav_menus' ) ? (array) get_registered_nav_menus() : [];
		$raw = get_option( DSA_OPTION_SETTINGS, [] );
		$out = [];
		foreach ( array_slice( is_array( $menus ) ? $menus : [], 0, 100 ) as $menu ) {
			$id = (int) ( $menu->term_id ?? 0 ); if ( ! $id ) continue;
			$items = $this->items( $id ); if ( is_wp_error( $items ) ) continue;
			$menu_locations = [];
			foreach ( $locations as $location=>$menu_id ) if ( $id === absint( $menu_id ) ) $menu_locations[] = sanitize_text_field( (string) ( $registered[ $location ] ?? $location ) );
			sort( $menu_locations, SORT_NATURAL | SORT_FLAG_CASE );
			$out[] = [ 'id'=>$id, 'name'=>sanitize_text_field( (string) ( $menu->name ?? '' ) ), 'usedByDsa'=>in_array( $id, $dsa_ids, true ), 'position'=>array_search( $id, $dsa_ids, true ) === false ? -1 : array_search( $id, $dsa_ids, true ), 'visibleLimit'=>$limits[ $id ] ?? max( self::DEFAULT_VISIBLE_ITEMS, count( $items ) ), 'pageContents'=>self::menu_contents( (array) ( $raw['dock'] ?? [] ), $id ), 'themeLocations'=>$menu_locations, 'items'=>$this->project_items( $items ), 'expected'=>self::fingerprint( $items ) ];
		}
		usort( $out, static function ( array $a, array $b ): int { if ( $a['usedByDsa'] !== $b['usedByDsa'] ) return $a['usedByDsa'] ? -1 : 1; if ( $a['usedByDsa'] ) return $a['position'] <=> $b['position']; return strnatcasecmp( $a['name'], $b['name'] ); } );
		return $out;
	}
	/** Read-only connection status. The technical owner still chooses the DSA menu. */
	private function dsa_menu_ids(): array {
		$settings = defined( 'DSA_OPTION_SETTINGS' ) ? get_option( DSA_OPTION_SETTINGS, [] ) : [];
		$dock = is_array( $settings ) ? (array) ( $settings['dock'] ?? [] ) : [];
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $dock['menu_nav_ids'] ?? [] ) ) ) ) );
		if ( ! $ids && ! empty( $dock['menu_nav_id'] ) ) $ids[] = absint( $dock['menu_nav_id'] );
		return array_values( array_unique( array_filter( array_map( 'absint', (array) apply_filters( 'kiwe_workspace_dsa_menu_ids', $ids ) ) ) ) );
	}
	private function group_limits(): array {
		$settings = defined( 'DSA_OPTION_SETTINGS' ) ? get_option( DSA_OPTION_SETTINGS, [] ) : [];
		$dock = is_array( $settings ) ? (array) ( $settings['dock'] ?? [] ) : [];
		$limits = [];
		foreach ( (array) ( $dock['menu_group_limits'] ?? [] ) as $id=>$limit ) if ( absint( $id ) && is_numeric( $limit ) ) $limits[ absint( $id ) ] = max( 1, min( self::MAX_VISIBLE_ITEMS, (int) $limit ) );
		ksort( $limits, SORT_NUMERIC );
		return $limits;
	}
	/** A fingerprint of the existing WordPress menu selection and DSA display policy. */
	public function group_expected(): string {
		$raw = get_option( DSA_OPTION_SETTINGS, [] ); $dock = (array) ( $raw['dock'] ?? [] );
		return hash( 'sha256', wp_json_encode( [ $this->dsa_menu_ids(), $this->group_limits(), $dock['menu_group_contents'] ?? [], $dock['menu_context_enabled'] ?? true, $dock['menu_context_title'] ?? 'Table of contents', array_map( static fn( $menu ) => [ (int) $menu->term_id, (string) $menu->name ], (array) wp_get_nav_menus() ) ] ) );
	}
	/** Presentation for one native menu; old global choices belong to its first selected group. */
	public static function menu_contents( array $dock, int $id ): array {
		$choice = $dock['menu_group_contents'][$id] ?? null;
		if ( is_array( $choice ) && is_bool( $choice['enabled'] ?? null ) && is_string( $choice['title'] ?? null ) ) return [ 'enabled'=>$choice['enabled'], 'title'=>sanitize_text_field( $choice['title'] ) ];
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $dock['menu_nav_ids'] ?? [] ) ) ) );
		$first = $ids[0] ?? absint( $dock['menu_nav_id'] ?? 0 );
		return [ 'enabled'=>$id > 0 && $id === $first && ! empty( $dock['menu_context_enabled'] ), 'title'=>sanitize_text_field( (string) ( $dock['menu_context_title'] ?? 'Table of contents' ) ) ];
	}
	/** Only Navigation can write a menu's page guide. All fields validate before one native CAS. */
	public function configure_contents( int $id, bool $enabled, string $title, string $expected ) {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return new \WP_Error( 'kiwe_menu_forbidden', 'Navigation is unavailable.', [ 'status'=>403 ] );
		if ( ! hash_equals( $this->group_expected(), $expected ) ) return new \WP_Error( 'kiwe_menu_stale', 'Navigation changed. Reload before saving page contents.', [ 'status'=>409 ] );
		if ( $id < 1 || ! wp_get_nav_menu_object( $id ) || strlen( $title ) > 160 ) return new \WP_Error( 'kiwe_menu_contents', 'Choose an existing menu and a title up to 160 characters.', [ 'status'=>400 ] );
		$title = sanitize_text_field( $title );
		if ( $enabled && '' === trim( $title ) ) return new \WP_Error( 'kiwe_menu_contents', 'Give page contents a clear title.', [ 'status'=>400 ] );
		$raw = get_option( DSA_OPTION_SETTINGS, null );
		if ( ! is_array( $raw ) || ! hash_equals( $this->group_expected(), $expected ) ) return new \WP_Error( 'kiwe_menu_stale', 'Navigation changed. Reload before saving.', [ 'status'=>409 ] );
		if ( ! ( new \DSA\Settings() )->compare_and_update_selected( $raw, [ 'dock'=>[ 'menu_group_contents'=>[ $id=>[ 'enabled'=>$enabled, 'title'=>$title ] ] ] ] ) ) return new \WP_Error( 'kiwe_menu_stale', 'Website settings changed while saving. Reload and review.', [ 'status'=>409 ] );
		do_action( 'litespeed_purge_all' );
		return true;
	}
	/** Recovery projects only the existing DSA selection; no credentials or menu records. */
	public function backup_policy(): array {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) throw new \RuntimeException( 'Navigation is unavailable.' );
		return [ 'ids'=>$this->dsa_menu_ids(),'limits'=>$this->group_limits() ];
	}
	public function create_group( string $name, string $expected ) {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return new \WP_Error( 'kiwe_menu_forbidden', 'Navigation is unavailable.', [ 'status'=>403 ] );
		if ( ! hash_equals( $this->group_expected(), $expected ) ) return new \WP_Error( 'kiwe_menu_stale', 'Navigation changed. Reload before creating a group.', [ 'status'=>409 ] );
		$name = sanitize_text_field( $name );
		if ( '' === $name || preg_match_all( '/./us', $name ) > 80 ) return new \WP_Error( 'kiwe_menu_name', 'Use a group name of up to 80 characters.', [ 'status'=>400 ] );
		$existing = (array) wp_get_nav_menus();
		if ( count( $existing ) >= 100 || count( $this->dsa_menu_ids() ) >= self::GROUP_LIMIT ) return new \WP_Error( 'kiwe_menu_capacity', 'Navigation has reached its group limit.', [ 'status'=>409 ] );
		foreach ( $existing as $menu ) if ( 0 === strcasecmp( (string) $menu->name, $name ) ) return new \WP_Error( 'kiwe_menu_duplicate', 'A group with this name already exists.', [ 'status'=>409 ] );
		$id = wp_create_nav_menu( $name );
		if ( is_wp_error( $id ) || ! $id || ! wp_get_nav_menu_object( $id ) ) return new \WP_Error( 'kiwe_menu_create', 'The group could not be confirmed.', [ 'status'=>409 ] );
		$settings = (array) get_option( DSA_OPTION_SETTINGS, [] ); $dock = (array) ( $settings['dock'] ?? [] );
		$ids = $this->dsa_menu_ids(); $ids[] = (int) $id;
		$dock['menu_nav_ids'] = $ids; $dock['menu_nav_id'] = $ids[0];
		$dock['menu_group_limits'] = array_replace( $this->group_limits(), [ (int) $id=>self::DEFAULT_VISIBLE_ITEMS ] );
		$settings['dock'] = $dock; update_option( DSA_OPTION_SETTINGS, $settings, false );
		if ( ! in_array( (int) $id, $this->dsa_menu_ids(), true ) ) return new \WP_Error( 'kiwe_menu_unconfirmed', 'The new group was created but its visitor visibility was not confirmed. Reload and review.', [ 'status'=>409 ] );
		return (int) $id;
	}

	/** Rename the original WordPress menu; no Kiwe copy of its links is made. */
	public function rename_group( int $id, string $name, string $expected ) {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return new \WP_Error( 'kiwe_menu_forbidden', 'Navigation is unavailable.', [ 'status'=>403 ] );
		if ( ! hash_equals( $this->group_expected(), $expected ) ) return new \WP_Error( 'kiwe_menu_stale', 'Navigation changed. Reload before renaming this group.', [ 'status'=>409 ] );
		$menu = $id > 0 ? wp_get_nav_menu_object( $id ) : false;
		if ( ! $menu ) return new \WP_Error( 'kiwe_menu_missing', 'Choose an existing group.', [ 'status'=>404 ] );
		$name = sanitize_text_field( $name );
		if ( '' === $name || preg_match_all( '/./us', $name ) > 80 ) return new \WP_Error( 'kiwe_menu_name', 'Use a group name of up to 80 characters.', [ 'status'=>400 ] );
		foreach ( (array) wp_get_nav_menus() as $other ) if ( (int) $other->term_id !== $id && 0 === strcasecmp( (string) $other->name, $name ) ) return new \WP_Error( 'kiwe_menu_duplicate', 'A group with this name already exists.', [ 'status'=>409 ] );
		if ( (string) $menu->name === $name ) return true;
		$result = wp_update_nav_menu_object( $id, [ 'menu-name'=>$name ] );
		$updated = wp_get_nav_menu_object( $id );
		if ( is_wp_error( $result ) || ! $updated || (string) $updated->name !== $name ) return new \WP_Error( 'kiwe_menu_rename', 'The group name could not be confirmed. Reload and review.', [ 'status'=>409 ] );
		return true;
	}

	/** A confirmed deletion removes a WordPress menu and its links, never source records. */
	public function delete_group( int $id, string $expected, string $menu_expected ) {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return new \WP_Error( 'kiwe_menu_forbidden', 'Navigation is unavailable.', [ 'status'=>403 ] );
		if ( ! hash_equals( $this->group_expected(), $expected ) ) return new \WP_Error( 'kiwe_menu_stale', 'Navigation changed. Reload before deleting this group.', [ 'status'=>409 ] );
		$menu = $id > 0 ? wp_get_nav_menu_object( $id ) : false;
		if ( ! $menu ) return new \WP_Error( 'kiwe_menu_missing', 'Choose an existing group.', [ 'status'=>404 ] );
		$items = $this->items( $id );
		if ( is_wp_error( $items ) ) return $items;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $menu_expected ) || ! hash_equals( self::fingerprint( $items ), $menu_expected ) ) return new \WP_Error( 'kiwe_menu_stale', 'The links changed. Reload before deleting this group.', [ 'status'=>409 ] );
		foreach ( (array) get_nav_menu_locations() as $menu_id ) if ( $id === absint( $menu_id ) ) return new \WP_Error( 'kiwe_menu_theme', 'This group is used by the website theme. Move that placement before deleting it.', [ 'status'=>409 ] );
		$result = wp_delete_nav_menu( $id );
		if ( is_wp_error( $result ) || ! $result || wp_get_nav_menu_object( $id ) ) return new \WP_Error( 'kiwe_menu_delete', 'The group could not be confirmed as deleted. Reload and review.', [ 'status'=>409 ] );
		$ids = array_values( array_filter( $this->dsa_menu_ids(), static fn( int $selected ): bool => $selected !== $id ) );
		$limits = $this->group_limits(); unset( $limits[ $id ] );
		$settings = (array) get_option( DSA_OPTION_SETTINGS, [] ); $dock = (array) ( $settings['dock'] ?? [] );
		$dock['menu_nav_ids'] = $ids; $dock['menu_nav_id'] = $ids[0] ?? 0; $dock['menu_group_limits'] = $limits;
		unset( $dock['menu_group_contents'][$id] );
		$settings['dock'] = $dock; update_option( DSA_OPTION_SETTINGS, $settings, false );
		if ( in_array( $id, $this->dsa_menu_ids(), true ) || isset( $this->group_limits()[ $id ] ) ) return new \WP_Error( 'kiwe_menu_unconfirmed', 'The group was removed but its visitor setting needs review.', [ 'status'=>409 ] );
		return true;
	}
	/** Reorders visible groups without changing WordPress menu contents or theme locations. */
	public function configure_groups( array $ids, array $limits, string $expected ) {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return new \WP_Error( 'kiwe_menu_forbidden', 'Navigation is unavailable.', [ 'status'=>403 ] );
		if ( ! hash_equals( $this->group_expected(), $expected ) ) return new \WP_Error( 'kiwe_menu_stale', 'Navigation changed. Reload before saving group choices.', [ 'status'=>409 ] );
		if ( count( $ids ) > self::GROUP_LIMIT || count( $ids ) !== count( array_unique( $ids ) ) ) return new \WP_Error( 'kiwe_menu_groups', 'Choose no more than ten different groups.', [ 'status'=>400 ] );
		foreach ( $ids as $id ) if ( ! is_int( $id ) || $id < 1 || ! wp_get_nav_menu_object( $id ) ) return new \WP_Error( 'kiwe_menu_groups', 'Choose existing WordPress menu groups.', [ 'status'=>400 ] );
		$clean = [];
		foreach ( $limits as $id=>$limit ) {
			if ( ! ctype_digit( (string) $id ) || ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_VISIBLE_ITEMS || ! wp_get_nav_menu_object( (int) $id ) ) return new \WP_Error( 'kiwe_menu_limit', 'Choose between 1 and 200 links per group.', [ 'status'=>400 ] );
			$items = $this->items( (int) $id );
			if ( is_wp_error( $items ) ) return $items;
			if ( count( $items ) > $limit ) return new \WP_Error( 'kiwe_menu_limit', 'Remove links before lowering this group limit; existing links must remain visible.', [ 'status'=>409 ] );
			$clean[ (int) $id ] = $limit;
		}
		ksort( $clean, SORT_NUMERIC );
		$settings = (array) get_option( DSA_OPTION_SETTINGS, [] ); $dock = (array) ( $settings['dock'] ?? [] );
		$dock['menu_nav_ids'] = $ids; $dock['menu_nav_id'] = $ids[0] ?? 0; $dock['menu_group_limits'] = $clean;
		$settings['dock'] = $dock; update_option( DSA_OPTION_SETTINGS, $settings, false );
		if ( $this->dsa_menu_ids() !== $ids || $this->group_limits() !== $clean ) return new \WP_Error( 'kiwe_menu_unconfirmed', 'Group choices could not be confirmed. Reload and review.', [ 'status'=>409 ] );
		return true;
	}
	/** Only the menus actually configured for the visitor DSA are card toggles. */
	public function dsa_menus(): array {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return [];
		$out = [];
		foreach ( array_slice( $this->dsa_menu_ids(), 0, 10 ) as $id ) {
			$items = $this->items( $id ); $menu = wp_get_nav_menu_object( $id );
			if ( ! is_wp_error( $items ) && $menu ) $out[] = [ 'id'=>$id, 'name'=>sanitize_text_field( (string) $menu->name ), 'items'=>$this->project_items( $items ), 'expected'=>self::fingerprint( $items ) ];
		}
		return $out;
	}
	public function project_items( array $items ): array {
		$out = [];
		foreach ( $items as $item ) $out[] = [ 'id'=>(int) $item->ID, 'title'=>sanitize_text_field( (string) $item->title ), 'label'=>sanitize_text_field( (string) $item->post_title ), 'url'=>esc_url_raw( (string) $item->url ), 'object'=>sanitize_key( (string) $item->object ), 'objectId'=>(int) $item->object_id, 'type'=>sanitize_key( (string) $item->type ), 'parentId'=>(int) $item->menu_item_parent, 'order'=>(int) $item->menu_order ];
		return $out;
	}
	/** Move existing leaf links between native menus, retaining their item IDs and metadata. */
	public function move_items( int $source_id, int $target_id, array $item_ids, string $source_expected, string $target_expected ) {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return new \WP_Error( 'kiwe_menu_forbidden', 'Navigation is unavailable.', [ 'status'=>403 ] );
		if ( $source_id < 1 || $target_id < 1 || $source_id === $target_id || ! wp_get_nav_menu_object( $source_id ) || ! wp_get_nav_menu_object( $target_id ) ) return new \WP_Error( 'kiwe_menu_target', 'Choose another existing menu category.', [ 'status'=>400 ] );
		if ( ! $item_ids || count( $item_ids ) > 20 || count( $item_ids ) !== count( array_unique( $item_ids ) ) ) return new \WP_Error( 'kiwe_menu_selection', 'Choose up to 20 different links to move.', [ 'status'=>400 ] );
		foreach ( $item_ids as $id ) if ( ! is_int( $id ) || $id < 1 ) return new \WP_Error( 'kiwe_menu_selection', 'Choose existing menu links.', [ 'status'=>400 ] );
		$source = $this->items( $source_id ); $target = $this->items( $target_id );
		if ( is_wp_error( $source ) ) return $source; if ( is_wp_error( $target ) ) return $target;
		if ( ! hash_equals( self::fingerprint( $source ), $source_expected ) || ! hash_equals( self::fingerprint( $target ), $target_expected ) ) return new \WP_Error( 'kiwe_menu_stale', 'A menu changed. Reload before moving links.', [ 'status'=>409 ] );
		$limit = $this->group_limits()[ $target_id ] ?? max( self::DEFAULT_VISIBLE_ITEMS, count( $target ) );
		if ( count( $target ) + count( $item_ids ) > min( self::ITEM_LIMIT, $limit ) ) return new \WP_Error( 'kiwe_menu_full', 'The destination has reached its link limit.', [ 'status'=>409 ] );
		$by_id = []; $children = [];
		foreach ( $source as $item ) { $by_id[ (int) $item->ID ] = $item; if ( $item->menu_item_parent ) $children[ (int) $item->menu_item_parent ] = true; }
		$existing = [];
		foreach ( $target as $item ) $existing[ 'custom' === $item->type ? 'custom:' . $item->url : $item->type . ':' . $item->object . ':' . $item->object_id ] = true;
		foreach ( $item_ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) || 'nav_menu_item' !== get_post_type( $id ) ) return new \WP_Error( 'kiwe_menu_selection', 'A selected link is no longer in this menu.', [ 'status'=>409 ] );
			if ( isset( $children[ $id ] ) ) return new \WP_Error( 'kiwe_menu_children', 'Move the links inside this item first, then move their parent.', [ 'status'=>409 ] );
			$item = $by_id[ $id ]; $key = 'custom' === $item->type ? 'custom:' . $item->url : $item->type . ':' . $item->object . ':' . $item->object_id;
			if ( isset( $existing[ $key ] ) ) return new \WP_Error( 'kiwe_menu_duplicate', 'A selected link already exists in the destination.', [ 'status'=>409 ] );
			$existing[ $key ] = true;
		}
		$moved = []; $position = 0;
		foreach ( $target as $item ) $position = max( $position, (int) $item->menu_order );
		foreach ( $item_ids as $id ) {
			$item = $by_id[ $id ]; $moved[] = [ $id, (int) $item->menu_item_parent, (int) $item->menu_order ];
			$assigned = wp_set_object_terms( $id, [ $target_id ], 'nav_menu', false );
			if ( ! is_wp_error( $assigned ) ) {
				update_post_meta( $id, '_menu_item_menu_item_parent', 0 );
				$updated = wp_update_post( [ 'ID'=>$id, 'menu_order'=>++$position ], true );
				if ( ! is_wp_error( $updated ) && $updated ) continue;
			}
			$this->restore_moved_items( $source_id, $moved );
			return new \WP_Error( 'kiwe_menu_move', 'The move could not be confirmed. Reload both menu categories before retrying.', [ 'status'=>409 ] );
		}
		$after_source = $this->items( $source_id ); $after_target = $this->items( $target_id );
		$source_ids = is_array( $after_source ) ? array_map( static fn( $item ): int => (int) $item->ID, $after_source ) : [];
		$target_ids = is_array( $after_target ) ? array_map( static fn( $item ): int => (int) $item->ID, $after_target ) : [];
		if ( is_wp_error( $after_source ) || is_wp_error( $after_target ) || array_intersect( $item_ids, $source_ids ) || count( array_intersect( $item_ids, $target_ids ) ) !== count( $item_ids ) ) {
			$this->restore_moved_items( $source_id, $moved );
			return new \WP_Error( 'kiwe_menu_move', 'The move could not be confirmed. Reload both menu categories before retrying.', [ 'status'=>409 ] );
		}
		do_action( 'wp_update_nav_menu', $source_id ); do_action( 'wp_update_nav_menu', $target_id );
		return true;
	}
	private function restore_moved_items( int $source_id, array $moved ): void {
		foreach ( array_reverse( $moved ) as [ $id, $parent, $position ] ) {
			wp_set_object_terms( $id, [ $source_id ], 'nav_menu', false );
			update_post_meta( $id, '_menu_item_menu_item_parent', $parent );
			wp_update_post( [ 'ID'=>$id, 'menu_order'=>$position ] );
		}
	}
	/** Public sources are bounded and limited to objects the menu editor can read. */
	public function sources( string $kind, string $search = '', int $page = 1 ): array {
		$search = Task_Workspace_UI::text( $search ); $page = max( 1, min( 10000, $page ) ); $records = [];
		$taxonomies = [ 'category', 'product_cat' ];
		if ( class_exists( Taxonomy_App_Service::class ) ) $taxonomies = array_keys( ( new Taxonomy_App_Service() )->definitions() );
		if ( in_array( $kind, $taxonomies, true ) ) $records = get_terms( [ 'taxonomy'=>$kind, 'hide_empty'=>false, 'number'=>21, 'offset'=>( $page - 1 ) * 20, 'search'=>$search, 'orderby'=>'name', 'order'=>'ASC' ] );
		elseif ( 'service' === $kind ) {
			$profiles = new \DSA\Onboarding\Design_Context_Profile_Service(); $stored = get_option( \DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE, [] ); $source = is_array( $stored ) ? sanitize_key( (string) ( $stored['services']['sourcePostType'] ?? '' ) ) : '';
			if ( $source && isset( $profiles->service_post_types()[ $source ] ) ) $records = get_posts( [ 'post_type'=>$source, 'post_status'=>'publish', 'posts_per_page'=>21, 'offset'=>( $page - 1 ) * 20, 's'=>$search, 'search_columns'=>[ 'post_title' ], 'orderby'=>'title', 'order'=>'ASC' ] );
		} elseif ( in_array( $kind, [ 'page', 'post', 'product' ], true ) && post_type_exists( $kind ) ) $records = get_posts( [ 'post_type'=>$kind, 'post_status'=>'publish', 'has_password'=>false, 'posts_per_page'=>21, 'offset'=>( $page - 1 ) * 20, 's'=>$search, 'search_columns'=>[ 'post_title' ], 'orderby'=>'title', 'order'=>'ASC' ] );
		$out = [];
		foreach ( array_slice( is_array( $records ) ? $records : [], 0, 20 ) as $record ) {
			$id = (int) ( in_array( $kind, $taxonomies, true ) ? $record->term_id : $record->ID ); $source = $this->source( $kind, $id );
			if ( ! is_wp_error( $source ) ) $out[] = [ 'id'=>$id, 'title'=>sanitize_text_field( (string) $source['title'] ), 'kind'=>$kind, 'object'=>$source['object'] ];
		}
		return [ 'items'=>$out, 'page'=>$page, 'has_next'=>count( $records ) > 20, 'search'=>$search ];
	}
	public static function url( string $kind = '', int $source = 0 ): string { return add_query_arg( [ 'page'=>self::PAGE, 'source_kind'=>$kind, 'source_id'=>$source ], admin_url( 'admin.php' ) ); }
	public function menu(): void {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return;
		$this->screen = (string) add_menu_page( __( 'Frontend navigation', 'dsa' ), __( 'Frontend navigation', 'dsa' ), 'kiwe_manage_navigation', self::PAGE, [ $this, 'render' ], 'dashicons-menu-alt3', 33 );
	}
	public function assets( string $hook = '' ): void {
		if ( $this->screen && $hook === $this->screen && current_user_can( 'kiwe_manage_navigation' ) ) Task_Workspace_UI::enhancement_assets();
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/menus/(?P<id>\d+)/items/(?P<item>\d+)/label', [
			'methods'=>'POST', 'callback'=>[ $this, 'rest_label' ], 'permission_callback'=>[ $this, 'rest_permission' ],
			'args'=>[ 'id'=>[ 'type'=>'integer','minimum'=>1,'required'=>true ], 'item'=>[ 'type'=>'integer','minimum'=>1,'required'=>true ], 'label'=>[ 'type'=>'string','maxLength'=>200,'required'=>true ], 'expected'=>[ 'type'=>'string','pattern'=>'^[a-f0-9]{64}$','required'=>true ] ],
		] );
	}
	public function rest_permission( \WP_REST_Request $request ): bool { return 'POST' === $request->get_method() && current_user_can( 'kiwe_manage_navigation' ); }
	public function rest_label( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->rest_permission( $request ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_menu_forbidden', __( 'Navigation is unavailable for your account.', 'dsa' ) ), 403 );
		$path = $request->get_url_params();
		$id = Task_Workspace_UI::id( $path['id'] ?? 0 ); $item_id = Task_Workspace_UI::id( $path['item'] ?? 0 );
		$before = $this->items( $id );
		if ( is_wp_error( $before ) ) return Task_Workspace_UI::failure( $before );
		$before = array_map( static fn( $item ) => clone $item, $before );
		$result = $this->change( $id, [ 'operation'=>'label', 'item_id'=>$item_id, 'expected'=>$request->get_param( 'expected' ), 'label'=>$request->get_param( 'label' ) ] );
		if ( is_wp_error( $result ) ) return Task_Workspace_UI::failure( $result, in_array( $result->get_error_code(), [ 'kiwe_menu_stale','kiwe_menu_save' ], true ) ? 409 : 400 );
		$after = $this->items( $id );
		if ( is_wp_error( $after ) ) return Task_Workspace_UI::failure( $after, 409 );
		$target = null;
		foreach ( $after as $item ) if ( (int) $item->ID === $item_id ) $target = $item;
		foreach ( $before as $item ) if ( (int) $item->ID === $item_id ) $item->post_title = sanitize_text_field( $request->get_param( 'label' ) );
		// Do not advance every open form to an unseen concurrent menu change.
		if ( ! $target || ! hash_equals( self::fingerprint( $before ), self::fingerprint( $after ) ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_menu_unconfirmed', __( 'The menu changed during the save. Reload and review it before editing another link.', 'dsa' ) ), 409 );
		return Task_Workspace_UI::response( [ 'menuId'=>$id, 'itemId'=>$item_id, 'label'=>$target->post_title, 'title'=>wp_strip_all_tags( $target->title ), 'expected'=>self::fingerprint( $after ), 'message'=>__( 'Menu label saved.', 'dsa' ) ] );
	}
	public function items( int $menu_id ) {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return new \WP_Error( 'kiwe_menu_forbidden', __( 'Navigation is unavailable for your account.', 'dsa' ) );
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu || is_wp_error( $menu ) || 'nav_menu' !== $menu->taxonomy ) return new \WP_Error( 'kiwe_menu_missing', __( 'Choose an existing menu.', 'dsa' ) );
		// ARRAY_A rewrites menu_order in core; retain native positions for validation.
		$items = wp_get_nav_menu_items( $menu_id, [ 'output'=>'OBJECT', 'post_status'=>'publish', 'nopaging'=>false, 'posts_per_page'=>self::ITEM_LIMIT + 1, 'numberposts'=>self::ITEM_LIMIT + 1, 'orderby'=>'menu_order', 'order'=>'ASC' ] );
		if ( ! is_array( $items ) || count( $items ) > self::ITEM_LIMIT ) return new \WP_Error( 'kiwe_menu_size', __( 'This menu needs designer review before it can be edited here. The workspace supports up to 200 items.', 'dsa' ) );
		return $items;
	}
	public static function fingerprint( array $items ): string {
		$state = [];
		foreach ( $items as $item ) $state[] = [ $item->ID, $item->post_title, $item->menu_order, $item->menu_item_parent, $item->type, $item->object, $item->object_id, $item->url, $item->target, $item->classes, $item->xfn, $item->description, $item->attr_title ];
		return hash( 'sha256', wp_json_encode( $state ) );
	}
	private function source( string $kind, int $id ) {
		$taxonomy_sources = ! in_array( $kind, [ 'page','post','product','service','category' ], true ) && class_exists( Taxonomy_App_Service::class ) ? ( new Taxonomy_App_Service() )->definitions() : [];
		if ( in_array( $kind, [ 'page','post','product' ], true ) ) {
			$post = get_post( $id );
			if ( $post && $kind === $post->post_type && \DSA\Onboarding\Context_Selection_Service::public_post( $post ) && current_user_can( 'read_post', $id ) ) return [ 'id'=>$id, 'title'=>$post->post_title, 'type'=>'post_type', 'object'=>$kind ];
		} elseif ( 'category' === $kind || isset( $taxonomy_sources[ $kind ] ) ) {
			$taxonomy_name = 'category' === $kind ? 'category' : sanitize_key( $kind );
			$term = get_term( $id, $taxonomy_name );
			$taxonomy = get_taxonomy( $taxonomy_name );
			if ( $term && ! is_wp_error( $term ) && $taxonomy_name === $term->taxonomy && $taxonomy && $taxonomy->public ) return [ 'id'=>$id, 'title'=>$term->name, 'type'=>'taxonomy', 'object'=>$taxonomy_name ];
		} elseif ( 'service' === $kind ) {
			$profiles = new \DSA\Onboarding\Design_Context_Profile_Service(); $stored = get_option( \DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE, [] ); $post_type = is_array( $stored ) ? sanitize_key( (string) ( $stored['services']['sourcePostType'] ?? '' ) ) : '';
			$post = get_post( $id ); $sources = $profiles->service_post_types();
			if ( $post_type && isset( $sources[ $post_type ] ) && $post && $post_type === $post->post_type && \DSA\Onboarding\Context_Selection_Service::public_post( $post ) && current_user_can( 'read_post', $id ) ) return [ 'id'=>$id, 'title'=>$post->post_title, 'type'=>'post_type', 'object'=>$post_type ];
		}
		return new \WP_Error( 'kiwe_menu_source', __( 'Choose a published, public record or category.', 'dsa' ) );
	}
	/** An explicitly linked card may be outside the first page of menu sources. */
	public function focused_source( string $kind, int $id ) {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) return new \WP_Error( 'kiwe_menu_forbidden', 'Navigation unavailable.' );
		$source = $this->source( $kind, $id );
		return is_wp_error( $source ) ? $source : [ 'id'=>$id, 'kind'=>$kind, 'title'=>sanitize_text_field( (string) $source['title'] ), 'object'=>$source['object'] ];
	}
	/** Every write derives its menu membership and mutable fields on the server. */
	public function change( int $menu_id, array $input ) {
		$items = $this->items( $menu_id );
		if ( is_wp_error( $items ) ) return $items;
		$expected = $input['expected'] ?? '';
		if ( ! is_string( $expected ) || ! hash_equals( self::fingerprint( $items ), $expected ) ) return new \WP_Error( 'kiwe_menu_stale', __( 'The menu changed since this screen opened. Refresh before making this change.', 'dsa' ) );
		$operation = $input['operation'] ?? '';
		if ( 'add' === $operation ) {
			if ( count( $items ) >= self::ITEM_LIMIT || count( $items ) >= ( $this->group_limits()[ $menu_id ] ?? max( self::DEFAULT_VISIBLE_ITEMS, count( $items ) ) ) ) return new \WP_Error( 'kiwe_menu_full', __( 'This group has reached its visible link limit. Adjust the limit in Frontend navigation before adding another.', 'dsa' ) );
			$source = $this->source( Task_Workspace_UI::text( $input['source_kind'] ?? '' ), Task_Workspace_UI::id( $input['source_id'] ?? 0 ) );
			if ( is_wp_error( $source ) ) return $source;
			$parent = Task_Workspace_UI::id( $input['parent_id'] ?? 0 );
			if ( ! $parent && ! in_array( $input['parent_id'] ?? 0, [ 0, '0' ], true ) ) return new \WP_Error( 'kiwe_menu_parent', __( 'Choose a valid menu level.', 'dsa' ) );
			$found_parent = ! $parent;
			$position = 0;
			foreach ( $items as $item ) {
				if ( $source['object'] === $item->object && $source['type'] === $item->type && $source['id'] === (int) $item->object_id ) return new \WP_Error( 'kiwe_menu_duplicate', __( 'This page or category is already in the menu. Edit its existing entry below.', 'dsa' ) );
				if ( $parent === (int) $item->ID ) $found_parent = true;
				$position = max( $position, (int) $item->menu_order );
			}
			if ( ! $found_parent || $position >= PHP_INT_MAX - 1 ) return new \WP_Error( 'kiwe_menu_parent', __( 'The destination is unavailable. Refresh the menu.', 'dsa' ) );
			$result = wp_update_nav_menu_item( $menu_id, 0, [ 'menu-item-object-id'=>$source['id'], 'menu-item-object'=>$source['object'], 'menu-item-type'=>$source['type'], 'menu-item-title'=>wp_slash( $source['title'] ), 'menu-item-parent-id'=>$parent, 'menu-item-position'=>$position + 1, 'menu-item-status'=>'publish' ] );
			if ( is_wp_error( $result ) || ! $result || true !== is_object_in_term( (int) $result, 'nav_menu', $menu_id ) ) return new \WP_Error( 'kiwe_menu_add', __( 'The new entry could not be confirmed. Refresh before trying again.', 'dsa' ) );
			do_action( 'wp_update_nav_menu', $menu_id );
			return true;
		}
		$id = Task_Workspace_UI::id( $input['item_id'] ?? 0 );
		$target = null;
		foreach ( $items as $item ) if ( $id === (int) $item->ID ) $target = $item;
		if ( ! $target || 'nav_menu_item' !== get_post_type( $id ) ) return new \WP_Error( 'kiwe_menu_item', __( 'This entry is no longer in the selected menu.', 'dsa' ) );
		if ( 'label' === $operation ) {
			if ( ! isset( $input['label'] ) || ! is_string( $input['label'] ) ) return new \WP_Error( 'kiwe_menu_label', __( 'Enter a menu label, or leave it empty to follow the page or category name.', 'dsa' ) );
			$label = sanitize_text_field( $input['label'] );
			$length = preg_match_all( '/./us', $label );
			if ( false === $length || $length > 200 || ( '' === $label && 'custom' === $target->type ) ) return new \WP_Error( 'kiwe_menu_label', __( 'Use a label of up to 200 characters. Custom links require a label.', 'dsa' ) );
			if ( $label === $target->post_title ) return true;
			// Core's menu helper supplies destructive defaults for omitted fields. A label-only
			// native post update preserves URL, parent, classes, target, XFN and all metadata.
			$result = wp_update_post( wp_slash( [ 'ID'=>$id, 'post_title'=>$label, 'post_name'=>get_post_field( 'post_name', $id, 'raw' ) ] ), true );
			if ( is_wp_error( $result ) || $label !== get_post_field( 'post_title', $id, 'raw' ) ) return new \WP_Error( 'kiwe_menu_save', __( 'The label could not be confirmed. Refresh and review the entry.', 'dsa' ) );
		} elseif ( 'remove' === $operation ) {
			foreach ( $items as $item ) if ( $id === (int) $item->menu_item_parent ) return new \WP_Error( 'kiwe_menu_children', __( 'This entry contains other links. Ask your designer to move those links before removing their parent.', 'dsa' ) );
			if ( ! wp_delete_post( $id, true ) || get_post( $id ) ) return new \WP_Error( 'kiwe_menu_remove', __( 'The entry could not be removed. Refresh and try again.', 'dsa' ) );
		} else return new \WP_Error( 'kiwe_menu_operation', __( 'Unknown menu action.', 'dsa' ) );
		do_action( 'wp_update_nav_menu', $menu_id );
		return true;
	}
	public function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'kiwe_manage_navigation' ) ) wp_die( esc_html__( 'Menu request unavailable.', 'dsa' ), '', [ 'response'=>403 ] );
		$id = Task_Workspace_UI::id( $_POST['menu_id'] ?? 0 );
		check_admin_referer( self::ACTION . '_' . $id );
		$result = $this->change( $id, (array) wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) { Task_Workspace_UI::error( $result ); return; }
		Task_Workspace_UI::finish( add_query_arg( 'menu_id', $id, self::url() ), __( 'Menu saved. Review it on your website.', 'dsa' ) );
	}
	public function render(): void {
		if ( ! current_user_can( 'kiwe_manage_navigation' ) ) wp_die( esc_html__( 'Navigation unavailable.', 'dsa' ), '', [ 'response'=>403 ] );
		$id = Task_Workspace_UI::id( $_GET['menu_id'] ?? 0 );
		$kind = 'category' === ( $_GET['source_kind'] ?? '' ) ? 'category' : 'page';
		$source_id = Task_Workspace_UI::id( $_GET['source_id'] ?? 0 );
		echo '<div class="wrap kiwe-tasks kiwe-task-app" data-kiwe-menu><p class="kiwe-tasks__eyebrow">' . esc_html__( 'Your website', 'dsa' ) . '</p><h1>' . esc_html__( 'Frontend navigation', 'dsa' ) . '</h1><p>' . esc_html__( 'Choose a menu to add a page or category, change a label, or remove a link. Menus connected to your website and DSA use these same entries.', 'dsa' ) . '</p>';
		Task_Workspace_UI::receipt();
		$menus = wp_get_nav_menus( [ 'number'=>101, 'orderby'=>'name', 'order'=>'ASC' ] );
		if ( ! $id ) {
			echo '<section class="kiwe-tasks__card kiwe-app-card"><h2>' . esc_html__( 'Choose a menu', 'dsa' ) . '</h2><ul class="kiwe-tasks__list">';
			foreach ( array_slice( is_array( $menus ) ? $menus : [], 0, 100 ) as $menu ) echo '<li><a href="' . esc_url( add_query_arg( 'menu_id', $menu->term_id, self::url( $kind, $source_id ) ) ) . '">' . esc_html( $menu->name ) . '</a></li>';
			echo '</ul><p>' . esc_html__( 'Your designer sets where each menu appears. If a menu is missing here, ask your designer to connect or review it.', 'dsa' ) . '</p></section></div>';
			return;
		}
		$items = $this->items( $id );
		if ( is_wp_error( $items ) ) { Task_Workspace_UI::error( $items ); return; }
		$menu = wp_get_nav_menu_object( $id );
		$expected = self::fingerprint( $items );
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'All menus', 'dsa' ) . '</a></p><h2>' . esc_html( $menu->name ) . '</h2>';
		$this->render_sources( $id, $kind, $source_id, $expected, $items );
		echo '<h2>' . esc_html__( 'Current links', 'dsa' ) . '</h2><p>' . esc_html__( 'Links are listed in their current order. Nested entries show their parent. Your designer can rearrange existing links and connect menus to new locations.', 'dsa' ) . '</p><div class="kiwe-tasks__grid kiwe-navigation__links">';
		$titles = []; foreach ( $items as $item ) $titles[ $item->ID ] = $item->title;
		foreach ( $items as $item ) {
			echo '<article class="kiwe-tasks__card kiwe-app-card"><h2 data-kiwe-menu-title="' . esc_attr( (string) $item->ID ) . '">' . esc_html( $item->title ) . '</h2><p>' . esc_html( $item->url ) . '</p>';
			if ( $item->menu_item_parent ) echo '<p>' . esc_html__( 'Inside:', 'dsa' ) . ' <span data-kiwe-menu-title="' . esc_attr( (string) $item->menu_item_parent ) . '">' . esc_html( $titles[ $item->menu_item_parent ] ?? __( 'Unavailable parent', 'dsa' ) ) . '</span></p>';
			Task_Workspace_UI::form( self::ACTION, self::ACTION . '_' . $id, [ 'menu_id'=>$id, 'item_id'=>$item->ID, 'expected'=>$expected, 'operation'=>'label' ], 'menu-label', rest_url( 'dsa/v1/workspace/menus/' . $id . '/items/' . $item->ID . '/label' ) );
			echo '<label>' . esc_html__( 'Custom label', 'dsa' ) . '<input name="label" maxlength="200" value="' . esc_attr( $item->post_title ) . '"' . ( 'custom' === $item->type ? ' required' : '' ) . '></label><p>' . esc_html__( 'Leave empty to follow the original name. Custom links need a label.', 'dsa' ) . '</p><button type="submit" class="button button-primary">' . esc_html__( 'Save label', 'dsa' ) . '</button>';
			Task_Workspace_UI::feedback( true );
			echo '</form>';
			$children = false; foreach ( $items as $child ) if ( (int) $item->ID === (int) $child->menu_item_parent ) $children = true;
			if ( ! $children ) {
				Task_Workspace_UI::form( self::ACTION, self::ACTION . '_' . $id, [ 'menu_id'=>$id, 'item_id'=>$item->ID, 'expected'=>$expected, 'operation'=>'remove' ] );
				echo '<p><button class="button">' . esc_html__( 'Remove link from menu', 'dsa' ) . '</button></p></form><p>' . esc_html__( 'The original page or category stays available.', 'dsa' ) . '</p>';
			}
			echo '</article>';
		}
		if ( ! $items ) echo '<p>' . esc_html__( 'This menu is empty. Add its first link above.', 'dsa' ) . '</p>';
		echo '</div></div>';
	}
	private function render_sources( int $menu_id, string $kind, int $source_id, string $expected, array $items ): void {
		$search = Task_Workspace_UI::text( wp_unslash( $_GET['search'] ?? '' ) );
		$page = min( 10000, max( 1, Task_Workspace_UI::id( $_GET['result_page'] ?? 1 ) ) );
		echo '<section class="kiwe-tasks__card kiwe-app-card"><h2>' . esc_html__( 'Add a link', 'dsa' ) . '</h2><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="kiwe-tasks__filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="menu_id" value="' . esc_attr( (string) $menu_id ) . '"><label>' . esc_html__( 'Find', 'dsa' ) . '<select name="source_kind"><option value="page"' . selected( $kind, 'page', false ) . '>' . esc_html__( 'Pages', 'dsa' ) . '</option><option value="category"' . selected( $kind, 'category', false ) . '>' . esc_html__( 'Categories', 'dsa' ) . '</option></select></label><label>' . esc_html__( 'Search by name', 'dsa' ) . '<input name="search" type="search" maxlength="200" value="' . esc_attr( $search ) . '"></label><button class="button">' . esc_html__( 'Find', 'dsa' ) . '</button></form>';
		$records = 'category' === $kind ? get_terms( [ 'taxonomy'=>'category', 'hide_empty'=>false, 'number'=>21, 'offset'=>( $page - 1 ) * 20, 'search'=>$search, 'orderby'=>'name', 'order'=>'ASC' ] ) : get_posts( [ 'post_type'=>'page', 'post_status'=>'publish', 'has_password'=>false, 'posts_per_page'=>21, 'offset'=>( $page - 1 ) * 20, 's'=>$search, 'orderby'=>'title', 'order'=>'ASC' ] );
		$records = is_array( $records ) ? $records : [];
		$sources = [];
		if ( $source_id ) { $source = $this->source( $kind, $source_id ); if ( ! is_wp_error( $source ) ) $sources[ $source_id ] = $source; }
		foreach ( array_slice( $records, 0, 20 ) as $record ) {
			$record_id = (int) ( 'category' === $kind ? $record->term_id : $record->ID );
			$source = $this->source( $kind, $record_id );
			if ( ! is_wp_error( $source ) ) $sources[ $record_id ] = $source;
		}
		foreach ( $items as $item ) {
			if ( $kind === $item->object ) unset( $sources[ (int) $item->object_id ] );
		}
		if ( $sources ) {
			Task_Workspace_UI::form( self::ACTION, self::ACTION . '_' . $menu_id, [ 'menu_id'=>$menu_id, 'expected'=>$expected, 'operation'=>'add', 'source_kind'=>$kind ] );
			echo '<label>' . esc_html__( 'Page or category', 'dsa' ) . '<select name="source_id" required>';
			foreach ( $sources as $source ) echo '<option value="' . esc_attr( (string) $source['id'] ) . '">' . esc_html( $source['title'] ) . '</option>';
			echo '</select></label><label>' . esc_html__( 'Add at the end of', 'dsa' ) . '<select name="parent_id"><option value="0">' . esc_html__( 'Main menu level', 'dsa' ) . '</option>';
			foreach ( $items as $item ) echo '<option value="' . esc_attr( (string) $item->ID ) . '">' . esc_html( $item->title ) . '</option>';
			echo '</select></label><button class="button button-primary">' . esc_html__( 'Add link', 'dsa' ) . '</button></form>';
		} else echo '<p>' . esc_html__( 'No new links matched this page of results. Matching entries may already be in the menu below. Try another search or the next page.', 'dsa' ) . '</p>';
		Task_Workspace_UI::pagination( add_query_arg( [ 'menu_id'=>$menu_id, 'search'=>$search ], self::url( $kind ) ), $page, count( $records ) > 20 );
		echo '</section>';
	}
}
