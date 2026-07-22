<?php

namespace DSA\Site_Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only selector for Kiwe Site Graph payloads.
 *
 * This is intentionally AI-agnostic. AI tools, admin screens, Bricks helpers,
 * audits, and future headless connectors can all ask the graph for just the
 * branches they need without downloading or parsing the whole graph.
 */
final class Query_Service {
	private const MAX_SELECTORS = 40;
	private const MAX_DEPTH = 8;

	public function query( array $graph, array $args = [] ): array {
		$selectors = $this->selectors( $args['select'] ?? ( $args['path'] ?? [] ) );

		if ( empty( $selectors ) ) {
			$selectors = [ '*' ];
		}

		$result = [];
		$meta   = [
			'schema'      => 'kiwe.site-graph.query.v1',
			'graphSchema' => sanitize_text_field( (string) ( $graph['schema'] ?? 'kiwe.site-graph.v1' ) ),
			'generatedAt' => gmdate( 'c' ),
			'selectors'   => $selectors,
			'count'       => 0,
			'missing'     => [],
		];

		foreach ( $selectors as $selector ) {
			if ( '*' === $selector ) {
				$result = $graph;
				$meta['count'] = count( $result );
				continue;
			}

			$value = $this->get_path( $graph, $selector );
			if ( null === $value ) {
				$meta['missing'][] = $selector;
				continue;
			}

			$this->set_path( $result, $selector, $value );
			$meta['count']++;
		}

		return [
			'meta' => $meta,
			'data' => $result,
		];
	}

	/**
	 * @param mixed $raw
	 * @return string[]
	 */
	private function selectors( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( array_slice( $raw, 0, self::MAX_SELECTORS ) as $selector ) {
			$selector = $this->sanitize_selector( (string) $selector );
			if ( '' !== $selector && ! in_array( $selector, $out, true ) ) {
				$out[] = $selector;
			}
		}

		return $out;
	}

	private function sanitize_selector( string $selector ): string {
		$selector = trim( $selector );
		if ( '*' === $selector ) {
			return '*';
		}

		$parts = array_filter(
			array_slice( explode( '.', $selector ), 0, self::MAX_DEPTH ),
			static fn( string $part ): bool => '' !== $part
		);

		$parts = array_map(
			static function ( string $part ): string {
				return preg_replace( '/[^A-Za-z0-9_:-]/', '', $part ) ?? '';
			},
			$parts
		);

		$parts = array_values( array_filter( $parts, static fn( string $part ): bool => '' !== $part ) );

		return implode( '.', $parts );
	}

	/**
	 * @return mixed|null
	 */
	private function get_path( array $source, string $selector ) {
		$current = $source;

		foreach ( explode( '.', $selector ) as $part ) {
			if ( is_array( $current ) && array_key_exists( $part, $current ) ) {
				$current = $current[ $part ];
				continue;
			}

			if ( is_array( $current ) && ctype_digit( $part ) ) {
				$index = (int) $part;
				if ( array_key_exists( $index, $current ) ) {
					$current = $current[ $index ];
					continue;
				}
			}

			return null;
		}

		return $current;
	}

	/**
	 * @param mixed $value
	 */
	private function set_path( array &$target, string $selector, $value ): void {
		$parts = explode( '.', $selector );
		$cursor =& $target;

		foreach ( $parts as $index => $part ) {
			if ( count( $parts ) - 1 === $index ) {
				$cursor[ $part ] = $value;
				return;
			}

			if ( ! isset( $cursor[ $part ] ) || ! is_array( $cursor[ $part ] ) ) {
				$cursor[ $part ] = [];
			}

			$cursor =& $cursor[ $part ];
		}
	}
}
