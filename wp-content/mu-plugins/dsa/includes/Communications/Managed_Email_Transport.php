<?php

namespace DSA\Communications;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Uses the enrolled website's Key credential. Mailbox access stays on Key. */
final class Managed_Email_Transport {
	private static function request( string $path, array $payload ): ?array {
		if ( ! function_exists( 'pk_website_setup_is_default' ) ) return null;
		$settings = pk_settings();
		$origin = untrailingslashit( home_url() );
		if ( ! str_starts_with( $origin, 'https://' ) || ! pk_website_setup_is_default( $settings ) ) return null;
		$body = wp_json_encode( [ 'origin' => $origin, ...$payload ] );
		if ( ! is_string( $body ) || strlen( $body ) > 132000 ) return null;
		$headers = pk_gateway_signed_headers( $settings, $body );
		if ( ! $headers ) return null;
		$headers['Origin'] = $origin;
		$url = substr( pk_default_whatsapp_gateway_url(), 0, -strlen( '/v1/otp' ) ) . $path;
		$response = wp_safe_remote_post( $url, [
			'timeout'=>'/v1/email' === $path ? 12 : 5, 'redirection'=>0, 'limit_response_size'=>4096, 'headers'=>$headers, 'body'=>$body,
		] );
		if ( is_wp_error( $response ) || ! in_array( wp_remote_retrieve_response_code( $response ), [ 200, 202 ], true ) ) return null;
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) && true === ( $decoded['ok'] ?? false ) ? $decoded : null;
	}

	public static function status(): array {
		$status = self::request( '/v1/email-status', [] );
		$from = $status['fromEmail'] ?? '';
		if ( ! is_string( $from ) || ! is_email( $from ) || ! str_ends_with( strtolower( $from ), '@kiwelaunch.com' ) ) return [];
		return [ 'from_email'=>strtolower( $from ), 'managed_campaigns'=>true === ( $status['campaigns'] ?? false ) ];
	}

	public static function enroll_default(): void {
		$settings = new Settings();
		$email = $settings->all()['email'];
		// A site owner's explicit choice and any existing configured transport have priority.
		if ( ! empty( $email['sender_choice'] ) || 'wordpress' !== $email['transport']
			|| ! empty( $email['from_email'] ) || ! empty( $email['smtp']['host'] ) ) return;
		// An existing dedicated SMTP plugin is eligible after controlled migration.
		foreach ( (array) get_option( 'active_plugins', [] ) as $plugin ) {
			if ( preg_match( '/smtp|mailer/i', (string) $plugin ) ) return;
		}
		$status = self::status();
		if ( ! $status ) return;
		$email['enabled'] = true;
		$email['transport'] = 'managed';
		$email = array_replace( $email, $status );
		if ( is_array( get_option( DSA_OPTION_SETTINGS, null ) ) ) $settings->update_selected( [ 'email' => $email ] );
		else $settings->update( [ 'email' => $email ] );
		delete_option( 'dsa_email_last_test' );
	}

	/** Forward the campaign unsubscribe headers when the enrolled transport supports them. */
	public static function message( array $mail, bool $campaigns ): ?array {
		$to = $mail['to'] ?? [];
		$to = is_array( $to ) ? array_values( $to ) : [ $to ];
		$subject = $mail['subject'] ?? null;
		$body = $mail['message'] ?? null;
		if ( count( $to ) !== 1 || ! is_string( $to[0] ) || ! is_email( $to[0] ) || ! empty( $mail['attachments'] )
			|| ! empty( $mail['embeds'] ) || ! is_string( $subject ) || '' === $subject || strlen( $subject ) > 240
			|| preg_match( '/[\r\n]/', $subject ) || ! is_string( $body ) || '' === $body || strlen( $body ) > 65536 ) return null;
		$type = null;
		$unsubscribe = null;
		$one_click = false;
		$headers = $mail['headers'] ?? [];
		$headers = is_array( $headers ) ? $headers : preg_split( '/\r?\n/', (string) $headers );
		foreach ( array_filter( $headers ) as $header ) {
			if ( ! is_string( $header ) || preg_match( '/[\r\n]/', $header ) ) return null;
			$header = trim( $header );
			if ( null === $type && preg_match( '/\AContent-Type:[ \t]*text\/(plain|html)(?:;[ \t]*charset[ \t]*=[ \t]*["\']?[a-z0-9-]+["\']?)?\z/i', $header, $match ) ) {
				$type = 'html' === strtolower( $match[1] ) ? 'html' : 'text';
			} elseif ( $campaigns && null === $unsubscribe && preg_match( '~\AList-Unsubscribe:[ \t]*<(https://[^\s<>]{1,2040})>\z~i', $header, $match ) ) {
				$unsubscribe = $match[1];
			} elseif ( $campaigns && ! $one_click && preg_match( '/\AList-Unsubscribe-Post:[ \t]*List-Unsubscribe=One-Click\z/i', $header ) ) {
				$one_click = true;
			} else {
				return null;
			}
		}
		if ( $one_click !== ( null !== $unsubscribe ) ) return null;
		$message = [ 'to'=>$to[0], 'subject'=>$subject, ( $type ?? 'text' )=>$body ];
		if ( null !== $unsubscribe ) $message['unsubscribe'] = $unsubscribe;
		return $message;
	}

	public static function send( array $message ): bool {
		return null !== self::request( '/v1/email', [ ...$message, 'requestId'=>wp_generate_password( 32, false, false ) ] );
	}
}
