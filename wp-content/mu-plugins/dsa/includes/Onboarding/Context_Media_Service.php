<?php
namespace DSA\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native image relationships, separate from the owner's explicit design-media choice. */
final class Context_Media_Service {
	public const INLINE_META = '_kiwe_context_inline_images';
	public const INDEX_OPTION = 'kiwe_context_image_index_v1';
	public const INDEX_HOOK = 'kiwe_context_image_index';
	private static array $states = [];
	public function register(): void {
		foreach ( [ 'clean_post_cache','added_post_meta','updated_post_meta','deleted_post_meta','added_user_meta','updated_user_meta','deleted_user_meta','updated_option' ] as $hook ) add_action( $hook, [ self::class, 'invalidate' ] );
		add_action( 'save_post', [ self::class, 'index_post' ], 20 );
		foreach ( [ 'added_post_meta','updated_post_meta','deleted_post_meta' ] as $hook ) add_action( $hook, [ self::class, 'builder_changed' ], 20, 3 );
		add_action( self::INDEX_HOOK, [ self::class, 'backfill' ] );
	}
	public static function invalidate(): void { self::$states = []; }
	public static function builder_changed( $meta_id, int $post_id, string $key ): void { if ( in_array( $key, [ '_bricks_page_content_2','_bricks_page_header_2','_bricks_page_footer_2' ], true ) ) self::index_post( $post_id ); }
	/** Small rebuildable reverse hint on the original post, never a second content store. */
	public static function index_post( int $id ): void {
		$post = get_post( $id );
		if ( ! $post || in_array( $post->post_type, [ 'attachment','revision','nav_menu_item','product_variation','shop_order','shop_order_refund','shop_subscription' ], true ) ) return;
		$ids = [];
		preg_match_all( '/<img\b[^>]*\bclass=["\x27][^"\x27]*\bwp-image-([1-9][0-9]*)\b|<!--\s*wp:image\s+\{[^}]*"id"\s*:\s*([1-9][0-9]*)/i', $post->post_content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) $ids[] = (int) ( $match[1] ?: ( $match[2] ?? 0 ) );
		preg_match_all( '/\[gallery\b[^\]]*\bids=["\x27]([0-9,\s]+)["\x27]/', $post->post_content, $galleries );
		foreach ( $galleries[1] as $gallery ) $ids = array_merge( $ids, array_map( 'absint', explode( ',', $gallery ) ) );
		preg_match_all( '/<!--\s*wp:gallery\s+\{[^}]*"ids"\s*:\s*\[([0-9,\s]+)\]/', $post->post_content, $galleries );
		foreach ( $galleries[1] as $gallery ) $ids = array_merge( $ids, array_map( 'absint', explode( ',', $gallery ) ) );
		$walk = static function( $value, string $key = '', int $depth = 0 ) use ( &$walk, &$ids ): void {
			if ( ! is_array( $value ) || $depth>30 ) return;
			if ( isset( $value['id'] ) && is_numeric( $value['id'] ) && ( isset( $value['url'] ) || preg_match( '/image|gallery|background/i', $key ) ) ) $ids[] = absint( $value['id'] );
			foreach ( $value as $name=>$child ) if ( is_array( $child ) ) $walk( $child, is_string( $name ) ? $name : $key, $depth+1 );
		};
		foreach ( [ '_bricks_page_content_2','_bricks_page_header_2','_bricks_page_footer_2' ] as $key ) $walk( get_post_meta( $id, $key, true ) );
		$ids = array_values( array_filter( array_unique( $ids ), 'wp_attachment_is_image' ) ); sort( $ids );
		$csv = implode( ',', $ids );
		if ( (string) get_post_meta( $id, self::INLINE_META, true ) === $csv ) return;
		if ( $csv ) update_post_meta( $id, self::INLINE_META, $csv ); else delete_post_meta( $id, self::INLINE_META );
		self::invalidate();
	}
	/** Existing sites are indexed in resumable 50-record background slices, triggered only by a private media task. */
	public static function backfill(): array {
		global $wpdb;
		$state = (array) get_option( self::INDEX_OPTION, [] );
		if ( ! empty( $state['done'] ) ) return $state;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_type NOT IN ('attachment','revision','nav_menu_item','product_variation') ORDER BY ID ASC LIMIT 50", (int) ( $state['cursor'] ?? 0 ) ) );
		foreach ( $ids as $id ) self::index_post( (int) $id );
		$state = [ 'cursor'=>$ids ? (int) end( $ids ) : (int) ( $state['cursor'] ?? 0 ), 'done'=>count( $ids )<50 ];
		update_option( self::INDEX_OPTION, $state, false );
		if ( ! $state['done'] && ! wp_next_scheduled( self::INDEX_HOOK ) ) wp_schedule_single_event( time()+60, self::INDEX_HOOK );
		return $state;
	}
	public static function ensure_index(): bool {
		$state = (array) get_option( self::INDEX_OPTION, [] );
		if ( ! empty( $state['done'] ) ) return true;
		if ( is_user_logged_in() && current_user_can( 'kiwe_manage_context' ) && ! wp_next_scheduled( self::INDEX_HOOK ) ) wp_schedule_single_event( time()+5, self::INDEX_HOOK );
		return false;
	}
	/** No copied membership or visitor job: this predicate is used only by requested media projections. */
	public static function automatic_sql( string $alias, bool $public = false ): string {
		global $wpdb;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $alias ) ) return '0=1';
		$types = get_post_types( [ 'public'=>true ], 'names' );
		$types = array_values( array_diff( $types, [ 'attachment','product_variation' ] ) );
		$slots = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$active = $public ? ( $types ? $wpdb->prepare( "p.post_status='publish' AND p.post_password='' AND p.post_type IN ($slots)", ...$types ) : '0=1' ) : "p.post_status NOT IN ('trash','auto-draft') AND p.post_type NOT IN ('attachment','revision','nav_menu_item')";
		$profile = (array) get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] );
		$ids = [ (int) get_option( 'site_icon', 0 ), \DSA\Site\Site_Identity_Service::attachment_id(), \DSA\Site\Site_Identity_Service::attachment_id( \DSA\Site\Site_Identity_Service::OPTION_LOGO_INVERSE ) ];
		foreach ( ['squareLogoId','roundLogoId'] as $brand_image ) $ids[] = (int) ($profile['identity'][$brand_image] ?? 0);
		if ( empty( $profile['about']['founder']['userId'] ) ) $ids[] = (int) ( $profile['about']['founder']['imageId'] ?? 0 );
		if ( empty( $profile['services']['sourcePostType'] ) && ( ! $public || ! empty( $profile['services']['enabled'] ) ) ) foreach ( (array) ( $profile['services']['items'] ?? [] ) as $item ) $ids[] = (int) ( $item['imageId'] ?? 0 );
		$ids = array_values( array_filter( array_unique( $ids ) ) );
		$identity = $ids ? "$alias.ID IN (" . implode( ',', $ids ) . ')' : '0=1';
		$avatar = "EXISTS (SELECT 1 FROM {$wpdb->usermeta} u WHERE u.meta_key='kiwe_avatar_id' AND u.meta_value=CAST($alias.ID AS CHAR)";
		if ( $public ) {
			$founder = (int) ( $profile['about']['founder']['userId'] ?? 0 );
			$team = $profile['about']['team']['enabledByOwner'] ?? true;
			$members = $team ? get_users( [ 'number'=>100,'fields'=>'ID','meta_key'=>Design_Context_Profile_Service::USER_META_TEAM_MEMBER,'meta_value'=>'1','count_total'=>false ] ) : [];
			$members = array_filter( array_map( 'intval', $members ), [ User_Profile_Service::class, 'eligible' ] );
			$avatar .= " AND (u.user_id=$founder" . ( $members ? ' OR u.user_id IN (' . implode( ',', $members ) . ')' : '' ) . ')';
		}
		$avatar .= ')';
		$gallery = function_exists( 'WC' ) ? " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} r JOIN {$wpdb->posts} p ON p.ID=r.post_id WHERE r.meta_key='_product_image_gallery' AND p.post_type='product' AND FIND_IN_SET(CAST($alias.ID AS CHAR),r.meta_value)>0 AND $active)" : '';
		$inline = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} r JOIN {$wpdb->posts} p ON p.ID=r.post_id WHERE r.meta_key='_kiwe_context_inline_images' AND FIND_IN_SET(CAST($alias.ID AS CHAR),r.meta_value)>0 AND $active)";
		return "($alias.post_mime_type LIKE 'image/%' AND ($identity OR EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID=$alias.post_parent AND $active) OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} r JOIN {$wpdb->posts} p ON p.ID=r.post_id WHERE r.meta_key='_thumbnail_id' AND r.meta_value=CAST($alias.ID AS CHAR) AND $active)$gallery OR $inline OR $avatar))";
	}
	public static function automatic( int $id, bool $public = false ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) return false;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT a.ID FROM {$wpdb->posts} a WHERE a.ID=%d AND a.post_type='attachment' AND " . self::automatic_sql( 'a', $public ) . ' LIMIT 1', $id ) );
	}
	public static function prime( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids || ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) return;
		$sql = 'SELECT a.ID, (' . self::automatic_sql( 'a' ) . ') AS automatic, (' . self::automatic_sql( 'a', true ) . ") AS public_automatic FROM {$wpdb->posts} a WHERE a.ID IN (" . implode( ',', array_slice( $ids, 0, 100 ) ) . ") AND a.post_type='attachment'";
		foreach ( (array) $wpdb->get_results( $sql ) as $row ) self::$states[ (int) $row->ID ] = [ 'automatic'=>(bool) $row->automatic,'public_automatic'=>(bool) $row->public_automatic ];
	}
	public static function state( int $id ): array {
		$explicit = Context_Selection_Service::selected( 'media', $id );
		if ( ! isset( self::$states[ $id ] ) ) self::prime( [ $id ] );
		$automatic = self::$states[ $id ]['automatic'] ?? false;
		$public = Context_Selection_Service::public_media( get_post( $id ) ) && ( ! $automatic || $explicit || ( self::$states[ $id ]['public_automatic'] ?? false ) );
		return [ 'selected'=>$explicit || $automatic, 'explicit'=>$explicit, 'automatic'=>$automatic, 'public'=>$public, 'reason'=>$automatic ? __( 'Included automatically because this image belongs to content, a profile or your business identity. Change its original use to remove this inclusion.', 'dsa' ) : '' ];
	}
	/** Shared SQL for the native Media library and paginated SiteGraph export. */
	public static function context_where( string $where, \WP_Query $query ): string {
		if ( ! $query->get( 'kiwe_context_media' ) ) return $where;
		global $wpdb;
		$explicit = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} c WHERE c.post_id={$wpdb->posts}.ID AND c.meta_key='_kiwe_design_context_media' AND c.meta_value='1')";
		return $where . " AND ($explicit OR " . self::automatic_sql( $wpdb->posts, true ) . ')';
	}
}
