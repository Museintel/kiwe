<?php

namespace DSA\Diagnostics;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Apex_Acceptance_Service {
	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'send_headers', [ $this, 'send_document_contract_headers' ], 20 );
	}

	public function send_document_contract_headers(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || headers_sent() ) return;
		header( 'X-Kiwe-Runtime-Profile: apex-v1' );
		header( 'X-Kiwe-Document-Profile: ' . $this->document_profile() );
		header( 'X-Kiwe-Edge-Policy: origin-required' );
	}

	public function public_profile(): array {
		$settings = $this->settings->all();
		$visual = is_array( $settings['visual_effects'] ?? null ) ? $settings['visual_effects'] : [];
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];

		return [
			'schema' => 1,
			'profile' => 'kiwe-apex-v1',
			'dsaVersion' => DSA_VERSION,
			'architectureComplete' => true,
			'productionCertified' => false,
			'navigation' => [
				'default' => 'full-document',
				'nativeEditorialTransitions' => ! empty( $visual['editorial_view_transitions'] ),
				'controlledStaticMorph' => ! empty( $visual['editorial_morph_navigation'] ),
				'protectedRoutes' => 'full-document-network-only',
			],
			'cacheContracts' => [
				'htmlDocument' => 'origin-required-until-public-private-shell-split',
				'personalized' => 'network-only',
				'transactional' => 'network-only',
				'offlineEditorial' => ! empty( $permissions['offline_editorial_enabled'] ) ? 'public-editorial-v1-pilot' : 'disabled',
				'generatedAssets' => 'packaged-authority',
			],
			'edge' => [
				'allowedNow' => [ 'versioned-static-assets', 'public-editorial-v1-contract' ],
				'originRequired' => [ 'html-shell', 'phonekey', 'cart', 'checkout', 'account', 'notifications', 'saved-items', 'admin' ],
				'futureReader' => rest_url( 'dsa/v1/manifest' ),
			],
		];
	}

	public function report(): array {
		$public = $this->public_profile();
		$css = DSA_DIR . 'assets/css/surface.css';
		$js = DSA_DIR . 'assets/js/surface.js';
		$game_module = DSA_DIR . 'assets/js/modules/games-engine.js';
		$matrix = [
			[ 'id' => 's16', 'label' => 'Navigation and fragment safety', 'code' => 'complete', 'proof' => 'partial', 'remaining' => 'Bricks/editorial morph, bfcache, comments, search, archives, forms, embeds, cache plugins, accessibility and SEO-head matrix.' ],
			[ 'id' => 's17', 'label' => 'Offline public editorial', 'code' => 'complete', 'proof' => 'pending', 'remaining' => 'Airplane-mode replay, cache/media eviction, worker upgrade, iOS/Chromium and identity/cart isolation.' ],
			[ 'id' => 'production', 'label' => 'Production gates', 'code' => 'implemented', 'proof' => 'pending', 'remaining' => 'Commerce, PhoneKey, SecureTrack, Push, host/cache and release rollback matrices.' ],
		];

		return array_merge(
			$public,
			[
				'generatedAt' => current_time( 'mysql' ),
				'runtime' => [
					'php' => PHP_VERSION,
					'wordpress' => get_bloginfo( 'version' ),
					'theme' => get_stylesheet(),
					'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
					'bricks' => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
				],
				'assetBudgetEvidence' => [
					'packagedCssBytes' => is_file( $css ) ? (int) filesize( $css ) : 0,
					'packagedJsBytes' => is_file( $js ) ? (int) filesize( $js ) : 0,
					'lazyModuleBytes' => [
						'games' => is_file( $game_module ) ? (int) filesize( $game_module ) : 0,
					],
					'hardPerformanceGuarantee' => false,
				],
				'accessibilityContract' => [
					'reducedMotion' => 'feature-detected',
					'focusAfterMorph' => 'required-and-invariant-checked',
					'liveRegion' => 'required',
					'surfaceHistoryClose' => 'synthetic-entry-first',
					'keyboardAndScreenReaderProof' => 'live-matrix-pending',
				],
				'matrix' => $matrix,
				'finalDecision' => [
					'apexArchitecture' => 'complete',
					'broadProductionRelease' => 'not-certified',
					'controlledPilot' => 'allowed-only-after-target-host-production-gates',
				],
			]
		);
	}

	private function document_profile(): string {
		$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
		if ( preg_match( '#/(?:wp-admin|wp-login\.php|cart|checkout|my-account|order-pay|order-received)(?:/|\?|$)#i', $uri ) ) return 'protected-network-only';
		if ( is_user_logged_in() || $this->has_personalization_cookie() ) return 'personalized-network-only';
		return 'public-origin-rendered';
	}

	private function has_personalization_cookie(): bool {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			$name = strtolower( sanitize_key( (string) $name ) );
			if ( str_starts_with( $name, 'wordpress_logged_in' ) || str_starts_with( $name, 'wp_woocommerce_session' ) || str_starts_with( $name, 'woocommerce_items_in_cart' ) || str_starts_with( $name, 'pk_' ) || str_starts_with( $name, 'dsa_' ) || str_starts_with( $name, 'kiwe_' ) ) return true;
		}
		return false;
	}
}
