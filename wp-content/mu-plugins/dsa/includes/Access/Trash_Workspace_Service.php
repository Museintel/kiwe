<?php
namespace DSA\Access;

use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private recovery over native trash. Financial orders and accounts keep their own lifecycle. */
final class Trash_Workspace_Service {
	public const PAGE = 'kiwe-trash';
	public const PAGE_SIZE = 24;
	public const REVIEW_LIMIT = 1000;
	public const CHUNK = 20;
	public static function url( string $type = '' ): string { $url = admin_url( 'admin.php?page=' . self::PAGE ); return isset( self::types()[ $type ] ) ? add_query_arg( 'type', $type, $url ) : $url; }
	public static function initial_type( $value ): string { return is_string( $value ) && isset( self::types()[ $value ] ) ? $value : ( array_key_first( self::types() ) ?? '' ); }
	public static function types(): array {
		$out = [];
		foreach ( get_post_types( [ 'show_ui'=>true ], 'objects' ) as $name=>$type ) {
			if ( 'bricks_template' === $name && ! Template_Workspace_Service::builder_actor() ) continue;
			if ( in_array( $name, [ 'attachment','revision','nav_menu_item','product_variation','shop_order','shop_order_refund','shop_subscription','customize_changeset','wp_global_styles' ], true ) || str_starts_with( $name, 'shop_order' ) || !\DSA\Apps\Store_App_Service::content_available( (string) $name ) ) continue;
			if ( current_user_can( $type->cap->delete_posts ) ) $out[ $name ] = $type->labels->name;
		}
		if ( current_user_can( 'upload_files' ) ) $out['attachment'] = __( 'Media', 'dsa' );
		if ( current_user_can( 'moderate_comments' ) ) $out['feedback'] = __( 'Feedback', 'dsa' );
		return $out;
	}
	public static function allowed(): bool { return is_user_logged_in() && (bool) self::types(); }
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 36 );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'deleted_user', [ self::class, 'retire_review' ] );
	}
	public static function retire_review( int $user ): void {
		$key = 'kiwe_trash_actor_review_' . $user;
		$token = get_option( $key );
		if ( is_string( $token ) && preg_match( '/^[a-zA-Z0-9]{40}$/', $token ) ) delete_transient( 'kiwe_trash_review_' . $token );
		delete_option( $key ); delete_option( 'kiwe_trash_lock_' . $user );
	}
	public function menu(): void {
		if ( ! self::allowed() ) return;
		$hook = add_menu_page( __( 'Trash', 'dsa' ), __( 'Trash', 'dsa' ), 'read', self::PAGE, [ $this, 'document' ], 'dashicons-trash', 36 );
		add_action( 'load-' . $hook, [ $this, 'document' ], 1 );
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/trash', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'save' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		return self::allowed() && in_array( $request->get_method(), [ 'GET','POST' ], true ) && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request() && ( 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request ) );
	}
	private function error( string $message, int $status = 400 ): \WP_Error { return new \WP_Error( 'kiwe_trash', $message, [ 'status'=>$status ] ); }
	public static function fingerprint( $record, string $type ): string {
		$id = (int) ( 'feedback' === $type ? $record->comment_ID : $record->ID );
		$state = 'feedback' === $type ? [ $record->comment_approved,$record->comment_content,$record->comment_author,$record->comment_post_ID,get_comment_meta( $id, '_wp_trash_meta_time', true ) ] : [ $record->post_type,$record->post_status,$record->post_modified_gmt,$record->post_title,$record->post_content,$record->post_excerpt,get_post_meta( $id, '_wp_trash_meta_status', true ),get_post_meta( $id, '_wp_trash_meta_time', true ),get_post_meta( $id, '_wp_attached_file', true ) ];
		$state[] = 'feedback' === $type ? get_comment_meta( $id ) : get_post_meta( $id );
		return hash( 'sha256', wp_json_encode( $state ) );
	}
	private function record( string $type, int $id, bool $trashed = true ) {
		if ( ! isset( self::types()[ $type ] ) ) return $this->error( 'This Trash tab is unavailable.', 403 );
		$record = 'feedback' === $type ? get_comment( $id ) : get_post( $id );
		if ( ! $record || ( 'feedback' !== $type && $record->post_type !== $type ) ) return $this->error( 'This record is no longer available.', 409 );
		if ( 'feedback' === $type ? ( ! current_user_can( 'edit_comment', $id ) || ! current_user_can( 'read_post', (int) $record->comment_post_ID ) ) : ! current_user_can( 'delete_post', $id ) ) return $this->error( 'You cannot remove or restore this record.', 403 );
		$is_trash = 'trash' === (string) ( 'feedback' === $type ? $record->comment_approved : $record->post_status );
		if ( $is_trash !== $trashed ) return $this->error( 'This record changed. Refresh and review its current state.', 409 );
		return $record;
	}
	private function item( $record, string $type ): array {
		$id = (int) ( 'feedback' === $type ? $record->comment_ID : $record->ID );
		return [ 'id'=>$id, 'type'=>$type, 'title'=>'feedback' === $type ? wp_trim_words( wp_strip_all_tags( $record->comment_content ), 18 ) : ( $record->post_title ?: __( 'Untitled', 'dsa' ) ), 'expected'=>self::fingerprint( $record, $type ), 'date'=>(int) ( 'feedback' === $type ? get_comment_meta( $id, '_wp_trash_meta_time', true ) : get_post_meta( $id, '_wp_trash_meta_time', true ) ), 'image'=>'attachment' === $type && wp_attachment_is_image( $id ) ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '' ];
	}
	public function listing( array $input, int $limit = self::PAGE_SIZE ): array {
		$type = sanitize_key( $input['type'] ?? array_key_first( self::types() ) ?? '' );
		if ( ! isset( self::types()[ $type ] ) ) return [ 'type'=>$type, 'items'=>[], 'hasNext'=>false, 'page'=>1, 'types'=>self::types() ];
		$page = min( 10000, max( 1, (int) ( $input['page'] ?? 1 ) ) );
		$search = Task_Workspace_UI::text( $input['search'] ?? '' );
		$limit = min( self::REVIEW_LIMIT, max( 1, $limit ) );
		if ( 'feedback' === $type ) $records = get_comments( [ 'status'=>'trash', 'number'=>$limit+1, 'offset'=>( $page-1 )*$limit, 'search'=>$search, 'orderby'=>'comment_ID','order'=>'DESC' ] );
		else {
			$args = [ 'post_type'=>$type, 'post_status'=>'trash','posts_per_page'=>$limit+1,'offset'=>( $page-1 )*$limit,'s'=>$search,'orderby'=>'ID','order'=>'DESC','no_found_rows'=>true,'update_post_term_cache'=>false ];
			$object = get_post_type_object( $type );
			if ( ! current_user_can( $object->cap->delete_others_posts ) ) $args['author'] = get_current_user_id();
			$records = ( new \WP_Query( $args ) )->posts;
		}
		$items = [];
		foreach ( array_slice( $records, 0, $limit ) as $record ) {
			$id = (int) ( 'feedback' === $type ? $record->comment_ID : $record->ID );
			if ( ! is_wp_error( $this->record( $type, $id ) ) ) $items[] = $this->item( $record, $type );
		}
		return [ 'type'=>$type, 'items'=>$items, 'hasNext'=>count( $records )>$limit, 'page'=>$page,'types'=>self::types(),'retentionDays'=>defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30 ];
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return Task_Workspace_UI::failure( $this->error( 'Trash is unavailable.', 403 ), 403 );
		if ( 'record' === $request->get_param( 'operation' ) ) {
			$type = sanitize_key( (string) $request->get_param( 'type' ) ); $id = absint( $request->get_param( 'id' ) );
			$record = $this->record( $type, $id, false );
			return is_wp_error( $record ) ? Task_Workspace_UI::failure( $record, 403 ) : Task_Workspace_UI::response( $this->item( $record, $type ) );
		}
		return Task_Workspace_UI::response( $this->listing( $request->get_params() ) );
	}
	/** Reviewed snapshot: new arrivals, another tab and later edits never enter the deletion set. */
	public function review( array $body ) {
		if ( (int) get_option( 'kiwe_trash_lock_' . get_current_user_id(), 0 )>time()-300 ) return $this->error( 'Your previous deletion is still running. Refresh before starting another review.', 409 );
		if ( ! in_array( $body['mode'] ?? '', [ 'selected','clear' ], true ) || ! is_string( $body['type'] ?? null ) || ! isset( self::types()[ $body['type'] ] ) ) return $this->error( 'Choose the Trash tab and records to review.' );
		$type = $body['type']; $more = false;
		if ( 'clear' === $body['mode'] ) { $data = $this->listing( [ 'type'=>$type ], self::REVIEW_LIMIT ); $items = $data['items']; $more = $data['hasNext']; }
		else {
			$items = [];
			if ( ! is_array( $body['items'] ?? null ) || ! array_is_list( $body['items'] ) || count( $body['items'] )>self::PAGE_SIZE ) return $this->error( 'Select records from the current page.' );
			foreach ( $body['items'] as $item ) {
				if ( ! is_array( $item ) || ! is_int( $item['id'] ?? null ) || ! is_string( $item['expected'] ?? null ) ) return $this->error( 'Refresh the current selection.' );
				$record = $this->record( $type, $item['id'] );
				if ( is_wp_error( $record ) ) return $record;
				if ( ! hash_equals( self::fingerprint( $record, $type ), $item['expected'] ) ) return $this->error( 'A selected record changed. Refresh before deleting.', 409 );
				$items[ $item['id'] ] = $this->item( $record, $type );
			}
			$items = array_values( $items );
		}
		if ( ! $items ) return $this->error( 'There are no removable records in this selection.' );
		$token = wp_generate_password( 40, false, false );
		self::retire_review( get_current_user_id() );
		update_option( 'kiwe_trash_actor_review_' . get_current_user_id(), $token, false );
		$review = [ 'user'=>get_current_user_id(), 'type'=>$type, 'items'=>$items, 'done'=>0, 'failed'=>[], 'more'=>$more ];
		set_transient( 'kiwe_trash_review_' . $token, $review, HOUR_IN_SECONDS );
		return [ 'token'=>$token,'type'=>$type,'items'=>$items,'more'=>$more,'count'=>count( $items ) ];
	}
	public function mutate( array $body ) {
		$operation = $body['operation'] ?? '';
		if ( in_array( $operation, [ 'trash','restore' ], true ) ) {
			if ( ! is_string( $body['type'] ?? null ) || ! is_array( $body['item'] ?? null ) ) return $this->error( 'Choose a valid record and Trash tab.' );
			$type = $body['type']; $item = $body['item'];
			if ( ! is_int( $item['id'] ?? null ) || ! is_string( $item['expected'] ?? null ) ) return $this->error( 'Refresh the record before continuing.' );
			$record = $this->record( $type, $item['id'], 'restore' === $operation );
			if ( is_wp_error( $record ) ) return $record;
			if ( ! hash_equals( self::fingerprint( $record, $type ), $item['expected'] ) ) return $this->error( 'This record changed. Refresh before continuing.', 409 );
			if ( 'trash' === $operation ) {
				if ( 'page' === $type && Page_Publishing_Service::critical_reason( $item['id'] ) ) return $this->error( 'This page is used by website or store settings. Reassign its original purpose before moving it to Trash.', 409 );
				if ( defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS<1 ) return $this->error( 'WordPress Trash is disabled. Nothing was deleted. Ask your administrator to enable Trash.' );
				$result = 'feedback' === $type ? wp_trash_comment( $item['id'] ) : wp_trash_post( $item['id'] );
			} elseif ( 'feedback' === $type ) $result = wp_untrash_comment( $item['id'] );
			else {
				// Core defaults restored posts to drafts; media must remain an attachment in inherit status.
				$filter = static fn( $status, $id ) => 'attachment' === $type && (int) $id === $item['id'] ? 'inherit' : $status;
				add_filter( 'wp_untrash_post_status', $filter, 10, 2 );
				try { $result = wp_untrash_post( $item['id'] ); } finally { remove_filter( 'wp_untrash_post_status', $filter, 10 ); }
			}
			$after = 'feedback' === $type ? get_comment( $item['id'] ) : get_post( $item['id'] );
			$status = $after ? (string) ( 'feedback' === $type ? $after->comment_approved : $after->post_status ) : '';
			if ( ! $result || ! $after || ( 'trash' === $status ) !== ( 'trash' === $operation ) ) return $this->error( 'WordPress could not confirm the change. Refresh before trying again.', 409 );
			return [ 'id'=>$item['id'],'status'=>$status,'message'=>'trash' === $operation ? __( 'Moved to Trash. You can restore it from Organize → Trash.', 'dsa' ) : __( 'Restored. Content follows WordPress’s restored status; media returns to the library.', 'dsa' ) ];
		}
		if ( 'review' === $operation ) return $this->review( $body );
		if ( 'delete' !== $operation || 'DELETE' !== ( $body['confirmation'] ?? '' ) || ! is_string( $body['token'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9]{40}$/', $body['token'] ) ) return $this->error( 'Review the deletion and type DELETE to confirm.' );
		$key = 'kiwe_trash_review_' . $body['token']; $lock = 'kiwe_trash_lock_' . get_current_user_id();
		$review = get_transient( $key );
		if ( ! is_array( $review ) || (int) $review['user'] !== get_current_user_id() ) return $this->error( 'This deletion review expired or belongs to another account. Review again.', 409 );
		$claim = time();
		if ( ! add_option( $lock, $claim, '', false ) ) {
			// One small non-autoload lock per actor; a killed PHP request cannot strand it forever.
			global $wpdb;
			$old = (int) get_option( $lock );
			if ( $old>time()-300 || 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", (string) $claim, $lock, (string) $old ) ) ) return $this->error( 'Another deletion request is still running. Wait briefly, then refresh and review before continuing.', 409 );
			wp_cache_delete( $lock, 'options' );
		}
		try {
			$review = get_transient( $key );
			if ( ! empty( $review['failed'] ) ) return $this->error( 'A previous item could not be confirmed. Close this review and refresh Trash.', 409 );
			foreach ( array_slice( $review['items'], $review['done'], self::CHUNK ) as $item ) {
				$record = $this->record( $review['type'], $item['id'] );
				if ( is_wp_error( $record ) || ! hash_equals( self::fingerprint( $record, $review['type'] ), $item['expected'] ) ) { $review['failed'][] = [ 'id'=>$item['id'],'message'=>'The record changed or permission was removed. It was not deleted by this request.' ]; break; }
				if ( 'feedback' === $review['type'] ) $deleted = wp_delete_comment( $item['id'], true );
				elseif ( 'attachment' === $review['type'] ) $deleted = wp_delete_attachment( $item['id'], true );
				elseif ( 'product' === $review['type'] && function_exists( 'wc_get_product' ) ) { $product = wc_get_product( $item['id'] ); $deleted = $product ? $product->delete( true ) : false; }
				else $deleted = wp_delete_post( $item['id'], true );
				$exists = 'feedback' === $review['type'] ? get_comment( $item['id'] ) : get_post( $item['id'] );
				if ( ! $deleted || $exists ) { $review['failed'][] = [ 'id'=>$item['id'],'message'=>'Deletion could not be confirmed. Refresh before trying again.' ]; break; }
				$review['done']++;
				set_transient( $key, $review, HOUR_IN_SECONDS );
			}
			set_transient( $key, $review, HOUR_IN_SECONDS );
			return [ 'done'=>$review['done'],'total'=>count( $review['items'] ),'complete'=>$review['done']===count( $review['items'] ),'failed'=>$review['failed'],'more'=>$review['more'] ];
		} finally { delete_option( $lock ); }
	}
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return Task_Workspace_UI::failure( $this->error( 'Trash action unavailable.', 403 ), 403 );
		$body = $request->get_json_params();
		$result = is_array( $body ) && strlen( wp_json_encode( $body ) )<=20000 ? $this->mutate( $body ) : $this->error( 'Choose a valid Trash action.' );
		return is_wp_error( $result ) ? Task_Workspace_UI::failure( $result, (int) ( $result->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $result );
	}
	public function document(): void {
		if ( ! self::allowed() || ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) return;
		nocache_headers(); header( 'Cache-Control: private, no-store' ); header( 'X-Robots-Tag: noindex, nofollow' ); header( 'X-Content-Type-Options: nosniff' );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		wp_enqueue_style( 'kiwe-trash', DSA_URL . 'assets/css/trash-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_script( 'kiwe-trash', DSA_URL . 'assets/js/trash-app.js', [ 'wp-element' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-trash', 'KIWE_TRASH_APP', [ 'initialType'=>self::initial_type( $_GET['type'] ?? '' ),'endpoint'=>rest_url( 'dsa/v1/workspace/trash' ),'nonce'=>wp_create_nonce( 'wp_rest' ),'home'=>Workspace_Admin_Service::url(),...Workspace_Chrome_Service::projection() ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html__( 'Trash', 'dsa' ); ?></title><?php wp_print_styles( [ 'kiwe-trash' ] ); ?></head><body><div id="kiwe-trash-app"><p>Opening Trash…</p></div><?php wp_print_scripts( [ 'wp-element','kiwe-trash' ] ); ?></body></html><?php exit;
	}
}
