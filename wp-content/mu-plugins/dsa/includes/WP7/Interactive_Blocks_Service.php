<?php

namespace DSA\WP7;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Interactive_Blocks_Service {
	public function summary(): array {
		return [
			'id'          => 'interactivity',
			'label'       => __( 'Interactivity API', 'dsa' ),
			'available'   => $this->available(),
			'status'      => $this->available() ? 'native-store-bridge' : 'fallback',
			'description' => __( 'One native script-module bridge mirrors AI, app-adoption, and public display snapshots into WordPress Interactivity stores without taking over Surface rendering.', 'dsa' ),
			'fallback'    => __( 'The persistent DSA surface runtime remains the reactive shell.', 'dsa' ),
		];
	}

	public function available(): bool {
		return function_exists( 'wp_interactivity_state' )
			|| function_exists( 'wp_interactivity_config' )
			|| function_exists( 'wp_register_script_module' )
			|| class_exists( 'WP_Interactivity_API' );
	}
}
