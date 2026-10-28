<?php

namespace DSA\AI;

use DSA\Utilities\Credential_Records;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short-lived, least-privilege credentials for browser AIs, IDEs and tools.
 *
 * Capsules deliberately exclude staging, publishing, runtime and mutation
 * scopes. A tool that needs those capabilities must use a separately reviewed
 * Kiwe API key so a temporary content task cannot silently gain write access.
 */
final class Task_Capsule_Service {
	private const OPTION      = 'dsa_sitegraph_task_capsules_v1';
	private const PREFIX      = 'kiwe_task_';
	private const MAX_RECORDS = 24;
	private const MAX_TTL     = DAY_IN_SECONDS;

	public const SCOPES = [
		'site_graph',
		'site_graph_data',
		'bricks_ai',
		'seamflow',
		'validate_bindings',
		'validate_bricks_conversion',
		'validate_accessibility',
	];

	public const RESOURCES = [ 'site', 'business', 'menus', 'posts', 'pages', 'products', 'commerce', 'customcontent', 'customfields', 'taxonomies', 'terms', 'media' ];
	public const FIELDS    = [ 'id', 'type', 'slug', 'status', 'title', 'url', 'excerpt', 'date', 'modified', 'content', 'featuredImage', 'terms', 'product' ];

	public function issue( string $label, string $purpose = 'convert_validate', array $policy = [], array $context = [] ): array {
		$purpose = in_array( $purpose, [ 'content_read', 'convert_validate' ], true ) ? $purpose : 'convert_validate';
		$scopes  = 'content_read' === $purpose
			? [ 'site_graph', 'site_graph_data', 'bricks_ai' ]
			: self::SCOPES;
		$ttl     = max( 300, min( self::MAX_TTL, absint( $policy['ttl'] ?? HOUR_IN_SECONDS ) ) );
		$max_uses = max( 1, min( 500, absint( $policy['maxUses'] ?? 100 ) ) );
		$id       = 'task_' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) ), 0, 16 );
		$secret   = wp_generate_password( 48, false, false );
		$plain    = self::PREFIX . $id . '_' . $secret;
		$now      = time();
		$record   = [
			'id'          => $id,
			'label'       => $this->label( $label ),
			'purpose'     => $purpose,
			'prefix'      => substr( $plain, 0, 25 ),
			'last4'       => substr( $plain, -4 ),
			'hash'        => wp_hash_password( $plain ),
			'scopes'      => $scopes,
			'policy'      => $this->normalize_policy( $policy, $max_uses ),
			'createdAt'   => gmdate( 'c', $now ),
			'createdBy'   => absint( $context['userId'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ),
			'expiresAt'   => gmdate( 'c', $now + $ttl ),
			'expiresUnix' => $now + $ttl,
			'uses'        => 0,
			'lastUsedAt'  => '',
			'lastUsedIp'  => '',
			'revokedAt'   => '',
			'revokedBy'   => 0,
		];
		$result = Credential_Records::change( self::OPTION, function ( array $stored ) use ( $record, $plain ): array {
			$records = $this->records( $stored );
			array_unshift( $records, $record );
			return [ 'records' => array_slice( $records, 0, self::MAX_RECORDS ), 'value' => [ 'token' => $plain, 'record' => $this->public_record( $record ) ] ];
		} );
		if ( ! $result['ok'] ) throw new \RuntimeException( 'Kiwe could not confirm that the task capsule was saved. Refresh the connection list before trying again.' );
		return $result['value'];
	}

	public function authenticate_request( WP_REST_Request $request, string $required_scope ): array {
		$token = $this->request_token( $request );
		if ( '' === $token ) {
			return $this->failure( 'missing_capsule', 'Missing Kiwe task capsule.' );
		}
		if ( ! str_starts_with( $token, self::PREFIX ) ) {
			return $this->failure( 'invalid_capsule_prefix', 'The supplied credential is not a Kiwe task capsule.' );
		}

		$result = Credential_Records::change( self::OPTION, function ( array $stored ) use ( $token, $required_scope ): array {
			$records = $this->records( $stored );
			foreach ( $records as &$record ) {
				if ( ! is_array( $record ) || '' !== (string) ( $record['revokedAt'] ?? '' ) ) {
					continue;
				}
				if ( ! wp_check_password( $token, (string) ( $record['hash'] ?? '' ) ) ) {
					continue;
				}
				if ( time() > absint( $record['expiresUnix'] ?? 0 ) ) {
					return [ 'value' => $this->failure( 'capsule_expired', 'This Kiwe task capsule has expired.' ) ];
				}
				$max_uses = absint( $record['policy']['maxUses'] ?? 0 );
				if ( $max_uses < 1 || absint( $record['uses'] ?? 0 ) >= $max_uses ) {
					return [ 'value' => $this->failure( 'capsule_exhausted', 'This Kiwe task capsule has used its request budget.' ) ];
				}
				$scopes = $this->normalize_scopes( (array) ( $record['scopes'] ?? [] ) );
				if ( 'status' !== $required_scope && ! in_array( $required_scope, $scopes, true ) ) {
					return [ 'value' => $this->failure( 'capsule_scope_denied', 'This Kiwe task capsule does not allow the requested capability.', 403 ) ];
				}

				$record['uses']       = absint( $record['uses'] ?? 0 ) + 1;
				$record['lastUsedAt'] = gmdate( 'c' );
				$record['lastUsedIp'] = $this->client_ip();
				$public = $this->public_record( $record );

				return [ 'records' => $records, 'value' => [
					'ok'     => true,
					'kind'   => 'task_capsule',
					'record' => $public,
					'policy' => $public['policy'],
					'scope'  => $required_scope,
				] ];
			}
			unset( $record );

			return [ 'value' => $this->failure( 'invalid_capsule', 'Kiwe task capsule was not found or has been revoked.' ) ];
		} );
		return $result['ok'] ? $result['value'] : $this->failure( $result['code'], 'Kiwe could not confirm the current task capsule state. Try again shortly.', 503 );
	}

	public function authorize_data_args( array $args, array $auth ): array {
		if ( 'task_capsule' !== (string) ( $auth['kind'] ?? '' ) ) {
			return [ 'ok' => true, 'args' => $args ];
		}

		$policy = is_array( $auth['policy'] ?? null ) ? $auth['policy'] : [];
		$resources = $this->normalize_resources( (array) ( $policy['resources'] ?? self::RESOURCES ) );
		$fields = $this->normalize_fields( (array) ( $policy['fields'] ?? self::FIELDS ) );
		$max_rows = max( 1, min( 100, absint( $policy['maxRows'] ?? 25 ) ) );
		$args['publicOnly'] = 1;
		unset( $args['metaKeys'], $args['status'], $args['postStatus'] );

		if ( isset( $args['queries'] ) && is_array( $args['queries'] ) ) {
			$queries = [];
			foreach ( array_slice( $args['queries'], 0, 20, true ) as $name => $query ) {
				if ( ! is_array( $query ) ) {
					continue;
				}
				$bounded = $this->bound_query( $query, $resources, $fields, $max_rows );
				if ( null !== $bounded ) {
					$queries[ $name ] = $bounded;
				}
			}
			if ( [] === $queries ) {
				return $this->data_failure( 'capsule_resource_denied', 'No requested SiteGraph Data resource is allowed by this task capsule.' );
			}
			$args['queries'] = $queries;
			unset( $args['resources'] );

			return [ 'ok' => true, 'args' => $args ];
		}

		if ( isset( $args['resources'] ) && is_array( $args['resources'] ) ) {
			$args['resources'] = array_values( array_intersect( $this->normalize_resources( $args['resources'] ), $resources ) );
			if ( [] === $args['resources'] ) {
				return $this->data_failure( 'capsule_resource_denied', 'No requested SiteGraph Data resource is allowed by this task capsule.' );
			}
			$args['limits'] = array_fill_keys( $args['resources'], $max_rows );
			$args['fields'] = $fields;

			return [ 'ok' => true, 'args' => $args ];
		}

		$bounded = $this->bound_query( $args, $resources, $fields, $max_rows );
		if ( null === $bounded ) {
			return $this->data_failure( 'capsule_resource_denied', 'The requested SiteGraph Data resource is not allowed by this task capsule.' );
		}

		return [ 'ok' => true, 'args' => $bounded ];
	}

	public function public_records(): array {
		$result = Credential_Records::change( self::OPTION, function ( array $stored ): array {
			$records = $this->records( $stored );
			$cutoff = time() - DAY_IN_SECONDS;
			$next = array_values( array_filter( $records, static fn( array $record ): bool => absint( $record['expiresUnix'] ?? 0 ) >= $cutoff ) );
			$value = [ 'value' => array_map( [ $this, 'public_record' ], $next ) ];
			return count( $next ) !== count( $records ) ? [ 'records' => $next ] + $value : $value;
		} );
		return $result['ok'] ? $result['value'] : [];
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

	private function normalize_policy( array $policy, int $max_uses ): array {
		$resources = $this->normalize_resources( (array) ( $policy['resources'] ?? self::RESOURCES ) );
		$fields    = $this->normalize_fields( (array) ( $policy['fields'] ?? self::FIELDS ) );

		return [
			'publicOnly'  => true,
			'resources'   => [] !== $resources ? $resources : self::RESOURCES,
			'fields'      => [] !== $fields ? $fields : self::FIELDS,
			'maxRows'     => max( 1, min( 100, absint( $policy['maxRows'] ?? 25 ) ) ),
			'sampleLimit' => max( 0, min( 24, absint( $policy['sampleLimit'] ?? 8 ) ) ),
			'maxUses'     => $max_uses,
			'mutation'    => 'forbidden',
		];
	}

	private function bound_query( array $query, array $resources, array $fields, int $max_rows ): ?array {
		$resource = sanitize_key( (string) ( $query['resource'] ?? $query['type'] ?? 'posts' ) );
		$resource = $this->canonical_resource( $resource );
		if ( ! in_array( $resource, $resources, true ) ) {
			return null;
		}
		$query['resource']   = $resource;
		$query['publicOnly'] = 1;
		$query['limit']      = max( 1, min( $max_rows, absint( $query['limit'] ?? $max_rows ) ) );
		$query['fields']     = $fields;
		unset( $query['metaKeys'], $query['status'], $query['postStatus'] );

		return $query;
	}

	private function canonical_resource( string $resource ): string {
		return match ( $resource ) {
			'menu' => 'menus',
			'post', 'content', 'nodes' => 'posts',
			'page' => 'pages',
			'product' => 'products',
			'term', 'category', 'categories', 'taxonomy', 'taxonomies', 'product_cat', 'productcategory', 'productcategories', 'product-categories' => 'terms',
			'image', 'images', 'attachment', 'attachments' => 'media',
			default => $resource,
		};
	}

	private function normalize_scopes( array $scopes ): array {
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $scopes ), static fn( string $scope ): bool => in_array( $scope, self::SCOPES, true ) ) ) );
	}

	private function normalize_resources( array $resources ): array {
		$out = [];
		foreach ( $resources as $resource ) {
			$resource = $this->canonical_resource( sanitize_key( (string) $resource ) );
			if ( in_array( $resource, self::RESOURCES, true ) ) {
				$out[] = $resource;
			}
		}

		return array_values( array_unique( $out ) );
	}

	private function normalize_fields( array $fields ): array {
		$out = [];
		foreach ( $fields as $field ) {
			$field = sanitize_key( (string) $field );
			foreach ( self::FIELDS as $allowed ) {
				if ( strtolower( $allowed ) === strtolower( $field ) ) {
					$out[] = $allowed;
					break;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	private function records( array $records ): array {
		return array_values( array_filter( $records, static fn( $record ): bool => is_array( $record ) && ! empty( $record['id'] ) ) );
	}

	private function public_record( array $record ): array {
		$max_uses = absint( $record['policy']['maxUses'] ?? 0 );
		$uses     = absint( $record['uses'] ?? 0 );

		return [
			'id'            => (string) ( $record['id'] ?? '' ),
			'label'         => (string) ( $record['label'] ?? '' ),
			'purpose'       => (string) ( $record['purpose'] ?? '' ),
			'prefix'        => (string) ( $record['prefix'] ?? '' ),
			'last4'         => (string) ( $record['last4'] ?? '' ),
			'scopes'        => $this->normalize_scopes( (array) ( $record['scopes'] ?? [] ) ),
			'policy'        => is_array( $record['policy'] ?? null ) ? $record['policy'] : [],
			'createdAt'     => (string) ( $record['createdAt'] ?? '' ),
			'createdBy'     => absint( $record['createdBy'] ?? 0 ),
			'expiresAt'     => (string) ( $record['expiresAt'] ?? '' ),
			'uses'          => $uses,
			'usesRemaining' => max( 0, $max_uses - $uses ),
			'lastUsedAt'    => (string) ( $record['lastUsedAt'] ?? '' ),
			'lastUsedIp'    => (string) ( $record['lastUsedIp'] ?? '' ),
			'revokedAt'     => (string) ( $record['revokedAt'] ?? '' ),
			'revokedBy'     => absint( $record['revokedBy'] ?? 0 ),
			'expired'       => time() > absint( $record['expiresUnix'] ?? 0 ),
		];
	}

	private function request_token( WP_REST_Request $request ): string {
		$authorization = trim( (string) $request->get_header( 'authorization' ) );
		if ( preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
			return trim( (string) $matches[1] );
		}
		return trim( (string) $request->get_header( 'x-kiwe-ai-key' ) );
	}

	private function label( string $label ): string {
		$label = trim( sanitize_text_field( $label ) );
		return '' !== $label ? $label : __( 'External SiteGraph task', 'dsa' );
	}

	private function client_ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			$value = isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : '';
			$first = trim( explode( ',', $value )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}
		return '';
	}

	private function failure( string $code, string $message, int $status = 401 ): array {
		return [ 'ok' => false, 'code' => $code, 'message' => $message, 'status' => $status ];
	}

	private function data_failure( string $code, string $message ): array {
		return [ 'ok' => false, 'status' => 403, 'error' => [ 'code' => $code, 'message' => $message ] ];
	}
}
