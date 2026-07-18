<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Introspection_Service {
	public function inspect( array $args = [] ): array {
		$sample_limit = isset( $args['sampleLimit'] ) ? absint( $args['sampleLimit'] ) : 12;
		$sample_limit = max( 1, min( 48, $sample_limit ) );

		return [
			'schema'      => 'kiwe.ai-site-inspection.v1',
			'generatedAt' => gmdate( 'c' ),
			'site'        => [
				'homeUrl'         => esc_url_raw( home_url( '/' ) ),
				'environmentType' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( wp_get_environment_type() ) : '',
				'isLikelyStaging' => $this->is_likely_staging(),
			],
			'plugins'     => $this->plugins(),
			'bricks'      => $this->bricks( $sample_limit ),
			'wordpress'   => $this->wordpress( $sample_limit ),
			'guardrails'  => [
				'readOnly'           => true,
				'secretsRedacted'    => true,
				'rawBricksMetaHidden' => true,
				'writePath'          => 'Use /wp-json/dsa/v1/ai/staging/execute with explicit staging confirmation for allowed draft/staging operations.',
			],
		];
	}

	private function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active  = array_fill_keys( (array) get_option( 'active_plugins', [] ), true );
		$plugins = [];
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = [
				'file'        => sanitize_text_field( (string) $file ),
				'name'        => sanitize_text_field( (string) ( $data['Name'] ?? '' ) ),
				'version'     => sanitize_text_field( (string) ( $data['Version'] ?? '' ) ),
				'author'      => wp_strip_all_tags( (string) ( $data['AuthorName'] ?? $data['Author'] ?? '' ) ),
				'active'      => isset( $active[ $file ] ),
				'networkOnly' => ! empty( $data['Network'] ),
			];
		}

		return [
			'count'  => count( $plugins ),
			'active' => count( array_filter( $plugins, static fn( array $plugin ): bool => ! empty( $plugin['active'] ) ) ),
			'items'  => $plugins,
		];
	}

	private function bricks( int $sample_limit ): array {
		$active = defined( 'BRICKS_VERSION' ) || post_type_exists( 'bricks_template' ) || class_exists( '\Bricks\Helpers' );

		return [
			'active'       => $active,
			'version'      => defined( 'BRICKS_VERSION' ) ? sanitize_text_field( (string) BRICKS_VERSION ) : '',
			'constants'    => [
				'globalSettings'  => defined( 'BRICKS_DB_GLOBAL_SETTINGS' ) ? BRICKS_DB_GLOBAL_SETTINGS : 'bricks_global_settings',
				'themeStyles'     => defined( 'BRICKS_DB_THEME_STYLES' ) ? BRICKS_DB_THEME_STYLES : 'bricks_theme_styles',
				'templatePostType' => defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? BRICKS_DB_TEMPLATE_SLUG : 'bricks_template',
				'templateTypeMeta' => defined( 'BRICKS_DB_TEMPLATE_TYPE' ) ? BRICKS_DB_TEMPLATE_TYPE : '_bricks_template_type',
				'templateSettingsMeta' => defined( 'BRICKS_DB_TEMPLATE_SETTINGS' ) ? BRICKS_DB_TEMPLATE_SETTINGS : '_bricks_template_settings',
			],
			'htmlCssToBricks' => [
				'detected' => class_exists( '\Bricks\Html_To_Bricks_Converter' ) || class_exists( '\Bricks\Abilities\Conversion' ),
				'kiwePolicy' => 'Kiwe stores Bricks-ready HTML on staging targets first; raw Bricks JSON writes stay locked until a converter/ability save path is proven on that site.',
			],
			'settings'    => [
				'global'      => $this->safe_option_summary( defined( 'BRICKS_DB_GLOBAL_SETTINGS' ) ? BRICKS_DB_GLOBAL_SETTINGS : 'bricks_global_settings' ),
				'themeStyles' => $this->safe_option_summary( defined( 'BRICKS_DB_THEME_STYLES' ) ? BRICKS_DB_THEME_STYLES : 'bricks_theme_styles' ),
				'globalClasses' => $this->safe_option_summary( defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ? BRICKS_DB_GLOBAL_CLASSES : 'bricks_global_classes' ),
				'globalVariables' => $this->safe_option_summary( defined( 'BRICKS_DB_GLOBAL_VARIABLES' ) ? BRICKS_DB_GLOBAL_VARIABLES : 'bricks_global_variables' ),
			],
			'templates'   => $this->templates( $sample_limit ),
		];
	}

	private function wordpress( int $sample_limit ): array {
		return [
			'pages' => $this->posts( 'page', $sample_limit ),
			'posts' => $this->posts( 'post', $sample_limit ),
		];
	}

	private function templates( int $limit ): array {
		$post_type = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? BRICKS_DB_TEMPLATE_SLUG : 'bricks_template';
		if ( ! post_type_exists( $post_type ) ) {
			return [ 'postType' => $post_type, 'items' => [] ];
		}

		$ids = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);

		$type_key = defined( 'BRICKS_DB_TEMPLATE_TYPE' ) ? BRICKS_DB_TEMPLATE_TYPE : '_bricks_template_type';
		$out      = [];
		foreach ( is_array( $ids ) ? $ids : [] as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$out[] = [
				'id'       => absint( $id ),
				'title'    => sanitize_text_field( get_the_title( $id ) ),
				'slug'     => sanitize_title( $post->post_name ),
				'status'   => sanitize_key( $post->post_status ),
				'type'     => sanitize_key( (string) get_post_meta( $id, $type_key, true ) ),
				'modified' => sanitize_text_field( (string) $post->post_modified_gmt ),
			];
		}

		return [ 'postType' => $post_type, 'items' => $out ];
	}

	private function posts( string $post_type, int $limit ): array {
		if ( ! post_type_exists( $post_type ) ) {
			return [];
		}
		$ids = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);

		$out = [];
		foreach ( is_array( $ids ) ? $ids : [] as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$out[] = [
				'id'       => absint( $id ),
				'title'    => sanitize_text_field( get_the_title( $id ) ),
				'slug'     => sanitize_title( $post->post_name ),
				'status'   => sanitize_key( $post->post_status ),
				'url'      => 'publish' === $post->post_status ? esc_url_raw( get_permalink( $id ) ) : '',
				'modified' => sanitize_text_field( (string) $post->post_modified_gmt ),
				'kiweStaging' => (bool) get_post_meta( $id, '_kiwe_ai_staging_execution', true ),
			];
		}

		return $out;
	}

	private function safe_option_summary( string $option ): array {
		$value = get_option( $option, null );
		$json  = wp_json_encode( $value );
		$keys  = is_array( $value ) ? array_keys( $value ) : [];

		return [
			'option' => sanitize_key( $option ),
			'type'   => gettype( $value ),
			'keys'   => array_values( array_map( 'sanitize_text_field', array_slice( $keys, 0, 80 ) ) ),
			'count'  => is_array( $value ) ? count( $value ) : 0,
			'hash'   => is_string( $json ) ? hash( 'sha256', $json ) : '',
			'safeScalars' => is_array( $value ) ? $this->safe_scalars( $value ) : [],
		];
	}

	private function safe_scalars( array $value ): array {
		$out = [];
		foreach ( $value as $key => $item ) {
			$key = (string) $key;
			if ( preg_match( '/key|secret|token|license|password|nonce|script|code|css/i', $key ) ) {
				continue;
			}
			if ( is_scalar( $item ) || null === $item ) {
				$out[ sanitize_key( $key ) ] = sanitize_text_field( substr( (string) $item, 0, 120 ) );
			}
			if ( count( $out ) >= 32 ) {
				break;
			}
		}

		return $out;
	}

	private function is_likely_staging(): bool {
		$env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';

		return in_array( $env, [ 'local', 'development', 'staging' ], true ) || (bool) preg_match( '/(^|[.-])(staging|stage|dev|test|sandbox|hostingersite)([.-]|$)/', $host );
	}
}
