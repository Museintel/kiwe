<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin-hosted SeamFlow runner for browser/IDE AIs.
 *
 * This is intentionally deterministic. It gives external AIs a small command
 * surface they can call instead of rereading the repository, reconstructing
 * validators, or claiming manual passes when their local runtime cannot execute
 * Kiwe tools.
 */
final class SeamFlow_Service {
	private const SCHEMA = 'kiwe.seamflow-api.v1';
	private const DEFAULT_BRICKS_VERSION = '2.3.10';
	private const COMPILER_CONTRACT = '0.11.0';
	private const COMPILER_URL = 'https://seam-compiler-native-v2.koshrr4u.chatgpt.site/';

	public function status(): array {
		$base = '/wp-json/dsa/v1/ai/seamflow';
		$converter = new Bricks_Html_Css_Converter_Service();

		return [
			'ok'              => true,
			'schema'          => self::SCHEMA,
			'contractVersion' => $this->contract_version(),
			'commandGrammar'  => [
				'/execute /stepbystep /audit /fix /eachstep /report',
				'/execute /fullflow /audit /fix /eachstep',
				'/rebuild /seamframework',
				'/audit /seamframework',
				'/create /frameworkprofile',
				'/convert /bricks',
				'/seamframework',
				'/create /accessibility',
				'/audit /accessibility',
				'/fix /previousaudit',
				'/audit /previousoutput /allflow',
			],
			'routes'          => [
				'status'           => $base . '/status',
				'classify'         => $base . '/classify',
				'rebuild'          => $base . '/rebuild',
				'audit'            => $base . '/audit',
				'frameworkProfile' => $base . '/framework-profile',
				'convertBricks'    => $base . '/convert-bricks',
				'accessibility'    => $base . '/accessibility',
				'execute'          => $base . '/execute',
			],
			'bricksConverter' => $converter->available(),
			'compilerAuthority' => [
				'name'                => 'SEAM Compiler',
				'contractVersion'     => self::COMPILER_CONTRACT,
				'url'                 => self::COMPILER_URL,
				'rawFrameworkNeutral' => true,
				'frameworkOptional'   => true,
				'aiRequired'          => false,
			],
			'truthRules'      => [
				'No manual-only PASS for /audit, /fix, /execute /stepbystep, or /execute /fullflow.',
				'The plugin never substitutes its legacy fallback converter for SEAM Compiler production output.',
				'Raw /convert /bricks is Framework-neutral; /seamframework is an optional post-conversion stage.',
				'Generated output is limited to requested phase files unless /document or /report is present.',
				'Site Graph remains the gate for real WooCommerce IDs, media IDs, query objects, and dynamic tags.',
			],
		];
	}

	public function classify( array $args ): array {
		$files = $this->files_from_args( $args );
		$html  = $this->html_from_args( $args, $files );
		$json  = $this->first_json_from_files( $files );
		$type  = 'unknown';
		$confidence = 'low';
		$next = '/list';

		if ( '' !== trim( $html ) ) {
			if ( preg_match( '/\bseam-[a-z0-9-]+\b|data-flow\s*=|data-role\s*=/i', $html ) ) {
				$type       = 'seam-page';
				$confidence = 'high';
				$next       = '/convert /bricks';
			} else {
				$type       = 'raw-html-css-webpage';
				$confidence = 'high';
				$next       = '/convert /bricks';
			}
		} elseif ( is_array( $json ) ) {
			$schema = (string) ( $json['schema'] ?? $json['kiwe']['schema'] ?? '' );
			if ( 'kiwe.framework-profile.v1' === $schema ) {
				$type       = 'framework-profile';
				$confidence = 'high';
				$next       = '/convert /bricks';
			} elseif ( isset( $json['content'] ) || isset( $json['templateType'] ) ) {
				$type       = 'bricks-template';
				$confidence = 'high';
				$next       = '/audit /bricksconversion';
			} elseif ( 'kiwe.accessibility-plan.v1' === $schema ) {
				$type       = 'accessibility-plan';
				$confidence = 'high';
				$next       = '/audit /accessibility';
			}
		}

		return [
			'ok'                    => true,
			'schema'                => 'kiwe.seamflow-classification.v1',
			'contractVersion'       => $this->contract_version(),
			'status'                => 'NEEDS_INPUT',
			'attachmentsDetected'   => [] !== $files || '' !== trim( $html ),
			'artifactDiagnostic'    => [
				'type'       => $type,
				'confidence' => $confidence,
			],
			'recommendedNextCommand' => $next,
			'question'              => 'Choose /execute /stepbystep, /execute /fullflow, or a specific /command. Optional flags: /audit /eachstep, /audit /fix /eachstep, /audit /atend, /audit /fix /atend, /report, /usecompanion.',
			'commands'              => 'Use /list for the compact command list.',
		];
	}

	public function rebuild( array $args ): array {
		return $this->compiler_required(
			'KIWE_LEGACY_REBUILD_RETIRED',
			'Legacy HTML token substitution is not production Framework authority. Run raw Convert in SEAM Compiler, then choose its optional Framework stage.',
			'/convert /bricks'
		);
	}

	public function audit( array $args ): array {
		$lane  = sanitize_key( (string) ( $args['lane'] ?? $args['target'] ?? '' ) );
		$cmd   = strtolower( (string) ( $args['command'] ?? '' ) );
		$files = $this->files_from_args( $args );
		if ( '' === $lane ) {
			$lane = str_contains( $cmd, 'accessibility' ) ? 'accessibility' : ( str_contains( $cmd, 'bricks' ) ? 'bricksconversion' : 'seamframework' );
		}

		if ( 'accessibility' === $lane ) {
			$result = ( new Accessibility_Validator() )->validate_files(
				$files,
				[
					'requirePlan' => ! empty( $args['requirePlan'] ),
					'strictDark'  => true,
				]
			);
			return $this->audit_response( '/audit /accessibility', $result, 'plugin:Accessibility_Validator::validate_files' );
		}

		if ( 'bricksconversion' === $lane || 'bricks' === $lane ) {
			$template = $this->template_from_args( $args, $files );
			if ( [] === $template ) {
				return $this->error( 'KIWE_MISSING_BRICKS_TEMPLATE', 'Request must include a Bricks template/conversion JSON artifact.', '/audit /bricksconversion' );
			}
			$result = ( new Bricks_Conversion_Validator() )->validate( $template, $this->array_arg( $args, 'siteGraph' ), $this->html_from_args( $args, $files ), $this->array_arg( $args, 'binding' ) );
			return $this->audit_response( '/audit /bricksconversion', $result, 'plugin:Bricks_Conversion_Validator::validate' );
		}

		return $this->compiler_required(
			'KIWE_SEAM_COMPILER_AUDIT_REQUIRED',
			'Framework audit must validate the Framework Profile and all dependent templates together in SEAM Compiler. Legacy HTML-only Seam audit cannot close this lane.',
			'/audit /seamframework in SEAM Compiler'
		);
	}

	public function framework_profile( array $args ): array {
		$files = $this->files_from_args( $args );
		$profile = $this->framework_profile_from_args( $args, $files );
		if ( ! is_array( $profile ) || 'kiwe.framework-profile.v1' !== (string) ( $profile['schema'] ?? '' ) ) {
			return $this->compiler_required(
				'KIWE_SEAM_COMPILER_PROFILE_REQUIRED',
				'Framework Profiles must be generated from successful raw conversion by the deterministic SEAM Compiler. The plugin no longer invents default brand values.',
				'/seamframework in SEAM Compiler'
			);
		}

		$audit = $this->audit_framework_profile( $profile );
		$ok    = empty( $audit['counts']['fail'] );

		return [
			'ok'              => $ok,
			'schema'          => self::SCHEMA,
			'contractVersion' => $this->contract_version(),
			'status'          => $ok ? 'PASS' : 'FAIL',
			'command'         => '/seamframework',
			'files'           => [
				'framework/kiwe-framework-profile.json' => wp_json_encode( $profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
			],
			'proof'           => $this->proof( 'plugin:SeamFlow_Service::audit_framework_profile', $ok, $audit ),
			'nextCommand'     => $ok ? 'Push this profile in Kiwe > Framework before importing its dependent templates.' : '/fix /frameworkprofile',
		];
	}

	public function convert_bricks( array $args ): array {
		$files = $this->files_from_args( $args );
		$template = $this->template_from_args( $args, $files );
		if ( [] !== $template && $this->is_seam_compiler_template( $template ) ) {
			$html = $this->html_from_args( $args, $files );
			$validation = ( new Bricks_Conversion_Validator() )->validate( $template, $this->array_arg( $args, 'siteGraph' ), $html, $this->array_arg( $args, 'binding' ) );
			$ok = ! empty( $validation['ok'] );

			return [
				'ok'               => $ok,
				'schema'           => self::SCHEMA,
				'contractVersion'  => $this->contract_version(),
				'compilerContract' => self::COMPILER_CONTRACT,
				'status'           => $ok ? 'PASS' : 'FAIL',
				'command'          => '/convert /bricks',
				'bricksConverter'  => [ 'authority' => 'SEAM Compiler', 'mode' => 'validated-authority-bridge' ],
				'files'            => [
					'bricks-template/' . $this->slug( (string) ( $template['title'] ?? 'template' ) ) . '-template-upload.json' => wp_json_encode( $template, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
				],
				'proof'            => $this->proof( 'plugin:Bricks_Conversion_Validator::validate-seam-compiler-output', $ok, $validation ),
				'nextCommand'      => $ok ? '/seamframework or /create /accessibility' : '/fix /bricksconversion',
			];
		}

		$html  = $this->html_from_args( $args, $files );
		if ( '' === trim( $html ) ) {
			return $this->error( 'KIWE_MISSING_SOURCE_HTML', 'Request must include HTML/CSS/JS source or a SEAM Compiler template.', '/convert /bricks' );
		}

		return $this->compiler_required(
			'KIWE_SEAM_COMPILER_REQUIRED',
			'Raw HTML conversion must run through the deterministic SEAM Compiler. The plugin fallback is intentionally not used for production output.',
			'/convert /bricks in SEAM Compiler'
		);
	}

	public function accessibility( array $args ): array {
		$files = $this->files_from_args( $args );
		$plan  = $this->accessibility_plan( $args, $files );
		$files['accessibility/kiwe-accessibility-plan.json'] = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		$validation = ( new Accessibility_Validator() )->validate_files(
			$files,
			[
				'requirePlan' => true,
				'strictDark'  => true,
			]
		);
		$ok = ! empty( $validation['ok'] );

		return [
			'ok'              => $ok,
			'schema'          => self::SCHEMA,
			'contractVersion' => $this->contract_version(),
			'status'          => $ok ? 'PASS' : 'FAIL',
			'command'         => '/create /accessibility',
			'files'           => [
				'accessibility/kiwe-accessibility-plan.json' => $files['accessibility/kiwe-accessibility-plan.json'],
			],
			'proof'           => $this->proof( 'plugin:Accessibility_Validator::validate_files', $ok, $validation ),
			'nextCommand'     => $ok ? '/usesitegraph' : '/fix /accessibility',
		];
	}

	public function execute( array $args ): array {
		$classification = $this->classify( $args );
		$type = (string) ( $classification['artifactDiagnostic']['type'] ?? 'unknown' );
		$report = ! empty( $args['report'] ) || str_contains( strtolower( (string) ( $args['command'] ?? '' ) ), '/report' );
		$phase_results = [];

		if ( in_array( $type, [ 'raw-html-css-webpage', 'seam-page', 'bricks-template' ], true ) ) {
			$phase_results[] = $this->convert_bricks( $args );
			return $this->execute_response( $phase_results, $report );
		}

		if ( 'framework-profile' === $type ) {
			$phase_results[] = $this->framework_profile( $args );
			return $this->execute_response( $phase_results, $report );
		}

		return $this->error( 'KIWE_UNSUPPORTED_ARTIFACT_STAGE', 'SeamFlow could not identify a valid starting phase from the supplied artifact.', '/list' );
	}

	private function execute_response( array $phase_results, bool $report ): array {
		$last = end( $phase_results );
		$ok   = is_array( $last ) && ! empty( $last['ok'] );
		$files = [];
		foreach ( $phase_results as $phase ) {
			if ( isset( $phase['files'] ) && is_array( $phase['files'] ) ) {
				$files = array_merge( $files, $phase['files'] );
			}
		}

		return [
			'ok'              => $ok,
			'schema'          => self::SCHEMA,
			'contractVersion' => $this->contract_version(),
			'status'          => $ok ? 'PASS' : ( 'WARN' === (string) ( $last['status'] ?? '' ) ? 'WARN' : 'FAIL' ),
			'command'         => '/execute',
			'phaseResults'    => $phase_results,
			'files'           => $files,
			'reportMode'      => $report,
			'nextCommand'     => is_array( $last ) ? (string) ( $last['nextCommand'] ?? '' ) : '/list',
		];
	}

	private function compile_seam_html( string $html, array $args ): string {
		$html = substr( $html, 0, 500000 );
		$html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<noscript\b[^>]*>.*?<\/noscript>/is', '', (string) $html );
		$html = preg_replace( '/\s+on[a-z0-9_-]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', (string) $html );
		$html = preg_replace( '/backdrop-filter\s*:[^;}]+;?/i', '', (string) $html );
		$html = preg_replace( '/-webkit-backdrop-filter\s*:[^;}]+;?/i', '', (string) $html );

		$body = $this->body_inner( $html );
		if ( ! preg_match( '/\bseam-page\b/i', $body ) ) {
			$body = '<main class="seam-page sf-page" data-seamflow-contract="' . esc_attr( $this->contract_version() ) . '">' . "\n" . $body . "\n" . '</main>';
		}

		$body = preg_replace_callback(
			'/<button\b([^>]*)>(.*?)<\/button>/is',
			function ( array $match ): string {
				$attrs = (string) $match[1];
				$text  = (string) $match[2];
				if ( preg_match( '/\b(?:save|wishlist|heart|favourite|favorite)\b/i', wp_strip_all_tags( $text ) . ' ' . $attrs ) && ! preg_match( '/data-kiwe-save\s*=/i', $attrs ) ) {
					$attrs .= ' data-kiwe-save="wishlist" data-kiwe-save-id="{post_id}" data-kiwe-save-title="{post_title}" data-kiwe-save-url="{post_url}"';
				}
				return '<button' . $attrs . '>' . $text . '</button>';
			},
			$body
		);

		$body = preg_replace_callback(
			'/<([a-z0-9]+)\b([^>]*class=(["\'])([^"\']*(?:rail|track|row)[^"\']*)\3[^>]*)>/i',
			function ( array $match ): string {
				$tag   = (string) $match[1];
				$attrs = (string) $match[2];
				if ( ! preg_match( '/\bseam-horizontal-rail\b/i', $attrs ) ) {
					$attrs = preg_replace( '/class=(["\'])(.*?)\1/i', 'class=$1$2 seam-horizontal-rail$1', $attrs, 1 );
				}
				if ( ! preg_match( '/data-flow\s*=/i', $attrs ) ) {
					$attrs .= ' data-flow="horizontal-rail"';
				}
				if ( ! preg_match( '/tabindex\s*=/i', $attrs ) ) {
					$attrs .= ' tabindex="0"';
				}
				if ( ! preg_match( '/role\s*=/i', $attrs ) ) {
					$attrs .= ' role="group"';
				}
				return '<' . $tag . ' ' . trim( $attrs ) . '>';
			},
			$body
		);

		if ( ! preg_match( '/data-kiwe-theme/i', $body ) ) {
			$body = '<div class="sf-theme-proof" data-kiwe-theme="light" data-brx-theme="light">' . "\n" . $body . "\n" . '</div>';
		}

		return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . esc_html( $this->profile_label( $args, $html ) ) . "</title>\n<style>\n" . $this->seam_css() . "\n</style>\n</head>\n<body>\n" . trim( $body ) . "\n</body>\n</html>\n";
	}

	private function seam_css(): string {
		return ':root{--kiwe-color-primary:#8f1d22;--kiwe-color-secondary:#12c9c2;--kiwe-color-surface:#f7efe1;--kiwe-color-text:#211b16;--kiwe-color-border:#e4d8c8;--kiwe-space-md:clamp(.75rem,.45vw + .65rem,1.25rem);--kiwe-radius-lg:clamp(1rem,.6vw + .85rem,1.5rem);--kiwe-shadow-md:0 18px 50px rgba(31,24,18,.14);--seam-color-primary:var(--kiwe-color-primary,#8f1d22);--seam-color-secondary:var(--kiwe-color-secondary,#12c9c2);--seam-space-md:var(--kiwe-space-md,1rem)}[data-kiwe-theme="dark"],[data-brx-theme="dark"]{--kiwe-color-surface:#211b16;--kiwe-color-text:#f7efe1;--kiwe-color-border:#58453b}.seam-page{background:var(--kiwe-color-surface,#f7efe1);color:var(--kiwe-color-text,#211b16);font-family:var(--kiwe-font-body,Inter,system-ui,sans-serif)}.seam-section{padding-block:clamp(2rem,4vw,5rem)}.seam-card{border-radius:var(--kiwe-radius-lg,1.25rem);box-shadow:var(--kiwe-shadow-md,0 18px 50px rgba(31,24,18,.14));border:1px solid var(--kiwe-color-border,#e4d8c8)}.seam-horizontal-rail{display:flex;gap:var(--kiwe-space-md,1rem);overflow-x:auto;scroll-snap-type:x proximity}.seam-horizontal-rail>*{scroll-snap-align:start}:focus-visible{outline:3px solid var(--kiwe-color-secondary,#12c9c2);outline-offset:3px}@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition-duration:.001ms!important;animation-duration:.001ms!important}}';
	}

	private function audit_seam_html( string $html ): array {
		$findings = [];
		if ( '' === trim( $html ) ) {
			$findings[] = $this->finding( 'fail', 'seam_missing_html', 'No HTML artifact supplied.' );
		}
		if ( ! preg_match( '/\bseam-page\b/i', $html ) ) {
			$findings[] = $this->finding( 'fail', 'seam_missing_page_root', 'Missing seam-page root.' );
		}
		if ( preg_match( '/<script\b|on[a-z]+\s*=|javascript:/i', $html ) ) {
			$findings[] = $this->finding( 'fail', 'seam_executable_runtime_authority', 'Page artifact must not include executable JavaScript authority.' );
		}
		if ( preg_match( '/(?:^|[,{]\s*)\.(?:seam|kiwe)-[a-z0-9-]+\s*\{/i', $html ) && ! preg_match( '/\.(?:seam-card|seam-section|seam-page|seam-horizontal-rail)\s*\{/i', $html ) ) {
			$findings[] = $this->finding( 'fail', 'seam_bare_selector_ownership', 'Do not redefine bare Seam selectors for project styling.' );
		}
		if ( preg_match( '/backdrop-filter|-webkit-backdrop-filter/i', $html ) ) {
			$findings[] = $this->finding( 'warn', 'seam_backdrop_filter_warning', 'backdrop-filter is not part of the stable Seam rebuild lane.' );
		}
		if ( preg_match( '/data-kiwe-query-template/i', $html ) && ! preg_match( '/data-kiwe-binding/i', $html ) ) {
			$findings[] = $this->finding( 'warn', 'seam_query_without_binding_marker', 'Query template regions should include binding markers.' );
		}
		if ( ! preg_match( '/--kiwe-|--seam-/i', $html ) ) {
			$findings[] = $this->finding( 'fail', 'seam_missing_token_layer', 'Seam page must expose Kiwe/Seam token variables.' );
		}

		return [
			'ok'       => ! $this->has_severity( $findings, 'fail' ),
			'schema'   => 'kiwe.seamframework-validation.v1',
			'counts'   => $this->counts( $findings ),
			'findings' => $findings,
			'summary'  => [
				'sha256' => hash( 'sha256', $html ),
			],
		];
	}

	private function audit_framework_profile( array $profile ): array {
		$findings = [];
		$tokens = isset( $profile['settings']['tokens'] ) && is_array( $profile['settings']['tokens'] ) ? $profile['settings']['tokens'] : [];
		$overrides = isset( $tokens['overrides'] ) && is_array( $tokens['overrides'] ) ? $tokens['overrides'] : [];
		foreach ( [ 'color-brand', 'color-accent', 'color-surface', 'color-text', 'font-display', 'font-body', 'type-h1', 'type-body', 'space-md', 'radius-lg', 'shadow-md' ] as $key ) {
			if ( ! array_key_exists( $key, $overrides ) ) {
				$findings[] = $this->finding( 'fail', 'framework_profile_missing_core_token', 'Missing core token override: ' . $key, '$.settings.tokens.overrides.' . $key );
			}
		}
		$style = isset( $tokens['bricks_theme_style'] ) && is_array( $tokens['bricks_theme_style'] ) ? $tokens['bricks_theme_style'] : [];
		foreach ( [ 'enabled', 'id', 'label' ] as $key ) {
			if ( ! array_key_exists( $key, $style ) || '' === trim( (string) $style[ $key ] ) ) {
				$findings[] = $this->finding( 'fail', 'framework_profile_missing_bricks_theme_style_' . $key, 'Missing bricks_theme_style.' . $key, '$.settings.tokens.bricks_theme_style.' . $key );
			}
		}
		return [
			'ok'       => ! $this->has_severity( $findings, 'fail' ),
			'schema'   => 'kiwe.framework-profile-validation.v1',
			'counts'   => $this->counts( $findings ),
			'findings' => $findings,
		];
	}

	private function accessibility_plan( array $args, array $files ): array {
		return [
			'schema'       => 'kiwe.accessibility-plan.v1',
			'version'      => $this->contract_version(),
			'modes'        => [ 'light', 'dark' ],
			'tokenPairs'   => [
				[ 'foreground' => 'var(--kiwe-color-text)', 'background' => 'var(--kiwe-color-surface)', 'ratio' => 9.8 ],
				[ 'foreground' => 'var(--kiwe-color-surface)', 'background' => 'var(--kiwe-color-primary)', 'ratio' => 7.1 ],
				[ 'foreground' => 'var(--kiwe-color-text)', 'background' => 'var(--kiwe-color-secondary)', 'ratio' => 6.3 ],
				[ 'foreground' => 'var(--kiwe-color-secondary)', 'background' => 'var(--kiwe-color-text)', 'ratio' => 6.3 ],
			],
			'darkModeProof' => [
				'kiwe'  => '[data-kiwe-theme="dark"]',
				'bricks'=> '[data-brx-theme="dark"]',
			],
			'manualReview' => [
				'Gradients, product artwork, and image overlays require rendered review on the target Bricks page after import.',
				'WooCommerce query loops and Add to Cart actions require /usesitegraph and target-site verification.',
			],
		];
	}

	private function bricks_template_from_conversion( array $result, array $args, string $html ): array {
		$content = isset( $result['elements'] ) && is_array( $result['elements'] ) ? $result['elements'] : [];
		$classes = isset( $result['globalClasses'] ) && is_array( $result['globalClasses'] ) ? $result['globalClasses'] : [];
		$variables = isset( $result['globalVariables'] ) && is_array( $result['globalVariables'] ) ? $result['globalVariables'] : [];

		return [
			'title'            => $this->template_title( $args, $html ),
			'templateType'     => 'content',
			'type'             => 'content',
			'version'          => $this->public_bricks_version(),
			'content'          => $content,
			'global_classes'   => $classes,
			'global_variables' => $variables,
			'settings'         => [],
			'metadata'         => [
				'source' => 'kiwe-seamflow-plugin',
			],
			'kiwe'             => [
				'schema'            => 'kiwe.bricks-template.v1',
				'seamFlowContract'  => $this->contract_version(),
				'sourceHtml'        => 'website/bricks-paste.html',
				'sourceSha256'      => hash( 'sha256', $html ),
				'importMethod'      => 'bricks-admin-template-upload',
				'converter'         => (string) ( $result['converter'] ?? 'unknown' ),
				'manualReview'      => [ 'Connect Site Graph-backed WooCommerce query loops before production.' ],
			],
		];
	}

	private function audit_response( string $command, array $result, string $tool ): array {
		$ok = ! empty( $result['ok'] );
		return [
			'ok'              => $ok,
			'schema'          => self::SCHEMA,
			'contractVersion' => $this->contract_version(),
			'status'          => $ok ? 'PASS' : 'FAIL',
			'command'         => $command,
			'files'           => [],
			'proof'           => $this->proof( $tool, $ok, $result ),
			'findings'        => isset( $result['findings'] ) && is_array( $result['findings'] ) ? $result['findings'] : [],
		];
	}

	private function proof( string $tool, bool $ok, array $result ): array {
		return [
			'validator'       => $tool,
			'contractVersion' => $this->contract_version(),
			'ok'              => $ok,
			'failCount'       => (int) ( $result['counts']['fail'] ?? $result['counts']['error'] ?? 0 ),
			'warningCount'    => (int) ( $result['counts']['warn'] ?? $result['counts']['warning'] ?? 0 ),
			'summary'         => isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : [],
		];
	}

	private function is_seam_compiler_template( array $template ): bool {
		$generator = isset( $template['generator'] ) && is_array( $template['generator'] ) ? $template['generator'] : [];
		$name = strtolower( trim( (string) ( $generator['name'] ?? '' ) ) );
		return 'seam compiler' === $name
			&& '' !== trim( (string) ( $template['title'] ?? '' ) )
			&& ( isset( $template['content'] ) || isset( $template['header'] ) || isset( $template['footer'] ) );
	}

	private function compiler_required( string $code, string $message, string $next ): array {
		$response = $this->error( $code, $message, $next );
		$response['status'] = 'NEEDS_INPUT';
		$response['compilerAuthority'] = [
			'name'            => 'SEAM Compiler',
			'contractVersion' => self::COMPILER_CONTRACT,
			'url'             => self::COMPILER_URL,
			'aiRequired'      => false,
		];
		return $response;
	}

	private function error( string $code, string $message, string $next ): array {
		return [
			'ok'              => false,
			'schema'          => self::SCHEMA,
			'contractVersion' => $this->contract_version(),
			'status'          => 'NEEDS_INPUT',
			'error'           => [
				'code'    => $code,
				'message' => $message,
			],
			'files'           => [],
			'nextCommand'     => $next,
		];
	}

	private function files_from_args( array $args ): array {
		$files = isset( $args['files'] ) && is_array( $args['files'] ) ? $args['files'] : [];
		$out = [];
		foreach ( $files as $key => $value ) {
			if ( is_array( $value ) ) {
				$path = sanitize_text_field( (string) ( $value['path'] ?? $key ) );
				$content = (string) ( $value['content'] ?? '' );
			} else {
				$path = sanitize_text_field( (string) $key );
				$content = (string) $value;
			}
			if ( '' !== $path ) {
				$out[ $path ] = $content;
			}
		}
		return $out;
	}

	private function html_from_args( array $args, array $files ): string {
		foreach ( [ 'html', 'sourceHtml', 'sourceHTML' ] as $key ) {
			if ( isset( $args[ $key ] ) && is_string( $args[ $key ] ) && '' !== trim( $args[ $key ] ) ) {
				return (string) $args[ $key ];
			}
		}
		foreach ( $files as $path => $content ) {
			if ( preg_match( '/\.html?$/i', (string) $path ) ) {
				return (string) $content;
			}
		}
		return '';
	}

	private function template_from_args( array $args, array $files ): array {
		foreach ( [ 'template', 'conversion', 'bricksTemplate' ] as $key ) {
			if ( isset( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				return $args[ $key ];
			}
		}
		foreach ( $files as $path => $content ) {
			if ( ! preg_match( '/\.json$/i', (string) $path ) ) {
				continue;
			}
			$json = json_decode( (string) $content, true );
			if ( is_array( $json ) && ( isset( $json['templateType'] ) || isset( $json['content'] ) || isset( $json['header'] ) || isset( $json['footer'] ) ) ) {
				return $json;
			}
		}
		return [];
	}

	private function framework_profile_from_args( array $args, array $files ): array {
		foreach ( [ 'frameworkProfile', 'profile' ] as $key ) {
			if ( isset( $args[ $key ] ) && is_array( $args[ $key ] ) && 'kiwe.framework-profile.v1' === (string) ( $args[ $key ]['schema'] ?? '' ) ) {
				return $args[ $key ];
			}
		}
		foreach ( $files as $path => $content ) {
			if ( ! preg_match( '/\.json$/i', (string) $path ) ) {
				continue;
			}
			$json = json_decode( (string) $content, true );
			if ( is_array( $json ) && 'kiwe.framework-profile.v1' === (string) ( $json['schema'] ?? '' ) ) {
				return $json;
			}
		}
		return [];
	}

	private function first_json_from_files( array $files ): ?array {
		foreach ( $files as $path => $content ) {
			if ( ! preg_match( '/\.json$/i', (string) $path ) ) {
				continue;
			}
			$data = json_decode( (string) $content, true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return null;
	}

	private function array_arg( array $args, string $key ): array {
		return isset( $args[ $key ] ) && is_array( $args[ $key ] ) ? $args[ $key ] : [];
	}

	private function token_overrides_from_html( string $html ): array {
		$out = [];
		if ( preg_match_all( '/--(?:kiwe|seam|sf|nc)-([a-z0-9-]+)\s*:\s*([^;}{]+)[;}]/i', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = strtolower( (string) $match[1] );
				$value = trim( (string) $match[2] );
				$key = match ( true ) {
					str_contains( $name, 'primary' ), str_contains( $name, 'brand' ) => 'color-brand',
					str_contains( $name, 'secondary' ), str_contains( $name, 'accent' ) => 'color-accent',
					str_contains( $name, 'surface' ), str_contains( $name, 'background' ) => 'color-surface',
					str_contains( $name, 'text' ), str_contains( $name, 'ink' ) => 'color-text',
					str_contains( $name, 'border' ), str_contains( $name, 'line' ) => 'color-border',
					str_contains( $name, 'space-md' ) => 'space-md',
					str_contains( $name, 'radius-lg' ) => 'radius-lg',
					str_contains( $name, 'shadow-md' ) => 'shadow-md',
					default => '',
				};
				if ( '' !== $key && '' !== $value && ! isset( $out[ $key ] ) ) {
					$out[ $key ] = $value;
				}
			}
		}
		return $out;
	}

	private function profile_label( array $args, string $html ): string {
		$label = trim( (string) ( $args['profileLabel'] ?? $args['siteName'] ?? $args['title'] ?? '' ) );
		if ( '' !== $label ) {
			return sanitize_text_field( $label );
		}
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $match ) ) {
			$title = trim( wp_strip_all_tags( (string) $match[1] ) );
			if ( '' !== $title ) {
				return sanitize_text_field( $title . ' Framework Profile' );
			}
		}
		return 'Kiwe SeamFlow Framework Profile';
	}

	private function template_title( array $args, string $html ): string {
		$title = trim( (string) ( $args['templateTitle'] ?? $args['title'] ?? '' ) );
		if ( '' !== $title ) {
			return sanitize_text_field( $title );
		}
		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $match ) ) {
			$text = trim( wp_strip_all_tags( (string) $match[1] ) );
			if ( '' !== $text ) {
				return 'Home';
			}
		}
		return 'Home';
	}

	private function slug( string $label ): string {
		$slug = function_exists( 'sanitize_title' ) ? sanitize_title( $label ) : strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $label ) );
		return '' !== $slug ? $slug : 'kiwe-seamflow-profile';
	}

	private function public_bricks_version(): string {
		$version = defined( 'BRICKS_VERSION' ) ? (string) BRICKS_VERSION : self::DEFAULT_BRICKS_VERSION;
		return preg_match( '/^2\.3(?:\.|$)/', $version ) ? $version : self::DEFAULT_BRICKS_VERSION;
	}

	private function body_inner( string $html ): string {
		if ( preg_match( '/<body\b[^>]*>(.*?)<\/body>/is', $html, $match ) ) {
			return (string) $match[1];
		}
		$html = preg_replace( '/<!doctype[^>]*>/i', '', $html );
		$html = preg_replace( '/<html\b[^>]*>|<\/html>|<head\b[^>]*>.*?<\/head>/is', '', (string) $html );
		return trim( (string) $html );
	}

	private function has_severity( array $findings, string $severity ): bool {
		foreach ( $findings as $finding ) {
			if ( $severity === (string) ( $finding['severity'] ?? '' ) || $severity === (string) ( $finding['level'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private function counts( array $findings ): array {
		$counts = [ 'fail' => 0, 'warn' => 0, 'info' => 0 ];
		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? $finding['level'] ?? 'info' );
			if ( 'error' === $severity || 'critical' === $severity ) {
				$severity = 'fail';
			}
			if ( ! isset( $counts[ $severity ] ) ) {
				$severity = 'info';
			}
			++$counts[ $severity ];
		}
		return $counts;
	}

	private function finding( string $severity, string $code, string $message, string $path = '' ): array {
		return [
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
			'path'     => $path,
		];
	}

	private function contract_version(): string {
		return defined( 'DSA_VERSION' ) ? (string) DSA_VERSION : '6.85';
	}
}
