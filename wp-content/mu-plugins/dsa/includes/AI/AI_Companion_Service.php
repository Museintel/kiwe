<?php

namespace DSA\AI;

use DSA\Secure\SecureTrack_AI_Brief_Service;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kiwe Companion AI broker.
 *
 * This service does not call a model. It is a bounded context broker and
 * deterministic reviewer that external AI tools can ask for the right Kiwe
 * contract slices instead of reading the whole codebase.
 */
final class AI_Companion_Service {
	private const MODES = [ 'website', 'theme', 'combined', 'dynamic', 'audit', 'staging', 'security' ];

	public function __construct(
		private Settings $settings,
		private Site_Graph_Service $site_graph,
		private ?AI_Companion_Memory_Service $memory = null
	) {
		$this->memory = $this->memory ?: new AI_Companion_Memory_Service();
	}

	public function status( array $auth = [] ): array {
		$settings = $this->settings();

		return [
			'ok'          => true,
			'schema'      => 'kiwe.ai-companion.status.v1',
			'enabled'     => ! empty( $settings['companion_enabled'] ),
			'modes'       => $settings['companion_modes'],
			'secureTrack' => [
				'briefSharingEnabled' => ! empty( $settings['securetrack_brief_enabled'] ),
				'availableForThisKey' => $this->securetrack_allowed( $auth ),
				'policy'              => 'redacted SecureTrack brief is available only when Kiwe > AI enables it and the key has all, security_brief, or companion_securetrack scope',
			],
			'budgets'     => [
				'maxContextCards' => (int) $settings['max_context_cards'],
				'maxReviewBytes'  => (int) $settings['max_review_bytes'],
				'cacheTtlSeconds' => (int) $settings['cache_ttl_seconds'],
				'logPrompts'      => ! empty( $settings['log_prompts'] ),
			],
			'memory'      => ! empty( $settings['memory_enabled'] ) ? $this->memory->summary( 8 ) : [ 'schema' => 'kiwe.ai-companion.memory-summary.v1', 'disabled' => true ],
			'routes'      => [
				'context'      => '/wp-json/dsa/v1/ai/companion/context',
				'ask'          => '/wp-json/dsa/v1/ai/companion/ask',
				'reviewOutput' => '/wp-json/dsa/v1/ai/companion/review-output',
				'memory'       => '/wp-json/dsa/v1/ai/companion/memory',
			],
			'boundaries'  => [
				'No model call is made by this service.',
				'No prompts or generated files are stored unless logPrompts is explicitly enabled; the default is off.',
				'SecureTrack context is redacted and separately gated.',
				'Writes still go through the controlled staging executor.',
			],
		];
	}

	public function context( array $args = [], array $auth = [] ): array {
		$settings = $this->settings();
		if ( empty( $settings['companion_enabled'] ) ) {
			return $this->disabled( 'companion_disabled', 'Kiwe Companion AI is disabled in Kiwe > AI.' );
		}

		$mode = $this->mode( (string) ( $args['mode'] ?? 'combined' ), $settings );
		if ( '' === $mode ) {
			return [
				'ok'         => false,
				'httpStatus' => 403,
				'schema'     => 'kiwe.ai-companion.context.v1',
				'error'      => [
					'code'    => 'mode_disabled',
					'message' => 'That Companion mode is disabled in Kiwe > AI.',
				],
			];
		}

		$sample_limit = max( 0, min( 12, absint( $args['sampleLimit'] ?? 4 ) ) );
		$graph        = $this->site_graph->graph( [ 'sampleLimit' => $sample_limit ] );
		$cards        = $this->cards_for_mode( $mode );
		$cards        = array_slice( $cards, 0, max( 1, (int) $settings['max_context_cards'] ) );

		return [
			'ok'          => true,
			'schema'      => 'kiwe.ai-companion.context.v1',
			'generatedAt' => gmdate( 'c' ),
			'mode'        => $mode,
			'purpose'     => 'Compact Kiwe contract/context cards for external AI tools building website/page, DSA theme, combined, dynamic-binding, audit, staging, or security work.',
			'siteGraph'   => [
				'summary'   => $this->site_graph->summary(),
				'graphHash' => substr( hash( 'sha256', (string) wp_json_encode( $graph ) ), 0, 32 ),
				'sampleLimit' => $sample_limit,
				'route'     => '/wp-json/dsa/v1/ai/site-graph',
				'dataRoute' => '/wp-json/dsa/v1/ai/site-graph-data',
			],
			'toolkit'     => [
				'readFirst' => [
					'https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md',
					'https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md',
				],
				'fallback'  => 'Use GitHub blob fallback only if raw URLs are unavailable. Do not read the whole repository.',
			],
			'cards'       => $cards,
			'memory'      => ! empty( $settings['memory_enabled'] ) ? $this->memory->summary( 10 ) : [ 'disabled' => true ],
			'secureTrack' => $this->securetrack_allowed( $auth )
				? ( new SecureTrack_AI_Brief_Service() )->brief( max( 1, min( 20, absint( $args['secureLimit'] ?? 8 ) ) ) )
				: [
					'schema'  => 'kiwe.securetrack-ai-brief.v1',
					'enabled' => false,
					'reason'  => 'SecureTrack brief sharing is off or the key does not include security_brief/companion_securetrack.',
				],
		];
	}

	public function ask( array $args = [], array $auth = [] ): array {
		$context = $this->context( $args, $auth );
		if ( empty( $context['ok'] ) ) {
			return $context;
		}

		$question = sanitize_textarea_field( (string) ( $args['question'] ?? '' ) );
		$answer   = $this->answer_for_question( $question, (string) ( $context['mode'] ?? 'combined' ) );

		return [
			'ok'          => true,
			'schema'      => 'kiwe.ai-companion.answer.v1',
			'mode'        => (string) ( $context['mode'] ?? 'combined' ),
			'answer'      => $answer,
			'contextHash' => substr( hash( 'sha256', (string) wp_json_encode( $context['cards'] ?? [] ) ), 0, 32 ),
			'nextRoutes'  => [
				'reviewOutput' => '/wp-json/dsa/v1/ai/companion/review-output',
				'validateBindings' => '/wp-json/dsa/v1/ai/validate-bindings',
				'stageExecution' => '/wp-json/dsa/v1/ai/staging/execute',
			],
		];
	}

	public function review_output( array $args = [], array $auth = [] ): array {
		$settings = $this->settings();
		if ( empty( $settings['companion_enabled'] ) ) {
			return $this->disabled( 'companion_disabled', 'Kiwe Companion AI is disabled in Kiwe > AI.' );
		}

		$mode  = $this->mode( (string) ( $args['mode'] ?? 'combined' ), $settings );
		$files = $this->normalize_files( $args['files'] ?? [] );
		$total = array_sum( array_map( static fn( array $file ): int => strlen( (string) ( $file['content'] ?? '' ) ), $files ) );
		if ( $total > (int) $settings['max_review_bytes'] ) {
			return [
				'ok'         => false,
				'httpStatus' => 413,
				'schema'     => 'kiwe.ai-companion.review.v1',
				'error'      => [
					'code'    => 'review_payload_too_large',
					'message' => 'Review payload exceeds Kiwe > AI max review byte budget.',
					'bytes'   => $total,
					'limit'   => (int) $settings['max_review_bytes'],
				],
			];
		}

		$findings = [];
		$paths    = array_map( static fn( array $file ): string => (string) ( $file['path'] ?? '' ), $files );
		$path_map = [];
		foreach ( $files as $file ) {
			$path_map[ (string) $file['path'] ] = (string) $file['content'];
		}

		$theme_css = $this->file_like( $path_map, 'theme.css' );
		if ( '' !== $theme_css ) {
			$findings = array_merge( $findings, $this->review_theme_css( $theme_css ) );
		}

		$combined_preview = $this->file_like( $path_map, 'combined-preview/index.html' );
		$combined_css     = $this->file_like( $path_map, 'combined-preview/assets/combined-preview.css' );
		if ( 'combined' === $mode && ( '' !== $combined_preview || '' !== $combined_css ) ) {
			$findings = array_merge( $findings, $this->review_combined_preview( $combined_preview . "\n" . $combined_css ) );
		}

		$bricks = $this->file_like( $path_map, 'bricks-paste.html' );
		if ( '' !== $bricks && preg_match( '/data-dsa-(?:surface|dock|screen|sheet|cart-panel|profile-panel)/i', $bricks ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'page_artifact_contains_appshell',
				'message'  => 'website/bricks-paste.html must remain page-only; AppShell preview or DSA fixture markup belongs in combined-preview only.',
			];
		}

		$package_json = $this->file_like( $path_map, 'theme-package.json' );
		if ( '' !== $theme_css && '' === $package_json ) {
			$findings[] = [
				'severity' => 'warning',
				'code'     => 'missing_theme_package_json',
				'message'  => 'Importable AppShell theme CSS should travel with theme-package.json so settings, tokens, and CSS stay one package.',
			];
		}

		foreach ( $path_map as $path => $content ) {
			if ( preg_match( '/kiwe_ai_[A-Za-z0-9_:-]+|Bearer\s+[A-Za-z0-9._:-]+/i', $content ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'secret_like_content',
					'message'  => 'Output appears to contain an API key or bearer token. Handoffs must never include live credentials.',
					'path'     => sanitize_text_field( $path ),
				];
			}
		}

		if ( ! in_array( 'combined-preview/index.html', $paths, true ) && 'combined' === $mode ) {
			$findings[] = [
				'severity' => 'warning',
				'code'     => 'combined_preview_missing',
				'message'  => 'Combined mode should provide one primary combined-preview/index.html showing the page behind the AppShell.',
			];
		}

		$counts = [ 'critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0 ];
		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? 'info' );
			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ]++;
			}
		}

		if ( ! empty( $settings['memory_enabled'] ) ) {
			$this->memory->record_findings( $findings, [ 'mode' => $mode, 'lane' => 'review-output' ] );
		}

		return [
			'ok'        => 0 === $counts['critical'] + $counts['error'],
			'schema'    => 'kiwe.ai-companion.review.v1',
			'mode'      => $mode,
			'bytes'     => $total,
			'counts'    => $counts,
			'findings'  => $findings,
			'next'      => [
				'If errors exist, revise actual files before rerunning the audit.',
				'If review passes, run official validators and live WordPress/Bricks/Theme import tests when available.',
			],
		];
	}

	public function memory(): array {
		$settings = $this->settings();
		if ( empty( $settings['memory_enabled'] ) ) {
			return [
				'ok'     => true,
				'schema' => 'kiwe.ai-companion.memory-summary.v1',
				'disabled' => true,
			];
		}

		return [
			'ok'     => true,
			'memory' => $this->memory->summary( 40 ),
		];
	}

	public function clear_memory(): array {
		$cleared = $this->memory->clear();

		return [
			'ok'      => true,
			'schema'  => 'kiwe.ai-companion.memory-clear.v1',
			'cleared' => $cleared,
		];
	}

	private function settings(): array {
		$defaults = $this->settings->defaults()['ai'] ?? [];
		$current  = $this->settings->get( 'ai', [] );

		return array_replace_recursive( is_array( $defaults ) ? $defaults : [], is_array( $current ) ? $current : [] );
	}

	private function securetrack_allowed( array $auth ): bool {
		$settings = $this->settings();
		if ( empty( $settings['securetrack_brief_enabled'] ) ) {
			return false;
		}
		$record = isset( $auth['record'] ) && is_array( $auth['record'] ) ? $auth['record'] : [];
		$scopes = isset( $record['scopes'] ) && is_array( $record['scopes'] ) ? array_map( 'sanitize_key', $record['scopes'] ) : [];

		return in_array( 'all', $scopes, true )
			|| in_array( 'security_brief', $scopes, true )
			|| in_array( 'companion_securetrack', $scopes, true )
			|| in_array( 'admin', $scopes, true );
	}

	private function mode( string $mode, array $settings ): string {
		$mode = sanitize_key( $mode );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$mode = 'combined';
		}
		$modes = isset( $settings['companion_modes'] ) && is_array( $settings['companion_modes'] ) ? $settings['companion_modes'] : [];

		return ! empty( $modes[ $mode ] ) ? $mode : '';
	}

	private function cards_for_mode( string $mode ): array {
		$common = [
			[
				'id'      => 'read-lite-contexts-only',
				'title'   => 'Token-efficient toolkit read path',
				'body'    => 'External AI should begin with combined-lite.md and audit-lite.md, then ask Kiwe API routes for site-specific facts. Do not crawl the full repository.',
				'applies' => [ 'website', 'theme', 'combined', 'dynamic', 'audit' ],
			],
			[
				'id'      => 'seam-is-headless',
				'title'   => 'Seam semantic/headless boundary',
				'body'    => 'Seam roles and class vocabulary describe meaning and layout vocabulary. Visual art direction belongs in page CSS or theme CSS, not in hidden framework defaults.',
				'applies' => [ 'website', 'combined', 'dynamic' ],
			],
			[
				'id'      => 'appshell-geometry-owned-by-core',
				'title'   => 'AppShell geometry is Kiwe core authority',
				'body'    => 'Theme CSS can style color, typography, borders, radii, shadows, states, cards, forms, and rails. It must not own dock/sheet/screen/backdrop fixed positioning, viewport offsets, or layout measurement.',
				'applies' => [ 'theme', 'combined', 'audit' ],
			],
			[
				'id'      => 'combined-preview-single-truth',
				'title'   => 'Combined preview is one live-feeling preview',
				'body'    => 'Combined mode should show the website/page behind the Kiwe AppShell in one preview with variation controls. Separate technical previews are optional only when explicitly allowed.',
				'applies' => [ 'combined', 'audit' ],
			],
			[
				'id'      => 'kiwe-owned-capabilities',
				'title'   => 'Capability authority stays in Kiwe/WordPress/WooCommerce/Bricks',
				'body'    => 'Search, save, cart, checkout, auth, profile, notifications, AI, security, and writes are connected through Kiwe runtime or controlled executor routes. Handoffs must not build duplicate app logic.',
				'applies' => [ 'website', 'theme', 'combined', 'dynamic', 'staging' ],
			],
			[
				'id'      => 'site-graph-data-not-scraping',
				'title'   => 'Site Graph Data replaces frontend scraping',
				'body'    => 'Use /ai/site-graph-data for public posts, products, media, terms, menus, pages, custom types, taxonomies, and fields. Use staging executor for confirmed writes.',
				'applies' => [ 'website', 'combined', 'dynamic', 'staging' ],
			],
			[
				'id'      => 'securetrack-separate-consent',
				'title'   => 'SecureTrack AI context has separate consent',
				'body'    => 'Security posture briefs are redacted, scoped, and off unless Kiwe > AI enables them. API keys need security_brief or companion_securetrack scope.',
				'applies' => [ 'security', 'audit', 'staging' ],
			],
		];

		return array_values(
			array_filter(
				$common,
				static fn( array $card ): bool => in_array( $mode, $card['applies'], true )
			)
		);
	}

	private function answer_for_question( string $question, string $mode ): array {
		$question_lc = strtolower( $question );
		if ( str_contains( $question_lc, 'theme' ) || str_contains( $question_lc, 'dsa' ) || 'theme' === $mode ) {
			return [
				'summary' => 'Build theme packages as styling and safe settings only; Kiwe core owns AppShell geometry and runtime behavior.',
				'do'      => [ 'Use documented live roots/selectors.', 'Keep preview fixture selectors out of import CSS.', 'Put screen copy/settings inside the theme package when supported.' ],
				'dont'    => [ 'Do not set fixed/inset/z-index/viewport geometry for dock/sheet/screen/backdrop.', 'Do not invent runtime modules.' ],
			];
		}
		if ( str_contains( $question_lc, 'bricks' ) || str_contains( $question_lc, 'dynamic' ) || 'dynamic' === $mode ) {
			return [
				'summary' => 'Use Site Graph Data for discovery and binding plans, then validate and stage through Kiwe before any Bricks/WordPress write.',
				'do'      => [ 'Use canonical data-dsa-open-module launchers.', 'Bind query loops/dynamic tags from the live Site Graph.', 'Treat writes as staged/confirmed operations.' ],
				'dont'    => [ 'Do not scrape the frontend for source-of-truth data.', 'Do not claim a save happened without controlled executor evidence.' ],
			];
		}

		return [
			'summary' => 'Use the smallest Kiwe context for the requested mode, keep Seam headless, keep capabilities Kiwe-owned, and run the audit loop before live import.',
			'do'      => [ 'Read lite contexts first.', 'Ask Companion/context for compact rules.', 'Use official validators and live tests when available.' ],
			'dont'    => [ 'Do not crawl the whole repo.', 'Do not store secrets or prompts in handoffs.', 'Do not duplicate Kiwe runtime capability logic.' ],
		];
	}

	private function normalize_files( $files ): array {
		if ( ! is_array( $files ) ) {
			return [];
		}

		$out = [];
		foreach ( $files as $path => $file ) {
			if ( is_array( $file ) ) {
				$file_path = sanitize_text_field( (string) ( $file['path'] ?? $path ) );
				$content   = (string) ( $file['content'] ?? '' );
			} else {
				$file_path = sanitize_text_field( (string) $path );
				$content   = (string) $file;
			}
			if ( '' === $file_path ) {
				continue;
			}
			$out[] = [
				'path'    => str_replace( '\\', '/', $file_path ),
				'content' => $content,
			];
		}

		return $out;
	}

	private function file_like( array $path_map, string $needle ): string {
		foreach ( $path_map as $path => $content ) {
			if ( str_ends_with( str_replace( '\\', '/', (string) $path ), $needle ) ) {
				return (string) $content;
			}
		}

		return '';
	}

	private function review_theme_css( string $css ): array {
		$findings = [];
		if ( preg_match( '/(?:^|[\\s,{])(?:\\.dsa-screen-head|\\.dsa-toolbar|\\.dsa-preview-|\\.dsa-fixture-|\\.dsa-dock-primary|\\.dsa-dock-secondary)\\b/i', $css ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'fixture_selector_in_import_css',
				'message'  => 'Importable theme.css includes preview/fixture selectors. Move them to combined-preview CSS.',
			];
		}
		if ( preg_match( '/(?:#dsa-surface|\\[data-dsa-surface\\])\\s*{[^}]*(?:position\\s*:\\s*(?:fixed|absolute)|\\binset\\s*:|\\btop\\s*:|\\bright\\s*:|\\bbottom\\s*:|\\bleft\\s*:|\\bz-index\\s*:|100vw|100vh)/is', $css ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'protected_surface_geometry_in_theme_css',
				'message'  => 'Importable theme.css owns protected AppShell surface geometry. Kiwe Geometry Engine owns surface positioning and viewport geometry.',
			];
		}
		if ( preg_match( '/(?:#dsa-surface|\[data-dsa-surface\])[^{}]*(?:data-dsa-dock|dsa-dock|dsa-installed-theme)[^{]*{[^}]*(?:position\\s*:\\s*(?:fixed|absolute)|\\binset\\s*:|\\btop\\s*:|\\bright\\s*:|\\bbottom\\s*:|\\bleft\\s*:|\\bz-index\\s*:|100vw|100vh)/is', $css ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'protected_geometry_in_theme_css',
				'message'  => 'Importable theme.css owns protected dock/sheet/screen/backdrop geometry. Kiwe Geometry Engine owns that layer.',
			];
		}
		if ( preg_match( '/(?:#dsa-surface|\[data-dsa-surface\])[^{}]*(?:data-dsa-dock|dsa-dock)[^{]*{[^}]*(?:display\\s*:\\s*flex|grid-template|justify-content|align-items|flex-direction|width\\s*:|height\\s*:)/is', $css ) ) {
			$findings[] = [
				'severity' => 'warning',
				'code'     => 'dock_arrangement_in_theme_css',
				'message'  => 'Theme CSS appears to own dock arrangement/measurement. Prefer Kiwe dock settings and core geometry variables.',
			];
		}

		return $findings;
	}

	private function review_combined_preview( string $content ): array {
		$findings = [];
		if ( preg_match( '/(?:^|[\\s"\\\'])dsa-(?:screen-head|screen-body|profile-card|score-card|links-identity|account-rows|link-list|install-steps|game-frame)\\b/i', $content ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'private_appshell_fixture_in_primary_preview',
				'message'  => 'Primary combined preview uses private AppShell fixture structure that Kiwe core does not render live. Use live-like DSA roots/internals for the primary proof.',
			];
		}

		return $findings;
	}

	private function disabled( string $code, string $message ): array {
		return [
			'ok'         => false,
			'httpStatus' => 403,
			'schema'     => 'kiwe.ai-companion.disabled.v1',
			'error'      => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}
}
