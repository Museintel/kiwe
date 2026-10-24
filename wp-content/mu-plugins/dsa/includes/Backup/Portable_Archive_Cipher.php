<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Portable, bounded frames. The capture owner must prove a complete, consistent set. */
final class Portable_Archive_Cipher {
	public const SCHEMA = 'kiwe.full-site-frames.v1';
	public const CHUNK_BYTES = 524288;
	private const MAGIC = "KIWEFSP1";
	private const PREFIX_BYTES = 29;
	private const OVERHEAD = 45;
	private string $key;
	private string $binding;
	private array $header;

	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function crypto(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) self::fail( 'This server needs OpenSSL AES-256-GCM for portable backups.' );
	}
	private static function exact_keys( array $value, array $keys ): bool { return count( $value ) === count( $keys ) && ! array_diff( $keys, array_keys( $value ) ); }
	/** Contains no site URL, filename, login, account state or recovery key. */
	public static function new_header(): array {
		self::crypto();
		return [ 'schema'=>self::SCHEMA, 'archiveId'=>bin2hex( random_bytes( 16 ) ), 'crypto'=>[ 'method'=>'AES-256-GCM', 'kdf'=>'PBKDF2-SHA256', 'iterations'=>Archive_Cipher::ITERATIONS, 'salt'=>base64_encode( random_bytes( 16 ) ) ], 'chunkBytes'=>self::CHUNK_BYTES ];
	}
	/** Cheap request validation; key derivation belongs to the background capture. */
	public static function validate_passphrase( #[\SensitiveParameter] string $phrase ): void {
		if ( strlen( $phrase ) < 16 || strlen( $phrase ) > 1024 ) self::fail( 'Use a recovery passphrase of at least 16 characters.' );
	}
	public function __construct( array $header, #[\SensitiveParameter] string $phrase ) {
		self::crypto();
		self::validate_passphrase( $phrase );
		if ( ! self::exact_keys( $header, [ 'schema','archiveId','crypto','chunkBytes' ] ) || ( $header['schema'] ?? null ) !== self::SCHEMA || ! is_string( $header['archiveId'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $header['archiveId'] ) || ( $header['chunkBytes'] ?? null ) !== self::CHUNK_BYTES || ! is_array( $header['crypto'] ?? null ) ) self::fail( 'The portable backup header is invalid or unsupported.' );
		$c = $header['crypto'];
		if ( ! self::exact_keys( $c, [ 'method','kdf','iterations','salt' ] ) || ( $c['method'] ?? null ) !== 'AES-256-GCM' || ( $c['kdf'] ?? null ) !== 'PBKDF2-SHA256' || ( $c['iterations'] ?? null ) !== Archive_Cipher::ITERATIONS || ! is_string( $c['salt'] ?? null ) ) self::fail( 'The portable backup encryption format is invalid or unsupported.' );
		$salt = base64_decode( $c['salt'], true );
		if ( ! is_string( $salt ) || strlen( $salt ) !== 16 || base64_encode( $salt ) !== $c['salt'] ) self::fail( 'The portable backup salt is damaged.' );
		// Canonicalize a parsed JSON object; JSON property order is not an authority.
		$this->header = [ 'schema'=>self::SCHEMA, 'archiveId'=>$header['archiveId'], 'crypto'=>[ 'method'=>'AES-256-GCM','kdf'=>'PBKDF2-SHA256','iterations'=>Archive_Cipher::ITERATIONS,'salt'=>$c['salt'] ], 'chunkBytes'=>self::CHUNK_BYTES ];
		$this->binding = hash( 'sha256', json_encode( $this->header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), true );
		// One derivation per active step, not one costly derivation per frame.
		$this->key = hash_pbkdf2( 'sha256', $phrase, $salt, Archive_Cipher::ITERATIONS, 32, true );
	}
	public function header(): array { return $this->header; }
	public function __debugInfo(): array { return [ 'schema'=>self::SCHEMA, 'archiveId'=>$this->header['archiveId'], 'key'=>'[private]' ]; }
	public function __serialize(): array { self::fail( 'A portable backup encryption context cannot be saved. Re-enter the recovery passphrase.' ); }
	public function __unserialize( array $data ): void { self::fail( 'A portable backup encryption context cannot be loaded.' ); }
	public function __clone(): void { self::fail( 'A portable backup encryption context cannot be copied.' ); }
	private static function position( int $sequence, string $purpose ): void {
		if ( $sequence < 0 || $sequence > 2147483647 || ! in_array( $purpose, [ 'index','manifest','data' ], true ) || ( 'index' === $purpose ) !== ( 0 === $sequence ) ) self::fail( 'The portable backup frame position is invalid.' );
	}
	private function aad( string $prefix, string $purpose ): string { return self::SCHEMA . '|' . $purpose . '|' . $this->binding . $prefix; }
	public function seal( string $plain, int $sequence, string $purpose ): string {
		self::position( $sequence, $purpose );
		if ( strlen( $plain ) > self::CHUNK_BYTES ) self::fail( 'The portable backup frame exceeds its limit.' );
		$body = $plain; $codec = 0;
		if ( function_exists( 'gzdeflate' ) && strlen( $plain ) >= 256 ) {
			$compressed = gzdeflate( $plain, 1 );
			if ( is_string( $compressed ) && strlen( $compressed ) < strlen( $body ) ) { $body = $compressed; $codec = 1; }
		}
		$iv = random_bytes( 12 ); $tag = '';
		$prefix = self::MAGIC . pack( 'NN', $sequence, strlen( $plain ) ) . chr( $codec ) . $iv;
		$cipher = openssl_encrypt( $body, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad( $prefix, $purpose ), 16 );
		if ( ! is_string( $cipher ) || strlen( $tag ) !== 16 ) self::fail( 'The portable backup frame could not be encrypted.' );
		return $prefix . $tag . $cipher;
	}
	public function open( string $frame, int $sequence, string $purpose ): string {
		self::position( $sequence, $purpose );
		if ( strlen( $frame ) < self::OVERHEAD || strlen( $frame ) > self::OVERHEAD + self::CHUNK_BYTES || substr( $frame, 0, 8 ) !== self::MAGIC ) self::fail( 'The portable backup frame is damaged or too large.' );
		$fields = unpack( 'Nsequence/Nbytes', substr( $frame, 8, 8 ) ); $codec = ord( $frame[16] );
		if ( $fields['sequence'] !== $sequence || $fields['bytes'] > self::CHUNK_BYTES || ! in_array( $codec, [ 0,1 ], true ) ) self::fail( 'The portable backup frame position or size is invalid.' );
		$iv = substr( $frame, 17, 12 ); $tag = substr( $frame, self::PREFIX_BYTES, 16 );
		$plain = openssl_decrypt( substr( $frame, self::OVERHEAD ), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad( substr( $frame, 0, self::PREFIX_BYTES ), $purpose ) );
		if ( ! is_string( $plain ) ) self::fail( 'The passphrase is wrong or the portable backup is damaged.' );
		if ( 1 === $codec ) {
			if ( ! function_exists( 'gzinflate' ) ) self::fail( 'This backup needs the zlib extension.' );
			$plain = @gzinflate( $plain, self::CHUNK_BYTES );
		}
		if ( ! is_string( $plain ) || strlen( $plain ) !== $fields['bytes'] ) self::fail( 'The portable backup frame content or size is invalid.' );
		return $plain;
	}
}
