<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts trusted staging HTML/CSS handoffs into Bricks element JSON.
 *
 * Bricks 2.4+ ships a native server-side converter. Kiwe prefers that when it is
 * present. Older Bricks installs still need an AI-safe path that preserves Seam
 * classes, data attributes, ARIA, links, and page CSS without using the browser
 * clipboard route.
 */
final class Bricks_Html_Css_Converter_Service {
	private const MAX_HTML_BYTES = 450000;
	private const MAX_CSS_BYTES  = 180000;
	private const MAX_ELEMENTS   = 2200;

	private int $counter = 0;
	private array $elements = [];
	private array $warnings = [];
	private array $query_templates = [];

	public function available(): array {
		return [
			'native'    => $this->native_available(),
			'fallback'  => true,
			'preferred' => $this->native_available() ? 'bricks-native' : 'kiwe-fallback',
		];
	}

	public function convert( string $html, string $css = '', array $options = [] ): array {
		$this->query_templates = $this->query_templates_from_options( $options );
		$style_css = '';
		$html = preg_replace_callback(
			'/<style\b[^>]*>(.*?)<\/style>/is',
			function ( array $match ) use ( &$style_css ): string {
				$style_css .= "\n" . (string) ( $match[1] ?? '' );
				return '';
			},
			$html
		);
		$html = is_string( $html ) ? $html : '';
		$html = $this->normalize_html( $html );
		$css  = $this->sanitize_css( $style_css . "\n" . $css );

		if ( '' === trim( $html ) ) {
			return [
				'success'  => false,
				'converter' => 'none',
				'elements' => [],
				'warnings' => [ 'No HTML was provided for Bricks conversion.' ],
				'errors'   => [ 'missing_html' ],
			];
		}

		if ( $this->native_available() ) {
			$native = $this->native_convert( $html, $css, $options );
			if ( ! empty( $native['success'] ) && ! empty( $native['elements'] ) ) {
				$native['pageSettings'] = $this->page_settings( $css, $options );
				return $native;
			}
		}

		return $this->fallback_convert( $html, $css, $options );
	}

	private function native_available(): bool {
		if ( class_exists( '\Bricks\Html_To_Bricks_Converter' ) ) {
			return true;
		}

		if ( ! defined( 'BRICKS_PATH' ) ) {
			return false;
		}

		$root = trailingslashit( (string) BRICKS_PATH ) . 'includes/html-to-bricks/';
		if ( ! is_readable( $root . 'converter.php' ) ) {
			return false;
		}

		foreach ( glob( $root . '*.php' ) ?: [] as $file ) {
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		$controls = trailingslashit( (string) BRICKS_PATH ) . 'includes/html-to-bricks/css-to-controls/';
		foreach ( glob( $controls . '*.php' ) ?: [] as $file ) {
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		return class_exists( '\Bricks\Html_To_Bricks_Converter' );
	}

	private function native_convert( string $html, string $css, array $options ): array {
		try {
			$result = \Bricks\Html_To_Bricks_Converter::convert(
				$this->html_with_css( $html, $css ),
				[
					'create_global_classes' => ! empty( $options['createGlobalClasses'] ),
					'extract_variables'     => ! empty( $options['extractVariables'] ),
					'validate'              => true,
				]
			);
		} catch ( \Throwable $error ) {
			return [
				'success'  => false,
				'converter' => 'bricks-native',
				'elements' => [],
				'warnings' => [ 'Native Bricks converter threw: ' . $error->getMessage() ],
				'errors'   => [ 'native_exception' ],
			];
		}

		$warnings = [];
		if ( ! empty( $result['has_executable_js'] ) ) {
			$warnings[] = 'Executable JavaScript was detected; Kiwe staging does not treat that as production authority.';
		}

		return [
			'success'         => ! empty( $result['success'] ),
			'converter'       => 'bricks-native',
			'elements'        => isset( $result['elements'] ) && is_array( $result['elements'] ) ? $result['elements'] : [],
			'globalClasses'   => isset( $result['global_classes'] ) && is_array( $result['global_classes'] ) ? $result['global_classes'] : [],
			'globalVariables' => isset( $result['global_variables'] ) && is_array( $result['global_variables'] ) ? $result['global_variables'] : [],
			'warnings'        => $warnings,
			'errors'          => [],
		];
	}

	private function fallback_convert( string $html, string $css, array $options ): array {
		$this->counter  = 0;
		$this->elements = [];
		$this->warnings = [ 'Used Kiwe fallback converter; CSS is stored as Bricks page custom CSS instead of expanded into Bricks controls.' ];

		if ( ! class_exists( '\DOMDocument' ) ) {
			return [
				'success'  => false,
				'converter' => 'kiwe-fallback',
				'elements' => [],
				'warnings' => [ 'PHP DOM extension is unavailable.' ],
				'errors'   => [ 'dom_unavailable' ],
			];
		}

		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded = $document->loadHTML(
			'<?xml encoding="utf-8" ?><!doctype html><html><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return [
				'success'  => false,
				'converter' => 'kiwe-fallback',
				'elements' => [],
				'warnings' => [ 'HTML could not be parsed by DOMDocument.' ],
				'errors'   => [ 'parse_failed' ],
			];
		}

		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			$this->warnings[] = 'No body node was found after parsing.';
		}

		$children = $body ? iterator_to_array( $body->childNodes ) : iterator_to_array( $document->childNodes );
		foreach ( $children as $child ) {
			$this->map_node( $child, 0 );
			if ( count( $this->elements ) >= self::MAX_ELEMENTS ) {
				$this->warnings[] = 'Element limit reached; remaining nodes were skipped.';
				break;
			}
		}

		return [
			'success'      => [] !== $this->elements,
			'converter'    => 'kiwe-fallback',
			'elements'     => $this->elements,
			'pageSettings' => $this->page_settings( $css, $options ),
			'warnings'     => array_values( array_unique( $this->warnings ) ),
			'errors'       => [],
		];
	}

	private function map_node( \DOMNode $node, string|int $parent_id ): ?string {
		if ( count( $this->elements ) >= self::MAX_ELEMENTS ) {
			return null;
		}

		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $node->textContent ) );
			if ( '' === $text || ! $parent_id ) {
				return null;
			}

			return $this->add_element(
				[
					'name'     => 'text-basic',
					'parent'   => $parent_id,
					'settings' => [
						'tag'  => 'span',
						'text' => sanitize_text_field( $text ),
					],
				],
				false
			);
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType || ! $node instanceof \DOMElement ) {
			return null;
		}

		$tag = strtolower( $node->tagName );
		if ( in_array( $tag, [ 'script', 'style', 'link', 'meta', 'noscript', 'template' ], true ) ) {
			if ( 'script' === $tag ) {
				$this->warnings[] = 'Script tags were removed by the fallback converter.';
			}
			return null;
		}

		$mapped = $this->map_element_shell( $node, $parent_id );
		if ( ! $mapped ) {
			return null;
		}

		$element_id = $this->add_element( $mapped, $this->is_nestable( (string) $mapped['name'] ) );
		if ( ! $element_id ) {
			return null;
		}

		if ( isset( $this->elements[ count( $this->elements ) - 1 ]['children'] ) ) {
			$parent_index = count( $this->elements ) - 1;
			foreach ( iterator_to_array( $node->childNodes ) as $child ) {
				$child_id = $this->map_node( $child, $element_id );
				if ( $child_id ) {
					$this->elements[ $parent_index ]['children'][] = $child_id;
				}
				if ( count( $this->elements ) >= self::MAX_ELEMENTS ) {
					break;
				}
			}
		}

		return $element_id;
	}

	private function map_element_shell( \DOMElement $node, string|int $parent_id ): array {
		$tag      = strtolower( $node->tagName );
		$name     = 'div';
		$settings = [];

		if ( preg_match( '/^h[1-6]$/', $tag ) ) {
			$name = 'heading';
			$settings['tag']  = $tag;
			$settings['text'] = sanitize_text_field( trim( (string) $node->textContent ) );
		} elseif ( 'p' === $tag ) {
			$name = 'text-basic';
			$settings['tag']  = 'p';
			$settings['text'] = $this->sanitize_inline_html( $this->inner_html( $node ) );
		} elseif ( 'a' === $tag ) {
			$name = 'text-link';
			$settings['text'] = sanitize_text_field( trim( (string) $node->textContent ) );
			$href = trim( (string) $node->getAttribute( 'href' ) );
			if ( '' !== $href && ! preg_match( '/^\s*javascript:/i', $href ) ) {
				$link_url = $this->dynamic_tag( $href ) ? $href : esc_url_raw( $href );
				$settings['link'] = [
					'type' => str_starts_with( $href, '#' ) ? 'external' : 'external',
					'url'  => $link_url,
				];
			}
		} elseif ( 'button' === $tag ) {
			$name = 'button';
			$settings['text'] = sanitize_text_field( trim( (string) $node->textContent ) );
		} elseif ( 'img' === $tag ) {
			$name = 'image';
			$raw_src = trim( (string) $node->getAttribute( 'src' ) );
			$src     = $this->dynamic_tag( $raw_src ) ? $raw_src : esc_url_raw( $raw_src );
			if ( '' !== $src ) {
				$settings['image'] = [
					'url'           => $src,
					'isPlaceholder' => true,
				];
			}
			$settings['altText'] = sanitize_text_field( (string) $node->getAttribute( 'alt' ) );
		} elseif ( 'svg' === $tag ) {
			$name = 'svg';
			$settings['source'] = 'code';
			$settings['code']   = $this->sanitize_svg_html( $this->outer_html( $node ) );
		} elseif ( in_array( $tag, [ 'section', 'header', 'footer', 'article', 'aside' ], true ) ) {
			$name = 'section';
			if ( 'section' !== $tag ) {
				$settings['tag'] = $tag;
			}
		} elseif ( in_array( $tag, [ 'main', 'nav', 'ul', 'ol', 'li', 'figure', 'figcaption', 'address', 'blockquote', 'span', 'label' ], true ) ) {
			$name = 'div';
			$settings['tag']       = 'custom';
			$settings['customTag'] = $tag;
		} elseif ( in_array( $tag, [ 'input', 'select', 'textarea', 'form' ], true ) ) {
			$name = 'div';
			$settings['tag']       = 'custom';
			$settings['customTag'] = $tag;
			if ( in_array( $tag, [ 'input', 'select', 'textarea' ], true ) ) {
				$this->warnings[] = 'Form controls were preserved as custom-tag elements; production submission authority remains with Kiwe/WordPress/Bricks.';
			}
		}

		$settings = array_merge( $settings, $this->shared_settings( $node, $tag ) );

		return [
			'name'     => $name,
			'parent'   => $parent_id ?: 0,
			'settings' => $settings,
		];
	}

	private function shared_settings( \DOMElement $node, string $tag ): array {
		$settings = [];
		$id       = trim( (string) $node->getAttribute( 'id' ) );
		$class    = trim( (string) $node->getAttribute( 'class' ) );
		$style    = trim( (string) $node->getAttribute( 'style' ) );

		if ( '' !== $id && preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,95}$/', $id ) ) {
			$settings['_cssId'] = $id;
		}
		if ( '' !== $class ) {
			$class_tokens = array_values( array_filter( preg_split( '/\s+/', $class ) ?: [], static fn( string $token ): bool => (bool) preg_match( '/^[A-Za-z0-9_-]{1,96}$/', $token ) ) );
			if ( [] !== $class_tokens ) {
				$settings['_cssClasses'] = implode( ' ', $class_tokens );
			}
		}
		if ( '' !== $style ) {
			$clean_style = $this->sanitize_inline_style( $style );
			if ( '' !== $clean_style ) {
				$settings['_cssCustom'] = $clean_style;
			}
		}

		$attributes = [];
		if ( $node->hasAttributes() && 'svg' !== $tag ) {
			foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
				$name  = strtolower( (string) $attribute->name );
				$value = (string) $attribute->value;
				if ( in_array( $name, [ 'id', 'class', 'style', 'href', 'src', 'alt' ], true ) || str_starts_with( $name, 'on' ) ) {
					continue;
				}
				if ( ! preg_match( '/^(data-[a-z0-9_.:-]+|aria-[a-z0-9_.:-]+|role|title|type|name|value|placeholder|for|tabindex|target|rel|loading)$/', $name ) ) {
					continue;
				}
				if ( preg_match( '/<\s*script|javascript:|data:text\/html/i', $value ) ) {
					continue;
				}
				$attributes[] = [
					'id'    => $this->make_id( 'at' ),
					'name'  => $name,
					'value' => sanitize_text_field( $value ),
				];
			}
		}
		if ( [] !== $attributes ) {
			$settings['_attributes'] = $attributes;
		}
		$query_template = trim( (string) $node->getAttribute( 'data-kiwe-query-template' ) );
		if ( '' !== $query_template ) {
			$query = $this->query_for_template( $query_template );
			if ( [] !== $query ) {
				$settings['query'] = $query;
			} else {
				$this->warnings[] = sprintf( 'No Kiwe binding query matched data-kiwe-query-template="%s".', sanitize_text_field( $query_template ) );
			}
		}

		return $settings;
	}

	private function query_templates_from_options( array $options ): array {
		$binding = [];
		foreach ( [ 'kiweBindings', 'binding', 'bindings' ] as $key ) {
			if ( isset( $options[ $key ] ) && is_array( $options[ $key ] ) ) {
				$binding = $options[ $key ];
				break;
			}
		}
		if ( [] === $binding || empty( $binding['queries'] ) || ! is_array( $binding['queries'] ) ) {
			return [];
		}

		$templates = [];
		foreach ( $binding['queries'] as $query ) {
			if ( ! is_array( $query ) || empty( $query['bricks'] ) || ! is_array( $query['bricks'] ) ) {
				continue;
			}
			$bricks_query = $this->sanitize_query_settings( $query['bricks'] );
			if ( [] === $bricks_query ) {
				continue;
			}
			$keys = [];
			$id   = sanitize_key( (string) ( $query['id'] ?? '' ) );
			if ( '' !== $id ) {
				$keys[] = $id;
			}
			$selector = (string) ( $query['selector'] ?? '' );
			if ( preg_match( '/data-kiwe-query-template\s*=\s*["\']([^"\']+)["\']/i', $selector, $match ) ) {
				$keys[] = (string) $match[1];
			}
			foreach ( $keys as $key ) {
				$normalized = $this->normalize_binding_key( $key );
				if ( '' !== $normalized ) {
					$templates[ $normalized ] = $bricks_query;
				}
			}
		}

		return $templates;
	}

	private function query_for_template( string $template ): array {
		$normalized = $this->normalize_binding_key( $template );
		return '' !== $normalized && isset( $this->query_templates[ $normalized ] ) ? $this->query_templates[ $normalized ] : [];
	}

	private function normalize_binding_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '', $key ) ?: '' );
	}

	private function sanitize_query_settings( array $query ): array {
		$allowed = [
			'objectType',
			'post_type',
			'post_status',
			'posts_per_page',
			'orderby',
			'order',
			'ignore_sticky_posts',
			'taxonomy',
			'hide_empty',
			'exclude',
			'include',
			'number',
			'meta_query',
			'tax_query',
			'relation',
		];
		$out = [];
		foreach ( $query as $key => $value ) {
			$key = (string) $key;
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			$out[ $key ] = $this->sanitize_query_value( $value, $key );
		}
		if ( empty( $out['objectType'] ) ) {
			return [];
		}

		return $out;
	}

	private function sanitize_query_value( mixed $value, string $key ): mixed {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			$value = (string) $value;
			if ( in_array( $key, [ 'posts_per_page', 'number' ], true ) ) {
				return max( 1, min( 24, absint( $value ) ) );
			}
			if ( in_array( $key, [ 'include', 'exclude' ], true ) ) {
				return array_values( array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', $value ) ?: [] ) ) );
			}
			return sanitize_text_field( substr( $value, 0, 180 ) );
		}
		if ( is_array( $value ) ) {
			if ( in_array( $key, [ 'include', 'exclude' ], true ) ) {
				return array_values( array_filter( array_map( 'absint', $value ) ) );
			}
			$out = [];
			foreach ( $value as $child_key => $child_value ) {
				if ( is_int( $child_key ) ) {
					$out[] = is_array( $child_value ) ? $this->sanitize_query_clause( $child_value ) : $this->sanitize_query_value( $child_value, $key );
				} else {
					$child_key = sanitize_key( (string) $child_key );
					if ( '' !== $child_key ) {
						$out[ $child_key ] = is_array( $child_value ) ? $this->sanitize_query_clause( $child_value ) : $this->sanitize_query_value( $child_value, $child_key );
					}
				}
			}
			return $out;
		}

		return '';
	}

	private function sanitize_query_clause( array $clause ): array {
		$allowed = [ 'key', 'value', 'compare', 'type', 'taxonomy', 'field', 'terms', 'operator', 'include_children', 'relation' ];
		$out = [];
		foreach ( $clause as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			$out[ $key ] = $this->sanitize_query_value( $value, $key );
		}
		return $out;
	}

	private function dynamic_tag( string $value ): bool {
		return (bool) preg_match( '/^\{[a-zA-Z0-9_:\-]+\}$/', trim( $value ) );
	}

	private function sanitize_inline_html( string $html ): string {
		$html = substr( $html, 0, 12000 );
		$html = preg_replace( '/<\s*(script|style|iframe|object|embed|template|noscript)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html );
		$html = preg_replace( '/\s+on[a-z0-9_-]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', (string) $html );
		$html = preg_replace( '/(href|src)\s*=\s*([\'"])\s*(javascript:|data:text\/html)[^\'"]*\2/is', '$1="#"', (string) $html );

		return trim( (string) $html );
	}

	private function sanitize_svg_html( string $html ): string {
		$html = substr( $html, 0, 30000 );
		$html = preg_replace( '/<\s*(script|foreignObject|iframe|object|embed)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html );
		$html = preg_replace( '/\s+on[a-z0-9_-]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', (string) $html );
		$html = preg_replace( '/(href|xlink:href)\s*=\s*([\'"])\s*(javascript:|data:text\/html)[^\'"]*\2/is', '$1="#"', (string) $html );

		return trim( (string) $html );
	}

	private function add_element( array $element, bool $nestable ): ?string {
		if ( count( $this->elements ) >= self::MAX_ELEMENTS ) {
			return null;
		}

		$element['id'] = $this->make_id( 'kw' );
		if ( $nestable ) {
			$element['children'] = [];
		}
		$this->elements[] = $element;

		return $element['id'];
	}

	private function is_nestable( string $name ): bool {
		return in_array( $name, [ 'section', 'div' ], true );
	}

	private function page_settings( string $css, array $options ): array {
		$settings = isset( $options['pageSettings'] ) && is_array( $options['pageSettings'] ) ? $options['pageSettings'] : [];
		$settings = $this->sanitize_page_settings( $settings );
		if ( '' !== trim( $css ) ) {
			$settings['customCss'] = $css;
		}

		return $settings;
	}

	private function sanitize_page_settings( array $settings ): array {
		$out = [];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $key ) || preg_match( '/script|php|password|secret|token|key|license|nonce/i', $key ) ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$out[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$out[ $key ] = sanitize_text_field( substr( (string) $value, 0, 4000 ) );
			}
		}

		return $out;
	}

	private function normalize_html( string $html ): string {
		$html = substr( $html, 0, self::MAX_HTML_BYTES );
		$html = preg_replace( '/<!doctype[^>]*>/i', '', $html );
		$html = preg_replace( '/<html\b[^>]*>|<\/html>|<head\b[^>]*>.*?<\/head>|<body\b[^>]*>|<\/body>/is', '', is_string( $html ) ? $html : '' );

		return is_string( $html ) ? trim( $html ) : '';
	}

	private function sanitize_css( string $css ): string {
		if ( '' === trim( $css ) ) {
			return '';
		}
		$css = substr( $css, 0, self::MAX_CSS_BYTES );
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css );
		$css = is_string( $css ) ? $css : '';
		if ( preg_match( '/<|@import|expression\s*\(|javascript:|vbscript:|data:text\/html|(^|[;{\s])behavior\s*:|-moz-binding/i', $css ) ) {
			return '';
		}

		return trim( $css );
	}

	private function sanitize_inline_style( string $style ): string {
		$style = substr( $style, 0, 2000 );
		if ( preg_match( '/<|@import|expression\s*\(|javascript:|vbscript:|data:text\/html|(^|[;{\s])behavior\s*:|-moz-binding/i', $style ) ) {
			return '';
		}

		return trim( $style );
	}

	private function make_id( string $prefix ): string {
		$this->counter += 1;
		$seed = $prefix . (string) $this->counter . microtime( true );
		return substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $prefix . base_convert( (string) $this->counter, 10, 36 ) . hash( 'crc32b', $seed ) ) ), 0, 6 );
	}

	private function html_with_css( string $html, string $css ): string {
		return '' === trim( $css ) ? $html : '<style>' . $css . '</style>' . "\n" . $html;
	}

	private function inner_html( \DOMElement $node ): string {
		$html = '';
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			$html .= $node->ownerDocument ? $node->ownerDocument->saveHTML( $child ) : '';
		}

		return $html;
	}

	private function outer_html( \DOMElement $node ): string {
		return $node->ownerDocument ? (string) $node->ownerDocument->saveHTML( $node ) : '';
	}

	private function svg_allowlist(): array {
		return [
			'svg'     => [ 'xmlns' => true, 'viewbox' => true, 'viewBox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'class' => true, 'aria-hidden' => true, 'role' => true ],
			'path'    => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true ],
			'g'       => [ 'fill' => true, 'stroke' => true, 'class' => true ],
			'circle'  => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'class' => true ],
			'rect'    => [ 'x' => true, 'y' => true, 'rx' => true, 'ry' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'class' => true ],
			'line'    => [ 'x1' => true, 'x2' => true, 'y1' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ],
			'polyline'=> [ 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ],
			'polygon' => [ 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ],
			'use'     => [ 'href' => true, 'xlink:href' => true ],
		];
	}
}
