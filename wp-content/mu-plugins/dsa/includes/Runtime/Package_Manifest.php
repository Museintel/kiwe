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

	public static function verify(): array {
		$stamp = self::stamp();
		$proof = get_option( self::CACHE_OPTION, [] );

		if (
			is_array( $proof )
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

				$size = @filesize( $path );
				$hash = @hash_file( 'sha256', $path );
				if ( (int) ( $expected['bytes'] ?? -1 ) !== (int) $size || ! is_string( $hash ) || ! hash_equals( (string) ( $expected['sha256'] ?? '' ), $hash ) ) {
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

	public static function required_files(): array {
		return [
			self::MANIFEST,
			'assets/css/surface.css',
			'assets/css/surface-presets.css',
			'assets/css/seam.css',
			'assets/js/seam.js',
			'assets/js/surface.js',
			'assets/js/seam-dev.js',
			'assets/js/modules/commerce-panels.js',
			'assets/js/modules/links-panel.js',
			'assets/js/modules/ai-panel.js',
			'assets/js/modules/surface-panels.js',
			'assets/js/modules/native-islands.js',
			'includes/Plugin.php',
			'includes/Settings.php',
			'includes/Site/Site_Identity_Service.php',
			'includes/Element_Registry.php',
			'includes/Diagnostics/Runtime_Profiler.php',
			'includes/Utilities/Atomic_Rate_Limiter.php',
			'includes/Diagnostics/Asset_Manifest_Service.php',
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
			'includes/Rest/Runtime_Hydration_Controller.php',
			'includes/Rest/Metrics_Controller.php',
			'includes/PWA/PWA_Service.php',
			'includes/Notifications/Push_Service.php',
			'includes/Notifications/Admin_Event_Notification_Service.php',
			'includes/Notifications/Notification_Campaign_Service.php',
			'includes/Rest/Push_Controller.php',
			'includes/Rest/Admin_Notifications_Controller.php',
			'includes/Rest/Search_Controller.php',
			'includes/Search/Search_Service.php',
			'includes/Rest/Saved_Items_Controller.php',
			'includes/Saved/Saved_Items_Service.php',
			'includes/Runtime/Route_Capability_Service.php',
			'includes/Runtime/Editorial_Fragment_Service.php',
			'includes/Rest/Editorial_Envelope_Controller.php',
			'includes/Public_Endpoint/Assets.php',
		];
	}

	public static function clear_cached_proof(): void {
		delete_option( self::CACHE_OPTION );
	}

	private static function stamp(): string {
		$manifest_file = DSA_DIR . self::MANIFEST;
		return hash( 'sha256', DSA_VERSION . '|' . (string) @filemtime( $manifest_file ) . '|' . (string) @filesize( $manifest_file ) );
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
