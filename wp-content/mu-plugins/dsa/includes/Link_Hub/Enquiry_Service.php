<?php

namespace DSA\Link_Hub;

use DSA\PhoneKey\PhoneKey_Bridge;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Intent-only validation and handoff planning. Subscriber capture has a separate native owner. */
final class Enquiry_Service {

	public const SCHEMA = 'kiwe.link-enquiry.v1';
	public const TTL = 900;
	public const FORM = 'kiwe-link-enquiries';
	public const MAX_BODY = 32768;
	public const MAX_URL = 4096;
	public const CHANNELS = [ 'whatsapp','email','x','instagram','facebook' ];

	public static function channel( string $id ): ?array {
		foreach ( Channel_Service::enquiry_rows() as $row ) if ( $row['id'] === $id ) return $row;
		return null;
	}

	private static function signature( string $body ): string {
		return hash_hmac( 'sha256', 'kiwe-link-enquiry|' . $body, wp_salt( 'nonce' ) );
	}

	/** Fresh, actor/site/channel-bound admission; never a claim that an entered contact is verified. */
	public static function ticket( string $channel ): string {
		$body = rtrim( strtr( base64_encode( wp_json_encode( [ 'v'=>1, 'site'=>get_current_blog_id(), 'actor'=>get_current_user_id(), 'channel'=>$channel, 'id'=>bin2hex( random_bytes( 16 ) ), 'issued'=>time(), 'expires'=>time() + self::TTL ] ) ), '+/', '-_' ), '=' );
		return $body . '.' . self::signature( $body );
	}

	public static function claims( $ticket, string $channel, bool $allow_expired_receipt = false ) {
		if ( ! is_string( $ticket ) || strlen( $ticket ) > 1024 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $ticket, $m ) || ! hash_equals( self::signature( $m[1] ), $m[2] ) ) return self::error( 'Refresh this enquiry before sending it.', 403 );
		$value = json_decode( (string) base64_decode( strtr( $m[1], '-_', '+/' ), true ), true );
		if ( ! is_array( $value ) || 1 !== ( $value['v'] ?? null ) || get_current_blog_id() !== ( $value['site'] ?? null ) || get_current_user_id() !== ( $value['actor'] ?? null ) || $channel !== ( $value['channel'] ?? null ) || ! is_string( $value['id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value['id'] ) || ! is_int( $value['issued'] ?? null ) || ! is_int( $value['expires'] ?? null ) || $value['expires'] !== $value['issued'] + self::TTL || ( ! $allow_expired_receipt && $value['expires'] < time() ) || $value['issued'] > time() + 30 ) return self::error( 'The enquiry session changed. Refresh before sending it.', 409 );
		return $value;
	}

	private static function length( string $value ): int {
		if ( 1 !== preg_match( '//u', $value ) ) return PHP_INT_MAX;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : preg_match_all( '/./us', $value );
	}

	public static function contact( string $value, string $kind ): string {
		$value = trim( $value );
		if ( 'email' === $kind ) return strlen( $value ) <= 254 && is_email( $value ) ? $value : '';
		if ( strlen( $value ) > 40 || preg_match( '/[^0-9+().\s-]/D', $value ) ) return '';
		$value = PhoneKey_Bridge::normalize_public_phone( $value ); // Existing stateless formatter; no account lookup.
		return preg_match( '/^\+[1-9][0-9]{7,14}$/D', $value ) ? $value : '';
	}

	/** Own account or own submitted contact; prefill is independent of verified delivery destinations. */
	public static function prefill( string $channel ): array {
		$own = \DSA\Identity\Form_Contact_Service::prefill();
		$contact = 'whatsapp' === $channel ? $own['phone'] : $own['email'];
		if ( ! in_array( $channel, [ 'email','whatsapp' ], true ) && ! $contact ) $contact = $own['phone'];
		return [ 'name'=>self::length( $own['name'] ) <= 150 ? $own['name'] : '', 'contact'=>$contact ];
	}

	public static function opening_message( int $id ): string {
		$subject = self::subject( $id );
		return $subject['id'] ? sprintf( __( 'I am enquiring about %s.', 'dsa' ), $subject['title'] ) : '';
	}

	public static function input( $raw, bool $allow_expired_receipt = false ) {
		$keys = [ 'channel','ticket','name','contact','message','subjectId','website' ];
		if ( ! is_array( $raw ) || array_diff( array_keys( $raw ), $keys ) || array_diff( $keys, array_keys( $raw ) ) ) return self::error( 'Check the enquiry fields and try again.' );
		foreach ( [ 'channel','ticket','name','contact','message','website' ] as $key ) if ( ! is_string( $raw[$key] ) ) return self::error( 'Check the enquiry fields and try again.' );
		if ( ! in_array( $raw['channel'], self::CHANNELS, true ) || ! is_int( $raw['subjectId'] ) || $raw['subjectId'] < 0 || $raw['subjectId'] > PHP_INT_MAX || '' !== $raw['website'] || self::length( $raw['name'] ) > 150 || self::length( $raw['message'] ) > 4000 || strlen( $raw['contact'] ) > 254 ) return self::error( 'Check the enquiry fields and try again.' );
		$claims = self::claims( $raw['ticket'], $raw['channel'], $allow_expired_receipt ); if ( is_wp_error( $claims ) ) return $claims;
		$name = sanitize_text_field( $raw['name'] );
		// This is private plain text, escaped at every presentation boundary. Keep meaningful URL
		// escapes and literal markup rather than applying an HTML sanitizer to the visitor's message.
		$message = trim( str_replace( [ "\r\n", "\r" ], "\n", $raw['message'] ) );
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message ) ) return self::error( 'Remove unsupported control characters from your message.' );
		$kind = 'whatsapp' === $raw['channel'] ? 'phone' : ( 'email' === $raw['channel'] || is_email( trim( $raw['contact'] ) ) ? 'email' : 'phone' ); $contact = self::contact( $raw['contact'], $kind );
		if ( '' === trim( $name ) || '' === trim( $message ) || '' === $contact ) return self::error( 'Add your name, a valid contact and your message.' );
		$body = [ 'channel'=>$raw['channel'], 'name'=>$name, 'contact'=>$contact, 'contactKind'=>$kind, 'message'=>$message, 'subjectId'=>$raw['subjectId'] ];
		$verified = $claims['actor'] > 0 ? self::contact( PhoneKey_Bridge::campaign_contact( $claims['actor'], 'email' === $kind ? 'email' : 'whatsapp' ), $kind ) : '';
		return [ 'schema'=>self::SCHEMA, ...$body, 'subject'=>self::subject( $raw['subjectId'] ), 'verifiedContact'=>'' !== $verified && 0 === strcasecmp( $verified, $contact ), 'actor'=>$claims['actor'], 'admittedUntil'=>$claims['expires'], 'key'=>hash_hmac( 'sha256', $claims['site'] . '|' . $claims['actor'] . '|' . $claims['id'], wp_salt( 'nonce' ) ), 'requestHash'=>hash( 'sha256', wp_json_encode( $body ) ) ];
	}

	/** Never accept a caller-provided URL/title or inherit an administrator's private-content access. */
	public static function subject( int $id ): array {
		return Public_Subject_Service::resolve( $id );
	}

	/** A plan for a user-initiated handoff, never evidence that a message was sent. */
	public static function handoff( array $row, array $record ): array {
		$subject = self::subject( (int) $record['subjectId'] );
		$text = $record['name'] . "\n\n" . $record['message'] . "\n\n" . $subject['title'] . "\n" . $subject['url'];
		if ( 'email' === $row['id'] ) $text = preg_replace( '/\r\n|\r|\n/', "\r\n", $text ); // RFC6068 body line breaks.
		$subject_line = sprintf( __( 'Enquiry about %s', 'dsa' ), $subject['title'] );
		$url = match ( $row['id'] ) { 'whatsapp'=>$row['url'] . '?text=' . rawurlencode( $text ), 'email'=>$row['url'] . '?subject=' . rawurlencode( $subject_line ) . '&body=' . rawurlencode( $text ), 'x'=>$row['url'] . '&text=' . rawurlencode( $text ), default=>$row['url'] };
		$copy = in_array( $row['id'], [ 'instagram','facebook' ], true ) || strlen( $url ) > self::MAX_URL;
		$out = [ 'channel'=>$row['id'], 'mode'=>$copy ? 'copy' : 'prefill', 'url'=>$copy ? $row['url'] : $url, 'text'=>$text, 'sent'=>false ];
		if ( 'email' === $row['id'] ) {
			$gmail = 'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode( substr( $row['url'], 7 ) ) . '&su=' . rawurlencode( $subject_line ) . '&body=' . rawurlencode( $text );
			if ( ! $copy && strlen( $gmail ) <= self::MAX_URL ) $out['gmailUrl'] = $gmail;
		}
		return $out;
	}

	public static function error( string $message, int $status = 400 ): \WP_Error { return new \WP_Error( 'kiwe_link_enquiry', $message, [ 'status'=>$status ] ); }
}
