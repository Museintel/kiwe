<?php
namespace DSA\Bricks;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Private, on-demand controller; loaded only on Kiwe's existing Framework authoring surface. */
final class Compiler_Import_Workspace {
	public static function render(): void {
		if ( ! Compiler_Import_Plan::allowed() ) return;
		wp_enqueue_script( 'kiwe-compiler-import',DSA_URL . 'assets/js/compiler-import.js',[],DSA_VERSION,true );
		wp_localize_script( 'kiwe-compiler-import','kiweCompilerImport',[ 'endpoint'=>admin_url( 'admin-ajax.php' ),'nonce'=>wp_create_nonce( 'kiwe_compiler_import' ),'resume'=>sanitize_key( (string) ( $_GET['compiler-import'] ?? '' ) ) ] );
		?>
		<section class="dsa-admin__panel" id="kiwe-compiler-import" aria-labelledby="kiwe-compiler-import-title">
			<h2 id="kiwe-compiler-import-title"><?php esc_html_e( 'Import a compiled design','dsa' ); ?></h2>
			<?php $jobs = ( new Compiler_Import_Service() )->listing(); if ( $jobs ) : ?><details><summary><?php esc_html_e( 'Saved imports and recovery','dsa' ); ?></summary><ul><?php foreach ( $jobs as $job ) : ?><li><a href="<?php echo esc_url( add_query_arg( [ 'page'=>'kiwe-framework','compiler-import'=>$job['id'] ],admin_url( 'admin.php' ) ) . '#kiwe-compiler-import' ); ?>"><?php echo esc_html( wp_date( 'j M Y, H:i',$job['created'] ) . ' · ' . $job['status'] ); ?></a></li><?php endforeach; ?></ul></details><?php endif; ?>
			<p><?php esc_html_e( 'Choose the Framework ZIP from Seam. Kiwe checks its real media and styles before showing the changes for review. Existing pages and website routes are kept; templates are created as drafts.','dsa' ); ?></p>
			<form data-compiler-upload><label for="kiwe-compiler-file"><?php esc_html_e( 'Compiler Framework ZIP','dsa' ); ?></label> <input id="kiwe-compiler-file" type="file" name="package" accept=".zip,application/zip" required> <button class="button button-primary" type="submit"><?php esc_html_e( 'Review package','dsa' ); ?></button></form>
			<div data-compiler-progress aria-live="polite" role="status"></div>
			<div data-compiler-review></div><div data-compiler-actions></div>
		</section>
		<?php
	}
	public static function handle(): void {
		nocache_headers();
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! Compiler_Import_Plan::allowed() || ! \DSA\Utilities\Origin_Checker::is_same_site_request() || ( $_SERVER['HTTP_X_KIWE_MUTATION'] ?? '' ) !== '1' || ! check_ajax_referer( 'kiwe_compiler_import','nonce',false ) ) wp_send_json_error( [ 'message'=>'Your import session or native permission expired. Refresh this page.' ],403 );
		try {
			$service = new Compiler_Import_Service(); $operation = sanitize_key( (string) ( $_POST['operation'] ?? '' ) );
			if ( $operation === 'stage' ) {
				$file = $_FILES['package'] ?? null;
				if ( ! is_array( $file ) || ! empty( $file['error'] ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) || ! preg_match( '/\.zip$/iD',(string) ( $file['name'] ?? '' ) ) ) throw new Compiler_Package_Error( 'Choose a Framework ZIP within this server’s upload limit.' );
				$result = $service->stage( $file['tmp_name'] );
			} else {
				$id = sanitize_key( (string) ( $_POST['id'] ?? '' ) );
				$result = $operation === 'status' ? $service->status( $id ) : $service->advance( $id,(int) ( $_POST['revision'] ?? 0 ),$operation );
			}
			wp_send_json_success( $result );
		} catch ( Compiler_Package_Error $error ) { wp_send_json_error( [ 'message'=>$error->getMessage() ],400 ); }
		catch ( \Throwable $error ) { wp_send_json_error( [ 'message'=>'Native import stopped. Refresh the saved import to review its progress; no automatic publish occurs.' ],500 ); }
	}
}
