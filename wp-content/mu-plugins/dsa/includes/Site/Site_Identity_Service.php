<?php

namespace DSA\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Identity_Service {
	public const OPTION_LOGO         = 'kiwe_site_logo_id';
	public const OPTION_LOGO_INVERSE = 'kiwe_site_logo_inverse_id';
	public const OPTION_STORE_PHONE  = 'kiwe_store_phone';
	public const OPTION_STORE_EMAIL  = 'kiwe_store_email';

	public function register(): void {
		add_action( 'after_setup_theme', [ $this, 'enable_custom_logo' ], 20 );
		add_action( 'admin_init', [ $this, 'register_general_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_general_settings_media' ] );
		add_action( 'admin_footer-options-general.php', [ $this, 'print_general_settings_media_script' ] );
		add_action( 'customize_register', [ $this, 'register_customizer_settings' ] );
	}

	public function enable_custom_logo(): void {
		if ( current_theme_supports( 'custom-logo' ) ) {
			return;
		}

		add_theme_support(
			'custom-logo',
			[
				'height'               => 512,
				'width'                => 512,
				'flex-height'          => true,
				'flex-width'           => true,
				'unlink-homepage-logo' => true,
			]
		);
	}

	public function register_general_settings(): void {
		foreach ( self::logo_options() as $option => $label ) {
			register_setting(
				'general',
				$option,
				[
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 0,
				]
			);

			add_settings_field(
				$option,
				$label,
				[ $this, 'render_general_logo_field' ],
				'general',
				'default',
				[
					'option' => $option,
					'label'  => $label,
				]
			);
		}

		register_setting(
			'general',
			self::OPTION_STORE_PHONE,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);

		register_setting(
			'general',
			self::OPTION_STORE_EMAIL,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			]
		);

		add_settings_field(
			self::OPTION_STORE_PHONE,
			__( 'Store phone', 'dsa' ),
			[ $this, 'render_general_contact_field' ],
			'general',
			'default',
			[
				'option'      => self::OPTION_STORE_PHONE,
				'type'        => 'tel',
				'description' => __( 'Public store phone used by Kiwe builder tags and future trust/contact surfaces.', 'dsa' ),
			]
		);

		add_settings_field(
			self::OPTION_STORE_EMAIL,
			__( 'Store email', 'dsa' ),
			[ $this, 'render_general_contact_field' ],
			'general',
			'default',
			[
				'option'      => self::OPTION_STORE_EMAIL,
				'type'        => 'email',
				'description' => __( 'Public store email used by Kiwe builder tags and future trust/contact surfaces.', 'dsa' ),
			]
		);
	}

	public function enqueue_general_settings_media( string $hook ): void {
		if ( 'options-general.php' !== $hook ) {
			return;
		}

		wp_enqueue_media();
	}

	public function render_general_logo_field( array $args ): void {
		$option = sanitize_key( $args['option'] ?? '' );
		$label  = sanitize_text_field( $args['label'] ?? __( 'Logo', 'dsa' ) );
		$id     = self::attachment_id( $option );
		$url    = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';

		printf(
			'<div class="kiwe-site-logo-field" data-kiwe-logo-field><input type="hidden" id="%1$s" name="%1$s" value="%2$d" data-kiwe-logo-input><div class="kiwe-site-logo-preview" data-kiwe-logo-preview>%3$s</div><button type="button" class="button" data-kiwe-logo-select data-title="%4$s">%5$s</button> <button type="button" class="button-link-delete" data-kiwe-logo-remove%6$s>%7$s</button><p class="description">%8$s</p></div>',
			esc_attr( $option ),
			(int) $id,
			$url ? '<img src="' . esc_url( $url ) . '" alt="" style="display:block;max-width:220px;max-height:90px;width:auto;height:auto;object-fit:contain;margin:0 0 8px;">' : '<span style="display:block;margin:0 0 8px;color:#646970;">' . esc_html__( 'No logo selected.', 'dsa' ) . '</span>',
			esc_attr( $label ),
			esc_html__( 'Choose image', 'dsa' ),
			$id ? '' : ' style="display:none"',
			esc_html__( 'Remove', 'dsa' ),
			esc_html__( 'Kiwe uses the full image here for DSA surfaces and builder tags. Use WordPress Site Icon separately for square PWA/app icons.', 'dsa' )
		);
	}

	public function render_general_contact_field( array $args ): void {
		$option      = sanitize_key( $args['option'] ?? '' );
		$type        = in_array( $args['type'] ?? '', [ 'email', 'tel' ], true ) ? (string) $args['type'] : 'text';
		$description = sanitize_text_field( $args['description'] ?? '' );
		$value       = (string) get_option( $option, '' );

		printf(
			'<input type="%1$s" class="regular-text" id="%2$s" name="%2$s" value="%3$s">%4$s',
			esc_attr( $type ),
			esc_attr( $option ),
			esc_attr( $value ),
			$description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}

	public function print_general_settings_media_script(): void {
		?>
		<script>
		( function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			document.addEventListener( 'click', function ( event ) {
				var select = event.target.closest ? event.target.closest( '[data-kiwe-logo-select]' ) : null;
				var remove = event.target.closest ? event.target.closest( '[data-kiwe-logo-remove]' ) : null;
				var field;
				var input;
				var preview;
				if ( select ) {
					event.preventDefault();
					field = select.closest( '[data-kiwe-logo-field]' );
					input = field ? field.querySelector( '[data-kiwe-logo-input]' ) : null;
					preview = field ? field.querySelector( '[data-kiwe-logo-preview]' ) : null;
					if ( ! input || ! preview ) {
						return;
					}
					window.wp.media( { title: select.getAttribute( 'data-title' ) || 'Choose logo', multiple: false, library: { type: 'image' } } ).on( 'select', function () {
						var attachment = this.state().get( 'selection' ).first().toJSON();
						var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
						input.value = attachment.id || 0;
						preview.innerHTML = url ? '<img src="' + url.replace( /"/g, '&quot;' ) + '" alt="" style="display:block;max-width:220px;max-height:90px;width:auto;height:auto;object-fit:contain;margin:0 0 8px;">' : '';
						if ( remove ) {
							remove.style.display = '';
						}
						var removeButton = field.querySelector( '[data-kiwe-logo-remove]' );
						if ( removeButton ) {
							removeButton.style.display = '';
						}
					} ).open();
				}
				if ( remove ) {
					event.preventDefault();
					field = remove.closest( '[data-kiwe-logo-field]' );
					input = field ? field.querySelector( '[data-kiwe-logo-input]' ) : null;
					preview = field ? field.querySelector( '[data-kiwe-logo-preview]' ) : null;
					if ( input ) {
						input.value = 0;
					}
					if ( preview ) {
						preview.innerHTML = '<span style="display:block;margin:0 0 8px;color:#646970;"><?php echo esc_js( __( 'No logo selected.', 'dsa' ) ); ?></span>';
					}
					remove.style.display = 'none';
				}
			} );
		}() );
		</script>
		<?php
	}

	public function register_customizer_settings( $wp_customize ): void {
		foreach ( self::logo_options() as $option => $label ) {
			$wp_customize->add_setting(
				$option,
				[
					'type'              => 'option',
					'capability'        => 'manage_options',
					'sanitize_callback' => 'absint',
					'default'           => 0,
				]
			);

			$wp_customize->add_control(
				new \WP_Customize_Media_Control(
					$wp_customize,
					$option,
					[
						'label'       => $label,
						'description' => __( 'Full-size Kiwe logo for DSA surfaces and builder tags. This is separate from the square Site Icon.', 'dsa' ),
						'section'     => 'title_tagline',
						'mime_type'   => 'image',
					]
				)
			);
		}
	}

	public static function logo_options(): array {
		return [
			self::OPTION_LOGO         => __( 'Site logo', 'dsa' ),
			self::OPTION_LOGO_INVERSE => __( 'Site logo inverse', 'dsa' ),
		];
	}

	public static function attachment_id( string $option = self::OPTION_LOGO ): int {
		if ( in_array( $option, [ self::OPTION_LOGO, self::OPTION_LOGO_INVERSE ], true ) ) {
			$id = absint( get_option( $option, 0 ) );

			if ( ! $id && self::OPTION_LOGO_INVERSE === $option ) {
				$id = absint( get_option( 'kiwe_site_logo_dark_id', 0 ) ) ?: absint( get_option( 'kiwe_site_logo_light_id', 0 ) );
			}

			return $id;
		}

		return 0;
	}

	public static function logo_url( string $variant = 'default' ): string {
		$option = self::OPTION_LOGO;

		if ( in_array( $variant, [ 'inverse', 'light', 'dark' ], true ) ) {
			$option = self::OPTION_LOGO_INVERSE;
		}

		$id = self::attachment_id( $option );

		if ( ! $id && self::OPTION_LOGO === $option ) {
			$id = absint( get_theme_mod( 'custom_logo' ) );
		}

		if ( ! $id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, 'full' );
		return $url ? esc_url_raw( $url ) : '';
	}

	public static function store_phone(): string {
		return sanitize_text_field( (string) get_option( self::OPTION_STORE_PHONE, '' ) );
	}

	public static function store_email(): string {
		return sanitize_email( (string) get_option( self::OPTION_STORE_EMAIL, '' ) );
	}
}
