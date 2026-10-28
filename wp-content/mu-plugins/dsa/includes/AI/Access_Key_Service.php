<?php

namespace DSA\AI;

use DSA\Utilities\Credential_Records;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Access_Key_Service {
	private const OPTION = 'dsa_ai_access_keys';
	private const PREFIX = 'kiwe_ai_';
	private const MAX_KEYS = 24;

	public const SCOPES = [
		'all',
		'site_graph',
		'site_graph_data',
		'validate_bindings',
		'validate_bricks_conversion',
		'validate_accessibility',
		'seamflow',
		'prepare_apply_plan',
		'stage_apply_plan',
		'trusted_apply_chain',
		'themes',
		'site_inspection',
		'security_brief',
		'internal_ai',
		'companion',
		'companion_securetrack',
		'studio_ai',
		'native_ai',
		'bricks_ai',
		'staging_execute',
		'controlled_mutation',
	];

	public function create( string $label, array $scopes, array $context = [] ): array {
		$label      = $this->sanitize_label( $label );
		$scopes     = $this->normalize_scopes( $scopes );
		$id         = 'key_' . substr( hash( 'sha256', $label . '|' . wp_generate_password( 24, true, true ) . '|' . microtime( true ) ), 0, 12 );
		$secret     = wp_generate_password( 40, false, false );
		$plain      = self::PREFIX . $id . '_' . $secret;
		$created_at = (string) ( $context['createdAt'] ?? gmdate( 'c' ) );
		$user_id    = isset( $context['userId'] ) ? absint( $context['userId'] ) : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$record = [
			'id'          => $id,
			'label'       => '' !== $label ? $label : __( 'Kiwe AI key', 'dsa' ),
			'prefix'      => substr( $plain, 0, 22 ),
			'last4'       => substr( $plain, -4 ),
			'hash'        => wp_hash_password( $plain ),
			'scopes'      => $scopes,
			'createdAt'   => $created_at,
			'createdBy'   => $user_id,
			'lastUsedAt'  => '',
			'lastUsedIp'  => '',
			'revokedAt'   => '',
			'revokedBy'   => 0,
		];
		$result = Credential_Records::change( self::OPTION, function ( array $stored ) use ( $record, $plain ): array {
			$records = $this->records( $stored );
			array_unshift( $records, $record );
			return [
				'records' => array_slice( $this->dedupe( $records ), 0, self::MAX_KEYS ),
				'value' => [ 'key' => $plain, 'record' => $this->public_record( $record ) ],
			];
		} );
		if ( ! $result['ok'] ) throw new \RuntimeException( 'Kiwe could not confirm that the API key was saved. Refresh the key list before trying again.' );
		return $result['value'];
	}

	public function revoke( string $id, int $user_id = 0 ): bool {
		$id = sanitize_key( $id );
		if ( '' === $id ) return false;
		$result = Credential_Records::change( self::OPTION, function ( array $stored ) use ( $id, $user_id ): array {
			$records = $this->records( $stored );
			$matched = false;
			foreach ( $records as &$record ) {
				if ( $id !== (string) ( $record['id'] ?? '' ) ) continue;
				if ( '' === (string) ( $record['revokedAt'] ?? '' ) ) {
					$record['revokedAt'] = gmdate( 'c' );
					$record['revokedBy'] = $user_id;
				}
				$matched = true;
			}
			unset( $record );
			return $matched ? [ 'records' => $records, 'value' => true ] : [ 'value' => false ];
		} );
		return $result['ok'] && true === $result['value'];
	}

	public function public_records(): array {
		$result = Credential_Records::change( self::OPTION, fn( array $stored ): array => [ 'value' => array_map( [ $this, 'public_record' ], $this->records( $stored ) ) ] );
		return $result['ok'] ? $result['value'] : [];
	}

	public function authenticate_request( WP_REST_Request $request, string $required_scope ): array {
		$key = $this->request_key( $request );
		if ( '' === $key ) {
			return $this->auth_failure( 'missing_key', 'Missing Kiwe AI API key.' );
		}
		if ( ! str_starts_with( $key, self::PREFIX ) ) {
			return $this->auth_failure( 'invalid_prefix', 'Invalid Kiwe AI API key prefix.' );
		}
		$result = Credential_Records::change( self::OPTION, function ( array $stored ) use ( $key, $required_scope ): array {
			$records = $this->records( $stored );
			foreach ( $records as &$record ) {
				if ( ! is_array( $record ) || '' !== (string) ( $record['revokedAt'] ?? '' ) ) {
					continue;
				}
				$hash = (string) ( $record['hash'] ?? '' );
				if ( '' === $hash || ! wp_check_password( $key, $hash ) ) {
					continue;
				}
				$scopes = $this->normalize_scopes( isset( $record['scopes'] ) && is_array( $record['scopes'] ) ? $record['scopes'] : [] );
				if ( 'status' !== $required_scope && ! $this->scope_satisfied( $required_scope, $scopes ) ) {
					return [ 'value' => $this->auth_failure( 'scope_denied', 'Kiwe AI API key does not include the required scope.' ) ];
				}
				$record['lastUsedAt'] = gmdate( 'c' );
				$record['lastUsedIp'] = $this->client_ip();

				return [ 'records' => $records, 'value' => [
					'ok'     => true,
					'record' => $this->public_record( $record ),
					'scope'  => $required_scope,
				] ];
			}
			unset( $record );
			return [ 'value' => $this->auth_failure( 'invalid_key', 'Kiwe AI API key was not found or has been revoked.' ) ];
		} );
		return $result['ok'] ? $result['value'] : $this->auth_failure( $result['code'], 'Kiwe could not confirm the current API key state. Try again shortly.', 503 );
	}

	public function normalize_scopes( array $scopes ): array {
		$out = [];
		foreach ( $scopes as $scope ) {
			$scope = sanitize_key( (string) $scope );
			if ( in_array( $scope, self::SCOPES, true ) ) {
				$out[] = $scope;
			}
		}
		if ( [] === $out ) {
			$out[] = 'site_graph';
		}
		if ( in_array( 'all', $out, true ) ) {
			return [ 'all' ];
		}

		return array_values( array_unique( $out ) );
	}

	private function scope_satisfied( string $required_scope, array $scopes ): bool {
		if ( in_array( 'all', $scopes, true ) || in_array( $required_scope, $scopes, true ) ) {
			return true;
		}
		if ( 'security_brief' === $required_scope && in_array( 'companion_securetrack', $scopes, true ) ) {
			return true;
		}
		if ( 'bricks_ai' === $required_scope && in_array( 'studio_ai', $scopes, true ) ) {
			return true;
		}
		if ( 'seamflow' === $required_scope && ( in_array( 'studio_ai', $scopes, true ) || in_array( 'bricks_ai', $scopes, true ) ) ) {
			return true;
		}

		return false;
	}

	private function records( array $records ): array {
		return array_values( array_filter( $records, static fn( $record ): bool => is_array( $record ) && ! empty( $record['id'] ) ) );
	}

	private function public_record( array $record ): array {
		return [
			'id'         => (string) ( $record['id'] ?? '' ),
			'label'      => (string) ( $record['label'] ?? '' ),
			'prefix'     => (string) ( $record['prefix'] ?? '' ),
			'last4'      => (string) ( $record['last4'] ?? '' ),
			'scopes'     => $this->normalize_scopes( isset( $record['scopes'] ) && is_array( $record['scopes'] ) ? $record['scopes'] : [] ),
			'createdAt'  => (string) ( $record['createdAt'] ?? '' ),
			'createdBy'  => absint( $record['createdBy'] ?? 0 ),
			'lastUsedAt' => (string) ( $record['lastUsedAt'] ?? '' ),
			'lastUsedIp' => (string) ( $record['lastUsedIp'] ?? '' ),
			'revokedAt'  => (string) ( $record['revokedAt'] ?? '' ),
			'revokedBy'  => absint( $record['revokedBy'] ?? 0 ),
		];
	}

	private function request_key( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return trim( (string) $matches[1] );
		}
		$kiwe = (string) $request->get_header( 'x-kiwe-ai-key' );
		if ( '' !== trim( $kiwe ) ) {
			return trim( $kiwe );
		}

		return '';
	}

	private function auth_failure( string $code, string $message, int $status = 401 ): array {
		return [
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'status'  => $status,
		];
	}

	private function dedupe( array $records ): array {
		$seen = [];
		$out  = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$id = (string) ( $record['id'] ?? '' );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $record;
		}

		return $out;
	}

	private function sanitize_label( string $label ): string {
		return trim( sanitize_text_field( $label ) );
	}

	private function client_ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			$value = isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$first = trim( explode( ',', $value )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}

		return '';
	}
}
