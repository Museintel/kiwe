<?php

namespace DSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Element_Registry {
	private const SNAPSHOT_META_KEY = '_dsa_registry_snapshot';
	private const SNAPSHOT_VERSION = 1;
	private const SNAPSHOT_ELEMENT_LIMIT = 140;

	/** @var array<string,array> */
	private array $elements = [];

	public function register(): void {
		add_action( 'save_post', [ $this, 'save_post_snapshot' ], 25, 3 );
		add_action( 'deleted_post', [ $this, 'delete_snapshot' ] );
	}

	public function add( array $element ): void {
		if ( empty( $element['id'] ) ) {
			return;
		}

		$id = sanitize_key( (string) $element['id'] );

		$this->elements[ $id ] = wp_parse_args(
			$element,
			[
				'id'         => $id,
				'source'     => 'unknown',
				'type'       => 'unknown',
				'label'      => '',
				'selector'   => '',
				'confidence' => 0.5,
				'postId'     => get_queried_object_id(),
				'editable'   => false,
				'aiVisible'  => true,
			]
		);
	}

	public function all(): array {
		return array_values( $this->elements );
	}

	public function to_array(): array {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';
		$post_id = get_queried_object_id();
		$live_elements = $this->all();
		$snapshot = $post_id ? $this->snapshot_for_post( (int) $post_id ) : [];
		$elements = $live_elements;
		$source = 'live';

		if ( empty( $elements ) && ! empty( $snapshot['elements'] ) && is_array( $snapshot['elements'] ) ) {
			$elements = $snapshot['elements'];
			$source = 'snapshot';
		}

		return [
			'route'          => esc_url_raw( home_url( $request_uri ) ),
			'postId'         => $post_id,
			'elements'       => $elements,
			'count'          => count( $elements ),
			'summary'        => $this->summary( $elements ),
			'registrySource' => $source,
			'snapshot'       => [
				'available'       => ! empty( $snapshot ),
				'version'         => isset( $snapshot['version'] ) ? absint( $snapshot['version'] ) : 0,
				'count'           => isset( $snapshot['count'] ) ? absint( $snapshot['count'] ) : 0,
				'generatedAt'     => isset( $snapshot['generatedAt'] ) ? sanitize_text_field( (string) $snapshot['generatedAt'] ) : '',
				'postModifiedGmt' => isset( $snapshot['postModifiedGmt'] ) ? sanitize_text_field( (string) $snapshot['postModifiedGmt'] ) : '',
				'contentHash'     => isset( $snapshot['contentHash'] ) ? sanitize_text_field( (string) $snapshot['contentHash'] ) : '',
				'usedAsFallback'  => 'snapshot' === $source,
			],
		];
	}

	public function save_post_snapshot( int $post_id, $post, bool $update ): void {
		unset( $update );

		if ( ! $post instanceof \WP_Post || $post_id <= 0 ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( in_array( $post->post_type, [ 'attachment', 'revision', 'nav_menu_item' ], true ) ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			delete_post_meta( $post_id, self::SNAPSHOT_META_KEY );
			return;
		}

		$snapshot = $this->build_snapshot_for_post( $post );

		if ( empty( $snapshot['elements'] ) ) {
			delete_post_meta( $post_id, self::SNAPSHOT_META_KEY );
			return;
		}

		update_post_meta( $post_id, self::SNAPSHOT_META_KEY, $snapshot );
	}

	public function delete_snapshot( int $post_id ): void {
		if ( $post_id > 0 ) {
			delete_post_meta( $post_id, self::SNAPSHOT_META_KEY );
		}
	}

	public function snapshot_for_post( int $post_id ): array {
		$snapshot = $post_id > 0 ? get_post_meta( $post_id, self::SNAPSHOT_META_KEY, true ) : [];

		if ( ! is_array( $snapshot ) || empty( $snapshot['version'] ) || empty( $snapshot['elements'] ) || ! is_array( $snapshot['elements'] ) ) {
			return [];
		}

		return $this->sanitize_snapshot( $snapshot );
	}

	public function classify_html( string $html, string $fallback_type = 'unknown' ): array {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		$type = $fallback_type;
		$confidence = 0.55;

		if ( in_array( $fallback_type, [ 'layout', 'region', 'icon', 'decorative' ], true ) ) {
			$type       = $fallback_type;
			$confidence = 'region' === $fallback_type ? 0.72 : 0.62;
		} elseif ( preg_match( '/<h([1-6])\b/i', $html, $match ) ) {
			$type       = 'heading';
			$confidence = 0.9;
		} elseif ( preg_match( '/<form\b/i', $html ) ) {
			$type       = 'form';
			$confidence = 0.9;
		} elseif ( preg_match( '/<(button)\b|<input[^>]+type=[\'"]?(submit|button)/i', $html ) ) {
			$type       = 'interactive';
			$confidence = 0.85;
		} elseif ( preg_match( '/<img\b|background-image\s*:/i', $html ) ) {
			$type       = 'image';
			$confidence = 0.82;
		} elseif ( preg_match( '/<a\b[^>]*href=/i', $html ) ) {
			$type       = 'navigation';
			$confidence = 0.78;
		} elseif ( '' !== $text && strlen( $text ) <= 280 ) {
			$type       = 'text';
			$confidence = 0.68;
		}

		$label = 'image' === $type ? $this->image_label_from_html( $html, $text ) : $this->label_from_text( $text, $type );

		return [
			'type'       => $type,
			'label'      => $label,
			'confidence' => $confidence,
			'editable'   => in_array( $type, [ 'heading', 'text', 'image' ], true ),
			'aiVisible'  => ! in_array( $type, [ 'layout', 'unknown', 'decorative' ], true ),
		];
	}

	private function build_snapshot_for_post( \WP_Post $post ): array {
		$post_id = (int) $post->ID;
		$elements = [];
		$sources = [];

		foreach ( $this->elements_from_html( (string) $post->post_content, 'post_content', $post_id ) as $element ) {
			$elements[ $element['id'] ] = $element;
			$sources['post_content'] = true;
		}

		foreach ( $this->bricks_saved_elements( $post_id ) as $element ) {
			$elements[ $element['id'] ] = $element;
			$sources['bricks_meta'] = true;
		}

		$elements = array_slice( array_values( $elements ), 0, self::SNAPSHOT_ELEMENT_LIMIT );

		return [
			'version'         => self::SNAPSHOT_VERSION,
			'generatedAt'     => gmdate( 'c' ),
			'postId'          => $post_id,
			'postType'        => sanitize_key( (string) $post->post_type ),
			'postStatus'      => sanitize_key( (string) $post->post_status ),
			'postModifiedGmt' => sanitize_text_field( (string) $post->post_modified_gmt ),
			'contentHash'     => hash( 'sha256', (string) $post->post_content . '|' . $this->bricks_saved_hash( $post_id ) ),
			'sources'         => array_keys( $sources ),
			'elements'        => $elements,
			'count'           => count( $elements ),
			'summary'         => $this->summary( $elements ),
		];
	}

	private function elements_from_html( string $html, string $source, int $post_id ): array {
		if ( '' === trim( $html ) ) {
			return [];
		}

		$elements = [];
		$matches = [];
		preg_match_all( '/<(h[1-6]|p|a|img|form|button)\b[^>]*(?:>.*?<\/\1>|\/?>)/is', $html, $matches );

		foreach ( $matches[0] ?? [] as $index => $fragment ) {
			$classification = $this->classify_html( (string) $fragment, 'unknown' );

			if ( 'unknown' === $classification['type'] || ( empty( $classification['label'] ) && empty( $classification['aiVisible'] ) ) ) {
				continue;
			}

			$id = 'snap-' . substr( hash( 'sha256', $source . '|' . $post_id . '|' . $index . '|' . $fragment ), 0, 14 );
			$elements[] = [
				'id'         => sanitize_key( $id ),
				'source'     => $source,
				'type'       => $classification['type'],
				'label'      => $classification['label'],
				'selector'   => '',
				'confidence' => min( 0.82, (float) $classification['confidence'] ),
				'postId'     => $post_id,
				'editable'   => (bool) $classification['editable'],
				'aiVisible'  => (bool) $classification['aiVisible'],
			];

			if ( count( $elements ) >= self::SNAPSHOT_ELEMENT_LIMIT ) {
				break;
			}
		}

		return $elements;
	}

	private function bricks_saved_elements( int $post_id ): array {
		$elements = [];

		foreach ( $this->bricks_meta_keys() as $meta_key ) {
			$data = get_post_meta( $post_id, $meta_key, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			foreach ( $this->flatten_bricks_nodes( $data ) as $node ) {
				if ( count( $elements ) >= self::SNAPSHOT_ELEMENT_LIMIT ) {
					break 2;
				}

				$id = ! empty( $node['id'] ) ? sanitize_key( (string) $node['id'] ) : 'bricks-' . substr( hash( 'sha256', wp_json_encode( $node ) ?: '' ), 0, 12 );
				$name = ! empty( $node['name'] ) ? sanitize_key( (string) $node['name'] ) : 'bricks-element';
				$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
				$fallback = $this->classify_from_builder_name( $name );
				$html = $this->html_from_bricks_settings( $settings, $name );
				$classification = $this->classify_html( $html, $fallback );

				$elements[] = [
					'id'         => 'snap-' . $id,
					'source'     => 'bricks_meta',
					'bricksType' => $name,
					'type'       => $classification['type'],
					'label'      => $classification['label'],
					'selector'   => '[data-dsa-bricks-id="' . esc_attr( $id ) . '"]',
					'confidence' => min( 0.78, (float) $classification['confidence'] ),
					'postId'     => $post_id,
					'editable'   => (bool) $classification['editable'],
					'aiVisible'  => (bool) $classification['aiVisible'],
				];
			}
		}

		return $elements;
	}

	private function flatten_bricks_nodes( array $nodes ): array {
		$out = [];

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['id'] ) || isset( $node['name'] ) || isset( $node['settings'] ) ) {
				$out[] = $node;
			}

			foreach ( [ 'children', 'elements' ] as $child_key ) {
				if ( ! empty( $node[ $child_key ] ) && is_array( $node[ $child_key ] ) ) {
					array_push( $out, ...$this->flatten_bricks_nodes( $node[ $child_key ] ) );
				}
			}
		}

		return $out;
	}

	private function html_from_bricks_settings( array $settings, string $name ): string {
		foreach ( [ 'text', 'content', 'title', 'label', 'altText', 'ariaLabel', 'buttonText' ] as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
				return '<div>' . wp_kses_post( (string) $settings[ $key ] ) . '</div>';
			}
		}

		if ( ! empty( $settings['image']['url'] ) && is_string( $settings['image']['url'] ) ) {
			return '<img src="' . esc_url( $settings['image']['url'] ) . '" alt="">';
		}

		return '<div>' . esc_html( $name ) . '</div>';
	}

	private function classify_from_builder_name( string $name ): string {
		if ( str_contains( $name, 'heading' ) || str_contains( $name, 'title' ) ) {
			return 'heading';
		}

		if ( str_contains( $name, 'image' ) || str_contains( $name, 'media' ) || str_contains( $name, 'logo' ) ) {
			return 'image';
		}

		if ( str_contains( $name, 'button' ) || str_contains( $name, 'cart' ) ) {
			return 'interactive';
		}

		if ( str_contains( $name, 'form' ) || str_contains( $name, 'checkout' ) ) {
			return 'form';
		}

		if ( str_contains( $name, 'nav' ) || str_contains( $name, 'menu' ) || str_contains( $name, 'link' ) ) {
			return 'navigation';
		}

		if ( str_contains( $name, 'section' ) || str_contains( $name, 'container' ) || str_contains( $name, 'block' ) ) {
			return 'region';
		}

		return 'text';
	}

	private function bricks_meta_keys(): array {
		$keys = [
			'_bricks_page_content',
			'_bricks_page_header',
			'_bricks_page_footer',
		];

		foreach ( [ 'BRICKS_DB_PAGE_CONTENT', 'BRICKS_DB_PAGE_HEADER', 'BRICKS_DB_PAGE_FOOTER' ] as $constant ) {
			if ( defined( $constant ) ) {
				$keys[] = (string) constant( $constant );
			}
		}

		if ( class_exists( '\\Bricks\\Database' ) && is_callable( [ '\\Bricks\\Database', 'get_bricks_data_key' ] ) ) {
			foreach ( [ 'content', 'header', 'footer' ] as $area ) {
				$key = \Bricks\Database::get_bricks_data_key( $area );
				if ( is_string( $key ) && '' !== $key ) {
					$keys[] = $key;
				}
			}
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	private function bricks_saved_hash( int $post_id ): string {
		$parts = [];

		foreach ( $this->bricks_meta_keys() as $meta_key ) {
			$data = get_post_meta( $post_id, $meta_key, true );
			if ( ! empty( $data ) ) {
				$parts[] = $meta_key . ':' . hash( 'sha256', wp_json_encode( $data ) ?: '' );
			}
		}

		return implode( '|', $parts );
	}

	private function sanitize_snapshot( array $snapshot ): array {
		$elements = [];

		foreach ( $snapshot['elements'] as $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				continue;
			}

			$elements[] = wp_parse_args(
				$element,
				[
					'id'         => sanitize_key( (string) $element['id'] ),
					'source'     => 'snapshot',
					'type'       => 'unknown',
					'label'      => '',
					'selector'   => '',
					'confidence' => 0.5,
					'postId'     => absint( $snapshot['postId'] ?? 0 ),
					'editable'   => false,
					'aiVisible'  => true,
				]
			);
		}

		$snapshot['elements'] = array_slice( $elements, 0, self::SNAPSHOT_ELEMENT_LIMIT );
		$snapshot['count'] = count( $snapshot['elements'] );
		$snapshot['summary'] = $this->summary( $snapshot['elements'] );

		return $snapshot;
	}

	private function summary( ?array $elements = null ): array {
		$summary = [];

		foreach ( $elements ?? $this->elements as $element ) {
			$type = isset( $element['type'] ) ? (string) $element['type'] : 'unknown';

			if ( ! isset( $summary[ $type ] ) ) {
				$summary[ $type ] = 0;
			}

			$summary[ $type ]++;
		}

		ksort( $summary );

		return $summary;
	}

	private function label_from_text( string $text, string $type ): string {
		if ( '' !== $text ) {
			return mb_substr( html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) ), 0, 80 );
		}

		$labels = [
			'heading'     => __( 'Heading', 'dsa' ),
			'form'        => __( 'Form', 'dsa' ),
			'interactive' => __( 'Interactive Element', 'dsa' ),
			'image'       => __( 'Image', 'dsa' ),
			'navigation'  => __( 'Navigation Link', 'dsa' ),
			'region'      => __( 'Content Region', 'dsa' ),
			'layout'      => __( 'Layout Element', 'dsa' ),
			'icon'        => __( 'Icon', 'dsa' ),
			'decorative'  => __( 'Decorative Element', 'dsa' ),
			'text'        => __( 'Text', 'dsa' ),
		];

		return $labels[ $type ] ?? __( 'Element', 'dsa' );
	}

	private function image_label_from_html( string $html, string $fallback_text ): string {
		foreach ( [ 'alt', 'title', 'aria-label' ] as $attribute ) {
			if ( preg_match( '/\s' . preg_quote( $attribute, '/' ) . '=["\']([^"\']+)["\']/i', $html, $match ) ) {
				return mb_substr( html_entity_decode( trim( $match[1] ), ENT_QUOTES, get_bloginfo( 'charset' ) ), 0, 80 );
			}
		}

		if ( preg_match( '/<img\b[^>]*\ssrc=["\']([^"\']+)["\']/i', $html, $match ) ) {
			$path = wp_parse_url( html_entity_decode( $match[1], ENT_QUOTES, get_bloginfo( 'charset' ) ), PHP_URL_PATH );
			$file = $path ? basename( $path ) : '';
			$name = $file ? preg_replace( '/\.[a-z0-9]+$/i', '', $file ) : '';
			$name = $name ? trim( str_replace( [ '-', '_' ], ' ', $name ) ) : '';

			if ( '' !== $name ) {
				return mb_substr( ucwords( $name ), 0, 80 );
			}
		}

		if ( '' !== $fallback_text ) {
			return $this->label_from_text( $fallback_text, 'image' );
		}

		return __( 'Image', 'dsa' );
	}
}
