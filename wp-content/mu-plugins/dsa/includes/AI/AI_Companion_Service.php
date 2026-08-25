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
	private const BRICKS_RESPONSIVE_LAYOUT_KEY_PATTERN = '/^_(?:cssCustom|direction|display|grid|gridItem|gridTemplate|gridAuto|align|justify|place|flex|gap|rowGap|columnGap|order|width|widthMin|widthMax|height|heightMin|heightMax|minWidth|maxWidth|minHeight|maxHeight|aspectRatio|margin|padding|position|top|right|bottom|left|zIndex|overflow|masonry)[A-Za-z0-9_]*:[a-z][a-z0-9_-]{1,48}(?::[a-z-]+)?$/i';
	private const BRICKS_COMPLEX_LAYOUT_PATTERN        = '/\b(?:bento|campaign-grid|masonry|editorial-grid)\b|grid-template-(?:columns|rows|areas)\s*:|grid-auto-(?:columns|rows|flow)\s*:|grid-column\s*:|grid-row\s*:|@media[\s\S]{0,1600}(?:grid-template|grid-column|grid-row|flex-direction|\.nc-section-head|\.seam-spread)/i';
	private const BRICKS_TOKEN_OWNED_CONTROL_PATTERN  = '/^_(?:typography|border|boxShadow|transform|grid|gridItem|gridTemplate|gridAuto|columnGap|rowGap|gap|width|widthMin|widthMax|height|heightMin|heightMax|margin|padding|top|right|bottom|left|font|lineHeight|letterSpacing)(?::|$)/';
	private const BRICKS_TOKEN_OWNED_NESTED_PATTERN   = '/^(?:font-size|fontSize|line-height|lineHeight|letter-spacing|letterSpacing|top|right|bottom|left|width|height|widthMin|widthMax|heightMin|heightMax|minWidth|maxWidth|minHeight|maxHeight|radius|offsetX|offsetY|blur|spread|translateX|translateY|translateZ|x|y|gap|rowGap|columnGap)$/i';
	private const BRICKS_STYLE_CONTROL_PATTERN        = '/^_(?:typography|background|gradient|border|boxShadow|transform|cssFilters|cssTransition|display|grid|gridItem|gridTemplate|gridAuto|direction|alignSelf|alignItems|justifyContent|flexWrap|flexGrow|flexShrink|flexBasis|columnGap|rowGap|gap|width|widthMin|widthMax|height|heightMin|heightMax|margin|padding|position|top|right|bottom|left|zIndex|overflow|color|textAlign|font|lineHeight|letterSpacing)(?::|$)/';
	private const BRICKS_STYLE_NESTED_PATTERN         = '/^(?:font-size|fontSize|line-height|lineHeight|letter-spacing|letterSpacing|top|right|bottom|left|width|height|widthMin|widthMax|heightMin|heightMax|minWidth|maxWidth|minHeight|maxHeight|radius|offsetX|offsetY|blur|spread|translateX|translateY|translateZ|x|y|gap|rowGap|columnGap|color|background|backgroundColor|background-color|backgroundImage|background-image|gradient|raw|fill|stroke|borderColor|border-color|shadowColor|shadow-color)$/i';
	private const BRICKS_LITERAL_LENGTH_PATTERN       = '/-?(?:\d*\.)?\d+(?:px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc)\b/i';
	private const BRICKS_TOKENIZED_LENGTH_PATTERN     = '/var\(\s*--(?:kiwe|seam)-|clamp\(/i';
	private const BRICKS_SELF_CLAMP_LENGTH_PATTERN    = '/clamp\(\s*(-?(?:\d*\.)?\d+(?:px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc)\b)\s*,\s*\1\s*,\s*\1\s*\)/i';
	private const BRICKS_TOKEN_FINDING_LIMIT          = 40;
	private const BRICKS_CSS_VAR_FINDING_LIMIT        = 40;
	private const BRICKS_SUPPORTED_TEMPLATE_VERSION_PATTERN = '/^2\.3(?:\.|$)/';
	private const BRICKS_MIN_ELEMENT_CONTROLS_PER_ELEMENT   = 1.15;
	private const BRICKS_MAX_CLASS_ONLY_ELEMENT_RATIO       = 0.25;
	private const BRICKS_REVIEW_ONLY_CODE_ELEMENT_ALLOWANCE_PATTERN = '/\b(?:review-only|manual-review|unsupported|code-exception)\b/i';
	private const BRICKS_COMPILE_UNSAFE_CONTROL_PATTERN     = '/^_(?:minWidth|maxWidth|minHeight|maxHeight)(?::|$)/';
	private const BRICKS_FONT_FAMILY_TOKEN_PATTERN          = '/var\(\s*--/i';
	private const BRICKS_SEMANTIC_HEADING_TAG_PATTERN       = '/^h[1-6]$/i';
	private const BRICKS_SEMANTIC_HEADING_TYPE_TOKEN_PATTERN = '/var\(\s*--(?:kiwe|seam)-type-h[1-6]\b/i';

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
				'validateBricksConversion' => '/wp-json/dsa/v1/ai/validate-bricks-conversion',
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

		$command      = sanitize_text_field( (string) ( $args['command'] ?? '' ) );
		$artifact_summary = sanitize_textarea_field( (string) ( $args['artifactSummary'] ?? '' ) );
		$site_graph_summary = sanitize_textarea_field( (string) ( $args['siteGraphSummary'] ?? '' ) );
		$command_gate = $this->command_gate( $command, $artifact_summary, $site_graph_summary );
		$phase_input  = ! empty( $command_gate['stop'] ) ? (string) ( $command_gate['kind'] ?? 'command-diagnostic' ) : (string) ( $args['phase'] ?? ( $command_gate['normalizedCommand'] ?? $command ) );
		$phase        = $this->phase( $phase_input );
		$sample_limit = max( 0, min( 12, absint( $args['sampleLimit'] ?? 4 ) ) );
		$graph        = $this->site_graph->graph( [ 'sampleLimit' => $sample_limit ] );
		$cards        = array_merge( $this->cards_for_command_gate( $command_gate ), $this->cards_for_phase( $phase ), $this->cards_for_mode( $mode ) );
		$cards        = array_slice( $cards, 0, max( 1, (int) $settings['max_context_cards'] ) );

		return [
			'ok'          => true,
			'schema'      => 'kiwe.ai-companion.context.v1',
			'generatedAt' => gmdate( 'c' ),
			'mode'        => $mode,
			'phase'       => $phase,
			'command'     => $command,
			'commandGate' => $command_gate,
			'purpose'     => 'Compact Kiwe contract/context cards for external AI tools building website/page, DSA theme, combined, dynamic-binding, audit, staging, or security work.',
			'useCompanion' => [
				'optional' => true,
				'fallback' => 'If this route is unavailable, disabled, rate-limited, over budget, or unclear, continue with the selected Kiwe command without Companion.',
				'modelCalled' => false,
				'role' => 'deterministic phase-aware contract oracle, not a creative co-author or full-codebase dump',
			],
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
					'https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/bricks-conversion-lite.md',
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

		$gate = isset( $context['commandGate'] ) && is_array( $context['commandGate'] ) ? $context['commandGate'] : [];
		if ( ! empty( $gate['stop'] ) ) {
			return [
				'ok'          => true,
				'schema'      => 'kiwe.ai-companion.answer.v1',
				'mode'        => (string) ( $context['mode'] ?? 'combined' ),
				'answer'      => (string) ( $gate['message'] ?? 'The command cannot be executed as written.' ),
				'commandGate' => $gate,
				'contextHash' => substr( hash( 'sha256', (string) wp_json_encode( $context['cards'] ?? [] ) ), 0, 32 ),
				'nextRoutes'  => [
					'context' => '/wp-json/dsa/v1/ai/companion/context',
					'auditReview' => '/wp-json/dsa/v1/ai/audit-companion/review',
				],
			];
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
				'validateBricksConversion' => '/wp-json/dsa/v1/ai/validate-bricks-conversion',
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
						'bricks-template/<page>-template-upload.json' => 'native Bricks My Templates upload JSON',
						'bricks-conversion/kiwe-bricks-conversion.json' => 'optional conversion/audit envelope JSON',
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
				'bricksConversionFidelity',
				'accessibilityLightDarkContrast',
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
		$findings = array_merge( $findings, $this->review_seam_css_ownership( $path_map ) );
		$findings = array_merge( $findings, $this->review_text_encoding( $path_map ) );
		$accessibility_context = strtolower( (string) wp_json_encode( [ $args['command'] ?? '', $args['phase'] ?? '', $args['mode'] ?? '', array_keys( $path_map ) ] ) );
		$accessibility_requested = (bool) preg_match( '/accessibility|a11y|kiwe-accessibility-plan\.json/', $accessibility_context );
		if ( $accessibility_requested ) {
			$findings = array_merge( $findings, $this->review_accessibility( $path_map, $accessibility_requested ) );
		}

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

		$conversion_json = $this->file_like( $path_map, 'kiwe-bricks-conversion.json' );
		if ( '' !== $conversion_json ) {
			$findings = array_merge( $findings, $this->review_bricks_conversion_package( $conversion_json, $path_map ) );
		}
		$template_upload = $this->first_bricks_template_upload( $path_map );
		if ( [] !== $template_upload ) {
			$findings = array_merge( $findings, $this->review_bricks_template_upload( (string) $template_upload['content'], (string) $template_upload['path'], $path_map ) );
		}
		if ( '' !== $conversion_json || [] !== $template_upload ) {
			$findings = array_merge( $findings, $this->review_lean_bricks_documentation( $path_map, (string) ( $args['command'] ?? '' ) ) );
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

	private function command_gate( string $command, string $artifact_summary = '', string $site_graph_summary = '' ): array {
		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $command ) ?? $command ) );
		$allowed    = [ '/ideate', '/convert /bricks', '/audit', '/accessibility', '/fix', '/redo' ];
		if ( '' === $normalized ) {
			return $this->command_gate_result( 'needs_input', 'missing_command', 'Use one command from the current SEAM 8.1 contract.', 'command-diagnostic', '', $allowed, [] );
		}

		$is_convert = 1 === preg_match( '#^/convert /bricks(?: /(dynamictags|queryloop))?$#', $normalized, $matches );
		if ( ! $is_convert && ! in_array( $normalized, [ '/ideate', '/audit', '/accessibility', '/fix', '/redo' ], true ) ) {
			return $this->command_gate_result( 'rejected', 'unknown_command_or_modifier', 'Unknown SEAM command, alias or modifier. Do not guess or continue.', 'command-diagnostic', $normalized, $allowed, [ 'SiteGraph and Design Context are inputs, not commands.', 'Only /dynamictags and /queryloop may modify /convert /bricks.' ] );
		}

		if ( $is_convert && ! $this->command_gate_has_page_artifact( $artifact_summary ) ) {
			return $this->command_gate_result( 'needs_input', 'binding_source_missing', '/convert /bricks needs the complete accepted HTML/CSS/JS project. It prepares binding intent and never emits Bricks JSON.', 'bricks-bindings', $normalized, [ 'Supply the accepted raw project' ], [ 'Site-specific bindings must be proven by SiteGraph.', 'Only seam.kiwe emits production Bricks JSON.' ] );
		}

		if ( in_array( $normalized, [ '/audit', '/accessibility', '/fix', '/redo' ], true ) && '' === trim( $artifact_summary ) ) {
			return $this->command_gate_result( 'needs_input', 'artifact_missing', $normalized . ' needs a concrete artifact. Do not inspect or change empty input.', ltrim( $normalized, '/' ), $normalized, [ 'Supply the source or generated artifact' ], [] );
		}

		$boundaries = $is_convert
			? [ 'Default: dynamic tags and query loops.', 'Use /dynamictags or /queryloop for one lane.', 'Output raw source plus kiwe.bricks-bindings.v1; never Bricks JSON.' ]
			: [];

		return $this->command_gate_result( 'ok', 'ok', 'Command is recognized by the SEAM 8.1 contract.', $is_convert ? 'bricks-bindings' : ltrim( $normalized, '/' ), $normalized, [], $boundaries );
	}

	private function command_gate_result( string $status, string $code, string $message, string $kind, string $normalized, array $suggestions, array $boundaries ): array {
		return [
			'schema'            => 'kiwe.command-diagnostic.v1',
			'status'            => $status,
			'stop'              => in_array( $status, [ 'rejected', 'needs_input', 'noop' ], true ),
			'code'              => $code,
			'kind'              => $kind,
			'normalizedCommand' => $normalized,
			'message'           => $message,
			'suggestions'       => array_values( $suggestions ),
			'boundaries'        => array_values( $boundaries ),
		];
	}

	private function command_gate_has_page_artifact( string $text ): bool {
		return (bool) preg_match( '/website[\\\\\/]bricks-paste\.html|bricks-paste\.html|(?:^|[\\s\\\\\/])[^\\s\\\\\/]+\.html?\\b|index\.html|html\\s*[,+&/]\\s*css|html\/css\/js|source project|website folder|webpage/i', $text );
	}

	private function command_gate_has_conversion_artifact( string $text ): bool {
		return (bool) preg_match( '/bricks-conversion[\\\\\/]kiwe-bricks-conversion\.json|kiwe-bricks-conversion\.json|bricks-template[\\\\\/][^\\\\\/\n]+\.json|template-upload\.json|"templateType"\s*:|"content"\s*:\s*\[/i', $text );
	}

	private function command_gate_has_theme_artifact( string $text ): bool {
		return (bool) preg_match( '/appshell-theme|theme-package\.json|css[\\\\\/]theme\.css|\btheme\.css\b|dsatheme|app\s*shell|appshell/i', $text );
	}

	private function command_gate_forbidden_bricks_source( string $text ): bool {
		return (bool) preg_match( '/combined-preview|appshell-theme|theme-package\.json|css[\\\\\/]theme\.css|\btheme\.css\b|data-dsa-surface|dsa[-\s]*(?:dock|sheet|screen|navbar)|appshell[-\s]*preview|app\s*shell[-\s]*preview/i', $text );
	}

	private function cards_for_command_gate( array $gate ): array {
		if ( empty( $gate['stop'] ) ) {
			return [];
		}

		return [
			[
				'id'    => 'command-gate-' . sanitize_key( (string) ( $gate['code'] ?? 'blocked' ) ),
				'title' => 'Stop: command boundary failed',
				'body'  => (string) ( $gate['message'] ?? 'The command cannot be executed as written.' ) . ' Suggested next command(s): ' . implode( ', ', array_map( 'sanitize_text_field', (array) ( $gate['suggestions'] ?? [] ) ) ),
			],
		];
	}

	private function phase( string $phase_or_command ): string {
		$text = strtolower( trim( $phase_or_command ) );
		if ( '' === $text ) {
			return '';
		}
		if ( preg_match( '/(?:^|\s)(?:\/ideate|\/creative|\/webdraft)\b/', $text ) ) {
			return 'ideate';
		}
		if ( preg_match( '/\/create.*\/preview.*(?:\/dsatheme|\/appshell|\/dsa|app shell)/', $text ) ) {
			return 'theme-preview-create';
		}
		if ( preg_match( '/\/create.*\/preview.*(?:\/combined|\/combine)/', $text ) ) {
			return 'combined-preview-create';
		}
		if ( preg_match( '/(?:\/build|\/create).*(?:dsathemeandhomepage|theme and homepage|homepage and theme)/', $text ) ) {
			return 'combined-assemble';
		}
		if ( preg_match( '/\/audit.*(?:\/bricksconversion|\/bricks-conversion|bricks conversion|bricks json|bricksjson|html-to-bricks)/', $text ) ) {
			return 'bricks-audit';
		}
		if ( preg_match( '/(?:\/create|\/build).*(?:\/accessibility|\/a11y|accessibility|a11y)/', $text ) ) {
			return 'accessibility-create';
		}
		if ( preg_match( '/^\s*\/(?:accessibility|a11y)\b/', $text ) ) {
			return 'accessibility-create';
		}
		if ( preg_match( '/\/audit.*(?:\/accessibility|\/a11y|accessibility|a11y)/', $text ) ) {
			return 'accessibility-audit';
		}
		if ( preg_match( '/(?:\/convert|\/export|\/translate|\/rebuild|\/adapt).*(?:\/bricks|bricks json|bricks conversion|html-to-bricks|html css to bricks)/', $text ) ) {
			return 'bricks-convert';
		}
		if ( preg_match( '/(?:^|\s)\/seamframework\b|(?:\/rebuild|\/convert|\/adapt).*(?:\/seamframework|\/seam|seam framework)/', $text ) ) {
			return 'seam-rebuild';
		}
		if ( preg_match( '/\/audit.*(?:\/seamframework|\/seam|seam framework)/', $text ) ) {
			return 'seam-audit';
		}
		if ( preg_match( '/(?:\/create|\/build).*(?:\/brickstheme|\/frameworkprofile|\/framework|bricks theme)/', $text ) ) {
			return 'framework-create';
		}
		if ( preg_match( '/\/audit.*(?:\/brickstheme|\/frameworkprofile|\/framework|bricks theme)/', $text ) ) {
			return 'framework-audit';
		}
		if ( preg_match( '/(?:\/create|\/build).*(?:\/dsatheme|\/appshell|\/dsa|app shell)/', $text ) ) {
			return 'theme-create';
		}
		if ( preg_match( '/\/audit.*(?:\/dsatheme|\/appshell|\/dsa|app shell)/', $text ) ) {
			return 'theme-audit';
		}
		if ( preg_match( '/\/audit.*(?:\/combined|\/combine)/', $text ) ) {
			return 'combined-audit';
		}
		if ( preg_match( '/(?:\/assemble|\/combine|\/combined)/', $text ) ) {
			return 'combined-assemble';
		}
		if ( preg_match( '/(?:\/dynamic|\/sitegraph|\/binding|\/bindings)/', $text ) ) {
			return 'dynamic';
		}
		if ( preg_match( '/(?:\/apply|\/staging)/', $text ) ) {
			return 'staging';
		}

		return sanitize_key( $phase_or_command );
	}

	private function cards_for_phase( string $phase ): array {
		if ( '' === $phase ) {
			return [];
		}
		$compiler_cards = [
			'bricks-convert' => [ 'id' => 'phase-seam-compiler-raw', 'title' => 'Run deterministic Bricks conversion', 'body' => 'Run SEAM Compiler 0.13.0 on the arbitrary HTML/CSS/JS project. Choose Framework-neutral raw output or one-pass Bricks + SEAM Framework; both discover any number of pages, may split header/footer/content, and use native Bricks controls before scoped CSS. Browser AI must not author production JSON.' ],
			'bricks-audit'   => [ 'id' => 'phase-seam-compiler-audit', 'title' => 'Audit raw conversion with valid proof', 'body' => 'Audit hierarchy, native coverage, safe behavior, and source parity. Require matching viewport provenance before a visual percentage. Source defects remain source parity; stale CSS, foreign overlays, or mismatched canvases make visual proof INCOMPLETE.' ],
			'seam-rebuild'   => [ 'id' => 'phase-seam-framework-optional', 'title' => 'Optimize the raw conversion with Framework', 'body' => 'Run optional /seamframework after raw Convert. Emit one Framework Profile first and dependent templates second. Do not redesign or ask AI to invent tokens and classes.' ],
			'seam-audit'     => [ 'id' => 'phase-seam-framework-package-audit', 'title' => 'Audit profile and templates together', 'body' => 'Verify profile-before-template installation, defined variables/classes, Theme Style ownership, element exceptions, unsupported CSS, and unchanged visual intent as one deterministic package.' ],
		];
		if ( isset( $compiler_cards[ $phase ] ) ) {
			return [ $compiler_cards[ $phase ] ];
		}

		$cards = [
			'ideate' => [
				'id'    => 'phase-ideate-adaptive-intake',
				'title' => 'Interview first, then create one homepage',
				'body'  => 'Collect project identity, defined site type, audience, goal, logo/brand evidence, visual direction, required sections, and constraints in short groups. Ask framework-neutral versus headless Seam last. Seam may add canonical semantic attributes, exact universal tokens, project tokens, and real Geometry clamp fallback without changing the visual thesis. After delivery, refine through normal conversation; do not restart the interview.',
			],
			'seam-rebuild' => [
				'id'    => 'phase-seam-rebuild-preserve-visual-thesis',
				'title' => 'Rebuild with Seam without flattening design',
				'body'  => 'Preserve the approved draft, replace arbitrary semantics with official Seam roles/classes/tokens, and keep page behavior free of Kiwe-owned cart/auth/search/save/AI authority.',
			],
			'seam-audit' => [
				'id'    => 'phase-seam-audit-official-vocabulary',
				'title' => 'Audit Seam vocabulary and page boundary',
				'body'  => 'Check official data-role values, class vocabulary use, responsive fit, Bricks-friendly structure, and absence of duplicated app capability logic.',
			],
			'framework-create' => [
				'id'    => 'phase-framework-profile-tokens-only',
				'title' => 'Create only the global Framework token profile',
				'body'  => 'Framework profile output is settings.tokens only: official Kiwe universal tokens and safe Bricks global style metadata. No AppShell, product, runtime, or element-level styling.',
			],
			'framework-audit' => [
				'id'    => 'phase-framework-audit-token-lane',
				'title' => 'Audit the token lane only',
				'body'  => 'Reject raw private variables, AppShell settings, Bricks element-level styling, and non-token authority in kiwe.framework-profile.v1.',
			],
			'theme-create' => [
				'id'    => 'phase-dsa-theme-live-parts',
				'title' => 'Create a real AppShell theme, not color-only skin',
				'body'  => 'Style documented live roots and data-dsa-part hooks for every registered screen while Kiwe core owns geometry, lifecycle, focus, cart, checkout, auth, search, save, and AI behavior.',
			],
			'theme-preview-create' => [
				'id'    => 'phase-theme-preview-proof',
				'title' => 'Create DSA theme preview proof',
				'body'  => 'Create or revise only appshell-theme/preview. Prove the AppShell theme against live-like DSA roots, screen/sheet internals, dock modes, Geometry Engine states, and installed theme.css. Do not convert this preview to Bricks.',
			],
			'theme-audit' => [
				'id'    => 'phase-dsa-theme-audit-fixture-live-match',
				'title' => 'Audit preview/live AppShell match',
				'body'  => 'Reject preview-only selectors in import CSS, protected dock/sheet/screen geometry, anonymous raw literals, unreadable rails, blank dock icons, and duplicate stacked sheet launches.',
			],
			'combined-preview-create' => [
				'id'    => 'phase-combined-preview-proof',
				'title' => 'Create combined preview proof',
				'body'  => 'Create or revise only combined-preview. It must show the page behind Kiwe AppShell with variation controls and must never be used as /convert /bricks source.',
			],
			'combined-assemble' => [
				'id'    => 'phase-combined-one-preview',
				'title' => 'Assemble approved lanes into one combined proof',
				'body'  => 'Use one combined preview with page behind AppShell, variation controls, page launchers, and the importable theme CSS linked. Do not redesign approved lanes unless asked.',
			],
			'combined-audit' => [
				'id'    => 'phase-combined-audit-three-lanes',
				'title' => 'Audit website, AppShell, and combined preview together',
				'body'  => 'Review the page-only Bricks artifact, importable AppShell package, and combined preview as separate authority lanes that visually agree.',
			],
			'dynamic' => [
				'id'    => 'phase-dynamic-sitegraph-truth',
				'title' => 'Bind through Site Graph, not guesses',
				'body'  => 'Use real Site Graph/Data facts for products, terms, pages, media, custom types, fields, Bricks query loops, dynamic tags, conditions, interactions, and Kiwe launchers. Do not mutate.',
			],
			'bricks-convert' => [
				'id'    => 'phase-bricks-convert-no-loss-json',
				'title' => 'Convert to Bricks with no-loss proof',
				'body'  => 'Produce one token-pure native Bricks My Templates upload JSON at bricks-template/[page]-template-upload.json. Target the public Bricks 2.3.x importer/runtime unless Site Graph reports a newer public compatible version. Bricks native converter output, Code2Bricks-style output, and third-party Bricks AI skills may be used as scaffold/reference only; final Kiwe output must normalize representable design into native Bricks elements, controls, variables, attributes, interactions, conditions, and query intent. Preserve Seam classes/data attributes/ARIA/Kiwe launchers, map query-loop/dynamic/condition/interaction intent, and follow the Kiwe token ladder in native element settings: official Kiwe/Seam token first, declared project variable second, real fluid clamp only for proven responsive min/max states. Reserved prefixes are not enough: every var(--kiwe-*) or var(--seam-*) consumed by a template must exist in Kiwe’s real universal token registry/runtime. If it does not, map to an existing official token or declare a project variable such as --nc-* in the paired Framework profile. Use a single visual owner: element-native controls own render/edit fidelity in full-page template uploads, while imported global_classes are semantic/name-only. Do not duplicate paint, layout, radius, spacing, shadows, or typography into styled global_classes because that creates Bricks ghost styling after designers remove or change one visible layer. Do not use no-op clamps such as clamp(22px, 22px, 22px). Do not put fallback values in Bricks render-owner CSS variables: use var(--token), not var(--token, fallback). SeamFlow requires Kiwe > Framework profile push before template import so missing variables fail visibly instead of rendering from hidden fallback values. Store native Bricks global variable names without leading --, use source-backed sizing controls (_widthMax/_widthMin/_heightMax/_heightMin), keep var(...) font stacks out of _typography.font-family because Bricks quotes them, store background/border/typography colors as Bricks color objects ({ raw: "var(--kiwe-color-text)" }) rather than plain strings, use _gradient for gradients instead of _background.color, and store border radius as _border.radius.top/right/bottom/left rather than CSS corner keys such as topLeft/topRight/bottomRight/bottomLeft. Reusable styled project classes belong in the Framework profile push or a dedicated class-library artifact, not duplicated inside the page template upload. Do not emit notes/reports unless /document is present. Do not mutate WordPress or Bricks.',
			],
			'bricks-audit' => [
				'id'    => 'phase-bricks-audit-conversion-fidelity',
				'title' => 'Audit Bricks conversion fidelity',
				'body'  => 'Reject page artifacts that include AppShell shell markup, lost Seam classes or data-dsa-open-module launchers, fake Bricks element names, broken parent/child references, unsafe JavaScript interactions, unverified dynamic tags, query-template markers without Bricks query-loop intent, and native Bricks design controls that hardcode lengths instead of consuming Kiwe/Seam tokens.',
			],
			'accessibility-create' => [
				'id'    => 'phase-accessibility-create-token-contrast',
				'title' => 'Improve the current artifact without changing lanes',
				'body'  => 'Audit semantics, keyboard/focus behavior, readable text, reflow, reduced motion, targets, and measured light/dark foreground-background pairs. Preserve creative art direction. Raw HTML/CSS/JS remains raw; Bricks remains Bricks; Framework remains Framework. Report automated evidence separately from manual checks and never call the score WCAG compliance.',
			],
			'accessibility-audit' => [
				'id'    => 'phase-accessibility-audit-light-dark-contrast',
				'title' => 'Audit light/dark contrast deterministically',
				'body'  => 'Fail obvious low-contrast literal pairs, missing dark-mode proof, missing accessibility plan fields, color work that bypasses Kiwe/Seam tokens or Bricks global theme-style slots, and critical labels/titles/pills/buttons/cards clipped by constrained Geometry/Seam sizing. Full font-size preference audit is intentionally out of scope for now.',
			],
			'staging' => [
				'id'    => 'phase-staging-controlled-executor-only',
				'title' => 'Staging apply is controlled executor work',
				'body'  => 'Only proceed with explicit staging confirmation, mutation flags, rollback capture, and executor routes. Browser AI should not bypass this with direct WordPress or Bricks writes.',
			],
		];

		return isset( $cards[ $phase ] ) ? [ $cards[ $phase ] ] : [];
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
				'id'      => 'seam-capability-attributes',
				'title'   => 'Seam can call Kiwe Appsite capabilities by attribute',
				'body'    => 'During optional /seamframework, use the deterministic compiler to preserve the raw conversion while producing one Framework Profile plus dependent templates. Theme Style owns body/headings/links/background; project classes own repeated design; elements keep true exceptions. Push the profile before importing templates.',
				'applies' => [ 'website', 'combined', 'dynamic', 'audit' ],
			],
			[
				'id'      => 'bricks-native-token-purity',
				'title'   => 'Bricks-native controls must consume Framework tokens',
				'body'    => 'A Kiwe Framework profile supplies token values; it does not rewrite hardcoded Bricks JSON. During SEAM Compiler validation, fail native element settings or global_classes that hardcode design lengths such as padding: 28px, radius: 24px, min-height: 390px, font-size: 2.35rem, gaps, shadows, or transform offsets. Use official var(--kiwe-*)/var(--seam-*) tokens when the meaning and property domain match; use declared project variables for stable art-direction constants; use real tokenized clamp() only for proven responsive interpolation. No-op clamps such as clamp(22px, 22px, 22px) do not count. For native Bricks template uploads, inline fallbacks are not allowed; use bare var(--token) and require Kiwe > Framework profile push before template import. Bricks border radius must be stored as _border.radius.top/right/bottom/left, not CSS corner keys such as topLeft/topRight/bottomRight/bottomLeft. Semantic Bricks Heading elements tagged h1-h6 must not carry local official heading-token font-size locks such as var(--kiwe-type-h3, 2rem); H1-H6 scale belongs in Kiwe > Framework / Bricks Theme Style so designers can change the heading tag and get the matching Bricks heading size.',
				'applies' => [ 'website', 'combined', 'dynamic', 'audit' ],
			],
			[
				'id'      => 'appshell-geometry-owned-by-core',
				'title'   => 'AppShell geometry is Kiwe core authority',
				'body'    => 'Theme CSS can style color, typography, borders, radii, shadows, states, cards, forms, and rails. It must not own dock/sheet/screen/backdrop fixed positioning, viewport offsets, or layout measurement.',
				'applies' => [ 'theme', 'combined', 'audit' ],
			],
			[
				'id'      => 'theme-css-token-purity',
				'title'   => 'Theme CSS consumes tokens, not magic literals',
				'body'    => 'Concrete values belong in settings.tokens or Kiwe core token registries. Importable AppShell theme.css should consume official --kiwe-* variables, documented --kiwe-theme-* aliases, or Kiwe/DSA geometry variables, and must not contain anonymous raw length, color, or shadow/effect literals.',
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

		$common = array_values(
			array_filter(
				$common,
				static fn( array $card ): bool => 'bricks-native-token-purity' !== (string) ( $card['id'] ?? '' ) && in_array( $mode, $card['applies'], true )
			)
		);
		array_unshift(
			$common,
			[
				'id'      => 'seam-compiler-stage-boundary',
				'title'   => 'Raw Convert first; Framework only when requested',
				'body'    => 'SEAM Compiler is deterministic authority. Raw conversion is Framework-neutral. Optional Framework moves repeated design into Theme Style, universal tokens/palette, and project variables/classes while elements keep genuine exceptions.',
				'applies' => [ $mode ],
			]
		);
		return $common;
	}

	private function answer_for_question( string $question, string $mode ): array {
		$question_lc = strtolower( $question );
		if ( str_contains( $question_lc, 'attribute' ) || str_contains( $question_lc, 'wishlist' ) || str_contains( $question_lc, 'bookmark' ) || str_contains( $question_lc, 'notification' ) || str_contains( $question_lc, 'dark mode' ) || str_contains( $question_lc, 'theme toggle' ) || str_contains( $question_lc, 'save button' ) ) {
			return [
				'summary' => 'Use Seam for meaning and Kiwe capability attributes for appsite behavior. Preserve the UI; add the smallest live attribute; do not recreate Kiwe runtime in page JavaScript.',
				'do'      => [ 'Use data-kiwe-save="wishlist" or "bookmark" for save controls.', 'Use data-kiwe-notifications only on explicit visitor-click controls.', 'Use data-kiwe-theme-toggle for page/header light-dark controls.', 'Use data-kiwe-contact and data-kiwe-social with canonical Kiwe dynamic URLs for public contact, directions, WhatsApp, and social CTAs.', 'Use native Bricks Map elements with Kiwe address dynamic tags instead of map iframes or page-owned map JavaScript.', 'Use data-dsa-open-module for Kiwe screen launchers.', 'Use semantic section IDs and labels for Menu context.' ],
				'dont'    => [ 'Do not invent candidate attributes unless the contract marks them live.', 'Do not add DSA shell markup to website/bricks-paste.html.', 'Do not write duplicate cart/save/notification/theme JavaScript.' ],
			];
		}
		if ( str_contains( $question_lc, 'bricks conversion' ) || str_contains( $question_lc, 'bricks json' ) || str_contains( $question_lc, 'html-to-bricks' ) || str_contains( $question_lc, 'convert to bricks' ) ) {
			return [
				'summary' => 'Treat Bricks conversion as a reviewable no-loss package: native Bricks elements plus a Kiwe fidelity manifest, not a direct save.',
				'do'      => [ 'Target public Bricks 2.3.x template import/runtime unless Site Graph proves a newer public compatible version.', 'Preserve Seam classes, data-role, public Kiwe capability attributes, ARIA, IDs, and canonical data-dsa-open-module launchers.', 'Map query loops, dynamic tags, conditions, and interactions from Site Graph and /ai/bricks/context.', 'Use Kiwe/Seam variables, declared project variables, or real tokenized clamp() expressions inside Bricks-native settings and global_classes for spacing, sizing, radius, type, shadows, transform offsets, and responsive layout; never use no-op clamp(v, v, v) wrappers.', 'Use bare CSS variables in native settings/global_classes, e.g. var(--nc-card-radius), never var(--nc-card-radius, 24px). Require Kiwe > Framework profile push before template import. Store global variable names without leading --, use _widthMax/_widthMin/_heightMax/_heightMin for sizing, do not put var(...) font stacks in _typography.font-family, store colors as Bricks color objects, use _gradient for gradients, and store border radius as _border.radius.top/right/bottom/left.', 'Keep full-page template visuals resilient when Bricks skips/remaps existing global class names by placing enough editable native controls on elements, especially for grid/flex, spacing, sizing, typography, paint, radius, shadows, and responsive overrides.' ],
				'dont'    => [ 'Do not put AppShell shell markup in website/bricks-paste.html.', 'Do not hide the whole page in one Code element when native Bricks elements can represent it.', 'Do not ship CSS/JS Code elements from Bricks native converter, Code2Bricks, or another external converter as production output unless explicitly marked review-only unsupported.', 'Do not rely mainly on global_classes hydration for rendered design.', 'Do not duplicate visual styles in both element-native controls and styled global_classes for a full-page template upload.', 'Do not hardcode native Bricks design lengths such as 28px padding, 390px min-height, or 2.35rem font-size.', 'Do not put official H1-H6 token font-size locks directly on semantic Bricks Heading elements.', 'Do not claim WordPress/Bricks/Woo writes without controlled executor evidence.' ],
			];
		}
		if ( str_contains( $question_lc, 'bricks conversion' ) || str_contains( $question_lc, 'bricks json' ) || str_contains( $question_lc, 'html-to-bricks' ) || str_contains( $question_lc, 'convert to bricks' ) ) {
			return [
				'summary' => 'Choose deterministic raw SEAM Compiler conversion or the one-pass Bricks + SEAM Framework mode. Browser AI must not author either production artifact.',
				'do'      => [ 'Accept arbitrary HTML/CSS/JS projects.', 'Discover all pages without route-name assumptions.', 'Use native Bricks controls before scoped CSS.', 'Treat source defects as parity, not converter failures.', 'Require matching viewport provenance for visual scores.' ],
				'dont'    => [ 'Do not require a Framework Profile for raw Convert.', 'Do not let browser AI author production JSON.', 'Do not inject Seam tokens/classes into raw conversion.', 'Do not score contaminated or mismatched screenshots.' ],
			];
		}
		if ( str_contains( $question_lc, 'theme' ) || str_contains( $question_lc, 'dsa' ) || 'theme' === $mode ) {
			return [
				'summary' => 'Build theme packages as styling and safe settings only; Kiwe core owns AppShell geometry and runtime behavior.',
				'do'      => [ 'Use documented live roots/selectors.', 'Consume official Kiwe/Seam tokens and Geometry Engine variables in import CSS.', 'Put screen copy/settings inside the theme package when supported.' ],
				'dont'    => [ 'Do not set fixed/inset/z-index/viewport geometry for dock/sheet/screen/backdrop.', 'Do not put anonymous raw length/color/shadow literals in importable theme.css.', 'Do not invent runtime modules.' ],
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

	private function has_path_matching( array $path_map, string $pattern ): bool {
		foreach ( $path_map as $path => $content ) {
			if ( preg_match( $pattern, str_replace( '\\', '/', (string) $path ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function path_like( array $path_map, string $needle ): string {
		foreach ( $path_map as $path => $content ) {
			if ( str_ends_with( str_replace( '\\', '/', (string) $path ), $needle ) ) {
				return (string) $path;
			}
		}

		return '';
	}

	private function is_likely_bricks_template_export( $data ): bool {
		if ( ! is_array( $data ) || isset( $data['elements'] ) || 'kiwe.bricks-conversion.v1' === (string) ( $data['schema'] ?? '' ) ) {
			return false;
		}

		return isset( $data['content'] ) || isset( $data['header'] ) || isset( $data['footer'] ) || isset( $data['templateType'] ) || isset( $data['pageSettings'] ) || isset( $data['bundles'] );
	}

	private function first_bricks_template_upload( array $path_map ): array {
		foreach ( $path_map as $path => $content ) {
			$normalized = str_replace( '\\', '/', (string) $path );
			if ( ! preg_match( '#(?:^|/)bricks-template/[^/]+\.json$|template-upload\.json$|\.bricks-template\.json$#i', $normalized ) ) {
				continue;
			}
			$data = json_decode( (string) $content, true );
			if ( $this->is_likely_bricks_template_export( $data ) ) {
				return [
					'path'    => $normalized,
					'content' => (string) $content,
					'data'    => $data,
				];
			}
		}

		foreach ( $path_map as $path => $content ) {
			$data = json_decode( (string) $content, true );
			if ( $this->is_likely_bricks_template_export( $data ) ) {
				return [
					'path'    => str_replace( '\\', '/', (string) $path ),
					'content' => (string) $content,
					'data'    => $data,
				];
			}
		}

		return [];
	}

	private function collect_bricks_responsive_layout_overrides( array $elements ): array {
		$out = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			foreach ( $settings as $key => $value ) {
				if ( preg_match( self::BRICKS_RESPONSIVE_LAYOUT_KEY_PATTERN, (string) $key ) ) {
					$out[] = [
						'id'      => (string) ( $element['id'] ?? '' ),
						'key'     => (string) $key,
						'value'   => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ),
						'classes' => (string) ( $settings['_cssClasses'] ?? '' ),
						'cssId'   => (string) ( $settings['_cssId'] ?? '' ),
					];
				}
			}
		}
		return $out;
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

	private function review_seam_css_ownership( array $path_map ): array {
		$findings = [];
		foreach ( $path_map as $path => $content ) {
			if ( ! preg_match( '/\.(?:html|css|json)$/i', (string) $path ) ) {
				continue;
			}
			$seen = [];
			if ( preg_match_all( '/(?:^|[{}]|\\\\n|\\\\r|\n|\r)\s*([^{}@]{0,760})\{/i', (string) $content, $matches ) ) {
				foreach ( $matches[1] as $selector_group ) {
					foreach ( explode( ',', (string) $selector_group ) as $selector ) {
						$selector = trim( preg_replace( '/\/\*[\s\S]*?\*\//', '', (string) $selector ) );
						$selector = trim( preg_replace( '/\s+/', ' ', str_replace( [ '\\n', '\\r' ], ' ', $selector ) ) );
						if ( '' === $selector || isset( $seen[ $selector ] ) || ! preg_match( '/(?:^|[\s>+~(:])\.seam-[a-z0-9_-]+|\[data-(?:flow|role|tone|state)\b/i', $selector ) ) {
							continue;
						}
						$seen[ $selector ] = true;
						$findings[]        = [
							'severity' => 'error',
							'code'     => 'bare_seam_selector_redefined',
							'message'  => 'Project CSS must not redefine Seam framework selectors, even when scoped under a project class. Use Seam classes/attributes in markup, but put visual CSS on project-owned classes so framework flow classes cannot shrink or rearrange Bricks layouts.',
							'path'     => sanitize_text_field( (string) $path ),
							'selector' => sanitize_text_field( $selector ),
						];
					}
				}
			}
			if ( preg_match( '/\.html?$/i', (string) $path ) && preg_match( '/<[a-z][a-z0-9-]*\b[^>]*class\s*=\s*["\'][^"\']*\bseam-nav\b[^"\']*\bseam-horizontal-rail\b[^"\']*["\'][^>]*>/i', (string) $content ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'seam_nav_rail_wrapper',
					'message'  => 'A seam-nav wrapper also carries seam-horizontal-rail. Keep nav/sticky/container shells as normal layout and put Seam rail flow only on the actual item track.',
					'path'     => sanitize_text_field( (string) $path ),
				];
			}
			if ( preg_match( '/\.html?$/i', (string) $path ) && preg_match( '/<[a-z][a-z0-9-]*\b[^>]*class\s*=\s*["\'][^"\']*\bseam-nav\b[^"\']*["\'][^>]*data-flow\s*=\s*["\'](?:reel|horizontal-rail)["\'][^>]*>/i', (string) $content ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'seam_nav_rail_flow',
					'message'  => 'A seam-nav wrapper also carries data-flow="horizontal-rail". Keep nav/sticky/container shells as normal layout and put Seam rail flow only on the actual item track.',
					'path'     => sanitize_text_field( (string) $path ),
				];
			}
			if ( preg_match( '/\.html?$/i', (string) $path ) && preg_match_all( '/<([a-z][a-z0-9-]*)\b[^>]*(?:class\s*=\s*["\'][^"\']*\bseam-horizontal-rail\b[^"\']*["\']|data-flow\s*=\s*["\'](?:reel|horizontal-rail)["\'])[^>]*>([\s\S]{0,5200}?)(?:<\/\1>|$)/i', (string) $content, $rail_matches, PREG_SET_ORDER ) ) {
				foreach ( $rail_matches as $rail_match ) {
					$inner = (string) ( $rail_match[2] ?? '' );
					if ( ! preg_match( '/(?:class\s*=\s*["\'][^"\']*\bseam-horizontal-rail\b[^"\']*["\']|data-flow\s*=\s*["\'](?:reel|horizontal-rail)["\']|class\s*=\s*["\'][^"\']*\bseam-container\b)/i', $inner ) ) {
						continue;
					}
					$findings[] = [
						'severity' => 'error',
						'code'     => 'seam_rail_on_wrapper',
						'message'  => 'Seam rail flow is applied to a wrapper that contains a container or descendant rail. Keep outer nav/sticky/container shells as normal layout and put .seam-horizontal-rail or data-flow="horizontal-rail" only on the actual item track.',
						'path'     => sanitize_text_field( (string) $path ),
					];
				}
			}
		}

		return $findings;
	}

	private function review_text_encoding( array $path_map ): array {
		$findings = [];
		foreach ( $path_map as $path => $content ) {
			if ( preg_match( '/(?:Ã¢â‚¬â€|Ã¢â‚¬â€œ|Ã¢â‚¬â„¢|Ã¢â‚¬Å“|Ã¢â‚¬Â|Ã¢â€ â€™|Ãƒâ€”|Ã‚Â·|Ã‚Â£|Ã‚â‚¹)/u', (string) $content ) ) {
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

	private function review_accessibility( array $path_map, bool $strict = false ): array {
		$report = ( new Accessibility_Validator() )->validate_files(
			$path_map,
			[
				'requirePlan' => $strict,
				'strictDark'  => $strict,
			]
		);

		$findings = [];
		foreach ( (array) ( $report['findings'] ?? [] ) as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$normalized = [
				'severity' => sanitize_key( (string) ( $finding['severity'] ?? 'info' ) ),
				'code'     => sanitize_key( (string) ( $finding['code'] ?? 'accessibility_finding' ) ),
				'message'  => sanitize_text_field( (string) ( $finding['message'] ?? 'Accessibility finding.' ) ),
			];
			if ( isset( $finding['path'] ) && '' !== (string) $finding['path'] ) {
				$normalized['path'] = sanitize_text_field( (string) $finding['path'] );
			}
			if ( isset( $finding['selector'] ) && '' !== (string) $finding['selector'] ) {
				$normalized['selector'] = sanitize_text_field( (string) $finding['selector'] );
			}
			$findings[] = $normalized;
		}

		return $findings;
	}

	private function review_lean_bricks_documentation( array $path_map, string $command ): array {
		if ( preg_match( '#/(?:document|docs?)\b#i', $command ) ) {
			return [];
		}

		$findings = [];
		foreach ( array_keys( $path_map ) as $path ) {
			$normalized = str_replace( '\\', '/', (string) $path );
			if ( ! preg_match( '#(?:^|/)(?:BRICKS-CONVERSION-NOTES|FRAMEWORK-NOTES|BRICKS-CONVERSION-AUDIT|LOCAL-VALIDATION|CURRENT-MAIN-BRICKS-AUDIT|validation-report)[^/]*\.(?:md|json|txt)$#i', $normalized ) ) {
				continue;
			}
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_lean_output_emitted_docs_without_document',
				'message'  => 'Lean SEAM Compiler output must not include unrelated notes or duplicate documentation artifacts.',
				'path'     => sanitize_text_field( $normalized ),
			];
		}

		return $findings;
	}

	private function review_bricks_template_upload( string $template_json, string $path, array $path_map = [] ): array {
		$data = json_decode( $template_json, true );
		if ( ! is_array( $data ) ) {
			return [
				[
					'severity' => 'error',
					'code'     => 'invalid_bricks_template_upload_json',
					'message'  => 'Bricks template upload JSON is not valid JSON.',
					'path'     => sanitize_text_field( $path ),
				],
			];
		}

		$findings = [];
		if ( 'kiwe.bricks-conversion.v1' === (string) ( $data['schema'] ?? '' ) || isset( $data['elements'] ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_is_kiwe_wrapper',
				'message'  => 'This is a Kiwe conversion/audit envelope, not a native Bricks My Templates upload file. Bricks reads top-level title plus content/header/footer, so wrappers import as `(no title)` and insert with no data.',
				'path'     => sanitize_text_field( $path ),
			];
		}

		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title || preg_match( '/^\(?\s*no\s+title\s*\)?$/i', $title ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_missing_title',
				'message'  => 'Native Bricks template upload JSON must include a real top-level title. A homepage body should normally use `Home`.',
				'path'     => sanitize_text_field( $path ),
			];
		}

		$populated_area = '';
		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( isset( $data[ $area ] ) && is_array( $data[ $area ] ) && [] !== $data[ $area ] ) {
				$populated_area = $area;
				break;
			}
		}
		if ( '' === $populated_area ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_missing_data',
				'message'  => 'Native Bricks template upload JSON must include non-empty content, header, or footer. Otherwise Bricks reports â€œThis template has no dataâ€.',
				'path'     => sanitize_text_field( $path ),
			];
		}

		$template_type = trim( (string) ( $data['templateType'] ?? '' ) );
		if ( '' === $template_type ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_missing_template_type',
				'message'  => 'Native Bricks template upload JSON must include templateType so Bricks stores the import in the intended template lane.',
				'path'     => sanitize_text_field( $path ),
			];
		} elseif ( 'header' === $template_type && 'header' !== $populated_area ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_header_area_mismatch',
				'message'  => 'A header template must carry its elements in the top-level header array.',
				'path'     => sanitize_text_field( $path ),
			];
		} elseif ( 'footer' === $template_type && 'footer' !== $populated_area ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_footer_area_mismatch',
				'message'  => 'A footer template must carry its elements in the top-level footer array.',
				'path'     => sanitize_text_field( $path ),
			];
		} elseif ( ! in_array( $template_type, [ 'header', 'footer' ], true ) && '' !== $populated_area && 'content' !== $populated_area ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_content_area_mismatch',
				'message'  => 'A non-header/footer Bricks template should carry its elements in the top-level content array.',
				'path'     => sanitize_text_field( $path ),
			];
		}

		if ( ! empty( $data['globalClasses'] ) && empty( $data['global_classes'] ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_missing_global_classes_dependency',
				'message'  => 'Bricks My Templates import uses global_classes for template class dependencies. Do not rely only on copied-elements style globalClasses.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		if ( ! empty( $data['version'] ) && ! preg_match( self::BRICKS_SUPPORTED_TEMPLATE_VERSION_PATTERN, (string) $data['version'] ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_unsupported_target_version',
				'message'  => sprintf( 'Bricks template upload declares version "%s". Kiwe production template uploads currently target the public Bricks 2.3.x importer/runtime; do not emit unreleased/beta 2.4 template metadata unless the contract is explicitly updated after a public Bricks release.', (string) $data['version'] ),
				'path'     => sanitize_text_field( $path ),
			];
		}

		$template_text = (string) wp_json_encode( $data );
		$generator = isset( $data['generator'] ) && is_array( $data['generator'] ) ? $data['generator'] : [];
		$framework_mode = ! empty( $generator['seamFramework'] ) || 'framework-profile-dependent' === (string) ( $generator['renderMode'] ?? '' );
		if ( preg_match( '/data-dsa-surface|data-dsa-screen|data-dsa-dock|data-dsa-sheet/i', $template_text ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_contains_appshell_markup',
				'message'  => 'Bricks template uploads must remain page-only. DSA/AppShell dock, sheet, screen, and theme markup belongs to Kiwe runtime or previews.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		if ( preg_match( '/<script\b|javascript:|on[a-z]+\s*=/i', $template_text ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_executable_code',
				'message'  => 'Bricks template upload JSON must not smuggle executable script or inline event handlers. Use safe Bricks interactions/Kiwe attributes or manual review.',
				'path'     => sanitize_text_field( $path ),
			];
		}

		$custom_css = $this->collect_bricks_custom_css_text(
			[
				'pageSettings' => $data['pageSettings'] ?? [],
				'settings'     => $data['settings'] ?? [],
			]
		);
		if ( strlen( $custom_css ) >= 2500 || preg_match( '/@media\b|#home-campaigns\b|\.nc-(?:bento|campaign|section-head)|grid-template|flex-direction/i', $custom_css ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_page_css_dependency',
				'message'  => 'Bricks template upload depends on page/template custom CSS for ordinary layout/design. Template uploads must carry grid/flex, spacing, typography, color, radius, and shadows through native element settings, global_classes, or globalVariables first.',
				'path'     => sanitize_text_field( $path ),
			];
		}

		$elements = [];
		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( isset( $data[ $area ] ) && is_array( $data[ $area ] ) ) {
				$elements = array_merge( $elements, $data[ $area ] );
			}
		}
		$runtime_code_elements = $this->review_bricks_runtime_code_elements( $elements, $path, '$.content/header/footer' );
		foreach ( array_slice( $runtime_code_elements, 0, 20 ) as $finding ) {
			$findings[] = $finding;
		}
		if ( count( $runtime_code_elements ) > 20 ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_runtime_code_element_overflow',
				'message'  => sprintf( 'Bricks template upload contains %d additional runtime Code elements. Treat external-converter output as scaffold/review-only until normalized.', count( $runtime_code_elements ) - 20 ),
				'path'     => sanitize_text_field( $path . '#$.content/header/footer' ),
			];
		}
		$native_controls = $this->count_bricks_native_style_controls( array_merge( $elements, (array) ( $data['global_classes'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ) );
		if ( $framework_mode ) {
			$findings = array_merge(
			$findings,
			$this->review_bricks_tokenized_native_lengths(
				array_merge( $elements, (array) ( $data['global_classes'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.content/header/footer/global_classes',
				$this->collect_bricks_declared_css_variables( $data )
			)
		);
			$findings = array_merge(
			$findings,
			$this->review_bricks_css_variable_fallbacks(
				array_merge( $elements, (array) ( $data['global_classes'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.content/header/footer/global_classes'
			)
		);
			$findings = array_merge(
			$findings,
			$this->review_bricks_unknown_framework_variables(
				array_merge( $elements, (array) ( $data['global_classes'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.content/header/footer/global_classes'
			)
		);
			$findings = array_merge(
			$findings,
			$this->review_bricks_project_variable_framework_proof(
				array_merge( $elements, (array) ( $data['global_classes'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.content/header/footer/global_classes',
				$data,
				$path_map
			)
		);
		}
		$findings        = array_merge(
			$findings,
			$this->review_bricks_template_variable_names( $data, $path )
		);
		$findings        = array_merge(
			$findings,
			$this->review_bricks_compiler_safe_controls(
				array_merge( $elements, (array) ( $data['global_classes'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.content/header/footer/global_classes'
			)
		);
		$findings        = array_merge(
			$findings,
			$this->review_bricks_implicit_layout_controls(
				array_merge( $elements, (array) ( $data['global_classes'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.content/header/footer/global_classes'
			)
		);
		if ( ! $framework_mode && count( $elements ) >= 180 && $native_controls < 60 ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_too_few_native_controls',
				'message'  => sprintf( 'Large Bricks template upload has %1$d elements but only %2$d native style/layout controls. Full-page templates must remain editable in Bricks, not render mainly through CSS dumps.', count( $elements ), $native_controls ),
				'path'     => sanitize_text_field( $path ),
			];
		}
		$editability = $this->bricks_template_editability_stats( $elements );
		if ( ! $framework_mode && count( $elements ) >= 180 && $editability['controls_per_element'] < self::BRICKS_MIN_ELEMENT_CONTROLS_PER_ELEMENT ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_element_native_controls_too_low',
				'message'  => sprintf( 'Large Bricks template upload has %1$d element-level native style/layout controls across %2$d elements (%3$.2f per element). This is too class-dependent for a visual-editor handoff: grid/flex, spacing, sizing, typography, color, borders, radius, shadows, and responsive overrides must remain editable on elements where the source design depends on them, not only in importable global_classes.', $editability['element_controls'], count( $elements ), $editability['controls_per_element'] ),
				'path'     => sanitize_text_field( $path ),
			];
		}
		if ( ! $framework_mode && count( $elements ) >= 180 && $editability['class_only_ratio'] > self::BRICKS_MAX_CLASS_ONLY_ELEMENT_RATIO ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_class_hydration_dependency',
				'message'  => sprintf( 'Large Bricks template upload has %1$d of %2$d elements (%3$d%%) carrying global-class dependencies without element-level native style/layout controls. Bricks My Templates can skip or remap global class definitions when class names already exist, so SEAM Compiler must keep the rendered design resilient with sufficient element-native controls instead of relying mainly on class hydration.', $editability['class_only_elements'], count( $elements ), (int) round( $editability['class_only_ratio'] * 100 ) ),
				'path'     => sanitize_text_field( $path ),
			];
		}

		return $findings;
	}

	private function review_bricks_template_variable_names( array $data, string $path ): array {
		$findings = [];
		foreach ( [ 'global_variables', 'globalVariables' ] as $lane ) {
			$variables = isset( $data[ $lane ] ) && is_array( $data[ $lane ] ) ? $data[ $lane ] : [];
			foreach ( $variables as $index => $variable ) {
				if ( ! is_array( $variable ) ) {
					continue;
				}
				$name = trim( (string) ( $variable['name'] ?? '' ) );
				if ( str_starts_with( $name, '--' ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_variable_name_has_css_prefix',
						'message'  => sprintf( 'Bricks global variable "%1$s" includes leading "--". Bricks emits that prefix during CSS compilation, so this compiles into a disconnected "----%2$s" variable while controls consume var(%1$s, ...). Store native Bricks variable names as "%2$s".', $name, ltrim( $name, '-' ) ),
						'path'     => sanitize_text_field( $path . '#$.' . $lane . '[' . (int) $index . '].name' ),
					];
				}
			}
		}
		return $findings;
	}

	private function review_bricks_runtime_code_elements( array $items, string $path, string $base_path ): array {
		$findings = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || 'code' !== strtolower( (string) ( $item['name'] ?? '' ) ) ) {
				continue;
			}
			$settings    = isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : [];
			$review_text = wp_json_encode(
				[
					'classes'    => $settings['_cssClasses'] ?? '',
					'attributes' => $settings['_attributes'] ?? [],
					'kiwe'       => $item['kiwe'] ?? [],
				]
			);
			if ( is_string( $review_text ) && preg_match( self::BRICKS_REVIEW_ONLY_CODE_ELEMENT_ALLOWANCE_PATTERN, $review_text ) ) {
				continue;
			}
			$runtime_keys = [];
			foreach ( $settings as $key => $value ) {
				$key = (string) $key;
				if ( ! preg_match( '/^(?:code|css|cssCode|javascriptCode|js|html|php|executeCode)$/i', $key ) ) {
					continue;
				}
				if ( 'executeCode' === $key && true === $value ) {
					$runtime_keys[] = $key;
					continue;
				}
				if ( is_array( $value ) ) {
					if ( [] !== $value ) {
						$runtime_keys[] = $key;
					}
					continue;
				}
				if ( is_object( $value ) ) {
					$runtime_keys[] = $key;
					continue;
				}
				if ( '' !== trim( (string) $value ) ) {
					$runtime_keys[] = $key;
				}
			}
			if ( [] === $runtime_keys ) {
				continue;
			}
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_runtime_code_element',
				'message'  => sprintf( 'Bricks Code element "%1$s" contains runtime/custom-code settings (%2$s). External converters may park CSS/JS in Code elements for manual review, but production SEAM Compiler output must decompose representable layout/design into native Bricks elements, controls, variables, attributes, interactions, and documented unsupported exceptions instead of shipping Code-element authority.', (string) ( $item['id'] ?? $item['label'] ?? $item['name'] ?? 'item-' . (int) $index ), implode( ', ', array_unique( $runtime_keys ) ) ),
				'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings' ),
			];
		}
		return $findings;
	}

	private function review_bricks_compiler_safe_controls( array $items, string $path, string $base_path ): array {
		$findings = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label               = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$is_semantic_heading = 'heading' === strtolower( (string) ( $item['name'] ?? '' ) ) && preg_match( self::BRICKS_SEMANTIC_HEADING_TAG_PATTERN, (string) ( $item['settings']['tag'] ?? '' ) );
			foreach ( $item['settings'] as $key => $value ) {
				$key = (string) $key;
				if ( preg_match( self::BRICKS_COMPILE_UNSAFE_CONTROL_PATTERN, $key ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_compiler_unsafe_control',
						'message'  => sprintf( 'Bricks native control "%1$s" on "%2$s" is not compiler-safe for My Templates output. Use _widthMin/_widthMax/_heightMin/_heightMax instead of _minWidth/_maxWidth/_minHeight/_maxHeight; otherwise Bricks can preserve the JSON while silently omitting the frontend CSS rule.', $key, $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings.' . $key ),
					];
				}
				if ( ( '_typography' === $key || preg_match( '/^_typography:/', $key ) ) && is_array( $value ) ) {
					$font_size = $value['font-size'] ?? $value['fontSize'] ?? $value['font_size'] ?? null;
					if ( $is_semantic_heading && is_string( $font_size ) && preg_match( self::BRICKS_SEMANTIC_HEADING_TYPE_TOKEN_PATTERN, $font_size ) ) {
						$findings[] = [
							'severity' => 'error',
							'code'     => 'bricks_template_upload_semantic_heading_font_size_lock',
							'message'  => sprintf( 'Bricks semantic heading "%1$s" is tagged "%2$s" but locks its own font-size to "%3$s". Semantic heading scale belongs in Kiwe > Framework / Bricks Theme Style; remove local heading-token font-size so changing h3 to h2/h4 in Bricks uses the selected heading level.', $label, (string) ( $item['settings']['tag'] ?? '' ), $font_size ),
							'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings.' . $key . '.font-size' ),
						];
					}
					$font_family = $value['font-family'] ?? $value['fontFamily'] ?? $value['font_family'] ?? null;
					if ( is_string( $font_family ) && preg_match( self::BRICKS_FONT_FAMILY_TOKEN_PATTERN, $font_family ) ) {
						$findings[] = [
							'severity' => 'error',
							'code'     => 'bricks_template_upload_typography_font_family_token',
							'message'  => sprintf( 'Bricks typography control "%1$s" on "%2$s" stores font-family as "%3$s". Bricks quotes typography font-family output, so CSS-variable font stacks become invalid literal font families. Use a concrete Bricks font-family value in _typography and keep tokenized font families in the Framework/theme layer.', $key, $label, $font_family ),
							'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings.' . $key . '.font-family' ),
						];
					}
				}
				if ( ( '_background' === $key || preg_match( '/^_background:/', $key ) ) && is_array( $value ) && isset( $value['color'] ) && is_string( $value['color'] ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_color_string_shape',
						'message'  => sprintf( 'Bricks color control "%1$s" on "%2$s" stores color as a plain string. Use Bricks color objects such as { "raw": "var(--kiwe-color-surface)" }; otherwise Bricks can keep the JSON while omitting frontend CSS.', $key, $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings.' . $key . '.color' ),
					];
				}
				if ( ( '_background' === $key || preg_match( '/^_background:/', $key ) ) && is_array( $value ) && isset( $value['color'] ) && is_array( $value['color'] ) && isset( $value['color']['raw'] ) && is_string( $value['color']['raw'] ) && preg_match( '/gradient\(/i', $value['color']['raw'] ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_gradient_in_background_color',
						'message'  => sprintf( 'Bricks background color control "%1$s" on "%2$s" stores a gradient in color.raw. Use the native _gradient control with tokenized color stops and keep _background.color as a solid fallback.', $key, $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings.' . $key . '.color.raw' ),
					];
				}
				if ( ( '_border' === $key || preg_match( '/^_border:/', $key ) ) && is_array( $value ) && isset( $value['color'] ) && is_string( $value['color'] ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_color_string_shape',
						'message'  => sprintf( 'Bricks border control "%1$s" on "%2$s" stores color as a plain string. Use a Bricks color object such as { "raw": "var(--kiwe-color-border)" }.', $key, $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings.' . $key . '.color' ),
					];
				}
				if ( ( '_border' === $key || preg_match( '/^_border:/', $key ) ) && is_array( $value ) && isset( $value['radius'] ) && is_array( $value['radius'] ) ) {
					$invalid_radius_keys = [];
					foreach ( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] as $radius_key ) {
						if ( array_key_exists( $radius_key, $value['radius'] ) ) {
							$invalid_radius_keys[] = $radius_key;
						}
					}
					if ( $invalid_radius_keys ) {
						$findings[] = [
							'severity' => 'error',
							'code'     => 'bricks_template_upload_border_radius_corner_shape',
							'message'  => sprintf( 'Bricks border-radius control "%1$s" on "%2$s" uses CSS corner keys "%3$s". Bricks compiles radii from _border.radius.top/right/bottom/left; corner keys can import but render with no radius.', $key, $label, implode( ', ', $invalid_radius_keys ) ),
							'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings.' . $key . '.radius' ),
						];
					}
				}
				if ( ( '_typography' === $key || preg_match( '/^_typography:/', $key ) ) && is_array( $value ) && isset( $value['color'] ) && is_string( $value['color'] ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_color_string_shape',
						'message'  => sprintf( 'Bricks typography control "%1$s" on "%2$s" stores color as a plain string. Use a Bricks color object such as { "raw": "var(--kiwe-color-text)" }.', $key, $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings.' . $key . '.color' ),
					];
				}
			}
		}
		return $findings;
	}

	private function review_bricks_implicit_layout_controls( array $items, string $path, string $base_path ): array {
		$findings = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$settings = $item['settings'];
			$label    = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$name     = strtolower( (string) ( $item['name'] ?? '' ) );
			$display  = strtolower( (string) ( $settings['_display'] ?? '' ) );
			$classes  = (string) ( $settings['_cssClasses'] ?? '' );
			$is_layout = in_array( $name, [ 'section', 'container', 'block', 'div' ], true );
			$is_rail   = preg_match( '/\bseam-horizontal-rail\b/', $classes ) || $this->bricks_setting_has_attribute( $settings, 'data-flow', '/^horizontal-rail$/i' );

			if ( $is_layout && 'flex' === $display && ! array_key_exists( '_direction', $settings ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_template_upload_missing_flex_direction',
					'message'  => sprintf( 'Bricks layout element "%s" sets _display:flex but omits _direction. Bricks source-backed layout controls must explicitly own flex direction; relying on browser defaults causes rail/card drift and makes the visual editor ambiguous.', $label ),
					'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings._direction' ),
				];
			}

			if ( $is_layout && 'grid' === $display ) {
				$has_columns = false;
				foreach ( array_keys( $settings ) as $key ) {
					if ( preg_match( '/^_grid(?:TemplateColumns|AutoColumns)(?::|$)/', (string) $key ) ) {
						$has_columns = true;
						break;
					}
				}
				if ( ! $has_columns ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_missing_grid_columns',
						'message'  => sprintf( 'Bricks layout element "%s" sets _display:grid but omits _gridTemplateColumns/_gridAutoColumns. Grid layout must be represented by Bricks-native grid controls, not implicit CSS/default behavior.', $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings._gridTemplateColumns' ),
					];
				}
			}

			if ( $is_rail ) {
				if ( 'flex' !== $display ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_rail_missing_flex_display',
						'message'  => sprintf( 'Seam horizontal rail "%s" must set Bricks _display:flex on the actual item track. Rail semantics alone do not create Bricks-native layout ownership.', $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings._display' ),
					];
				}
				if ( 'row' !== strtolower( (string) ( $settings['_direction'] ?? '' ) ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_rail_missing_row_direction',
						'message'  => sprintf( 'Seam horizontal rail "%s" must set Bricks _direction:row. This is the source-backed control that preserves category/product rail orientation in Bricks 2.3.x/2.4.', $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings._direction' ),
					];
				}
				if ( ! preg_match( '/(?:auto|scroll)/i', (string) ( $settings['_overflow'] ?? '' ) ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_rail_missing_overflow',
						'message'  => sprintf( 'Seam horizontal rail "%s" must set Bricks _overflow:auto or scroll so the actual rail track remains scrollable after import.', $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings._overflow' ),
					];
				}
				if ( ! array_key_exists( '_columnGap', $settings ) && ! array_key_exists( '_gap', $settings ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'bricks_template_upload_rail_missing_gap',
						'message'  => sprintf( 'Seam horizontal rail "%s" must expose a tokenized Bricks _columnGap or _gap control; spacing cannot be hidden in defaults or external CSS.', $label ),
						'path'     => sanitize_text_field( $path . '#' . $base_path . '[' . (int) $index . '].settings._columnGap' ),
					];
				}
			}
		}
		return $findings;
	}

	private function bricks_setting_has_attribute( array $settings, string $name, ?string $pattern = null ): bool {
		if ( ! isset( $settings['_attributes'] ) || ! is_array( $settings['_attributes'] ) ) {
			return false;
		}
		$wanted = strtolower( $name );
		foreach ( $settings['_attributes'] as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			if ( strtolower( (string) ( $attribute['name'] ?? '' ) ) !== $wanted ) {
				continue;
			}
			if ( null === $pattern || preg_match( $pattern, (string) ( $attribute['value'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function collect_bricks_custom_css_text( $value ): string {
		$out = '';
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( ( 'customCss' === (string) $key || preg_match( '/^_cssCustom(?::|$)/', (string) $key ) ) && is_string( $item ) ) {
					$out .= "\n" . $item;
				}
				$out .= $this->collect_bricks_custom_css_text( $item );
			}
		}

		return $out;
	}

	private function count_bricks_native_style_controls( $value ): int {
		$count = 0;
		if ( is_array( $value ) ) {
			$settings = isset( $value['settings'] ) && is_array( $value['settings'] ) ? $value['settings'] : [];
			foreach ( array_keys( $settings ) as $key ) {
				if ( preg_match( '/^_(?:typography|background|gradient|border|boxShadow|transform|cssFilters|cssTransition|display|grid|gridItem|gridTemplate|gridAuto|direction|alignSelf|alignItems|justifyContent|flexWrap|flexGrow|flexShrink|flexBasis|columnGap|rowGap|gap|width|widthMin|widthMax|height|heightMin|heightMax|margin|padding|position|top|right|bottom|left|zIndex|overflow|color|textAlign|font|lineHeight|letterSpacing)(?::|$)/', (string) $key ) && ! preg_match( '/^_cssCustom(?::|$)/', (string) $key ) ) {
					$count++;
				}
			}
			foreach ( $value as $item ) {
				$count += $this->count_bricks_native_style_controls( $item );
			}
		}

		return $count;
	}

	private function count_bricks_native_style_controls_on_item( array $item ): int {
		$count    = 0;
		$settings = isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : [];
		foreach ( array_keys( $settings ) as $key ) {
			if ( preg_match( '/^_(?:typography|background|gradient|border|boxShadow|transform|cssFilters|cssTransition|display|grid|gridItem|gridTemplate|gridAuto|direction|alignSelf|alignItems|justifyContent|flexWrap|flexGrow|flexShrink|flexBasis|columnGap|rowGap|gap|width|widthMin|widthMax|height|heightMin|heightMax|margin|padding|position|top|right|bottom|left|zIndex|overflow|color|textAlign|font|lineHeight|letterSpacing)(?::|$)/', (string) $key ) && ! preg_match( '/^_cssCustom(?::|$)/', (string) $key ) ) {
				$count++;
			}
		}

		return $count;
	}

	private function bricks_template_editability_stats( array $elements ): array {
		$element_controls    = 0;
		$class_only_elements = 0;
		foreach ( $elements as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item_controls = $this->count_bricks_native_style_controls_on_item( $item );
			$element_controls += $item_controls;
			$classes = $item['settings']['_cssGlobalClasses'] ?? [];
			if ( 0 === $item_controls && is_array( $classes ) && count( $classes ) > 0 ) {
				$class_only_elements++;
			}
		}

		$total = max( 1, count( $elements ) );
		return [
			'element_controls'     => $element_controls,
			'controls_per_element' => $element_controls / $total,
			'class_only_elements'  => $class_only_elements,
			'class_only_ratio'     => $class_only_elements / $total,
		];
	}

	private function review_bricks_tokenized_native_lengths( array $items, string $path, string $base_path, array $declared_variables = [] ): array {
		$found = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label  = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$values = [];
			$this->collect_bricks_untokenized_native_lengths( $item['settings'], $values, $base_path . '[' . (int) $index . '].settings', false, $declared_variables );
			foreach ( $values as $value ) {
				$value['label'] = $label;
				$found[]        = $value;
			}
		}

		$findings = [];
		foreach ( array_slice( $found, 0, self::BRICKS_TOKEN_FINDING_LIMIT ) as $item ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_untokenized_native_length',
				'message'  => sprintf(
					'Bricks native style "%1$s" on "%2$s" uses literal length "%3$s". A Framework profile supplies token values but does not rewrite hardcoded Bricks JSON; use an official var(--kiwe-*)/var(--seam-*) token when the meaning and property domain match, a declared project variable for stable art direction, or a real tokenized clamp() only for proven responsive interpolation. No-op clamps such as clamp(22px, 22px, 22px) do not count as tokenization.',
					(string) ( $item['path'] ?? '' ),
					(string) ( $item['label'] ?? '' ),
					(string) ( $item['value'] ?? '' )
				),
				'path'     => sanitize_text_field( $path ),
			];
		}
		if ( count( $found ) > self::BRICKS_TOKEN_FINDING_LIMIT ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_untokenized_native_length_overflow',
				'message'  => sprintf( 'Bricks native styles contain %d additional untokenized literal length values beyond the first %d. Fix with official tokens, declared project variables, or real fluid clamps from proven responsive states, then rerun /audit /bricksconversion.', count( $found ) - self::BRICKS_TOKEN_FINDING_LIMIT, self::BRICKS_TOKEN_FINDING_LIMIT ),
				'path'     => sanitize_text_field( $path ),
			];
		}

		return $findings;
	}

	private function review_bricks_css_variable_fallbacks( array $items, string $path, string $base_path ): array {
		$found = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label  = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$values = [];
			$this->collect_bricks_css_variables_with_fallback( $item['settings'], $values, $base_path . '[' . (int) $index . '].settings' );
			foreach ( $values as $value ) {
				$value['label'] = $label;
				$found[]        = $value;
			}
		}

		$findings = [];
		foreach ( array_slice( $found, 0, self::BRICKS_CSS_VAR_FINDING_LIMIT ) as $item ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_css_var_has_fallback',
				'message'  => sprintf(
					'Bricks native style "%1$s" on "%2$s" references "%3$s" with an inline fallback in "%4$s". SeamFlow template render-owner settings must consume bare Framework/project variables only, e.g. var(%3$s). Put the actual value in the paired Kiwe Framework profile / Bricks variable push so missing profile setup fails visibly instead of silently rendering from hidden fallback values.',
					(string) ( $item['path'] ?? '' ),
					(string) ( $item['label'] ?? '' ),
					(string) ( $item['variable'] ?? '' ),
					(string) ( $item['value'] ?? '' )
				),
				'path'     => sanitize_text_field( $path ),
			];
		}
		if ( count( $found ) > self::BRICKS_CSS_VAR_FINDING_LIMIT ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_template_upload_css_var_has_fallback_overflow',
				'message'  => sprintf( 'Bricks native styles contain %d additional CSS variable references with inline fallbacks beyond the first %d. Remove fallbacks from Bricks render-owner settings and define those values in the paired Framework profile, then rerun /audit /bricksconversion.', count( $found ) - self::BRICKS_CSS_VAR_FINDING_LIMIT, self::BRICKS_CSS_VAR_FINDING_LIMIT ),
				'path'     => sanitize_text_field( $path ),
			];
		}

		return $findings;
	}

	private function review_bricks_unknown_framework_variables( array $items, string $path, string $base_path ): array {
		$found = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label  = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$values = [];
			$this->collect_bricks_native_owned_css_variables( $item['settings'], $values, $base_path . '[' . (int) $index . '].settings' );
			foreach ( $values as $value ) {
				$variable = $this->normalize_css_variable_name( (string) ( $value['variable'] ?? '' ) );
				if ( '' === $variable || ! preg_match( '/^--(?:kiwe|seam)-/i', $variable ) || $this->bricks_is_official_framework_variable( $variable ) ) {
					continue;
				}
				$value['label']    = $label;
				$value['variable'] = $variable;
				$found[]           = $value;
			}
		}

		$names = array_values( array_unique( array_map( static fn( array $item ): string => (string) ( $item['variable'] ?? '' ), $found ) ) );
		sort( $names );
		if ( [] === $names ) {
			return [];
		}

		return [
			[
				'severity' => 'error',
				'code'     => 'bricks_template_unknown_framework_variable',
				'message'  => sprintf(
					'Bricks template uses %1$d reserved-looking Framework variable(s) that are not in the Kiwe universal token registry: %2$s%3$s. Do not invent --kiwe-* or --seam-* variables. Map to an existing official token, declare a collision-safe project variable such as --nc-*, or formally add the token to Kiwe universal registry before SEAM Compiler validation can pass.',
					count( $names ),
					implode( ', ', array_slice( $names, 0, 20 ) ),
					count( $names ) > 20 ? ', ...' : ''
				),
				'path'     => sanitize_text_field( $path ),
			],
		];
	}

	private function review_bricks_project_variable_framework_proof( array $items, string $path, string $base_path, array $data, array $path_map ): array {
		$usage = [];
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
				continue;
			}
			$label  = (string) ( $item['id'] ?? $item['name'] ?? $item['label'] ?? 'item-' . (int) $index );
			$values = [];
			$this->collect_bricks_native_owned_css_variables( $item['settings'], $values, $base_path . '[' . (int) $index . '].settings' );
			foreach ( $values as $value ) {
				$variable = $this->normalize_css_variable_name( (string) ( $value['variable'] ?? '' ) );
				if ( '' === $variable || $this->bricks_is_official_framework_variable( $variable ) || preg_match( '/^--(?:kiwe|seam)-/i', $variable ) ) {
					continue;
				}
				$value['label']    = $label;
				$value['variable'] = $variable;
				$usage[]           = $value;
			}
		}

		$required = array_values( array_unique( array_map( static fn( array $item ): string => (string) ( $item['variable'] ?? '' ), $usage ) ) );
		sort( $required );
		if ( [] === $required ) {
			return [];
		}

		$proof = $this->collect_bricks_framework_project_variable_proof( $data, $path_map );
		$missing = array_values(
			array_filter(
				$required,
				static fn( string $name ): bool => empty( $proof[ strtolower( $name ) ] )
			)
		);
		if ( [] === $missing ) {
			return [];
		}

		$template_declared = $this->collect_bricks_template_declared_variable_names( $data );
		$template_only = array_values(
			array_filter(
				$missing,
				static fn( string $name ): bool => ! empty( $template_declared[ strtolower( $name ) ] )
			)
		);
		$first = null;
		foreach ( $usage as $item ) {
			if ( in_array( (string) ( $item['variable'] ?? '' ), $missing, true ) ) {
				$first = $item;
				break;
			}
		}

		return [
			[
				'severity' => 'error',
				'code'     => 'bricks_template_project_variable_missing_framework_profile_proof',
				'message'  => sprintf(
					'Bricks template consumes %1$d project CSS variable(s) in native controls, but Framework-profile proof is missing for %2$d: %3$s%4$s. %5$sSEAM Compiler must pair project variables with framework/kiwe-framework-profile.json or embedded kiwe.frameworkProfile.projectVariables proof; template-local globalVariables alone are not reliable Bricks foundation install proof.',
					count( $required ),
					count( $missing ),
					implode( ', ', array_slice( $missing, 0, 20 ) ),
					count( $missing ) > 20 ? ', ...' : '',
					[] !== $template_only ? sprintf( 'These variable(s) appear only in the template globalVariables lane: %s%s. ', implode( ', ', array_slice( $template_only, 0, 12 ) ), count( $template_only ) > 12 ? ', ...' : '' ) : ''
				),
				'path'     => sanitize_text_field( (string) ( $first['path'] ?? $path ) ),
			],
		];
	}

	private function collect_bricks_framework_project_variable_proof( array $data, array $path_map ): array {
		$proof = [];
		$framework = [];
		if ( isset( $data['kiwe']['frameworkProfile'] ) && is_array( $data['kiwe']['frameworkProfile'] ) ) {
			$framework = $data['kiwe']['frameworkProfile'];
		} elseif ( isset( $data['frameworkProfile'] ) && is_array( $data['frameworkProfile'] ) ) {
			$framework = $data['frameworkProfile'];
		}
		foreach ( [ 'projectVariables', 'variables', 'requiredVariables' ] as $key ) {
			$items = isset( $framework[ $key ] ) && is_array( $framework[ $key ] ) ? $framework[ $key ] : [];
			foreach ( $items as $item ) {
				$name = $this->normalize_css_variable_name( is_array( $item ) ? (string) ( $item['name'] ?? $item['variable'] ?? $item['key'] ?? $item['id'] ?? '' ) : (string) $item );
				if ( '' !== $name ) {
					$proof[ strtolower( $name ) ] = true;
				}
			}
		}

		foreach ( $path_map as $candidate_path => $content ) {
			$normalized_path = str_replace( '\\', '/', (string) $candidate_path );
			if ( ! preg_match( '#(^|/)framework/kiwe-framework-profile\.json$|(^|/)kiwe-framework-profile\.json$#', $normalized_path ) ) {
				continue;
			}
			$profile = json_decode( (string) $content, true );
			if ( ! is_array( $profile ) ) {
				continue;
			}
			$project = $profile['settings']['tokens']['project'] ?? $profile['tokens']['project'] ?? $profile['project'] ?? [];
			$variables = is_array( $project ) && isset( $project['variables'] ) && is_array( $project['variables'] ) ? $project['variables'] : [];
			foreach ( $variables as $variable ) {
				if ( ! is_array( $variable ) ) {
					continue;
				}
				$name = $this->normalize_css_variable_name( (string) ( $variable['name'] ?? $variable['variable'] ?? $variable['key'] ?? $variable['id'] ?? '' ) );
				if ( '' !== $name ) {
					$proof[ strtolower( $name ) ] = true;
				}
			}
		}

		return $proof;
	}

	private function collect_bricks_template_declared_variable_names( array $data ): array {
		$names = [];
		foreach ( [ 'global_variables', 'globalVariables' ] as $lane ) {
			$variables = isset( $data[ $lane ] ) && is_array( $data[ $lane ] ) ? $data[ $lane ] : [];
			foreach ( $variables as $variable ) {
				if ( ! is_array( $variable ) ) {
					continue;
				}
				$name = $this->normalize_css_variable_name( (string) ( $variable['name'] ?? $variable['variable'] ?? $variable['key'] ?? $variable['id'] ?? '' ) );
				if ( '' !== $name ) {
					$names[ strtolower( $name ) ] = true;
				}
			}
		}
		return $names;
	}

	private function collect_bricks_declared_css_variables( $value, array &$out = [] ): array {
		if ( is_array( $value ) ) {
			foreach ( [ 'name', 'variable', 'key', 'id' ] as $key ) {
				if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
					$clean = preg_replace( '/^--/', '', trim( $value[ $key ] ) );
					if ( is_string( $clean ) && preg_match( '/^(?:kiwe|seam|[a-z][a-z0-9]*)-[a-z0-9][a-z0-9-]*$/i', $clean ) ) {
						$out[ $clean ]       = true;
						$out[ '--' . $clean ] = true;
					}
				}
			}
			foreach ( $value as $item ) {
				$this->collect_bricks_declared_css_variables( $item, $out );
			}
		}

		return $out;
	}

	private function bricks_uses_declared_project_variable( string $value, array $declared_variables ): bool {
		if ( ! preg_match_all( '/var\(\s*--([a-z][a-z0-9]*-[a-z0-9][a-z0-9-]*)/i', $value, $matches ) ) {
			return false;
		}

		foreach ( $matches[1] as $name ) {
			$name = (string) $name;
			if ( $this->bricks_is_official_framework_variable( '--' . $name ) || ! empty( $declared_variables[ $name ] ) || ! empty( $declared_variables[ '--' . $name ] ) ) {
				return true;
			}
		}

		return false;
	}

	private function bricks_uses_official_framework_variable( string $value ): bool {
		if ( ! preg_match_all( '/var\(\s*(--[a-z][a-z0-9_-]*)/i', $value, $matches ) ) {
			return false;
		}

		foreach ( $matches[1] as $name ) {
			if ( $this->bricks_is_official_framework_variable( (string) $name ) ) {
				return true;
			}
		}

		return false;
	}

	private function bricks_is_official_framework_variable( string $name ): bool {
		$normalized = strtolower( $this->normalize_css_variable_name( $name ) );
		if ( '' === $normalized ) {
			return false;
		}
		$official = $this->bricks_official_framework_variable_names();
		return ! empty( $official[ $normalized ] );
	}

	private function bricks_official_framework_variable_names(): array {
		static $official = null;

		if ( is_array( $official ) ) {
			return $official;
		}

		$official = [];
		if ( class_exists( '\DSA\Design\Seam_Token_Service' ) && method_exists( '\DSA\Design\Seam_Token_Service', 'universal_tokens' ) ) {
			foreach ( \DSA\Design\Seam_Token_Service::universal_tokens() as $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}
				$css_var = strtolower( $this->normalize_css_variable_name( (string) ( $token['cssVar'] ?? '' ) ) );
				if ( '' !== $css_var ) {
					$official[ $css_var ] = true;
				}
				$alias = strtolower( $this->normalize_css_variable_name( (string) ( $token['seamAlias'] ?? '' ) ) );
				if ( '' !== $alias && 0 === strpos( $alias, '--seam-' ) ) {
					$official[ $alias ] = true;
				}
			}
		}

		return $official;
	}

	private function normalize_css_variable_name( string $name ): string {
		$clean = preg_replace( '/^--/', '', trim( $name ) );
		if ( ! is_string( $clean ) || ! preg_match( '/^[a-z][a-z0-9_-]*$/i', $clean ) ) {
			return '';
		}
		return '--' . $clean;
	}

	private function collect_bricks_untokenized_native_lengths( $value, array &$out, string $path, bool $parent_owned = false, array $declared_variables = [] ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$owned = $parent_owned || preg_match( self::BRICKS_TOKEN_OWNED_CONTROL_PATTERN, (string) $key ) || preg_match( self::BRICKS_TOKEN_OWNED_NESTED_PATTERN, (string) $key );
				$this->collect_bricks_untokenized_native_lengths( $item, $out, $path . '.' . (string) $key, (bool) $owned, $declared_variables );
			}
			return;
		}

		if ( ! $parent_owned || ! is_string( $value ) ) {
			return;
		}

		if ( preg_match( self::BRICKS_LITERAL_LENGTH_PATTERN, $value ) && ( ( ! $this->bricks_uses_official_framework_variable( $value ) && ! $this->bricks_uses_declared_project_variable( $value, $declared_variables ) && false === stripos( $value, 'clamp(' ) ) || preg_match( self::BRICKS_SELF_CLAMP_LENGTH_PATTERN, $value ) ) ) {
			$out[] = [
				'path'  => $path,
				'value' => $value,
			];
		}
	}

	private function collect_bricks_native_owned_css_variables( $value, array &$out, string $path, bool $parent_owned = false ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$key_string = (string) $key;
				$owned      = $parent_owned || preg_match( self::BRICKS_STYLE_CONTROL_PATTERN, $key_string ) || preg_match( self::BRICKS_STYLE_NESTED_PATTERN, $key_string );
				$this->collect_bricks_native_owned_css_variables( $item, $out, $path . '.' . $key_string, (bool) $owned );
			}
			return;
		}

		if ( ! $parent_owned || ! is_string( $value ) || false === strpos( $value, 'var(' ) ) {
			return;
		}

		foreach ( $this->extract_css_function_calls( $value, 'var' ) as $call ) {
			$args     = $this->split_css_args( $call );
			$variable = $this->normalize_css_variable_name( (string) ( $args[0] ?? '' ) );
			if ( '' === $variable ) {
				continue;
			}
			$out[] = [
				'path'     => $path,
				'value'    => $value,
				'variable' => $variable,
			];
		}
	}

	private function collect_bricks_css_variables_with_fallback( $value, array &$out, string $path, bool $parent_owned = false ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$key_string = (string) $key;
				$owned      = $parent_owned || preg_match( self::BRICKS_STYLE_CONTROL_PATTERN, $key_string ) || preg_match( self::BRICKS_STYLE_NESTED_PATTERN, $key_string );
				$this->collect_bricks_css_variables_with_fallback( $item, $out, $path . '.' . $key_string, (bool) $owned );
			}
			return;
		}

		if ( ! $parent_owned || ! is_string( $value ) || false === strpos( $value, 'var(' ) ) {
			return;
		}

		foreach ( $this->extract_bricks_css_function_calls( $value, 'var' ) as $call ) {
			$args = $this->split_bricks_css_args( $call );
			if ( count( $args ) < 2 ) {
				continue;
			}
			$variable = trim( (string) ( $args[0] ?? '' ) );
			if ( ! preg_match( '/^--[a-z][a-z0-9_-]*$/i', $variable ) ) {
				continue;
			}
			$out[] = [
				'path'     => $path,
				'value'    => $value,
				'variable' => $variable,
			];
		}
	}

	private function extract_bricks_css_function_calls( string $value, string $function_name ): array {
		$text   = $value;
		$lower  = strtolower( $text );
		$needle = strtolower( $function_name ) . '(';
		$calls  = [];
		$index  = 0;
		$len    = strlen( $text );

		while ( false !== ( $index = strpos( $lower, $needle, $index ) ) ) {
			$depth = 0;
			$end   = -1;
			for ( $i = $index; $i < $len; ++$i ) {
				$char = $text[ $i ];
				if ( '(' === $char ) {
					++$depth;
				} elseif ( ')' === $char ) {
					--$depth;
					if ( 0 === $depth ) {
						$end = $i;
						break;
					}
				}
			}
			if ( -1 === $end ) {
				break;
			}
			$calls[] = substr( $text, $index + strlen( $needle ), $end - $index - strlen( $needle ) );
			$index   = $end + 1;
		}

		return $calls;
	}

	private function split_bricks_css_args( string $value ): array {
		$args  = [];
		$depth = 0;
		$start = 0;
		$len   = strlen( $value );
		for ( $i = 0; $i < $len; ++$i ) {
			$char = $value[ $i ];
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$args[] = trim( substr( $value, $start, $i - $start ) );
				$start  = $i + 1;
			}
		}
		$args[] = trim( substr( $value, $start ) );
		return $args;
	}

	private function review_bricks_conversion_package( string $conversion_json, array $path_map ): array {
		$findings = [];
		$path     = $this->path_like( $path_map, 'kiwe-bricks-conversion.json' );
		$data     = json_decode( $conversion_json, true );
		if ( ! is_array( $data ) ) {
			return [
				[
					'severity' => 'error',
					'code'     => 'invalid_bricks_conversion_json',
					'message'  => 'kiwe-bricks-conversion.json is not valid JSON.',
					'path'     => sanitize_text_field( $path ),
				],
			];
		}

		foreach ( [ 'schema', 'source', 'target', 'conversion', 'elements', 'fidelity', 'report' ] as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_missing_root_key',
					'message'  => sprintf( 'kiwe-bricks-conversion.json is missing required root key "%s".', $key ),
					'path'     => sanitize_text_field( $path ),
				];
			}
		}

		if ( (string) ( $data['schema'] ?? '' ) !== 'kiwe.bricks-conversion.v1' ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'invalid_bricks_conversion_schema',
				'message'  => 'kiwe-bricks-conversion.json schema must be kiwe.bricks-conversion.v1.',
				'path'     => sanitize_text_field( $path ),
			];
		}

		$findings = array_merge(
			$findings,
			$this->review_bricks_tokenized_native_lengths(
				array_merge( (array) ( $data['elements'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.elements/globalClasses',
				$this->collect_bricks_declared_css_variables( $data )
			)
		);
		$findings = array_merge(
			$findings,
			$this->review_bricks_css_variable_fallbacks(
				array_merge( (array) ( $data['elements'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.elements/globalClasses'
			)
		);
		$findings = array_merge(
			$findings,
			$this->review_bricks_unknown_framework_variables(
				array_merge( (array) ( $data['elements'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.elements/globalClasses'
			)
		);
		$findings = array_merge(
			$findings,
			$this->review_bricks_project_variable_framework_proof(
				array_merge( (array) ( $data['elements'] ?? [] ), (array) ( $data['globalClasses'] ?? [] ) ),
				$path,
				'$.elements/globalClasses',
				$data,
				$path_map
			)
		);

		$source = isset( $data['source'] ) && is_array( $data['source'] ) ? $data['source'] : [];
		if ( [] === $source ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_missing_source',
				'message'  => 'kiwe-bricks-conversion.json source must describe the page artifact being converted.',
				'path'     => sanitize_text_field( $path ),
			];
		} else {
			$source_text = (string) wp_json_encode( $source );
			$source_html = str_replace( '\\', '/', (string) ( $source['html'] ?? $source['path'] ?? '' ) );
			if ( preg_match( '#(^|[\\\\/])(combined-preview|appshell-theme|ui-system)([\\\\/]|$)|theme-package\.json|css[\\\\/]theme\.css|\b(?:dsa\s*theme|appshell|app\s*shell)\b#i', $source_text ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_forbidden_source_lane',
					'message'  => 'SEAM Compiler source must be website/bricks-paste.html only. Do not compile combined-preview, appshell-theme, DSA/AppShell preview markup, theme-package.json, or theme.css into Bricks.',
					'path'     => sanitize_text_field( $path ),
				];
			}
			if ( '' !== $source_html && ! str_ends_with( $source_html, 'website/bricks-paste.html' ) ) {
				$findings[] = [
					'severity' => 'warning',
					'code'     => 'bricks_conversion_noncanonical_source_path',
					'message'  => 'source.html should point to website/bricks-paste.html. Combined previews and AppShell theme previews are never Bricks conversion sources.',
					'path'     => sanitize_text_field( $path ),
				];
			}
		}

		$target = isset( $data['target'] ) && is_array( $data['target'] ) ? $data['target'] : [];
		if ( (string) ( $target['builder'] ?? '' ) !== 'bricks' ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_wrong_builder',
				'message'  => 'Bricks conversion target.builder must be bricks.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		if ( ! str_contains( strtolower( (string) ( $target['format'] ?? '' ) ), 'bricks' ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_missing_format',
				'message'  => 'Bricks conversion target.format must identify Bricks element JSON.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		$authority = (string) ( $target['applyAuthority'] ?? '' );
		if ( '' === $authority || ( preg_match( '/(?:auto|direct|save|publish|mutat|write)/i', $authority ) && ! preg_match( '/(?:human|review|trusted|adapter|staging)/i', $authority ) ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_unsafe_apply_authority',
				'message'  => 'Bricks conversion applyAuthority must point to human review or the trusted Kiwe staging adapter, not direct unsupervised writes.',
				'path'     => sanitize_text_field( $path ),
			];
		}

		$elements = isset( $data['elements'] ) && is_array( $data['elements'] ) ? $data['elements'] : [];
		if ( [] === $elements ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_missing_elements',
				'message'  => 'Bricks conversion must include a non-empty top-level elements array.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		$ids = [];
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_invalid_element',
					'message'  => 'Every Bricks conversion element must be an object.',
					'path'     => sanitize_text_field( $path ),
				];
				continue;
			}
			$id = (string) ( $element['id'] ?? '' );
			if ( '' === $id ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_element_missing_id',
					'message'  => sprintf( 'Bricks conversion element at index %d is missing id.', (int) $index ),
					'path'     => sanitize_text_field( $path ),
				];
			} elseif ( isset( $ids[ $id ] ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_duplicate_element_id',
					'message'  => sprintf( 'Duplicate Bricks conversion element id "%s".', $id ),
					'path'     => sanitize_text_field( $path ),
				];
			}
			if ( '' !== $id ) {
				$ids[ $id ] = true;
			}
			if ( '' === (string) ( $element['name'] ?? '' ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_element_missing_name',
					'message'  => sprintf( 'Bricks conversion element "%s" is missing name.', '' !== $id ? $id : '#' . (int) $index ),
					'path'     => sanitize_text_field( $path ),
				];
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			if ( isset( $settings['_conditions'] ) && ! is_array( $settings['_conditions'] ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_invalid_conditions',
					'message'  => sprintf( 'Element "%s" has _conditions but it is not an array.', $id ),
					'path'     => sanitize_text_field( $path ),
				];
			}
			if ( isset( $settings['_interactions'] ) && ! is_array( $settings['_interactions'] ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_invalid_interactions',
					'message'  => sprintf( 'Element "%s" has _interactions but it is not an array.', $id ),
					'path'     => sanitize_text_field( $path ),
				];
			} elseif ( isset( $settings['_interactions'] ) ) {
				foreach ( $settings['_interactions'] as $interaction ) {
					if ( is_array( $interaction ) && 'javascript' === (string) ( $interaction['action'] ?? $interaction['actionType'] ?? '' ) ) {
						$findings[] = [
							'severity' => 'error',
							'code'     => 'bricks_conversion_javascript_interaction',
							'message'  => sprintf( 'Element "%s" uses the Bricks javascript interaction action. Use safe Bricks/Kiwe behavior or manual review.', $id ),
							'path'     => sanitize_text_field( $path ),
						];
					}
				}
			}
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$parent = (string) ( $element['parent'] ?? '' );
			if ( '' !== $parent && '0' !== $parent && empty( $ids[ $parent ] ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_missing_parent',
					'message'  => sprintf( 'Element "%s" references missing parent "%s".', (string) ( $element['id'] ?? '' ), $parent ),
					'path'     => sanitize_text_field( $path ),
				];
			}
		}

		$fidelity = isset( $data['fidelity'] ) && is_array( $data['fidelity'] ) ? $data['fidelity'] : [];
		if ( empty( $fidelity['sourceSelectors'] ) || ! is_array( $fidelity['sourceSelectors'] ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_missing_fidelity_map',
				'message'  => 'Bricks conversion must include fidelity.sourceSelectors mapping important source regions to Bricks element IDs.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		foreach ( [ 'elementMapping', 'dynamicIntent', 'responsiveIntent', 'interactions', 'conditions', 'unsupported' ] as $fidelity_key ) {
			if ( isset( $fidelity[ $fidelity_key ] ) && ! is_array( $fidelity[ $fidelity_key ] ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_invalid_fidelity_lane',
					'message'  => sprintf( 'fidelity.%s must be an array when present.', $fidelity_key ),
					'path'     => sanitize_text_field( $path ),
				];
			}
		}

		$website_for_layout = $this->file_like( $path_map, 'bricks-paste.html' );
		$responsive        = isset( $fidelity['responsiveIntent'] ) && is_array( $fidelity['responsiveIntent'] ) ? $fidelity['responsiveIntent'] : [];
		$overrides         = $this->collect_bricks_responsive_layout_overrides( $elements );
		$has_complex       = (bool) preg_match( self::BRICKS_COMPLEX_LAYOUT_PATTERN, $website_for_layout . "\n" . $conversion_json );
		if ( ( $has_complex || [] !== $overrides ) && [] === $responsive ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_missing_responsive_intent',
				'message'  => 'fidelity.responsiveIntent must be a non-empty array when source/conversion uses complex bento/grid/campaign layout or Bricks breakpoint layout overrides.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		foreach ( $responsive as $index => $item ) {
			$item_text = is_array( $item ) ? (string) wp_json_encode( $item ) : (string) $item;
			if ( ! is_array( $item ) || ! preg_match( '/desktop|tablet|mobile|narrow|breakpoint|viewport|range/i', $item_text ) || ! preg_match( '/selector|source|element|bricks|mappedElementIds|id/i', $item_text ) || ! preg_match( '/grid|flex|direction|columns|rows|span|wrap|align|justify|flow/i', $item_text ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_invalid_responsive_intent',
					'message'  => sprintf( 'fidelity.responsiveIntent[%d] must identify breakpoint/range, source selector to Bricks element mapping, and preserved grid/flex behavior.', (int) $index ),
					'path'     => sanitize_text_field( $path ),
				];
			}
		}
		if ( $has_complex && ! preg_match( '/#home-campaigns|bento|campaign|grid-template|grid-column|grid-row/i', (string) wp_json_encode( $fidelity['sourceSelectors'] ?? [] ) ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_missing_complex_layout_fidelity',
				'message'  => 'fidelity.sourceSelectors must explicitly include complex bento/grid/campaign regions such as #home-campaigns/.nc-bento and their mapped Bricks element IDs.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		if ( $has_complex && [] !== $responsive && ! preg_match( '/#home-campaigns|bento|campaign|grid|columns|rows|span/i', (string) wp_json_encode( $responsive ) ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_missing_complex_responsive_fidelity',
				'message'  => 'fidelity.responsiveIntent must explicitly describe bento/grid/campaign responsive behavior so Bricks desktop/tablet/mobile layouts cannot silently drift.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		$responsive_text = (string) wp_json_encode( $responsive );
		foreach ( $overrides as $override ) {
			$key     = (string) ( $override['key'] ?? '' );
			$value   = strtolower( (string) ( $override['value'] ?? '' ) );
			$id      = (string) ( $override['id'] ?? '' );
			$classes = (string) ( $override['classes'] ?? '' );
			$css_id  = (string) ( $override['cssId'] ?? '' );
			if ( preg_match( '/\bseam-spread\b/', $classes . ' ' . $css_id ) && preg_match( '/_(?:direction|flexDirection):/i', $key ) && 'column' === $value && ! preg_match( '/' . preg_quote( '' !== $id ? $id : 'missing-id', '/' ) . '|' . preg_quote( '' !== $css_id ? $css_id : 'missing-css-id', '/' ) . '|seam-spread|section-head/i', $responsive_text ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_unproven_seam_spread_direction_override',
					'message'  => sprintf( 'Element "%s" changes seam-spread to column at %s without a responsiveIntent entry tied to source evidence.', '' !== $id ? $id : 'unknown', $key ),
					'path'     => sanitize_text_field( $path ),
				];
			}
		}

		$website = $this->file_like( $path_map, 'bricks-paste.html' );
		if ( '' !== $website ) {
			if ( preg_match( '/data-dsa-(?:surface|dock|screen|sheet|cart-panel|profile-panel)/i', $website ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_source_contains_appshell',
					'message'  => 'Bricks conversion source must be page-only and must not include AppShell shell markup.',
					'path'     => sanitize_text_field( $path ),
				];
			}
			if ( preg_match_all( '/class\s*=\s*["\']([^"\']+)["\']/i', $website, $class_matches ) ) {
				$seam_classes = [];
				foreach ( $class_matches[1] as $classes ) {
					foreach ( preg_split( '/\s+/', (string) $classes ) as $class ) {
						if ( preg_match( '/^seam-/', $class ) ) {
							$seam_classes[ $class ] = true;
						}
					}
				}
				if ( [] !== $seam_classes ) {
					$missing = [];
					foreach ( array_keys( $seam_classes ) as $class ) {
						if ( ! str_contains( $conversion_json, $class ) ) {
							$missing[] = $class;
						}
					}
					if ( count( $missing ) === count( $seam_classes ) ) {
						$findings[] = [
							'severity' => 'error',
							'code'     => 'bricks_conversion_lost_seam_classes',
							'message'  => 'No source Seam classes are preserved in the Bricks conversion package.',
							'path'     => sanitize_text_field( $path ),
						];
					} elseif ( [] !== $missing ) {
						$findings[] = [
							'severity' => 'warning',
							'code'     => 'bricks_conversion_partial_seam_loss',
							'message'  => sprintf( 'Some source Seam classes are not visible in the Bricks conversion package: %s.', implode( ', ', array_slice( $missing, 0, 12 ) ) ),
							'path'     => sanitize_text_field( $path ),
						];
					}
				}
			}
			if ( preg_match_all( '/data-dsa-open-module\s*=\s*["\']([^"\']+)["\']/i', $website, $launcher_matches ) ) {
				foreach ( $launcher_matches[1] as $module ) {
					$module = (string) $module;
					if ( ! str_contains( $conversion_json, 'data-dsa-open-module' ) || ! str_contains( $conversion_json, $module ) ) {
						$findings[] = [
							'severity' => 'error',
							'code'     => 'bricks_conversion_lost_kiwe_launcher',
							'message'  => sprintf( 'Source launcher data-dsa-open-module="%s" was not preserved in the Bricks conversion package.', $module ),
							'path'     => sanitize_text_field( $path ),
						];
					}
				}
			}
			$capability_attributes = [
				'data-kiwe-save',
				'data-kiwe-save-id',
				'data-kiwe-save-title',
				'data-kiwe-save-url',
				'data-kiwe-save-image',
				'data-kiwe-notifications',
				'data-kiwe-notification-status-target',
				'data-kiwe-notification-topic',
				'data-dsa-native-notification-request',
				'data-kiwe-theme-toggle',
				'data-kiwe-theme-status-target',
				'data-kiwe-query-template',
				'data-kiwe-binding',
			];
			$capability_pattern = '/\b(' . implode( '|', array_map( static fn( string $name ): string => preg_quote( $name, '/' ), $capability_attributes ) ) . ')(?:\s*=\s*["\']([^"\']*)["\'])?/i';
			if ( preg_match_all( $capability_pattern, $website, $capability_matches, PREG_SET_ORDER ) ) {
				$seen_capabilities = [];
				foreach ( $capability_matches as $capability_match ) {
					$name  = (string) ( $capability_match[1] ?? '' );
					$value = trim( (string) ( $capability_match[2] ?? '' ) );
					$key   = $name . '=' . $value;
					if ( isset( $seen_capabilities[ $key ] ) ) {
						continue;
					}
					$seen_capabilities[ $key ] = true;
					if ( ! str_contains( $conversion_json, $name ) ) {
						$findings[] = [
							'severity' => 'error',
							'code'     => 'bricks_conversion_lost_kiwe_capability_attribute',
							'message'  => sprintf( 'Source Kiwe capability attribute %1$s%2$s was not preserved in the Bricks conversion package.', $name, '' !== $value ? '="' . $value . '"' : '' ),
							'path'     => sanitize_text_field( $path ),
						];
					}
				}
			}
			if ( preg_match( '/data-kiwe-query-template\s*=/i', $website ) && ! preg_match( '/"query"\s*:|"dynamicIntent"\s*:\s*\[[^\]]+\]/i', $conversion_json ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'bricks_conversion_missing_query_intent',
					'message'  => 'Source has data-kiwe-query-template markers but conversion has no Bricks query settings or fidelity.dynamicIntent.',
					'path'     => sanitize_text_field( $path ),
				];
			}
		}

		if ( preg_match( '/data-dsa-surface|data-dsa-screen|data-dsa-dock/i', $conversion_json ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_contains_appshell_markup',
				'message'  => 'Bricks conversion JSON must remain page-only. AppShell/dock/sheet/screen markup belongs to Kiwe runtime and previews, not Bricks page conversion.',
				'path'     => sanitize_text_field( $path ),
			];
		}
		if ( preg_match( '/<script\b|javascript:|on[a-z]+\s*=/i', $conversion_json ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'bricks_conversion_executable_code',
				'message'  => 'Bricks conversion package appears to contain executable script or inline event code. Convert to safe Bricks interactions/Kiwe launchers or manual review.',
				'path'     => sanitize_text_field( $path ),
			];
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
		$allowed_top = [ 'enabled' => true, 'profile_label' => true, 'overrides' => true, 'bricks_theme_style' => true, 'project' => true ];
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

		if ( empty( $tokens['bricks_theme_style'] ) || ! is_array( $tokens['bricks_theme_style'] ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'missing_bricks_theme_style',
				'message'  => 'settings.tokens.bricks_theme_style must be a complete object so Kiwe > Framework can push the matching Bricks Theme Style.',
				'path'     => sanitize_text_field( $path ),
			];
		} else {
			$style        = $tokens['bricks_theme_style'];
			$allowed_keys = [
				'enabled'              => true,
				'id'                   => true,
				'label'                => true,
				'siteBackground'       => true,
				'site_background'      => true,
				'background'           => true,
				'colorPrimary'         => true,
				'color_primary'        => true,
				'primary'              => true,
				'brand'                => true,
				'colorSecondary'       => true,
				'color_secondary'      => true,
				'secondary'            => true,
				'accent'               => true,
				'colorSurface'         => true,
				'color_surface'        => true,
				'surface'              => true,
				'colorSurfaceRaised'   => true,
				'color_surface_raised' => true,
				'surfaceRaised'        => true,
				'colorLight'           => true,
				'color_light'          => true,
				'light'                => true,
				'colorDark'            => true,
				'color_dark'           => true,
				'dark'                 => true,
				'colorMuted'           => true,
				'color_muted'          => true,
				'muted'                => true,
				'colorBorder'          => true,
				'color_border'         => true,
				'borderColor'          => true,
				'border_color'         => true,
				'linkColor'            => true,
				'link_color'           => true,
				'colorLink'            => true,
				'color_link'           => true,
				'linkHoverColor'       => true,
				'link_hover_color'     => true,
				'fontDisplay'          => true,
				'font_display'         => true,
				'displayFont'          => true,
				'display_font'         => true,
				'fontBody'             => true,
				'font_body'            => true,
				'bodyFont'             => true,
				'body_font'            => true,
				'typeH1'               => true,
				'type_h1'              => true,
				'typeH2'               => true,
				'type_h2'              => true,
				'typeBody'             => true,
				'type_body'            => true,
				'radiusLg'             => true,
				'radius_lg'            => true,
				'radiusLarge'          => true,
				'radius_large'         => true,
				'shadowMd'             => true,
				'shadow_md'            => true,
				'shadowMedium'         => true,
				'shadow_medium'        => true,
				'spaceMd'              => true,
				'space_md'             => true,
			];
			foreach ( $style as $key => $value ) {
				if ( empty( $allowed_keys[ (string) $key ] ) ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'unsupported_bricks_theme_style_key',
						'message'  => sprintf( 'Unsupported settings.tokens.bricks_theme_style key "%s". Use global Bricks theme-style slots only.', (string) $key ),
						'path'     => sanitize_text_field( $path ),
					];
				}
			}
			if ( ! array_key_exists( 'enabled', $style ) || true !== $style['enabled'] ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'invalid_bricks_theme_style_enabled',
					'message'  => 'settings.tokens.bricks_theme_style.enabled must be true for Kiwe > Framework push.',
					'path'     => sanitize_text_field( $path ),
				];
			}
			if ( empty( $style['id'] ) || ! is_string( $style['id'] ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,79}$/i', $style['id'] ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'invalid_bricks_theme_style_id',
					'message'  => 'settings.tokens.bricks_theme_style.id must be a safe Bricks theme-style id.',
					'path'     => sanitize_text_field( $path ),
				];
			}
			if ( empty( $style['label'] ) || ! is_string( $style['label'] ) || strlen( $style['label'] ) > 100 ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'invalid_bricks_theme_style_label',
					'message'  => 'settings.tokens.bricks_theme_style.label must be a human-readable label up to 100 characters.',
					'path'     => sanitize_text_field( $path ),
				];
			}

			$core_token_coverage = [
				'color-brand'          => [ '--kiwe-color-brand', [ 'colorPrimary', 'color_primary', 'primary', 'brand', 'linkColor', 'link_color', 'colorLink', 'color_link' ] ],
				'color-accent'         => [ '--kiwe-color-accent', [ 'colorSecondary', 'color_secondary', 'secondary', 'accent', 'linkHoverColor', 'link_hover_color' ] ],
				'color-surface'        => [ '--kiwe-color-surface', [ 'siteBackground', 'site_background', 'background', 'colorSurface', 'color_surface', 'surface', 'colorLight', 'color_light', 'light' ] ],
				'color-surface-raised' => [ '--kiwe-color-surface-raised', [ 'colorSurfaceRaised', 'color_surface_raised', 'surfaceRaised' ] ],
				'color-text'           => [ '--kiwe-color-text', [ 'colorDark', 'color_dark', 'dark' ] ],
				'color-text-muted'     => [ '--kiwe-color-text-muted', [ 'colorMuted', 'color_muted', 'muted' ] ],
				'color-border'         => [ '--kiwe-color-border', [ 'colorBorder', 'color_border', 'borderColor', 'border_color' ] ],
				'font-display'         => [ '--kiwe-font-display', [ 'fontDisplay', 'font_display', 'displayFont', 'display_font' ] ],
				'font-body'            => [ '--kiwe-font-body', [ 'fontBody', 'font_body', 'bodyFont', 'body_font' ] ],
				'type-h1'              => [ '--kiwe-type-h1', [ 'typeH1', 'type_h1' ] ],
				'type-body'            => [ '--kiwe-type-body', [ 'typeBody', 'type_body' ] ],
				'space-md'             => [ '--kiwe-space-md', [ 'spaceMd', 'space_md' ] ],
				'radius-lg'            => [ '--kiwe-radius-lg', [ 'radiusLg', 'radius_lg', 'radiusLarge', 'radius_large' ] ],
				'shadow-md'            => [ '--kiwe-shadow-md', [ 'shadowMd', 'shadow_md', 'shadowMedium', 'shadow_medium' ] ],
			];
			$overrides           = isset( $tokens['overrides'] ) && is_array( $tokens['overrides'] ) ? $tokens['overrides'] : [];
			foreach ( $core_token_coverage as $token_name => $requirement ) {
				$css_var    = (string) $requirement[0];
				$style_keys = is_array( $requirement[1] ) ? $requirement[1] : [];
				$covered    = isset( $overrides[ $token_name ] ) && is_scalar( $overrides[ $token_name ] ) && '' !== trim( (string) $overrides[ $token_name ] );
				if ( ! $covered ) {
					foreach ( $style_keys as $style_key ) {
						if ( isset( $style[ $style_key ] ) && is_scalar( $style[ $style_key ] ) && '' !== trim( (string) $style[ $style_key ] ) ) {
							$covered = true;
							break;
						}
					}
				}
				if ( ! $covered ) {
					$findings[] = [
						'severity' => 'error',
						'code'     => 'missing_core_token_coverage',
						'message'  => sprintf( 'Framework profile must cover official token "%1$s" (%2$s) through settings.tokens.overrides or a mapped bricks_theme_style global slot so Kiwe > Framework push does not leave live Seam/Bricks variables empty.', $token_name, $css_var ),
						'path'     => sanitize_text_field( $path ),
					];
				}
			}
		}

		if ( isset( $tokens['project'] ) ) {
			$findings = array_merge( $findings, $this->review_project_token_extensions( $tokens['project'], $path . '.project' ) );
		}

		return $findings;
	}

	private function review_project_token_extensions( $project, string $path ): array {
		$findings = [];
		if ( ! is_array( $project ) ) {
			return [
				[
					'severity' => 'error',
					'code'     => 'invalid_project_extensions',
					'message'  => 'settings.tokens.project must be an object when present.',
					'path'     => sanitize_text_field( $path ),
				],
			];
		}

		$allowed = [ 'enabled' => true, 'id' => true, 'label' => true, 'name' => true, 'variables' => true, 'classes' => true ];
		foreach ( $project as $key => $value ) {
			if ( empty( $allowed[ (string) $key ] ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'unsupported_project_key',
					'message'  => sprintf( 'Unsupported settings.tokens.project key "%s". Use id, label, variables, and classes only.', (string) $key ),
					'path'     => sanitize_text_field( $path ),
				];
			}
		}

		$variables = isset( $project['variables'] ) && is_array( $project['variables'] ) ? $project['variables'] : [];
		foreach ( $variables as $index => $variable ) {
			$name = is_array( $variable ) ? strtolower( trim( (string) ( $variable['name'] ?? $variable['variable'] ?? $variable['key'] ?? '' ) ) ) : '';
			if ( ! preg_match( '/^--[a-z][a-z0-9]*-[a-z0-9][a-z0-9_-]{0,80}$/', $name ) || preg_match( '/^--(?:kiwe|seam)-/', $name ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'invalid_project_variable_name',
					'message'  => 'Project variables must be prefixed CSS custom properties such as --nc-card-radius and must not use reserved --kiwe-* or --seam-* names.',
					'path'     => sanitize_text_field( $path . '.variables.' . $index ),
				];
			}
		}

		$classes = isset( $project['classes'] ) && is_array( $project['classes'] ) ? $project['classes'] : [];
		foreach ( $classes as $index => $class ) {
			$name = is_array( $class ) ? sanitize_html_class( (string) ( $class['name'] ?? '' ) ) : '';
			if ( ! preg_match( '/^(?!(?:kiwe|seam)-)(?:[a-z][a-z0-9]{1,12})-[a-z0-9][a-z0-9_-]{1,80}$/', $name ) ) {
				$findings[] = [
					'severity' => 'error',
					'code'     => 'invalid_project_class_name',
					'message'  => 'Project classes must be prefixed and collision-safe, for example nc-promo-card or bv-product-card. Universal seam-* classes belong to the Seam library, not the project lane.',
					'path'     => sanitize_text_field( $path . '.classes.' . $index ),
				];
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
		$css_without_comments = (string) preg_replace( '/\/\*[\s\S]*?\*\//', '', $css );
		$declaration_text      = '';
		if ( preg_match_all( '/[^{}]+\{([^{}]*)\}/', $css_without_comments, $declaration_matches ) ) {
			$declaration_text = implode( "\n", array_map( 'strval', $declaration_matches[1] ) );
		}
		$literal_lengths = [];
		$literal_colors  = [];
		$literal_effects = [];
		if ( preg_match_all( '/(^|[^-_a-zA-Z0-9.])(-?(?:\d*\.)?\d+(?:px|rem|em|ch|ex|cap|ic|lh|rlh|vw|vh|vmin|vmax|svw|svh|lvw|lvh|dvw|dvh|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc))\b/i', $css_without_comments, $length_matches, PREG_SET_ORDER ) ) {
			foreach ( $length_matches as $match ) {
				$literal_lengths[] = strtolower( (string) $match[2] );
			}
		}
		if ( preg_match_all( '/(^|[^#_a-zA-Z0-9-])(#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b)/i', $declaration_text, $color_matches, PREG_SET_ORDER ) ) {
			foreach ( $color_matches as $match ) {
				$literal_colors[] = strtolower( (string) $match[2] );
			}
		}
		if ( preg_match_all( '/\b(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color-mix|light-dark|color)\s*\(/i', $declaration_text, $color_fn_matches, PREG_SET_ORDER ) ) {
			foreach ( $color_fn_matches as $match ) {
				$literal_colors[] = strtolower( preg_replace( '/\s+/', '', (string) $match[0] ) );
			}
		}
		if ( preg_match_all( '/(?:^|;)\s*((?:box-shadow|text-shadow)\s*:\s*(?![^;]*\b(?:none|inherit|initial|unset|revert)\b)(?![^;]*var\()[^;]+)/i', $declaration_text, $effect_matches, PREG_SET_ORDER ) ) {
			foreach ( $effect_matches as $match ) {
				$literal_effects[] = substr( preg_replace( '/\s+/', ' ', trim( (string) $match[1] ) ), 0, 120 );
			}
		}
		$literal_lengths = array_values( array_unique( $literal_lengths ) );
		$literal_colors  = array_values( array_unique( $literal_colors ) );
		$literal_effects = array_values( array_unique( $literal_effects ) );
		sort( $literal_lengths );
		sort( $literal_colors );
		sort( $literal_effects );
		$literal_details = [];
		if ( ! empty( $literal_lengths ) ) {
			$literal_details[] = 'lengths ' . implode( ', ', $literal_lengths );
		}
		if ( ! empty( $literal_colors ) ) {
			$literal_details[] = 'colors/functions ' . implode( ', ', $literal_colors );
		}
		if ( ! empty( $literal_effects ) ) {
			$literal_details[] = 'effects ' . implode( ' | ', $literal_effects );
		}
		if ( ! empty( $literal_details ) ) {
			$findings[] = [
				'severity' => 'error',
				'code'     => 'anonymous_literal_value_in_theme_css',
				'message'  => sprintf( 'Importable theme.css contains anonymous CSS literal(s): %s. AppShell theme CSS must consume official --kiwe-* universal tokens, documented --kiwe-theme-* aliases, or Kiwe/DSA geometry variables. Put concrete base values in theme-package.json settings.tokens or Kiwe core token registries, not installable theme.css.', implode( '; ', $literal_details ) ),
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
			'tokenPurityChecked' => ! isset( $codes['private_runtime_bridge_token_in_theme_css'] ) && ! isset( $codes['anonymous_literal_px_in_theme_css'] ) && ! isset( $codes['anonymous_literal_value_in_theme_css'] ) && ! isset( $codes['token_css_variable_key'] ) && ! isset( $codes['invalid_token_override_name'] ),
			'pageArtifactChecked' => ! isset( $codes['page_artifact_contains_appshell'] ),
			'bricksConversionChecked' => ( '' !== $this->file_like( $path_map, 'kiwe-bricks-conversion.json' ) || $this->has_path_matching( $path_map, '#(^|/)bricks-template/[^/]+-template-upload\.json$#i' ) ) && ! isset( $codes['invalid_bricks_conversion_json'] ) && ! isset( $codes['bricks_conversion_missing_root_key'] ) && ! isset( $codes['invalid_bricks_conversion_schema'] ) && ! isset( $codes['bricks_conversion_missing_source'] ) && ! isset( $codes['bricks_conversion_forbidden_source_lane'] ) && ! isset( $codes['bricks_conversion_missing_elements'] ) && ! isset( $codes['bricks_conversion_missing_fidelity_map'] ) && ! isset( $codes['bricks_conversion_invalid_fidelity_lane'] ) && ! isset( $codes['bricks_conversion_missing_responsive_intent'] ) && ! isset( $codes['bricks_conversion_invalid_responsive_intent'] ) && ! isset( $codes['bricks_conversion_missing_complex_layout_fidelity'] ) && ! isset( $codes['bricks_conversion_missing_complex_responsive_fidelity'] ) && ! isset( $codes['bricks_conversion_unproven_seam_spread_direction_override'] ) && ! isset( $codes['bricks_conversion_source_contains_appshell'] ) && ! isset( $codes['bricks_conversion_contains_appshell_markup'] ) && ! isset( $codes['bricks_conversion_lost_seam_classes'] ) && ! isset( $codes['bricks_conversion_lost_kiwe_launcher'] ) && ! isset( $codes['bricks_conversion_missing_query_intent'] ) && ! isset( $codes['bricks_conversion_executable_code'] ),
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
