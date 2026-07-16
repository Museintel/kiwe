<?php

namespace DSA\WP7;

use DSA\Element_Registry;
use DSA\Settings;
use DSA\Trust\Trust_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Abilities_Service {
	private const CATEGORY = 'kiwe-appsite';

	public function __construct(
		private Settings $settings,
		private Element_Registry $registry,
		private Trust_Service $trust
	) {}

	public function register(): void {
		if ( ! $this->available() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Kiwe Appsite', 'dsa' ),
				'description' => __( 'Readonly diagnostics for the Kiwe app shell, route registry, and deterministic trust state.', 'dsa' ),
			]
		);
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'dsa/audit-trust',
			[
				'label'               => __( 'Audit Kiwe trust signals', 'dsa' ),
				'description'         => __( 'Returns a deterministic, non-secret summary of SSL, Kiwe Key, Kiwe Secure, and payment-provider availability.', 'dsa' ),
				'category'            => self::CATEGORY,
				'output_schema'       => $this->trust_output_schema(),
				'execute_callback'    => [ $this, 'execute_trust_audit' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'annotations' => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'dsa/summarize-route',
			[
				'label'               => __( 'Summarize the current Kiwe route', 'dsa' ),
				'description'         => __( 'Returns a bounded semantic count of the current WordPress route without exposing element content or private visitor state.', 'dsa' ),
				'category'            => self::CATEGORY,
				'output_schema'       => $this->route_output_schema(),
				'execute_callback'    => [ $this, 'execute_route_summary' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'meta'                => [
					'annotations' => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function execute_trust_audit(): array {
		$link_hub = $this->settings->get( 'link_hub', [] );
		$summary  = $this->trust->summary( is_array( $link_hub ) ? $link_hub : [] );

		return [
			'version'          => 1,
			'sslActive'        => ! empty( $summary['ssl']['active'] ),
			'sslProvider'      => sanitize_text_field( (string) ( $summary['ssl']['provider'] ?? '' ) ),
			'phonekeyActive'   => ! empty( $summary['phonekey']['active'] ),
			'securetrackActive' => ! empty( $summary['secure']['active'] ),
			'paymentActive'    => ! empty( $summary['payment']['active'] ),
			'paymentProviders' => array_values( array_map( 'sanitize_text_field', array_slice( (array) ( $summary['payment']['providers'] ?? [] ), 0, 8 ) ) ),
		];
	}

	public function execute_route_summary(): array {
		$registry = $this->registry->to_array();
		$types    = [];

		foreach ( (array) ( $registry['summary'] ?? [] ) as $type => $count ) {
			$types[] = [
				'type'  => sanitize_key( (string) $type ),
				'count' => max( 0, (int) $count ),
			];
		}

		return [
			'version'        => 1,
			'route'          => esc_url_raw( (string) ( $registry['route'] ?? home_url( '/' ) ) ),
			'postId'         => max( 0, (int) ( $registry['postId'] ?? 0 ) ),
			'elementCount'   => max( 0, (int) ( $registry['count'] ?? 0 ) ),
			'registrySource' => sanitize_key( (string) ( $registry['registrySource'] ?? 'runtime' ) ),
			'types'          => array_slice( $types, 0, 24 ),
		];
	}

	public function summary(): array {
		return [
			'id'          => 'abilities',
			'label'       => __( 'Abilities API', 'dsa' ),
			'available'   => $this->available(),
			'status'      => $this->available() ? 'registered-readonly' : 'fallback',
			'description' => __( 'Machine-readable, admin-only readonly diagnostics for AI agents, automation, and WordPress-native command surfaces.', 'dsa' ),
			'fallback'    => __( 'DSA REST controllers and deterministic admin reports remain available.', 'dsa' ),
			'abilities'   => [ 'dsa/audit-trust', 'dsa/summarize-route' ],
		];
	}

	public function available(): bool {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	private function trust_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'version'           => [ 'type' => 'integer' ],
				'sslActive'         => [ 'type' => 'boolean' ],
				'sslProvider'       => [ 'type' => 'string' ],
				'phonekeyActive'    => [ 'type' => 'boolean' ],
				'securetrackActive' => [ 'type' => 'boolean' ],
				'paymentActive'     => [ 'type' => 'boolean' ],
				'paymentProviders'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'maxItems' => 8 ],
			],
			'required'   => [ 'version', 'sslActive', 'sslProvider', 'phonekeyActive', 'securetrackActive', 'paymentActive', 'paymentProviders' ],
		];
	}

	private function route_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'version'        => [ 'type' => 'integer' ],
				'route'          => [ 'type' => 'string', 'format' => 'uri' ],
				'postId'         => [ 'type' => 'integer', 'minimum' => 0 ],
				'elementCount'   => [ 'type' => 'integer', 'minimum' => 0 ],
				'registrySource' => [ 'type' => 'string' ],
				'types'          => [
					'type'     => 'array',
					'maxItems' => 24,
					'items'    => [
						'type'       => 'object',
						'properties' => [ 'type' => [ 'type' => 'string' ], 'count' => [ 'type' => 'integer', 'minimum' => 0 ] ],
						'required'   => [ 'type', 'count' ],
					],
				],
			],
			'required'   => [ 'version', 'route', 'postId', 'elementCount', 'registrySource', 'types' ],
		];
	}
}
