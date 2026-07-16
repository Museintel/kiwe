<?php

namespace DSA\Communications;

use DSA\Security\Secret_Store;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Service {
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'phpmailer_init', [ $this, 'configure_phpmailer' ], 20 );
		add_filter( 'wp_mail_from', [ $this, 'filter_from_email' ] );
		add_filter( 'wp_mail_from_name', [ $this, 'filter_from_name' ] );
		add_action( 'wp_mail_failed', [ $this, 'record_failure' ] );
	}

	public function send( string $to, string $subject, string $message, array $headers = [] ) {
		$config = $this->config();

		if ( empty( $config['enabled'] ) ) {
			return new \WP_Error( 'dsa_email_disabled', __( 'Kiwe Email is disabled.', 'dsa' ) );
		}

		$to = sanitize_email( $to );

		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'dsa_email_invalid_recipient', __( 'The reminder email address is invalid.', 'dsa' ) );
		}

		$sent = wp_mail( $to, wp_strip_all_tags( $subject ), $message, $headers );

		if ( ! $sent ) {
			return new \WP_Error( 'dsa_email_send_failed', __( 'WordPress could not hand the message to the configured mail transport.', 'dsa' ) );
		}

		return true;
	}

	public function configure_phpmailer( $phpmailer ): void {
		$config = $this->config();
		$smtp = isset( $config['smtp'] ) && is_array( $config['smtp'] ) ? $config['smtp'] : [];

		if ( empty( $config['enabled'] ) || 'smtp' !== ( $config['transport'] ?? 'wordpress' ) || empty( $smtp['host'] ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host = sanitize_text_field( (string) $smtp['host'] );
		$phpmailer->Port = max( 1, min( 65535, absint( $smtp['port'] ?? 587 ) ) );
		$phpmailer->SMTPAuth = ! empty( $smtp['auth'] );
		$encryption = sanitize_key( (string) ( $smtp['encryption'] ?? 'tls' ) );
		$phpmailer->SMTPSecure = in_array( $encryption, [ 'tls', 'ssl' ], true ) ? $encryption : '';

		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = sanitize_text_field( (string) ( $smtp['username'] ?? '' ) );
			$phpmailer->Password = Secret_Store::decrypt( (string) ( $smtp['password'] ?? '' ) );
		}
	}

	public function filter_from_email( string $email ): string {
		$configured = sanitize_email( (string) ( $this->config()['from_email'] ?? '' ) );
		return is_email( $configured ) ? $configured : $email;
	}

	public function filter_from_name( string $name ): string {
		$configured = sanitize_text_field( (string) ( $this->config()['from_name'] ?? '' ) );
		return '' !== $configured ? $configured : $name;
	}

	public function record_failure( $error ): void {
		$message = is_wp_error( $error ) ? $error->get_error_message() : __( 'Unknown mail transport error.', 'dsa' );
		update_option(
			'dsa_email_last_failure',
			[
				'message' => sanitize_text_field( $message ),
				'time'    => current_time( 'mysql' ),
			],
			false
		);
	}

	public function diagnostics(): array {
		$config = $this->config();
		$smtp = is_array( $config['smtp'] ?? null ) ? $config['smtp'] : [];

		return [
			'enabled'      => ! empty( $config['enabled'] ),
			'transport'    => 'smtp' === ( $config['transport'] ?? 'wordpress' ) ? 'smtp' : 'wordpress',
			'from_email'   => sanitize_email( (string) ( $config['from_email'] ?? '' ) ),
			'smtp_ready'   => ! empty( $smtp['host'] ) && ( empty( $smtp['auth'] ) || ( ! empty( $smtp['username'] ) && ! empty( $smtp['password'] ) ) ),
			'last_test'    => get_option( 'dsa_email_last_test', [] ),
			'last_failure' => get_option( 'dsa_email_last_failure', [] ),
		];
	}

	private function config(): array {
		$all = $this->settings->all();
		$defaults = $this->settings->defaults();
		return wp_parse_args( $all['email'] ?? [], $defaults['email'] ?? [] );
	}
}
