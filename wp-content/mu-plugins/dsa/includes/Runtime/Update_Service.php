<?php

namespace DSA\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signed, staged updates for Kiwe's MU-plugin package.
 *
 * WordPress does not update folder-based MU plugins. This service deliberately
 * avoids the normal plugin upgrader and accepts only Kiwe releases signed by
 * the embedded Ed25519 release key. Package and inner-manifest hashes are
 * checked before any live path is touched.
 */
final class Update_Service {
	public const SETTINGS_OPTION = 'kiwe_update_settings';
	public const STATE_OPTION    = 'kiwe_update_state';
	public const CRON_HOOK       = 'kiwe_signed_update_check';

	private const LOCK_OPTION     = 'kiwe_update_lock';
	private const FEED_BASE       = 'https://app.kiwelaunch.com/updates/kiwe/v1/';
	private const SCHEMA          = 'kiwe.mu-release.v1';
	private const PUBLIC_KEY_ID   = 'kiwe-release-2026-01';
	private const PUBLIC_KEY_B64  = 'v6qwuL3+Zqx76G5A1MCqkvnzOM62fyXudc8OCgXqKzg=';
	private const CHECK_TTL       = 21600;
	private const LOCK_TTL        = 900;
	private const TRANSACTION_FILE = 'kiwe-update-transaction.json';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'reconcile_schedule' ] );
		add_action( self::CRON_HOOK, [ $this, 'run_automatic_update' ] );
	}

	public function settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, [] );
		return wp_parse_args(
			is_array( $stored ) ? $stored : [],
			[
				'automatic' => false,
				'channel'   => 'stable',
			]
		);
	}

	public function save_settings( array $input ): array {
		$settings = [
			'automatic' => ! empty( $input['automatic'] ),
			'channel'   => in_array( (string) ( $input['channel'] ?? '' ), [ 'stable', 'candidate' ], true ) ? (string) $input['channel'] : 'stable',
		];
		update_option( self::SETTINGS_OPTION, $settings, false );
		$this->reconcile_schedule();
		return $settings;
	}

	public function state(): array {
		$state = get_option( self::STATE_OPTION, [] );
		return is_array( $state ) ? $state : [];
	}

	public function reconcile_schedule(): void {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( ! empty( $this->settings()['automatic'] ) ) {
			if ( false === $scheduled ) {
				wp_schedule_event( time() + wp_rand( 300, 1800 ), 'twicedaily', self::CRON_HOOK );
			}
			return;
		}

		if ( false !== $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}
	}

	public function run_automatic_update(): void {
		if ( empty( $this->settings()['automatic'] ) ) {
			return;
		}

		try {
			$release = $this->check( true );
			if ( ! empty( $release['available'] ) ) {
				$this->install( $release );
			}
		} catch ( \Throwable $error ) {
			$this->record_error( $error->getMessage() );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function check( bool $force = false ): array {
		$state = $this->state();
		if ( ! $force && ! empty( $state['release'] ) && ( time() - (int) ( $state['checked_at'] ?? 0 ) ) < self::CHECK_TTL ) {
			return (array) $state['release'];
		}

		$channel = (string) $this->settings()['channel'];
		$url     = apply_filters( 'kiwe_update_feed_url', self::FEED_BASE . $channel . '.json', $channel );
		if ( ! $this->is_trusted_release_url( $url, false ) ) {
			throw new \RuntimeException( 'Kiwe update feed must use the trusted HTTPS release origin.' );
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'     => 12,
				'redirection' => 2,
				'headers'     => [ 'Accept' => 'application/json' ],
				'user-agent'  => 'Kiwe/' . DSA_VERSION . '; ' . home_url( '/' ),
			]
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Kiwe update check failed: ' . $response->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( 'Kiwe update feed returned HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . '.' );
		}

		$release = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || ! $this->verify_release( $release, $channel ) ) {
			throw new \RuntimeException( 'Kiwe rejected update metadata because its signature or release contract is invalid.' );
		}

		$release['available'] = version_compare( (string) $release['version'], DSA_VERSION, '>' );
		$state = [
			'checked_at' => time(),
			'release'    => $release,
			'last_error' => '',
		] + $state;
		update_option( self::STATE_OPTION, $state, false );
		return $release;
	}

	/**
	 * @param array<string,mixed>|null $release Verified release metadata.
	 * @return array<string,mixed>
	 */
	public function install( ?array $release = null ): array {
		if ( ! $this->acquire_lock() ) {
			throw new \RuntimeException( 'Another Kiwe update is already running.' );
		}

		$download = '';
		$work     = '';
		try {
			$release = is_array( $release ) ? $release : $this->check( true );
			$channel = (string) $this->settings()['channel'];
			if ( ! $this->verify_release( $release, $channel ) ) {
				throw new \RuntimeException( 'Kiwe refused to install unverified release metadata.' );
			}
			if ( ! version_compare( (string) $release['version'], DSA_VERSION, '>' ) ) {
				throw new \RuntimeException( 'No newer Kiwe release is available for this channel.' );
			}

			global $wp_version;
			if ( version_compare( PHP_VERSION, (string) $release['requiresPhp'], '<' ) ) {
				throw new \RuntimeException( 'This Kiwe release requires PHP ' . (string) $release['requiresPhp'] . ' or newer.' );
			}
			if ( version_compare( (string) $wp_version, (string) $release['requiresWp'], '<' ) ) {
				throw new \RuntimeException( 'This Kiwe release requires WordPress ' . (string) $release['requiresWp'] . ' or newer.' );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			global $wp_filesystem;
			if ( ! WP_Filesystem() || ! $wp_filesystem ) {
				throw new \RuntimeException( 'Kiwe could not initialize WordPress filesystem access for the staged update.' );
			}
			$download = download_url( (string) $release['packageUrl'], 60 );
			if ( is_wp_error( $download ) ) {
				throw new \RuntimeException( 'Kiwe package download failed: ' . $download->get_error_message() );
			}
			if ( ! hash_equals( (string) $release['sha256'], hash_file( 'sha256', $download ) ?: '' ) ) {
				throw new \RuntimeException( 'Kiwe package checksum did not match the signed release.' );
			}
			if ( (int) $release['bytes'] !== (int) filesize( $download ) ) {
				throw new \RuntimeException( 'Kiwe package size did not match the signed release.' );
			}

			$upgrade_root = trailingslashit( WP_CONTENT_DIR ) . 'upgrade';
			if ( ! wp_mkdir_p( $upgrade_root ) ) {
				throw new \RuntimeException( 'Kiwe could not create the WordPress upgrade directory.' );
			}
			$work = trailingslashit( $upgrade_root ) . 'kiwe-stage-' . wp_generate_uuid4();
			if ( ! wp_mkdir_p( $work ) ) {
				throw new \RuntimeException( 'Kiwe could not create a staging directory.' );
			}

			$unzipped = unzip_file( $download, $work );
			if ( is_wp_error( $unzipped ) ) {
				throw new \RuntimeException( 'Kiwe could not unpack the verified release: ' . $unzipped->get_error_message() );
			}
			$package_root = $this->locate_package_root( $work );
			$this->verify_package_root( $package_root, $release );
			$this->swap_package( $package_root, $release );

			$state = $this->state();
			$state['last_installed']    = (string) $release['version'];
			$state['last_installed_at'] = time();
			$state['last_error']        = '';
			$state['release']           = $release;
			$state['release']['available'] = false;
			update_option( self::STATE_OPTION, $state, false );

			return [ 'ok' => true, 'version' => (string) $release['version'] ];
		} catch ( \Throwable $error ) {
			$this->record_error( $error->getMessage() );
			throw $error;
		} finally {
			if ( is_string( $download ) && '' !== $download && is_file( $download ) ) {
				@unlink( $download );
			}
			if ( '' !== $work && is_dir( $work ) ) {
				$this->remove_tree( $work );
			}
			$this->release_lock();
		}
	}

	private function verify_release( array $release, string $channel ): bool {
		$required = [ 'schema', 'version', 'channel', 'publishedAt', 'packageUrl', 'sha256', 'bytes', 'requiresPhp', 'requiresWp', 'packageManifestSha256', 'keyId', 'signature' ];
		foreach ( $required as $field ) {
			if ( ! isset( $release[ $field ] ) || '' === (string) $release[ $field ] ) {
				return false;
			}
		}
		if ( self::SCHEMA !== $release['schema'] || self::PUBLIC_KEY_ID !== $release['keyId'] || $channel !== $release['channel'] ) {
			return false;
		}
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', (string) $release['version'] ) ) {
			return false;
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $release['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $release['packageManifestSha256'] ) ) {
			return false;
		}
		if ( (int) $release['bytes'] < 1024 || ! $this->is_trusted_release_url( (string) $release['packageUrl'], true ) ) {
			return false;
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}

		$signature = base64_decode( (string) $release['signature'], true );
		$key       = base64_decode( self::PUBLIC_KEY_B64, true );
		if ( false === $signature || false === $key || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return false;
		}

		return sodium_crypto_sign_verify_detached( $signature, $this->signature_payload( $release ), $key );
	}

	private function signature_payload( array $release ): string {
		return implode(
			"\n",
			[
				(string) $release['schema'],
				(string) $release['version'],
				(string) $release['channel'],
				(string) $release['publishedAt'],
				(string) $release['packageUrl'],
				(string) $release['sha256'],
				(string) (int) $release['bytes'],
				(string) $release['requiresPhp'],
				(string) $release['requiresWp'],
				(string) $release['packageManifestSha256'],
				(string) $release['keyId'],
			]
		) . "\n";
	}

	private function is_trusted_release_url( string $url, bool $package ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || 'app.kiwelaunch.com' !== strtolower( (string) ( $parts['host'] ?? '' ) ) ) {
			return false;
		}
		$path = (string) ( $parts['path'] ?? '' );
		return 0 === strpos( $path, '/updates/kiwe/v1/' ) && ( ! $package || str_ends_with( $path, '.zip' ) );
	}

	private function locate_package_root( string $work ): string {
		if ( is_file( trailingslashit( $work ) . 'dsa.php' ) ) {
			return untrailingslashit( $work );
		}
		$entries = array_values( array_filter( (array) glob( trailingslashit( $work ) . '*' ), 'is_dir' ) );
		if ( 1 === count( $entries ) && is_file( trailingslashit( $entries[0] ) . 'dsa.php' ) ) {
			return untrailingslashit( $entries[0] );
		}
		throw new \RuntimeException( 'Kiwe package layout is invalid.' );
	}

	private function verify_package_root( string $root, array $release ): void {
		foreach ( [ 'dsa.php', 'kiwe-incident-guard.php', 'dsa/dsa.php', 'dsa/package-manifest.json' ] as $relative ) {
			if ( ! is_file( trailingslashit( $root ) . $relative ) || is_link( trailingslashit( $root ) . $relative ) ) {
				throw new \RuntimeException( 'Kiwe package is missing required file: ' . $relative );
			}
		}

		$manifest_path = trailingslashit( $root ) . 'dsa/package-manifest.json';
		if ( ! hash_equals( (string) $release['packageManifestSha256'], hash_file( 'sha256', $manifest_path ) ?: '' ) ) {
			throw new \RuntimeException( 'Kiwe inner package manifest does not match the signed release.' );
		}
		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) || 1 !== (int) ( $manifest['schema'] ?? 0 ) || (string) $release['version'] !== (string) ( $manifest['version'] ?? '' ) || empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			throw new \RuntimeException( 'Kiwe inner package manifest is invalid.' );
		}

		foreach ( $manifest['files'] as $relative => $proof ) {
			$relative = str_replace( '\\', '/', (string) $relative );
			if ( '' === $relative || str_starts_with( $relative, '/' ) || str_contains( $relative, '../' ) ) {
				throw new \RuntimeException( 'Kiwe package manifest contains an unsafe path.' );
			}
			$file = trailingslashit( $root ) . 'dsa/' . $relative;
			if ( ! is_file( $file ) || is_link( $file ) ) {
				throw new \RuntimeException( 'Kiwe package inventory is incomplete: ' . $relative );
			}
			$body = file_get_contents( $file );
			if ( false === $body ) {
				throw new \RuntimeException( 'Kiwe could not read staged file: ' . $relative );
			}
			if ( in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), [ 'css', 'html', 'js', 'json', 'md', 'php', 'svg', 'txt', 'xml' ], true ) ) {
				$body = str_replace( [ "\r\n", "\r" ], "\n", $body );
			}
			if ( (int) ( $proof['bytes'] ?? -1 ) !== strlen( $body ) || ! hash_equals( (string) ( $proof['sha256'] ?? '' ), hash( 'sha256', $body ) ) ) {
				throw new \RuntimeException( 'Kiwe staged file failed manifest verification: ' . $relative );
			}
		}

		$loader = (string) file_get_contents( trailingslashit( $root ) . 'dsa.php' );
		$nested = (string) file_get_contents( trailingslashit( $root ) . 'dsa/dsa.php' );
		$version = preg_quote( (string) $release['version'], '/' );
		if ( ! preg_match( "/define\\(\\s*'KIWE_MU_LOADER_VERSION'\\s*,\\s*'{$version}'\\s*\\)/", $loader ) || ! preg_match( "/define\\(\\s*'DSA_VERSION'\\s*,\\s*'{$version}'\\s*\\)/", $nested ) ) {
			throw new \RuntimeException( 'Kiwe loader and nested package versions do not match the signed release.' );
		}
	}

	private function swap_package( string $source, array $release ): void {
		$mu_root      = trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
		$upgrade_root = trailingslashit( WP_CONTENT_DIR ) . 'upgrade';
		$backup       = trailingslashit( $upgrade_root ) . 'kiwe-backup-' . wp_generate_uuid4();
		$transaction  = trailingslashit( $mu_root ) . self::TRANSACTION_FILE;
		if ( ! is_dir( $mu_root ) || ! wp_mkdir_p( $backup ) ) {
			throw new \RuntimeException( 'Kiwe could not prepare an atomic update backup.' );
		}

		foreach ( [ 'dsa.php', 'kiwe-incident-guard.php' ] as $file ) {
			if ( is_file( trailingslashit( $mu_root ) . $file ) && ! copy( trailingslashit( $mu_root ) . $file, trailingslashit( $backup ) . $file ) ) {
				throw new \RuntimeException( 'Kiwe could not back up ' . $file . '.' );
			}
		}

		$record = [
			'schema'      => 1,
			'state'       => 'installing',
			'oldVersion'  => DSA_VERSION,
			'newVersion'  => (string) $release['version'],
			'backup'      => wp_normalize_path( $backup ),
			'createdAt'   => time(),
		];
		if ( false === file_put_contents( $transaction, wp_json_encode( $record, JSON_UNESCAPED_SLASHES ), LOCK_EX ) ) {
			throw new \RuntimeException( 'Kiwe could not write the rollback transaction.' );
		}

		$target_dsa = trailingslashit( $mu_root ) . 'dsa';
		try {
			if ( is_dir( $target_dsa ) && ! rename( $target_dsa, trailingslashit( $backup ) . 'dsa' ) ) {
				throw new \RuntimeException( 'Kiwe could not move the current package into rollback storage.' );
			}
			if ( ! rename( trailingslashit( $source ) . 'dsa', $target_dsa ) ) {
				throw new \RuntimeException( 'Kiwe could not activate the staged package directory.' );
			}

			foreach ( [ 'kiwe-incident-guard.php', 'dsa.php' ] as $file ) {
				$temporary = trailingslashit( $mu_root ) . '.' . $file . '.kiwe-new-' . wp_generate_password( 8, false, false );
				$target_file = trailingslashit( $mu_root ) . $file;
				$replaced = copy( trailingslashit( $source ) . $file, $temporary ) && @rename( $temporary, $target_file );
				if ( ! $replaced && is_file( $temporary ) ) {
					@unlink( $target_file );
					$replaced = @rename( $temporary, $target_file );
				}
				if ( ! $replaced ) {
					@unlink( $temporary );
					throw new \RuntimeException( 'Kiwe could not atomically replace ' . $file . '.' );
				}
			}

			$record['state'] = 'awaiting_boot';
			if ( false === file_put_contents( $transaction, wp_json_encode( $record, JSON_UNESCAPED_SLASHES ), LOCK_EX ) ) {
				throw new \RuntimeException( 'Kiwe could not finalize the rollback transaction.' );
			}
			delete_option( 'dsa_package_manifest_proof' );
		} catch ( \Throwable $error ) {
			$this->rollback_now( $mu_root, $backup );
			@unlink( $transaction );
			throw $error;
		}
	}

	private function rollback_now( string $mu_root, string $backup ): void {
		$target = trailingslashit( $mu_root ) . 'dsa';
		$failed = trailingslashit( dirname( $backup ) ) . 'kiwe-failed-' . wp_generate_uuid4();
		if ( ! is_dir( trailingslashit( $backup ) . 'dsa' ) ) {
			$this->remove_tree( $backup );
			return;
		}
		if ( is_dir( $target ) ) {
			@rename( $target, $failed );
		}
		if ( is_dir( trailingslashit( $backup ) . 'dsa' ) ) {
			@rename( trailingslashit( $backup ) . 'dsa', $target );
		}
		foreach ( [ 'dsa.php', 'kiwe-incident-guard.php' ] as $file ) {
			if ( is_file( trailingslashit( $backup ) . $file ) ) {
				@copy( trailingslashit( $backup ) . $file, trailingslashit( $mu_root ) . $file );
			}
		}
		$this->remove_tree( $failed );
		$this->remove_tree( $backup );
	}

	private function acquire_lock(): bool {
		$lock = get_option( self::LOCK_OPTION, [] );
		if ( is_array( $lock ) && ! empty( $lock['time'] ) && ( time() - (int) $lock['time'] ) < self::LOCK_TTL ) {
			return false;
		}
		delete_option( self::LOCK_OPTION );
		return add_option( self::LOCK_OPTION, [ 'time' => time(), 'token' => wp_generate_uuid4() ], '', false );
	}

	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	private function record_error( string $message ): void {
		$state = $this->state();
		$state['last_error']    = sanitize_text_field( $message );
		$state['last_error_at'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	private function remove_tree( string $path ): void {
		$normalized = wp_normalize_path( $path );
		$upgrade    = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'upgrade/kiwe-' );
		if ( ! str_starts_with( $normalized, $upgrade ) || ! is_dir( $path ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			$entry->isDir() && ! $entry->isLink() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
		}
		@rmdir( $path );
	}
}
