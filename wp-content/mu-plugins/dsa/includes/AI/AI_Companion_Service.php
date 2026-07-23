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
				'auditContext' => '/wp-json/dsa/v1/ai/audit-companion/context',
				'auditReview'  => '/wp-json/dsa/v1/ai/audit-companion/review',
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
				'auditCompanion' => [
					'context' => '/wp-json/dsa/v1/ai/audit-companion/context',
					'review'  => '/wp-json/dsa/v1/ai/audit-companion/review',
					'purpose' => 'Submit generated files for a compact deterministic pass/fail map before spending another model revision pass.',
				],
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
				'auditReview'  => '/wp-json/dsa/v1/ai/audit-companion/review',
				'validateBindings' => '/wp-json/dsa/v1/ai/validate-bindings',
				'stageExecution' => '/wp-json/dsa/v1/ai/staging/execute',
			],
		];
	}

	public function audit_context( array $args = [], array $auth = [] ): array {
		$args['mode'] = (string) ( $args['mode'] ?? 'audit' );
		$context      = $this->context( $args, $auth );
		if ( empty( $context['ok'] ) ) {
			return $context;
		}

		return [
			'ok'            => true,
			'schema'        => 'kiwe.audit-companion.context.v1',
			'generatedAt'   => gmdate( 'c' ),
			'mode'          => (string) ( $context['mode'] ?? 'audit' ),
			'purpose'       => 'Deterministic pre-revision audit lane. Use this after v1 output so browser AI revises concrete failures instead of rediscovering Kiwe rules token by token.',
			'routes'        => [
				'context' => '/wp-json/dsa/v1/ai/audit-companion/context',
				'review'  => '/wp-json/dsa/v1/ai/audit-companion/review',
			],
			'payloadShape'  => [
				'method' => 'POST',
				'body'   => [
					'mode'  => 'combined|website|theme|dynamic|audit',
					'files' => [
						'README.md' => 'file text',
						'combined-preview/index.html' => 'file text',
						'website/bricks-paste.html' => 'file text',
						'appshell-theme/import/<theme-id>/theme-package.json' => 'file text',
					],
				],
			],
			'gates'         => [
				'requiredOutputShape',
				'pageOnlyWebsiteArtifact',
				'themePackageSchemaAndSettings',
				'seamControlledDataRoles',
				'appshellGeometryAuthority',
				'combinedPreviewProof',
				'customDockLinks',
				'tokenPurity',
				'secretLeakage',
				'encodingMojibake',
			],
			'limits'        => [
				'maxReviewBytes' => (int) ( $this->settings()['max_review_bytes'] ?? 0 ),
				'filesAreNotStored' => true,
				'memoryStoresOnlyFingerprints' => ! empty( $this->settings()['memory_enabled'] ),
			],
			'contextCards'  => $context['cards'] ?? [],
			'contextHash'   => substr( hash( 'sha256', (string) wp_json_encode( $context['cards'] ?? [] ) ), 0, 32 ),
			'next'          => [
				'Generate or revise actual files.',
				'Submit those files to /ai/audit-companion/review.',
				'Fix every mustFix item, then re-submit until verdict is pass or only acknowledged warnings remain.',
				'Do not claim browser, Bricks import, WooCommerce, checkout/auth/cart, or live Kiwe install tests unless those tests actually ran.',
			],
		];
	}

	public function audit_review( array $args = [], array $auth = [] ): array {
		$review = $this->review_output( $args, $auth );
		if ( empty( $review['schema'] ) || ! empty( $review['error'] ) ) {
			return $review;
		}

		$findings = isset( $review['findings'] ) && is_array( $review['findings'] ) ? $review['findings'] : [];
		$must_fix = array_values(
			array_filter(
				$findings,
				static fn( array $finding ): bool => in_array( (string) ( $finding['severity'] ?? 'info' ), [ 'critical', 'error' ], true )
			)
		);
		$should_fix = array_values(
			array_filter(
				$findings,
				static fn( array $finding ): bool => 'warning' === (string) ( $finding['severity'] ?? 'info' )
			)
		);

		return [
			'ok'             => empty( $must_fix ),
			'schema'         => 'kiwe.audit-companion.review.v1',
			'mode'           => (string) ( $review['mode'] ?? ( $args['mode'] ?? 'combined' ) ),
			'verdict'        => empty( $must_fix ) ? ( empty( $should_fix ) ? 'pass' : 'pass_with_warnings' ) : 'needs_revision',
			'bytes'          => (int) ( $review['bytes'] ?? 0 ),
			'counts'         => $review['counts'] ?? [],
			'mustFix'        => $must_fix,
			'shouldFix'      => $should_fix,
			'passed'         => $review['auditMap']['passed'] ?? [],
			'revisionPrompt' => empty( $must_fix )
				? 'Audit Companion found no blocking deterministic errors. If warnings remain, address them when they affect the brief, then run official validators and live tests.'
				: 'Revise the actual files for every mustFix item, keep unchanged files intact, then re-submit the same file map to /ai/audit-companion/review. Do not browse the whole repo.',
			'trace'          => [
				'sourceReviewSchema' => (string) ( $review['schema'] ?? '' ),
				'fingerprintLane'    => 'audit-companion',
				'modelCalled'        => false,
			],
			'limitations'    => [
				'This deterministic review does not prove browser rendering, WordPress import, Bricks import, WooCommerce behavior, checkout/auth/cart behavior, or live Kiwe theme installation.',
				'Those tests must be reported separately with actual commands or browser/live-site evidence.',
			],
		];
	}

	public function review_output( array $args = [], array $auth = [] ): array {
		$settings = $this->settings();
		if ( empty( $settings['companion_enabled'] ) ) {
			return $this->disabled( 'companion_disabled', 'Kiwe Companion AI is disabled in Kiwe > AI.' );
		}

		$mode  = $this->mode( (string) ( $args['mode'] ?? 'combined' ), $settings );
		if ( '' === $mode ) {
			return [
				'ok'         => false,
				'httpStatus' => 403,
				'schema'     => 'kiwe.ai-companion.review.v1',
				'error'      => [
					'code'    => 'mode_disabled',
					'message' => 'That Companion mode is disabled in Kiwe > AI.',
				],
			];
		}
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

		$findings = array_merge( $findings, $this->review_required_shape( $mode, $path_map ) );
		$findings = array_merge( $findings, $this->review_data_roles( $path_map ) );
		$findings = array_merge( $findings, $this->review_text_encoding( $path_map ) );

		$theme_css = $this->file_like( $path_map, 'theme.css' );
		if ( '' !== $theme_css ) {
			$findings = array_merge( $findings, $this->review_theme_css( $theme_css ) );
		}

		$findings = array_merge( $findings, $this->review_theme_package( $mode, $path_map, $theme_css ) );

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
			'verdict'   => 0 === $counts['critical'] + $counts['error'] ? ( 0 === $counts['warning'] ? 'pass' : 'pass_with_warnings' ) : 'needs_revision',
			'bytes'     => $total,
			'counts'    => $counts,
			'findings'  => $findings,
			'auditMap'  => [
				'mustFix' => array_values(
					array_filter(
						$findings,
						static fn( array $finding ): bool => in_array( (string) ( $finding['severity'] ?? 'info' ), [ 'critical', 'error' ], true )
					)
				),
				'shouldFix' => array_values(
					array_filter(
						$findings,
						static fn( array $finding ): bool => 'warning' === (string) ( $finding['severity'] ?? 'info' )
					)
				),
				'passed' => $this->passed_review_checks( $mode, $path_map, $findings ),
			],
			'next'      => [
				'If errors exist, revise actual files before rerunning the audit.',
				'If review passes, run official validators and live WordPress/Bricks/Theme import tests when available.',
				'For token-efficient browser-AI revisions, use /wp-json/dsa/v1/ai/audit-companion/review and fix its mustFix list first.',
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

	private function has_file_like( array $path_map, string $needle ): bool {
		return '' !== $this->file_like( $path_map, $needle );
	}

	private function path_like( array $path_map, string $needle ): string {
		foreach ( $path_map as $path => $content ) {
			if ( str_ends_with( str_replace( '\\', '/', (string) $path ), $needle ) ) {
				return (string) $path;
			}
		}

		return '';
	}

	private function review_required_shape( string $mode, array $path_map ): array {
		$findings = [];
		if ( [] === $path_map ) {
			return [
				[
					'severity' => 'error',
					'code'     => 'no_files_submitted',
					'message'  => 'Audit Companion needs generated files in the files map. Submit path => content for the actual handoff files.',
				],
			];
		}

		$required = [ 'README.md' ];
		if ( in_array( $mode, [ 'website', 'combined' ], true ) ) {
			$required[] = 'website/bricks-paste.html';
			$required[] = 'website/bricks-notes.md';
		}
		if ( 'combined' === $mode ) {
			$required[] = 'combined-preview/index.html';
		}
		if ( in_array( $mode, [ 'theme', 'combined' ], true ) ) {
			$required[] = 'theme.json';
			$required[] = 'css/theme.css';
			$required[] = 'theme-package.json';
		}

		foreach ( $required as $needle ) {
			if ( ! $this->has_file_like( $path_map, $needle ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'missing_required_file',
					'message'  => sprintf( 'Required Kiwe %s handoff file is missing: %s.', $mode, $needle ),
					'path'     => $needle,
				];
			}
		}

		foreach ( array_keys( $path_map ) as $path ) {
			$path = str_replace( '\\', '/', (string) $path );
			if ( str_starts_with( $path, 'theme/' ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'obsolete_theme_folder',
					'message'  => 'Combined/theme handoffs must use appshell-theme/import/<theme-id>/... not a root theme/ folder.',
					'path'     => $path,
				];
			}
			if ( str_starts_with( $path, 'kiwe-settings/' ) ) {
				$findings[] = [
					'severity' => 'warning',
					'code'     => 'obsolete_separate_settings_lane',
					'message'  => 'AppShell theme settings should travel inside appshell-theme/import/<theme-id>/theme-package.json, not a separate kiwe-settings folder.',
					'path'     => $path,
				];
			}
			if ( preg_match( '#^(audit|data|reports?|validation-output)/#i', $path ) ) {
				$findings[] = [
					'severity' => 'warning',
					'code'     => 'non_required_handoff_lane',
					'message'  => 'This folder is not part of the compact required handoff shape. Keep output lean unless the brief explicitly asks for this artifact.',
					'path'     => $path,
				];
			}
		}

		return $findings;
	}

	private function review_data_roles( array $path_map ): array {
		$allowed = [
			'section', 'container', 'hero', 'lead', 'eyebrow', 'label', 'caption', 'hint', 'micro',
			'card', 'media', 'avatar', 'button', 'badge', 'chip', 'nav', 'actions', 'form', 'field',
			'input', 'textarea', 'select', 'modal', 'toast', 'testimonial', 'price', 'progress',
			'skeleton', 'footer', 'aside',
		];
		$allowed_map = array_fill_keys( $allowed, true );
		$findings    = [];

		foreach ( $path_map as $path => $content ) {
			if ( ! preg_match( '/\\.(?:html|md)$/i', (string) $path ) ) {
				continue;
			}
			if ( preg_match_all( '/data-role\\s*=\\s*["\\\']([^"\\\']+)["\\\']/i', (string) $content, $matches ) ) {
				foreach ( $matches[1] as $role ) {
					$role = sanitize_key( (string) $role );
					if ( '' !== $role && empty( $allowed_map[ $role ] ) ) {
						$findings[] = [
							'severity' => 'error',
							'code'     => 'unsupported_seam_data_role',
							'message'  => sprintf( 'Unsupported data-role "%s". Use official broad Seam roles only; put project concepts in classes or data-project-role.', $role ),
							'path'     => sanitize_text_field( (string) $path ),
						];
					}
				}
			}
		}

		return $findings;
	}

	private function review_text_encoding( array $path_map ): array {
		$findings = [];
		foreach ( $path_map as $path => $content ) {
			if ( preg_match( '/(?:â€”|â€“|â€™|â€œ|â€|â†’|Ã—|Â·|Â£|Â₹)/u', (string) $content ) ) {
				$findings[] = [
					'severity' => 'warning',
					'code'     => 'mojibake_text_encoding',
					'message'  => 'File appears to contain mojibake/encoding artifacts. Fix text encoding before handoff.',
					'path'     => sanitize_text_field( (string) $path ),
				];
			}
		}

		return $findings;
	}

	private function review_theme_package( string $mode, array $path_map, string $theme_css ): array {
		$findings    = [];
		$package     = $this->file_like( $path_map, 'theme-package.json' );
		$packagePath = $this->path_like( $path_map, 'theme-package.json' );
		if ( '' === $package ) {
			return $findings;
		}

		$json = json_decode( $package, true );
		if ( ! is_array( $json ) ) {
			return [
				[
					'severity' => 'error',
					'code'     => 'invalid_theme_package_json',
					'message'  => 'theme-package.json is not valid JSON.',
					'path'     => sanitize_text_field( $packagePath ),
				],
			];
		}

		foreach ( [ 'theme', 'settings', 'css' ] as $key ) {
			if ( ! array_key_exists( $key, $json ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'theme_package_missing_root_key',
					'message'  => sprintf( 'theme-package.json must contain root "%s". It is the single import file: theme manifest + settings + inline CSS.', $key ),
					'path'     => sanitize_text_field( $packagePath ),
				];
			}
		}

		if ( isset( $json['css'] ) && is_string( $json['css'] ) && preg_match( '/\\.css$/i', trim( $json['css'] ) ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'theme_package_css_not_inline',
				'message'  => 'theme-package.json root css must contain the actual import CSS, not a path such as theme.css.',
				'path'     => sanitize_text_field( $packagePath ),
			];
		} elseif ( '' !== $theme_css && isset( $json['css'] ) && is_string( $json['css'] ) && trim( (string) $json['css'] ) !== trim( $theme_css ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'theme_package_css_mismatch',
				'message'  => 'theme-package.json root css must byte-match appshell-theme/import/<theme-id>/css/theme.css.',
				'path'     => sanitize_text_field( $packagePath ),
			];
		}

		$settings = isset( $json['settings'] ) && is_array( $json['settings'] ) ? $json['settings'] : [];
		if ( 'combined' === $mode && empty( $settings['tokens'] ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'missing_theme_token_profile',
				'message'  => 'Combined marketplace AppShell themes with a distinctive visual personality must include settings.tokens so DSA, Seam page CSS, and Bricks global style share one token profile.',
				'path'     => sanitize_text_field( $packagePath ),
			];
		}
		if ( isset( $settings['tokens'] ) ) {
			$findings = array_merge( $findings, $this->review_token_settings( $settings['tokens'], $packagePath ) );
		}
		if ( isset( $settings['screens'] ) ) {
			$findings = array_merge( $findings, $this->review_screen_settings( $settings['screens'], $packagePath ) );
		}
		if ( isset( $settings['dock'] ) && is_array( $settings['dock'] ) ) {
			$findings = array_merge( $findings, $this->review_dock_settings( $settings['dock'], $packagePath ) );
		}

		return $findings;
	}

	private function review_token_settings( $tokens, string $path ): array {
		$findings = [];
		if ( ! is_array( $tokens ) ) {
			return [
				[
					'severity' => 'error',
					'code'     => 'invalid_theme_tokens',
					'message'  => 'settings.tokens must be an object containing enabled, profile_label, overrides, and optional bricks_theme_style.',
					'path'     => sanitize_text_field( $path ),
				],
			];
		}
		$allowed_top = [ 'enabled' => true, 'profile_label' => true, 'overrides' => true, 'bricks_theme_style' => true ];
		foreach ( $tokens as $key => $value ) {
			$key = (string) $key;
			if ( str_starts_with( $key, '--' ) || str_contains( $key, 'var(' ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'token_css_variable_key',
					'message'  => 'settings.tokens must use official token names in settings.tokens.overrides, not CSS variable keys.',
					'path'     => sanitize_text_field( $path ),
				];
			} elseif ( empty( $allowed_top[ $key ] ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'unsupported_tokens_key',
					'message'  => sprintf( 'Unsupported settings.tokens key "%s". Token values belong in settings.tokens.overrides.', $key ),
					'path'     => sanitize_text_field( $path ),
				];
			}
		}
		if ( empty( $tokens['overrides'] ) || ! is_array( $tokens['overrides'] ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'missing_token_overrides',
				'message'  => 'settings.tokens must include an overrides object keyed by official Kiwe universal token names.',
				'path'     => sanitize_text_field( $path ),
			];
		} else {
			foreach ( $tokens['overrides'] as $token => $value ) {
				$token = (string) $token;
				if ( str_starts_with( $token, '--' ) || str_contains( $token, 'var(' ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{1,80}$/i', $token ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'invalid_token_override_name',
						'message'  => sprintf( 'Invalid token override "%s". Use official names like color-brand, color-surface, radius-lg, shadow-md, type-h1.', $token ),
						'path'     => sanitize_text_field( $path ),
					];
				}
			}
		}

		return $findings;
	}

	private function review_screen_settings( $screens, string $path ): array {
		$allowed_fields = [
			'profile' => [ 'label', 'eyebrow', 'title', 'intro', 'accountLabel', 'editLabel', 'ordersTitle', 'ordersText', 'downloadsTitle', 'downloadsText', 'notificationsTitle', 'notificationsText', 'addressesTitle', 'addressesText', 'passwordTitle', 'passwordText', 'signOutLabel', 'recentOrdersTitle' ],
			'cart' => [ 'label', 'eyebrow', 'title', 'emptyTitle', 'emptyText', 'fbtTitle', 'checkoutLabel', 'checkoutEmptyLabel' ],
			'checkout' => [ 'label', 'title', 'loadingText', 'unavailableText', 'continueLabel', 'returnLabel', 'shippingToggleLabel', 'accountToggleLabel' ],
			'search' => [ 'label', 'eyebrow', 'title', 'intro', 'placeholder' ],
			'menu' => [ 'label', 'eyebrow', 'title', 'intro', 'contextTitle', 'dashboardLabel' ],
			'saved' => [ 'label', 'eyebrow', 'title', 'intro', 'emptyTitle', 'emptyText', 'wishlistLabel', 'bookmarksLabel', 'summaryWishlistLabel', 'summaryBookmarksLabel', 'summaryTotalLabel' ],
			'links' => [ 'label', 'eyebrow', 'title', 'intro', 'shopLabel', 'shopMeta', 'cartLabel', 'cartMeta' ],
			'notifications' => [ 'label', 'eyebrow', 'title', 'intro', 'topicsLegend', 'channelsLegend', 'appText', 'submitLabel', 'emailPlaceholder', 'phonePlaceholder' ],
			'ios-install' => [ 'label', 'eyebrow', 'title', 'intro', 'stepOneTitle', 'stepOneText', 'stepTwoTitle', 'stepTwoText', 'stepThreeTitle', 'stepThreeText', 'doneLabel' ],
			'games' => [ 'label', 'eyebrow', 'startTitle', 'startText', 'mobileStartText', 'chooseText', 'scoreLabel', 'bestLabel' ],
			'ai' => [ 'label', 'eyebrow', 'title', 'intro', 'emptyTitle', 'emptyText', 'chatPlaceholder' ],
		];
		$findings = [];
		if ( ! is_array( $screens ) ) {
			return [
				[
					'severity' => 'error',
					'code'     => 'invalid_screen_settings',
					'message'  => 'settings.screens must be an object keyed by registered DSA screen ids.',
					'path'     => sanitize_text_field( $path ),
				],
			];
		}

		foreach ( $screens as $screen => $config ) {
			$screen = sanitize_key( (string) $screen );
			if ( empty( $allowed_fields[ $screen ] ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'unsupported_screen_settings_key',
					'message'  => sprintf( 'Unsupported settings.screens key "%s". Use registered DSA screens only.', $screen ),
					'path'     => sanitize_text_field( $path ),
				];
				continue;
			}
			if ( ! is_array( $config ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'invalid_screen_copy_object',
					'message'  => sprintf( 'settings.screens.%s must be an object of presentation-only copy fields.', $screen ),
					'path'     => sanitize_text_field( $path ),
				];
				continue;
			}
			$field_map = array_fill_keys( $allowed_fields[ $screen ], true );
			foreach ( $config as $field => $value ) {
				if ( empty( $field_map[ (string) $field ] ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'unsupported_screen_copy_field',
						'message'  => sprintf( 'settings.screens.%s.%s is not a live Kiwe screen-copy field.', $screen, (string) $field ),
						'path'     => sanitize_text_field( $path ),
					];
				}
			}
		}

		return $findings;
	}

	private function review_dock_settings( array $dock, string $path ): array {
		$registered = array_fill_keys( [ 'menu', 'search', 'profile', 'links', 'saved', 'cart', 'theme', 'ai', 'notifications', 'ios-install', 'games' ], true );
		$custom     = [];
		if ( isset( $dock['custom_items'] ) && is_array( $dock['custom_items'] ) ) {
			foreach ( $dock['custom_items'] as $item ) {
				if ( is_array( $item ) && ! empty( $item['id'] ) ) {
					$custom[ sanitize_key( (string) $item['id'] ) ] = true;
				}
			}
		}

		$requested = [];
		foreach ( [ 'enabled_items', 'item_order' ] as $key ) {
			if ( isset( $dock[ $key ] ) && is_array( $dock[ $key ] ) ) {
				foreach ( $dock[ $key ] as $item ) {
					$requested[ sanitize_key( (string) $item ) ] = true;
				}
			}
		}
		if ( isset( $dock['focus_item'] ) ) {
			$requested[ sanitize_key( (string) $dock['focus_item'] ) ] = true;
		}

		$findings = [];
		foreach ( array_keys( $requested ) as $item ) {
			if ( '' === $item || isset( $registered[ $item ] ) || isset( $custom[ $item ] ) ) {
				continue;
			}
			$findings[] = [
				'severity' => 'error',
				'code'     => 'dock_item_without_registered_or_custom_authority',
				'message'  => sprintf( 'Dock item "%s" is neither a registered DSA module nor declared in settings.dock.custom_items.', $item ),
				'path'     => sanitize_text_field( $path ),
			];
		}

		return $findings;
	}

	private function theme_selector_targets_protected_root( string $selector ): bool {
		foreach ( explode( ',', $selector ) as $part ) {
			$part = trim( $part );
			if ( '' === $part || ! preg_match( '/(?:#dsa-surface|\[data-dsa-surface\]|\.dsa-installed-theme-[a-z0-9_-]+)(.*)$/i', $part, $match ) ) {
				continue;
			}
			$after = isset( $match[1] ) ? (string) $match[1] : '';
			if ( ! preg_match( '/[>+~\s]/', $after ) ) {
				return true;
			}
		}

		return false;
	}

	private function theme_css_paints_protected_root( string $css ): bool {
		$css = (string) preg_replace( '/\/\*[\s\S]*?\*\//', '', $css );
		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER ) ) {
			return false;
		}

		foreach ( $matches as $rule ) {
			$selector     = isset( $rule[1] ) ? (string) $rule[1] : '';
			$declarations = isset( $rule[2] ) ? (string) $rule[2] : '';
			if ( $this->theme_selector_targets_protected_root( $selector ) && preg_match( '/(?:^|;)\s*(?:background(?:-color|-image)?|border(?:-[a-z-]+)?|box-shadow|filter|backdrop-filter|opacity)\s*:/i', $declarations ) ) {
				return true;
			}
		}

		return false;
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
		if ( $this->theme_css_paints_protected_root( $css ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'protected_surface_root_paint_in_theme_css',
				'message'  => 'Importable theme.css paints the protected AppShell surface root. The DSA surface root is transparent Kiwe runtime scaffolding; theme CSS may set tokens/inherited typography on the root, but backgrounds, borders, shadows, opacity, and filters belong on dock/sheet/screen/panel parts.',
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
		if ( preg_match( '/--dsa-runtime-token-\\d{4}/i', $css ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'private_runtime_bridge_token_in_theme_css',
				'message'  => 'Importable theme.css references private --dsa-runtime-token-* bridge variables. Use public --kiwe-* or documented --kiwe-theme-* tokens.',
			];
		}
		if ( preg_match( '/(?:#dsa-surface|\\[data-dsa-surface\\])[^{}]*(?:data-dsa-dock|dsa-dock|data-dsa-dock-focus|data-dsa-dock-primary|dsa-ai-launcher|dsa-dock__button|data-dsa-module)[^{]*{[^}]*(?:\\bgap\\s*:|\\bmargin\\s*:|\\bpadding\\s*:|inline-size\\s*:|block-size\\s*:|min-width\\s*:|max-width\\s*:|min-height\\s*:|max-height\\s*:|\\bdisplay\\s*:|\\bflex\\s*:|\\border\\s*:|align-|justify-|place-|\\btransform\\s*:|\\btranslate\\s*:|\\bscale\\s*:|\\brotate\\s*:|\\boverflow)/is', $css ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'dock_geometry_or_arrangement_in_theme_css',
				'message'  => 'Importable theme.css owns dock geometry/arrangement/effect gutters. Kiwe Geometry Engine owns dock layout, sizing, spacing, transform, overflow, and split/focus placement.',
			];
		}
		if ( ! preg_match( '/\\bdata-dsa-part\\b/i', $css ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'missing_live_part_hooks_in_theme_css',
				'message'  => 'Importable theme.css never targets documented live AppShell part hooks. Broad root/panel color styling alone makes installed themes collapse into the same live UI with only palette changes.',
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
		if ( preg_match( '/\\bkiwe-preview-(?:panel|panel-heading|alpha|fbt|score|empty|muted)\\b/i', $content ) && preg_match( '/\\bdata-dsa-screen\\b/i', $content ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'preview_panel_identity_mismatch',
				'message'  => 'Primary combined preview styles DSA screens with preview-only panel classes. The approval preview must use live-like Kiwe DSA screen/sheet markup and put the visual identity in importable theme.css against live selectors.',
			];
		}
		foreach ( [
			'data-dsa-surface' => 'Combined preview must include the live AppShell surface root.',
			'data-dsa-ui-contract="2"' => 'Combined preview must prove the current DSA UI contract.',
			'data-dsa-dock-presentation' => 'Combined preview must expose dock presentation switching/proof.',
			'data-dsa-dock-orientation' => 'Combined preview must expose dock orientation switching/proof.',
			'dsa-dock-shape-pill' => 'Combined preview must prove pill dock shape.',
			'dsa-dock-shape-box' => 'Combined preview must prove rounded-box dock shape.',
			'dsa-dock-shape-square' => 'Combined preview must prove square/no-radius dock shape.',
			'data-dsa-profile-panel' => 'Combined preview must include Profile screen proof when AppShell direction is included.',
			'data-dsa-cart-panel' => 'Combined preview must include Cart screen proof when commerce/AppShell direction is included.',
			'data-dsa-search-panel' => 'Combined preview must include Search screen proof.',
			'data-dsa-ai-panel' => 'Combined preview must include AI screen proof or clearly mark the theme partial.',
		] as $needle => $message ) {
			if ( ! str_contains( $content, $needle ) ) {
				$findings[] = [
					'severity' => 'warning',
					'code'     => 'combined_preview_missing_proof',
					'message'  => $message,
				];
			}
		}

		return $findings;
	}

	private function passed_review_checks( string $mode, array $path_map, array $findings ): array {
		$codes = array_fill_keys( array_map( static fn( array $finding ): string => (string) ( $finding['code'] ?? '' ), $findings ), true );
		$passed = [];
		foreach ( [
			'requiredShapeChecked' => ! isset( $codes['missing_required_file'] ) && [] !== $path_map,
			'noSecretLeakagePattern' => ! isset( $codes['secret_like_content'] ),
			'seamDataRolesChecked' => ! isset( $codes['unsupported_seam_data_role'] ),
			'appshellGeometryChecked' => ! isset( $codes['protected_geometry_in_theme_css'] ) && ! isset( $codes['protected_surface_geometry_in_theme_css'] ) && ! isset( $codes['protected_surface_root_paint_in_theme_css'] ) && ! isset( $codes['dock_geometry_or_arrangement_in_theme_css'] ),
			'themePackageChecked' => ! isset( $codes['theme_package_missing_root_key'] ) && ! isset( $codes['theme_package_css_not_inline'] ) && ! isset( $codes['theme_package_css_mismatch'] ),
			'tokenPurityChecked' => ! isset( $codes['private_runtime_bridge_token_in_theme_css'] ) && ! isset( $codes['token_css_variable_key'] ) && ! isset( $codes['invalid_token_override_name'] ),
			'pageArtifactChecked' => ! isset( $codes['page_artifact_contains_appshell'] ),
		] as $label => $ok ) {
			if ( $ok ) {
				$passed[] = $label;
			}
		}
		if ( 'combined' === $mode && ! isset( $codes['combined_preview_missing'] ) ) {
			$passed[] = 'combinedPreviewPresenceChecked';
		}

		return $passed;
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
