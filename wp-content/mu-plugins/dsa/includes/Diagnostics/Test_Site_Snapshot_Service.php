<?php

namespace DSA\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hash-verified, same-site rollback for Bricks conversion acceptance work.
 *
 * The snapshot owns builder/content configuration only. Customer identities,
 * orders, credentials, conversations and media binaries are deliberately
 * outside its mutation boundary.
 */
final class Test_Site_Snapshot_Service {
	private const SCHEMA = 'kiwe.test-site-snapshot.v1';
	private const OPTION = 'dsa_test_site_snapshot_v1';

	public function status(): array {
		$record = get_option( self::OPTION, [] );
		if ( ! is_array( $record ) || self::SCHEMA !== ( $record['schema'] ?? '' ) ) {
			return [ 'active' => false, 'schema' => self::SCHEMA ];
		}

		$path = $this->snapshot_path( (string) ( $record['file'] ?? '' ) );
		return [
			'active'     => true,
			'schema'     => self::SCHEMA,
			'createdAt'  => sanitize_text_field( (string) ( $record['createdAt'] ?? '' ) ),
			'createdBy'  => absint( $record['createdBy'] ?? 0 ),
			'label'      => sanitize_text_field( (string) ( $record['label'] ?? '' ) ),
			'posts'      => absint( $record['posts'] ?? 0 ),
			'postTypes'  => array_values( array_filter( array_map( 'sanitize_key', (array) ( $record['postTypes'] ?? [] ) ) ) ),
			'bytes'      => is_file( $path ) ? max( 0, (int) filesize( $path ) ) : 0,
			'fileReady'  => is_file( $path ) && is_readable( $path ),
			'hashPrefix' => substr( sanitize_text_field( (string) ( $record['hash'] ?? '' ) ), 0, 12 ),
		];
	}

	public function capture( string $label = '' ): array {
		if ( ! empty( $this->status()['active'] ) ) {
			throw new \RuntimeException( 'A test-site snapshot already exists. Restore or discard it before capturing another baseline.' );
		}

		$post_types = $this->post_types();
		$posts      = $this->capture_posts( $post_types );
		$snapshot   = [
			'schema'      => self::SCHEMA,
			'siteUrl'     => home_url( '/' ),
			'siteHash'    => hash( 'sha256', strtolower( untrailingslashit( home_url( '/' ) ) ) ),
			'createdAt'   => gmdate( 'c' ),
			'createdBy'   => get_current_user_id(),
			'label'       => sanitize_text_field( $label ),
			'postTypes'   => $post_types,
			'posts'       => $posts,
			'options'     => $this->capture_options(),
			'preservationBoundary' => [
				'users'             => 'untouched',
				'orders'            => 'untouched',
				'credentials'       => 'untouched',
				'mediaBinaries'     => 'untouched',
				'newMediaRecords'   => 'preserved',
			],
		];
		$snapshot['hash'] = $this->snapshot_hash( $snapshot );

		$json = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw new \RuntimeException( 'The test-site snapshot could not be encoded.' );
		}
		$directory = $this->private_directory();
		$filename  = 'test-site-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 16, false, false ) . '.json';
		$path      = trailingslashit( $directory ) . $filename;
		if ( strlen( $json ) !== file_put_contents( $path, $json, LOCK_EX ) ) {
			@unlink( $path );
			throw new \RuntimeException( 'The private snapshot file could not be written completely.' );
		}

		$record = [
			'schema'    => self::SCHEMA,
			'createdAt' => $snapshot['createdAt'],
			'createdBy' => $snapshot['createdBy'],
			'label'     => $snapshot['label'],
			'posts'     => count( $posts ),
			'postTypes' => $post_types,
			'file'      => $filename,
			'hash'      => $snapshot['hash'],
		];
		if ( ! update_option( self::OPTION, $record, false ) && get_option( self::OPTION, [] ) !== $record ) {
			@unlink( $path );
			throw new \RuntimeException( 'The snapshot index could not be stored.' );
		}

		return $this->status();
	}

	public function restore(): array {
		$snapshot = $this->read_snapshot();
		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $snapshot['postTypes'] ?? [] ) ) ) );
		$posts = is_array( $snapshot['posts'] ?? null ) ? $snapshot['posts'] : [];
		if ( [] === $post_types || [] === $posts ) {
			throw new \RuntimeException( 'The snapshot has no restorable content boundary.' );
		}

		$baseline_ids = array_values( array_filter( array_map( 'absint', array_keys( $posts ) ) ) );
		$current_ids  = get_posts(
			[
				'post_type'              => $post_types,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		$removed = 0;
		foreach ( array_diff( array_map( 'absint', (array) $current_ids ), $baseline_ids ) as $post_id ) {
			if ( $post_id > 0 && wp_delete_post( $post_id, true ) ) {
				++$removed;
			}
		}

		$restored = 0;
		$failed   = [];
		foreach ( $posts as $post_id => $record ) {
			try {
				$this->restore_post( absint( $post_id ), is_array( $record ) ? $record : [] );
				++$restored;
			} catch ( \Throwable $error ) {
				$failed[] = absint( $post_id ) . ': ' . $error->getMessage();
			}
		}
		$this->restore_options( is_array( $snapshot['options'] ?? null ) ? $snapshot['options'] : [] );
		$this->flush_builder_state();

		return [
			'restored'          => $restored,
			'removedTestPosts'  => $removed,
			'failed'            => $failed,
			'preservedUsers'    => true,
			'preservedOrders'   => true,
			'preservedMedia'    => true,
		];
	}

	public function discard(): bool {
		$record = get_option( self::OPTION, [] );
		$path   = $this->snapshot_path( is_array( $record ) ? (string) ( $record['file'] ?? '' ) : '' );
		$ok     = ! is_file( $path ) || @unlink( $path );
		if ( $ok ) {
			delete_option( self::OPTION );
		}
		return $ok;
	}

	private function read_snapshot(): array {
		$record = get_option( self::OPTION, [] );
		if ( ! is_array( $record ) || self::SCHEMA !== ( $record['schema'] ?? '' ) ) {
			throw new \RuntimeException( 'No valid test-site snapshot is indexed.' );
		}
		$path = $this->snapshot_path( (string) ( $record['file'] ?? '' ) );
		$json = is_readable( $path ) ? file_get_contents( $path ) : false;
		$snapshot = false !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $snapshot ) || self::SCHEMA !== ( $snapshot['schema'] ?? '' ) ) {
			throw new \RuntimeException( 'The private test-site snapshot is missing or invalid.' );
		}
		if ( ! hash_equals( (string) ( $snapshot['siteHash'] ?? '' ), hash( 'sha256', strtolower( untrailingslashit( home_url( '/' ) ) ) ) ) ) {
			throw new \RuntimeException( 'This snapshot belongs to a different site.' );
		}
		$expected = (string) ( $snapshot['hash'] ?? '' );
		if ( '' === $expected || ! hash_equals( $expected, $this->snapshot_hash( $snapshot ) ) || ! hash_equals( $expected, (string) ( $record['hash'] ?? '' ) ) ) {
			throw new \RuntimeException( 'Snapshot integrity verification failed. Nothing was restored.' );
		}
		return $snapshot;
	}

	private function capture_posts( array $post_types ): array {
		$ids = get_posts(
			[
				'post_type'              => $post_types,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		$out = [];
		foreach ( array_map( 'absint', (array) $ids ) as $post_id ) {
			$post = get_post( $post_id, ARRAY_A );
			if ( ! is_array( $post ) ) {
				continue;
			}
			$out[ (string) $post_id ] = [
				'fields' => array_intersect_key( $post, array_flip( $this->post_fields() ) ),
				'meta'   => get_post_meta( $post_id ),
				'terms'  => $this->capture_terms( $post_id, (string) $post['post_type'] ),
			];
		}
		return $out;
	}

	private function restore_post( int $post_id, array $record ): void {
		global $wpdb;
		$fields = is_array( $record['fields'] ?? null ) ? array_intersect_key( $record['fields'], array_flip( $this->post_fields() ) ) : [];
		$fields['ID'] = $post_id;
		if ( $post_id <= 0 || empty( $fields['post_type'] ) ) {
			throw new \RuntimeException( 'Missing post identity.' );
		}
		if ( get_post( $post_id ) ) {
			$result = wp_update_post( wp_slash( $fields ), true );
		} else {
			unset( $fields['ID'] );
			$fields['import_id'] = $post_id;
			$result = wp_insert_post( wp_slash( $fields ), true );
		}
		if ( is_wp_error( $result ) || absint( $result ) !== $post_id ) {
			throw new \RuntimeException( is_wp_error( $result ) ? $result->get_error_message() : 'WordPress did not preserve the snapshot post ID.' );
		}

		$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $post_id ], [ '%d' ] );
		foreach ( (array) ( $record['meta'] ?? [] ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				// WordPress unslashes incoming metadata, including nested Bricks CSS/JS.
				add_post_meta( $post_id, wp_slash( (string) $key ), wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		foreach ( get_object_taxonomies( (string) $fields['post_type'] ) as $taxonomy ) {
			wp_set_object_terms( $post_id, [], $taxonomy, false );
		}
		foreach ( (array) ( $record['terms'] ?? [] ) as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, array_map( 'absint', (array) $term_ids ), (string) $taxonomy, false );
		}
		clean_post_cache( $post_id );
	}

	private function capture_terms( int $post_id, string $post_type ): array {
		$out = [];
		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $terms ) && [] !== $terms ) {
				$out[ $taxonomy ] = array_map( 'absint', $terms );
			}
		}
		return $out;
	}

	private function capture_options(): array {
		$sentinel = new \stdClass();
		$out      = [];
		foreach ( $this->option_names() as $name ) {
			$value = get_option( $name, $sentinel );
			$out[ $name ] = [ 'exists' => $value !== $sentinel, 'value' => $value !== $sentinel ? $value : null ];
		}
		return $out;
	}

	private function restore_options( array $options ): void {
		foreach ( $options as $name => $record ) {
			if ( ! in_array( $name, $this->option_names(), true ) || ! is_array( $record ) ) {
				continue;
			}
			! empty( $record['exists'] ) ? update_option( $name, $record['value'] ?? null, false ) : delete_option( $name );
		}
	}

	private function post_types(): array {
		$types = [ 'bricks_template', 'page', 'post', 'product', 'product_variation', 'shop_coupon', 'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_navigation' ];
		return array_values( array_filter( $types, 'post_type_exists' ) );
	}

	private function post_fields(): array {
		return [ 'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count' ];
	}

	private function option_names(): array {
		$names = [
			// Never roll back the aggregate Kiwe settings option: it owns live
			// AI/SMTP/PhoneKey credentials and unrelated service configuration.
			'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'stylesheet', 'template',
			'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id',
			$this->option_name( 'BRICKS_DB_GLOBAL_CLASSES', 'bricks_global_classes' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_CLASSES_TRASH', 'bricks_global_classes_trash' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES', 'bricks_global_classes_categories' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_VARIABLES', 'bricks_global_variables' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES', 'bricks_global_variables_categories' ),
			$this->option_name( 'BRICKS_DB_COLOR_PALETTE', 'bricks_color_palette' ),
			$this->option_name( 'BRICKS_DB_THEME_STYLES', 'bricks_theme_styles' ),
			$this->option_name( 'BRICKS_DB_ELEMENT_MANAGER', 'bricks_element_manager' ),
		];
		$stylesheet = get_option( 'stylesheet', '' );
		if ( is_string( $stylesheet ) && '' !== $stylesheet ) {
			$names[] = 'theme_mods_' . $stylesheet;
		}
		return array_values( array_unique( array_filter( $names ) ) );
	}

	private function private_directory(): string {
		$parent = dirname( untrailingslashit( ABSPATH ) );
		$path   = trailingslashit( $parent ) . '.kiwe-private';
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			throw new \RuntimeException( 'Kiwe could not create its private snapshot directory outside the public web root.' );
		}
		if ( ! is_writable( $path ) ) {
			throw new \RuntimeException( 'The private snapshot directory is not writable.' );
		}
		return $path;
	}

	private function snapshot_path( string $filename ): string {
		$filename = sanitize_file_name( basename( $filename ) );
		return '' === $filename ? '' : trailingslashit( dirname( untrailingslashit( ABSPATH ) ) . '/.kiwe-private' ) . $filename;
	}

	private function snapshot_hash( array $snapshot ): string {
		unset( $snapshot['hash'] );
		$json = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash_hmac( 'sha256', false !== $json ? $json : serialize( $snapshot ), wp_salt( 'auth' ) );
	}

	private function option_name( string $constant, string $fallback ): string {
		return defined( $constant ) ? (string) constant( $constant ) : $fallback;
	}

	private function flush_builder_state(): void {
		wp_cache_flush();
		flush_rewrite_rules( false );
		if ( class_exists( '\\Bricks\\Assets_Files' ) && method_exists( '\\Bricks\\Assets_Files', 'schedule_css_file_regeneration' ) ) {
			\Bricks\Assets_Files::schedule_css_file_regeneration();
		}
	}
}
