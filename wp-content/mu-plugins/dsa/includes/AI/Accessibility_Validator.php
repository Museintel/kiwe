<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic accessibility validator for browser-AI handoffs.
 *
 * This intentionally focuses on the launch-critical lane we are hardening now:
 * token-backed light/dark color proof and obvious contrast collisions. It is
 * not a full WCAG engine and does not make font-size or UX claims.
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
				'No native dark-mode proof was found. Provide data-kiwe-theme="dark", [data-kiwe-theme="dark"], data-kiwe-theme-toggle, or a documented Bricks/Kiwe dark-mode bridge.'
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
