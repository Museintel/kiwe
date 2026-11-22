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
	public const FEATURED_IMAGE_API = 1;
	public const IMAGE_MODEL = 'gemini-3.1-flash-image';
	public const IMAGE_RATIOS = [ '1:1', '4:3', '3:2', '16:9' ];
	public const IMAGE_MIMES = [ 'image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp' ];
	public const MAX_REFERENCE_BYTES = 2097152;
	public const MAX_REFERENCE_TOTAL = 6291456;
	private const MAX_IMAGE_OUTPUT_BYTES = 4194304;

	public function __construct( private Settings $settings ) {}

	public function status(): array {
		$settings = $this->settings();
		$provider = $this->provider();
		$key_status = $this->key_status();
		$wp_ai = new AI_Client_Service();

		return [
			'schema'              => 'kiwe.ai-provider.status.v1',
			'enabled'             => ! empty( $settings['allow_native_generation'] ),
			'nativeGeneration'    => ! empty( $settings['allow_native_generation'] ),
			'provider'            => $provider,
			'model'               => $this->model(),
			'configured'          => 'wordpress_ai_client' === $provider ? $wp_ai->text_generation_available() : 'ok' === $key_status['status'],
			'keyStatus'           => [
				'status'    => $key_status['status'],
				'stored'    => $key_status['stored'],
				'encrypted' => $key_status['encrypted'],
			],
			'wordpressAiClient'   => $wp_ai->summary(),
			'baseUrlConfigured'   => '' !== $this->base_url(),
			'maxNativeTokens'     => max( 200, absint( $settings['max_native_tokens'] ?? 3000 ) ),
			'maxContextBytes'     => max( 10000, absint( $settings['max_native_context_bytes'] ?? 60000 ) ),
			'allowance'           => ! empty( $settings['allow_native_generation'] ) ? ( new AI_Allowance_Service( $this->settings ) )->status() : [ 'enabled'=>false ],
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
		if ( ! \DSA\Apps\AI_App_Service::enabled() ) return $this->not_called( 'ai_app_unavailable',$provider,$model );

		if ( empty( $settings['allow_native_generation'] ) ) {
			return $this->not_called( 'native_generation_disabled', $provider, $model );
		}
		if ( 'none' === $provider ) {
			return $this->not_called( 'provider_none', $provider, $model );
		}
		if ( '' === trim( (string) ( $envelope['user'] ?? '' ) ) ) return $this->not_called( 'empty_prompt', $provider, $model );
		if ( strlen( (string) ( $envelope['system'] ?? '' ) ) + strlen( (string) $envelope['user'] ) > self::MAX_PROMPT_BYTES ) return $this->allowance_blocked( [ 'reason'=>'prompt_budget_exceeded' ], $provider, $model );
		$allowance = new AI_Allowance_Service( $this->settings );
		$max_output = max( 200, min( 12000, absint( $settings['max_native_tokens'] ?? 3000 ) ) );
		if ( 'wordpress_ai_client' === $provider ) {
			$wp_ai = new AI_Client_Service();
			if ( ! $wp_ai->text_generation_available() ) return $this->not_called( 'wordpress_ai_client_unavailable', $provider, $model );
			$estimate = AI_Usage_Service::estimate( $envelope, $max_output );
			if ( ! $estimate ) return $this->allowance_blocked( [ 'reason'=>'site_ai_token_estimate_invalid' ], $provider, $model );
			$reservation = $allowance->reserve( $estimate );
			if ( empty( $reservation['ok'] ) ) return $this->allowance_blocked( $reservation, $provider, $model );
			$result = $wp_ai->generate( $envelope, $max_output, $model );
			// WordPress's adapter returns text without certified usage/underlying attempt counts.
			$settled = $allowance->settle( $reservation['ticket'], null );
			if ( empty( $settled['ok'] ) ) return $this->allowance_blocked( $settled, $provider, $model, 1 );
			$result['tokenAccounting'] = $settled;
			$result['envelope']  = $this->public_envelope( $envelope );
			$result['estimates'] = $this->estimates( $envelope );
			return $result;
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
		$estimate = AI_Usage_Service::estimate( $payload, $max_output );
		if ( ! $estimate ) return $this->allowance_blocked( [ 'reason'=>'site_ai_token_estimate_invalid' ], $provider, $model );

		$attempts = 0;
		$usage = null;
		do {
			$reservation = $allowance->reserve( $estimate );
			if ( empty( $reservation['ok'] ) ) return $this->allowance_blocked( $reservation, $provider, $model, $attempts );
			++$attempts;
			$response = wp_remote_post(
				(string) $request['url'],
				[
					'timeout' => 35,
					'headers' => $request['headers'],
					'body'    => wp_json_encode( $payload ),
				]
			);
			$http_error = function_exists( 'is_wp_error' ) && is_wp_error( $response );
			$status = ! $http_error && function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
			$decoded_usage = ! $http_error && $status >= 200 && $status < 300 ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
			$usage = is_array( $decoded_usage ) && empty( $decoded_usage['error'] ) ? AI_Usage_Service::reported( $provider, $model, $decoded_usage ) : null;
			$settled = $allowance->settle( $reservation['ticket'], $usage['total'] ?? null );
			if ( empty( $settled['ok'] ) ) return $this->allowance_blocked( $settled, $provider, $model, $attempts );
			if ( $http_error ) break;
			if ( 429 === $status ) {
				$hold = $allowance->cooldown( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
				return $this->allowance_blocked( $hold, $provider, $model, $attempts ) + [ 'httpStatus'=>429 ];
			}
			if ( 1 === $attempts && in_array( $status, [ 500, 502, 503, 504 ], true ) ) {
				// A single short retry absorbs provider capacity spikes without creating
				// an unbounded shared-host request or retrying quota/auth failures.
				usleep( 750000 );
				continue;
			}
			break;
		} while ( $attempts < 2 );

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
				'tokenAccounting' => $settled,
				'responseMeta' => [ 'attempts' => $attempts ],
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
			'usage'        => $usage,
			'tokenAccounting' => $settled,
			'responseMeta' => [
				'hasJson'  => is_array( $decoded ),
				'attempts' => $attempts,
			],
		];
		if ( [] !== $error ) {
			$result['error'] = $error;
		}

		return $result;
	}

	private function allowance_blocked( array $reservation, string $provider, string $model, int $attempts = 0 ): array {
		$reason = (string) ( $reservation['reason'] ?? 'site_ai_storage_unavailable' );
		$message = 'site_ai_token_request_too_large' === $reason
			? __( 'This request cannot fit the website token allowance. Use a smaller batch or ask your website manager to review the token limits. A zero token limit pauses generation.', 'dsa' )
			: __( 'AI requests are paused by the website allowance or provider cooldown. Review the shared AI allowance before trying again.', 'dsa' );
		return [ 'ok'=>false, 'called'=>$attempts > 0, 'provider'=>$provider, 'model'=>$model, 'reason'=>$reason, 'retryAt'=>(int) ( $reservation['retryAt'] ?? 0 ), 'responseMeta'=>[ 'attempts'=>$attempts ], 'error'=>[ 'code'=>$reason, 'message'=>$message ] ];
	}

	public function build_prompt( array $context,string $task,string $mode = 'combined' ): array { return ( new AI_Prompt_Service( $this->settings ) )->build_prompt( $context,$task,$mode ); }

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
			$headers['x-goog-api-key'] = $key;
			return [
				'ok'      => true,
				'url'     => str_starts_with( $model, 'gemini-3' )
					? 'https://generativelanguage.googleapis.com/v1/interactions'
					: 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
				'headers' => $headers,
			];
		}

		return [ 'ok' => false, 'reason' => 'unsupported_provider' ];
	}

	private function payload( string $provider, string $model, array $envelope ): array {
		$settings = $this->settings();
		$max_tokens = max( 200, min( 12000, absint( $settings['max_native_tokens'] ?? 3000 ) ) );
		$system = $this->trim_bytes( (string) ( $envelope['system'] ?? '' ), 20000 );
		$user = $this->trim_bytes( (string) ( $envelope['user'] ?? '' ), max( 12000, absint( $settings['max_native_context_bytes'] ?? 60000 ) ) );

		if ( 'gemini' === $provider ) {
			if ( str_starts_with( $model, 'gemini-3' ) ) {
				$payload = [
					'model' => $model,
					'input' => $system . "\n\n" . $user,
					'store' => false,
					'generation_config' => [
						'max_output_tokens' => $max_tokens,
						'thinking_level' => in_array( (string) ( $envelope['thinkingLevel'] ?? '' ), [ 'low','medium','high' ], true ) ? (string) $envelope['thinkingLevel'] : 'low',
					],
				];
				if ( 'application/json' === ( $envelope['responseMimeType'] ?? '' ) ) {
					$format = [ 'type'=>'text', 'mime_type'=>'application/json' ];
					if ( is_array( $envelope['responseSchema'] ?? null ) && [] !== $envelope['responseSchema'] ) {
						$format['schema'] = $envelope['responseSchema'];
					}
					$payload['response_format'] = [ $format ];
				}
				return $payload;
			}
			$config = [ 'maxOutputTokens' => $max_tokens ];
			$config['temperature'] = 0.35;
			if ( 'application/json' === ( $envelope['responseMimeType'] ?? '' ) ) {
				$config['responseMimeType'] = 'application/json';
				if ( is_array( $envelope['responseSchema'] ?? null ) && [] !== $envelope['responseSchema'] ) {
					$config['responseJsonSchema'] = $envelope['responseSchema'];
				}
			}
			return [
				'contents'         => [
					[
						'role'  => 'user',
						'parts' => [
							[ 'text' => $system . "\n\n" . $user ],
						],
					],
				],
				'generationConfig' => $config,
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
			if ( isset( $decoded['steps'] ) && is_array( $decoded['steps'] ) ) {
				$out = '';
				foreach ( $decoded['steps'] as $step ) {
					if ( ! is_array( $step ) || 'model_output' !== (string) ( $step['type'] ?? '' ) ) continue;
					foreach ( is_array( $step['content'] ?? null ) ? $step['content'] : [] as $content ) {
						if ( is_array( $content ) && 'text' === (string) ( $content['type'] ?? '' ) ) $out .= (string) ( $content['text'] ?? '' );
					}
				}
				return trim( $out );
			}
			$candidates = isset( $decoded['candidates'] ) && is_array( $decoded['candidates'] ) ? $decoded['candidates'] : [];
			$parts = isset( $candidates[0]['content']['parts'] ) && is_array( $candidates[0]['content']['parts'] ) ? $candidates[0]['content']['parts'] : [];
			$out = '';
			foreach ( $parts as $part ) {
				if ( ! is_array( $part ) || ! empty( $part['thought'] ) ) continue;
				$out .= (string) ( $part['text'] ?? '' );
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
			'maxOutputTokens'  => max( 200, absint( $this->settings()['max_native_tokens'] ?? 3000 ) ),
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

	/** Featured images use the website's existing provider account and allowance. */
	public function image_status(): array {
		$ai = $this->settings();
		$reason = match ( true ) {
			! \DSA\Apps\AI_App_Service::enabled() => 'ai_app_unavailable',
			empty( $ai['allow_native_generation'] ) => 'native_generation_disabled',
			'gemini' !== $this->provider() => 'image_provider_required',
			'' === $this->api_key() => 'missing_api_key',
			default => '',
		};
		return [
			'ready' => '' === $reason,
			'reason' => $reason,
			'model' => self::IMAGE_MODEL,
			'requiresPaidProject' => true,
			'providerQuotaVerified' => false,
			'configurationPin' => hash_hmac( 'sha256', wp_json_encode( [ $reason, (string) ( $ai['native_api_key'] ?? '' ) ] ), wp_salt( 'auth' ) ),
		];
	}

	/** One reviewed request. Timeouts and ambiguous responses never trigger an automatic retry. */
	public function generate_featured_image( string $prompt, array $images, string $ratio, string $configuration_pin ): array|\WP_Error {
		$status = $this->image_status();
		if ( ! $status['ready'] || ! hash_equals( $status['configurationPin'], $configuration_pin ) ) {
			throw new \RuntimeException( 'The website image provider changed. Prepare a fresh image review.' );
		}
		if ( '' === trim( $prompt ) || strlen( $prompt ) > 6000 || ! in_array( $ratio, self::IMAGE_RATIOS, true ) || count( $images ) < 1 || count( $images ) > 5 ) {
			throw new \RuntimeException( 'Choose a supported image size and a bounded set of reference photos.' );
		}
		$parts = [ [ 'text'=>$prompt ] ];
		$total_bytes = 0;
		foreach ( $images as $image ) {
			$bytes = $image['bytes'] ?? null;
			$mime = $image['mime'] ?? '';
			$role = $image['role'] ?? '';
			if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > self::MAX_REFERENCE_BYTES ||
				! is_string( $mime ) || ! isset( self::IMAGE_MIMES[ $mime ] ) || ! in_array( $role, [ 'subject', 'style' ], true ) ) {
				throw new \RuntimeException( 'A selected image exceeds the provider input limits.' );
			}
			$total_bytes += strlen( $bytes );
			if ( $total_bytes > self::MAX_REFERENCE_TOTAL ) {
				throw new \RuntimeException( 'The selected photos are too large together. Choose fewer or smaller photos.' );
			}
			$parts[] = [ 'text'=>'subject' === $role ? 'Subject photo. Preserve what it shows.' : 'Website style reference. Use styling only.' ];
			$parts[] = [ 'inlineData'=>[ 'mimeType'=>$mime, 'data'=>base64_encode( $bytes ) ] ];
		}
		$body = wp_json_encode( [
			'contents' => [ [ 'role'=>'user', 'parts'=>$parts ] ],
			'generationConfig' => [
				'responseModalities' => [ 'TEXT', 'IMAGE' ],
				'responseFormat' => [ 'image'=>[ 'aspectRatio'=>$ratio, 'imageSize'=>'1K' ] ],
			],
		] );
		if ( ! is_string( $body ) ) throw new \RuntimeException( 'The selected photos could not be prepared.' );

		$allowance = new AI_Allowance_Service( $this->settings );
		$reservation = $allowance->reserve( 12000 + 1500 * count( $images ) );
		if ( empty( $reservation['ok'] ) ) {
			return self::featured_image_failure( 'kiwe_image_site_allowance', false );
		}
		$decoded = null;
		$failure = null;
		$settled = [];
		try {
			$response = wp_remote_post( 'https://generativelanguage.googleapis.com/v1/models/' . self::IMAGE_MODEL . ':generateContent', [
				'timeout' => 40,
				'redirection' => 0,
				'limit_response_size' => 6291456,
				'headers' => [ 'Content-Type'=>'application/json', 'x-goog-api-key'=>$this->api_key() ],
				'body' => $body,
			] );
			if ( is_wp_error( $response ) ) {
				$failure = self::featured_image_failure( 'kiwe_image_transport', true );
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$raw = wp_remote_retrieve_body( $response );
				if ( $code >= 200 && $code < 300 ) {
					$decoded = json_decode( $raw, true, 24 );
				} else {
					$failure = self::featured_image_http_failure( $code, $raw );
				}
				if ( 429 === $code ) {
					$allowance->cooldown( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
				}
			}
		} finally {
			$usage = is_array( $decoded ) ? AI_Usage_Service::reported( 'gemini', self::IMAGE_MODEL, $decoded, false ) : null;
			$settled = $allowance->settle( $reservation['ticket'], $usage['total'] ?? null );
		}
		if ( empty( $settled['ok'] ) ) return self::featured_image_failure( 'kiwe_image_usage_unconfirmed', true );
		if ( $failure ) return $failure;
		if ( ! is_array( $decoded ) ) return self::featured_image_failure( 'kiwe_image_invalid_response', true );
		try { return $this->featured_image_result( $decoded, $ratio ); }
		catch ( \RuntimeException ) { return self::featured_image_failure( 'kiwe_image_invalid_response', true ); }
	}

	/** Return known statuses only. Google may include project IDs or request details in its error body. */
	private static function featured_image_http_failure( int $status, string $body ): \WP_Error {
		$error = strlen( $body ) <= 65536 ? json_decode( $body, true, 16 ) : null;
		$details = $error['error']['details'] ?? [];
		foreach ( is_array( $details ) ? $details : [] as $detail ) {
			if ( is_array( $detail ) && in_array( $detail['reason'] ?? '', [ 'BILLING_DISABLED', 'BILLING_NOT_ENABLED', 'BILLING_REQUIRED' ], true ) ) {
				return self::featured_image_failure( 'kiwe_image_billing', true, $status );
			}
		}
		return self::featured_image_failure( match ( $status ) {
			400 => 'kiwe_image_request_rejected',
			401, 403 => 'kiwe_image_access',
			404 => 'kiwe_image_model_unavailable',
			429 => 'kiwe_image_quota',
			default => 'kiwe_image_provider_unavailable',
		}, true, $status );
	}

	private static function featured_image_failure( string $code, bool $attempted, int $status = 0 ): \WP_Error {
		return new \WP_Error( $code, '', [ 'attempted'=>$attempted, 'httpStatus'=>$status >= 100 && $status <= 599 ? $status : 0 ] );
	}

	private function featured_image_result( array $response, string $ratio ): array {
		$candidate = $response['candidates'][0] ?? [];
		$image = null;
		$notes = [];
		$parts = $candidate['content']['parts'] ?? null;
		if ( 'STOP' !== ( $candidate['finishReason'] ?? '' ) || ! is_array( $parts ) ) {
			throw new \RuntimeException( 'The provider did not return a complete image.' );
		}
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) || ! empty( $part['thought'] ) ) continue;
			if ( is_string( $part['text'] ?? null ) && count( $notes ) < 2 ) {
				$notes[] = sanitize_textarea_field( $this->trim_bytes( $part['text'], 500 ) );
			}
			$inline = $part['inlineData'] ?? null;
			if ( ! is_array( $inline ) ) continue;
			$mime = $inline['mimeType'] ?? '';
			$data = $inline['data'] ?? null;
			if ( null !== $image || ! is_string( $mime ) || ! isset( self::IMAGE_MIMES[ $mime ] ) ||
				! is_string( $data ) || strlen( $data ) > 4 * (int) ceil( self::MAX_IMAGE_OUTPUT_BYTES / 3 ) ) {
				throw new \RuntimeException( 'The provider did not return one supported image.' );
			}
			$bytes = base64_decode( $data, true );
			$geometry = false !== $bytes && strlen( $bytes ) <= self::MAX_IMAGE_OUTPUT_BYTES ? @getimagesizefromstring( $bytes ) : false;
			[ $horizontal, $vertical ] = array_map( 'intval', explode( ':', $ratio ) );
			if ( ! $geometry || $geometry['mime'] !== $mime || min( $geometry[0], $geometry[1] ) < 320 ||
				max( $geometry[0], $geometry[1] ) > 2048 || abs( $geometry[0] / $geometry[1] - $horizontal / $vertical ) > 0.06 ) {
				throw new \RuntimeException( 'The image returned in an unexpected format or framing. Review the provider result.' );
			}
			$image = [ 'bytes'=>$bytes, 'mime'=>$mime, 'width'=>$geometry[0], 'height'=>$geometry[1] ];
		}
		if ( null === $image ) throw new \RuntimeException( 'The provider did not return a usable image.' );
		return $image + [ 'observations'=>implode( "\n", $notes ) ];
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
			'gemini'            => 'gemini-3.7-flash',
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
