<?php

namespace DSA\WP7;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Client_Service {
	public function summary(): array {
		return [
			'id'          => 'ai_client',
			'label'       => __( 'WP AI Client', 'dsa' ),
			'available'   => $this->available(),
			'configured'  => $this->text_generation_available(),
			'status'      => $this->text_generation_available() ? 'ready' : ( $this->available() ? 'provider_required' : 'fallback' ),
			'description' => __( 'Admin-only intelligence for trust audits, transition copy, SecureTrack explanations, and GEO recommendations.', 'dsa' ),
			'fallback'    => __( 'DSA keeps AI as a bounded admin module until a native client is present.', 'dsa' ),
		];
	}

	public function available(): bool {
		return function_exists( 'wp_ai_client_prompt' )
			|| function_exists( 'wp_ai_client' )
			|| function_exists( 'wp_get_ai_client' )
			|| class_exists( 'WP_AI_Client' )
			|| class_exists( 'WP_AI_Service' );
	}

	public function text_generation_available(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		try {
			$builder = wp_ai_client_prompt( 'Kiwe capability check' );
			return ! method_exists( $builder, 'is_supported_for_text_generation' ) || $builder->is_supported_for_text_generation();
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Run a bounded, text-only request through the WordPress 7 AI Client.
	 *
	 * WordPress owns provider selection and credentials. Kiwe deliberately does
	 * not inspect Connector secrets or carry conversation history into this call.
	 */
	public function generate( array $envelope, int $max_tokens = 1200, string $model = '' ): array {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return $this->not_called( 'wordpress_ai_client_unavailable' );
		}

		$system = trim( (string) ( $envelope['system'] ?? '' ) );
		$user   = trim( (string) ( $envelope['user'] ?? '' ) );
		if ( '' === $user ) {
			return $this->not_called( 'empty_prompt' );
		}

		try {
			$builder = wp_ai_client_prompt( $user );
			if ( '' !== $system && method_exists( $builder, 'using_system_instruction' ) ) {
				$builder = $builder->using_system_instruction( $system );
			}
			if ( method_exists( $builder, 'using_temperature' ) ) {
				$builder = $builder->using_temperature( 0.2 );
			}
			if ( method_exists( $builder, 'using_max_tokens' ) ) {
				$builder = $builder->using_max_tokens( max( 80, min( 12000, $max_tokens ) ) );
			}
			if ( '' !== $model && method_exists( $builder, 'using_model_preference' ) ) {
				$builder = $builder->using_model_preference( $model );
			}
			if ( method_exists( $builder, 'is_supported_for_text_generation' ) && ! $builder->is_supported_for_text_generation() ) {
				return $this->not_called( 'wordpress_ai_text_generation_unsupported' );
			}

			$result = $builder->generate_text();
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
				return [
					'ok'       => false,
					'called'   => true,
					'provider' => 'wordpress_ai_client',
					'model'    => $model,
					'error'    => [
						'code'    => sanitize_key( (string) $result->get_error_code() ),
						'message' => sanitize_text_field( $result->get_error_message() ),
					],
				];
			}

			$text = is_string( $result ) ? trim( $result ) : '';
			return [
				'ok'         => '' !== $text,
				'called'     => true,
				'provider'   => 'wordpress_ai_client',
				'model'      => $model,
				'output'     => $text,
				'outputBytes'=> strlen( $text ),
			];
		} catch ( \Throwable $error ) {
			return [
				'ok'       => false,
				'called'   => true,
				'provider' => 'wordpress_ai_client',
				'model'    => $model,
				'error'    => [
					'code'    => 'wordpress_ai_exception',
					'message' => sanitize_text_field( $error->getMessage() ),
				],
			];
		}
	}

	private function not_called( string $reason ): array {
		return [
			'ok'       => true,
			'called'   => false,
			'provider' => 'wordpress_ai_client',
			'model'    => '',
			'reason'   => $reason,
		];
	}
}
