<?php
/**
 * Plugin Name: Kiwe
 * Description: MU loader for the Kiwe Surface and Auth plugin.
 * Version: 8.0.0-rc.436
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

	define( 'KIWE_MU_LOADER_VERSION', '8.0.0-rc.436' );

if ( ! function_exists( 'kiwe_mu_debug_log' ) ) {
	function kiwe_mu_debug_log( $message, $context = [] ) {
		// Respect the site's explicit debug policy even during early MU recovery.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) return;
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

// Deliberately self-contained: the package may be absent during early rollback.
if ( ! function_exists( 'kiwe_mu_private_release_parent' ) ) {
	function kiwe_mu_private_release_parent() {
		$root = realpath( ABSPATH );
		$document = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
		if ( ! $document && $root && in_array( basename( $root ), [ 'public_html','htdocs','www','html' ], true ) ) $document = $root;
		if ( ! $root || ! $document || dirname( $document ) === $document ) return '';
		$base = dirname( $document ) . '/kiwe-private-releases';
		$path = $base . '/site-' . substr( hash( 'sha256', wp_normalize_path( $root ) ), 0, 16 );
		foreach ( [ $root,$document ] as $public ) if ( str_starts_with( wp_normalize_path( $path ) . '/', trailingslashit( wp_normalize_path( $public ) ) ) ) return '';
		return ! is_link( $base ) && ! is_link( $path ) && is_dir( $path ) && wp_normalize_path( (string) realpath( $path ) ) === wp_normalize_path( $path ) ? $path : '';
	}
}

if ( ! function_exists( 'kiwe_mu_remove_update_tree' ) ) {
	function kiwe_mu_remove_update_tree( $path ) {
		$path    = wp_normalize_path( (string) $path );
		$allowed = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'upgrade/kiwe-' );
		$parent = realpath( dirname( $path ) );
		$upgrade = realpath( trailingslashit( WP_CONTENT_DIR ) . 'upgrade' );
		$private = kiwe_mu_private_release_parent();
		$legacy = str_starts_with( $path, $allowed ) && false !== $upgrade && $parent === $upgrade;
		$durable = $private && $parent === realpath( $private ) && preg_match( '/^kiwe-(?:backup|failed)-[a-f0-9-]{36}$/D', basename( $path ) );
		if ( ( ! $legacy && ! $durable ) || ! is_dir( $path ) || is_link( $path ) || false === $parent ) {
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

if ( ! function_exists( 'kiwe_mu_refresh_php_runtime' ) ) {
	function kiwe_mu_refresh_php_runtime( $root ) {
		$root = wp_normalize_path( (string) $root );
		$mu_root = wp_normalize_path( __DIR__ );
		if ( $root !== $mu_root || ! is_dir( $root . '/dsa' ) ) return false;
		$files = [ $root . '/dsa.php', $root . '/kiwe-incident-guard.php' ];
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/dsa', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) if ( $entry->isFile() && ! $entry->isLink() && 'php' === strtolower( $entry->getExtension() ) ) $files[] = wp_normalize_path( $entry->getPathname() );
		$time = time();
		foreach ( $files as $file ) {
			if ( ! is_file( $file ) || is_link( $file ) || ! @touch( $file, $time ) ) return false;
			if ( function_exists( 'opcache_invalidate' ) ) @opcache_invalidate( $file, true );
		}
		return true;
	}
}

if ( ! function_exists( 'kiwe_mu_rollback_update' ) ) {
	function kiwe_mu_rollback_update( $record ) {
		$backup  = wp_normalize_path( (string) ( $record['backup'] ?? '' ) );
		$allowed = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'upgrade/kiwe-backup-' );
		$mu_root = wp_normalize_path( __DIR__ );
		$private = kiwe_mu_private_release_parent();
		$parent = realpath( dirname( $backup ) );
		$legacy = str_starts_with( $backup, $allowed ) && $parent === realpath( trailingslashit( WP_CONTENT_DIR ) . 'upgrade' );
		$durable = $private && $parent === realpath( $private ) && preg_match( '/^kiwe-backup-[a-f0-9-]{36}$/D', basename( $backup ) );
		if ( ( ! $legacy && ! $durable ) || str_contains( $backup, '/../' ) || ! is_dir( $backup ) || is_link( $backup ) ) {
			return false;
		}

		$target = $mu_root . '/dsa';
		$failed = wp_normalize_path( dirname( $backup ) . '/kiwe-failed-' . wp_generate_uuid4() );
		$transaction = $mu_root . '/kiwe-update-transaction.json';
		if ( is_link( $target ) || is_link( $backup . '/dsa' ) ) return false;
		// Restore and verify the entry files before moving the only package backup.
		// A failed copy must leave the complete backup and transaction available.
		foreach ( [ 'dsa.php', 'kiwe-incident-guard.php' ] as $file ) {
			$source = $backup . '/' . $file;
			$destination = $mu_root . '/' . $file;
			if ( ! is_file( $source ) || is_link( $source ) || is_link( $destination ) || is_dir( $destination ) ) return false;
			if ( ! @copy( $source, $destination ) || ! hash_equals( hash_file( 'sha256', $source ) ?: '', hash_file( 'sha256', $destination ) ?: 'failed' ) ) return false;
		}
		if ( ! is_dir( $backup . '/dsa' ) ) {
			// The installer may have stopped before moving the old package, or a
			// previous restore may have completed before transaction retirement.
			$old_version = preg_quote( (string) ( $record['oldVersion'] ?? '' ), '/' );
			if ( '' === $old_version || ! is_file( $target . '/dsa.php' ) || ! preg_match( "/define\\(\\s*'DSA_VERSION'\\s*,\\s*'{$old_version}'\\s*\\)/", (string) file_get_contents( $target . '/dsa.php' ) ) ) return false;
			if ( ! kiwe_mu_refresh_php_runtime( $mu_root ) ) return false;
			if ( is_file( $transaction ) && ! @unlink( $transaction ) ) return false;
			kiwe_mu_remove_update_tree( $backup );
			return true;
		}
		if ( file_exists( $target ) && ( ! is_dir( $target ) || ! @rename( $target, $failed ) ) ) {
			return false;
		}
		if ( is_dir( $backup . '/dsa' ) && ! @rename( $backup . '/dsa', $target ) ) {
			return false;
		}
		if ( ! kiwe_mu_refresh_php_runtime( $mu_root ) ) return false;
		if ( is_file( $transaction ) && ! @unlink( $transaction ) ) return false;
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
		$transaction = kiwe_mu_update_transaction();
		if ( 'awaiting_boot' === ( $transaction['state'] ?? '' ) ) kiwe_mu_rollback_update( $transaction );
	}
);

$kiwe_update_transaction = kiwe_mu_update_transaction();
if ( 'installing' === (string) ( $kiwe_update_transaction['state'] ?? '' ) ) {
	$kiwe_update_lock = get_option( 'kiwe_update_lock', [] );
	if ( is_array( $kiwe_update_lock ) && ! empty( $kiwe_update_lock['time'] ) && time() - (int) $kiwe_update_lock['time'] < 900 ) {
		// Another request owns the swap. Do not undo it or load a mixed pair.
		return;
	}
	if ( ! kiwe_mu_rollback_update( $kiwe_update_transaction ) ) {
		kiwe_mu_admin_notice( 'Kiwe update recovery is incomplete. The rollback files have been retained; ask your site manager to restore the complete previous package.' );
		return;
	}
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
			$kiwe_restored = kiwe_mu_rollback_update( $kiwe_update_transaction );
			kiwe_mu_admin_notice( $kiwe_restored ? 'Kiwe rolled back the failed update. Reload once to use the previous verified build.' : 'Kiwe recovery could not finish. The previous files have been retained for your site manager.' );
		}
		return;
	}

	if ( 'awaiting_boot' === (string) ( $kiwe_update_transaction['state'] ?? '' ) ) {
		// Service registration runs after this loader returns. Keep recovery files
		// until WordPress has reached wp_loaded and Kiwe's services completed.
		add_action( 'wp_loaded', static function () use ( $kiwe_update_transaction ) {
			if ( ! defined( 'DSA_VERSION' ) || ! defined( 'KIWE_PACKAGE_BOOT_COMPLETE' ) || true !== KIWE_PACKAGE_BOOT_COMPLETE || ! hash_equals( (string) ( $kiwe_update_transaction['newVersion'] ?? '' ), (string) DSA_VERSION ) ) return;
			$current = kiwe_mu_update_transaction();
			if ( $current !== $kiwe_update_transaction || ! @unlink( __DIR__ . '/kiwe-update-transaction.json' ) ) return;
			kiwe_mu_remove_update_tree( (string) ( $current['backup'] ?? '' ) );
			kiwe_mu_debug_log( 'Verified Kiwe update boot completed.', [ 'version' => DSA_VERSION ] );
		}, PHP_INT_MAX );
	}
} else {
	kiwe_mu_admin_notice( 'Kiwe loader found no wp-content/mu-plugins/dsa/dsa.php package file. Upload the complete dsa folder to enable Kiwe.' );
}
