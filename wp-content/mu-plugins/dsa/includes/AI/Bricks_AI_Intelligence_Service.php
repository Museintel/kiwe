<?php

namespace DSA\AI;

use DSA\Design\Seam_Token_Service;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bricks_AI_Intelligence_Service {
	private const DEFAULT_ELEMENTS = [
		'section',
		'container',
		'block',
		'div',
		'heading',
		'text-basic',
		'text',
		'image',
		'button',
		'icon',
		'filter-search',
		'query-results-summary',
		'carousel',
		'accordion',
		'accordion-nested',
		'tabs-nested',
		'post-title',
		'post-excerpt',
		'post-featured-image',
		'product-title',
		'product-price',
		'product-add-to-cart',
		'product-upsells',
		'woocommerce-mini-cart',
	];

	public function __construct( private Settings $settings ) {}

	public function context( array $args = [] ): array {
		$elements = $this->requested_elements( $args );
		$settings = $this->settings->all();
		$tokens = Seam_Token_Service::tokens_with_overrides( Seam_Token_Service::overrides_from_settings( $settings ) );

		return [
			'ok'               => true,
			'schema'           => 'kiwe.bricks-ai-intelligence.v1',
			'generatedAt'      => gmdate( 'c' ),
			'bricks'           => [
				'active'             => $this->bricks_active(),
				'version'            => defined( 'BRICKS_VERSION' ) ? sanitize_text_field( (string) BRICKS_VERSION ) : '',
				'abilitiesAvailable' => class_exists( '\Bricks\Abilities\Reference' ),
				'elementsAvailable'  => class_exists( '\Bricks\Elements' ),
				'interactionsAvailable' => class_exists( '\Bricks\Interactions' ),
				'conditionsAvailable'   => class_exists( '\Bricks\Conditions' ),
			],
			'elements'         => $this->list_elements( $args ),
			'elementSchemas'   => $this->element_schemas( $elements ),
			'queryLoops'       => $this->query_loop_types(),
			'dynamicDataTags'  => $this->dynamic_data_tags( $args ),
			'interactions'     => $this->interaction_controls(),
			'conditions'       => $this->condition_controls(),
			'seam'             => [
				'schema'       => 'kiwe.seam.headless.v1',
				'tokenCount'   => count( $tokens ),
				'tokenCounts'  => Seam_Token_Service::counts( $tokens ),
				'classRules'   => [
					'Use Seam classes/attributes for meaning and reusable structure.',
					'Do not make Seam roles own default padding, radius, shadow, or theme identity.',
					'Bricks element settings and page/theme CSS own the visual design.',
					'Preserve data-role, data-seam-*, data-dsa-open-module, IDs, ARIA, and classes through conversion.',
				],
			],
			'kiwe'             => [
				'launcherAttribute' => 'data-dsa-open-module',
				'knownModules'      => [ 'menu', 'search', 'profile', 'links', 'saved', 'cart', 'ai', 'theme' ],
				'bricksControls'    => [
					'iconLauncher' => 'Bricks Icon elements can receive a Kiwe DSA launcher control when enabled.',
					'filterSearch' => 'Bricks Filter Search can bridge to Kiwe Search when the DSA bridge control is enabled.',
					'productAddToCart' => 'Woo product add-to-cart runtime remains Woo/Kiwe-owned; AI should not recreate cart behavior.',
				],
			],
			'toolUseRules'     => $this->tool_use_rules(),
		];
	}

	public function planning_packet( array $args = [] ): array {
		$brief = $this->clean_text( (string) ( $args['brief'] ?? $args['prompt'] ?? '' ), 12000 );
		$context = $this->context( $args );
		$intent = $this->infer_intent( $brief );

		return [
			'ok'        => true,
			'schema'    => 'kiwe.bricks-ai-plan.v1',
			'brief'     => $brief,
			'intent'    => $intent,
			'context'   => $context,
			'plan'      => [
				'preferredArtifact' => 'Bricks-native element tree plus safe page CSS and optional Framework profile',
				'elementStrategy'   => $this->element_strategy( $intent ),
				'queryStrategy'     => $this->query_strategy( $intent ),
				'dynamicData'       => [
					'rule' => 'Use Bricks dynamic tags from the live context. Do not invent tags; use Kiwe Site Graph/Data API for real IDs and terms.',
				],
				'interactions'      => [
					'rule' => 'Use Bricks _interactions only for UI animation/toggle/load-more behaviors; never use interactions to run Kiwe cart/checkout/auth.',
				],
				'conditions'        => [
					'rule' => 'Use Bricks _conditions for element visibility such as logged-in state, post type, taxonomy, viewport, or commerce context when documented by Bricks controls.',
				],
				'seam'              => [
					'rule' => 'Every semantic section/card/rail/tab/TOC candidate should carry Seam vocabulary and stable classes while visual style remains in Bricks settings or page CSS.',
				],
			],
		];
	}

	private function list_elements( array $args ): array {
		if ( class_exists( '\Bricks\Abilities\Reference' ) && method_exists( '\Bricks\Abilities\Reference', 'list_element_types' ) ) {
			try {
				$result = \Bricks\Abilities\Reference::list_element_types(
					[
						'page'    => max( 1, absint( $args['page'] ?? 1 ) ),
						'perPage' => max( 10, min( 200, absint( $args['perPage'] ?? 120 ) ) ),
					]
				);
				if ( is_array( $result ) ) {
					return $this->sanitize_list_result( $result );
				}
			} catch ( \Throwable $error ) {
				return [ 'items' => [], 'error' => $this->error( $error ) ];
			}
		}

		if ( class_exists( '\Bricks\Elements' ) && isset( \Bricks\Elements::$elements ) && is_array( \Bricks\Elements::$elements ) ) {
			$items = [];
			foreach ( \Bricks\Elements::$elements as $name => $element ) {
				if ( ! is_array( $element ) || ! empty( $element['deprecated'] ) ) {
					continue;
				}
				$items[] = [
					'name'     => sanitize_key( (string) $name ),
					'label'    => sanitize_text_field( (string) ( $element['label'] ?? $name ) ),
					'category' => sanitize_text_field( (string) ( $element['category'] ?? '' ) ),
					'nestable' => ! empty( $element['nestable'] ),
				];
				if ( count( $items ) >= 200 ) {
					break;
				}
			}
			return [
				'items'   => $items,
				'total'   => count( $items ),
				'hasMore' => count( $items ) >= 200,
				'source'  => 'Bricks\\Elements::$elements',
			];
		}

		return [
			'items'  => array_map(
				static fn( string $name ): array => [
					'name'     => $name,
					'label'    => ucwords( str_replace( [ '-', '_' ], ' ', $name ) ),
					'category' => 'fallback',
				],
				self::DEFAULT_ELEMENTS
			),
			'total'  => count( self::DEFAULT_ELEMENTS ),
			'source' => 'kiwe-static-fallback',
		];
	}

	private function element_schemas( array $element_names ): array {
		$out = [];
		foreach ( array_slice( $element_names, 0, 36 ) as $name ) {
			$schema = $this->element_schema( $name );
			if ( [] !== $schema ) {
				$out[ $name ] = $schema;
			}
		}

		return $out;
	}

	private function element_schema( string $name ): array {
		$name = sanitize_key( $name );
		if ( '' === $name ) {
			return [];
		}

		if ( class_exists( '\Bricks\Abilities\Reference' ) && method_exists( '\Bricks\Abilities\Reference', 'get_element_schema' ) ) {
			try {
				$schema = \Bricks\Abilities\Reference::get_element_schema( [ 'elementName' => $name ] );
				if ( is_array( $schema ) ) {
					return $this->compact_element_schema( $schema, 'bricks-ability' );
				}
			} catch ( \Throwable $error ) {
				return [ 'name' => $name, 'error' => $this->error( $error ) ];
			}
		}

		if ( class_exists( '\Bricks\Elements' ) && method_exists( '\Bricks\Elements', 'get_element' ) ) {
			try {
				$schema = \Bricks\Elements::get_element( [ 'name' => $name ] );
				if ( is_array( $schema ) ) {
					return $this->compact_element_schema( $schema + [ 'name' => $name ], 'bricks-elements' );
				}
			} catch ( \Throwable $error ) {
				return [ 'name' => $name, 'error' => $this->error( $error ) ];
			}
		}

		return [
			'name'     => $name,
			'label'    => ucwords( str_replace( [ '-', '_' ], ' ', $name ) ),
			'source'   => 'kiwe-static-fallback',
			'controls' => [],
		];
	}

	private function query_loop_types(): array {
		if ( class_exists( '\Bricks\Abilities\Reference' ) && method_exists( '\Bricks\Abilities\Reference', 'list_query_loop_types' ) ) {
			try {
				$result = \Bricks\Abilities\Reference::list_query_loop_types( [] );
				if ( is_array( $result ) ) {
					return $this->sanitize_list_result( $result );
				}
			} catch ( \Throwable $error ) {
				return [ 'items' => [], 'error' => $this->error( $error ) ];
			}
		}

		if ( class_exists( '\Bricks\Setup' ) && method_exists( '\Bricks\Setup', 'get_control_options' ) ) {
			try {
				$options = \Bricks\Setup::get_control_options();
				$query_types = isset( $options['queryTypes'] ) && is_array( $options['queryTypes'] ) ? $options['queryTypes'] : [];
				$items = [];
				foreach ( $query_types as $object_type => $label ) {
					$items[] = [
						'objectType' => sanitize_key( (string) $object_type ),
						'label'      => sanitize_text_field( is_scalar( $label ) ? (string) $label : (string) ( $label['label'] ?? $object_type ) ),
					];
				}
				return [ 'items' => $items, 'total' => count( $items ), 'source' => 'Bricks\\Setup::get_control_options' ];
			} catch ( \Throwable $error ) {
				return [ 'items' => [], 'error' => $this->error( $error ) ];
			}
		}

		return [
			'items' => [
				[ 'objectType' => 'post', 'label' => 'Posts/pages/products/custom post types' ],
				[ 'objectType' => 'term', 'label' => 'Taxonomy terms' ],
				[ 'objectType' => 'user', 'label' => 'Users' ],
				[ 'objectType' => 'array', 'label' => 'Array/dynamic data' ],
			],
			'source' => 'kiwe-static-fallback',
		];
	}

	private function dynamic_data_tags( array $args ): array {
		if ( class_exists( '\Bricks\Abilities\Reference' ) && method_exists( '\Bricks\Abilities\Reference', 'list_dynamic_data_tags' ) ) {
			try {
				$result = \Bricks\Abilities\Reference::list_dynamic_data_tags(
					[
						'page'             => 1,
						'perPage'          => max( 40, min( 220, absint( $args['dynamicPerPage'] ?? 180 ) ) ),
						'includeModifiers' => ! empty( $args['includeModifiers'] ),
						'postId'           => isset( $args['postId'] ) ? absint( $args['postId'] ) : 0,
					]
				);
				if ( is_array( $result ) ) {
					return $this->sanitize_list_result( $result );
				}
			} catch ( \Throwable $error ) {
				return [ 'items' => [], 'error' => $this->error( $error ) ];
			}
		}

		if ( class_exists( '\Bricks\Integrations\Dynamic_Data\Providers' ) && method_exists( '\Bricks\Integrations\Dynamic_Data\Providers', 'get_dynamic_tags_list' ) ) {
			try {
				$tags = \Bricks\Integrations\Dynamic_Data\Providers::get_dynamic_tags_list();
				$items = [];
				foreach ( is_array( $tags ) ? $tags : [] as $tag ) {
					if ( ! is_array( $tag ) ) {
						continue;
					}
					$name = sanitize_text_field( (string) ( $tag['name'] ?? $tag['tag'] ?? '' ) );
					if ( '' === $name ) {
						continue;
					}
					$items[] = [
						'name'  => $name,
						'label' => sanitize_text_field( (string) ( $tag['label'] ?? $name ) ),
						'group' => sanitize_text_field( (string) ( $tag['group'] ?? '' ) ),
					];
					if ( count( $items ) >= 220 ) {
						break;
					}
				}
				return [ 'items' => $items, 'total' => count( $items ), 'source' => 'Bricks dynamic data providers' ];
			} catch ( \Throwable $error ) {
				return [ 'items' => [], 'error' => $this->error( $error ) ];
			}
		}

		return [
			'items' => [
				[ 'name' => '{post_title}', 'label' => 'Post title', 'group' => 'WP' ],
				[ 'name' => '{post_url}', 'label' => 'Post URL', 'group' => 'WP' ],
				[ 'name' => '{featured_image}', 'label' => 'Featured image', 'group' => 'WP' ],
				[ 'name' => '{woo_product_price}', 'label' => 'Woo product price', 'group' => 'WooCommerce' ],
				[ 'name' => '{kiwe_site_logo}', 'label' => 'Kiwe site logo', 'group' => 'Kiwe' ],
			],
			'source' => 'kiwe-static-fallback',
		];
	}

	private function interaction_controls(): array {
		if ( class_exists( '\Bricks\Interactions' ) && method_exists( '\Bricks\Interactions', 'get_controls_data' ) ) {
			try {
				$data = \Bricks\Interactions::get_controls_data();
				return [
					'source'   => 'Bricks\\Interactions::get_controls_data',
					'controls' => $this->compact_controls( is_array( $data ) ? ( $data['controls'] ?? $data ) : [] ),
					'rules'    => [
						'Use _interactions for Bricks UI behavior only.',
						'Do not trigger Kiwe cart, checkout, auth, or payment flows through Bricks interactions.',
					],
				];
			} catch ( \Throwable $error ) {
				return [ 'controls' => [], 'error' => $this->error( $error ) ];
			}
		}

		return [
			'source'   => 'kiwe-static-fallback',
			'controls' => [
				'trigger' => [ 'type' => 'select', 'label' => 'Trigger' ],
				'action'  => [ 'type' => 'select', 'label' => 'Action' ],
				'target'  => [ 'type' => 'select', 'label' => 'Target' ],
			],
		];
	}

	private function condition_controls(): array {
		if ( class_exists( '\Bricks\Conditions' ) && method_exists( '\Bricks\Conditions', 'get_controls_data' ) ) {
			try {
				$data = \Bricks\Conditions::get_controls_data();
				return [
					'source'   => 'Bricks\\Conditions::get_controls_data',
					'controls' => $this->compact_controls( is_array( $data ) ? ( $data['controls'] ?? $data ) : [] ),
					'rules'    => [
						'Element conditions are visibility gates, not data-fetching logic.',
						'Use live Site Graph post types, taxonomies, terms, and Woo state before proposing conditions.',
					],
				];
			} catch ( \Throwable $error ) {
				return [ 'controls' => [], 'error' => $this->error( $error ) ];
			}
		}

		return [
			'source'   => 'kiwe-static-fallback',
			'controls' => [
				'conditions' => [ 'type' => 'repeater', 'label' => 'Display conditions' ],
			],
		];
	}

	private function compact_element_schema( array $schema, string $source ): array {
		return [
			'name'          => sanitize_key( (string) ( $schema['name'] ?? '' ) ),
			'label'         => sanitize_text_field( (string) ( $schema['label'] ?? $schema['name'] ?? '' ) ),
			'category'      => sanitize_text_field( (string) ( $schema['category'] ?? '' ) ),
			'nestable'      => ! empty( $schema['nestable'] ),
			'source'        => $source,
			'controlGroups' => $this->compact_control_groups( isset( $schema['controlGroups'] ) && is_array( $schema['controlGroups'] ) ? $schema['controlGroups'] : [] ),
			'controls'      => $this->compact_controls( isset( $schema['controls'] ) && is_array( $schema['controls'] ) ? $schema['controls'] : [] ),
		];
	}

	private function compact_control_groups( array $groups ): array {
		$out = [];
		foreach ( $groups as $key => $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$out[ $this->safe_context_key( (string) $key ) ] = [
				'title' => sanitize_text_field( (string) ( $group['title'] ?? $group['label'] ?? $key ) ),
				'tab'   => sanitize_key( (string) ( $group['tab'] ?? '' ) ),
			];
			if ( count( $out ) >= 80 ) {
				break;
			}
		}

		return $out;
	}

	private function compact_controls( array $controls ): array {
		$out = [];
		foreach ( $controls as $key => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$item = [
				'type'  => sanitize_key( (string) ( $control['type'] ?? '' ) ),
				'label' => sanitize_text_field( (string) ( $control['label'] ?? $control['title'] ?? $key ) ),
				'tab'   => sanitize_key( (string) ( $control['tab'] ?? '' ) ),
				'group' => sanitize_key( (string) ( $control['group'] ?? '' ) ),
			];
			if ( isset( $control['required'] ) ) {
				$item['required'] = $this->safe_scalar_array( $control['required'] );
			}
			if ( isset( $control['options'] ) && is_array( $control['options'] ) ) {
				$item['options'] = $this->compact_options( $control['options'] );
			}
			$out[ $this->safe_context_key( (string) $key ) ] = $item;
			if ( count( $out ) >= 220 ) {
				break;
			}
		}

		return $out;
	}

	private function compact_options( array $options ): array {
		$out = [];
		foreach ( $options as $key => $value ) {
			$out[ sanitize_text_field( (string) $key ) ] = sanitize_text_field( is_scalar( $value ) ? (string) $value : (string) ( $value['label'] ?? $value['name'] ?? $key ) );
			if ( count( $out ) >= 80 ) {
				break;
			}
		}

		return $out;
	}

	private function sanitize_list_result( array $result ): array {
		$out = [];
		foreach ( $result as $key => $value ) {
			if ( in_array( $key, [ 'items', 'coreTypes', 'providerPrefixes', 'acfFlexibleQueryTypes', 'arrayConditionObjectTypes', 'modifiers', 'notes' ], true ) ) {
				$out[ $key ] = $this->sanitize_deep( $value );
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$out[ $this->safe_context_key( (string) $key ) ] = is_bool( $value ) ? $value : sanitize_text_field( (string) $value );
			}
		}
		$out['source'] = $out['source'] ?? 'bricks-ability';

		return $out;
	}

	private function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $key => $child ) {
				$out[ is_int( $key ) ? $key : sanitize_text_field( (string) $key ) ] = $this->sanitize_deep( $child );
			}
			return $out;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	private function safe_scalar_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return [ sanitize_text_field( (string) $value ) ];
		}
		$out = [];
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) || null === $item ) {
				$out[] = sanitize_text_field( (string) $item );
			}
		}

		return $out;
	}

	private function safe_context_key( string $key ): string {
		$key = trim( $key );
		$key = preg_replace( '/[^A-Za-z0-9_.:-]/', '', $key );
		$key = is_string( $key ) ? substr( $key, 0, 96 ) : '';

		return '' !== $key ? $key : 'key';
	}

	private function requested_elements( array $args ): array {
		$elements = isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : self::DEFAULT_ELEMENTS;
		$out = [];
		foreach ( $elements as $element ) {
			$element = sanitize_key( (string) $element );
			if ( '' !== $element ) {
				$out[] = $element;
			}
		}

		return array_values( array_unique( $out ) );
	}

	private function infer_intent( string $brief ): array {
		$lower = strtolower( $brief );
		return [
			'ecommerce' => str_contains( $lower, 'product' ) || str_contains( $lower, 'shop' ) || str_contains( $lower, 'cart' ) || str_contains( $lower, 'woocommerce' ),
			'editorial' => str_contains( $lower, 'news' ) || str_contains( $lower, 'blog' ) || str_contains( $lower, 'article' ) || str_contains( $lower, 'story' ),
			'landing'   => str_contains( $lower, 'hero' ) || str_contains( $lower, 'landing' ) || str_contains( $lower, 'homepage' ),
			'membership'=> str_contains( $lower, 'account' ) || str_contains( $lower, 'login' ) || str_contains( $lower, 'profile' ),
		];
	}

	private function element_strategy( array $intent ): array {
		$base = [ 'section', 'container', 'block', 'heading', 'text-basic', 'image', 'button', 'icon' ];
		if ( ! empty( $intent['editorial'] ) ) {
			$base = array_merge( $base, [ 'post-title', 'post-excerpt', 'post-featured-image', 'query-results-summary', 'filter-search' ] );
		}
		if ( ! empty( $intent['ecommerce'] ) ) {
			$base = array_merge( $base, [ 'product-title', 'product-price', 'product-add-to-cart', 'product-upsells', 'woocommerce-mini-cart' ] );
		}

		return [
			'preferredElements' => array_values( array_unique( $base ) ),
			'rule'              => 'Use Bricks-native elements whenever they carry native semantics/query/runtime behavior; use div/block only for neutral layout shells.',
		];
	}

	private function query_strategy( array $intent ): array {
		if ( ! empty( $intent['ecommerce'] ) ) {
			return [
				'objectType' => 'post',
				'postType'   => 'product',
				'rule'       => 'Use Bricks query loop with real product_cat term IDs from Site Graph, not preview product cards.',
			];
		}
		if ( ! empty( $intent['editorial'] ) ) {
			return [
				'objectType' => 'post',
				'postType'   => 'post',
				'rule'       => 'Use Bricks query loop with real categories/tags from Site Graph for rails and article grids.',
			];
		}

		return [
			'objectType' => 'post',
			'postType'   => 'page',
			'rule'       => 'Use query loops only when the section is data-driven; static hero/editorial copy can remain normal Bricks elements.',
		];
	}

	private function tool_use_rules(): array {
		return [
			'Use /wp-json/dsa/v1/ai/bricks/context before emitting Bricks JSON when an API key is available.',
			'Use /wp-json/dsa/v1/ai/site-graph and /ai/site-graph-data for real pages, products, posts, media, custom fields, post types, taxonomies, and term IDs.',
			'Use /wp-json/dsa/v1/ai/studio/start before native/browser-AI collaboration so the model sees Kiwe + Bricks + Seam boundaries in one packet.',
			'Never paste runtime cart/checkout/auth logic into Bricks; use Kiwe/WordPress/WooCommerce authority instead.',
		];
	}

	private function bricks_active(): bool {
		return defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Helpers' ) || class_exists( '\Bricks\Elements' ) || class_exists( '\Bricks\Setup' );
	}

	private function error( \Throwable $error ): array {
		return [
			'code'    => 'unavailable',
			'message' => sanitize_text_field( $error->getMessage() ),
		];
	}

	private function clean_text( string $value, int $limit ): string {
		$value = trim( function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $value ) : wp_strip_all_tags( $value ) );
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, max( 0, $limit - 28 ) ) . "\n...[trimmed by Kiwe]...";
	}
}
