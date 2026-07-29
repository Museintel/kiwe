<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic accessibility validator for browser-AI handoffs.
 *
 * This intentionally focuses on the launch-critical lane we are hardening now:
 * token-backed light/dark color proof, obvious contrast collisions, Bricks
 * token-pair evidence, and critical text containment risks. It is not a full
 * WCAG engine and does not make broad font-size preference claims.
 */
final class Accessibility_Validator {
	private const SCHEMA = 'kiwe.accessibility-validation.v1';
	private const MIN_RATIO = 4.5;

	/**
	 * @param array<string,string>|array<int,array{path?:string,content?:string}> $files
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	public function validate_files( array $files, array $options = [] ): array {
		$path_map      = $this->normalize_files( $files );
		$strict_dark   = ! empty( $options['strictDark'] );
		$require_plan  = ! empty( $options['requirePlan'] );
		$findings      = [];
		$combined_text  = implode( "\n", $path_map );
		$plan_path      = $this->find_plan_path( $path_map );
		$has_dark_proof = (bool) preg_match( '/data-kiwe-theme\s*=\s*[\"\']dark[\"\']|data-theme\s*=\s*[\"\']dark[\"\']|\[data-kiwe-theme\s*=\s*[\"\']dark[\"\']\]|data-kiwe-theme-toggle|prefers-color-scheme\s*:\s*dark/i', $combined_text );

		if ( '' === $plan_path ) {
			if ( $require_plan ) {
				$this->add( $findings, 'error', 'accessibility_missing_plan', 'Missing accessibility/kiwe-accessibility-plan.json for /create or /audit /accessibility.' );
			}
		} else {
			$this->review_plan( $path_map[ $plan_path ], $plan_path, $findings );
		}

		if ( ! $has_dark_proof ) {
			$this->add(
				$findings,
				$strict_dark ? 'error' : 'warning',
				'accessibility_missing_dark_mode_proof',
				'No native dark-mode proof was found. Use the Kiwe accessibility-lite dark-mode token remap recipe: data-kiwe-theme="dark", [data-kiwe-theme="dark"], data-kiwe-theme-toggle, or a documented Bricks/Kiwe dark-mode bridge. Do not search repo docs for another contract.'
			);
		}

		if ( ! preg_match( '/--kiwe-(?:color|theme|surface|text|accent|bg|background)|--dsa-(?:color|theme|surface|text)|settings\.tokens|themeStyle/i', $combined_text ) ) {
			$this->add( $findings, 'warning', 'accessibility_missing_kiwe_token_evidence', 'Color work should be grounded in Kiwe/Seam tokens or Bricks theme-style slots, not only private project literals.' );
		}

		if ( ! preg_match( '/colorPrimary|colorSecondary|colorLight|colorDark|colorMuted|siteBackground/i', $combined_text ) ) {
			$this->add( $findings, 'warning', 'accessibility_missing_bricks_theme_style_alignment', 'No Bricks global theme-style color mapping evidence was found. Use Bricks theme-style slots for site-wide light/dark palette handoff when creating Bricks artifacts.' );
		}

		foreach ( $path_map as $path => $content ) {
			if ( ! $this->is_reviewable_path( $path ) ) {
				continue;
			}

			$this->review_css_fragments( $path, $content, $findings );
			$this->review_bricks_json( $path, $content, $findings );
		}

		$counts = [ 'critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0 ];
		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? 'info' );
			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ]++;
			}
		}

		return [
			'ok'       => 0 === $counts['critical'] + $counts['error'],
			'schema'   => self::SCHEMA,
			'counts'   => $counts,
			'findings' => $findings,
			'summary'  => [
				'filesReviewed' => count( $path_map ),
				'planPresent'   => '' !== $plan_path,
				'darkProof'     => $has_dark_proof,
				'minRatio'      => self::MIN_RATIO,
				'scope'         => 'deterministic color-contrast and light/dark token proof only',
			],
		];
	}

	/**
	 * @param array<string,string>|array<int,array{path?:string,content?:string}> $files
	 * @return array<string,string>
	 */
	private function normalize_files( array $files ): array {
		$path_map = [];
		foreach ( $files as $key => $value ) {
			if ( is_array( $value ) ) {
				$path    = sanitize_text_field( (string) ( $value['path'] ?? $key ) );
				$content = (string) ( $value['content'] ?? '' );
			} else {
				$path    = sanitize_text_field( (string) $key );
				$content = (string) $value;
			}

			if ( '' === $path ) {
				continue;
			}
			$path_map[ $path ] = $content;
		}

		return $path_map;
	}

	/**
	 * @param array<string,string> $path_map
	 */
	private function find_plan_path( array $path_map ): string {
		foreach ( $path_map as $path => $content ) {
			if ( preg_match( '#(?:^|[\\\\/])kiwe-accessibility-plan\.json$#i', $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * @param array<int,array<string,mixed>> $findings
	 */
	private function review_plan( string $content, string $path, array &$findings ): void {
		$plan = json_decode( $content, true );
		if ( ! is_array( $plan ) ) {
			$this->add( $findings, 'error', 'accessibility_plan_invalid_json', 'accessibility/kiwe-accessibility-plan.json is not valid JSON.', $path );
			return;
		}

		if ( 'kiwe.accessibility-plan.v1' !== (string) ( $plan['schema'] ?? '' ) ) {
			$this->add( $findings, 'error', 'accessibility_plan_schema_invalid', 'Accessibility plan must use schema kiwe.accessibility-plan.v1.', $path );
		}

		$modes = isset( $plan['modes'] ) && is_array( $plan['modes'] ) ? array_map( 'strtolower', array_map( 'strval', $plan['modes'] ) ) : [];
		if ( ! in_array( 'light', $modes, true ) || ! in_array( 'dark', $modes, true ) ) {
			$this->add( $findings, 'error', 'accessibility_plan_modes_incomplete', 'Accessibility plan must explicitly cover both light and dark modes.', $path );
		}

		$pairs = isset( $plan['tokenPairs'] ) && is_array( $plan['tokenPairs'] ) ? $plan['tokenPairs'] : [];
		if ( [] === $pairs ) {
			$this->add( $findings, 'error', 'accessibility_plan_missing_token_pairs', 'Accessibility plan must include tokenPairs for foreground/background combinations.', $path );
		}

		foreach ( $pairs as $index => $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			if ( empty( $pair['foreground'] ) || empty( $pair['background'] ) ) {
				$this->add( $findings, 'error', 'accessibility_plan_pair_incomplete', 'Each tokenPairs entry needs foreground and background token/value references.', $path, 'tokenPairs[' . $index . ']' );
			}
			if ( isset( $pair['ratio'] ) && is_numeric( $pair['ratio'] ) && (float) $pair['ratio'] < self::MIN_RATIO ) {
				$this->add( $findings, 'error', 'accessibility_plan_ratio_below_minimum', 'Token pair contrast ratio is below 4.5:1.', $path, 'tokenPairs[' . $index . ']' );
			}
		}

		if ( ! isset( $plan['manualReview'] ) || ! is_array( $plan['manualReview'] ) ) {
			$this->add( $findings, 'warning', 'accessibility_plan_missing_manual_review', 'Accessibility plan should include manualReview for gradients, image overlays, transparency, and non-literal token states.', $path );
		}
	}

	private function is_reviewable_path( string $path ): bool {
		return (bool) preg_match( '/\.(?:html|css|json)$/i', $path );
	}

	/**
	 * @param array<int,array<string,mixed>> $findings
	 */
	private function review_css_fragments( string $path, string $content, array &$findings ): void {
		$fragments = [];
		if ( preg_match( '/\.css$/i', $path ) ) {
			$fragments[] = [ 'selector' => 'stylesheet', 'css' => $content ];
		}

		if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $css ) {
				$fragments[] = [ 'selector' => 'style', 'css' => (string) $css ];
			}
		}

		if ( preg_match_all( '/\sstyle\s*=\s*([\"\'])(.*?)\1/is', $content, $matches ) ) {
			foreach ( $matches[2] as $css ) {
				$fragments[] = [ 'selector' => 'inline-style', 'css' => '{' . html_entity_decode( (string) $css, ENT_QUOTES ) . '}' ];
			}
		}

		if ( preg_match( '/\.json$/i', $path ) && preg_match_all( '/(?:color|background|background-color)\s*:\s*[^;"\'}]+/i', $content, $matches ) ) {
			$fragments[] = [ 'selector' => 'json-style-snippets', 'css' => 'x{' . implode( ';', $matches[0] ) . '}' ];
		}

		foreach ( $fragments as $fragment ) {
			$this->review_css( $path, (string) $fragment['css'], (string) $fragment['selector'], $findings );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $findings
	 */
	private function review_css( string $path, string $css, string $fallback_selector, array &$findings ): void {
		if ( preg_match( '/(?:linear|radial|conic)-gradient|url\(/i', $css ) ) {
			$this->add( $findings, 'warning', 'accessibility_gradient_or_image_needs_manual_review', 'Gradient/image backgrounds need a documented manual contrast review for overlaid text.', $path, $fallback_selector );
		}

		if ( preg_match( '/rgba?\([^)]*,\s*(?:0?\.\d+|0)\s*\)|#[0-9a-f]{8}\b/i', $css ) ) {
			$this->add( $findings, 'warning', 'accessibility_alpha_color_needs_manual_review', 'Transparent foreground/background colors need manual contrast review against their composed backdrop.', $path, $fallback_selector );
		}

		if ( ! preg_match_all( '/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER ) ) {
			return;
		}

		foreach ( $matches as $match ) {
			$selector = trim( (string) $match[1] );
			if ( '' === $selector ) {
				$selector = $fallback_selector;
			}
			$declarations = $this->parse_declarations( (string) $match[2] );
			$foregrounds  = $this->declared_colors( $declarations, [ 'color', 'fill' ] );
			$backgrounds  = $this->declared_colors( $declarations, [ 'background', 'background-color' ] );
			$this->review_text_fit_css( $path, $selector, $declarations, (string) $match[2], $findings );

			foreach ( $foregrounds as $fg_raw => $fg ) {
				foreach ( $backgrounds as $bg_raw => $bg ) {
					$ratio = $this->contrast_ratio( $fg, $bg );
					if ( $ratio < self::MIN_RATIO ) {
						$this->add(
							$findings,
							'error',
							'accessibility_low_contrast_literal_pair',
							sprintf( 'Literal color pair contrast is %.2f:1, below 4.5:1 (%s on %s).', $ratio, $fg_raw, $bg_raw ),
							$path,
							$selector
						);
					}
				}
			}
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $findings
	 */
	private function review_bricks_json( string $path, string $content, array &$findings ): void {
		if ( ! preg_match( '/\.json$/i', $path ) ) {
			return;
		}

		$data = json_decode( $content, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		$variables = $this->collect_json_variables( $data );
		$nodes     = [];
		foreach ( [ 'content', 'header', 'footer', 'elements', 'global_classes', 'globalClasses' ] as $lane ) {
			if ( isset( $data[ $lane ] ) && is_array( $data[ $lane ] ) ) {
				$nodes = array_merge( $nodes, $data[ $lane ] );
			}
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
			$selector = $this->describe_json_node( $node );
			$fg       = $this->json_color_path( $settings, [ [ '_typography', 'color' ], [ 'typography', 'color' ], [ 'color' ], [ '_color' ] ] );
			$bg       = $this->json_color_path( $settings, [ [ '_background', 'color' ], [ '_backgroundColor', 'color' ], [ 'background', 'color' ], [ '_background' ], [ 'background-color' ] ] );

			if ( '' !== $fg && '' !== $bg ) {
				$this->review_resolved_json_pair( $path, $selector, $fg, $bg, $variables, $findings );
			} elseif ( '' !== $bg && $this->is_important_text_surface( $node, $settings ) ) {
				$this->add( $findings, 'warning', 'accessibility_missing_explicit_foreground_for_surface', 'Text-bearing/interactive Bricks surface has a background but no explicit readable foreground token. In light/dark mode this can become white-on-white or dark-on-dark.', $path, $selector );
			}

			$this->review_bricks_text_fit( $path, $selector, $node, $settings, $findings );
		}
	}

	/**
	 * @return array<string,string>
	 */
	private function collect_json_variables( array $data ): array {
		$variables = [];
		foreach ( [ 'globalVariables', 'global_variables' ] as $lane ) {
			if ( empty( $data[ $lane ] ) || ! is_array( $data[ $lane ] ) ) {
				continue;
			}
			foreach ( $data[ $lane ] as $variable ) {
				if ( ! is_array( $variable ) || empty( $variable['name'] ) || ! array_key_exists( 'value', $variable ) ) {
					continue;
				}
				$name = strtolower( (string) $variable['name'] );
				$name = str_starts_with( $name, '--' ) ? $name : '--' . $name;
				$variables[ $name ] = (string) $variable['value'];
			}
		}

		return $variables;
	}

	private function describe_json_node( array $node ): string {
		$label = (string) ( $node['label'] ?? $node['name'] ?? $node['id'] ?? 'bricks-node' );
		$id    = ! empty( $node['id'] ) ? '#' . (string) $node['id'] : '';
		return trim( $label . $id );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param array<int,array<int,string>> $paths
	 */
	private function json_color_path( array $settings, array $paths ): string {
		foreach ( $paths as $path ) {
			$value = $settings;
			foreach ( $path as $part ) {
				if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
					$value = null;
					break;
				}
				$value = $value[ $part ];
			}
			$color = $this->normalize_json_color( $value );
			if ( '' !== $color ) {
				return $color;
			}
		}

		return '';
	}

	private function normalize_json_color( mixed $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		foreach ( [ 'raw', 'hex', 'rgb', 'hsl', 'value', 'color' ] as $key ) {
			if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && '' !== trim( $value[ $key ] ) ) {
				return trim( $value[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param array<string,string> $variables
	 * @param array<int,array<string,mixed>> $findings
	 */
	private function review_resolved_json_pair( string $path, string $selector, string $fg, string $bg, array $variables, array &$findings ): void {
		$resolved_fg = $this->resolve_css_vars( $fg, $variables );
		$resolved_bg = $this->resolve_css_vars( $bg, $variables );
		$fg_colors   = $this->colors_in_value( $resolved_fg );
		$bg_colors   = $this->colors_in_value( $resolved_bg );

		if ( [] === $fg_colors || [] === $bg_colors ) {
			if ( preg_match( '/var\(/i', $fg . ' ' . $bg ) && ! preg_match( '/var\(\s*--kiwe-/i', $fg . ' ' . $bg ) ) {
				$this->add( $findings, 'warning', 'accessibility_project_color_pair_needs_token_plan', 'Bricks foreground/background pair uses project variables that could not be resolved to Kiwe token pairs. Map this pair in accessibility/kiwe-accessibility-plan.json.', $path, $selector );
			}
			return;
		}

		foreach ( $fg_colors as $fg_raw => $fg_rgb ) {
			foreach ( $bg_colors as $bg_raw => $bg_rgb ) {
				$ratio = $this->contrast_ratio( $fg_rgb, $bg_rgb );
				if ( $ratio < self::MIN_RATIO ) {
					$this->add( $findings, 'error', 'accessibility_low_contrast_bricks_pair', sprintf( 'Bricks foreground/background contrast is %.2f:1, below 4.5:1 (%s on %s).', $ratio, $fg_raw, $bg_raw ), $path, $selector );
				}
			}
		}
	}

	/**
	 * @param array<string,string> $variables
	 */
	private function resolve_css_vars( string $value, array $variables, int $depth = 0 ): string {
		if ( $depth > 8 || '' === trim( $value ) ) {
			return $value;
		}

		$resolved = preg_replace_callback(
			'/var\(\s*(--[a-z0-9_-]+)\s*(?:,\s*([^)]+))?\)/i',
			function ( array $matches ) use ( $variables, $depth ): string {
				$name = strtolower( (string) $matches[1] );
				if ( isset( $variables[ $name ] ) ) {
					return $this->resolve_css_vars( $variables[ $name ], $variables, $depth + 1 );
				}
				return isset( $matches[2] ) ? $this->resolve_css_vars( (string) $matches[2], $variables, $depth + 1 ) : (string) $matches[0];
			},
			$value
		);

		return is_string( $resolved ) && $resolved !== $value ? $this->resolve_css_vars( $resolved, $variables, $depth + 1 ) : $value;
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array<string,mixed> $settings
	 */
	private function is_important_text_surface( array $node, array $settings ): bool {
		$context = strtolower( wp_json_encode( [ $node['name'] ?? '', $node['label'] ?? '', $settings['_cssClasses'] ?? '', $settings['_attributes'] ?? [] ] ) ?: '' );
		return (bool) preg_match( '/(?:title|heading|headline|label|badge|chip|pill|button|btn|cta|tab|link|price|amount|stat)/i', $context );
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array<string,mixed> $settings
	 * @param array<int,array<string,mixed>> $findings
	 */
	private function review_bricks_text_fit( string $path, string $selector, array $node, array $settings, array &$findings ): void {
		$context = strtolower( wp_json_encode( [ $node['name'] ?? '', $node['label'] ?? '', $settings['_cssClasses'] ?? '', $settings['_attributes'] ?? [], $settings['_cssCustom'] ?? '' ] ) ?: '' );
		if ( ! preg_match( '/(?:title|heading|headline|eyebrow|label|badge|chip|pill|button|btn|cta|tab|link|text|copy|summary|excerpt|description|price|amount|stat|caption|card)/i', $context ) ) {
			return;
		}

		$overflow       = strtolower( (string) ( $settings['_overflow'] ?? $settings['overflow'] ?? '' ) );
		$custom         = (string) ( $settings['_cssCustom'] ?? '' );
		$has_clip       = preg_match( '/(?:hidden|clip)/i', $overflow ) || preg_match( '/overflow\s*:\s*(?:hidden|clip)/i', $custom );
		$has_strict_box = isset( $settings['_height'] ) || isset( $settings['_heightMax'] ) || isset( $settings['_maxHeight'] ) || isset( $settings['_gridTemplateRows'] );
		$has_nowrap     = preg_match( '/nowrap/i', $custom );
		$has_ellipsis   = preg_match( '/text-overflow\s*:\s*(?:ellipsis|clip)|line-clamp\s*:\s*[1-9]|-webkit-line-clamp\s*:\s*[1-9]/i', $custom );

		if ( $has_clip && ( $has_strict_box || $has_nowrap || $has_ellipsis ) ) {
			$severity = $this->is_important_text_surface( $node, $settings ) ? 'error' : 'warning';
			$this->add( $findings, $severity, 'accessibility_bricks_text_clipping_risk', 'Bricks text-bearing UI is clipped/nowrap/ellipsized inside a constrained box. Fix with wrapping, fluid Geometry/Seam tokens, safer min-block sizing, or accessible full text before shipping.', $path, $selector );
		}
	}

	/**
	 * @param array<string,string> $declarations
	 * @param array<int,array<string,mixed>> $findings
	 */
	private function review_text_fit_css( string $path, string $selector, array $declarations, string $body, array &$findings ): void {
		$context = strtolower( $selector . "\n" . $body );
		if ( ! preg_match( '/(?:title|heading|headline|eyebrow|label|badge|chip|pill|button|btn|cta|tab|link|text|copy|summary|excerpt|description|price|amount|stat|caption|card)/i', $context ) ) {
			return;
		}

		$overflow       = (string) ( $declarations['overflow'] ?? $declarations['overflow-x'] ?? $declarations['overflow-y'] ?? '' );
		$has_clip       = preg_match( '/(?:hidden|clip)/i', $overflow );
		$has_strict_box = isset( $declarations['height'] ) || isset( $declarations['max-height'] ) || isset( $declarations['grid-template-rows'] );
		$has_nowrap     = isset( $declarations['white-space'] ) && preg_match( '/nowrap/i', (string) $declarations['white-space'] );
		$has_ellipsis   = preg_match( '/text-overflow\s*:\s*(?:ellipsis|clip)|line-clamp\s*:\s*[1-9]|-webkit-line-clamp\s*:\s*[1-9]/i', $body );
		$critical       = preg_match( '/(?:title|heading|headline|label|badge|chip|pill|button|btn|cta|tab|link|price|amount|stat)/i', $context );

		if ( $has_clip && ( $has_strict_box || $has_nowrap || $has_ellipsis ) ) {
			$this->add( $findings, $critical ? 'error' : 'warning', 'accessibility_text_clipping_risk', 'Text-bearing UI uses clipping/nowrap/line-clamp inside a constrained box. Kiwe/Seam accessibility requires titles, labels, pills, chips, buttons, tabs, prices, and stats to remain readable across responsive states.', $path, $selector );
		}
	}

	/**
	 * @return array<string,string>
	 */
	private function parse_declarations( string $body ): array {
		$declarations = [];
		foreach ( explode( ';', $body ) as $part ) {
			if ( ! str_contains( $part, ':' ) ) {
				continue;
			}
			[ $property, $value ] = array_map( 'trim', explode( ':', $part, 2 ) );
			$property = strtolower( $property );
			if ( '' !== $property && '' !== $value ) {
				$declarations[ $property ] = $value;
			}
		}

		return $declarations;
	}

	/**
	 * @param array<string,string> $declarations
	 * @param array<int,string> $properties
	 * @return array<string,array{r:float,g:float,b:float}>
	 */
	private function declared_colors( array $declarations, array $properties ): array {
		$colors = [];
		foreach ( $properties as $property ) {
			if ( ! isset( $declarations[ $property ] ) ) {
				continue;
			}
			foreach ( $this->colors_in_value( $declarations[ $property ] ) as $raw => $rgb ) {
				$colors[ $raw ] = $rgb;
			}
		}

		return $colors;
	}

	/**
	 * @return array<string,array{r:float,g:float,b:float}>
	 */
	private function colors_in_value( string $value ): array {
		if ( preg_match( '/\b(?:var|color-mix|oklch|lab|lch|hsl|hsla)\s*\(/i', $value ) ) {
			return [];
		}

		$colors = [];
		if ( preg_match_all( '/#[0-9a-f]{3,8}\b/i', $value, $matches ) ) {
			foreach ( $matches[0] as $raw ) {
				$rgb = $this->parse_hex_color( (string) $raw );
				if ( null !== $rgb ) {
					$colors[ strtolower( (string) $raw ) ] = $rgb;
				}
			}
		}

		if ( preg_match_all( '/rgba?\(([^)]+)\)/i', $value, $matches ) ) {
			foreach ( $matches[1] as $index => $body ) {
				$rgb = $this->parse_rgb_color( (string) $body );
				if ( null !== $rgb ) {
					$colors[ strtolower( $matches[0][ $index ] ) ] = $rgb;
				}
			}
		}

		foreach ( [ 'white' => '#ffffff', 'black' => '#000000', 'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff' ] as $name => $hex ) {
			if ( preg_match( '/(^|[^a-z-])' . preg_quote( $name, '/' ) . '([^a-z-]|$)/i', $value ) ) {
				$rgb = $this->parse_hex_color( $hex );
				if ( null !== $rgb ) {
					$colors[ $name ] = $rgb;
				}
			}
		}

		return $colors;
	}

	/**
	 * @return array{r:float,g:float,b:float}|null
	 */
	private function parse_hex_color( string $raw ): ?array {
		$hex = ltrim( strtolower( trim( $raw ) ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 8 === strlen( $hex ) ) {
			$hex = substr( $hex, 0, 6 );
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return null;
		}

		return [
			'r' => hexdec( substr( $hex, 0, 2 ) ),
			'g' => hexdec( substr( $hex, 2, 2 ) ),
			'b' => hexdec( substr( $hex, 4, 2 ) ),
		];
	}

	/**
	 * @return array{r:float,g:float,b:float}|null
	 */
	private function parse_rgb_color( string $body ): ?array {
		$parts = array_map( 'trim', explode( ',', $body ) );
		if ( count( $parts ) < 3 ) {
			return null;
		}

		return [
			'r' => max( 0, min( 255, (float) $parts[0] ) ),
			'g' => max( 0, min( 255, (float) $parts[1] ) ),
			'b' => max( 0, min( 255, (float) $parts[2] ) ),
		];
	}

	/**
	 * @param array{r:float,g:float,b:float} $a
	 * @param array{r:float,g:float,b:float} $b
	 */
	private function contrast_ratio( array $a, array $b ): float {
		$l1 = $this->relative_luminance( $a );
		$l2 = $this->relative_luminance( $b );
		$hi = max( $l1, $l2 );
		$lo = min( $l1, $l2 );

		return ( $hi + 0.05 ) / ( $lo + 0.05 );
	}

	/**
	 * @param array{r:float,g:float,b:float} $rgb
	 */
	private function relative_luminance( array $rgb ): float {
		$channels = [];
		foreach ( [ 'r', 'g', 'b' ] as $channel ) {
			$value       = $rgb[ $channel ] / 255;
			$channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * @param array<int,array<string,mixed>> $findings
	 */
	private function add( array &$findings, string $severity, string $code, string $message, string $path = '', string $selector = '' ): void {
		$finding = [
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
		];
		if ( '' !== $path ) {
			$finding['path'] = sanitize_text_field( $path );
		}
		if ( '' !== $selector ) {
			$finding['selector'] = sanitize_text_field( $selector );
		}
		$findings[] = $finding;
	}
}
