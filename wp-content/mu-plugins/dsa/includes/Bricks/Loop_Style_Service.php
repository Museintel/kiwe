<?php

namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reuse invariant loop styles without changing the native content query or its history. */
final class Loop_Style_Service {

	private const ELEMENTS = [ 'div', 'section', 'container', 'block', 'text-basic', 'heading', 'text-link', 'button', 'image', 'icon', 'product-stock' ];
	private array $active = [];

	public function register(): void {
		add_action( 'bricks/query/before_loop', [ $this, 'before_loop' ], PHP_INT_MAX, 2 );
		add_action( 'bricks/query/after_loop', [ $this, 'after_loop' ], PHP_INT_MIN, 1 );
	}

	public function before_loop( $query, $args ): void {
		if ( ! defined( 'BRICKS_VERSION' ) || version_compare( BRICKS_VERSION, '2.3.4', '<' ) || version_compare( BRICKS_VERSION, '2.5', '>=' )
			|| is_admin() || ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() )
			|| ! is_object( $query ) || ! is_array( $args ) || count( $args ) !== 2 || ! is_array( $args[0] ?? null )
			|| ! in_array( $args[1] ?? null, [ 'content', 'header', 'footer', 'popup' ], true ) ) { return; }

		// Bricks passes the same hook to HTML rendering. Only its exact native CSS callback is admitted.
		$css_render = false;
		foreach ( debug_backtrace( 0, 9 ) as $frame ) {
			if ( 'Bricks\Query' === ( $frame['class'] ?? '' ) && 'render' === ( $frame['function'] ?? '' ) && is_string( $frame['args'][0] ?? null )
				&& 'bricks\assets::generate_css_from_element' === strtolower( ltrim( $frame['args'][0], '\\' ) ) ) { $css_render = true; break; }
		}
		if ( ! $css_render ) { return; }
		$result = $query->query_result ?? null;
		if ( ! $result instanceof \WP_Query || $result === ( $GLOBALS['wp_query'] ?? null ) || $result->post_count < 2
			|| -1 !== $result->current_post || ! is_array( $result->posts ) || count( $result->posts ) !== $result->post_count
			|| isset( $this->active[ spl_object_id( $query ) ] ) || ! $this->invariant_tree( $args[0] ) ) { return; }
		if ( ! apply_filters( 'kiwe/bricks/reuse_static_loop_styles', true, $query, $args[0] ) ) { return; }

		// Query::render already holds this WP_Query by reference. Do not slice posts, alter count,
		// rerun the query or replace its history. The native first iteration sets up its usual context.
		$this->active[ spl_object_id( $query ) ] = [ $result, $result->post_count ];
		$result->post_count = 1;
	}

	public function after_loop( $query ): void {
		if ( ! is_object( $query ) ) { return; }
		$id = spl_object_id( $query );
		if ( ! isset( $this->active[ $id ] ) ) { return; }
		[ $result, $count ] = $this->active[ $id ];
		$result->post_count = $count;
		unset( $this->active[ $id ] );
	}

	/** A conservative subset: unresolved references and extensions keep Bricks' complete CSS loop. */
	public function invariant_tree( array $root ): bool {
		if ( ! class_exists( '\Bricks\Assets', false ) || ! class_exists( '\Bricks\Database', false ) ) { return false; }
		$pending = [ $root ]; $seen = []; $class_ids = [];
		while ( $pending ) {
			$element = array_pop( $pending ); $id = $element['id'] ?? '';
			if ( ! is_string( $id ) || '' === $id || isset( $seen[ $id ] ) || count( $seen ) >= 256
				|| ! in_array( $element['name'] ?? '', self::ELEMENTS, true ) || ! empty( $element['cid'] )
				|| ! empty( $element['parentComponent'] ) || ! empty( $element['slotChildren'] ) || ! empty( $element['instanceId'] ) ) { return false; }
			$seen[ $id ] = true;
			$settings = $element['settings'] ?? [];
			if ( ! is_array( $settings ) || ( $element !== $root && array_key_exists( 'hasLoop', $settings ) ) ) { return false; }
			foreach ( $settings as $key => $value ) {
				// Native content/attributes stay record-specific. Everything else, including unknown
				// element-specific style controls, must have no dynamic tags or dynamic media.
				if ( in_array( $key, [ 'text', 'title', 'link', '_attributes', '_conditions', 'query', 'hasLoop' ], true ) ) { continue; }
				if ( in_array( $key, [ 'image', 'altText', 'caption' ], true ) && 'image' === $element['name'] ) { continue; }
				if ( $this->dynamic_value( $value ) ) { return false; }
			}
			$ids = $settings['_cssGlobalClasses'] ?? [];
			if ( is_string( $ids ) ) { $ids = preg_split( '/\s+/', trim( $ids ), -1, PREG_SPLIT_NO_EMPTY ); }
			if ( ! is_array( $ids ) ) { return false; }
			foreach ( $ids as $class_id ) { if ( ! is_string( $class_id ) ) { return false; } $class_ids[ $class_id ] = true; }
			$children = $element['children'] ?? [];
			if ( ! is_array( $children ) ) { return false; }
			foreach ( $children as $child ) {
				if ( ! is_string( $child ) || ! isset( \Bricks\Assets::$elements[ $child ] ) || ! is_array( \Bricks\Assets::$elements[ $child ] ) ) { return false; }
				$pending[] = \Bricks\Assets::$elements[ $child ];
			}
		}
		// Read current native class settings on every decision; no cross-request or stale authoring cache.
		$classes = \Bricks\Database::$global_data['globalClasses'] ?? [];
		if ( ! is_array( $classes ) || count( $classes ) > 5000 ) { return false; }
		foreach ( $classes as $class ) {
			if ( ! is_array( $class ) || ! isset( $class_ids[ $class['id'] ?? '' ] ) ) { continue; }
			if ( $this->dynamic_value( $class['settings'] ?? [] ) ) { return false; }
			unset( $class_ids[ $class['id'] ] );
		}
		return ! $class_ids;
	}

	private function dynamic_value( $value, int $depth = 0 ): bool {
		if ( $depth > 20 || is_object( $value ) || is_resource( $value ) ) { return true; }
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( 'useDynamicData' === $key && ! empty( $item ) ) { return true; }
				if ( $this->dynamic_value( $item, $depth + 1 ) ) { return true; }
			}
			return false;
		}
		// Braces containing a tag (including arguments/fallbacks) are dynamic. CSS blocks with
		// declarations may conservatively opt out; the native fallback is always correct.
		if ( ! is_string( $value ) || ! str_contains( $value, '{' ) ) { return false; }
		if ( strlen( $value ) > 131072 ) { return true; }
		// Native tags accept hyphenated field names and whitespace arguments. Quoted
		// fallback values may contain semicolons/braces; ordinary CSS declarations do not
		// become tags. Possessive runs keep failed matches bounded; parse errors decline reuse.
		$pattern = <<<'REGEX'
~\{\s*[^\s{};:'"@][^\s:{};'"@]*(?:(?::|\s+)(?:[^{};'"]++|"(?:\\.|[^"\\])*+"|'(?:\\.|[^'\\])*+')*+)?\s*\}~
REGEX;
		$match = preg_match( $pattern, $value );
		return 1 === $match || false === $match;
	}
}
