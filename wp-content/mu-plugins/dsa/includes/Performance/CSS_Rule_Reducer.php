<?php

namespace DSA\Performance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Lossless removal of redundant flat rules; never a selector-use or minification pass. */
final class CSS_Rule_Reducer {
	private const MAX_BYTES = 4194304;
	private const MAX_TOKENS = 300000;
	private const MAX_RULES = 20000;
	private const MAX_DEPTH = 64;
	private const MAX_RULE_BYTES = 262144;
	private const TOKEN = <<<'REGEX'
~"(?:\\[\s\S]|[^"\\\r\n]++)*+"|'(?:\\[\s\S]|[^'\\\r\n]++)*+'|/\*[^*]*+(?:\*(?!/)[^*]*+)*+\*/|\\[\s\S]|[{}();\[\]"'/]~
REGEX;

	public static function reduce( string $css ): string {
		$plan = self::removals( $css );
		if ( ! $plan ) { return $css; }
		$parts = []; $cursor = 0;
		foreach ( $plan as $start => $length ) {
			$parts[] = substr( $css, $cursor, $start - $cursor );
			$cursor = $start + $length;
		}
		$parts[] = substr( $css, $cursor );
		return implode( '', $parts );
	}

	/** Byte offsets to remove, or null on ambiguous/unbounded input. All other bytes survive. */
	public static function removals( string $css ): ?array {
		$size = strlen( $css );
		if ( $size > self::MAX_BYTES || str_contains( $css, '<!--' ) || str_contains( $css, '-->' ) ) { return null; }
		$stack = [ [ 'kind' => 'group', 'enabled' => true, 'head' => 0, 'seen' => [] ] ];
		$delimiters = []; $drops = []; $offset = 0; $tokens = 0; $rules = 0;
		while ( $offset < $size ) {
			$found = preg_match( self::TOKEN, $css, $match, PREG_OFFSET_CAPTURE, $offset );
			if ( false === $found ) { return null; }
			if ( 0 === $found ) { break; }
			if ( ++$tokens > self::MAX_TOKENS ) { return null; }
			[ $token, $at ] = $match[0];
			$offset = $at + strlen( $token );
			$i = count( $stack ) - 1;
			if ( str_starts_with( $token, '/*' ) ) {
				// Keep leading comments outside rule spans, including source-map and author comments.
				if ( '' === trim( substr( $css, $stack[ $i ]['head'], $at - $stack[ $i ]['head'] ) ) ) { $stack[ $i ]['head'] = $offset; }
				continue;
			}
			if ( '\\' === $token[0] ) { continue; }
			if ( '"' === $token[0] || "'" === $token[0] ) {
				if ( strlen( $token ) < 2 ) { return null; }
				continue;
			}
			if ( '/' === $token ) {
				if ( '*' === ( $css[ $at + 1 ] ?? '' ) ) { return null; }
				continue;
			}
			if ( '(' === $token || '[' === $token ) {
				$delimiters[] = $token;
				if ( count( $delimiters ) > self::MAX_DEPTH ) { return null; }
				continue;
			}
			if ( ')' === $token || ']' === $token ) {
				if ( array_pop( $delimiters ) !== ( ')' === $token ? '(' : '[' ) ) { return null; }
				continue;
			}
			if ( $delimiters ) { continue; }
			if ( ';' === $token ) {
				$statement = substr( $css, $stack[ $i ]['head'], $at - $stack[ $i ]['head'] );
				if ( 'rule' === $stack[ $i ]['kind'] ) {
					if ( ! self::declaration( $statement ) ) { $stack[ $i ]['flat'] = false; }
				} elseif ( 'group' === $stack[ $i ]['kind'] ) {
					// Imports/namespaces have ordering side effects. Never prune across them.
					$stack[ $i ]['seen'] = [];
				}
				$stack[ $i ]['head'] = $offset;
				continue;
			}
			if ( '{' === $token ) {
				if ( count( $stack ) >= self::MAX_DEPTH || $at - $stack[ $i ]['head'] > 16384 ) { return null; }
				if ( 'rule' === $stack[ $i ]['kind'] ) { $stack[ $i ]['flat'] = false; }
				$start = $stack[ $i ]['head'];
				$start += strspn( $css, " \t\r\n\f", $start, $at - $start );
				$head = substr( $css, $start, $at - $start );
				$at_rule = str_starts_with( $head, '@' );
				$group = $at_rule && 1 === preg_match( '/^@(media|supports)\b/i', $head );
				$enabled = 'group' === $stack[ $i ]['kind'] && $stack[ $i ]['enabled'];
				if ( $at_rule && ! $group ) { $stack[ $i ]['seen'] = []; }
				$stack[] = [
					'kind' => $at_rule ? 'group' : 'rule',
					'enabled' => $enabled && ( ! $at_rule || $group ),
					'head' => $offset, 'start' => $start,
					'flat' => '' !== $head, 'seen' => [],
				];
				continue;
			}
			if ( '}' === $token ) {
				if ( count( $stack ) < 2 ) { return null; }
				$frame = array_pop( $stack );
				$i = count( $stack ) - 1;
				if ( 'rule' === $frame['kind'] && $frame['enabled'] && $frame['flat']
					&& self::declaration( substr( $css, $frame['head'], $at - $frame['head'] ) ) ) {
					if ( ++$rules > self::MAX_RULES ) { return null; }
					$length = $offset - $frame['start'];
					if ( $length <= self::MAX_RULE_BYTES ) {
						$text = substr( $css, $frame['start'], $length );
						$key = hash( 'sha256', $text );
						$previous = $stack[ $i ]['seen'][ $key ] ?? null;
						if ( $previous && $previous[1] === $length && 0 === substr_compare( $css, $text, $previous[0], $length ) ) {
							$drops[ $previous[0] ] = $previous[1];
						}
						$stack[ $i ]['seen'][ $key ] = [ $frame['start'], $length ];
					}
				}
				$stack[ $i ]['head'] = $offset;
			}
		}
		if ( count( $stack ) !== 1 || $delimiters || str_ends_with( $css, '\\' )
			|| '' !== trim( substr( $css, $stack[0]['head'] ) ) ) { return null; }
		ksort( $drops, SORT_NUMERIC );
		return $drops;
	}

	private static function declaration( string $statement ): bool {
		$statement = trim( $statement );
		if ( '' === $statement ) { return true; }
		// Unknown escaped/hacked properties and nested at-rules keep their complete rule.
		return 1 === preg_match( '/^(?:--[-_a-zA-Z0-9\x80-\xff]+|-?[_a-zA-Z\x80-\xff][-_a-zA-Z0-9\x80-\xff]*)\s*:/', $statement );
	}
}
