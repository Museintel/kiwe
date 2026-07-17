<?php

namespace DSA\WP7;

use DSA\AI\Site_Graph_Service;
use DSA\Element_Registry;
use DSA\Settings;
use DSA\Trust\Trust_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Native_Service {
	private $abilities;
	private $ai_client;
	private $interactivity;
	private $bindings;
	private $dataviews;

	public function __construct( Settings $settings, Element_Registry $registry, Trust_Service $trust, ?Site_Graph_Service $site_graph = null ) {
		$this->abilities     = new Abilities_Service( $settings, $registry, $trust, $site_graph );
		$this->ai_client     = new AI_Client_Service();
		$this->interactivity = new Interactive_Blocks_Service();
		$this->bindings      = new Bindings_Service();
		$this->dataviews     = new DataViews_Service();
	}

	public function register(): void {
		$this->abilities->register();
		add_action( 'init', [ $this->bindings, 'register' ] );
	}

	public function summary(): array {
		$features = [
			'abilities'       => $this->abilities->summary(),
			'ai_client'       => $this->ai_client->summary(),
			'interactivity'   => $this->interactivity->summary(),
			'bindings'        => $this->bindings->summary(),
			'php_only_blocks' => $this->php_only_blocks_summary(),
			'dataviews'       => $this->dataviews->summary(),
		];

		return [
			'version'       => 1,
			'wpVersion'     => get_bloginfo( 'version' ),
			'phpVersion'    => PHP_VERSION,
			'available'     => array_values(
				array_map(
					static function ( array $feature ): string {
						return $feature['id'];
					},
					array_filter(
						$features,
						static function ( array $feature ): bool {
							return ! empty( $feature['available'] );
						}
					)
				)
			),
			'features'      => $features,
			'featureFlags'  => $this->feature_flags( $features ),
			'contractNotes' => [
				__( 'Native APIs are adapters, not shell dependencies.', 'dsa' ),
				__( 'Visitor-facing trust remains deterministic even when AI APIs are present.', 'dsa' ),
				__( 'Checkout, payment, login, reset, and account routes keep Protected Flow boundaries.', 'dsa' ),
			],
		];
	}

	public function manifest_fragment(): array {
		$summary = $this->summary();

		return [
			'wpVersion'    => $summary['wpVersion'],
			'phpVersion'   => $summary['phpVersion'],
			'featureFlags' => $summary['featureFlags'],
			'adapters'     => array_map(
				static function ( array $feature ): array {
					return [
						'id'        => $feature['id'],
						'label'     => $feature['label'],
						'available' => (bool) $feature['available'],
						'status'    => $feature['status'],
					];
				},
				$summary['features']
			),
		];
	}

	private function php_only_blocks_summary(): array {
		return [
			'id'          => 'php_only_blocks',
			'label'       => __( 'PHP-only Blocks', 'dsa' ),
			'available'   => function_exists( 'register_block_type' ) && function_exists( 'render_block' ),
			'status'      => function_exists( 'register_block_type' ) && function_exists( 'render_block' ) ? 'available' : 'fallback',
			'description' => __( 'Server-rendered components for trust badges, action panels, account tasks, and future POS/scanner views.', 'dsa' ),
			'fallback'    => __( 'DSA renders PHP templates and frontend panels directly.', 'dsa' ),
		];
	}

	private function feature_flags( array $features ): array {
		$flags = [];

		foreach ( $features as $id => $feature ) {
			$flags[ $id ] = ! empty( $feature['available'] );
		}

		return $flags;
	}
}
