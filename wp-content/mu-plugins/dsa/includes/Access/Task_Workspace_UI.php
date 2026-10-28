<?php

namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Shared presentation and actor-bound receipts for the native task workspaces. */
final class Task_Workspace_UI {
	/** Shared private document bootstrap for new workspace modules. */
	public static function app_document( string $module, string $title, array $config ): void {
		if ( ! in_array( $module, [ 'templates','analytics','backups','forms','app','apps','system' ], true ) ) return;
		nocache_headers(); header( 'Cache-Control: private, no-store' ); header( 'X-Robots-Tag: noindex, nofollow' ); header( 'X-Content-Type-Options: nosniff' );
		$handle = 'kiwe-' . $module . '-app';
		wp_enqueue_style( $handle, DSA_URL . 'assets/css/' . $module . '-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_script( $handle,DSA_URL . 'assets/js/' . $module . '-app.js',[ 'wp-element' ],DSA_VERSION,true );
		wp_localize_script( $handle,'KIWE_' . strtoupper( $module ) . '_APP',[ ...$config,'nonce'=>wp_create_nonce( 'wp_rest' ),'home'=>Workspace_Admin_Service::url(),...Workspace_Chrome_Service::projection() ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $title ); ?> · Kiwe</title><?php wp_print_styles( [ $handle ] ); ?></head><body><div id="<?php echo esc_attr( $handle ); ?>"><p><?php echo esc_html( 'Opening ' . $title . '…' ); ?></p></div><?php wp_print_scripts( [ 'wp-element',$handle ] ); ?></body></html><?php exit;
	}
	public static function text( $value, int $limit = 200 ): string {
		$text = is_string( $value ) ? sanitize_text_field( $value ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
	}
	public static function id( $value ): int {
		return ( is_int( $value ) || is_string( $value ) ) ? ( filter_var( $value, FILTER_VALIDATE_INT, [ 'options'=>[ 'min_range'=>1 ] ] ) ?: 0 ) : 0;
	}
	/** One review proof for native revision previews and their delegated writers. */
	public static function revision_fingerprint( \WP_Post $revision ): string {
		return hash( 'sha256', wp_json_encode( [ (int) $revision->ID, (int) $revision->post_parent, (string) $revision->post_title, (string) $revision->post_content, (string) $revision->post_excerpt, (string) $revision->post_modified_gmt ] ) );
	}
	/** Native revision text has three fields, including excerpt-only recovery work. */
	public static function revision_text_matches( \WP_Post $version, \WP_Post $parent ): bool {
		foreach ( [ 'post_title','post_content','post_excerpt' ] as $field ) if ( (string) $version->$field !== (string) $parent->$field ) return false;
		return true;
	}
	/** Native text restore without WordPress advancing an older draft's creation date. */
	public static function restore_revision_writing( \WP_Post $parent, int $revision_id ) {
		$id = (int) $parent->ID; $date = (string) $parent->post_date; $date_gmt = (string) $parent->post_date_gmt;
		$keep_dates = static function ( array $data, array $postarr ) use ( $id, $date, $date_gmt ): array {
			if ( (int) ( $postarr['ID'] ?? 0 ) === $id ) { $data['post_date'] = $date; $data['post_date_gmt'] = $date_gmt; }
			return $data;
		};
		add_filter( 'wp_insert_post_data', $keep_dates, PHP_INT_MAX, 2 );
		try { return wp_restore_post_revision( $revision_id, [ 'post_title','post_content','post_excerpt' ] ); }
		finally { remove_filter( 'wp_insert_post_data', $keep_dates, PHP_INT_MAX ); }
	}
	public static function form( string $action, string $nonce, array $fields, string $task = '', string $endpoint = '' ): void {
		$enhancement = $task && $endpoint ? ' data-kiwe-task="' . esc_attr( $task ) . '" data-rest-url="' . esc_url( $endpoint ) . '"' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $enhancement . '><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $nonce );
		foreach ( $fields as $key=>$value ) echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
	}
	/** Loaded only by the two task screens that use these interactions. */
	public static function enhancement_assets(): void {
		Workspace_Design_Service::enqueue();
		wp_enqueue_style( 'kiwe-task-workspaces', DSA_URL . 'assets/css/task-workspaces.css', [ 'kiwe-app-primitives' ], DSA_VERSION );
		wp_enqueue_script( 'kiwe-task-actions', DSA_URL . 'assets/js/workspace-task-actions.js', [], DSA_VERSION, true );
		wp_localize_script( 'kiwe-task-actions', 'KIWE_TASK_ACTIONS', [
			'nonce'=>wp_create_nonce( 'wp_rest' ), 'saving'=>__( 'Saving…', 'dsa' ),
			'changed'=>__( 'You have unsaved changes.', 'dsa' ), 'unchanged'=>__( 'No unsaved changes.', 'dsa' ),
			'uncertain'=>__( 'The save could not be confirmed. Reload to check the current state before trying again. Your unsaved text is still here.', 'dsa' ),
			'reload'=>__( 'Reload and review', 'dsa' ),
		] );
	}
	public static function feedback( bool $undo = false ): void {
		if ( $undo ) echo '<button type="reset" class="button" data-kiwe-reset hidden>' . esc_html__( 'Undo changes', 'dsa' ) . '</button>';
		echo '<p class="kiwe-app-state" data-kiwe-save-state role="status" aria-live="polite" hidden></p><a href="" data-kiwe-reload hidden>' . esc_html__( 'Reload and review', 'dsa' ) . '</a>';
	}
	/** Small feedback only; submission and receipts remain native WordPress navigation. */
	public static function native_form_assets(): void {
		Workspace_Design_Service::enqueue();
		wp_enqueue_style( 'kiwe-native-forms', DSA_URL . 'assets/css/workspace-native-forms.css', [ 'kiwe-app-primitives' ], DSA_VERSION );
		wp_enqueue_script( 'kiwe-native-forms', DSA_URL . 'assets/js/workspace-native-forms.js', [], DSA_VERSION, true );
		wp_localize_script( 'kiwe-native-forms', 'KIWE_NATIVE_FORMS', [
			'changed'=>__( 'You have unsaved changes.', 'dsa' ), 'unchanged'=>__( 'No unsaved changes.', 'dsa' ),
			'submitting'=>__( 'Submitting… The next screen will confirm the result.', 'dsa' ),
			'selected'=>__( '%1$d of %2$d posts selected', 'dsa' ), 'choosePosts'=>__( 'Choose between 1 and 20 posts to move.', 'dsa' ),
			'otherEdits'=>__( 'Another form on this page has unsaved changes. Continuing will leave those edits behind. Continue?', 'dsa' ),
		] );
	}
	public static function native_form_feedback(): void {
		echo '<button type="reset" class="button" data-kiwe-native-undo hidden>' . esc_html__( 'Undo changes', 'dsa' ) . '</button><p class="kiwe-app-state" data-kiwe-native-state role="status" aria-live="polite" hidden></p>';
	}
	public static function response( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		if ( $status >= 200 && $status < 300 && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && current_user_can( 'kiwe_manage_context' ) ) {
			$progress = \DSA\Onboarding\Design_Context_Journey_Service::projection();
			if ( $progress ) $response->header( 'X-Kiwe-Context-Progress', wp_json_encode( array_intersect_key( $progress, array_flip( [ 'percent','ready','total','later','next','url','resumeUrl' ] ) ) ) );
		}
		return $response;
	}
	public static function failure( \WP_Error $error, int $status = 400 ): \WP_REST_Response {
		return self::response( [ 'code'=>$error->get_error_code(), 'message'=>$error->get_error_message(), 'data'=>[ 'status'=>$status ] ], $status );
	}
	public static function error( \WP_Error $error ): void {
		wp_die( esc_html( $error->get_error_message() ), '', [ 'response'=>400, 'back_link'=>true ] );
	}
	public static function finish( string $url, string $message ): void {
		$token = str_replace( '-', '', wp_generate_uuid4() );
		if ( ! set_transient( 'kiwe_task_' . get_current_user_id() . '_' . $token, $message, 120 ) ) wp_die( esc_html__( 'The operation finished, but its receipt could not be saved. Refresh to check the current state.', 'dsa' ), '', [ 'response'=>500 ] );
		wp_safe_redirect( add_query_arg( 'task_receipt', $token, $url ) ); exit;
	}
	public static function receipt(): void {
		$token = $_GET['task_receipt'] ?? '';
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) return;
		$key = 'kiwe_task_' . get_current_user_id() . '_' . $token;
		$message = get_transient( $key );
		if ( ! is_string( $message ) ) return;
		delete_transient( $key );
		echo '<div class="notice notice-success" role="status"><p>' . esc_html( $message ) . '</p></div>';
	}
	public static function pagination( string $url, int $page, bool $more ): void {
		echo '<nav class="kiwe-tasks__actions" aria-label="' . esc_attr__( 'Result pages', 'dsa' ) . '">';
		if ( $page > 1 ) echo '<a class="button" href="' . esc_url( add_query_arg( 'result_page', $page - 1, $url ) ) . '">' . esc_html__( 'Previous', 'dsa' ) . '</a>';
		echo '<span>' . esc_html( sprintf( __( 'Page %d', 'dsa' ), $page ) ) . '</span>';
		if ( $more ) echo '<a class="button" href="' . esc_url( add_query_arg( 'result_page', $page + 1, $url ) ) . '">' . esc_html__( 'Next', 'dsa' ) . '</a>';
		echo '</nav>';
	}
}
