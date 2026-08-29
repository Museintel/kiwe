<?php

namespace DSA\Site_Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Target-side, one-request remote manifest inspection. Credentials are never stored. */
final class Staging_Seed_Connection_Service {
	public function inspect( string $source_url, string $username, string $application_password ): array {
		$source_url = $this->source_origin( $source_url );
		$username   = sanitize_text_field( $username );
		$password   = preg_replace( '/\s+/', '', $application_password );
		if ( '' === $source_url || ! str_starts_with( $source_url, 'https://' ) ) {
			throw new \RuntimeException( 'The source WordPress site must use an HTTPS URL.' );
		}
		if ( '' === $username || '' === $password ) {
			throw new \RuntimeException( 'A source administrator username and WordPress Application Password are required.' );
		}
		if ( hash_equals( strtolower( untrailingslashit( home_url( '/' ) ) ), $source_url ) ) {
			throw new \RuntimeException( 'The source and destination cannot be the same site.' );
		}

		$url      = $source_url . '/wp-json/dsa/v1/site-graph/staging-seed/manifest';
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'     => 25,
				'redirection' => 2,
				'headers'     => [
					'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Accept'        => 'application/json',
					'User-Agent'    => 'Kiwe-Staging-Seed/' . ( defined( 'DSA_VERSION' ) ? DSA_VERSION : 'unknown' ),
				],
			]
		);
		unset( $password, $application_password );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Kiwe could not reach the source SiteGraph: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $status ) {
			throw new \RuntimeException( 401 === $status || 403 === $status ? 'The source rejected the Application Password or administrator does not have permission.' : 'The source SiteGraph returned HTTP ' . $status . '.' );
		}
		if ( strlen( $body ) > 2 * MB_IN_BYTES ) {
			throw new \RuntimeException( 'The source manifest exceeded the safe response limit.' );
		}
		$manifest = json_decode( $body, true );
		if ( ! is_array( $manifest ) ) {
			throw new \RuntimeException( 'The source returned an invalid SiteGraph staging manifest.' );
		}

		$preflight = ( new Staging_Seed_Preflight_Service() )->evaluate( $manifest );
		$ledger    = ( new Staging_Seed_Ledger_Service() )->record_preflight( $manifest, $preflight );

		return [ 'manifest' => $manifest, 'preflight' => $preflight, 'ledger' => $ledger ];
	}

	private function source_origin( string $url ): string {
		$url = trim( $url );
		$url = preg_replace( '#/(wp-admin|wp-json)(/.*)?$#i', '', $url );
		$url = esc_url_raw( $url, [ 'https' ] );
		return strtolower( untrailingslashit( (string) $url ) );
	}
}
