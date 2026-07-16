<?php

namespace DSA\Communications;

use DSA\Security\Secret_Store;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Channel_Service {
	private $settings;
	private $email;

	public function __construct( Settings $settings, Email_Service $email ) {
		$this->settings = $settings;
		$this->email = $email;
	}

	public function available( string $channel ): bool {
		$config = $this->config();

		if ( 'email' === $channel ) {
			$email = $this->settings->all()['email'] ?? [];
			return ! empty( $config['manual_reminders_enabled'] ) && ! empty( $email['enabled'] );
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

		$config = $this->config()['channels'][ $channel ] ?? [];
		return ! empty( $config['enabled'] ) && wp_http_validate_url( (string) ( $config['webhook_url'] ?? '' ) );
	}

	public function send( string $channel, string $recipient, string $subject, string $message, array $context = [] ) {
		$available = 'notification_campaign' === sanitize_key( (string) ( $context['purpose'] ?? '' ) )
			? $this->available_for_campaign( $channel )
			: $this->available( $channel );
		if ( ! $available ) {
			return new \WP_Error( 'dsa_channel_unavailable', __( 'That reminder channel is not configured.', 'dsa' ) );
		}

		if ( 'email' === $channel ) {
			return $this->email->send( $recipient, $subject, $message );
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
}
