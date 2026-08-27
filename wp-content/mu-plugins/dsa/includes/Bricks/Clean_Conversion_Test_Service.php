<?php

namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a reversible, contamination-free Bricks conversion test window.
 *
 * This service never deletes content. It temporarily isolates global design
 * data and builder capability flags, then restores the exact option values
 * (including options that did not previously exist).
 */
final class Clean_Conversion_Test_Service {
	private const SNAPSHOT_OPTION = 'dsa_clean_conversion_test_snapshot_v1';
	private const LAST_RUN_OPTION = 'dsa_clean_conversion_test_last_run_v1';
	private const SCHEMA           = 'kiwe.clean-conversion-test.v1';
	private const SETTINGS_FIELDS  = [
		'diagnostics' => [ 'enabled', 'frontend_debug', 'console_logs', 'raw_convert_test_mode', 'accessibility_preview_mode' ],
		'bricks'      => [ 'mini_cart_adapter_enabled', 'add_to_cart_enhancer_enabled', 'linked_products_controls_enabled' ],
	];

	/** @return string[] */
	public static function woocommerce_elements(): array {
		return [
			'product-title', 'product-gallery', 'product-short-description', 'product-price',
			'product-stock', 'product-meta', 'product-rating', 'product-content',
			'product-add-to-cart', 'product-related', 'product-reviews',
			'product-additional-information', 'product-tabs', 'product-upsells',
			'woocommerce-breadcrumbs', 'woocommerce-mini-cart', 'woocommerce-cart-collaterals',
			'woocommerce-cart-coupon', 'woocommerce-cart-items', 'woocommerce-checkout-coupon',
			'woocommerce-checkout-login', 'woocommerce-checkout-customer-details',
			'woocommerce-checkout-order-review', 'woocommerce-checkout-thankyou',
			'woocommerce-checkout-order-table', 'woocommerce-checkout-order-payment',
			'woocommerce-products', 'woocommerce-products-pagination',
			'woocommerce-products-orderby', 'woocommerce-products-total-results',
			'woocommerce-products-filter', 'woocommerce-products-archive-description',
			'woocommerce-notice', 'woocommerce-account-page',
		];
	}

	/**
	 * @return array{active:bool,profile:string,created_at:string,user_id:int,hash:string,isolated_templates:int,counts:array<string,int>,disabled_woocommerce_elements:string[],last_run:array}
	 */
	public function status(): array {
		$snapshot = get_option( self::SNAPSHOT_OPTION, [] );
		$snapshot = is_array( $snapshot ) && self::SCHEMA === ( $snapshot['schema'] ?? '' ) ? $snapshot : [];
		$manager  = get_option( $this->option_name( 'BRICKS_DB_ELEMENT_MANAGER', 'bricks_element_manager' ), [] );

		return [
			'active'                         => ! empty( $snapshot ),
			'profile'                        => sanitize_key( (string) ( $snapshot['profile'] ?? '' ) ),
			'created_at'                     => sanitize_text_field( (string) ( $snapshot['created_at'] ?? '' ) ),
			'user_id'                        => absint( $snapshot['user_id'] ?? 0 ),
			'hash'                           => substr( sanitize_text_field( (string) ( $snapshot['hash'] ?? '' ) ), 0, 12 ),
			'isolated_templates'             => count( array_filter( array_map( 'absint', (array) ( $snapshot['excluded_template_ids'] ?? [] ) ) ) ),
			'counts'                         => $this->current_counts(),
			'disabled_woocommerce_elements' => $this->disabled_woocommerce_elements( is_array( $manager ) ? $manager : [] ),
			'last_run'                       => is_array( get_option( self::LAST_RUN_OPTION, [] ) ) ? get_option( self::LAST_RUN_OPTION, [] ) : [],
		];
	}

	/** @return array{profile:string,hash:string,css_queued:bool,activated_woocommerce_elements:int} */
	public function begin( string $profile ): array {
		$profile = sanitize_key( $profile );
		if ( ! in_array( $profile, [ 'raw', 'woo_native', 'woo_kiwe' ], true ) ) {
			throw new \InvalidArgumentException( 'Choose raw, Woo native, or Woo native + Kiwe.' );
		}
		if ( get_option( self::SNAPSHOT_OPTION, false ) ) {
			throw new \RuntimeException( 'A clean conversion test is already active. Restore it before starting another.' );
		}
		if ( ! defined( 'BRICKS_VERSION' ) && ! class_exists( '\\Bricks\\Setup' ) ) {
			throw new \RuntimeException( 'Bricks is not active.' );
		}

		$options  = $this->snapshot_options();
		$snapshot = [
			'schema'     => self::SCHEMA,
			'created_at' => gmdate( 'c' ),
			'user_id'    => get_current_user_id(),
			'profile'    => $profile,
			'options'    => $options,
			'settings'   => $this->snapshot_settings(),
			// Existing templates stay published and editable, but are excluded from
			// Bricks' active-template query while this acceptance window is open.
			// Templates imported after the snapshot remain eligible, so headers,
			// footers, products and archives are measured without another project's
			// conditions winning the same route.
			'excluded_template_ids' => $this->published_template_ids(),
		];
		$snapshot['hash'] = $this->snapshot_hash( $snapshot );

		if ( ! update_option( self::SNAPSHOT_OPTION, $snapshot, false ) && get_option( self::SNAPSHOT_OPTION, [] ) !== $snapshot ) {
			throw new \RuntimeException( 'The clean-run snapshot could not be stored. No changes were made.' );
		}
		$stored = get_option( self::SNAPSHOT_OPTION, [] );
		if ( ! is_array( $stored ) || ! hash_equals( $snapshot['hash'], (string) ( $stored['hash'] ?? '' ) ) ) {
			delete_option( self::SNAPSHOT_OPTION );
			throw new \RuntimeException( 'The clean-run snapshot failed verification. No changes were made.' );
		}

		try {
			$this->isolate_global_styles();
			$activated = $this->configure_profile( $profile );
			$this->flush_template_cache();
			$queued    = $this->queue_css_regeneration();
		} catch ( \Throwable $error ) {
			$this->restore_snapshot( $snapshot );
			delete_option( self::SNAPSHOT_OPTION );
			throw $error;
		}

		return [
			'profile'                        => $profile,
			'hash'                           => substr( $snapshot['hash'], 0, 12 ),
			'css_queued'                     => $queued,
			'activated_woocommerce_elements' => $activated,
		];
	}

	/** @return array{profile:string,hash:string,css_queued:bool} */
	public function restore(): array {
		$snapshot = get_option( self::SNAPSHOT_OPTION, [] );
		if ( ! is_array( $snapshot ) || self::SCHEMA !== ( $snapshot['schema'] ?? '' ) ) {
			throw new \RuntimeException( 'No valid clean conversion test snapshot exists.' );
		}
		$expected = (string) ( $snapshot['hash'] ?? '' );
		if ( '' === $expected || ! hash_equals( $expected, $this->snapshot_hash( $snapshot ) ) ) {
			throw new \RuntimeException( 'The clean-run snapshot is damaged. It was not applied or deleted.' );
		}

		$this->restore_snapshot( $snapshot );
		$this->flush_template_cache();
		$queued = $this->queue_css_regeneration();
		$result = [
			'schema'      => self::SCHEMA,
			'restored_at' => gmdate( 'c' ),
			'user_id'     => get_current_user_id(),
			'profile'     => sanitize_key( (string) ( $snapshot['profile'] ?? '' ) ),
			'hash'        => substr( $expected, 0, 12 ),
			'css_queued'  => $queued,
		];
		update_option( self::LAST_RUN_OPTION, $result, false );
		delete_option( self::SNAPSHOT_OPTION );

		return $result;
	}

	/**
	 * Exclude every template that existed before the active clean run.
	 *
	 * This is query-only isolation: it never changes post status, conditions,
	 * content or post meta. Imported templates therefore become the only Bricks
	 * templates eligible during the run, and the exact pre-test state returns as
	 * soon as the snapshot is restored.
	 */
	public static function register_runtime_isolation(): void {
		$snapshot = get_option( self::SNAPSHOT_OPTION, [] );
		if ( ! is_array( $snapshot ) || self::SCHEMA !== ( $snapshot['schema'] ?? '' ) ) {
			return;
		}

		$expected = (string) ( $snapshot['hash'] ?? '' );
		$service  = new self();
		if ( '' === $expected || ! hash_equals( $expected, $service->snapshot_hash( $snapshot ) ) ) {
			return;
		}

		$excluded = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $snapshot['excluded_template_ids'] ?? [] ) ) ) ) );
		if ( [] === $excluded ) {
			return;
		}

		add_filter(
			// Bricks 2.x exposes the active-template WP_Query arguments on this
			// (intentionally redundant) hook name. Using the similarly named
			// after-query action prefix does not intercept header/footer defaults.
			'bricks/database/bricks_get_all_templates_by_type_args',
			static function ( array $args ) use ( $excluded ): array {
				$current             = array_filter( array_map( 'absint', (array) ( $args['post__not_in'] ?? [] ) ) );
				$args['post__not_in'] = array_values( array_unique( array_merge( $current, $excluded ) ) );
				return $args;
			},
			PHP_INT_MAX
		);
	}

	/** @return array<string,array{exists:bool,value:mixed}> */
	private function snapshot_options(): array {
		$out      = [];
		$sentinel = new \stdClass();
		foreach ( $this->managed_options() as $name ) {
			$value        = get_option( $name, $sentinel );
			$out[ $name ] = [
				'exists' => $value !== $sentinel,
				'value'  => $value !== $sentinel ? $value : null,
			];
		}
		return $out;
	}

	/** @return int[] */
	private function published_template_ids(): array {
		$slug = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? (string) BRICKS_DB_TEMPLATE_SLUG : 'bricks_template';
		$ids  = get_posts(
			[
				'post_type'              => $slug,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : [] ) ) ) );
	}

	/** @return string[] */
	private function managed_options(): array {
		$names = [
			$this->option_name( 'BRICKS_DB_GLOBAL_CLASSES', 'bricks_global_classes' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_CLASSES_TRASH', 'bricks_global_classes_trash' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES', 'bricks_global_classes_categories' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_CLASSES_LOCKED', 'bricks_global_classes_locked' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP', 'bricks_global_classes_timestamp' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_CLASSES_USER', 'bricks_global_classes_user' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_VARIABLES', 'bricks_global_variables' ),
			$this->option_name( 'BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES', 'bricks_global_variables_categories' ),
			$this->option_name( 'BRICKS_DB_COLOR_PALETTE', 'bricks_color_palette' ),
			$this->option_name( 'BRICKS_DB_THEME_STYLES', 'bricks_theme_styles' ),
			$this->option_name( 'BRICKS_DB_ELEMENT_MANAGER', 'bricks_element_manager' ),
		];
		return array_values( array_unique( $names ) );
	}

	private function snapshot_settings(): array {
		$sentinel = new \stdClass();
		$settings = get_option( defined( 'DSA_OPTION_SETTINGS' ) ? DSA_OPTION_SETTINGS : 'dsa_settings', $sentinel );
		$out = [ 'exists' => $settings !== $sentinel, 'groups' => [] ];
		$settings = is_array( $settings ) ? $settings : [];
		foreach ( self::SETTINGS_FIELDS as $group => $fields ) {
			$values = is_array( $settings[ $group ] ?? null ) ? $settings[ $group ] : [];
			$out['groups'][ $group ] = [ 'exists' => array_key_exists( $group, $settings ), 'fields' => [] ];
			foreach ( $fields as $field ) {
				$out['groups'][ $group ]['fields'][ $field ] = [
					'exists' => array_key_exists( $field, $values ),
					'value'  => $values[ $field ] ?? null,
				];
			}
		}
		return $out;
	}

	private function restore_settings( array $record ): void {
		$name = defined( 'DSA_OPTION_SETTINGS' ) ? DSA_OPTION_SETTINGS : 'dsa_settings';
		$settings = get_option( $name, [] );
		$settings = is_array( $settings ) ? $settings : [];
		foreach ( self::SETTINGS_FIELDS as $group => $fields ) {
			$original = $record['groups'][ $group ];
			$values = is_array( $settings[ $group ] ?? null ) ? $settings[ $group ] : [];
			foreach ( $fields as $field ) {
				$state = $original['fields'][ $field ];
				if ( ! empty( $state['exists'] ) ) {
					$values[ $field ] = $state['value'] ?? null;
				} else {
					unset( $values[ $field ] );
				}
			}
			if ( [] === $values && empty( $original['exists'] ) ) {
				unset( $settings[ $group ] );
			} else {
				$settings[ $group ] = $values;
			}
		}
		if ( [] === $settings && empty( $record['exists'] ) ) {
			delete_option( $name );
		} else {
			update_option( $name, $settings, false );
		}
	}

	private function isolate_global_styles(): void {
		$class_option = $this->option_name( 'BRICKS_DB_GLOBAL_CLASSES', 'bricks_global_classes' );
		update_option( $class_option, [], false );
		foreach ( [
			'BRICKS_DB_GLOBAL_CLASSES_TRASH'      => 'bricks_global_classes_trash',
			'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES' => 'bricks_global_classes_categories',
			'BRICKS_DB_GLOBAL_CLASSES_LOCKED'     => 'bricks_global_classes_locked',
			'BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP'  => 'bricks_global_classes_timestamp',
			'BRICKS_DB_GLOBAL_CLASSES_USER'       => 'bricks_global_classes_user',
			'BRICKS_DB_GLOBAL_VARIABLES'          => 'bricks_global_variables',
			'BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES' => 'bricks_global_variables_categories',
			'BRICKS_DB_COLOR_PALETTE'              => 'bricks_color_palette',
			'BRICKS_DB_THEME_STYLES'                => 'bricks_theme_styles',
		] as $constant => $fallback ) {
			update_option( $this->option_name( $constant, $fallback ), [], false );
		}
	}

	private function configure_profile( string $profile ): int {
		$settings_name = defined( 'DSA_OPTION_SETTINGS' ) ? DSA_OPTION_SETTINGS : 'dsa_settings';
		$settings      = get_option( $settings_name, [] );
		$settings      = is_array( $settings ) ? $settings : [];
		$settings['diagnostics'] = is_array( $settings['diagnostics'] ?? null ) ? $settings['diagnostics'] : [];
		$settings['diagnostics']['enabled']                    = false;
		$settings['diagnostics']['frontend_debug']             = false;
		$settings['diagnostics']['console_logs']               = false;
		$settings['diagnostics']['raw_convert_test_mode']      = true;
		$settings['diagnostics']['accessibility_preview_mode'] = false;
		$settings['bricks'] = is_array( $settings['bricks'] ?? null ) ? $settings['bricks'] : [];
		$settings['bricks']['mini_cart_adapter_enabled']      = 'woo_kiwe' === $profile;
		$settings['bricks']['add_to_cart_enhancer_enabled']   = 'woo_kiwe' === $profile;
		$settings['bricks']['linked_products_controls_enabled'] = 'woo_kiwe' === $profile;
		update_option( $settings_name, $settings, false );

		if ( 'raw' === $profile ) {
			return 0;
		}

		$manager_name = $this->option_name( 'BRICKS_DB_ELEMENT_MANAGER', 'bricks_element_manager' );
		$manager      = get_option( $manager_name, [] );
		$manager      = is_array( $manager ) ? $manager : [];
		$activated    = 0;
		foreach ( self::woocommerce_elements() as $element ) {
			if ( ! isset( $manager[ $element ] ) || ! is_array( $manager[ $element ] ) || 'disabled' !== ( $manager[ $element ]['status'] ?? '' ) ) {
				continue;
			}
			unset( $manager[ $element ]['status'] );
			if ( [] === $manager[ $element ] ) {
				unset( $manager[ $element ] );
			}
			++$activated;
		}
		update_option( $manager_name, $manager, false );
		return $activated;
	}

	private function restore_snapshot( array $snapshot ): void {
		$options = is_array( $snapshot['options'] ?? null ) ? $snapshot['options'] : [];
		if ( [] === $options ) {
			throw new \RuntimeException( 'The clean-run snapshot contains no restorable options.' );
		}
		// Validate before touching any globals. Never restore an older aggregate
		// settings copy, which could roll back AI/SMTP/PhoneKey credentials.
		$settings = $snapshot['settings'] ?? null;
		foreach ( self::SETTINGS_FIELDS as $group => $fields ) {
			foreach ( $fields as $field ) {
				if ( ! is_array( $settings['groups'][ $group ]['fields'][ $field ] ?? null ) ) {
					throw new \RuntimeException( 'The clean-run snapshot has no isolated settings fields. No options were restored.' );
				}
			}
		}

		// Restore active classes first and trash last because Bricks class hooks may
		// reconcile trash while the active option changes.
		$trash = $this->option_name( 'BRICKS_DB_GLOBAL_CLASSES_TRASH', 'bricks_global_classes_trash' );
		foreach ( $options as $name => $record ) {
			if ( $trash === $name || ! in_array( $name, $this->managed_options(), true ) ) {
				continue;
			}
			$this->restore_option( (string) $name, is_array( $record ) ? $record : [] );
		}
		if ( isset( $options[ $trash ] ) ) {
			$this->restore_option( $trash, is_array( $options[ $trash ] ) ? $options[ $trash ] : [] );
		}
		$this->restore_settings( $settings );
	}

	private function restore_option( string $name, array $record ): void {
		if ( ! empty( $record['exists'] ) ) {
			update_option( $name, $record['value'] ?? null, false );
		} else {
			delete_option( $name );
		}
	}

	private function snapshot_hash( array $snapshot ): string {
		unset( $snapshot['hash'] );
		return hash( 'sha256', serialize( $snapshot ) );
	}

	private function option_name( string $constant, string $fallback ): string {
		return defined( $constant ) ? (string) constant( $constant ) : $fallback;
	}

	/** @return string[] */
	private function disabled_woocommerce_elements( array $manager ): array {
		$out = [];
		foreach ( self::woocommerce_elements() as $element ) {
			if ( 'disabled' === ( $manager[ $element ]['status'] ?? '' ) ) {
				$out[] = $element;
			}
		}
		return $out;
	}

	/** @return array<string,int> */
	private function current_counts(): array {
		return [
			'classes'   => count( (array) get_option( $this->option_name( 'BRICKS_DB_GLOBAL_CLASSES', 'bricks_global_classes' ), [] ) ),
			'variables' => count( (array) get_option( $this->option_name( 'BRICKS_DB_GLOBAL_VARIABLES', 'bricks_global_variables' ), [] ) ),
			'palettes'  => count( (array) get_option( $this->option_name( 'BRICKS_DB_COLOR_PALETTE', 'bricks_color_palette' ), [] ) ),
			'themes'    => count( (array) get_option( $this->option_name( 'BRICKS_DB_THEME_STYLES', 'bricks_theme_styles' ), [] ) ),
		];
	}

	private function queue_css_regeneration(): bool {
		if ( class_exists( '\\Bricks\\Assets_Files' ) && method_exists( '\\Bricks\\Assets_Files', 'schedule_css_file_regeneration' ) ) {
			\Bricks\Assets_Files::schedule_css_file_regeneration();
			return true;
		}
		if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( 'bricks_regenerate_css_files' ) ) {
			wp_schedule_single_event( time() + 1, 'bricks_regenerate_css_files' );
			return true;
		}
		return false;
	}

	private function flush_template_cache(): void {
		if ( ! function_exists( 'wp_cache_set' ) ) {
			return;
		}
		$slug = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? (string) BRICKS_DB_TEMPLATE_SLUG : 'bricks_template';
		wp_cache_set( 'last_changed', microtime(), 'bricks_' . $slug );
	}
}
