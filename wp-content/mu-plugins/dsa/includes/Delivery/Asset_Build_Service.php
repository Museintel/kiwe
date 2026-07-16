<?php

namespace DSA\Delivery;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Asset_Build_Service {
	private const BUILD_HOOK = 'dsa_asset_build_pilot';
	private const MANIFEST_OPTION = 'dsa_asset_build_manifest_v1';
	private const STATUS_OPTION = 'dsa_asset_build_status_v1';

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( self::BUILD_HOOK, [ $this, 'build' ] );
		add_action( 'admin_post_dsa_queue_asset_build', [ $this, 'handle_queue' ] );
		add_action( 'admin_init', [ $this, 'maybe_queue_stale_build' ] );
		add_action( 'switch_theme', [ $this, 'queue_theme_build' ] );
		add_filter( 'dsa_surface_stylesheet_url', [ $this, 'stylesheet_url' ] );
		add_filter( 'dsa_surface_stylesheet_version', [ $this, 'stylesheet_version' ] );
		add_action( 'wp_head', [ $this, 'print_font_hints' ], 3 );
	}

	public function handle_queue(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dsa' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'dsa_queue_asset_build' );
		$this->queue( 'manual' );
		wp_safe_redirect( add_query_arg( [ 'page' => 'kiwe', 'asset-build-queued' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function queue_theme_build(): void {
		if ( $this->enabled() ) $this->queue( 'theme-change' );
	}

	public function maybe_queue_stale_build(): void {
		if ( $this->enabled() && ! $this->valid_manifest() ) $this->queue( 'stale-or-missing' );
	}

	public function queue( string $reason ): bool {
		if ( wp_next_scheduled( self::BUILD_HOOK ) ) return true;
		$scheduled = wp_schedule_single_event( time() + 5, self::BUILD_HOOK, [], true );
		update_option(
			self::STATUS_OPTION,
			[
				'state' => is_wp_error( $scheduled ) ? 'failed' : 'queued',
				'reason' => sanitize_key( $reason ),
				'updatedAt' => current_time( 'mysql' ),
				'message' => is_wp_error( $scheduled ) ? sanitize_text_field( $scheduled->get_error_message() ) : '',
			],
			false
		);
		return ! is_wp_error( $scheduled );
	}

	public function build(): void {
		$source = DSA_DIR . 'assets/css/surface.css';
		$css = is_readable( $source ) ? file_get_contents( $source ) : false;
		if ( false === $css || '' === $css ) {
			$this->fail( 'source_unreadable' );
			return;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			$this->fail( 'uploads_unavailable' );
			return;
		}

		$source_hash = hash( 'sha256', $css );
		$theme = $this->theme_fingerprint();
		$build_id = substr( hash( 'sha256', DSA_VERSION . '|' . $source_hash . '|' . $theme ), 0, 20 );
		$directory = trailingslashit( $uploads['basedir'] ) . 'kiwe-builds';
		if ( ! wp_mkdir_p( $directory ) ) {
			$this->fail( 'build_directory_unavailable' );
			return;
		}

		$filename = 'surface-' . $build_id . '.css';
		$path = trailingslashit( $directory ) . $filename;
		if ( ! $this->atomic_write( $path, $css ) ) {
			$this->fail( 'artifact_write_failed' );
			return;
		}

		$artifact_hash = hash_file( 'sha256', $path );
		if ( ! is_string( $artifact_hash ) || ! hash_equals( $source_hash, $artifact_hash ) ) {
			$this->fail( 'artifact_hash_mismatch' );
			return;
		}

		$manifest = [
			'schema' => 1,
			'buildId' => $build_id,
			'pluginVersion' => DSA_VERSION,
			'themeFingerprint' => $theme,
			'generatedAt' => current_time( 'mysql' ),
			'source' => [ 'pathId' => substr( md5( wp_normalize_path( $source ) ), 0, 12 ), 'hash' => $source_hash, 'bytes' => strlen( $css ) ],
			'artifact' => [
				'path' => wp_normalize_path( $path ),
				'url' => trailingslashit( $uploads['baseurl'] ) . 'kiwe-builds/' . $filename,
				'hash' => $artifact_hash,
				'bytes' => (int) filesize( $path ),
			],
			'hints' => $this->asset_hints( $css ),
			'fallback' => [ 'url' => DSA_URL . 'assets/css/surface.css', 'version' => DSA_VERSION ],
			'validated' => true,
		];

		$public_manifest = $manifest;
		unset( $public_manifest['artifact']['path'] );
		$manifest_json = wp_json_encode( $public_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $manifest_json ) || ! $this->atomic_write( trailingslashit( $directory ) . 'manifest.json', $manifest_json ) ) {
			$this->fail( 'manifest_write_failed' );
			return;
		}

		update_option( self::MANIFEST_OPTION, $manifest, false );
		update_option( self::STATUS_OPTION, [ 'state' => 'ready', 'reason' => 'build-complete', 'updatedAt' => current_time( 'mysql' ), 'message' => '' ], false );
		$this->cleanup( $directory, $filename );
	}

	public function stylesheet_url( string $fallback ): string {
		$manifest = $this->valid_manifest();
		return $this->apply_enabled() && $manifest ? esc_url_raw( (string) $manifest['artifact']['url'] ) : $fallback;
	}

	public function stylesheet_version( string $fallback ): string {
		$manifest = $this->valid_manifest();
		return $this->apply_enabled() && $manifest ? sanitize_text_field( (string) $manifest['buildId'] ) : $fallback;
	}

	public function print_font_hints(): void {
		if ( ! $this->hints_enabled() ) return;
		$manifest = $this->valid_manifest();
		if ( ! $manifest ) return;
		foreach ( array_slice( (array) ( $manifest['hints']['fonts'] ?? [] ), 0, 2 ) as $url ) {
			printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>\n', esc_url( (string) $url ) );
		}
	}

	public static function status(): array {
		$status = get_option( self::STATUS_OPTION, [] );
		$manifest = get_option( self::MANIFEST_OPTION, [] );
		return [
			'state' => sanitize_key( (string) ( $status['state'] ?? 'not-built' ) ),
			'updatedAt' => sanitize_text_field( (string) ( $status['updatedAt'] ?? '' ) ),
			'message' => sanitize_text_field( (string) ( $status['message'] ?? '' ) ),
			'buildId' => sanitize_text_field( (string) ( $manifest['buildId'] ?? '' ) ),
			'bytes' => absint( $manifest['artifact']['bytes'] ?? 0 ),
			'fonts' => count( (array) ( $manifest['hints']['fonts'] ?? [] ) ),
			'media' => count( (array) ( $manifest['hints']['media'] ?? [] ) ),
		];
	}

	private function valid_manifest(): array {
		$manifest = get_option( self::MANIFEST_OPTION, [] );
		if ( ! is_array( $manifest ) || empty( $manifest['validated'] ) || ( $manifest['pluginVersion'] ?? '' ) !== DSA_VERSION || ( $manifest['themeFingerprint'] ?? '' ) !== $this->theme_fingerprint() ) return [];
		$path = (string) ( $manifest['artifact']['path'] ?? '' );
		return $path && is_file( $path ) ? $manifest : [];
	}

	private function asset_hints( string $css ): array {
		$fonts = [];
		$media = [];
		if ( preg_match_all( '#url\(\s*["\']?([^"\')]+)#i', $css, $matches ) ) {
			foreach ( $matches[1] as $candidate ) {
				$url = $this->absolute_asset_url( trim( (string) $candidate ) );
				if ( ! $url || ! $this->same_origin( $url ) ) continue;
				$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				if ( str_ends_with( $path, '.woff2' ) ) $fonts[] = $url;
				if ( preg_match( '#\.(?:avif|webp|png|jpe?g|gif)$#', $path ) ) $media[] = $url;
			}
		}
		foreach ( [ get_option( 'site_icon', 0 ), get_option( 'kiwe_site_logo_id', 0 ), get_option( 'kiwe_site_logo_inverse_id', 0 ) ] as $attachment_id ) {
			$url = $attachment_id ? wp_get_attachment_image_url( absint( $attachment_id ), 'full' ) : '';
			if ( $url && $this->same_origin( $url ) ) $media[] = esc_url_raw( $url );
		}
		return [ 'fonts' => array_slice( array_values( array_unique( $fonts ) ), 0, 4 ), 'media' => array_slice( array_values( array_unique( $media ) ), 0, 8 ) ];
	}

	private function absolute_asset_url( string $value ): string {
		if ( '' === $value || str_starts_with( $value, 'data:' ) ) return '';
		if ( preg_match( '#^https?://#i', $value ) ) return esc_url_raw( $value );
		$path = realpath( DSA_DIR . 'assets/css/' . strtok( $value, '?#' ) );
		$root = realpath( DSA_DIR );
		if ( ! is_string( $path ) || ! is_string( $root ) ) return '';
		$path = wp_normalize_path( $path );
		$root = wp_normalize_path( trailingslashit( $root ) );
		if ( ! str_starts_with( $path, $root ) ) return '';
		return esc_url_raw( trailingslashit( DSA_URL ) . ltrim( substr( $path, strlen( $root ) ), '/' ) );
	}

	private function same_origin( string $url ): bool {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	private function atomic_write( string $path, string $content ): bool {
		if ( is_file( $path ) ) {
			$existing = hash_file( 'sha256', $path );
			if ( is_string( $existing ) && hash_equals( hash( 'sha256', $content ), $existing ) ) return true;
		}
		$temp = $path . '.tmp-' . wp_generate_password( 8, false, false );
		$written = file_put_contents( $temp, $content, LOCK_EX );
		if ( false === $written || $written !== strlen( $content ) ) {
			if ( is_file( $temp ) ) unlink( $temp );
			return false;
		}
		if ( ! rename( $temp, $path ) ) {
			if ( is_file( $path ) && ! unlink( $path ) ) {
				unlink( $temp );
				return false;
			}
			if ( rename( $temp, $path ) ) return true;
			unlink( $temp );
			return false;
		}
		return true;
	}

	private function cleanup( string $directory, string $current ): void {
		$files = glob( trailingslashit( $directory ) . 'surface-*.css', GLOB_NOSORT );
		if ( ! is_array( $files ) || count( $files ) <= 3 ) return;
		usort( $files, static fn( string $a, string $b ): int => (int) filemtime( $b ) <=> (int) filemtime( $a ) );
		foreach ( array_slice( $files, 3 ) as $file ) {
			if ( basename( $file ) !== $current && str_starts_with( wp_normalize_path( $file ), wp_normalize_path( trailingslashit( $directory ) ) ) ) unlink( $file );
		}
	}

	private function theme_fingerprint(): string {
		$theme = wp_get_theme();
		return substr( hash( 'sha256', get_stylesheet() . '|' . (string) $theme->get( 'Version' ) ), 0, 16 );
	}

	private function diagnostics(): array {
		$value = $this->settings->get( 'diagnostics', [] );
		return is_array( $value ) ? $value : [];
	}

	private function enabled(): bool { $settings = $this->diagnostics(); return ! empty( $settings['asset_build_pilot'] ); }
	private function apply_enabled(): bool { $settings = $this->diagnostics(); return $this->enabled() && ! empty( $settings['asset_build_apply'] ); }
	private function hints_enabled(): bool { $settings = $this->diagnostics(); return $this->enabled() && ! empty( $settings['asset_build_hints'] ); }

	private function fail( string $reason ): void {
		update_option( self::STATUS_OPTION, [ 'state' => 'failed', 'reason' => sanitize_key( $reason ), 'updatedAt' => current_time( 'mysql' ), 'message' => sanitize_text_field( $reason ) ], false );
	}
}
