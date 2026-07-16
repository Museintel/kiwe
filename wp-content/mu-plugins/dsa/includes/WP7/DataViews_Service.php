<?php

namespace DSA\WP7;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DataViews_Service {
	public function summary(): array {
		return [
			'id'          => 'dataviews',
			'label'       => __( 'DataViews', 'dsa' ),
			'available'   => $this->available(),
			'status'      => $this->available() ? 'available' : 'fallback',
			'description' => __( 'Native admin tables for games, triggers, link hub, rewards, SecureTrack, and future POS rules.', 'dsa' ),
			'fallback'    => __( 'Kiwe admin forms remain the settings surface.', 'dsa' ),
		];
	}

	public function available(): bool {
		return function_exists( 'wp_enqueue_dataviews' )
			|| function_exists( 'wp_register_dataview' )
			|| class_exists( 'WP_DataViews' )
			|| ( function_exists( 'wp_script_is' ) && wp_script_is( 'wp-dataviews', 'registered' ) );
	}
}
