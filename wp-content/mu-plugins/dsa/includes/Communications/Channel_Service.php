<?php

namespace DSA\Communications;

use DSA\Security\Secret_Store;
use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Channel_Service {
	private $settings;
	private $email;
	private $phonekey;

	public function __construct( Settings $settings, Email_Service $email, PhoneKey_Bridge $phonekey ) {
		$this->settings = $settings;
		$this->email = $email;
		$this->phonekey = $phonekey;
	}

	public function available( string $channel ): bool {
		$config = $this->config();

		if ( 'email' === $channel ) {
			$email = $this->settings->all()['email'] ?? [];
			return ! empty( $config['manual_reminders_enabled'] ) && ! empty( $email['enabled'] );
		}

		if ( 'whatsapp' === $channel && $this->phonekey_available() ) {
			return ! empty( $config['manual_reminders_enabled'] );
		}

		$channel_config = $config['channels'][ $channel ] ?? [];
		return ! empty( $config['manual_reminders_enabled'] )
			&& ! empty( $channel_config['enabled'] )
			&& wp_http_validate_url( (string) ( $channel_config['webhook_url'] ?? '' ) );
	}

	public function available_for_campaign( string $channel ): bool {
		if ( 'email' === $channel ) {
			$email = $this->settings->all()['email'] ?? [];
			return ! empty( $email['enabled'] );
		}

		if ( 'whatsapp' === $channel && $this->phonekey_available() ) {
			return true;
		}

		$config = $this->config()['channels'][ $channel ] ?? [];
		return ! empty( $config['enabled'] ) && wp_http_validate_url( (string) ( $config['webhook_url'] ?? '' ) );
	}

	public function send( string $channel, string $recipient, string $subject, string $message, array $context = [] ) {
		$campaign_purposes = [
			'notification_campaign',
			'abandoned_cart_automation',
			'order_status',
			'admin_new_order',
			'admin_new_comment',
			'admin_visitor_summary',
			'admin_live_visitor',
			'admin_guest_application',
			'admin_guest_submission',
			'guest_post_status',
			'editorial_post_status',
			'securetrack_incident',
		];
		$available = in_array( sanitize_key( (string) ( $context['purpose'] ?? '' ) ), $campaign_purposes, true )
			? $this->available_for_campaign( $channel )
			: $this->available( $channel );
		if ( ! $available ) {
			return new \WP_Error( 'dsa_channel_unavailable', __( 'That reminder channel is not configured.', 'dsa' ) );
		}

		if ( 'email' === $channel ) {
			$headers = [];
			if ( isset( $context['headers'] ) && is_array( $context['headers'] ) ) {
				$headers = array_values( array_filter( array_map( 'sanitize_text_field', $context['headers'] ) ) );
			}

			return $this->email->send( $recipient, $subject, $message, $headers );
		}

		if ( 'whatsapp' === $channel && $this->phonekey_available() ) {
			$purpose = sanitize_key( (string) ( $context['purpose'] ?? 'notification_campaign' ) );
			$result = $this->phonekey->send_notification( $recipient, $message, $purpose, $context );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			return $this->maybe_email_fallback( $result, $recipient, $subject, $message, $context );
		}

		$config = $this->config()['channels'][ $channel ] ?? [];
		$headers = [ 'Content-Type' => 'application/json' ];
		$token = Secret_Store::decrypt( (string) ( $config['api_token'] ?? '' ) );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_safe_remote_post(
			(string) $config['webhook_url'],
			[
				'timeout' => 15,
				'headers' => $headers,
				'body'    => wp_json_encode(
					[
						'channel'   => $channel,
						'to'        => $recipient,
						'sender'    => sanitize_text_field( (string) ( $config['sender'] ?? '' ) ),
						'message'   => $message,
						'context'   => $context,
						'site_url'  => home_url( '/' ),
						'site_name' => get_bloginfo( 'name' ),
					]
				),
			],
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'dsa_channel_http_error', sprintf( __( 'The %1$s provider returned HTTP %2$d.', 'dsa' ), $channel, $code ) );
		}

		return true;
	}

	private function config(): array {
		$all = $this->settings->all();
		$defaults = $this->settings->defaults();
		return wp_parse_args( $all['abandoned_cart'] ?? [], $defaults['abandoned_cart'] ?? [] );
	}

	private function phonekey_available(): bool {
		return $this->phonekey->notification_ready();
	}

	private function maybe_email_fallback( \WP_Error $error, string $phone, string $subject, string $message, array $context ) {
		$email = sanitize_email( (string) ( $context['fallback_email'] ?? '' ) );
		$allowed = ! empty( $context['fallback_email_allowed'] ) && is_email( $email );
		if ( ! $allowed || ! $this->available_for_campaign( 'email' ) ) {
			return $error;
		}

		$headers = isset( $context['fallback_headers'] ) && is_array( $context['fallback_headers'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $context['fallback_headers'] ) ) )
			: [];
		$fallback_message = isset( $context['fallback_message'] ) ? (string) $context['fallback_message'] : $message;
		$accepted = $this->email->send( $email, $subject, $fallback_message, $headers );
		$error_data = $error->get_error_data();
		$request_id = sanitize_text_field( (string) ( is_array( $error_data ) ? ( $error_data['request_id'] ?? '' ) : '' ) );
		if ( '' !== $request_id ) {
			$this->phonekey->report_fallback( $phone, $request_id, ! is_wp_error( $accepted ) && false !== $accepted );
		}

		if ( is_wp_error( $accepted ) || false === $accepted ) {
			return is_wp_error( $accepted ) ? $accepted : $error;
		}

		return [ 'ok' => true, 'provider' => 'email_fallback', 'request_id' => $request_id ];
	}
}
