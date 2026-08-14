<?php

namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safely inventories and removes superseded SEAM compiler global-style groups.
 *
 * Compiler output uses a collision-resistant namespace such as `seam-qrbj6-*`
 * or `seam-152qsk-*`. Five-character namespaces remain valid for older
 * compiler exports; current exports use six characters.
 * These classes belong to imported templates, not to Kiwe Framework, and must be
 * cleaned only after Bricks content no longer references their IDs.
 */
final class Compiler_Batch_Cleanup_Service {
	private const CURRENT_NAMESPACE_OPTION = 'dsa_bricks_compiler_current_namespace';
	private const BACKUP_OPTION            = 'dsa_bricks_compiler_cleanup_backup';
	private const NAMESPACE_REGISTRY_OPTION = 'dsa_bricks_compiler_namespace_registry';

	public static function compiler_namespace( string $class_name ): string {
		$class_name = sanitize_html_class( $class_name );
		if ( 1 !== preg_match( '/^seam-([a-z0-9]{5,6})-/', $class_name, $matches ) ) {
			return '';
		}

		return 'seam-' . $matches[1] . '-';
	}

	public static function is_valid_namespace( string $namespace ): bool {
		return 1 === preg_match( '/^seam-[a-z0-9]{5,6}-$/', $namespace );
	}

	/**
	 * A name-shaped match is not proof: normal Framework utilities can also use
	 * five- or six-letter segment (for example seam-align-*). Recognition requires an
	 * explicit compiler source marker or class-ID evidence from a template tagged
	 * SEAM Compiler. Observed namespaces remain registered for cleanup after the
	 * originating template is later moved to trash.
	 */
	public static function is_recognized_compiler_class( array $class ): bool {
		static $recognized = null;
		if ( null === $recognized ) {
			$classes    = defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ? get_option( BRICKS_DB_GLOBAL_CLASSES, [] ) : [];
			$classes    = is_array( $classes ) ? $classes : [];
			$recognized = ( new self() )->recognized_namespace_map( $classes );
		}

		$namespace = self::compiler_namespace( (string) ( $class['name'] ?? '' ) );
		return '' !== $namespace && isset( $recognized[ $namespace ] );
	}

	/**
	 * @return array{available:bool,current:string,namespaces:array<string,array{classes:int,referenced:int,removable:int}>}
	 */
	public function report(): array {
		if ( ! defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ) {
			return [ 'available' => false, 'current' => '', 'namespaces' => [] ];
		}

		$classes        = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		$classes        = is_array( $classes ) ? $classes : [];
		$recognized     = $this->recognized_namespace_map( $classes );
		$referenced_ids = $this->referenced_global_class_ids();
		$namespaces     = [];

		foreach ( $classes as $class ) {
			if ( ! is_array( $class ) ) {
				continue;
			}

			$namespace = self::compiler_namespace( (string) ( $class['name'] ?? '' ) );
			if ( '' === $namespace || ! isset( $recognized[ $namespace ] ) ) {
				continue;
			}

			if ( ! isset( $namespaces[ $namespace ] ) ) {
				$namespaces[ $namespace ] = [ 'classes' => 0, 'referenced' => 0, 'removable' => 0 ];
			}

			++$namespaces[ $namespace ]['classes'];
			$id = (string) ( $class['id'] ?? '' );
			if ( '' !== $id && isset( $referenced_ids[ $id ] ) ) {
				++$namespaces[ $namespace ]['referenced'];
			} else {
				++$namespaces[ $namespace ]['removable'];
			}
		}

		ksort( $namespaces, SORT_NATURAL );
		$current = (string) get_option( self::CURRENT_NAMESPACE_OPTION, '' );
		if ( ! self::is_valid_namespace( $current ) || ! isset( $namespaces[ $current ] ) ) {
			$current = $this->suggest_current_namespace( $namespaces );
		}

		return [ 'available' => true, 'current' => $current, 'namespaces' => $namespaces ];
	}

	/**
	 * @return array{removed:int,protected:int,kept:int,css_queued:bool}
	 */
	public function cleanup( string $keep_namespace ): array {
		$keep_namespace = sanitize_html_class( $keep_namespace );
		if ( ! self::is_valid_namespace( $keep_namespace ) ) {
			throw new \InvalidArgumentException( 'A valid compiler namespace such as seam-qrbj6- or seam-152qsk- is required.' );
		}
		if ( ! defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ) {
			throw new \RuntimeException( 'Bricks global classes are unavailable.' );
		}

		$classes = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		$trash   = defined( 'BRICKS_DB_GLOBAL_CLASSES_TRASH' ) ? get_option( BRICKS_DB_GLOBAL_CLASSES_TRASH, [] ) : [];
		$classes = is_array( $classes ) ? $classes : [];
		$trash   = is_array( $trash ) ? $trash : [];
		$recognized = $this->recognized_namespace_map( $classes );
		if ( ! isset( $recognized[ $keep_namespace ] ) ) {
			throw new \InvalidArgumentException( 'The keep namespace has no SEAM Compiler ownership evidence.' );
		}

		$keep_found = false;
		foreach ( $classes as $class ) {
			if ( is_array( $class ) && $keep_namespace === self::compiler_namespace( (string) ( $class['name'] ?? '' ) ) ) {
				$keep_found = true;
				break;
			}
		}
		if ( ! $keep_found ) {
			throw new \InvalidArgumentException( 'The keep namespace does not exist in the active Bricks global classes.' );
		}

		$referenced_ids = $this->referenced_global_class_ids();
		$remaining      = [];
		$removed        = [];
		$protected      = 0;
		$kept           = 0;

		foreach ( $classes as $class ) {
			if ( ! is_array( $class ) ) {
				$remaining[] = $class;
				continue;
			}

			$namespace = self::compiler_namespace( (string) ( $class['name'] ?? '' ) );
			if ( '' === $namespace || ! isset( $recognized[ $namespace ] ) || $keep_namespace === $namespace ) {
				$remaining[] = $class;
				if ( $keep_namespace === $namespace ) {
					++$kept;
				}
				continue;
			}

			$id = (string) ( $class['id'] ?? '' );
			if ( '' !== $id && isset( $referenced_ids[ $id ] ) ) {
				$remaining[] = $class;
				++$protected;
				continue;
			}

			$class['deletedAt'] = time();
			$removed[]          = $class;
		}

		update_option(
			self::BACKUP_OPTION,
			[
				'created_at'     => gmdate( 'c' ),
				'user_id'        => get_current_user_id(),
				'kiwe_version'   => defined( 'DSA_VERSION' ) ? DSA_VERSION : '',
				'keep_namespace' => $keep_namespace,
				'active_classes' => $classes,
				'trash_classes'  => $trash,
			],
			false
		);

		if ( class_exists( '\\Bricks\\Helpers' ) && method_exists( '\\Bricks\\Helpers', 'save_global_classes_in_db' ) ) {
			\Bricks\Helpers::save_global_classes_in_db( $remaining );
		} else {
			update_option( BRICKS_DB_GLOBAL_CLASSES, $remaining, false );
		}

		if ( ! empty( $removed ) && defined( 'BRICKS_DB_GLOBAL_CLASSES_TRASH' ) ) {
			update_option( BRICKS_DB_GLOBAL_CLASSES_TRASH, $this->merge_trash( $trash, $removed ), false );
		}
		update_option( self::CURRENT_NAMESPACE_OPTION, $keep_namespace, false );

		$css_queued = false;
		if ( ! empty( $removed ) && class_exists( '\\Bricks\\Assets_Files' ) && method_exists( '\\Bricks\\Assets_Files', 'schedule_css_file_regeneration' ) ) {
			\Bricks\Assets_Files::schedule_css_file_regeneration();
			$css_queued = true;
		} elseif ( ! empty( $removed ) && function_exists( 'wp_schedule_single_event' ) ) {
			if ( ! wp_next_scheduled( 'bricks_regenerate_css_files' ) ) {
				wp_schedule_single_event( time() + 1, 'bricks_regenerate_css_files' );
			}
			$css_queued = true;
		}

		return [
			'removed'    => count( $removed ),
			'protected'  => $protected,
			'kept'       => $kept,
			'css_queued' => $css_queued,
		];
	}

	/**
	 * @param array<string,array{classes:int,referenced:int,removable:int}> $namespaces
	 */
	private function suggest_current_namespace( array $namespaces ): string {
		$best       = '';
		$references = -1;
		foreach ( $namespaces as $namespace => $counts ) {
			if ( $counts['referenced'] > $references || ( $counts['referenced'] === $references && strcmp( $namespace, $best ) > 0 ) ) {
				$best       = $namespace;
				$references = $counts['referenced'];
			}
		}

		return $best;
	}

	/** @return array<string,true> */
	private function recognized_namespace_map( array $classes ): array {
		$stored = get_option( self::NAMESPACE_REGISTRY_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$registry = [];

		foreach ( $stored as $namespace => $record ) {
			if ( self::is_valid_namespace( (string) $namespace ) ) {
				$registry[ (string) $namespace ] = is_array( $record ) ? $record : [];
			}
		}

		$template_ids = $this->compiler_template_global_class_ids();
		$changed      = false;
		foreach ( $classes as $class ) {
			if ( ! is_array( $class ) ) {
				continue;
			}

			$namespace = self::compiler_namespace( (string) ( $class['name'] ?? '' ) );
			if ( '' === $namespace ) {
				continue;
			}

			$source          = sanitize_key( (string) ( $class['source'] ?? '' ) );
			$explicit_source = in_array( $source, [ 'seam-compiler', 'kiwe-seam-compiler' ], true );
			$framework_owned = in_array( $source, [ 'kiwe-seam', 'kiwe-framework', 'kiwe-universal', 'kiwe-project', 'seamflow' ], true );
			$id              = (string) ( $class['id'] ?? '' );
			$template_proof  = ! $framework_owned && '' !== $id && isset( $template_ids[ $id ] );

			if ( ! $explicit_source && ! $template_proof ) {
				continue;
			}

			$record = [
				'last_seen' => gmdate( 'c' ),
				'evidence'  => $explicit_source ? 'class-source' : 'seam-compiler-template',
			];
			if ( ! isset( $registry[ $namespace ] ) || (string) ( $registry[ $namespace ]['evidence'] ?? '' ) !== $record['evidence'] ) {
				$changed = true;
			}
			$registry[ $namespace ] = $record;
		}

		if ( $changed || count( $registry ) !== count( $stored ) ) {
			update_option( self::NAMESPACE_REGISTRY_OPTION, $registry, false );
		}

		return array_fill_keys( array_keys( $registry ), true );
	}

	/** @return array<string,true> */
	private function compiler_template_global_class_ids(): array {
		global $wpdb;

		$meta_keys    = $this->bricks_content_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$sql          = "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE pm.meta_key IN ($placeholders) AND p.post_type = 'bricks_template' AND p.post_status NOT IN ('trash','auto-draft','inherit') AND tt.taxonomy = 'template_tag' AND t.slug = 'seam-compiler'";
		$values       = $wpdb->get_col( $wpdb->prepare( $sql, ...$meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids          = [];
		foreach ( $values as $value ) {
			$this->collect_global_class_ids( maybe_unserialize( $value ), $ids );
		}

		return $ids;
	}

	/** @return array<string,true> */
	private function referenced_global_class_ids(): array {
		global $wpdb;

		$meta_keys = $this->bricks_content_meta_keys();

		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$sql          = "SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ($placeholders) AND p.post_status NOT IN ('trash','auto-draft','inherit') AND p.post_type <> 'revision'";
		$values       = $wpdb->get_col( $wpdb->prepare( $sql, ...$meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids          = [];

		foreach ( $values as $value ) {
			$this->collect_global_class_ids( maybe_unserialize( $value ), $ids );
		}

		return $ids;
	}

	/** @return array<int,string> */
	private function bricks_content_meta_keys(): array {
		return array_values(
			array_filter(
				[
					defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2',
					defined( 'BRICKS_DB_PAGE_HEADER' ) ? BRICKS_DB_PAGE_HEADER : '_bricks_page_header_2',
					defined( 'BRICKS_DB_PAGE_FOOTER' ) ? BRICKS_DB_PAGE_FOOTER : '_bricks_page_footer_2',
				]
			)
		);
	}

	/**
	 * Mirrors Bricks' trash synchronization: one record per ID, newest deletion wins.
	 *
	 * @param array<int,mixed> $trash
	 * @param array<int,array<string,mixed>> $removed
	 * @return array<int,array<string,mixed>>
	 */
	private function merge_trash( array $trash, array $removed ): array {
		$by_id = [];
		foreach ( array_merge( $trash, $removed ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || ! isset( $item['deletedAt'] ) ) {
				continue;
			}

			$id = (string) $item['id'];
			if ( ! isset( $by_id[ $id ] ) || (int) $item['deletedAt'] > (int) $by_id[ $id ]['deletedAt'] ) {
				$by_id[ $id ] = $item;
			}
		}

		return array_values( $by_id );
	}

	/** @param array<string,true> $ids */
	private function collect_global_class_ids( $value, array &$ids ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $key => $item ) {
			if ( '_cssGlobalClasses' === $key ) {
				foreach ( (array) $item as $id ) {
					if ( is_scalar( $id ) && '' !== (string) $id ) {
						$ids[ (string) $id ] = true;
					}
				}
				continue;
			}

			if ( is_array( $item ) ) {
				$this->collect_global_class_ids( $item, $ids );
			}
		}
	}
}
