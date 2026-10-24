<?php

namespace DSA\Access;

use DSA\Onboarding\Context_Selection_Service as Selection;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One curation surface; editing and uploads remain in the native source screens. */
final class Context_Workspace_Service {
	public const PAGE = 'kiwe-context-library';
	public const ACTION = 'kiwe_context_select';
	public const PAGE_SIZE = 16;
	private string $screen = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 34 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}
	public static function url( string $kind = 'media' ): string { if ('testimonial' === $kind) return add_query_arg(['page'=>'kiwe-feedback','type'=>'testimonial'],admin_url('admin.php')); return add_query_arg( [ 'page'=>self::PAGE, 'kind'=>$kind ], admin_url( 'admin.php' ) ); }
	public function menu(): void {
		if ( ! current_user_can( 'kiwe_manage_context' ) ) return;
		$this->screen = (string) add_menu_page( __( 'Media', 'dsa' ), __( 'Media', 'dsa' ), 'kiwe_manage_context', self::PAGE, [ $this, 'render' ], 'dashicons-images-alt2', 32 );
	}
	public function assets( string $hook = '' ): void {
		if ( $this->screen && $hook === $this->screen && current_user_can( 'kiwe_manage_context' ) ) Task_Workspace_UI::enhancement_assets();
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/context/(?P<kind>media|testimonial)/(?P<id>\d+)/selection', [
			'methods'=>'POST', 'callback'=>[ $this, 'rest_select' ], 'permission_callback'=>[ $this, 'rest_permission' ],
			'args'=>[ 'id'=>[ 'type'=>'integer', 'minimum'=>1, 'required'=>true ], 'kind'=>[ 'type'=>'string', 'enum'=>[ 'media','testimonial' ], 'required'=>true ], 'selected'=>[ 'type'=>'boolean','required'=>true ], 'expected'=>[ 'type'=>'boolean','required'=>true ] ],
		] );
	}
	public function rest_permission( \WP_REST_Request $request ): bool {
		if ( 'POST' !== $request->get_method() || ! current_user_can( 'kiwe_manage_context' ) ) return false;
		$path = $request->get_url_params();
		$id = Task_Workspace_UI::id( $path['id'] ?? 0 );
		if ( ! $id ) return false;
		if ( 'media' === ( $path['kind'] ?? '' ) ) {
			$post = get_post( $id );
			return $post && 'attachment' === $post->post_type && current_user_can( 'edit_post', $id );
		}
		if ( 'testimonial' !== ( $path['kind'] ?? '' ) || ! current_user_can( 'moderate_comments' ) ) return false;
		$comment = get_comment( $id );
		return $comment && current_user_can( 'read_post', (int) $comment->comment_post_ID );
	}
	public function rest_select( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->rest_permission( $request ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_context_forbidden', __( 'Selection is unavailable for this account or record.', 'dsa' ) ), 403 );
		$path = $request->get_url_params();
		$kind = $path['kind']; $id = (int) $path['id'];
		$selected = $request->get_param( 'selected' ); $expected = $request->get_param( 'expected' );
		if ( ! is_bool( $selected ) || ! is_bool( $expected ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_context_value', __( 'Choose a valid selection action.', 'dsa' ) ) );
		$result = ( new Selection() )->select( $kind, $id, $selected, $expected );
		if ( is_wp_error( $result ) ) return Task_Workspace_UI::failure( $result, in_array( $result->get_error_code(), [ 'kiwe_context_stale','kiwe_context_write' ], true ) ? 409 : 400 );
		if ( Selection::selected( $kind, $id ) !== $selected ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_context_unconfirmed', __( 'Selection changed during the save. Reload to review it.', 'dsa' ) ), 409 );
		$public = 'media' === $kind ? Selection::public_media( get_post( $id ) ) : Selection::public_testimonial( get_comment( $id ) );
		return Task_Workspace_UI::response( [
			'id'=>$id, 'kind'=>$kind, 'selected'=>$selected, 'canSelect'=>$public,
			'button'=>__( 'Mark as design context','dsa' ),
			'state'=>$selected ? ( $public ? __( 'Included in Design Context', 'dsa' ) : __( 'Selected, but currently excluded because it is not public or approved', 'dsa' ) ) : __( 'Not in Design Context', 'dsa' ),
			'message'=>$selected ? __( 'Included in Design Context.', 'dsa' ) : __( 'Removed from Design Context. The original record is unchanged.', 'dsa' ),
		] );
	}
	public function listing( string $kind, array $input = [] ) {
		if ( ! current_user_can( 'kiwe_manage_context' ) || ! in_array( $kind, [ 'media', 'testimonial' ], true ) || ( 'testimonial' === $kind && ! current_user_can( 'moderate_comments' ) ) ) return new \WP_Error( 'kiwe_context_forbidden', __( 'This library is unavailable for your account.', 'dsa' ) );
		if ( 'media' === $kind ) \DSA\Onboarding\Context_Media_Service::ensure_index();
		$page = min( 10000, max( 1, Task_Workspace_UI::id( $input['result_page'] ?? 1 ) ) );
		$search = Task_Workspace_UI::text( $input['search'] ?? '' );
		$view = in_array( $input['view'] ?? '', [ 'all','public','private','selected' ], true ) ? $input['view'] : ( 'media' === $kind ? 'all' : 'selected' );
		$selected = 'selected' === $view;
		$sort = in_array( $input['sort'] ?? '', [ 'date_desc','date_asc' ], true ) ? $input['sort'] : 'date_desc';
		if ( 'media' === $kind ) {
			$args = [ 'post_type'=>'attachment', 'post_status'=>[ 'inherit', 'private' ], 'posts_per_page'=>self::PAGE_SIZE + 1, 'offset'=>( $page - 1 ) * self::PAGE_SIZE, 'orderby'=>'date', 'order'=>'date_asc' === $sort ? 'ASC' : 'DESC', 's'=>$search, 'no_found_rows'=>true, 'update_post_term_cache'=>false ];
			if ( $selected ) $args['kiwe_context_media'] = true;
			if ( in_array( $view, [ 'public','private' ], true ) ) $args['kiwe_media_visibility'] = $view;
			if ( ! current_user_can( 'edit_others_posts' ) ) $args['author'] = get_current_user_id();
			add_filter( 'posts_where', [ \DSA\Onboarding\Context_Media_Service::class, 'context_where' ], 20, 2 );
			add_filter( 'posts_where', [ Selection::class, 'media_visibility_where' ], 21, 2 );
			try { $records = ( new \WP_Query( $args ) )->posts; }
			finally { remove_filter( 'posts_where', [ \DSA\Onboarding\Context_Media_Service::class, 'context_where' ], 20 ); remove_filter( 'posts_where', [ Selection::class, 'media_visibility_where' ], 21 ); }
			\DSA\Onboarding\Context_Media_Service::prime( array_map( static fn( $post ) => (int) $post->ID, $records ) );
		} else {
			$args = [ 'status'=>$selected ? 'all' : 'approve', 'type__in'=>[ '', 'comment', 'review' ], 'number'=>self::PAGE_SIZE + 1, 'offset'=>( $page - 1 ) * self::PAGE_SIZE, 'orderby'=>'comment_date_gmt', 'order'=>'date_asc' === $sort ? 'ASC' : 'DESC', 'search'=>$search ];
			if ( $selected ) { $args['meta_key'] = Selection::TESTIMONIAL_META; $args['meta_value'] = '1'; }
			$records = get_comments( $args );
		}
		$items = [];
		foreach ( array_slice( $records, 0, self::PAGE_SIZE ) as $record ) {
			if ( 'media' === $kind ? current_user_can( 'edit_post', (int) $record->ID ) : current_user_can( 'read_post', (int) $record->comment_post_ID ) ) $items[] = $record;
		}
		return [ 'items'=>$items, 'page'=>$page, 'search'=>$search, 'selected'=>$selected, 'view'=>$view, 'sort'=>$sort, 'has_more'=>count( $records ) > self::PAGE_SIZE ];
	}
	public function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'kiwe_manage_context' ) ) wp_die( esc_html__( 'Selection request unavailable.', 'dsa' ), '', [ 'response'=>403 ] );
		$kind = Task_Workspace_UI::text( $_POST['kind'] ?? '' );
		$id = Task_Workspace_UI::id( $_POST['record_id'] ?? 0 );
		check_admin_referer( self::ACTION . '_' . $kind . '_' . $id );
		$value = $_POST['selected'] ?? null;
		$expected = $_POST['expected'] ?? null;
		if ( ! in_array( $value, [ '0', '1' ], true ) || ! in_array( $expected, [ '0', '1' ], true ) ) { Task_Workspace_UI::error( new \WP_Error( 'kiwe_context_value', __( 'Refresh and choose a selection action.', 'dsa' ) ) ); return; }
		$result = ( new Selection() )->select( $kind, $id, '1' === $value, '1' === $expected );
		if ( is_wp_error( $result ) ) { Task_Workspace_UI::error( $result ); return; }
		$url = add_query_arg( [ 'view'=>in_array( $_POST['view'] ?? '', [ 'all','public','private','selected' ], true ) ? $_POST['view'] : 'all', 'search'=>Task_Workspace_UI::text( wp_unslash( $_POST['search'] ?? '' ) ), 'result_page'=>min( 10000, max( 1, Task_Workspace_UI::id( $_POST['result_page'] ?? 1 ) ) ) ], self::url( $kind ) );
		Task_Workspace_UI::finish( $url, '1' === $value ? __( 'Included in Design Context. The source record remains in its original library.', 'dsa' ) : __( 'Removed from Design Context. The source record is unchanged.', 'dsa' ) );
	}
	public function render(): void {
		$kind = 'media';
		$data = $this->listing( $kind, (array) wp_unslash( $_GET ) );
		if ( is_wp_error( $data ) ) { Task_Workspace_UI::error( $data ); return; }
		echo '<div class="wrap kiwe-tasks kiwe-task-app"><p class="kiwe-tasks__eyebrow">' . esc_html__( 'Design Context', 'dsa' ) . '</p><h1>' . esc_html__( 'Media', 'dsa' ) . '</h1><p>' . esc_html__( 'Manage your files and mark the ones that should guide your website design.', 'dsa' ) . '</p>';
		Task_Workspace_UI::receipt();
		echo '<nav class="kiwe-tasks__actions" aria-label="' . esc_attr__( 'Media views', 'dsa' ) . '">';
		foreach ( [ 'all'=>__('All files','dsa'),'public'=>__('Public','dsa'),'private'=>__('Private','dsa'),'selected'=>__('Design Context','dsa') ] as $key=>$label ) echo '<a class="button"'.($key === $data['view'] ? ' aria-current="page"' : '').' href="'.esc_url(add_query_arg('view',$key,self::url())).'">'.esc_html($label).'</a>';
		echo '</nav><section class="kiwe-tasks__card kiwe-app-card"><h2>' . esc_html( 'media' === $kind ? __( 'Files for your design', 'dsa' ) : __( 'Customer words', 'dsa' ) ) . '</h2><p>' . esc_html( 'media' === $kind ? __( 'Images used by your content, profiles and business identity are included automatically. Mark other images, videos, audio or documents to guide your website design. Only public files enter the shared design brief.', 'dsa' ) : __( 'Select approved comments and product reviews you have permission to feature. Their public name and words are included; email addresses and account details are excluded. Selection supplies your design brief; your designer decides where quotes appear.', 'dsa' ) ) . '</p>';
		echo '<div class="kiwe-tasks__actions">';
		if ( 'media' === $kind && current_user_can( 'upload_files' ) ) echo '<a class="button" href="' . esc_url( admin_url( 'media-new.php' ) ) . '">' . esc_html__( 'Upload files', 'dsa' ) . '</a><a href="' . esc_url( admin_url( 'upload.php' ) ) . '">' . esc_html__( 'Open media library', 'dsa' ) . '</a>';
		if ( 'testimonial' === $kind ) echo '<a href="' . esc_url( admin_url( 'edit-comments.php' ) ) . '">' . esc_html__( 'Moderate comments', 'dsa' ) . '</a>';
		echo '</div><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="kiwe-tasks__filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="kind" value="' . esc_attr( $kind ) . '"><label>' . esc_html__( 'Show', 'dsa' ) . '<select name="view"><option value="all"' . selected( $data['view'], 'all', false ) . '>' . esc_html__( 'All files', 'dsa' ) . '</option><option value="public"' . selected( $data['view'], 'public', false ) . '>' . esc_html__( 'Public', 'dsa' ) . '</option><option value="private"' . selected( $data['view'], 'private', false ) . '>' . esc_html__( 'Private', 'dsa' ) . '</option><option value="selected"' . selected( $data['view'], 'selected', false ) . '>' . esc_html__( 'Design Context', 'dsa' ) . '</option></select></label><label>' . esc_html__( 'Search', 'dsa' ) . '<input name="search" type="search" maxlength="200" value="' . esc_attr( $data['search'] ) . '"></label><button class="button">' . esc_html__( 'Find', 'dsa' ) . '</button></form></section><div class="kiwe-tasks__grid">';
		foreach ( $data['items'] as $record ) $this->card( $kind, $record, $data );
		if ( ! $data['items'] ) echo '<section class="kiwe-tasks__card kiwe-app-card"><h2>' . esc_html__( 'No matching records on this page', 'dsa' ) . '</h2><p>' . esc_html__( 'Browse the source library, try another search, or continue to the next page if available.', 'dsa' ) . '</p></section>';
		echo '</div>';
		Task_Workspace_UI::pagination( add_query_arg( [ 'view'=>$data['view'], 'search'=>$data['search'] ], self::url( $kind ) ), $data['page'], $data['has_more'] );
		echo '</div>';
	}
	private function card( string $kind, $record, array $data ): void {
		$id = (int) ( 'media' === $kind ? $record->ID : $record->comment_ID );
		$media_state = 'media' === $kind ? \DSA\Onboarding\Context_Media_Service::state( $id ) : [];
		$selected = $media_state['selected'] ?? Selection::selected( $kind, $id );
		$public = 'media' === $kind ? Selection::public_media( $record ) : Selection::public_testimonial( $record );
		echo '<article class="kiwe-tasks__card kiwe-app-card" data-kiwe-selection-card data-selected="' . ( $selected ? 'true' : 'false' ) . '">';
		if ( 'media' === $kind ) {
			if ( wp_attachment_is_image( $id ) ) echo wp_get_attachment_image( $id, 'medium', false, [ 'loading'=>'lazy', 'class'=>'kiwe-tasks__preview' ] );
			echo '<h2>' . esc_html( $record->post_title ?: __( 'Untitled file', 'dsa' ) ) . '</h2><p>' . esc_html( $record->post_mime_type ) . '</p><p><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html__( 'Edit file details', 'dsa' ) . '</a></p>';
		} else {
			echo '<h2>' . esc_html( $record->comment_author ?: __( 'Anonymous', 'dsa' ) ) . '</h2><blockquote>' . esc_html( wp_trim_words( wp_strip_all_tags( $record->comment_content ), 60 ) ) . '</blockquote><p>' . esc_html( get_the_title( $record->comment_post_ID ) ) . '</p>';
			if ( $public ) echo '<p><a href="' . esc_url( get_comment_link( $record ) ) . '">' . esc_html__( 'Read original', 'dsa' ) . '</a></p>';
		}
		echo '<p class="kiwe-tasks__status" data-kiwe-selection-state>' . esc_html( $selected ? ( $public ? __( 'Included in Design Context', 'dsa' ) : __( 'Selected, but currently excluded because it is not public or approved', 'dsa' ) ) : __( 'Not in Design Context', 'dsa' ) ) . '</p>';
		if ( ! empty( $media_state['automatic'] ) ) {
			echo '<button type="button" class="button" role="switch" aria-checked="true" disabled>' . esc_html__( 'Mark as design context', 'dsa' ) . '</button><p>' . esc_html( $media_state['reason'] ) . '</p>';
		} elseif ( $selected || $public ) {
			Task_Workspace_UI::form( self::ACTION, self::ACTION . '_' . $kind . '_' . $id, [ 'kind'=>$kind, 'record_id'=>$id, 'selected'=>$selected ? '0' : '1', 'expected'=>$selected ? '1' : '0', 'view'=>$data['view'], 'search'=>$data['search'], 'result_page'=>$data['page'] ], 'context-selection', rest_url( 'dsa/v1/workspace/context/' . $kind . '/' . $id . '/selection' ) );
			echo '<button type="submit" class="button" role="switch" aria-checked="'.($selected ? 'true' : 'false').'">'.esc_html__('Mark as design context','dsa').'</button>';
			Task_Workspace_UI::feedback();
			echo '</form>';
		} else echo '<p>' . esc_html__( 'Only public, approved records can be selected.', 'dsa' ) . '</p>';
		echo '</article>';
	}
}
