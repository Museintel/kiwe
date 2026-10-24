<?php
namespace DSA\Bricks;

/** Source-runtime admission and narrow style repair; native code authorization stays authoritative. */
final class Source_Runtime_Bridge {
	public const VERSION = '1';

	/** Import metadata cannot disguise a shared runtime as a portable template. */
	public static function dependency_error( array $template ): ?string {
		$declared = $template['generator']['runtimeDependencies']['sourceBridge'] ?? null;
		$has_declaration = is_array( $template['generator']['runtimeDependencies'] ?? null ) && array_key_exists( 'sourceBridge', $template['generator']['runtimeDependencies'] );
		$bridges = []; $cached = false;
		foreach ( [ 'content', 'header', 'footer' ] as $area ) foreach ( is_array( $template[ $area ] ?? null ) ? $template[ $area ] : [] as $node ) {
			$html = is_array( $node ) && 'html' === ( $node['name'] ?? '' ) ? ( $node['settings']['html'] ?? null ) : null;
			if ( ! is_string( $html ) || ! preg_match( '/<script\b[^>]*\bdata-brx-source-runtime-bundle\s*=/', $html ) ) continue;
			$bridges[] = $html;
			$cached = $cached || (bool) preg_match( '/<script\b[^>]*\bdata-seam-runtime\s*=/', $html );
		}
		if ( ! $has_declaration ) return $cached ? 'Cached source JavaScript requires an explicit Kiwe runtime declaration. Recompile for this destination or export a portable embedded runtime.' : null;
		if ( ! is_array( $declared ) ) return 'The source-runtime declaration is invalid.';
		$keys = array_keys( $declared ); sort( $keys, SORT_STRING );
		if ( [ 'delivery', 'requiresKiwe', 'version' ] !== $keys || self::VERSION !== ( $declared['version'] ?? null ) || ! in_array( $declared['delivery'] ?? null, [ 'embedded', 'kiwe-cached' ], true ) || ( $declared['requiresKiwe'] ?? null ) !== ( 'kiwe-cached' === $declared['delivery'] ) ) return 'The source-runtime version or delivery declaration is unsupported.';
		if ( 1 !== count( $bridges ) || $cached !== $declared['requiresKiwe'] ) return 'The source-runtime declaration does not match its template markup.';
		$html = $bridges[0];
		if ( strlen( $html ) > 2097152 || 1 !== preg_match_all( '/<script\b/i', $html ) || ! preg_match( '/^<script data-brx-source-runtime-bundle="[\w-]{1,80}"' . ( $cached ? ' data-seam-runtime="' . self::VERSION . '"' : '' ) . '>/', $html ) ) return 'The declared source runtime is not a supported compiler bridge.';
		return null;
	}

	public static function observe( string $html ): void {
		if ( str_contains( $html, 'data-seam-runtime="1"' ) && str_contains( $html, 'data-brx-source-runtime-bundle=' ) ) {
			wp_enqueue_script( 'kiwe-source-runtime', DSA_URL . 'assets/js/source-runtime.js', [], DSA_VERSION, true );
		}
	}

	/** Only HTML-encoded CSS nesting anchors, outside strings/attributes/comments. */
	public static function normalize_selector( string $selector ): string {
		$output = ''; $quote = ''; $brackets = 0; $length = strlen( $selector );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $selector[ $i ];
			if ( '\\' === $char ) { $output .= substr( $selector, $i, 2 ); $i++; continue; }
			if ( '' !== $quote ) { $output .= $char; if ( $char === $quote ) $quote = ''; continue; }
			if ( '"' === $char || "'" === $char ) { $quote = $char; $output .= $char; continue; }
			if ( '/*' === substr( $selector, $i, 2 ) ) { $end = strpos( $selector, '*/', $i + 2 ); $until = false === $end ? $length : $end + 2; $output .= substr( $selector, $i, $until - $i ); $i = $until - 1; continue; }
			if ( '[' === $char ) $brackets++;
			if ( ']' === $char ) $brackets = max( 0, $brackets - 1 );
			if ( ! $brackets && '&gt;' === substr( $selector, $i, 4 ) ) { $output .= '>'; $i += 3; continue; }
			if ( ! $brackets && '&amp;' === substr( $selector, $i, 5 ) && ( $i + 5 === $length || preg_match( '/[\s.,:#\[)>+~&]/', $selector[ $i + 5 ] ) ) ) { $output .= '&'; $i += 4; }
			else $output .= $char;
		}
		return $output;
	}

	/** Decode rule-selector syntax only; never CSS declaration or at-rule data. */
	public static function normalize_custom_css( string $css ): string {
		$output = ''; $start = 0; $quote = ''; $parentheses = 0; $brackets = 0; $custom_blocks = []; $length = strlen( $css );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];
			if ( '\\' === $char ) { $i++; continue; }
			if ( '' !== $quote ) { if ( $char === $quote ) $quote = ''; continue; }
			if ( '"' === $char || "'" === $char ) { $quote = $char; continue; }
			if ( '/*' === substr( $css, $i, 2 ) ) { $end = strpos( $css, '*/', $i + 2 ); $i = false === $end ? $length : $end + 1; continue; }
			if ( '&' === $char && preg_match( '/^&(?:amp|gt);/', substr( $css, $i, 5 ), $entity ) ) { $i += strlen( $entity[0] ) - 1; continue; }
			if ( '(' === $char ) $parentheses++;
			if ( ')' === $char ) $parentheses = max( 0, $parentheses - 1 );
			if ( '[' === $char ) $brackets++;
			if ( ']' === $char ) $brackets = max( 0, $brackets - 1 );
			if ( $parentheses || $brackets ) continue;
			if ( '{' === $char ) {
				$prelude = substr( $css, $start, $i - $start ); $probe = trim( preg_replace( '~/\*[\s\S]*?\*/~', '', $prelude ) );
				$custom = true === end( $custom_blocks ) || str_starts_with( $probe, '--' );
				$output .= ( $custom || str_starts_with( $probe, '@' ) ? $prelude : self::normalize_selector( $prelude ) ) . $char;
				$custom_blocks[] = $custom; $start = $i + 1;
			} elseif ( ';' === $char || '}' === $char ) {
				$output .= substr( $css, $start, $i - $start + 1 ); $start = $i + 1;
				if ( '}' === $char ) array_pop( $custom_blocks );
			}
		}
		return $output . substr( $css, $start );
	}

	private static function normalize_settings( array $settings ): array {
		foreach ( $settings as $key => $value ) if ( preg_match( '/^_cssCustom(?::[a-z0-9_-]+)?$/', (string) $key ) && is_string( $value ) && strlen( $value ) <= 2097152 ) $settings[ $key ] = self::normalize_custom_css( $value );
		return $settings;
	}

	public static function normalize_elements( array $elements ): array {
		foreach ( $elements as &$element ) {
			if ( is_array( $element['settings'] ?? null ) ) $element['settings'] = self::normalize_settings( $element['settings'] );
			if ( ! is_array( $element['selectors'] ?? null ) ) continue;
			foreach ( $element['selectors'] as &$selector ) {
				if ( is_string( $selector['selector'] ?? null ) && strlen( $selector['selector'] ) <= 4096 ) $selector['selector'] = self::normalize_selector( $selector['selector'] );
				if ( is_array( $selector['settings'] ?? null ) ) $selector['settings'] = self::normalize_settings( $selector['settings'] );
			}
			unset( $selector );
		}
		unset( $element ); return $elements;
	}
}
