<?php
use DSA\AI\AI_Companion_Memory_Service;
use DSA\AI\AI_Companion_Service;
use DSA\AI\AI_Broker_Service;
use DSA\AI\AI_Allowance_Service;
use DSA\AI\Site_Graph_Service;
use DSA\AI\Task_Capsule_Service;
use DSA\AI\External_Client_Adapter_Service;
use DSA\AI\Internal_AI_Advisor_Service;
use DSA\AI\Internal_AI_Context_Service;
use DSA\AI\Internal_AI_Enrichment_Service;
use DSA\AI\Binding_Plan_Validator;
use DSA\AI\Apply_Plan_Preparer;
use DSA\AI\Trusted_Apply_Stager;
use DSA\AI\Trusted_Adapter_Proof_Service;
use DSA\AI\Guarded_Apply_Authorizer;
use DSA\AI\Pre_Execution_Gate_Service;
use DSA\AI\Trusted_Execution_Preview_Service;
use DSA\AI\Final_Apply_Confirmation_Service;
use DSA\AI\Fresh_Site_Graph_Revalidator;
use DSA\AI\Access_Key_Service;
use DSA\AI\Rollback_Readiness_Checkpoint_Service;
use DSA\AI\Target_Resolution_Service;
use DSA\AI\Rollback_Capture_Service;
use DSA\AI\Rendered_Target_Inspection_Service;
use DSA\AI\Minimal_Adapter_Shell_Service;
use DSA\AI\Final_Save_Approval_Service;
use DSA\AI\Controlled_Executor_Service;
use DSA\AI\Bricks_Controlled_Adapter_Service;
use DSA\AI\Post_Apply_Verification_Service;
use DSA\Bricks\Compiler_Batch_Cleanup_Service;
use DSA\Bricks\Clean_Conversion_Test_Service;
use DSA\Commerce\Linked_Products_Service;
use DSA\Commerce\Store_Analytics_Service;
use DSA\Commerce\Abandoned_Cart_Service;
use DSA\Communications\Email_Service;
use DSA\Design\Seam_Token_Service;
use DSA\Design\Seam_Vocabulary_Schema;
use DSA\Diagnostics\Production_Readiness_Service;
use DSA\Diagnostics\Persistence_Maintenance_Service;
use DSA\Diagnostics\Database_Inventory_Service;
use DSA\Diagnostics\Cache_Maintenance_Service;
use DSA\Diagnostics\Test_Site_Snapshot_Service;
use DSA\Modules\Module_Registry;
use DSA\Notifications\Notification_Campaign_Service;
use DSA\Notifications\Notification_Preference_Service;
use DSA\Onboarding\Design_Context_Enhancement_Service;
use DSA\Security\Secret_Store;
use DSA\Saved\Saved_Items_Service;
use DSA\Search\Search_Service;
use DSA\Runtime\Update_Service;
use DSA\Site_Graph\Calibration_Pairing_Service;
use DSA\Site_Graph\Design_Context_Service;
use DSA\Site_Graph\Staging_Seed_Connection_Service;
use DSA\Site_Graph\Staging_Seed_Ledger_Service;
use DSA\Site_Graph\Staging_Seed_Package_Service;
use DSA\Site_Graph\Staging_Seed_Import_Ledger_Service;
use DSA\Site_Graph\Staging_Seed_Import_Service;
use DSA\Settings;
use DSA\Theme\Screen_Copy_Schema;
use DSA\Theme\Theme_Package_Service;
use DSA\WP7\Native_Service;


if ( ! defined('ABSPATH') ) exit;

		$binding_report = $this->framework_binding_report();
		$rest_base      = rest_url( 'dsa/v1/ai' );
		$settings       = $this->settings->all();
		$ai_settings    = array_replace_recursive( $this->settings->defaults()['ai'] ?? [], is_array( $settings['ai'] ?? null ) ? $settings['ai'] : [] );
		$broker_status  = ( new AI_Broker_Service( $this->settings ) )->status( 'sitegraph' );
		$profile        = is_array( $broker_status['profile'] ?? null ) ? $broker_status['profile'] : [];
		$capsules       = ( new Task_Capsule_Service() )->public_records();
		$seed_ledgers   = ( new Staging_Seed_Ledger_Service() )->records();
		$seed_packages  = ( new Staging_Seed_Package_Service() )->records();
		$seed_imports   = ( new Staging_Seed_Import_Ledger_Service() )->records();
		$seed_snapshot  = ( new Test_Site_Snapshot_Service() )->status();
		$seed_open_import = [];
		foreach ( $seed_imports as $candidate_import ) {
			if ( is_array( $candidate_import ) && empty( $candidate_import['closedAt'] ) && in_array( (string) ( $candidate_import['state'] ?? '' ), [ 'running', 'failed', 'complete' ], true ) ) {
				$seed_open_import = $candidate_import;
				break;
			}
		}
		$seed_review_active = 'complete' === (string) ( $seed_open_import['state'] ?? '' );
		$seed_import_locked = [] !== $seed_open_import;
		$seed_stale_snapshot = ! empty( $seed_snapshot['active'] ) && [] === $seed_open_import;
		$seed_error     = '';
		$seed_error_key = isset( $_GET['staging-seed-error'] ) ? sanitize_key( (string) wp_unslash( $_GET['staging-seed-error'] ) ) : '';
		if ( '' !== $seed_error_key ) {
			$seed_error = sanitize_text_field( (string) get_transient( 'dsa_staging_seed_error_' . $seed_error_key ) );
			delete_transient( 'dsa_staging_seed_error_' . $seed_error_key );
		}
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe SiteGraph', 'dsa' ); ?></h1>
			<p class="description"><?php esc_html_e( 'The complete SiteGraph workspace: read-only discovery, SEAM Compiler calibration, binding validation, and the trusted apply chain. SiteGraph remains deterministic; optional AI is isolated behind the shared Kiwe AI broker.', 'dsa' ); ?></p>

			<?php if ( isset( $_GET['site-graph-settings-saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'SiteGraph settings saved.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['site-graph-exported'] ) && 'encode-error' === sanitize_key( (string) wp_unslash( $_GET['site-graph-exported'] ) ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Kiwe could not encode the SiteGraph JSON. Try a smaller sample size and check server logs if it continues.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['binding-plan'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $this->binding_plan_error_message( sanitize_key( (string) wp_unslash( $_GET['binding-plan'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['sitegraph-capsule-revoked'] ) ) : ?>
				<?php $capsule_revoked = '1' === sanitize_key( (string) wp_unslash( $_GET['sitegraph-capsule-revoked'] ) ); ?>
				<div class="notice <?php echo $capsule_revoked ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><?php echo $capsule_revoked ? esc_html__( 'SiteGraph task capsule revoked.', 'dsa' ) : esc_html__( 'SiteGraph task capsule could not be revoked.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['staging-seed-preflight'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The remote SiteGraph manifest was authenticated and inspected. No WordPress or WooCommerce content was imported, and the Application Password was not stored.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['staging-seed-package'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Kiwe pulled and hash-verified every source resource, confirmed that the source revision stayed unchanged, and built a target dry run. No WordPress or WooCommerce content was changed.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['staging-seed-import'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The verified public-business package was imported into staging. Customers, orders, credentials, messages and payment state were untouched. Keep the rollback baseline until visual and commerce acceptance is complete.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['staging-seed-rollback'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The staging import was rolled back to its hash-verified baseline and import-created media was removed.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['staging-seed-accepted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The staging import was accepted and its private rollback baseline was discarded.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $seed_error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $seed_error ); ?></p></div>
			<?php endif; ?>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'SiteGraph AI isolation', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'SiteGraph never owns a provider or API key. When enabled, it may ask the shared Kiwe AI broker only for SiteGraph summaries or binding recommendations based on a redacted graph. The broker is stateless and cannot reuse Studio, SecureTrack, Bricks, visitor, order, payment, authentication, or prior-chat context.', 'dsa' ); ?></p>
				<div class="dsa-admin-token-summary">
					<div><strong><?php echo ! empty( $profile['enabled'] ) ? esc_html__( 'Enabled', 'dsa' ) : esc_html__( 'Disabled', 'dsa' ); ?></strong><span><?php esc_html_e( 'AI profile', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Stateless', 'dsa' ); ?></strong><span><?php esc_html_e( 'requests', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'No secrets', 'dsa' ); ?></strong><span><?php esc_html_e( 'consumer access', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Schema gate', 'dsa' ); ?></strong><span><?php esc_html_e( 'outputs', 'dsa' ); ?></span></div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_save_site_graph_settings">
					<?php wp_nonce_field( 'dsa_save_site_graph_settings' ); ?>
					<label><input type="checkbox" name="sitegraph_ai_enabled" value="1" <?php checked( ! empty( $ai_settings['sitegraph_ai_enabled'] ) ); ?>> <?php esc_html_e( 'Allow optional SiteGraph AI enrichment through the shared Kiwe AI broker', 'dsa' ); ?></label>
					<p class="description"><?php esc_html_e( 'Configure the provider once under Kiwe > AI or WordPress Settings > Connectors. Deterministic exports and validation do not require AI.', 'dsa' ); ?></p>
					<?php submit_button( __( 'Save SiteGraph AI policy', 'dsa' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>

			<section class="dsa-admin__panel" style="margin-top: 1rem;">
				<h2><?php esc_html_e( 'Copy public site data to staging', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Kiwe guides one safe path: connect the source, review the calculated changes, import, then accept or roll back after testing. Customers, orders, credentials, messages and payment state never enter this transfer.', 'dsa' ); ?></p>
				<div class="dsa-admin-token-summary">
					<div><strong>1</strong><span><?php esc_html_e( 'Connect', 'dsa' ); ?></span></div>
					<div><strong>2</strong><span><?php esc_html_e( 'Review changes', 'dsa' ); ?></span></div>
					<div><strong>3</strong><span><?php esc_html_e( 'Import', 'dsa' ); ?></span></div>
					<div><strong>4</strong><span><?php esc_html_e( 'Accept or roll back', 'dsa' ); ?></span></div>
				</div>

				<?php if ( $seed_review_active ) : ?>
					<h3><?php esc_html_e( 'Imported and ready for review', 'dsa' ); ?></h3>
					<p><?php esc_html_e( 'The rollback baseline is being kept for this review. Open the staging site, Shop, Cart, Checkout and My Account on desktop and mobile. When satisfied, accept the import; otherwise roll it back.', 'dsa' ); ?></p>
					<p><a class="button button-secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open staging site', 'dsa' ); ?></a></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem"><input type="hidden" name="action" value="dsa_sitegraph_staging_seed_rollback"><input type="hidden" name="ledgerId" value="<?php echo esc_attr( (string) ( $seed_open_import['id'] ?? '' ) ); ?>"><?php wp_nonce_field( 'dsa_sitegraph_staging_seed_rollback' ); ?><?php submit_button( __( 'Roll back this import', 'dsa' ), 'secondary', 'submit', false ); ?></form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block"><input type="hidden" name="action" value="dsa_sitegraph_staging_seed_accept"><input type="hidden" name="ledgerId" value="<?php echo esc_attr( (string) ( $seed_open_import['id'] ?? '' ) ); ?>"><?php wp_nonce_field( 'dsa_sitegraph_staging_seed_accept' ); ?><?php submit_button( __( 'Accept import and finish', 'dsa' ), 'primary', 'submit', false ); ?></form>
				<?php elseif ( $seed_import_locked ) : ?>
					<h3><?php esc_html_e( 'The current import needs attention', 'dsa' ); ?></h3>
					<p><?php echo esc_html( (string) ( $seed_open_import['error'] ?? __( 'The import did not reach review state. Roll it back before starting another transfer.', 'dsa' ) ) ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dsa_sitegraph_staging_seed_rollback"><input type="hidden" name="ledgerId" value="<?php echo esc_attr( (string) ( $seed_open_import['id'] ?? '' ) ); ?>"><?php wp_nonce_field( 'dsa_sitegraph_staging_seed_rollback' ); ?><?php submit_button( __( 'Roll back incomplete import', 'dsa' ), 'primary', 'submit', false ); ?></form>
				<?php elseif ( $seed_stale_snapshot ) : ?>
					<h3><?php esc_html_e( 'A separate test baseline needs a decision', 'dsa' ); ?></h3>
					<p><?php echo esc_html( sprintf( __( 'The baseline “%1$s” from %2$s is not owned by an active SiteGraph import. Restore it if you need its old content, or discard it without changing the current site; then return here.', 'dsa' ), (string) ( $seed_snapshot['label'] ?? __( 'Unlabelled baseline', 'dsa' ) ), (string) ( $seed_snapshot['createdAt'] ?? '' ) ) ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-database#kiwe-test-baseline' ) ); ?>"><?php esc_html_e( 'Open baseline controls', 'dsa' ); ?></a></p>
				<?php else : ?>
				<h3><?php esc_html_e( 'Connect and prepare', 'dsa' ); ?></h3>
				<p><?php esc_html_e( 'Enter the source once. Kiwe authenticates it, checks compatibility, pulls and verifies the approved resources, then presents the changes before anything is imported.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
					<input type="hidden" name="action" value="dsa_sitegraph_staging_seed_pull">
					<?php wp_nonce_field( 'dsa_sitegraph_staging_seed_pull' ); ?>
					<p class="dsa-admin-inline-fields">
						<label><span><?php esc_html_e( 'Source site URL', 'dsa' ); ?></span><input type="url" name="sourceUrl" placeholder="https://example.com" required></label>
						<label><span><?php esc_html_e( 'Source administrator username', 'dsa' ); ?></span><input type="text" name="sourceUsername" autocomplete="off" required></label>
						<label><span><?php esc_html_e( 'WordPress Application Password', 'dsa' ); ?></span><input type="password" name="sourceApplicationPassword" autocomplete="new-password" required></label>
						<?php submit_button( __( 'Connect and calculate changes', 'dsa' ), 'primary', 'submit', false ); ?>
					</p>
				</form>
				<p class="description"><?php esc_html_e( 'Use a temporary WordPress Application Password from the source administrator profile, not the normal login password. Kiwe never stores it.', 'dsa' ); ?></p>
				<?php endif; ?>

				<?php if ( ! $seed_import_locked && ! $seed_stale_snapshot && [] !== $seed_packages ) : ?>
					<h3><?php esc_html_e( 'Review calculated changes', 'dsa' ); ?></h3>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Source', 'dsa' ); ?></th><th><?php esc_html_e( 'State', 'dsa' ); ?></th><th><?php esc_html_e( 'Pulled', 'dsa' ); ?></th><th><?php esc_html_e( 'Dry run', 'dsa' ); ?></th><th><?php esc_html_e( 'Integrity', 'dsa' ); ?></th><th><?php esc_html_e( 'Action', 'dsa' ); ?></th></tr></thead><tbody>
					<?php foreach ( array_slice( $seed_packages, 0, 1 ) as $seed_package ) : ?>
						<?php $package_counts = is_array( $seed_package['resourceCounts'] ?? null ) ? $seed_package['resourceCounts'] : []; $dry = is_array( $seed_package['dryRun'] ?? null ) ? $seed_package['dryRun'] : []; $product_dry = is_array( $dry['summary']['products'] ?? null ) ? $dry['summary']['products'] : []; $content_dry = is_array( $dry['summary']['content'] ?? null ) ? $dry['summary']['content'] : []; $package_capabilities = is_array( $seed_package['capabilities'] ?? null ) ? $seed_package['capabilities'] : []; $clean_ready = ! empty( $package_capabilities['cleanReconciliation'] ) && 'v1' === ( $package_capabilities['pageAuthority'] ?? '' ); ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $seed_package['sourceOrigin'] ?? '' ) ); ?></code><br><small><?php echo esc_html( substr( (string) ( $seed_package['revisionHash'] ?? '' ), 0, 12 ) ); ?> · Kiwe <?php echo esc_html( (string) ( $seed_package['sourceKiweVersion'] ?? 'unknown' ) ); ?></small></td>
							<td><?php echo esc_html( (string) ( $seed_package['state'] ?? '' ) ); ?><br><small><?php echo $clean_ready ? esc_html__( 'Clean reconciliation ready', 'dsa' ) : esc_html__( 'Additive import only', 'dsa' ); ?></small></td>
							<td><?php echo esc_html( sprintf( 'Products %1$d · Content %2$d · Media %3$d', absint( $package_counts['products'] ?? 0 ), absint( $package_counts['content'] ?? 0 ), absint( $package_counts['media'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $dry['status'] ?? '' ) ); ?><br><small><?php echo esc_html( sprintf( 'Products: %1$d create · %2$d update · %3$d remove · %4$d blocked', absint( $product_dry['create'] ?? 0 ), absint( $product_dry['update'] ?? 0 ), absint( $product_dry['remove'] ?? 0 ), absint( $product_dry['blocked'] ?? 0 ) ) ); ?></small><br><small><?php echo esc_html( sprintf( 'Content: %d remove', absint( $content_dry['remove'] ?? 0 ) ) ); ?></small></td>
							<td><?php echo esc_html( size_format( absint( $seed_package['bytes'] ?? 0 ) ) ); ?> · <?php echo esc_html( substr( (string) ( $seed_package['hash'] ?? '' ), 0, 12 ) ); ?><br><small><?php esc_html_e( 'Credentials not stored', 'dsa' ); ?></small></td>
							<td>
								<?php if ( 'ready-for-baseline-and-import-confirmation' === ( $dry['status'] ?? '' ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="dsa_sitegraph_staging_seed_import">
									<input type="hidden" name="packageId" value="<?php echo esc_attr( (string) ( $seed_package['id'] ?? '' ) ); ?>">
									<input type="hidden" name="revisionHash" value="<?php echo esc_attr( (string) ( $seed_package['revisionHash'] ?? '' ) ); ?>">
									<?php wp_nonce_field( 'dsa_sitegraph_staging_seed_import' ); ?>
									<label><input type="checkbox" name="confirmBoundedImport" value="1" required> <?php esc_html_e( 'I confirm this is the staging destination.', 'dsa' ); ?></label><br>
									<?php if ( $clean_ready ) : ?><label><input type="checkbox" name="reconcilePublicData" value="1" checked> <?php esc_html_e( 'Reconcile public pages/posts and WooCommerce products to this source package; remove unmatched test records under the rollback baseline.', 'dsa' ); ?></label><br><?php endif; ?>
									<?php submit_button( __( 'Capture baseline and import', 'dsa' ), 'primary', 'submit', false ); ?>
								</form>
								<?php else : ?>—<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>

				<?php if ( [] !== $seed_imports ) : ?>
					<details style="margin-top:1rem"><summary><strong><?php esc_html_e( 'Advanced: import and rollback history', 'dsa' ); ?></strong></summary>
					<h3><?php esc_html_e( 'Staging import rollback ledger', 'dsa' ); ?></h3>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Source revision', 'dsa' ); ?></th><th><?php esc_html_e( 'State', 'dsa' ); ?></th><th><?php esc_html_e( 'Objects', 'dsa' ); ?></th><th><?php esc_html_e( 'Rollback boundary', 'dsa' ); ?></th></tr></thead><tbody>
					<?php foreach ( array_slice( $seed_imports, 0, 6 ) as $seed_import ) : ?>
						<?php $created = is_array( $seed_import['created'] ?? null ) ? $seed_import['created'] : []; $is_open = empty( $seed_import['closedAt'] ) && in_array( (string) ( $seed_import['state'] ?? '' ), [ 'running', 'failed', 'complete' ], true ); ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $seed_import['sourceOrigin'] ?? '' ) ); ?></code><br><small><?php echo esc_html( substr( (string) ( $seed_import['revisionHash'] ?? '' ), 0, 12 ) ); ?></small></td>
							<td><?php echo esc_html( (string) ( $seed_import['state'] ?? '' ) ); ?><?php if ( ! empty( $seed_import['error'] ) ) : ?><br><small><?php echo esc_html( (string) $seed_import['error'] ); ?></small><?php endif; ?></td>
							<td><?php echo esc_html( sprintf( 'Posts %1$d · Media %2$d · Terms %3$d', count( (array) ( $created['posts'] ?? [] ) ), count( (array) ( $created['media'] ?? [] ) ), count( (array) ( $created['terms'] ?? [] ) ) ) ); ?></td>
							<td>
							<?php if ( $is_open ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem"><input type="hidden" name="action" value="dsa_sitegraph_staging_seed_rollback"><input type="hidden" name="ledgerId" value="<?php echo esc_attr( (string) ( $seed_import['id'] ?? '' ) ); ?>"><?php wp_nonce_field( 'dsa_sitegraph_staging_seed_rollback' ); ?><?php submit_button( __( 'Roll back import', 'dsa' ), 'secondary', 'submit', false ); ?></form>
								<?php if ( 'complete' === ( $seed_import['state'] ?? '' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block"><input type="hidden" name="action" value="dsa_sitegraph_staging_seed_accept"><input type="hidden" name="ledgerId" value="<?php echo esc_attr( (string) ( $seed_import['id'] ?? '' ) ); ?>"><?php wp_nonce_field( 'dsa_sitegraph_staging_seed_accept' ); ?><?php submit_button( __( 'Accept and discard baseline', 'dsa' ), 'primary', 'submit', false ); ?></form><?php endif; ?>
							<?php else : ?><?php echo esc_html( (string) ( $seed_import['closedAt'] ?? 'closed' ) ); ?><?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
					</details>
				<?php endif; ?>

				<?php if ( [] !== $seed_ledgers ) : ?>
					<details style="margin-top:1rem"><summary><strong><?php esc_html_e( 'Advanced: connection preflight history', 'dsa' ); ?></strong></summary>
					<h3><?php esc_html_e( 'Recent preflights', 'dsa' ); ?></h3>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Source', 'dsa' ); ?></th><th><?php esc_html_e( 'State', 'dsa' ); ?></th><th><?php esc_html_e( 'Resources', 'dsa' ); ?></th><th><?php esc_html_e( 'Safety', 'dsa' ); ?></th><th><?php esc_html_e( 'Created', 'dsa' ); ?></th></tr></thead><tbody>
					<?php foreach ( array_slice( $seed_ledgers, 0, 5 ) as $seed_ledger ) : ?>
						<?php $seed_counts = is_array( $seed_ledger['resourceCounts'] ?? null ) ? $seed_ledger['resourceCounts'] : []; ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $seed_ledger['source']['origin'] ?? '' ) ); ?></code><br><small><?php echo esc_html( substr( (string) ( $seed_ledger['source']['revisionHash'] ?? '' ), 0, 12 ) ); ?></small></td>
							<td><?php echo esc_html( (string) ( $seed_ledger['state'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( sprintf( 'Products %1$d · Content %2$d · Media %3$d', absint( $seed_counts['products'] ?? 0 ), absint( $seed_counts['content'] ?? 0 ), absint( $seed_counts['media'] ?? 0 ) ) ); ?></td>
							<td><?php echo empty( $seed_ledger['blockers'] ) ? esc_html__( 'Ready for reviewed import', 'dsa' ) : esc_html( sprintf( _n( '%d blocker', '%d blockers', count( (array) $seed_ledger['blockers'] ), 'dsa' ), count( (array) $seed_ledger['blockers'] ) ) ); ?><br><small><?php esc_html_e( 'Credentials not stored', 'dsa' ); ?></small></td>
							<td><?php echo esc_html( (string) ( $seed_ledger['createdAt'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
					</details>
				<?php endif; ?>
			</section>

			<section class="dsa-admin__panel" style="margin-top: 1rem;">
				<h2><?php esc_html_e( 'SiteGraph workspace', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Use this when an AI or web developer needs to turn an approved design into Bricks query loops, dynamic data, and Kiwe AppShell launchers for this exact WordPress site. SiteGraph is read-only and contains no secrets, visitor state, orders, payment data, or credentials.', 'dsa' ); ?></p>
				<div class="dsa-admin-token-summary">
					<div><strong><?php esc_html_e( 'Read-only', 'dsa' ); ?></strong><span><?php esc_html_e( 'SiteGraph', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'v1', 'dsa' ); ?></strong><span><?php esc_html_e( 'schema', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'API key', 'dsa' ); ?></strong><span><?php esc_html_e( 'tool access', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Staging only', 'dsa' ); ?></strong><span><?php esc_html_e( 'write boundary', 'dsa' ); ?></span></div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_export_site_graph">
					<?php wp_nonce_field( 'dsa_export_site_graph' ); ?>
					<p class="dsa-admin-inline-fields">
						<label><span><?php esc_html_e( 'Products', 'dsa' ); ?></span><select name="productLimit"><?php foreach ( [ 0, 24, 50, 100, 200 ] as $limit ) : ?><option value="<?php echo esc_attr( (string) $limit ); ?>" <?php selected( 100, $limit ); ?>><?php echo esc_html( (string) $limit ); ?></option><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Images', 'dsa' ); ?></span><select name="mediaLimit"><?php foreach ( [ 0, 48, 100, 250, 500 ] as $limit ) : ?><option value="<?php echo esc_attr( (string) $limit ); ?>" <?php selected( 100, $limit ); ?>><?php echo esc_html( (string) $limit ); ?></option><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Pages/posts each', 'dsa' ); ?></span><select name="contentLimit"><?php foreach ( [ 0, 12, 24, 50, 100 ] as $limit ) : ?><option value="<?php echo esc_attr( (string) $limit ); ?>" <?php selected( 24, $limit ); ?>><?php echo esc_html( (string) $limit ); ?></option><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Custom records total', 'dsa' ); ?></span><select name="customContentLimit"><?php foreach ( [ 0, 24, 48, 100, 200 ] as $limit ) : ?><option value="<?php echo esc_attr( (string) $limit ); ?>" <?php selected( 48, $limit ); ?>><?php echo esc_html( (string) $limit ); ?></option><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Taxonomy terms total', 'dsa' ); ?></span><select name="termLimit"><?php foreach ( [ 0, 50, 100, 150, 300 ] as $limit ) : ?><option value="<?php echo esc_attr( (string) $limit ); ?>" <?php selected( 150, $limit ); ?>><?php echo esc_html( (string) $limit ); ?></option><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Image search (optional)', 'dsa' ); ?></span><input type="search" name="mediaSearch" placeholder="<?php esc_attr_e( 'owner, factory, logo…', 'dsa' ); ?>"></label>
						<?php submit_button( __( 'Download SiteGraph JSON', 'dsa' ), 'secondary', 'submit', false ); ?>
					</p>
				</form>
				<p class="description"><?php esc_html_e( 'This is the one public-only AI handoff. It includes owner Design Context, approved design material, products, media, content, custom fields, taxonomies, Bricks capabilities and binding targets—never API keys, customers, orders, drafts, visitor state or filesystem data.', 'dsa' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5rem;">
					<input type="hidden" name="action" value="dsa_export_site_graph_calibration"><?php wp_nonce_field( 'dsa_export_site_graph_calibration' ); ?><?php submit_button( __( 'Download compiler calibration JSON', 'dsa' ), 'secondary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Content-free target profile for deterministic SEAM Compiler calibration: Bricks version, exact breakpoints, collision names and supported native capabilities.', 'dsa' ); ?></p>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5rem;">
					<input type="hidden" name="action" value="dsa_create_site_graph_calibration_pair"><?php wp_nonce_field( 'dsa_create_site_graph_calibration_pair' ); ?><?php submit_button( __( 'Create one-time compiler pairing file', 'dsa' ), 'primary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Expires after 10 minutes and one successful read. It grants content-free, read-only calibration access only.', 'dsa' ); ?></p>
				</form>

				<h3><?php esc_html_e( 'Connect an external AI, IDE or tool', 'dsa' ); ?></h3>
				<p><?php esc_html_e( 'The base URL below is an API namespace, not a webpage. A client also needs the OpenAPI contract and authenticated requests. Download a short-lived task capsule for ChatGPT-compatible actions, Claude/Cursor adapters, MCP clients, IDE tools or any standards-based HTTP client.', 'dsa' ); ?></p>
				<p><strong><?php esc_html_e( 'Do not paste the capsule secret into an ordinary AI chat.', 'dsa' ); ?></strong> <?php esc_html_e( 'Configure it in the client action, tool, secret store, MCP adapter or IDE environment, then delete the downloaded connection file.', 'dsa' ); ?></p>
				<div class="dsa-admin-token-summary">
					<div><strong><?php esc_html_e( 'Vendor-neutral', 'dsa' ); ?></strong><span><?php esc_html_e( 'OpenAPI 3.1', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Short-lived', 'dsa' ); ?></strong><span><?php esc_html_e( 'task token', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Public only', 'dsa' ); ?></strong><span><?php esc_html_e( 'content data', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'No writes', 'dsa' ); ?></strong><span><?php esc_html_e( 'hard boundary', 'dsa' ); ?></span></div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_create_sitegraph_client_package">
					<?php wp_nonce_field( 'dsa_create_sitegraph_client_package' ); ?>
					<p class="dsa-admin-inline-fields">
						<label><span><?php esc_html_e( 'Connection label', 'dsa' ); ?></span><input type="text" name="label" placeholder="<?php esc_attr_e( 'Homepage content conversion', 'dsa' ); ?>" required></label>
						<label><span><?php esc_html_e( 'Purpose', 'dsa' ); ?></span><select name="purpose"><option value="convert_validate"><?php esc_html_e( 'Read + convert + validate', 'dsa' ); ?></option><option value="content_read"><?php esc_html_e( 'Read content and Bricks context', 'dsa' ); ?></option></select></label>
						<label><span><?php esc_html_e( 'Expires', 'dsa' ); ?></span><select name="expiresMinutes"><option value="15">15 minutes</option><option value="60" selected>1 hour</option><option value="240">4 hours</option><option value="1440">24 hours</option></select></label>
						<label><span><?php esc_html_e( 'Request budget', 'dsa' ); ?></span><select name="maxUses"><option value="25">25</option><option value="100" selected>100</option><option value="250">250</option></select></label>
						<?php submit_button( __( 'Download client connection', 'dsa' ), 'primary', 'submit', false ); ?>
					</p>
					<details>
						<summary><?php esc_html_e( 'Data budget', 'dsa' ); ?></summary>
						<p class="dsa-admin-inline-fields"><label><span><?php esc_html_e( 'Rows per query', 'dsa' ); ?></span><select name="maxRows"><option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option></select></label><label><span><?php esc_html_e( 'SiteGraph samples', 'dsa' ); ?></span><select name="sampleLimit"><option value="0">0</option><option value="4">4</option><option value="8" selected>8</option><option value="16">16</option><option value="24">24</option></select></label></p>
						<p><strong><?php esc_html_e( 'Resources', 'dsa' ); ?></strong></p><p class="dsa-admin-token-chips"><?php foreach ( Task_Capsule_Service::RESOURCES as $resource ) : ?><label><input type="checkbox" name="resources[]" value="<?php echo esc_attr( $resource ); ?>" checked> <code><?php echo esc_html( $resource ); ?></code></label><?php endforeach; ?></p>
						<p><strong><?php esc_html_e( 'Fields', 'dsa' ); ?></strong></p><p class="dsa-admin-token-chips"><?php foreach ( Task_Capsule_Service::FIELDS as $field ) : ?><label><input type="checkbox" name="fields[]" value="<?php echo esc_attr( $field ); ?>" checked> <code><?php echo esc_html( $field ); ?></code></label><?php endforeach; ?></p>
					</details>
				</form>
				<p class="description"><a href="<?php echo esc_url( rest_url( 'dsa/v1/ai/openapi.task.json' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open safe task OpenAPI JSON', 'dsa' ); ?></a> · <a href="<?php echo esc_url( rest_url( 'dsa/v1/ai/client-adapters' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open adapter catalog', 'dsa' ); ?></a> · <a href="<?php echo esc_url( rest_url( 'dsa/v1/ai/client-manifest' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open client manifest', 'dsa' ); ?></a> · <?php esc_html_e( 'The connection package contains the secret once; Kiwe stores only its hash.', 'dsa' ); ?></p>

				<?php if ( [] !== $capsules ) : ?>
					<h4><?php esc_html_e( 'Task capsules', 'dsa' ); ?></h4>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Label', 'dsa' ); ?></th><th><?php esc_html_e( 'Purpose', 'dsa' ); ?></th><th><?php esc_html_e( 'Expires', 'dsa' ); ?></th><th><?php esc_html_e( 'Usage', 'dsa' ); ?></th><th><?php esc_html_e( 'Last used', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th><th><?php esc_html_e( 'Action', 'dsa' ); ?></th></tr></thead><tbody>
					<?php foreach ( $capsules as $capsule ) : ?>
						<?php $inactive = ! empty( $capsule['expired'] ) || '' !== (string) ( $capsule['revokedAt'] ?? '' ) || absint( $capsule['usesRemaining'] ?? 0 ) < 1; ?>
						<tr><td><?php echo esc_html( (string) ( $capsule['label'] ?? '' ) ); ?><br><code><?php echo esc_html( (string) ( $capsule['prefix'] ?? '' ) ); ?>...<?php echo esc_html( (string) ( $capsule['last4'] ?? '' ) ); ?></code></td><td><code><?php echo esc_html( (string) ( $capsule['purpose'] ?? '' ) ); ?></code></td><td><?php echo esc_html( (string) ( $capsule['expiresAt'] ?? '' ) ); ?></td><td><?php echo esc_html( sprintf( '%1$d used / %2$d left', absint( $capsule['uses'] ?? 0 ), absint( $capsule['usesRemaining'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( (string) ( $capsule['lastUsedAt'] ?? '' ) ?: __( 'Never', 'dsa' ) ); ?></td><td><?php echo $inactive ? esc_html__( 'Inactive', 'dsa' ) : esc_html__( 'Active', 'dsa' ); ?></td><td><?php if ( ! $inactive ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dsa_revoke_sitegraph_task_capsule"><input type="hidden" name="capsuleId" value="<?php echo esc_attr( (string) ( $capsule['id'] ?? '' ) ); ?>"><?php wp_nonce_field( 'dsa_revoke_sitegraph_task_capsule_' . (string) ( $capsule['id'] ?? '' ) ); ?><button class="button button-secondary" type="submit"><?php esc_html_e( 'Revoke', 'dsa' ); ?></button></form><?php else : ?>—<?php endif; ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
				<h3><?php esc_html_e( 'Validate binding plan', 'dsa' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Upload bricks-bindings/kiwe-bindings.json. Kiwe validates it against the current SiteGraph; it does not save Bricks data or apply changes.', 'dsa' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_validate_binding_plan"><?php wp_nonce_field( 'dsa_validate_binding_plan' ); ?>
					<p class="dsa-admin-inline-fields"><label><span><?php esc_html_e( 'Binding JSON', 'dsa' ); ?></span><input type="file" name="dsa_binding_file" accept="application/json,.json" required></label><label><span><?php esc_html_e( 'SiteGraph sample size', 'dsa' ); ?></span><select name="sampleLimit"><?php foreach ( [ 0, 4, 8, 16, 24 ] as $limit ) : ?><option value="<?php echo esc_attr( (string) $limit ); ?>" <?php selected( 8, $limit ); ?>><?php echo esc_html( (string) $limit ); ?></option><?php endforeach; ?></select></label><?php submit_button( __( 'Validate Binding Plan', 'dsa' ), 'secondary', 'submit', false ); ?></p>
				</form>
				<?php $this->render_binding_plan_report( $binding_report ); ?>
				<?php $this->render_trusted_apply_stages(); ?>
				<h3><?php esc_html_e( 'REST endpoints for tool clients', 'dsa' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Base API namespace (not a webpage):', 'dsa' ); ?> <code><?php echo esc_html( $rest_base ); ?></code></p>
				<ul class="ul-disc"><li><code>GET /status</code></li><li><code>GET /site-graph</code></li><li><code>POST /validate-bindings</code></li><li><code>POST /prepare-apply-plan</code></li><li><code>POST /stage-apply-plan</code> and <code>/stages/{stageId}/...</code></li></ul>
				<p class="description"><?php esc_html_e( 'Safe sequence: connect with a task capsule for read/convert/validate work. Use a separate narrowly scoped permanent key only when a human intentionally enters the trusted staging chain. A base URL and secret pasted into a normal chat are not a secure connection.', 'dsa' ); ?></p>
			</section>
		</div>
		<?php
	