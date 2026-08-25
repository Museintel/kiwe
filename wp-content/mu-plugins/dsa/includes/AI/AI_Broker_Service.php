<?php

namespace DSA\AI;

use DSA\Settings;
use DSA\Utilities\Atomic_Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared, stateless AI control plane for Kiwe and approved integrations.
 *
 * Provider credentials are configured once. Consumers never receive them and
 * cannot inherit another consumer's prompt, memory, data scope, or authority.
 */
final class AI_Broker_Service {
	private const AUDIT_OPTION = 'dsa_ai_broker_audit_v1';
	private const AUDIT_LIMIT  = 100;

	public function __construct(
		private Settings $settings,
		private ?AI_Provider_Service $transport = null
	) {
		$this->transport = $this->transport ?: new AI_Provider_Service( $settings );
	}

	public function status( string $service = '' ): array {
		$profiles = $this->profiles();
		$profile  = '' !== $service && isset( $profiles[ $service ] ) ? $profiles[ $service ] : null;

		return [
			'schema'        => 'kiwe.ai-broker.status.v1',
			'provider'      => $this->transport->status(),
			'service'       => '' !== $service ? $service : null,
			'profile'       => $profile,
			'supported'     => null !== $profile,
			'stateless'     => true,
			'sharedMemory'  => false,
			'secretAccess'  => false,
			'serviceIds'    => array_keys( $profiles ),
			'extensionHook' => 'kiwe_ai_broker_profiles',
		];
	}

	/**
	 * Execute one isolated request.
	 *
	 * Required: service, capability, user. Conversation history and arbitrary
	 * provider options are intentionally not accepted.
	 */
	public function request( array $request ): array {
		$service    = sanitize_key( (string) ( $request['service'] ?? '' ) );
		$capability = sanitize_key( (string) ( $request['capability'] ?? '' ) );
		$operation  = sanitize_key( (string) ( $request['operation'] ?? $capability ) );
		$profiles   = $this->profiles();
		$profile    = $profiles[ $service ] ?? null;
		$started    = microtime( true );
		$correlation_id = $this->correlation_id();

		if ( ! is_array( $profile ) ) {
			return $this->blocked( 'unknown_service', $service, $capability, $correlation_id );
		}
		if ( empty( $profile['enabled'] ) ) {
			return $this->blocked( 'service_disabled', $service, $capability, $correlation_id );
		}
		if ( ! in_array( $capability, (array) ( $profile['capabilities'] ?? [] ), true ) ) {
			return $this->blocked( 'capability_not_allowed', $service, $capability, $correlation_id );
		}
		if ( isset( $request['history'] ) || isset( $request['messages'] ) || isset( $request['memory'] ) ) {
			return $this->blocked( 'shared_or_implicit_memory_forbidden', $service, $capability, $correlation_id );
		}

		$system = trim( (string) ( $request['system'] ?? '' ) );
		$user   = trim( (string) ( $request['user'] ?? '' ) );
		$max_bytes = max( 1000, absint( $profile['maxPromptBytes'] ?? 40000 ) );
		if ( '' === $user ) {
			return $this->blocked( 'empty_prompt', $service, $capability, $correlation_id );
		}
		if ( strlen( $system ) + strlen( $user ) > $max_bytes ) {
			return $this->blocked( 'prompt_budget_exceeded', $service, $capability, $correlation_id );
		}

		$limit = max( 1, absint( $profile['callsPerMinute'] ?? 6 ) );
		if ( ! Atomic_Rate_Limiter::allow( 'kiwe-ai-broker:' . $service, $limit, 60 ) ) {
			return $this->blocked( 'service_rate_limited', $service, $capability, $correlation_id );
		}

		$envelope = [
			'system' => $this->service_instruction( $service, $capability, $profile ) . "\n\n" . $system,
			'user'   => $user,
		];
		$result = $this->transport->generate( $envelope );
		$result['schema']        = 'kiwe.ai-broker.result.v1';
		$result['service']       = $service;
		$result['capability']    = $capability;
		$result['operation']     = $operation;
		$result['correlationId'] = $correlation_id;
		$result['stateless']     = true;

		$validation = $this->validate_output( (string) ( $result['output'] ?? '' ), $profile );
		$result['validation'] = $validation;
		if ( ! empty( $result['called'] ) && empty( $validation['valid'] ) ) {
			$result['ok'] = false;
			$result['error'] = [
				'code'    => 'output_contract_failed',
				'message' => (string) ( $validation['message'] ?? 'AI output failed its service contract.' ),
			];
		}

		$this->audit(
			[
				'correlationId' => $correlation_id,
				'service'       => $service,
				'capability'    => $capability,
				'operation'     => $operation,
				'promptBytes'   => strlen( $system ) + strlen( $user ),
				'promptHash'    => hash( 'sha256', $system . "\n" . $user ),
				'called'        => ! empty( $result['called'] ),
				'ok'            => ! empty( $result['ok'] ),
				'reason'        => sanitize_key( (string) ( $result['reason'] ?? $result['error']['code'] ?? '' ) ),
				'durationMs'    => (int) round( ( microtime( true ) - $started ) * 1000 ),
			]
		);

		return $result;
	}

	/** Compatibility surface for Studio AI. */
	public function generate( array $envelope ): array {
		return $this->request(
			[
				'service'    => 'studio',
				'capability' => 'draft',
				'operation'  => 'studio_draft',
				'system'     => (string) ( $envelope['system'] ?? '' ),
				'user'       => (string) ( $envelope['user'] ?? '' ),
			]
		);
	}

	public function build_prompt( array $context, string $task, string $mode = 'combined' ): array {
		return $this->transport->build_prompt( $context, $task, $mode );
	}

	private function profiles(): array {
		$settings = $this->ai_settings();
		$profiles = [
			'studio' => [
				'enabled'        => ! empty( $settings['studio_enabled'] ),
				'capabilities'   => [ 'draft' ],
				'dataBoundary'   => 'approved_context_packet',
				'maxPromptBytes' => max( 10000, absint( $settings['max_native_context_bytes'] ?? 60000 ) ) + 20000,
				'callsPerMinute' => 10,
				'output'         => 'text',
			],
			'sitegraph' => [
				'enabled'        => ! empty( $settings['sitegraph_ai_enabled'] ),
				'capabilities'   => [ 'summarize', 'recommend_bindings' ],
				'dataBoundary'   => 'redacted_site_graph_only',
				'maxPromptBytes' => 60000,
				'callsPerMinute' => 8,
				'output'         => 'json',
			],
			'design_context' => [
				'enabled'        => ! empty( $settings['sitegraph_ai_enabled'] ),
				'capabilities'   => [ 'refine' ],
				'dataBoundary'   => 'administrator_approved_owner_context_editorial_fields_only',
				'maxPromptBytes' => 60000,
				'callsPerMinute' => 6,
				'output'         => 'json',
			],
			'seo' => [
				'enabled'        => ! empty( $settings['sitegraph_ai_enabled'] ),
				'capabilities'   => [ 'propose_batch' ],
				'dataBoundary'   => 'five_public_content_records_without_visitor_or_order_data',
				'maxPromptBytes' => 50000,
				'callsPerMinute' => 4,
				'output'         => 'json',
			],
			'securetrack' => [
				'enabled'        => ! empty( $settings['securetrack_brief_enabled'] ),
				'capabilities'   => [ 'classify_security' ],
				'dataBoundary'   => 'hashed_security_packet_only',
				'maxPromptBytes' => 12000,
				'callsPerMinute' => 30,
				'output'         => 'securetrack_review_json',
			],
			'bricks' => [
				'enabled'        => ! empty( $settings['bricks_editor_companion_enabled'] ),
				'capabilities'   => [ 'draft', 'explain' ],
				'dataBoundary'   => 'current_user_approved_builder_context',
				'maxPromptBytes' => 80000,
				'callsPerMinute' => 12,
				'output'         => 'text',
			],
		];

		/**
		 * Third-party services may register a least-privilege profile. They receive
		 * broker results, never provider credentials. Unknown capabilities fail closed.
		 */
		$profiles = apply_filters( 'kiwe_ai_broker_profiles', $profiles );
		return is_array( $profiles ) ? $profiles : [];
	}

	private function service_instruction( string $service, string $capability, array $profile ): string {
		return sprintf(
			'Kiwe isolated service request. Service=%1$s; capability=%2$s; data-boundary=%3$s. Use only the supplied request. Do not infer another Kiwe service context, prior conversation, site secret, visitor, order, payment, authentication, or runtime authority. Return only the requested output contract.',
			$service,
			$capability,
			sanitize_key( (string) ( $profile['dataBoundary'] ?? 'none' ) )
		);
	}

	private function validate_output( string $output, array $profile ): array {
		$type = sanitize_key( (string) ( $profile['output'] ?? 'text' ) );
		if ( 'text' === $type ) {
			return [ 'valid' => '' !== trim( $output ), 'type' => 'text', 'message' => '' !== trim( $output ) ? '' : 'Text output is empty.' ];
		}

		$decoded = $this->decode_json( $output );
		if ( ! is_array( $decoded ) ) {
			return [ 'valid' => false, 'type' => $type, 'message' => 'Expected a JSON object.' ];
		}
		if ( 'securetrack_review_json' === $type ) {
			$label = sanitize_key( (string) ( $decoded['label'] ?? '' ) );
			$valid = isset( $decoded['score'] ) && is_numeric( $decoded['score'] ) && in_array( $label, [ 'clean', 'protected', 'suspicious', 'critical' ], true );
			return [ 'valid' => $valid, 'type' => $type, 'message' => $valid ? '' : 'Security review JSON must contain score and an allowed label.', 'data' => $valid ? $decoded : null ];
		}

		return [ 'valid' => true, 'type' => 'json', 'message' => '', 'data' => $decoded ];
	}

	private function decode_json( string $output ): ?array {
		$output = trim( $output );
		$output = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $output );
		$decoded = json_decode( (string) $output, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function blocked( string $reason, string $service, string $capability, string $correlation_id ): array {
		return [
			'ok'            => false,
			'called'        => false,
			'schema'        => 'kiwe.ai-broker.result.v1',
			'reason'        => $reason,
			'service'       => $service,
			'capability'    => $capability,
			'correlationId' => $correlation_id,
			'stateless'     => true,
		];
	}

	private function audit( array $record ): void {
		$records = get_option( self::AUDIT_OPTION, [] );
		$records = is_array( $records ) ? $records : [];
		$record['at'] = gmdate( 'c' );
		$records[] = $record;
		if ( count( $records ) > self::AUDIT_LIMIT ) {
			$records = array_slice( $records, -self::AUDIT_LIMIT );
		}
		update_option( self::AUDIT_OPTION, $records, false );
	}

	private function ai_settings(): array {
		$defaults = $this->settings->defaults()['ai'] ?? [];
		$current  = $this->settings->get( 'ai', [] );
		return array_replace_recursive( is_array( $defaults ) ? $defaults : [], is_array( $current ) ? $current : [] );
	}

	private function correlation_id(): string {
		return 'kiwe_' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) ), 0, 20 );
	}
}
