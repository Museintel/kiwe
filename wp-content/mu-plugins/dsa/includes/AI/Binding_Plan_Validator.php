<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Binding_Plan_Validator {
	private const REQUIRED_ROOT_KEYS = [
		'schema',
		'siteGraphSchema',
		'target',
		'queries',
		'dynamicFields',
		'launchers',
		'menuContext',
		'assumptions',
		'requiresHumanReview',
	];

	private const KNOWN_DSA_MODULES = [
		'menu',
		'search',
		'profile',
		'links',
		'saved',
		'cart',
		'checkout',
		'theme',
		'ai',
		'notifications',
		'ios-install',
		'games',
	];

	private const COMMON_DYNAMIC_TAGS = [
		'{post_title}',
		'{post_content}',
		'{post_excerpt}',
		'{post_date}',
		'{post_url}',
		'{post_id}',
		'{post_author}',
		'{featured_image}',
		'{author_name}',
		'{author_url}',
		'{author_bio}',
		'{author_avatar}',
		'{site_title}',
		'{site_tagline}',
		'{site_url}',
		'{term_name}',
		'{term_description}',
	];

	public function validate( array $binding, array $site_graph ): array {
		$findings = [];
		$index    = $this->graph_index( $site_graph );

		$this->validate_root( $binding, $findings );
		$this->validate_queries( $binding, $findings, $index );
		$this->validate_dynamic_fields( $binding, $findings, $index );
		$this->validate_launchers( $binding, $findings, $index );
		$this->validate_menu_context( $binding, $findings );
		$this->validate_review_discipline( $binding, $findings );

		return [
			'ok'       => ! $this->has_level( $findings, 'fail' ),
			'counts'   => $this->counts( $findings ),
			'summary'  => [
				'queries'             => count( $this->array_value( $binding, 'queries' ) ),
				'dynamicFields'       => count( $this->array_value( $binding, 'dynamicFields' ) ),
				'launchers'           => count( $this->array_value( $binding, 'launchers' ) ),
				'menuContext'         => count( $this->array_value( $binding, 'menuContext' ) ),
				'assumptions'         => count( $this->array_value( $binding, 'assumptions' ) ),
				'requiresHumanReview' => count( $this->array_value( $binding, 'requiresHumanReview' ) ),
			],
			'findings' => $findings,
		];
	}

	private function graph_index( array $site_graph ): array {
		$index = [
			'postTypes'   => [],
			'taxonomies'  => [],
			'queryTypes'  => [],
			'dynamicTags' => array_fill_keys( self::COMMON_DYNAMIC_TAGS, true ),
			'dsaModules'  => array_fill_keys( self::KNOWN_DSA_MODULES, true ),
		];

		foreach ( $this->array_value( $site_graph['wordpress'] ?? [], 'postTypes' ) as $post_type ) {
			if ( is_array( $post_type ) && ! empty( $post_type['name'] ) ) {
				$index['postTypes'][ (string) $post_type['name'] ] = true;
			}
		}

		foreach ( $this->array_value( $site_graph['wordpress'] ?? [], 'taxonomies' ) as $taxonomy ) {
			if ( is_array( $taxonomy ) && ! empty( $taxonomy['name'] ) ) {
				$this->add_taxonomy_terms( $index, (string) $taxonomy['name'], $this->array_value( $taxonomy, 'terms' ) );
			}
		}

		$this->add_taxonomy_terms( $index, 'product_cat', $this->array_value( $site_graph['woocommerce'] ?? [], 'productCategories' ) );
		$this->add_taxonomy_terms( $index, 'product_tag', $this->array_value( $site_graph['woocommerce'] ?? [], 'productTags' ) );

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

		$modules = $site_graph['kiwe']['modules'] ?? [];
		if ( is_array( $modules ) ) {
			foreach ( $this->array_value( $modules, 'items' ) as $module ) {
				if ( is_array( $module ) && ! empty( $module['id'] ) ) {
					$index['dsaModules'][ (string) $module['id'] ] = true;
				}
			}
		}

		return $index;
	}

	private function add_taxonomy_terms( array &$index, string $taxonomy, array $terms ): void {
		if ( '' === $taxonomy ) {
			return;
		}
		if ( ! isset( $index['taxonomies'][ $taxonomy ] ) ) {
			$index['taxonomies'][ $taxonomy ] = [];
		}
		foreach ( $terms as $term ) {
			if ( is_array( $term ) && isset( $term['id'] ) ) {
				$index['taxonomies'][ $taxonomy ][ (string) absint( $term['id'] ) ] = true;
			}
		}
	}

	private function validate_root( array $binding, array &$findings ): void {
		foreach ( self::REQUIRED_ROOT_KEYS as $key ) {
			if ( ! array_key_exists( $key, $binding ) ) {
				$this->add( $findings, 'fail', sprintf( 'kiwe-bindings.json missing required key: %s', $key ) );
			}
		}

		if ( 'kiwe.bricks-bindings.v1' !== ( $binding['schema'] ?? '' ) ) {
			$this->add( $findings, 'fail', 'schema must be kiwe.bricks-bindings.v1.' );
		}

		if ( 'kiwe.site-graph.v1' !== ( $binding['siteGraphSchema'] ?? '' ) ) {
			$this->add( $findings, 'warn', 'siteGraphSchema should be kiwe.site-graph.v1.' );
		}

		$target = $binding['target'] ?? [];
		if ( ! is_array( $target ) ) {
			$this->add( $findings, 'fail', 'target must be an object.' );
			return;
		}

		if ( 'bricks' !== ( $target['builder'] ?? '' ) ) {
			$this->add( $findings, 'warn', 'target.builder should be bricks.' );
		}

		if ( 'binding-plan' !== ( $target['mode'] ?? '' ) ) {
			$this->add( $findings, 'warn', 'target.mode should be binding-plan.' );
		}

		$authority = (string) ( $target['applyAuthority'] ?? '' );
		if ( '' === $authority ) {
			$this->add( $findings, 'fail', 'target.applyAuthority is required.' );
		} elseif ( ! preg_match( '/human|review|adapter|trusted|manual/i', $authority ) ) {
			$this->add( $findings, 'warn', 'target.applyAuthority should clearly indicate human review or a trusted Kiwe/Bricks adapter.' );
		}

		if ( preg_match( '/auto|direct|mutat|write|save|publish/i', $authority ) && ! preg_match( '/human|review|adapter|trusted/i', $authority ) ) {
			$this->add( $findings, 'fail', 'target.applyAuthority must not claim direct write/save/publish authority from the binding plan itself.' );
		}

		foreach ( [ 'queries', 'dynamicFields', 'launchers', 'menuContext', 'assumptions', 'requiresHumanReview' ] as $key ) {
			if ( array_key_exists( $key, $binding ) && ! is_array( $binding[ $key ] ) ) {
				$this->add( $findings, 'fail', sprintf( '%s must be an array.', $key ) );
			}
		}
	}

	private function validate_queries( array $binding, array &$findings, array $index ): void {
		$seen = [];
		foreach ( $this->array_value( $binding, 'queries' ) as $position => $query ) {
			if ( ! is_array( $query ) ) {
				$this->add( $findings, 'fail', sprintf( 'queries[%d] must be an object.', $position ) );
				continue;
			}

			$id = (string) ( $query['id'] ?? '' );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $id ) ) {
				$this->add( $findings, 'fail', sprintf( 'queries[%d].id must be a stable slug-like id.', $position ) );
			} elseif ( isset( $seen[ $id ] ) ) {
				$this->add( $findings, 'fail', sprintf( 'Duplicate query id "%s".', $id ) );
			}
			$seen[ $id ] = true;

			if ( empty( $query['label'] ) ) {
				$this->add( $findings, 'warn', sprintf( 'queries[%d].label is missing.', $position ) );
			}
			if ( empty( $query['selector'] ) ) {
				$this->add( $findings, 'warn', sprintf( 'queries[%d].selector is missing.', $position ) );
			}

			$bricks = $query['bricks'] ?? null;
			if ( ! is_array( $bricks ) ) {
				$this->add( $findings, 'fail', sprintf( 'queries[%d].bricks must be an object.', $position ) );
				continue;
			}

			$object_type = (string) ( $bricks['objectType'] ?? '' );
			if ( '' === $object_type ) {
				$this->add( $findings, 'fail', sprintf( 'queries[%d].bricks.objectType is required.', $position ) );
			} elseif ( $index['queryTypes'] && ! isset( $index['queryTypes'][ $object_type ] ) ) {
				$this->add( $findings, 'warn', sprintf( 'queries[%d].bricks.objectType "%s" is not present in this Site Graph.', $position, $object_type ) );
			}

			if ( 'post' === $object_type ) {
				$post_types = $this->array_value( $bricks, 'post_type' );
				if ( [] === $post_types ) {
					$this->add( $findings, 'warn', sprintf( 'queries[%d].bricks.post_type should be set for post/product loops.', $position ) );
				}
				foreach ( $post_types as $post_type ) {
					if ( $index['postTypes'] && ! isset( $index['postTypes'][ (string) $post_type ] ) ) {
						$this->add( $findings, 'warn', sprintf( 'queries[%d].bricks.post_type "%s" is not present in this Site Graph.', $position, (string) $post_type ) );
					}
				}
			}

			foreach ( [ 'tax_query', 'tax_query_not' ] as $field ) {
				foreach ( $this->array_value( $bricks, $field ) as $value ) {
					$text = (string) $value;
					if ( ! preg_match( '/^([a-z0-9_-]+)::(\d+)$/i', $text, $match ) ) {
						$this->add( $findings, 'fail', sprintf( 'queries[%d].bricks.%s value "%s" must use taxonomy::term_id.', $position, $field, $text ) );
						continue;
					}
					$taxonomy = $match[1];
					$term_id  = (string) absint( $match[2] );
					if ( $index['taxonomies'] && ( ! isset( $index['taxonomies'][ $taxonomy ] ) || ! isset( $index['taxonomies'][ $taxonomy ][ $term_id ] ) ) ) {
						$this->add( $findings, 'warn', sprintf( 'queries[%d].bricks.%s term "%s" is not present in this Site Graph sample.', $position, $field, $text ) );
					}
				}
			}

			$this->validate_dynamic_tags_in_value( $query['bindings'] ?? [], sprintf( 'queries[%d].bindings', $position ), $findings, $index );
		}
	}

	private function validate_dynamic_fields( array $binding, array &$findings, array $index ): void {
		foreach ( $this->array_value( $binding, 'dynamicFields' ) as $position => $field ) {
			if ( ! is_array( $field ) ) {
				$this->add( $findings, 'fail', sprintf( 'dynamicFields[%d] must be an object.', $position ) );
				continue;
			}
			foreach ( [ 'selector', 'field', 'tag' ] as $key ) {
				if ( empty( $field[ $key ] ) ) {
					$this->add( $findings, 'warn', sprintf( 'dynamicFields[%d].%s is missing.', $position, $key ) );
				}
			}
			$this->validate_dynamic_tags_in_value( (string) ( $field['tag'] ?? '' ), sprintf( 'dynamicFields[%d].tag', $position ), $findings, $index );
		}
	}

	private function validate_launchers( array $binding, array &$findings, array $index ): void {
		foreach ( $this->array_value( $binding, 'launchers' ) as $position => $launcher ) {
			if ( ! is_array( $launcher ) ) {
				$this->add( $findings, 'fail', sprintf( 'launchers[%d] must be an object.', $position ) );
				continue;
			}
			if ( 'data-dsa-open-module' !== ( $launcher['attribute'] ?? '' ) ) {
				$this->add( $findings, 'fail', sprintf( 'launchers[%d].attribute must be data-dsa-open-module.', $position ) );
			}
			$value = (string) ( $launcher['value'] ?? '' );
			if ( '' === $value ) {
				$this->add( $findings, 'fail', sprintf( 'launchers[%d].value is required.', $position ) );
			} elseif ( ! isset( $index['dsaModules'][ $value ] ) ) {
				$this->add( $findings, 'warn', sprintf( 'launchers[%d].value "%s" is not a known Kiwe module in this Site Graph.', $position, $value ) );
			}
		}
	}

	private function validate_menu_context( array $binding, array &$findings ): void {
		foreach ( $this->array_value( $binding, 'menuContext' ) as $position => $item ) {
			if ( ! is_array( $item ) ) {
				$this->add( $findings, 'fail', sprintf( 'menuContext[%d] must be an object.', $position ) );
				continue;
			}
			if ( empty( $item['label'] ) ) {
				$this->add( $findings, 'warn', sprintf( 'menuContext[%d].label is missing.', $position ) );
			}
			if ( ! empty( $item['selector'] ) && preg_match( '/hidden|sr-only|display\s*:\s*none/i', (string) $item['selector'] ) ) {
				$this->add( $findings, 'warn', sprintf( 'menuContext[%d] appears to target hidden navigation data. Use visible sections/headings/Seam semantics.', $position ) );
			}
		}
	}

	private function validate_review_discipline( array $binding, array &$findings ): void {
		$text   = wp_json_encode( $binding );
		$review = $this->array_value( $binding, 'requiresHumanReview' );
		if ( is_string( $text ) && preg_match( '/unknown|guess|placeholder|todo|tbd|not sure|assume/i', $text ) && [] === $review ) {
			$this->add( $findings, 'warn', 'Binding plan contains uncertainty language but requiresHumanReview is empty.' );
		}
		if ( is_string( $text ) && preg_match( '/fetch\s*\(|XMLHttpRequest|localStorage|sessionStorage|serviceWorker|checkout session|stripe|razorpay|paypal/i', $text ) ) {
			$this->add( $findings, 'warn', 'Binding plan references runtime/payment/storage code. Dynamic bindings must remain a Bricks/Kiwe/Woo plan, not a custom app runtime.' );
		}
	}

	private function validate_dynamic_tags_in_value( $value, string $path, array &$findings, array $index ): void {
		foreach ( $this->extract_dynamic_tags( $value ) as $tag ) {
			$base = preg_replace( '/\s+@[^}]+/', '', $tag );
			$base = preg_replace( '/:[^}:]+(?=})/', '', (string) $base );
			if ( isset( $index['dynamicTags'][ $tag ] ) || isset( $index['dynamicTags'][ $base ] ) ) {
				continue;
			}
			$this->add( $findings, 'warn', sprintf( '%s references dynamic tag %s, which was not found in this Site Graph.', $path, $tag ) );
		}
	}

	private function extract_dynamic_tags( $value ): array {
		$out = [];
		if ( is_string( $value ) ) {
			if ( preg_match_all( '/\{[^{}]+\}/', $value, $matches ) ) {
				foreach ( $matches[0] as $match ) {
					$out[ $match ] = true;
				}
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				foreach ( $this->extract_dynamic_tags( $item ) as $tag ) {
					$out[ $tag ] = true;
				}
			}
		}

		return array_keys( $out );
	}

	private function normalize_dynamic_tag( string $tag ): string {
		$tag = trim( $tag );
		if ( '' === $tag ) {
			return '';
		}
		return str_starts_with( $tag, '{' ) ? $tag : '{' . trim( $tag, '{}' ) . '}';
	}

	private function array_value( array $source, string $key ): array {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : [];
	}

	private function add( array &$findings, string $level, string $message ): void {
		$findings[] = [
			'level'   => $level,
			'message' => $message,
		];
	}

	private function has_level( array $findings, string $level ): bool {
		foreach ( $findings as $finding ) {
			if ( is_array( $finding ) && $level === ( $finding['level'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private function counts( array $findings ): array {
		$out = [];
		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$level         = (string) ( $finding['level'] ?? 'info' );
			$out[ $level ] = ( $out[ $level ] ?? 0 ) + 1;
		}
		return $out;
	}
}
