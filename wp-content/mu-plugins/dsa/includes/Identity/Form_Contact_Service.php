<?php
namespace DSA\Identity;

use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\Security\Secret_Store;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private, self-declared form details. A remembered contact is never account or delivery proof. */
final class Form_Contact_Service {

	public const COOKIE = 'kiwe_form_contact_v1';
	private const TTL = 86400;
	public static function contract(): array {
		return [ 'schema'=>'kiwe.form-contact.v1', 'subscriberCapture'=>'confirmed-submission-new-pending-subscriber', 'prefill'=>'private-own-session-or-own-submitted-browser', 'proofGranted'=>false, 'marketingConsentImplied'=>false, 'fields'=>[ 'name'=>'name', 'first_name'=>'given-name', 'last_name'=>'family-name', 'email'=>'email', 'phone'=>'tel' ] ];
	}

	public static function clean( array $input ): array {
		$out = [];
		foreach ( [ 'name','first_name','last_name' ] as $key ) {
			$value = is_string( $input[$key] ?? null ) ? sanitize_text_field( $input[$key] ) : '';
			if ( $value && strlen( $value ) <= 600 && ! preg_match( '/[\x00-\x1f\x7f]/', $value ) ) $out[$key] = $value;
		}
		$email = is_string( $input['email'] ?? null ) ? trim( $input['email'] ) : '';
		if ( strlen( $email ) <= 254 && is_email( $email ) ) $out['email'] = strtolower( $email );
		$phone = is_string( $input['phone'] ?? null ) ? trim( $input['phone'] ) : '';
		if ( strlen( $phone ) <= 40 && ! preg_match( '/[^0-9+().\s-]/', $phone ) ) {
			$phone = PhoneKey_Bridge::normalize_public_phone( $phone );
			if ( preg_match( '/^\+[1-9][0-9]{7,14}$/D', $phone ) ) $out['phone'] = $phone;
		}
		if ( empty( $out['name'] ) ) $out['name'] = trim( ( $out['first_name'] ?? '' ) . ' ' . ( $out['last_name'] ?? '' ) );
		if ( $out['name'] && empty( $out['first_name'] ) && empty( $out['last_name'] ) ) {
			$parts = preg_split( '/\s+/u', $out['name'], 2 );
			$out['first_name'] = $parts[0]; $out['last_name'] = $parts[1] ?? '';
		}
		return $out;
	}

	private static function binding(): string {
		return hash_hmac( 'sha256', home_url( '/' ) . '|' . get_current_user_id() . '|' . ( get_current_user_id() ? wp_get_session_token() : '' ), wp_salt( 'nonce' ) );
	}

	private static function remembered(): array {
		$cookie = $_COOKIE[self::COOKIE] ?? '';
		if ( ! is_string( $cookie ) || strlen( $cookie ) > 3800 || ! str_starts_with( $cookie, 'dsa-secret:v2:' ) ) return [];
		$decoded = Secret_Store::decrypt_with_status( $cookie );
		$value = 'ok' === $decoded['status'] ? json_decode( $decoded['value'], true ) : null;
		if ( ! is_array( $value ) || ( $value['v'] ?? null ) !== 1 || ! is_string( $value['binding'] ?? null ) || ! hash_equals( self::binding(), $value['binding'] ) || ! is_int( $value['expires'] ?? null ) || $value['expires'] < time() || $value['expires'] > time() + self::TTL || ! is_array( $value['contact'] ?? null ) ) return [];
		return self::clean( $value['contact'] );
	}

	/** No contact lookup by typed identifier. Only this WP session and its own encrypted session cookie. */
	public static function prefill(): array {
		$own = PhoneKey_Bridge::own_form_contact();
		return array_replace( [ 'name'=>'','first_name'=>'','last_name'=>'','email'=>'','phone'=>'' ], self::clean( $own ), self::remembered() );
	}

	/** Called only after an admitted submission/save, never when a form is rendered or merely focused. */
	public static function capture( array $input ): void {
		$contact = self::clean( $input );
		if ( empty( $contact['email'] ) && empty( $contact['phone'] ) ) return;
		// Account capture is best-effort beside the successful business submission. It cannot undo a saved enquiry.
		PhoneKey_Bridge::register_form_subscriber( $contact );
		if ( headers_sent() || ! Secret_Store::supports_native_encryption() ) return;
		$remembered = array_replace( self::remembered(), array_filter( $contact, static fn( $value ) => '' !== $value ) );
		$value = Secret_Store::encrypt( wp_json_encode( [ 'v'=>1, 'binding'=>self::binding(), 'expires'=>time() + self::TTL, 'contact'=>$remembered ] ) );
		if ( ! str_starts_with( $value, 'dsa-secret:v2:' ) || strlen( urlencode( $value ) ) + strlen( self::COOKIE ) > 3800 ) return;
		if ( setcookie( self::COOKIE, $value, [ 'expires'=>0, 'path'=>defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax' ] ) ) $_COOKIE[self::COOKIE] = $value;
	}
	public static function forget(): void {
		unset( $_COOKIE[self::COOKIE] );
		if ( ! headers_sent() ) setcookie( self::COOKIE, '', [ 'expires'=>time() - 3600, 'path'=>defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax' ] );
	}

	public static function routes(): void {
		register_rest_route( 'dsa/v1', '/form-contact', [ 'methods'=>'GET', 'permission_callback'=>static fn() => \DSA\Utilities\Origin_Checker::is_same_site_request(), 'callback'=>static fn() => new \WP_REST_Response( [ 'ok'=>true, 'contact'=>self::prefill() ], 200, [ 'Cache-Control'=>'private, no-store, max-age=0', 'Vary'=>'Cookie', 'X-Robots-Tag'=>'noindex, nofollow' ] ) ] );
	}
}
