<?php

namespace DSA\Site_Graph;

use DSA\AI\Site_Graph_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces one framework-neutral design evidence packet for live tools and
 * file-only browser AIs. It contains public content and builder capabilities,
 * never credentials, visitor state, orders, drafts, filesystem paths or writes.
 */
final class Design_Context_Service {
	private const PUBLIC_MAX_PRODUCTS = 100;
	private const PUBLIC_MAX_MEDIA    = 100;
	private const ADMIN_MAX_PRODUCTS  = 200;
	private const ADMIN_MAX_MEDIA     = 500;
	private const CONTENT_MAX         = 100;

	public function __construct( private Site_Graph_Service $site_graph, private ?Data_Query_Service $data = null ) {
		$this->data = $this->data ?: new Data_Query_Service();
	}

	public function context( array $args = [], bool $administrator = false ): array {
		$product_limit = $this->bounded( $args['productLimit'] ?? 24, 0, $administrator ? self::ADMIN_MAX_PRODUCTS : self::PUBLIC_MAX_PRODUCTS );
		$media_limit   = $this->bounded( $args['mediaLimit'] ?? 48, 0, $administrator ? self::ADMIN_MAX_MEDIA : self::PUBLIC_MAX_MEDIA );
		$content_limit = $this->bounded( $args['contentLimit'] ?? 12, 0, self::CONTENT_MAX );
		$media_search  = substr( trim( sanitize_text_field( (string) ( $args['mediaSearch'] ?? $args['search'] ?? '' ) ) ), 0, 200 );
		$resources     = isset( $args['resources'] ) && is_array( $args['resources'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $args['resources'] ) ) ) )
			: [ 'site', 'menus', 'products', 'media', 'pages', 'posts', 'terms' ];
		$allows        = static fn( string $resource ): bool => in_array( $resource, $resources, true );

		// Design context is public-only even when an administrator downloads it.
		// Administrator mode raises export size budgets; it never unlocks drafts.
		$graph = $this->site_graph->graph( [ 'sampleLimit' => 0, 'publicOnly' => true ] );
		$site  = $allows( 'site' ) ? $this->data->query( [ 'resource' => 'site' ], false ) : [ 'data' => [] ];
		$menus = $allows( 'menus' ) ? $this->data->query( [ 'resource' => 'menus' ], false ) : [ 'data' => [] ];
		$products = $allows( 'products' ) && $product_limit ? $this->collect(
			'products',
			$product_limit,
			[
				'fields' => [ 'id', 'type', 'slug', 'status', 'title', 'url', 'excerpt', 'featuredImage', 'terms', 'product' ],
			],
			false
		) : [];
		$media = $allows( 'media' ) && $media_limit ? $this->collect(
			'media',
			$media_limit,
			[
				'mimeType' => 'image',
				'search'   => $media_search,
			],
			false
		) : [];
		$pages = $allows( 'pages' ) && $content_limit ? $this->collect( 'pages', $content_limit, [ 'fields' => [ 'id', 'type', 'slug', 'title', 'url', 'excerpt', 'featuredImage', 'terms' ] ], false ) : [];
		$posts = $allows( 'posts' ) && $content_limit ? $this->collect( 'posts', $content_limit, [ 'fields' => [ 'id', 'type', 'slug', 'title', 'url', 'excerpt', 'date', 'featuredImage', 'terms' ] ], false ) : [];
		$product_categories = $allows( 'terms' ) && taxonomy_exists( 'product_cat' ) ? $this->data->query( [ 'resource' => 'terms', 'taxonomy' => 'product_cat', 'limit' => 100 ], false ) : [ 'data' => [] ];
		$product_tags       = $allows( 'terms' ) && taxonomy_exists( 'product_tag' ) ? $this->data->query( [ 'resource' => 'terms', 'taxonomy' => 'product_tag', 'limit' => 100 ], false ) : [ 'data' => [] ];

		$catalog = [
			'site'       => is_array( $site['data'] ?? null ) ? $site['data'] : [],
			'menus'      => is_array( $menus['data'] ?? null ) ? $menus['data'] : [],
			'products'   => $products,
			'media'      => $media,
			'pages'      => $pages,
			'posts'      => $posts,
			'productCategories' => is_array( $product_categories['data'] ?? null ) ? $product_categories['data'] : [],
			'productTags'       => is_array( $product_tags['data'] ?? null ) ? $product_tags['data'] : [],
		];

		return [
			'schema'      => 'kiwe.sitegraph-design-context.v1',
			'generatedAt' => gmdate( 'c' ),
			'authority'   => [
				'source'              => esc_url_raw( home_url( '/' ) ),
				'live'                => true,
				'publicDataOnly'      => true,
				'readOnly'            => true,
				'mayMutateWordPress'  => false,
				'mayPublish'          => false,
				'credentialsIncluded' => false,
				'privateContentIncluded' => false,
				'ordersIncluded'      => false,
				'visitorDataIncluded' => false,
			],
			'siteGraph'   => [
				'site'           => is_array( $graph['site'] ?? null ) ? $graph['site'] : [],
				'wordpress'      => [
					'postTypes'  => $this->without_samples( is_array( $graph['wordpress']['postTypes'] ?? null ) ? $graph['wordpress']['postTypes'] : [] ),
					'taxonomies' => $this->without_samples( is_array( $graph['wordpress']['taxonomies'] ?? null ) ? $graph['wordpress']['taxonomies'] : [] ),
				],
				'woocommerce'    => is_array( $graph['woocommerce'] ?? null ) ? $graph['woocommerce'] : [],
				'bricks'         => is_array( $graph['bricks'] ?? null ) ? $graph['bricks'] : [],
				'kiwe'           => [
					'version'      => sanitize_text_field( (string) ( $graph['kiwe']['version'] ?? '' ) ),
					'modules'      => is_array( $graph['kiwe']['modules'] ?? null ) ? $graph['kiwe']['modules'] : [],
					'bricksBridge' => is_array( $graph['kiwe']['bricksBridge'] ?? null ) ? $graph['kiwe']['bricksBridge'] : [],
				],
				'bindingTargets' => is_array( $graph['bindingTargets'] ?? null ) ? $graph['bindingTargets'] : [],
				'calibration'    => is_array( $graph['calibration'] ?? null ) ? $graph['calibration'] : [],
			],
			'catalog'     => $catalog,
			'catalogHash' => hash( 'sha256', (string) wp_json_encode( $catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'counts'      => array_map( 'count', array_filter( $catalog, 'is_array' ) ),
			'query'       => [
				'mediaSearch'  => $media_search,
				'productLimit' => $product_limit,
				'mediaLimit'   => $media_limit,
				'contentLimit' => $content_limit,
				'resources'    => $resources,
			],
			'rawSourceContract' => [
				'staticMedia' => [ 'preserve public URL', 'add data-kiwe-media-id', 'add stable source selector', 'record attachment ownership in bindings' ],
				'dynamicPreview' => [ 'real records may render in preview', 'production regions remain dynamic', 'add data-kiwe-query-template or data-kiwe-dynamic-field markers', 'emit bricks-bindings/kiwe-bindings.json' ],
				'compiler' => [ 'SEAM Compiler consumes only declared selectors and target-proven tags/query types', 'unmatched or ambiguous targets require human review', 'no Seam Framework requirement' ],
			],
			'usage'       => [
				'command' => '/usesitegraph /for /designcontext',
				'fileOnlyCommand' => '/usesitegraph /for /designcontext /nonai',
				'entityScopes' => [ '/products', '/posts', '/pages', '/media', '/menus' ],
				'fieldScopes'  => [ '/titles', '/images', '/prices', '/links', '/excerpts', '/metadata' ],
				'rule' => 'Use this evidence to improve design choices and binding precision without redesigning an approved artifact or hardcoding production collections.',
			],
		];
	}

	private function collect( string $resource, int $limit, array $args, bool $private ): array {
		$out       = [];
		$page      = 1;
		$remaining = $limit;
		$page_size = $private ? 200 : 100;
		while ( $remaining > 0 ) {
			$take = min( $page_size, $remaining );
			$result = $this->data->query( [ 'resource' => $resource, 'limit' => $take, 'page' => $page ] + $args, $private );
			$nodes  = is_array( $result['data'] ?? null ) ? $result['data'] : [];
			foreach ( $nodes as $node ) {
				if ( is_array( $node ) ) {
					$out[] = $node;
				}
			}
			$remaining -= count( $nodes );
			$total_pages = absint( $result['pageInfo']['totalPages'] ?? 1 );
			if ( [] === $nodes || $page >= $total_pages || count( $nodes ) < $take ) {
				break;
			}
			++$page;
		}

		return array_slice( $out, 0, $limit );
	}

	private function without_samples( array $records ): array {
		return array_map(
			static function ( $record ) {
				if ( is_array( $record ) ) {
					unset( $record['samples'] );
				}
				return $record;
			},
			$records
		);
	}

	private function bounded( $value, int $minimum, int $maximum ): int {
		return max( $minimum, min( $maximum, absint( $value ) ) );
	}
}
