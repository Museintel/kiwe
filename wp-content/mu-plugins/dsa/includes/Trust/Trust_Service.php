<?php

namespace DSA\Trust;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Trust_Service {
	public function summary( array $config = [] ): array {
		return [
			'ssl'      => [
				'active'     => is_ssl(),
				'provider'   => $this->ssl_provider( $config ),
				'confidence' => is_ssl() ? 'high' : 'low',
			],
			'phonekey' => [
				'active' => $this->phonekey_active(),
				'label'  => __( 'Kiwe Key', 'dsa' ),
			],
			'secure'   => [
				'active' => $this->securetrack_active(),
				'label'  => __( 'Kiwe Secure', 'dsa' ),
			],
			'payment'  => [
				'active'    => ! empty( $this->payment_gateways( $config ) ),
				'providers' => $this->payment_gateways( $config ),
				'label'     => $this->payment_provider_label( $config ),
			],
		];
	}

	public function health_data( array $config = [] ): array {
		$summary = $this->summary( $config );

		return [
			[
				'label'  => sprintf( __( 'SSL by %s', 'dsa' ), $summary['ssl']['provider'] ),
				'active' => (bool) $summary['ssl']['active'],
			],
			[
				'label'  => __( 'Secure login by Kiwe Key', 'dsa' ),
				'active' => (bool) $summary['phonekey']['active'],
			],
			[
				'label'  => sprintf( __( 'Payment protected by %s', 'dsa' ), $summary['payment']['label'] ),
				'active' => (bool) $summary['payment']['active'],
			],
		];
	}

	public function payment_gateways( array $config = [] ): array {
		$manual = sanitize_text_field( $config['payment_provider'] ?? '' );

		if ( '' !== $manual ) {
			return [ $manual ];
		}

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
			return [];
		}

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$out      = [];

		foreach ( $gateways as $gateway ) {
			$out[] = wp_strip_all_tags( $gateway->get_title() );
		}

		return array_values( array_unique( array_filter( $out ) ) );
	}

	public function payment_provider_label( array $config = [] ): string {
		$gateways = $this->payment_gateways( $config );

		if ( ! empty( $gateways ) ) {
			return implode( ', ', array_slice( $gateways, 0, 2 ) );
		}

		return __( 'your payment provider', 'dsa' );
	}

	public function ssl_provider( array $config = [] ): string {
		$provider = trim( (string) ( $config['ssl_provider'] ?? '' ) );

		if ( '' !== $provider ) {
			return sanitize_text_field( $provider );
		}

		$host     = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '' ) );
		$software = strtolower( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );

		if ( false !== strpos( $host, 'hostinger' ) || false !== strpos( $software, 'hostinger' ) ) {
			return 'Hostinger';
		}

		return __( 'active SSL', 'dsa' );
	}

	public function phonekey_active(): bool {
		return defined( 'PK_STAGE3_LOADED' ) || function_exists( 'pk_settings' );
	}

	public function securetrack_active(): bool {
		return defined( 'STP_VER' ) || function_exists( 'stp_cfg' );
	}
}
