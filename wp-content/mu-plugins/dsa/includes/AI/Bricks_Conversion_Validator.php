<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bricks_Conversion_Validator {
	private const COMMON_DYNAMIC_TAGS = [
		'{post_title}',
		'{post_content}',
		'{post_excerpt}',
		'{post_date}',
		'{post_url}',
		'{post_id}',
		'{featured_image}',
		'{site_title}',
		'{site_tagline}',
		'{site_url}',
		'{term_name}',
		'{term_description}',
		'{woo_product_price}',
		'{woo_product_weight}',
		'{kiwe_site_logo}',
		'{kiwe_site_logo_inverse}',
	];

	public function validate( array $conversion, array $site_graph = [], string $source_html = '', array $binding = [] ): array {
		$findings = [];
		$index    = $this->graph_index( $site_graph );

		$this->validate_root( $conversion, $findings );
		$this->validate_elements( $conversion, $findings, $index );
		$this->validate_source_parity( $conversion, $source_html, $findings );
		$this->validate_dynamic_tags( $conversion, $findings, $index, [] !== $site_graph );
		if ( [] !== $binding ) {
			$binding_report = ( new Binding_Plan_Validator() )->validate( $binding, $site_graph );
			if ( empty( $binding_report['ok'] ) ) {
				$this->add( $findings, 'fail', 'linked_binding_plan_failed', 'Linked binding plan did not pass validation.' );
			}
		}

		return [
			'ok'       => ! $this->has_level( $findings, 'fail' ),
			'schema'   => 'kiwe.bricks-conversion-validation.v1',
			'counts'   => $this->counts( $findings ),
			'summary'  => [
				'elements'       => isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? count( $conversion['elements'] ) : 0,
				'hasSourceHtml'  => '' !== trim( $source_html ),
				'hasSiteGraph'   => [] !== $site_graph,
				'hasBindingPlan' => [] !== $binding,
			],
			'findings' => $findings,
		];
	}

	private function validate_root( array $conversion, array &$findings ): void {
		foreach ( [ 'schema', 'source', 'target', 'conversion', 'elements', 'fidelity', 'report' ] as $key ) {
			if ( ! array_key_exists( $key, $conversion ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_missing_root_key', sprintf( 'Missing root key "%s".', $key ), '$.' . $key );
			}
		}
		if ( 'kiwe.bricks-conversion.v1' !== (string) ( $conversion['schema'] ?? '' ) ) {
			$this->add( $findings, 'fail', 'invalid_bricks_conversion_schema', 'schema must be kiwe.bricks-conversion.v1.', '$.schema' );
		}
		$target = isset( $conversion['target'] ) && is_array( $conversion['target'] ) ? $conversion['target'] : [];
		if ( 'bricks' !== (string) ( $target['builder'] ?? '' ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_wrong_builder', 'target.builder must be bricks.', '$.target.builder' );
		}
		if ( ! str_contains( strtolower( (string) ( $target['format'] ?? '' ) ), 'bricks' ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_format', 'target.format must identify Bricks element JSON.', '$.target.format' );
		}
		$authority = (string) ( $target['applyAuthority'] ?? '' );
		if ( '' === $authority || ( preg_match( '/(?:auto|direct|save|publish|mutat|write)/i', $authority ) && ! preg_match( '/(?:human|review|trusted|adapter|staging)/i', $authority ) ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_unsafe_apply_authority', 'applyAuthority must point to human review or a trusted Kiwe staging adapter.', '$.target.applyAuthority' );
		}
		if ( ! isset( $conversion['elements'] ) || ! is_array( $conversion['elements'] ) || [] === $conversion['elements'] ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_elements', 'elements must be a non-empty array.', '$.elements' );
		}
		$fidelity = isset( $conversion['fidelity'] ) && is_array( $conversion['fidelity'] ) ? $conversion['fidelity'] : [];
		if ( empty( $fidelity['sourceSelectors'] ) || ! is_array( $fidelity['sourceSelectors'] ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_fidelity_map', 'fidelity.sourceSelectors must map important source regions to Bricks element IDs.', '$.fidelity.sourceSelectors' );
		}
		$report = isset( $conversion['report'] ) && is_array( $conversion['report'] ) ? $conversion['report'] : [];
		if ( ! isset( $report['manualReview'] ) || ! is_array( $report['manualReview'] ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_manual_review_lane', 'report.manualReview must be an array, even when empty.', '$.report.manualReview' );
		}
	}

	private function validate_elements( array $conversion, array &$findings, array $index ): void {
		$elements = isset( $conversion['elements'] ) && is_array( $conversion['elements'] ) ? $conversion['elements'] : [];
		$ids      = [];
		foreach ( $elements as $position => $element ) {
			if ( ! is_array( $element ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_invalid_element', sprintf( 'elements[%d] must be an object.', (int) $position ), '$.elements' );
				continue;
			}
			$id = (string) ( $element['id'] ?? '' );
			if ( '' === $id ) {
				$this->add( $findings, 'fail', 'bricks_conversion_element_missing_id', sprintf( 'elements[%d].id is required.', (int) $position ), '$.elements' );
			} elseif ( isset( $ids[ $id ] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_duplicate_element_id', sprintf( 'Duplicate element id "%s".', $id ), '$.elements' );
			}
			if ( '' !== $id ) {
				$ids[ $id ] = true;
			}
			if ( '' === (string) ( $element['name'] ?? '' ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_element_missing_name', sprintf( 'Element "%s" is missing name.', '' !== $id ? $id : '#' . (int) $position ), '$.elements' );
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			if ( isset( $settings['_conditions'] ) && ! is_array( $settings['_conditions'] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_invalid_conditions', sprintf( 'Element "%s" has _conditions but it is not an array.', $id ), '$.elements' );
			}
			if ( isset( $settings['_interactions'] ) && ! is_array( $settings['_interactions'] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_invalid_interactions', sprintf( 'Element "%s" has _interactions but it is not an array.', $id ), '$.elements' );
			} elseif ( isset( $settings['_interactions'] ) ) {
				foreach ( $settings['_interactions'] as $interaction ) {
					if ( is_array( $interaction ) && 'javascript' === (string) ( $interaction['action'] ?? $interaction['actionType'] ?? '' ) ) {
						$this->add( $findings, 'fail', 'bricks_conversion_javascript_interaction', sprintf( 'Element "%s" uses Bricks javascript interaction action.', $id ), '$.elements' );
					}
				}
			}
			$query = isset( $settings['query'] ) && is_array( $settings['query'] ) ? $settings['query'] : [];
			if ( [] !== $query ) {
				$this->validate_query( $query, $findings, $index, $id );
			}
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$parent = (string) ( $element['parent'] ?? '' );
			if ( '' !== $parent && '0' !== $parent && empty( $ids[ $parent ] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_missing_parent', sprintf( 'Element "%s" references missing parent "%s".', (string) ( $element['id'] ?? '' ), $parent ), '$.elements' );
			}
		}
	}

	private function validate_query( array $query, array &$findings, array $index, string $element_id ): void {
		$object_type = (string) ( $query['objectType'] ?? $query['object_type'] ?? '' );
		if ( '' !== $object_type && $index['queryTypes'] && empty( $index['queryTypes'][ $object_type ] ) ) {
			$this->add( $findings, 'warn', 'bricks_conversion_query_object_type_unverified', sprintf( 'Element "%s" uses query objectType "%s" not found in Site Graph.', $element_id, $object_type ), '$.elements' );
		}
		$post_types = $this->array_value( $query, 'post_type' );
		foreach ( $post_types as $post_type ) {
			if ( $index['postTypes'] && empty( $index['postTypes'][ (string) $post_type ] ) ) {
				$this->add( $findings, 'fail', 'bricks_conversion_query_post_type_missing', sprintf( 'Element "%s" uses post type "%s" missing from Site Graph.', $element_id, (string) $post_type ), '$.elements' );
			}
		}
	}

	private function validate_source_parity( array $conversion, string $source_html, array &$findings ): void {
		$conversion_text = wp_json_encode( $conversion );
		$conversion_text = is_string( $conversion_text ) ? $conversion_text : '';
		if ( '' === trim( $source_html ) ) {
			$this->add( $findings, 'warn', 'bricks_conversion_missing_source_html', 'No sourceHtml was supplied, so source-to-conversion parity could not be fully checked.' );
			return;
		}
		if ( preg_match( '/data-dsa-(?:surface|dock|screen|sheet|cart-panel|profile-panel)/i', $source_html ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_source_contains_appshell', 'Source HTML must remain page-only and must not include AppShell shell markup.' );
		}
		if ( preg_match_all( '/class\s*=\s*["\']([^"\']+)["\']/i', $source_html, $class_matches ) ) {
			$seam = [];
			foreach ( $class_matches[1] as $classes ) {
				foreach ( preg_split( '/\s+/', (string) $classes ) as $class ) {
					if ( preg_match( '/^seam-/', $class ) ) {
						$seam[ $class ] = true;
					}
				}
			}
			if ( [] !== $seam ) {
				$missing = [];
				foreach ( array_keys( $seam ) as $class ) {
					if ( ! str_contains( $conversion_text, $class ) ) {
						$missing[] = $class;
					}
				}
				if ( count( $missing ) === count( $seam ) ) {
					$this->add( $findings, 'fail', 'bricks_conversion_lost_seam_classes', 'No source Seam classes are preserved in the Bricks conversion package.' );
				} elseif ( [] !== $missing ) {
					$this->add( $findings, 'warn', 'bricks_conversion_partial_seam_loss', sprintf( 'Some source Seam classes are not visible in the conversion package: %s.', implode( ', ', array_slice( $missing, 0, 12 ) ) ) );
				}
			}
		}
		if ( preg_match_all( '/data-dsa-open-module\s*=\s*["\']([^"\']+)["\']/i', $source_html, $launcher_matches ) ) {
			foreach ( $launcher_matches[1] as $module ) {
				if ( ! str_contains( $conversion_text, 'data-dsa-open-module' ) || ! str_contains( $conversion_text, (string) $module ) ) {
					$this->add( $findings, 'fail', 'bricks_conversion_lost_kiwe_launcher', sprintf( 'Source launcher data-dsa-open-module="%s" was not preserved.', (string) $module ) );
				}
			}
		}
		if ( preg_match( '/data-kiwe-query-template\s*=/i', $source_html ) && ! preg_match( '/"query"\s*:|"dynamicIntent"\s*:\s*\[[^\]]+\]/i', $conversion_text ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_missing_query_intent', 'Source has data-kiwe-query-template markers but conversion has no Bricks query settings or fidelity.dynamicIntent.' );
		}
		if ( preg_match( '/data-dsa-surface|data-dsa-screen|data-dsa-dock/i', $conversion_text ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_contains_appshell_markup', 'Bricks conversion JSON must remain page-only and cannot contain AppShell shell markup.' );
		}
		if ( preg_match( '/<script\b|javascript:|on[a-z]+\s*=/i', $conversion_text ) ) {
			$this->add( $findings, 'fail', 'bricks_conversion_executable_code', 'Bricks conversion package appears to contain executable script or inline event code.' );
		}
	}

	private function validate_dynamic_tags( array $conversion, array &$findings, array $index, bool $has_graph ): void {
		$text = wp_json_encode( $conversion );
		if ( ! is_string( $text ) || ! preg_match_all( '/\{[A-Za-z_][A-Za-z0-9_.:-]{0,120}\}/', $text, $matches ) ) {
			return;
		}
		$tags = array_values( array_unique( $matches[0] ) );
		if ( [] !== $tags && ! $has_graph ) {
			$this->add( $findings, 'warn', 'bricks_conversion_dynamic_tags_unverified', 'Dynamic tags are present but no Site Graph was supplied.' );
			return;
		}
		foreach ( $tags as $tag ) {
			if ( ! isset( $index['dynamicTags'][ $tag ] ) ) {
				$this->add( $findings, 'warn', 'bricks_conversion_unknown_dynamic_tag', sprintf( 'Dynamic tag "%s" is not listed in Site Graph dynamic tags or common Kiwe tags.', $tag ) );
			}
		}
	}

	private function graph_index( array $site_graph ): array {
		$index = [
			'postTypes'   => [],
			'queryTypes'  => [],
			'dynamicTags' => array_fill_keys( self::COMMON_DYNAMIC_TAGS, true ),
		];
		foreach ( $this->array_value( $site_graph['wordpress'] ?? [], 'postTypes' ) as $post_type ) {
			if ( is_array( $post_type ) && ! empty( $post_type['name'] ) ) {
				$index['postTypes'][ (string) $post_type['name'] ] = true;
			}
		}
		foreach ( $this->array_value( $site_graph['customContent'] ?? [], 'postTypes' ) as $post_type ) {
			if ( is_array( $post_type ) && ! empty( $post_type['name'] ) ) {
				$index['postTypes'][ (string) $post_type['name'] ] = true;
			}
		}
		foreach ( $this->array_value( $site_graph['bricks'] ?? [], 'queryLoopTypes' ) as $query_type ) {
			if ( is_array( $query_type ) && ! empty( $query_type['objectType'] ) ) {
				$index['queryTypes'][ (string) $query_type['objectType'] ] = true;
			}
		}
		foreach ( $this->array_value( $site_graph['bricks'] ?? [], 'dynamicTags' ) as $tag ) {
			$name = is_array( $tag ) ? (string) ( $tag['name'] ?? $tag['tag'] ?? '' ) : (string) $tag;
			$name = $this->normalize_dynamic_tag( $name );
			if ( '' !== $name ) {
				$index['dynamicTags'][ $name ] = true;
			}
		}
		foreach ( $this->array_value( $site_graph['bricks'] ?? [], 'kiweDynamicTags' ) as $tag ) {
			$name = $this->normalize_dynamic_tag( (string) $tag );
			if ( '' !== $name ) {
				$index['dynamicTags'][ $name ] = true;
			}
		}
		return $index;
	}

	private function normalize_dynamic_tag( string $tag ): string {
		$tag = trim( $tag );
		if ( '' === $tag ) {
			return '';
		}
		return str_starts_with( $tag, '{' ) ? $tag : '{' . trim( $tag, '{}' ) . '}';
	}

	private function array_value( array $data, string $key ): array {
		return isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : [];
	}

	private function add( array &$findings, string $level, string $code, string $message, string $path = '' ): void {
		$findings[] = [
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
			'path'    => $path,
		];
	}

	private function has_level( array $findings, string $level ): bool {
		foreach ( $findings as $finding ) {
			if ( $level === (string) ( $finding['level'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private function counts( array $findings ): array {
		$counts = [ 'fail' => 0, 'warn' => 0, 'info' => 0 ];
		foreach ( $findings as $finding ) {
			$level = (string) ( $finding['level'] ?? 'info' );
			if ( isset( $counts[ $level ] ) ) {
				$counts[ $level ]++;
			}
		}
		return $counts;
	}
}
