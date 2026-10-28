<?php
namespace DSA\Discussion;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Composer adapter for existing PhoneKey interfaces. It creates no credentials, sessions or proof itself. */
final class Discussion_Identity_Bridge {
	public static function own_contact(): array { return \DSA\PhoneKey\PhoneKey_Bridge::discussion_own_contact(); }
	public static function start( \WP_REST_Request $request, int $id ) {
		$post = Discussion_Target::get( $id ); if ( is_wp_error( $post ) ) return $post;
		if ( ! Discussion_Target::descriptor( $post )['open'] ) return new \WP_Error( 'kiwe_discussion_closed', __( 'New comments are closed.', 'dsa' ), [ 'status'=>403 ] );
		if ( ! \DSA\PhoneKey\PhoneKey_Bridge::discussion_configuration()['available'] ) return new \WP_Error( 'kiwe_discussion_identity_unavailable', __( 'Verification is not available right now. Your draft is safe.', 'dsa' ), [ 'status'=>503 ] );
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), [ 'identifier','email','phone' ] ) ) return new \WP_Error( 'kiwe_discussion_identity_input', __( 'Enter your email or phone number.', 'dsa' ), [ 'status'=>400 ] );
		foreach ( $input as $value ) if ( ! is_string( $value ) || strlen( $value ) > 254 ) return new \WP_Error( 'kiwe_discussion_identity_input', __( 'Enter a valid contact.', 'dsa' ), [ 'status'=>400 ] );
		$result = \DSA\PhoneKey\PhoneKey_Bridge::discussion_start_identity( $request, $input );
		if ( is_wp_error( $result ) ) return $result;
		if ( $result instanceof \WP_REST_Response ) {
			if ( $result->get_status() >= 400 ) return $result;
			$result = $result->get_data();
		}
		// Never project an existing account's display name, roles or recovery-contact data into a typed-contact flow.
		$keys = [ 'ok','token','mode','identifierType','identifier','deliveryPending','resendAfter','autoContinue','canLater','alreadyVerified' ];
		$out = array_intersect_key( (array) $result, array_fill_keys( $keys, true ) );
		$out['subscribeOnSkip'] = ! empty( $result['autoContinue'] );
		$out['secureSigninRequired'] = in_array( $result['mode'] ?? '', [ 'login_passkey','privileged_setup','privileged_password_required' ], true );
		return $out;
	}
}
