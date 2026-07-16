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
			'status'      => $this->available() ? 'available' : 'fallback',
			'description' => __( 'Admin-only intelligence for trust audits, transition copy, SecureTrack explanations, and GEO recommendations.', 'dsa' ),
			'fallback'    => __( 'DSA keeps AI as a bounded admin module until a native client is present.', 'dsa' ),
		];
	}

	public function available(): bool {
		return function_exists( 'wp_ai_client' )
			|| function_exists( 'wp_get_ai_client' )
			|| class_exists( 'WP_AI_Client' )
			|| class_exists( 'WP_AI_Service' );
	}
}
