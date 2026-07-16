<?php

namespace DSA\Notifications;

use DSA\Communications\Channel_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notification_Campaign_Service {
	private const HISTORY_OPTION = 'dsa_notification_campaign_history';
	private $preferences;
	private $channels;
	private $push;

	public function __construct( Notification_Preference_Service $preferences, Channel_Service $channels, Push_Service $push ) {
		$this->preferences = $preferences;
		$this->channels = $channels;
		$this->push = $push;
	}

	public function send( string $channel, string $subject, string $message ): array {
		$channel = sanitize_key( $channel );
		$subject = sanitize_text_field( $subject );
		$message = sanitize_textarea_field( $message );
		if ( ! in_array( $channel, [ 'email', 'whatsapp', 'sms', 'app' ], true ) || '' === $message ) {
			return [ 'ok' => false, 'message' => __( 'Choose a valid channel and write a notification.', 'dsa' ), 'sent' => 0, 'failed' => 0 ];
		}
		if ( 'app' === $channel ) {
			$result = $this->push->broadcast( $subject, $message, home_url( '/' ) );
			$this->remember( $channel, $subject, $result );
			return $result;
		}
		if ( ! $this->channels->available_for_campaign( $channel ) ) {
			return [ 'ok' => false, 'message' => sprintf( __( '%s delivery is not configured.', 'dsa' ), ucfirst( $channel ) ), 'sent' => 0, 'failed' => 0 ];
		}

		$user_ids = array_slice( $this->preferences->audience_user_ids( $channel ), 0, 100 );
		$sent = 0;
		$failed = 0;
		foreach ( $user_ids as $user_id ) {
			$recipient = $this->preferences->contact_for_user( (int) $user_id, $channel );
			if ( '' === $recipient ) {
				$failed++;
				continue;
			}
			$delivery_message = 'email' === $channel || '' === $subject ? $message : $subject . "\n\n" . $message;
			$result = $this->channels->send( $channel, $recipient, $subject, $delivery_message, [ 'purpose' => 'notification_campaign', 'user_id' => (int) $user_id ] );
			is_wp_error( $result ) ? $failed++ : $sent++;
		}
		$result = [
			'ok' => $sent > 0 && 0 === $failed,
			'message' => sprintf( __( 'Sent %1$d. Failed or missing contact: %2$d. Campaigns are capped at 100 recipients per send.', 'dsa' ), $sent, $failed ),
			'sent' => $sent,
			'failed' => $failed,
		];
		$this->remember( $channel, $subject, $result );
		return $result;
	}

	public function history(): array {
		$history = get_option( self::HISTORY_OPTION, [] );
		return is_array( $history ) ? $history : [];
	}

	public function push_summary(): array {
		return $this->push->audience_summary();
	}

	private function remember( string $channel, string $subject, array $result ): void {
		$history = $this->history();
		array_unshift( $history, [
			'channel' => $channel,
			'subject' => $subject,
			'sent' => (int) ( $result['sent'] ?? 0 ),
			'failed' => (int) ( $result['failed'] ?? 0 ),
			'createdAt' => current_time( 'mysql' ),
		] );
		update_option( self::HISTORY_OPTION, array_slice( $history, 0, 20 ), false );
	}
}
