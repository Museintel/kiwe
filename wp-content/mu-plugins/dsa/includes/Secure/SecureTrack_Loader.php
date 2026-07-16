<?php

namespace DSA\Secure;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecureTrack_Loader {
	public function register(): void {
		add_action( 'plugins_loaded', [ $this, 'boot' ], 20 );
	}

	public function boot(): void {
		$settings = new Settings();
		$secure   = $settings->get( 'secure', [] );
		$enabled  = ! empty( $secure['enabled'] );

		if ( defined( 'DSA_ENABLE_BUNDLED_SECURETRACK' ) ) {
			$enabled = $enabled && DSA_ENABLE_BUNDLED_SECURETRACK;
		}

		$enabled = (bool) apply_filters( 'dsa_enable_bundled_securetrack', $enabled );

		if ( ! $enabled ) {
			$this->sync_securetrack_settings(
				[
					'enabled'             => false,
					'auto_logout_enabled' => false,
					'auto_logout_roles'   => [],
				]
			);
			return;
		}

		if ( PHP_VERSION_ID < 80200 ) {
			$this->register_boot_notice(
				__( 'Kiwe Secure did not load SecureTrack because the server PHP version is below 8.2. Upgrade PHP before enabling the bundled security engine.', 'dsa' ),
				'error'
			);
			return;
		}

		if ( ! function_exists( 'wp_salt' ) ) {
			$this->register_boot_notice(
				__( 'Kiwe Secure is waiting for WordPress authentication salts before loading SecureTrack. Try again after WordPress finishes loading plugins.', 'dsa' ),
				'warning'
			);
			return;
		}

		if ( defined( 'STP_VER' ) || function_exists( 'stp_cfg' ) || function_exists( 'stp_t' ) ) {
			$this->register_collision_notice();
			return;
		}

		$core = DSA_DIR . 'includes/Secure/securetrack-core.php';
		try {
			if ( ! defined( 'DSA_OWNS_SECURETRACK_ADMIN' ) ) {
				define( 'DSA_OWNS_SECURETRACK_ADMIN', true );
			}

			if ( file_exists( $core ) ) {
				require_once $core;
			}

			$this->sync_securetrack_settings( is_array( $secure ) ? $secure : [] );

			if ( function_exists( 'stp_maybe_install' ) ) {
				stp_maybe_install();
			}
		} catch ( \Throwable $e ) {
			$this->log_boot_error( $e, $core );
			$this->register_boot_notice(
				__( 'Kiwe Secure could not start SecureTrack, so the site was allowed to keep loading. Check the PHP debug log and Kiwe > Secure before re-enabling enforcement.', 'dsa' ),
				'error'
			);
			return;
		}

		do_action( 'dsa_securetrack_loaded' );
	}

	private function sync_securetrack_settings( array $secure ): void {
		SecureTrack_Settings_Policy::sync_from_kiwe( $secure );
	}

	private function log_boot_error( \Throwable $e, string $core ): void {
		$context = [
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
			'core'    => $core,
		];

		if ( function_exists( 'kiwe_mu_debug_log' ) ) {
			kiwe_mu_debug_log( 'SecureTrack boot failed', $context );
			return;
		}

		error_log( '[Kiwe MU] SecureTrack boot failed ' . wp_json_encode( $context ) );
	}

	private function register_collision_notice(): void {
		$this->register_boot_notice(
			__( 'Kiwe Secure did not load its bundled SecureTrack engine because another SecureTrack/PhoneKey security snippet is already active. Deactivate the older snippet before using the bundled Kiwe Secure module.', 'dsa' ),
			'warning'
		);
	}

	private function register_boot_notice( string $message, string $type = 'warning' ): void {
		add_action(
			'admin_notices',
			static function () use ( $message, $type ): void {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}

				$type = in_array( $type, [ 'error', 'warning', 'success', 'info' ], true ) ? $type : 'warning';
				echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
			}
		);
	}
}
