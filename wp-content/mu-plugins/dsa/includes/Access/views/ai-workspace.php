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


if ( ! defined( 'ABSPATH' ) ) exit;

		$binding_report = $this->framework_binding_report();
		$key_service    = new Access_Key_Service();
		$keys           = $key_service->public_records();
		$created_key    = '';
		$created_token  = isset( $_GET['kiwe-ai-key-created'] ) ? sanitize_key( (string) wp_unslash( $_GET['kiwe-ai-key-created'] ) ) : '';
		if ( '' !== $created_token ) {
			$created_key = (string) get_transient( 'dsa_ai_key_once_' . $created_token );
			if ( '' === $created_key ) {
				$created_fallback = get_option( 'dsa_ai_key_once_' . $created_token, [] );
				if ( is_array( $created_fallback ) && time() <= absint( $created_fallback['expiresAt'] ?? 0 ) ) {
					$created_key = (string) ( $created_fallback['key'] ?? '' );
				}
			}
			delete_transient( 'dsa_ai_key_once_' . $created_token );
			delete_option( 'dsa_ai_key_once_' . $created_token );
		}
		$rest_base = rest_url( 'dsa/v1/ai' );
		$settings  = $this->settings->all();
		$ai_settings = array_replace_recursive( $this->settings->defaults()['ai'] ?? [], is_array( $settings['ai'] ?? null ) ? $settings['ai'] : [] );
		$site_graph_service = new Site_Graph_Service( $this->settings, $this->modules );
		$companion = new AI_Companion_Service( $this->settings, $site_graph_service, new AI_Companion_Memory_Service() );
		$companion_status = $companion->status( [ 'record' => [ 'scopes' => [ 'admin' ] ] ] );
		$include_securetrack = ! empty( $ai_settings['securetrack_brief_enabled'] );
		$advisor   = ( new Internal_AI_Advisor_Service( new Internal_AI_Context_Service( $site_graph_service ) ) )->advise(
			[
				'focus'       => isset( $_GET['advisor-focus'] ) ? sanitize_key( (string) wp_unslash( $_GET['advisor-focus'] ) ) : 'all',
				'sampleLimit' => 8,
				'secureLimit' => 12,
				'includeSecureTrack' => $include_securetrack,
			]
		);
		$enrichment = ( new Internal_AI_Enrichment_Service( new Internal_AI_Advisor_Service( new Internal_AI_Context_Service( $site_graph_service ) ) ) )->enrich(
			[
				'focus'       => isset( $_GET['advisor-focus'] ) ? sanitize_key( (string) wp_unslash( $_GET['advisor-focus'] ) ) : 'all',
				'style'       => isset( $_GET['advisor-style'] ) ? sanitize_key( (string) wp_unslash( $_GET['advisor-style'] ) ) : 'executive',
				'sampleLimit' => 8,
				'secureLimit' => 12,
				'limit'       => 6,
				'includeSecureTrack' => $include_securetrack,
			]
		);
		?>
		<div class="wrap dsa-admin">
			<h1><?php esc_html_e( 'Kiwe AI', 'dsa' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Configure AI once for all Kiwe services. WordPress 7 Connectors are preferred when available; Kiwe’s encrypted provider fallback remains available for older sites. Each consumer receives an isolated, stateless request profile and never receives provider credentials. SiteGraph operations live under Kiwe > SiteGraph.', 'dsa' ); ?></p>

			<?php if ( '' !== $created_key ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Kiwe AI API key created.', 'dsa' ); ?></strong> <?php esc_html_e( 'Copy it now. Kiwe stores only a hash and will not show the secret again.', 'dsa' ); ?></p>
					<p><textarea readonly rows="3" style="width: 100%; max-width: 860px;"><?php echo esc_textarea( $created_key ); ?></textarea></p>
				</div>
			<?php elseif ( '' !== $created_token ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'That one-time API key view has expired or was already shown.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['kiwe-ai-key-revoked'] ) ) : ?>
				<?php $revoked_status = sanitize_key( (string) wp_unslash( $_GET['kiwe-ai-key-revoked'] ) ); ?>
				<div class="notice <?php echo '1' === $revoked_status ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><?php echo '1' === $revoked_status ? esc_html__( 'Kiwe AI API key revoked.', 'dsa' ) : esc_html__( 'Kiwe AI API key could not be revoked.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['site-graph-exported'] ) && 'encode-error' === sanitize_key( (string) wp_unslash( $_GET['site-graph-exported'] ) ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Kiwe could not encode the Site Graph JSON. Try a smaller sample size and check server logs if it continues.', 'dsa' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['binding-plan'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $this->binding_plan_error_message( sanitize_key( (string) wp_unslash( $_GET['binding-plan'] ) ) ) ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_ai_companion_settings( $ai_settings, $companion_status, $rest_base ); ?>

			<?php $this->render_internal_ai_advisor( $advisor, $enrichment, $rest_base ); ?>

			<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'API access keys', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Create a revocable key when an external AI tool needs to inspect this site and use Kiwe’s safe AI connector endpoints. Use Authorization: Bearer or X-Kiwe-AI-Key. Keys are scoped, hashed at rest, and can be deleted from this page.', 'dsa' ); ?></p>
				<div class="dsa-admin-token-summary">
					<div><strong><?php echo esc_html( (string) count( array_filter( $keys, static fn( $record ) => empty( $record['revokedAt'] ) ) ) ); ?></strong><span><?php esc_html_e( 'active keys', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Hashed', 'dsa' ); ?></strong><span><?php esc_html_e( 'storage', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Scoped', 'dsa' ); ?></strong><span><?php esc_html_e( 'access', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Revocable', 'dsa' ); ?></strong><span><?php esc_html_e( 'control', 'dsa' ); ?></span></div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_create_ai_access_key">
					<?php wp_nonce_field( 'dsa_create_ai_access_key' ); ?>
					<p class="dsa-admin-inline-fields">
						<label>
							<span><?php esc_html_e( 'Label', 'dsa' ); ?></span>
							<input type="text" name="label" value="" placeholder="<?php esc_attr_e( 'Claude / ChatGPT / Codex testing', 'dsa' ); ?>">
						</label>
						<label>
							<input type="checkbox" name="scopes[]" value="all">
							<span><?php esc_html_e( 'All Kiwe AI connector access', 'dsa' ); ?></span>
						</label>
						<?php submit_button( __( 'Create API Key', 'dsa' ), 'primary', 'submit', false ); ?>
					</p>
					<details>
						<summary><?php esc_html_e( 'Advanced scopes', 'dsa' ); ?></summary>
						<p class="description"><?php esc_html_e( '“All” is intentionally unchecked because it includes high-authority staging, execution and mutation routes. Select only the scopes the client needs. If none are selected, Kiwe creates a site_graph-only key.', 'dsa' ); ?></p>
						<p class="dsa-admin-token-chips">
							<?php foreach ( Access_Key_Service::SCOPES as $scope ) : ?>
								<?php if ( 'all' === $scope ) { continue; } ?>
								<label><input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>"> <code><?php echo esc_html( $scope ); ?></code></label>
							<?php endforeach; ?>
						</p>
					</details>
				</form>

				<?php if ( [] !== $keys ) : ?>
					<table class="widefat striped" style="margin-top: 1rem;">
						<thead><tr><th><?php esc_html_e( 'Label', 'dsa' ); ?></th><th><?php esc_html_e( 'Key', 'dsa' ); ?></th><th><?php esc_html_e( 'Scopes', 'dsa' ); ?></th><th><?php esc_html_e( 'Created', 'dsa' ); ?></th><th><?php esc_html_e( 'Last used', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th><th><?php esc_html_e( 'Action', 'dsa' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $keys as $record ) : ?>
								<?php
								if ( ! is_array( $record ) ) {
									continue;
								}
								$id      = sanitize_key( (string) ( $record['id'] ?? '' ) );
								$revoked = '' !== (string) ( $record['revokedAt'] ?? '' );
								?>
								<tr>
									<td><?php echo esc_html( (string) ( $record['label'] ?? '' ) ); ?></td>
									<td><code><?php echo esc_html( (string) ( $record['prefix'] ?? '' ) ); ?>...<?php echo esc_html( (string) ( $record['last4'] ?? '' ) ); ?></code></td>
									<td><code><?php echo esc_html( implode( ', ', isset( $record['scopes'] ) && is_array( $record['scopes'] ) ? $record['scopes'] : [] ) ); ?></code></td>
									<td><?php echo esc_html( (string) ( $record['createdAt'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $record['lastUsedAt'] ?? __( 'Never', 'dsa' ) ) ); ?></td>
									<td><?php echo $revoked ? esc_html__( 'Revoked', 'dsa' ) : esc_html__( 'Active', 'dsa' ); ?></td>
									<td>
										<?php if ( ! $revoked && '' !== $id ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="dsa_revoke_ai_access_key">
												<input type="hidden" name="keyId" value="<?php echo esc_attr( $id ); ?>">
												<?php wp_nonce_field( 'dsa_revoke_ai_access_key_' . $id ); ?>
												<button class="button button-secondary" type="submit"><?php esc_html_e( 'Revoke', 'dsa' ); ?></button>
											</form>
										<?php else : ?>
											<span aria-hidden="true">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<section class="dsa-admin__panel" style="margin-top: 1rem;">
				<h2><?php esc_html_e( 'SiteGraph has its own control plane', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Exports, compiler pairing, binding validation, trusted staging, and SiteGraph AI isolation now live under Kiwe > SiteGraph.', 'dsa' ); ?></p>
				<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-sitegraph' ) ); ?>"><?php esc_html_e( 'Open Kiwe SiteGraph', 'dsa' ); ?></a></p>
			</section>
		</div>
		<?php
	