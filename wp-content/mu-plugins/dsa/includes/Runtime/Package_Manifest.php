<?php

namespace DSA\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cheap package-integrity gate for shared-host deployments.
 *
 * The manifest stamp is checked on every request; the full file inventory is
 * checked only after a release/upload change or when the cached proof expires.
 */
final class Package_Manifest {
	private const CACHE_OPTION = 'dsa_package_manifest_proof';
	private const CACHE_TTL    = 43200;
	private const MANIFEST     = 'package-manifest.json';

	public static function verify( bool $force = false ): array {
		$stamp = self::stamp();
		$proof = get_option( self::CACHE_OPTION, [] );

		if (
			! $force
			&& is_array( $proof )
			&& hash_equals( (string) ( $proof['stamp'] ?? '' ), $stamp )
			&& ! empty( $proof['checked_at'] )
			&& ( time() - (int) $proof['checked_at'] ) < self::CACHE_TTL
		) {
			return $proof;
		}

		$missing  = [];
		$changed  = [];
		$manifest = self::read_manifest();

		if ( null === $manifest ) {
			$missing[] = self::MANIFEST;
		} else {
			foreach ( $manifest['files'] as $relative => $expected ) {
				$path = DSA_DIR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
				if ( ! is_file( $path ) ) {
					$missing[] = $relative;
					continue;
				}

				$body = @file_get_contents( $path );
				$body = is_string( $body ) ? self::canonical_body( $body, pathinfo( $path, PATHINFO_EXTENSION ) ) : false;
				if ( false === $body || (int) ( $expected['bytes'] ?? -1 ) !== strlen( $body ) || ! hash_equals( (string) ( $expected['sha256'] ?? '' ), hash( 'sha256', $body ) ) ) {
					$changed[] = $relative;
				}
			}
		}

		$required_missing = [];
		foreach ( self::required_files() as $relative ) {
			if ( ! is_file( DSA_DIR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) ) ) {
				$required_missing[] = $relative;
			}
		}

		$blocking_missing = array_values( array_unique( array_merge( null === $manifest ? [ self::MANIFEST ] : [], $required_missing ) ) );

		$proof = [
			'stamp'            => $stamp,
			'checked_at'       => time(),
			'valid'            => [] === $blocking_missing,
			'complete'         => [] === $missing && [] === $changed,
			'blocking_missing' => $blocking_missing,
			'missing'          => $missing,
			'changed'          => $changed,
			'file_count'       => null !== $manifest ? count( $manifest['files'] ) : 0,
		];
		update_option( self::CACHE_OPTION, $proof, false );

		return $proof;
	}

	private static function canonical_body( string $body, string $extension ): string {
		// Must match build-package-manifest.cjs and verify-package.cjs. Windows
		// uploads can contain CRLF without changing the release's text content.
		if ( in_array( strtolower( $extension ), [ 'css', 'html', 'js', 'json', 'md', 'php', 'svg', 'txt', 'xml' ], true ) ) {
			return str_replace( [ "\r\n", "\r" ], "\n", $body );
		}
		return $body;
	}

	public static function required_files(): array {
		$files = [
			self::MANIFEST,
			'assets/css/surface.css',
			'assets/css/surface-presets.css',
			'assets/css/seam.css',
			'assets/css/workspace-tokens.css',
			'assets/css/workspace-foundation.css',
			'assets/js/seam.js',
			'assets/js/surface.js',
			'assets/js/public-contract.js',
			'assets/js/source-runtime.js',
			'includes/Bricks/Source_Runtime_Bridge.php',
			'assets/js/modules/diagnostics.js',
			'assets/js/seam-dev.js',
			'assets/js/modules/commerce-panels.js',
			'assets/js/modules/links-panel.js',
			'assets/js/modules/ai-panel.js',
			'assets/js/modules/surface-panels.js',
			'assets/js/modules/native-islands.js',
			'includes/Plugin.php',
			'includes/Runtime/Request_Option_Primer.php',
			'includes/Settings.php',
			'includes/AI/AI_Broker_Service.php',
			'includes/SEO/SEO_Refinement_Service.php',
			'includes/SEO/SEO_Job_Store.php',
			'includes/SEO/SEO_Score_Source.php',
			'includes/SEO/SEO_Score_Service.php',
			'includes/SEO/SEO_Discovery_Service.php',
			'includes/AI/AI_Allowance_Service.php',
			'includes/AI/AI_Usage_Service.php',
			'includes/AI/AI_Feature_Policy.php',
			'includes/Access/AI_Workspace_Service.php',
			'includes/Access/Backup_Workspace_Service.php',
			'includes/Backup/Configuration_Snapshot_Service.php',
			'includes/Backup/SEO_State_Snapshot_Service.php',
			'includes/Backup/Archive_Cipher.php',
			'includes/Backup/Content_Archive_Service.php',
			'includes/Backup/Backup_Request_Service.php',
			'includes/Access/Content_Archive_App_Service.php',
			'includes/Backup/Bricks_Design_Package_Service.php',
			'includes/Access/Bricks_Design_Package_App_Service.php',
			'assets/js/backups-app.js',
			'assets/css/backups-app.css',
			'includes/Access/Campaign_Workspace_Service.php',
			'includes/Access/User_Group_Service.php',
			'includes/Access/Record_Card_Control_Service.php',
			'includes/Onboarding/Native_Service_Source_Service.php',
			'includes/Onboarding/Native_Service_Conversion_Service.php',
			'includes/Access/Service_History_Service.php',
			'includes/Onboarding/Service_Conversion_Exception.php',
			'includes/SEO/SEO_Metadata_Migration_Service.php',
			'includes/Apps/SEO_App_Service.php',
			'includes/Apps/Store_App_Service.php',
			'includes/Apps/App_Card_Service.php',
			'includes/SEO/SEO_Engine_Service.php',
			'includes/Onboarding/Native_Indexing_Service.php',
			'includes/Onboarding/Search_Report_Snapshot.php',
			'includes/SEO/SEO_Defaults_Model.php',
			'includes/Analytics/Restored_Reporting_State.php',
			'includes/SEO/SEO_Settings_Handover_Model.php',
			'includes/SEO/SEO_Settings_Handover_Service.php',
			'includes/AI/Task_Capsule_Service.php',
			'includes/AI/External_Client_OpenAPI_Service.php',
			'includes/AI/External_Client_Adapter_Service.php',
			'includes/Site/Site_Identity_Service.php',
			'includes/Element_Registry.php',
			'includes/Diagnostics/Runtime_Profiler.php',
			'includes/Utilities/Atomic_Rate_Limiter.php',
			'includes/Diagnostics/Asset_Manifest_Service.php',
			'includes/Diagnostics/Persistence_Maintenance_Service.php',
			'includes/Diagnostics/Database_Inventory_Service.php',
			'includes/Diagnostics/Cache_Maintenance_Service.php',
			'includes/Diagnostics/Test_Site_Snapshot_Service.php',
			'includes/Site_Graph/Staging_Seed_Export_Service.php',
			'includes/Site_Graph/Staging_Seed_Preflight_Service.php',
			'includes/Site_Graph/Staging_Seed_Connection_Service.php',
			'includes/Site_Graph/Staging_Seed_Ledger_Service.php',
			'includes/Site_Graph/Staging_Seed_Package_Service.php',
			'includes/Site_Graph/Staging_Seed_Dry_Run_Service.php',
			'includes/Site_Graph/Staging_Seed_Import_Ledger_Service.php',
			'includes/Site_Graph/Staging_Seed_Import_Service.php',
			'includes/Design/Token_Schema.php',
			'includes/Design/Seam_Token_Service.php',
			'includes/Design/Seam_Vocabulary_Schema.php',
			'includes/Modules/Module_Registry.php',
			'includes/Protected_Flow/Flow_Context.php',
			'includes/Protected_Flow/Flow_State.php',
			'includes/PhoneKey/PhoneKey_Bridge.php',
			'includes/Trust/Trust_Service.php',
			'includes/Commerce/Checkout_Service.php',
			'includes/Commerce/Abandoned_Cart_Service.php',
			'includes/Communications/Email_Service.php',
			'includes/Communications/Channel_Service.php',
			'includes/Security/Secret_Store.php',
			'includes/Rest/Checkout_Controller.php',
			'includes/Rest/AI_Access_Controller.php',
			'includes/Rest/Runtime_Hydration_Controller.php',
			'includes/Rest/Metrics_Controller.php',
			'includes/PWA/PWA_Service.php',
			'includes/Notifications/Push_Service.php',
			'includes/Notifications/Admin_Event_Notification_Service.php',
			'includes/Notifications/Notification_Campaign_Service.php',
			'includes/Notifications/Campaign_Unsubscribe_Service.php',
			'includes/Notifications/New_Post_Notification_Service.php',
			'includes/Rest/Push_Controller.php',
			'includes/Rest/Admin_Notifications_Controller.php',
			'includes/Rest/Search_Controller.php',
			'includes/Search/Search_Service.php',
			'includes/Rest/Saved_Items_Controller.php',
			'includes/Saved/Saved_Items_Service.php',
			'includes/Runtime/Route_Capability_Service.php',
			'includes/Runtime/Editorial_Fragment_Service.php',
			'includes/Runtime/Update_Service.php',
			'includes/Rest/Editorial_Envelope_Controller.php',
			'includes/Public_Endpoint/Assets.php',
		];
		$files = array_merge( $files, ['includes/Apps/Package_Layout.php','includes/Apps/Runtime_Contract.php','includes/Apps/Runtime_Registry.php','includes/Apps/Asset_Locator.php','includes/Apps/Update_Hooks.php','includes/Apps/Native_Update_Service.php','includes/Apps/Signed_Packet.php','includes/Apps/Package_Verifier.php','includes/Apps/Native_State.php','includes/Apps/Catalogue_Service.php','includes/Apps/Install_Plan.php','includes/Apps/Installer_Service.php','includes/Apps/Code_Recovery.php','includes/Apps/Library_Service.php','includes/Apps/Release_Coordinator.php','includes/Apps/Migration_Service.php','includes/Onboarding/Search_Defaults_Model.php','includes/Onboarding/Search_Settings_Writer.php'] );
		if ( class_exists( \DSA\Apps\Package_Layout::class ) && ! \DSA\Apps\Package_Layout::BUNDLED ) $files = array_values( array_diff( $files, \DSA\Apps\Package_Layout::APP_FILES ) );
		return $files;
	}

	public static function clear_cached_proof(): void {
		delete_option( self::CACHE_OPTION );
	}

	/**
	 * Files left behind by merged/partial MU-plugin uploads are not covered by
	 * the integrity manifest and still consume hosting inodes. Report only
	 * entries inside the canonical Kiwe package directory.
	 *
	 * @return array<int,array{path:string,bytes:int,link:bool}>
	 */
	public static function unexpected_files(): array {
		$manifest = self::read_manifest();
		if ( null === $manifest || ! is_dir( DSA_DIR ) ) {
			return [];
		}

		$expected = array_fill_keys( array_keys( $manifest['files'] ), true );
		$expected[ self::MANIFEST ] = true;
		$unexpected = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( DSA_DIR, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() && ! $file->isLink() ) {
				continue;
			}

			$absolute = wp_normalize_path( $file->getPathname() );
			$relative = ltrim( substr( $absolute, strlen( wp_normalize_path( DSA_DIR ) ) ), '/' );
			if ( '' === $relative || isset( $expected[ $relative ] ) ) {
				continue;
			}

			$unexpected[] = [
				'path'  => $relative,
				'bytes' => $file->isLink() ? 0 : max( 0, (int) $file->getSize() ),
				'link'  => $file->isLink(),
			];
		}

		usort( $unexpected, static fn( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );
		return $unexpected;
	}

	private static function stamp(): string {
		$manifest_file = DSA_DIR . self::MANIFEST;
		return hash( 'sha256', 'canonical-lf-v1|' . DSA_VERSION . '|' . (string) @filemtime( $manifest_file ) . '|' . (string) @filesize( $manifest_file ) );
	}

	private static function read_manifest(): ?array {
		$path = DSA_DIR . self::MANIFEST;
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if (
			! is_array( $decoded )
			|| 1 !== (int) ( $decoded['schema'] ?? 0 )
			|| DSA_VERSION !== (string) ( $decoded['version'] ?? '' )
			|| ! isset( $decoded['files'] )
			|| ! is_array( $decoded['files'] )
			|| [] === $decoded['files']
		) {
			return null;
		}

		foreach ( $decoded['files'] as $relative => $proof ) {
			if (
				! is_string( $relative )
				|| '' === $relative
				|| str_contains( $relative, '..' )
				|| str_starts_with( $relative, '/' )
				|| ! is_array( $proof )
				|| ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $proof['sha256'] ?? '' ) )
			) {
				return null;
			}
		}

		return $decoded;
	}
}
