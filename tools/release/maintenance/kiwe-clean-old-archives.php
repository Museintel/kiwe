<?php
/**
 * One-shot Kiwe release-archive cleanup for the MU-plugin root.
 *
 * This file intentionally names every approved deletion target. It removes
 * itself after the first WordPress request so no maintenance surface remains.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kiwe_old_archives = [
	'kiwe-8.0.0-rc.9-mu-plugins.zip',
	'kiwe-8.0.0-rc.10-mu-plugins.zip',
	'kiwe-8.0.0-rc.11-mu-plugins.zip',
	'kiwe-8.0.0-rc.12-mu-plugins.zip',
	'kiwe-8.0.0-rc.13-mu-plugins.zip',
	'kiwe-mu-plugins-8.0.0-rc.26-20260901-005846.zip',
	'kiwe-mu-plugins-8.0.0-rc.27-20260901-013005.zip',
	'kiwe-mu-plugins-8.0.0-rc.31-20260901-133346.zip',
];

foreach ( $kiwe_old_archives as $kiwe_old_archive ) {
	$kiwe_old_archive_path = __DIR__ . DIRECTORY_SEPARATOR . $kiwe_old_archive;
	if ( is_file( $kiwe_old_archive_path ) && ! is_link( $kiwe_old_archive_path ) ) {
		@unlink( $kiwe_old_archive_path );
	}
}

@unlink( __FILE__ );
