<?php

namespace DSA\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Secret_Store {
	private const PREFIX_SODIUM = 'dsa-sodium:';
	private const PREFIX_GCM = 'dsa-gcm:';
	private const PREFIX_SODIUM_V2 = 'dsa-secret:v2:sodium:';
	private const PREFIX_GCM_V2 = 'dsa-secret:v2:gcm:';

	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$key = self::key();
		$key_id = self::key_id( $key );

		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$box = sodium_crypto_secretbox( $value, $nonce, $key );
				return self::PREFIX_SODIUM_V2 . $key_id . ':' . base64_encode( $nonce . $box );
			}

			if ( function_exists( 'openssl_encrypt' ) ) {
				$iv = random_bytes( 12 );
				$tag = '';
				$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

				if ( false !== $cipher ) {
					return self::PREFIX_GCM_V2 . $key_id . ':' . base64_encode( $iv . $tag . $cipher );
				}
			}
		} catch ( \Throwable $error ) {
			return '';
		}

		return '';
	}

	public static function decrypt( string $stored ): string {
		$result = self::decrypt_with_status( $stored );
		return 'ok' === $result['status'] ? $result['value'] : '';
	}

	public static function decrypt_with_status( string $stored ): array {
		if ( '' === $stored ) {
			return [ 'status' => 'empty', 'value' => '', 'keyId' => '', 'legacy' => false ];
		}

		if ( 0 === strpos( $stored, self::PREFIX_SODIUM_V2 ) || 0 === strpos( $stored, self::PREFIX_GCM_V2 ) ) {
			$prefix = 0 === strpos( $stored, self::PREFIX_SODIUM_V2 ) ? self::PREFIX_SODIUM_V2 : self::PREFIX_GCM_V2;
			$algorithm = self::PREFIX_SODIUM_V2 === $prefix ? 'sodium' : 'gcm';
			$parts = explode( ':', substr( $stored, strlen( $prefix ) ), 2 );
			if ( 2 !== count( $parts ) ) return [ 'status' => 'invalid', 'value' => '', 'keyId' => '', 'legacy' => false ];
			[ $key_id, $payload ] = $parts;
			foreach ( self::key_ring() as $candidate ) {
				if ( ! hash_equals( $key_id, self::key_id( $candidate ) ) ) continue;
				$value = self::decrypt_payload( $algorithm, $payload, $candidate );
				return false === $value
					? [ 'status' => 'invalid', 'value' => '', 'keyId' => $key_id, 'legacy' => false ]
					: [ 'status' => 'ok', 'value' => $value, 'keyId' => $key_id, 'legacy' => false ];
			}
			return [ 'status' => 'key_mismatch', 'value' => '', 'keyId' => $key_id, 'legacy' => false ];
		}

		if ( 0 === strpos( $stored, self::PREFIX_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_SODIUM ) ), true );

			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return [ 'status' => 'invalid', 'value' => '', 'keyId' => '', 'legacy' => true ];
			}

			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			foreach ( self::key_ring() as $candidate ) {
				$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $candidate );
				if ( false !== $plain ) return [ 'status' => 'ok', 'value' => (string) $plain, 'keyId' => self::key_id( $candidate ), 'legacy' => true ];
			}
			return [ 'status' => 'key_mismatch', 'value' => '', 'keyId' => '', 'legacy' => true ];
		}

		if ( 0 === strpos( $stored, self::PREFIX_GCM ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_GCM ) ), true );

			if ( false === $raw || strlen( $raw ) < 29 ) return [ 'status' => 'invalid', 'value' => '', 'keyId' => '', 'legacy' => true ];
			foreach ( self::key_ring() as $candidate ) {
				$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $candidate, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
				if ( false !== $plain ) return [ 'status' => 'ok', 'value' => (string) $plain, 'keyId' => self::key_id( $candidate ), 'legacy' => true ];
			}
			return [ 'status' => 'key_mismatch', 'value' => '', 'keyId' => '', 'legacy' => true ];
		}

		return [ 'status' => 'unknown_format', 'value' => '', 'keyId' => '', 'legacy' => false ];
	}

	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX_SODIUM_V2 )
			|| 0 === strpos( $value, self::PREFIX_GCM_V2 )
			|| 0 === strpos( $value, self::PREFIX_SODIUM )
			|| 0 === strpos( $value, self::PREFIX_GCM );
	}

	public static function diagnostics(): array {
		$algorithms = [];
		if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) $algorithms[] = 'sodium';
		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && function_exists( 'openssl_get_cipher_methods' ) && in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true ) ) $algorithms[] = 'aes-256-gcm';
		return [
			'ready'       => ! empty( $algorithms ),
			'version'     => 2,
			'keyId'       => self::key_id( self::key() ),
			'algorithms'  => $algorithms,
			'previousKeys'=> max( 0, count( self::key_ring() ) - 1 ),
		];
	}

	private static function decrypt_payload( string $algorithm, string $payload, string $key ) {
		$raw = base64_decode( $payload, true );
		if ( false === $raw ) return false;
		if ( 'sodium' === $algorithm ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) return false;
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
		}
		if ( ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) < 29 ) return false;
		return openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
	}

	private static function key_id( string $key ): string {
		return substr( hash( 'sha256', $key ), 0, 12 );
	}

	private static function key_ring(): array {
		$keys = [ self::key() ];
		$previous = defined( 'DSA_SECRET_STORE_PREVIOUS_KEYS' ) ? DSA_SECRET_STORE_PREVIOUS_KEYS : [];
		$previous = apply_filters( 'dsa_secret_store_previous_keys', is_array( $previous ) ? $previous : [] );
		foreach ( $previous as $candidate ) {
			$candidate = (string) $candidate;
			if ( '' === $candidate ) continue;
			$decoded = base64_decode( $candidate, true );
			$keys[] = false !== $decoded && 32 === strlen( $decoded ) ? $decoded : hash( 'sha256', $candidate, true );
		}
		return array_values( array_unique( $keys ) );
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|kiwe-secret-store-v1', true );
	}
}
