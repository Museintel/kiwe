<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** The shared, versioned encryption primitive; each archive owns its data validation. */
final class Archive_Cipher {
	public const ITERATIONS = 310000;
	public static function site_hash(): string { return hash( 'sha256', strtolower( untrailingslashit( home_url( '/' ) ) ) . '|' . get_current_blog_id() ); }
	private static function ready( string $phrase ): void {
		if ( strlen( $phrase ) < 16 || strlen( $phrase ) > 1024 ) throw new \RuntimeException( 'Use a recovery passphrase of at least 16 characters.' );
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) throw new \RuntimeException( 'This server needs OpenSSL AES-256-GCM for encrypted archives.' );
	}
	public static function seal( array $payload, string $schema, string $phrase, array $header, int $limit ): string {
		self::ready( $phrase );
		$payload['schema'] = $schema; $payload['siteHash'] = self::site_hash();
		$plain = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $plain ) || strlen( $plain ) > $limit / 2 ) throw new \RuntimeException( 'This archive exceeds the small-archive limit. Nothing has been exported.' );
		$salt = random_bytes( 16 ); $iv = random_bytes( 12 ); $tag = '';
		$key = hash_pbkdf2( 'sha256', $phrase, $salt, self::ITERATIONS, 32, true );
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $schema . '|' . self::site_hash(), 16 );
		if ( ! is_string( $cipher ) || strlen( $tag ) !== 16 ) throw new \RuntimeException( 'The archive could not be encrypted.' );
		$bytes = wp_json_encode( [ ...$header, 'schema'=>$schema, 'siteHash'=>self::site_hash(), 'crypto'=>[ 'method'=>'AES-256-GCM','kdf'=>'PBKDF2-SHA256','iterations'=>self::ITERATIONS,'salt'=>base64_encode( $salt ),'iv'=>base64_encode( $iv ),'tag'=>base64_encode( $tag ) ], 'data'=>base64_encode( $cipher ) ], JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $bytes ) || strlen( $bytes ) > $limit ) throw new \RuntimeException( 'The encrypted archive exceeds the small-archive limit.' );
		return $bytes;
	}
	public static function open( string $bytes, string $schema, string $phrase, int $limit ): array {
		self::ready( $phrase );
		if ( strlen( $bytes ) > $limit ) throw new \RuntimeException( 'This archive is too large for this import.' );
		$envelope = json_decode( $bytes, true, 32 );
		if ( ! is_array( $envelope ) || ( $envelope['schema'] ?? '' ) !== $schema || ! is_string( $envelope['siteHash'] ?? null ) || ! hash_equals( self::site_hash(), $envelope['siteHash'] ) ) throw new \RuntimeException( 'This is not an archive for this website.' );
		$crypto = $envelope['crypto'] ?? [];
		if ( ! is_array( $crypto ) || ( $crypto['method'] ?? '' ) !== 'AES-256-GCM' || ( $crypto['kdf'] ?? '' ) !== 'PBKDF2-SHA256' || ( $crypto['iterations'] ?? 0 ) !== self::ITERATIONS ) throw new \RuntimeException( 'This archive uses an unsupported encryption format.' );
		$values = [];
		foreach ( [ 'salt'=>16,'iv'=>12,'tag'=>16 ] as $key=>$length ) {
			$values[$key] = is_string( $crypto[$key] ?? null ) ? base64_decode( $crypto[$key], true ) : false;
			if ( ! is_string( $values[$key] ) || strlen( $values[$key] ) !== $length ) throw new \RuntimeException( 'The archive is damaged.' );
		}
		$cipher = is_string( $envelope['data'] ?? null ) ? base64_decode( $envelope['data'], true ) : false;
		if ( ! is_string( $cipher ) ) throw new \RuntimeException( 'The archive is damaged.' );
		$key = hash_pbkdf2( 'sha256', $phrase, $values['salt'], self::ITERATIONS, 32, true );
		$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $values['iv'], $values['tag'], $schema . '|' . self::site_hash() );
		$payload = is_string( $plain ) ? json_decode( $plain, true, 32 ) : null;
		if ( ! is_array( $payload ) || ( $payload['schema'] ?? '' ) !== $schema || ! is_string( $payload['siteHash'] ?? null ) || ! hash_equals( self::site_hash(), $payload['siteHash'] ) ) throw new \RuntimeException( 'The passphrase is wrong or the archive is damaged.' );
		return $payload;
	}
}
