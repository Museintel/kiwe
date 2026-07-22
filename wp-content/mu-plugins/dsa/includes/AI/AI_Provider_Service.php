<?php

namespace DSA\AI;

use DSA\Security\Secret_Store;
use DSA\Settings;
use DSA\WP7\AI_Client_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Provider_Service {
	private const MAX_PROMPT_BYTES = 90000;
	private const MAX_OUTPUT_BYTES = 80000;

	public function __construct( private Settings $settings ) {}

	public function status(): array {
		$settings = $this->settings();
		$provider = $this->provider();
		$key_status = $this->key_status();
		$wp_ai = new AI_Client_Service();

		return [
			'schema'              => 'kiwe.ai-provider.status.v1',
			'enabled'             => ! empty( $settings['studio_enabled'] ),
			'nativeGeneration'    => ! empty( $settings['allow_native_generation'] ),
			'provider'            => $provider,
			'model'               => $this->model(),
			'configured'          => 'wordpress_ai_client' === $provider ? $wp_ai->available() : 'ok' === $key_status['status'],
			'keyStatus'           => [
				'status'    => $key_status['status'],
				'stored'    => $key_status['stored'],
				'encrypted' => $key_status['encrypted'],
			],
			'wordpressAiClient'   => $wp_ai->summary(),
			'baseUrlConfigured'   => '' !== $this->base_url(),
			'maxNativeTokens'     => max( 200, absint( $settings['max_native_tokens'] ?? 1200 ) ),
			'maxContextBytes'     => max( 10000, absint( $settings['max_native_context_bytes'] ?? 60000 ) ),
			'supportedProviders'  => $this->providers(),
			'safety'              => [
				'readOnly'          => true,
				'noRuntimeActions'  => true,
				'noCheckoutAuthRun' => true,
				'noRawSecretEcho'   => true,
				'boundedPrompt'     => true,
			],
		];
	}

	public function generate( array $envelope ): array {
		$settings = $this->settings();
		$provider = $this->provider();
		$model = $this->model();

		if ( empty( $settings['studio_enabled'] ) || empty( $settings['allow_native_generation'] ) ) {
			return $this->not_called( 'native_generation_disabled', $provider, $model );
		}
		if ( 'none' === $provider ) {
			return $this->not_called( 'provider_none', $provider, $model );
		}
		if ( 'wordpress_ai_client' === $provider ) {
			$wp_ai = new AI_Client_Service();
			return [
				'ok'        => true,
				'called'    => false,
				'provider'  => $provider,
				'model'     => $model,
				'reason'    => $wp_ai->available() ? 'wordpress_ai_client_detected_but_not_invoked_by_adapter' : 'wordpress_ai_client_unavailable',
				'envelope'  => $this->public_envelope( $envelope ),
				'estimates' => $this->estimates( $envelope ),
			];
		}

		$key = $this->api_key();
		if ( '' === $key ) {
			return $this->not_called( 'missing_api_key', $provider, $model, $envelope );
		}
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return $this->not_called( 'wordpress_http_unavailable', $provider, $model, $envelope );
		}

		$payload = $this->payload( $provider, $model, $envelope );
		$request = $this->request( $provider, $model, $key, $payload );
		if ( empty( $request['ok'] ) ) {
			return $this->not_called( (string) ( $request['reason'] ?? 'request_not_prepared' ), $provider, $model, $envelope );
		}

		$response = wp_remote_post(
			(string) $request['url'],
			[
				'timeout' => 35,
				'headers' => $request['headers'],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return [
				'ok'        => false,
				'called'    => true,
				'provider'  => $provider,
				'model'     => $model,
				'error'     => [
					'code'    => 'http_error',
					'message' => $response->get_error_message(),
				],
				'estimates' => $this->estimates( $envelope ),
			];
		}

		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		$decoded = json_decode( $body, true );
		$text = $this->extract_text( $provider, is_array( $decoded ) ? $decoded : [] );
		$error = $this->extract_error( is_array( $decoded ) ? $decoded : [], $code, $text );

		$result = [
			'ok'           => $code >= 200 && $code < 300 && '' !== $text,
			'called'       => true,
			'provider'     => $provider,
			'model'        => $model,
			'httpStatus'   => $code,
			'output'       => $this->trim_bytes( $text, self::MAX_OUTPUT_BYTES ),
			'outputBytes'  => strlen( $text ),
			'estimates'    => $this->estimates( $envelope ),
			'responseMeta' => [
				'hasJson' => is_array( $decoded ),
			],
		];
		if ( [] !== $error ) {
			$result['error'] = $error;
		}

		return $result;
	}

	public function build_prompt( array $context, string $task, string $mode = 'combined' ): array {
		$settings = $this->settings();
		$system = implode(
			"\n",
			[
				'You are Kiwe Studio AI, a bounded WordPress/Bricks/Kiwe AppShell design assistant.',
				'Use the supplied Kiwe context packet only. Do not ask to read the whole repository.',
				'Keep Seam semantic/headless. Use custom page/theme CSS for visual art direction.',
				'Respect Kiwe authority boundaries: WordPress/Bricks own page content, Kiwe owns AppShell, WooCommerce owns money/cart/order runtime.',
				'Return concise, implementation-ready output. If asked for files, keep to the Kiwe output shape and avoid extra reports unless requested.',
			]
		);
		$user = [
			'task'       => $this->trim_bytes( $task, 12000 ),
			'mode'       => sanitize_key( $mode ),
			'context'    => $context,
			'budget'     => [
				'maxOutputTokens' => max( 200, absint( $settings['max_native_tokens'] ?? 1200 ) ),
				'tokenSaver'      => ! empty( $settings['token_saver_enabled'] ),
			],
		];

		$encoded_user = wp_json_encode( $user, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$encoded_user = false === $encoded_user ? '{}' : (string) $encoded_user;

		return [
			'system' => $system,
			'user'   => $this->trim_bytes( $encoded_user, self::MAX_PROMPT_BYTES ),
		];
	}

	private function request( string $provider, string $model, string $key, array $payload ): array {
		$headers = [ 'Content-Type' => 'application/json' ];
		if ( in_array( $provider, [ 'openai_compatible', 'groq', 'xai' ], true ) ) {
			$headers['Authorization'] = 'Bearer ' . $key;
			return [
				'ok'      => true,
				'url'     => $this->chat_completions_url( $provider ),
				'headers' => $headers,
			];
		}
		if ( 'gemini' === $provider ) {
			return [
				'ok'      => true,
				'url'     => 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key ),
				'headers' => $headers,
			];
		}

		return [ 'ok' => false, 'reason' => 'unsupported_provider' ];
	}

	private function payload( string $provider, string $model, array $envelope ): array {
		$settings = $this->settings();
		$max_tokens = max( 200, min( 12000, absint( $settings['max_native_tokens'] ?? 1200 ) ) );
		$system = $this->trim_bytes( (string) ( $envelope['system'] ?? '' ), 20000 );
		$user = $this->trim_bytes( (string) ( $envelope['user'] ?? '' ), max( 12000, absint( $settings['max_native_context_bytes'] ?? 60000 ) ) );

		if ( 'gemini' === $provider ) {
			return [
				'contents'         => [
					[
						'role'  => 'user',
						'parts' => [
							[ 'text' => $system . "\n\n" . $user ],
						],
					],
				],
				'generationConfig' => [
					'maxOutputTokens' => $max_tokens,
					'temperature'     => 0.35,
				],
			];
		}

		return [
			'model'       => $model,
			'messages'    => [
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user', 'content' => $user ],
			],
			'temperature' => 0.35,
			'max_tokens'  => $max_tokens,
		];
	}

	private function extract_text( string $provider, array $decoded ): string {
		if ( 'gemini' === $provider ) {
			$candidates = isset( $decoded['candidates'] ) && is_array( $decoded['candidates'] ) ? $decoded['candidates'] : [];
			$parts = isset( $candidates[0]['content']['parts'] ) && is_array( $candidates[0]['content']['parts'] ) ? $candidates[0]['content']['parts'] : [];
			$out = '';
			foreach ( $parts as $part ) {
				$out .= (string) ( is_array( $part ) ? ( $part['text'] ?? '' ) : '' );
			}
			return trim( $out );
		}

		return trim( (string) ( $decoded['choices'][0]['message']['content'] ?? $decoded['choices'][0]['text'] ?? '' ) );
	}

	private function extract_error( array $decoded, int $code, string $text ): array {
		if ( $code >= 200 && $code < 300 && '' !== $text ) {
			return [];
		}
		$error = isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : [];

		return [
			'code'    => sanitize_key( (string) ( $error['status'] ?? $error['code'] ?? ( $code > 0 ? 'http_' . $code : 'empty_output' ) ) ),
			'message' => sanitize_text_field( (string) ( $error['message'] ?? ( '' === $text ? 'Provider returned no text output.' : 'Provider request did not complete successfully.' ) ) ),
		];
	}

	private function chat_completions_url( string $provider ): string {
		$base = $this->base_url();
		if ( '' !== $base ) {
			return str_ends_with( $base, '/chat/completions' ) ? $base : rtrim( $base, '/' ) . '/chat/completions';
		}
		if ( 'groq' === $provider ) {
			return 'https://api.groq.com/openai/v1/chat/completions';
		}
		if ( 'xai' === $provider ) {
			return 'https://api.x.ai/v1/chat/completions';
		}

		return 'https://api.openai.com/v1/chat/completions';
	}

	private function public_envelope( array $envelope ): array {
		return [
			'systemBytes' => strlen( (string) ( $envelope['system'] ?? '' ) ),
			'userBytes'   => strlen( (string) ( $envelope['user'] ?? '' ) ),
			'userPreview' => $this->trim_bytes( (string) ( $envelope['user'] ?? '' ), 900 ),
		];
	}

	private function estimates( array $envelope ): array {
		$system = (string) ( $envelope['system'] ?? '' );
		$user = (string) ( $envelope['user'] ?? '' );

		return [
			'promptBytes'      => strlen( $system ) + strlen( $user ),
			'estimatedTokens'  => (int) ceil( ( strlen( $system ) + strlen( $user ) ) / 4 ),
			'maxOutputTokens'  => max( 200, absint( $this->settings()['max_native_tokens'] ?? 1200 ) ),
		];
	}

	private function not_called( string $reason, string $provider, string $model, array $envelope = [] ): array {
		return [
			'ok'        => true,
			'called'    => false,
			'provider'  => $provider,
			'model'     => $model,
			'reason'    => $reason,
			'envelope'  => [] === $envelope ? [] : $this->public_envelope( $envelope ),
			'estimates' => [] === $envelope ? [] : $this->estimates( $envelope ),
		];
	}

	private function api_key(): string {
		$stored = (string) ( $this->settings()['native_api_key'] ?? '' );
		return Secret_Store::decrypt( $stored );
	}

	private function key_status(): array {
		$stored = (string) ( $this->settings()['native_api_key'] ?? '' );
		$status = Secret_Store::decrypt_with_status( $stored );

		return [
			'status'    => (string) ( $status['status'] ?? 'empty' ),
			'stored'    => '' !== $stored,
			'encrypted' => '' !== $stored && Secret_Store::is_encrypted( $stored ),
		];
	}

	private function provider(): string {
		$provider = sanitize_key( (string) ( $this->settings()['native_provider'] ?? 'none' ) );
		return array_key_exists( $provider, $this->providers() ) ? $provider : 'none';
	}

	private function model(): string {
		$model = preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', (string) ( $this->settings()['native_model'] ?? '' ) );
		if ( '' !== $model ) {
			return preg_replace( '#^models/#', '', $model );
		}

		return match ( $this->provider() ) {
			'gemini'            => 'gemini-2.5-flash',
			'groq'              => 'llama-3.1-8b-instant',
			'xai'               => 'grok-3-mini',
			'openai_compatible' => 'gpt-4.1-mini',
			default             => '',
		};
	}

	private function base_url(): string {
		$url = trim( (string) ( $this->settings()['native_base_url'] ?? '' ) );
		return '' === $url ? '' : esc_url_raw( $url );
	}

	private function settings(): array {
		$defaults = $this->settings->defaults()['ai'] ?? [];
		$current = $this->settings->get( 'ai', [] );

		return array_replace_recursive( is_array( $defaults ) ? $defaults : [], is_array( $current ) ? $current : [] );
	}

	private function providers(): array {
		return [
			'none'                => 'None / deterministic only',
			'wordpress_ai_client' => 'WordPress AI Client',
			'openai_compatible'   => 'OpenAI-compatible chat completions',
			'gemini'              => 'Google Gemini',
			'groq'                => 'Groq API',
			'xai'                 => 'xAI Grok API',
		];
	}

	private function trim_bytes( string $value, int $limit ): string {
		$value = trim( $value );
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, max( 0, $limit - 28 ) ) . "\n...[trimmed by Kiwe]...";
	}
}
