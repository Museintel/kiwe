<?php

namespace DSA\Site_Graph;

use DSA\AI\Site_Graph_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Short-lived, read-once capability for content-free compiler calibration. */
final class Calibration_Pairing_Service {
	private const TTL = 600;
	private const TRANSIENT_PREFIX = 'dsa_sitegraph_calibration_pair_';

	public function __construct( private Site_Graph_Service $site_graph ) {}

	public function issue(): array {
		$id     = bin2hex( random_bytes( 12 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		$origin = $this->allowed_origin();
		$expiry = time() + self::TTL;
		set_transient(
			self::TRANSIENT_PREFIX . $id,
			[
				'hash'      => $this->secret_hash( $secret ),
				'origin'    => $origin,
				'expiresAt' => $expiry,
			],
			self::TTL
		);

		return [
			'schema'        => 'kiwe.sitegraph-calibration-pair.v1',
			'endpoint'      => rest_url( 'dsa/v1/site-graph/calibration/pair/' . $id ),
			'secret'        => $secret,
			'allowedOrigin' => $origin,
			'expiresAt'     => gmdate( 'c', $expiry ),
			'authority'     => [
				'readOnly'                     => true,
				'singleUse'                    => true,
				'mayMutateWordPress'           => false,
				'contentIncluded'              => false,
				'containsOneTimeCapability'    => true,
				'permanentCredentialsIncluded' => false,
			],
		];
	}

	public function consume( string $id, string $secret, string $origin ) {
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $id ) || ! preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			return new WP_Error( 'dsa_calibration_pair_invalid', __( 'This compiler pairing file is invalid.', 'dsa' ), [ 'status' => 403 ] );
		}
		$state = get_transient( self::TRANSIENT_PREFIX . $id );
		if ( ! is_array( $state ) || time() > (int) ( $state['expiresAt'] ?? 0 ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $id );
			return new WP_Error( 'dsa_calibration_pair_expired', __( 'This compiler pairing has expired or was already used.', 'dsa' ), [ 'status' => 410 ] );
		}
		if ( ! hash_equals( (string) ( $state['origin'] ?? '' ), $origin ) || ! hash_equals( (string) ( $state['hash'] ?? '' ), $this->secret_hash( $secret ) ) ) {
			return new WP_Error( 'dsa_calibration_pair_denied', __( 'The compiler pairing proof did not match.', 'dsa' ), [ 'status' => 403 ] );
		}

		// Consume before producing the response: replay remains impossible if later work fails.
		delete_transient( self::TRANSIENT_PREFIX . $id );
		return $this->site_graph->calibration_profile();
	}

	public function allowed_origin(): string {
		$origin = (string) apply_filters( 'dsa_sitegraph_compiler_origin', 'https://seam-compiler-native.munaf-m-patni.chatgpt.site' );
		return untrailingslashit( esc_url_raw( $origin ) );
	}

	private function secret_hash( string $secret ): string {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}
}
