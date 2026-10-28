<?php
/** Native-test EC/CBOR credentials, generated in memory; no real authenticator or account. */
function kiwe_pt2_cbor_head( int $major, int $value ): string {
	if ( $value < 24 ) return chr( ( $major << 5 ) | $value );
	if ( $value < 256 ) return chr( ( $major << 5 ) | 24 ) . pack( 'C', $value );
	if ( $value < 65536 ) return chr( ( $major << 5 ) | 25 ) . pack( 'n', $value );
	throw new RuntimeException( 'Bounded synthetic CBOR required' );
}
function kiwe_pt2_cbor_int( int $value ): string { return kiwe_pt2_cbor_head( $value < 0 ? 1 : 0, $value < 0 ? -1 - $value : $value ); }
function kiwe_pt2_cbor_bytes( string $value ): string { return kiwe_pt2_cbor_head( 2, strlen( $value ) ) . $value; }
function kiwe_pt2_cbor_text( string $value ): string { return kiwe_pt2_cbor_head( 3, strlen( $value ) ) . $value; }
function kiwe_pt2_cbor_map( array $pairs ): string {
	$body = kiwe_pt2_cbor_head( 5, count( $pairs ) );
	foreach ( $pairs as [ $key, $value ] ) $body .= $key . $value;
	return $body;
}
function kiwe_pt2_authenticator(): array {
	$key = openssl_pkey_new( [ 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ] );
	if ( ! $key ) throw new RuntimeException( 'Native EC fixture key unavailable' );
	$details = openssl_pkey_get_details( $key );
	$x = $details['ec']['x'] ?? ''; $y = $details['ec']['y'] ?? '';
	if ( strlen( $x ) > 32 || strlen( $y ) > 32 || '' === $x || '' === $y ) throw new RuntimeException( 'Native EC coordinates unavailable' );
	$cose = kiwe_pt2_cbor_map( [
		[ kiwe_pt2_cbor_int( 1 ), kiwe_pt2_cbor_int( 2 ) ],
		[ kiwe_pt2_cbor_int( 3 ), kiwe_pt2_cbor_int( -7 ) ],
		[ kiwe_pt2_cbor_int( -1 ), kiwe_pt2_cbor_int( 1 ) ],
		[ kiwe_pt2_cbor_int( -2 ), kiwe_pt2_cbor_bytes( str_pad( $x, 32, "\0", STR_PAD_LEFT ) ) ],
		[ kiwe_pt2_cbor_int( -3 ), kiwe_pt2_cbor_bytes( str_pad( $y, 32, "\0", STR_PAD_LEFT ) ) ],
	] );
	return [ 'key' => $key, 'cose' => $cose, 'id' => random_bytes( 32 ) ];
}
function kiwe_pt2_registration( array $options, array $authenticator, int $flags = 0x45 ): array {
	$client = wp_json_encode( [ 'type' => 'webauthn.create', 'challenge' => $options['publicKey']['challenge'], 'origin' => pk_expected_origin() ] );
	$auth = hash( 'sha256', pk_rp_id(), true ) . chr( $flags ) . pack( 'N', 0 ) . str_repeat( "\0", 16 ) . pack( 'n', strlen( $authenticator['id'] ) ) . $authenticator['id'] . $authenticator['cose'];
	$attestation = kiwe_pt2_cbor_map( [ [ kiwe_pt2_cbor_text( 'fmt' ), kiwe_pt2_cbor_text( 'none' ) ], [ kiwe_pt2_cbor_text( 'authData' ), kiwe_pt2_cbor_bytes( $auth ) ], [ kiwe_pt2_cbor_text( 'attStmt' ), kiwe_pt2_cbor_map( [] ) ] ] );
	return [ 'token' => $options['token'], 'credential' => [ 'id' => pk_b64u( $authenticator['id'] ), 'rawId' => pk_b64u( $authenticator['id'] ), 'type' => 'public-key', 'response' => [ 'clientDataJSON' => pk_b64u( $client ), 'attestationObject' => pk_b64u( $attestation ), 'transports' => [ 'internal' ] ] ] ];
}
function kiwe_pt2_assertion( array $options, array $authenticator, int $counter, ?string $origin = null, int $flags = 0x05 ): array {
	$client = wp_json_encode( [ 'type' => 'webauthn.get', 'challenge' => $options['publicKey']['challenge'], 'origin' => $origin ?? pk_expected_origin() ] );
	$auth = hash( 'sha256', pk_rp_id(), true ) . chr( $flags ) . pack( 'N', $counter );
	if ( ! openssl_sign( $auth . hash( 'sha256', $client, true ), $signature, $authenticator['key'], OPENSSL_ALGO_SHA256 ) ) throw new RuntimeException( 'Native fixture assertion could not be signed' );
	return [ 'token' => $options['token'], 'credential' => [ 'id' => pk_b64u( $authenticator['id'] ), 'rawId' => pk_b64u( $authenticator['id'] ), 'type' => 'public-key', 'response' => [ 'clientDataJSON' => pk_b64u( $client ), 'authenticatorData' => pk_b64u( $auth ), 'signature' => pk_b64u( $signature ) ] ] ];
}
