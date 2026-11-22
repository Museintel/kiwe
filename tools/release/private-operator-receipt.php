<?php
/** Private release-tool output. Not a WordPress runtime component. */
declare(strict_types=1);

final class Kiwe_Private_Operator_Receipt {

	/**
	 * Call after bootstrap with configuration held in an immutable constant.
	 * Existing, non-identical receipts are never overwritten.
	 */
	public static function write( string $directory, string $name, string $bytes ): string {
		$directory = str_replace( '\\', '/', $directory );
		$resolved  = realpath( $directory );
		if ( false === $resolved || str_replace( '\\', '/', $resolved ) !== $directory || is_link( $directory ) || ! is_dir( $directory ) ) {
			throw new RuntimeException( 'Exact existing private receipt directory required.' );
		}
		if ( preg_match( '~(?:^|/)(?:public_html|htdocs|wwwroot)(?:/|$)~i', $directory ) || ! preg_match( '/^[a-z][a-z0-9-]{1,100}\.json$/D', $name ) ) {
			throw new RuntimeException( 'Private receipt path required.' );
		}
		$path = $directory . '/' . $name;
		if ( is_link( $path ) ) {
			throw new RuntimeException( 'Receipt symlink refused.' );
		}
		if ( file_exists( $path ) ) {
			if ( ! is_file( $path ) || ! hash_equals( hash( 'sha256', $bytes ), hash_file( 'sha256', $path ) ) ) {
				throw new RuntimeException( 'Preserve existing receipt.' );
			}
			return $path;
		}
		$stream = fopen( $path, 'x+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Exclusive private receipt creation failed.' );
		}
		try {
			if ( ! chmod( $path, 0600 ) || ! flock( $stream, LOCK_EX ) || fwrite( $stream, $bytes ) !== strlen( $bytes ) || ! fflush( $stream ) ) {
				throw new RuntimeException( 'Private receipt write failed.' );
			}
		} finally {
			fclose( $stream );
		}
		if ( ! hash_equals( hash( 'sha256', $bytes ), hash_file( 'sha256', $path ) ) ) {
			throw new RuntimeException( 'Private receipt readback failed.' );
		}
		return $path;
	}
}
