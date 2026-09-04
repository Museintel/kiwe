<?php
/**
 * Plugin Name: Kiwe
 * Description: MU loader for the Kiwe Surface and Auth plugin.
 * Version: 8.0.0-rc.53
 * Requires PHP: 8.2
 * Author: Kiwelauch
 *
 * MU loader for Kiwe.
 *
 * WordPress only auto-loads PHP files directly inside mu-plugins, so this
 * file loads the actual plugin package from the dsa/ directory.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KIWE_MU_LOADER_VERSION', '8.0.0-rc.53' );

if ( ! function_exists( 'kiwe_mu_debug_log' ) ) {
	function kiwe_mu_debug_log( $message, $context = [] ) {
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] [Kiwe MU] ' . $message;

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		$line .= PHP_EOL;
		$file  = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : __DIR__ . '/../debug.log';

		if ( is_string( $file ) ) {
			@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
		}

		error_log( trim( $line ) );
	}
}

if ( ! function_exists( 'kiwe_mu_admin_notice' ) ) {
	function kiwe_mu_admin_notice( $message ) {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () use ( $message ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			}
		);
	}
}

if ( ! function_exists( 'kiwe_mu_update_transaction' ) ) {
	function kiwe_mu_update_transaction() {
		$file = __DIR__ . '/kiwe-update-transaction.json';
		if ( ! is_readable( $file ) ) {
			return [];
		}
		$record = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $record ) ? $record : [];
	}
}

if ( ! function_exists( 'kiwe_mu_remove_update_tree' ) ) {
	function kiwe_mu_remove_update_tree( $path ) {
		$path    = wp_normalize_path( (string) $path );
		$allowed = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'upgrade/kiwe-' );
		if ( ! str_starts_with( $path, $allowed ) || ! is_dir( $path ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			$entry->isDir() && ! $entry->isLink() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
		}
		@rmdir( $path );
	}
}

if ( ! function_exists( 'kiwe_mu_rollback_update' ) ) {
	function kiwe_mu_rollback_update( $record ) {
		$backup  = wp_normalize_path( (string) ( $record['backup'] ?? '' ) );
		$allowed = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'upgrade/kiwe-backup-' );
		$mu_root = wp_normalize_path( __DIR__ );
		if ( ! str_starts_with( $backup, $allowed ) || ! is_dir( $backup ) ) {
			return false;
		}

		$target = $mu_root . '/dsa';
		$failed = wp_normalize_path( dirname( $backup ) . '/kiwe-failed-' . wp_generate_uuid4() );
		if ( ! is_dir( $backup . '/dsa' ) ) {
			@unlink( $mu_root . '/kiwe-update-transaction.json' );
			kiwe_mu_remove_update_tree( $backup );
			return true;
		}
		if ( is_dir( $target ) ) {
			@rename( $target, $failed );
		}
		if ( is_dir( $backup . '/dsa' ) && ! @rename( $backup . '/dsa', $target ) ) {
			return false;
		}
		foreach ( [ 'dsa.php', 'kiwe-incident-guard.php' ] as $file ) {
			if ( is_file( $backup . '/' . $file ) ) {
				@copy( $backup . '/' . $file, $mu_root . '/' . $file );
			}
		}
		@unlink( $mu_root . '/kiwe-update-transaction.json' );
		kiwe_mu_remove_update_tree( $failed );
		kiwe_mu_remove_update_tree( $backup );
		return true;
	}
}

register_shutdown_function(
	static function () {
		$error = error_get_last();

		if ( ! is_array( $error ) ) {
			return;
		}

		if ( ! in_array( (int) $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ], true ) ) {
			return;
		}

		$file = isset( $error['file'] ) ? (string) $error['file'] : '';

		if ( false === strpos( wp_normalize_path( $file ), '/mu-plugins/dsa' ) ) {
			return;
		}

		kiwe_mu_debug_log(
			'Fatal error during Kiwe boot',
			[
				'type'    => (int) $error['type'],
				'message' => isset( $error['message'] ) ? (string) $error['message'] : '',
				'file'    => $file,
				'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
			]
		);

		if ( function_exists( 'update_option' ) ) {
			update_option(
				'kiwe_mu_last_fatal',
				[
					'time'    => time(),
					'type'    => (int) $error['type'],
					'message' => isset( $error['message'] ) ? sanitize_text_field( (string) $error['message'] ) : '',
				],
				false
			);
		}
	}
);

$kiwe_update_transaction = kiwe_mu_update_transaction();
if ( 'installing' === (string) ( $kiwe_update_transaction['state'] ?? '' ) ) {
	kiwe_mu_rollback_update( $kiwe_update_transaction );
	$kiwe_update_transaction = [];
	kiwe_mu_debug_log( 'Recovered an interrupted Kiwe update before boot.' );
}

$dsa_bootstrap = __DIR__ . '/dsa/dsa.php';

if ( file_exists( $dsa_bootstrap ) ) {
	try {
		require_once $dsa_bootstrap;
	} catch ( Throwable $e ) {
		$message = 'Kiwe disabled itself for this request because the MU plugin package is incomplete or mismatched. Delete wp-content/mu-plugins/dsa, upload a fresh complete dsa folder, then reload.';

		kiwe_mu_debug_log(
			'Caught exception during Kiwe boot',
			[
				'class'   => get_class( $e ),
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			]
		);
		if ( function_exists( 'update_option' ) ) {
			update_option(
				'kiwe_mu_last_fatal',
				[
					'time'    => time(),
					'type'    => E_ERROR,
					'message' => sanitize_text_field( $e->getMessage() ),
				],
				false
			);
		}

		kiwe_mu_admin_notice( $message . ' Last error: ' . $e->getMessage() );
		if ( 'awaiting_boot' === (string) ( $kiwe_update_transaction['state'] ?? '' ) ) {
			kiwe_mu_rollback_update( $kiwe_update_transaction );
			kiwe_mu_admin_notice( 'Kiwe rolled back the failed update. Reload once to use the previous verified build.' );
		}
		return;
	}

	if (
		'awaiting_boot' === (string) ( $kiwe_update_transaction['state'] ?? '' )
		&& defined( 'DSA_VERSION' )
		&& hash_equals( (string) ( $kiwe_update_transaction['newVersion'] ?? '' ), (string) DSA_VERSION )
	) {
		@unlink( __DIR__ . '/kiwe-update-transaction.json' );
		kiwe_mu_remove_update_tree( (string) ( $kiwe_update_transaction['backup'] ?? '' ) );
		kiwe_mu_debug_log( 'Verified Kiwe update boot completed.', [ 'version' => DSA_VERSION ] );
	}
} else {
	kiwe_mu_admin_notice( 'Kiwe loader found no wp-content/mu-plugins/dsa/dsa.php package file. Upload the complete dsa folder to enable Kiwe.' );
}
