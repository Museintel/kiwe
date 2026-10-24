<?php
namespace DSA\Access;

if ( ! defined( 'ABSPATH' ) ) exit;

/** A private Kiwe task over WordPress's existing media picker and REST authority. */
final class Media_App_Service {
	public const PAGE = 'kiwe-media';
	public static function url( int $id = 0 ): string {
		return admin_url( 'admin.php?page=' . self::PAGE . ( $id > 0 ? '&id=' . $id : '' ) );
	}
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 30 );
		add_action( 'rest_api_init', [ $this, 'register_delete_permission' ] );
		if ( is_admin() ) add_action( 'load-toplevel_page_' . self::PAGE, [ $this, 'document' ] );
	}
	/** Project WordPress's per-file delete decision into the private editor view. */
	public function register_delete_permission(): void {
		register_rest_field( 'attachment', 'kiwe_can_delete', [
			'get_callback' => static fn( array $attachment ): bool => current_user_can( 'delete_post', absint( $attachment['id'] ?? 0 ) ),
			'schema' => [ 'description' => 'Whether the current user may delete this media item.', 'type' => 'boolean', 'context' => [ 'edit' ], 'readonly' => true ],
		] );
		register_rest_field( 'attachment', 'kiwe_trash_expected', [
			'get_callback'=>static function( array $attachment ): string { $post = get_post( (int) $attachment['id'] ); return $post && current_user_can( 'delete_post', $post->ID ) ? Trash_Workspace_Service::fingerprint( $post, 'attachment' ) : ''; },
			'schema'=>[ 'type'=>'string','context'=>[ 'edit' ],'readonly'=>true ],
		] );
		register_rest_field( 'attachment', 'kiwe_design_context', [
			'get_callback'=>static fn( array $attachment ): array => current_user_can( 'kiwe_manage_context' ) ? \DSA\Onboarding\Context_Media_Service::state( (int) $attachment['id'] ) : [],
			'schema'=>[ 'type'=>'object','context'=>[ 'edit' ],'readonly'=>true ],
		] );
	}
	public function menu(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) || ! Workspace_Admin_Service::uses_workspace( wp_get_current_user() ) ) return;
		add_menu_page( __( 'Media', 'dsa' ), __( 'Media', 'dsa' ), 'upload_files', self::PAGE, [ $this, 'document' ], 'dashicons-format-image', 30 );
	}
	public function document(): void {
		if ( ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'upload_files' ) || ! Workspace_Admin_Service::uses_workspace( wp_get_current_user() ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) ) return;
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		Workspace_Media::enqueue();
		wp_enqueue_style( 'kiwe-media-app', DSA_URL . 'assets/css/media-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_script( 'kiwe-media-app', DSA_URL . 'assets/js/media-app.js', [ 'wp-element' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-media-app', 'KIWE_MEDIA_APP', [ 'home'=>Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection(), 'nonce'=>wp_create_nonce( 'wp_rest' ), 'mediaEndpoint'=>rest_url( 'wp/v2/media' ),'contextEndpoint'=>rest_url( 'dsa/v1/workspace/context/media' ),'canCurate'=>current_user_can( 'kiwe_manage_context' ),'trashEndpoint'=>rest_url( 'dsa/v1/workspace/trash' ),'trashUrl'=>Trash_Workspace_Service::url( 'attachment' ), 'initialId'=>absint( $_GET['id'] ?? 0 ) ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( __( 'Media', 'dsa' ) . ' · ' . get_bloginfo( 'name' ) ); ?></title><?php Workspace_Media::styles(); wp_print_styles( [ 'kiwe-media-app' ] ); ?></head><body class="kiwe-media-app"><div id="kiwe-media-app"><p><?php esc_html_e( 'Opening your media library…', 'dsa' ); ?></p></div><?php Workspace_Media::footer(); wp_print_scripts( [ 'wp-element', 'kiwe-media-app' ] ); ?></body></html><?php exit;
	}
}
