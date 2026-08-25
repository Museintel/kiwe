<?php

namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public, owner-managed product evidence which WooCommerce does not own.
 *
 * Product title, descriptions, prices, dimensions, gallery and stock remain
 * native WooCommerce data. Kiwe only adds the nutrition-label media record so
 * SiteGraph and builders can bind it without copying a URL into page content.
 */
final class Product_Context_Service {
	public const META_NUTRITION_IMAGE_ID = 'kiwe_nutrition_image_id';

	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ], 20 );
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_fields' ], 40 );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_fields' ], 40 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function register_meta(): void {
		register_post_meta(
			'product',
			self::META_NUTRITION_IMAGE_ID,
			[
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn(): bool => current_user_can( 'edit_products' ),
			]
		);
	}

	public function render_fields(): void {
		global $post;
		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$image_id   = absint( get_post_meta( $product_id, self::META_NUTRITION_IMAGE_ID, true ) );
		$image_url  = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		?>
		<div class="options_group kiwe-product-context" data-kiwe-product-context>
			<p class="form-field">
				<label><?php esc_html_e( 'Nutrition information image', 'dsa' ); ?></label>
				<input type="hidden" name="<?php echo esc_attr( self::META_NUTRITION_IMAGE_ID ); ?>" value="<?php echo esc_attr( (string) $image_id ); ?>" data-kiwe-nutrition-image-id>
				<span data-kiwe-nutrition-preview><?php if ( $image_url ) : ?><img src="<?php echo esc_url( $image_url ); ?>" alt="" style="display:block;max-width:180px;height:auto;margin:0 0 8px"><?php endif; ?></span>
				<button type="button" class="button" data-kiwe-nutrition-select><?php esc_html_e( 'Choose nutrition image', 'dsa' ); ?></button>
				<button type="button" class="button-link-delete" data-kiwe-nutrition-remove<?php echo $image_id ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'dsa' ); ?></button>
				<span class="description" style="display:block"><?php esc_html_e( 'Stored on the product and exposed read-only through SiteGraph and Bricks dynamic data.', 'dsa' ); ?></span>
			</p>
		</div>
		<?php
	}

	public function save_fields( int $product_id ): void {
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		$image_id = absint( $_POST[ self::META_NUTRITION_IMAGE_ID ] ?? 0 );
		if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
			$image_id = 0;
		}
		update_post_meta( $product_id, self::META_NUTRITION_IMAGE_ID, $image_id );
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || 'product' !== get_current_screen()?->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'kiwe-product-context', DSA_URL . 'assets/js/product-context.js', [], DSA_VERSION, true );
	}

	public static function nutrition_image( int $product_id ): array {
		$image_id = absint( get_post_meta( $product_id, self::META_NUTRITION_IMAGE_ID, true ) );
		if ( ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
			return [];
		}

		$metadata = wp_get_attachment_metadata( $image_id );
		$metadata = is_array( $metadata ) ? $metadata : [];

		return [
			'id'     => $image_id,
			'url'    => esc_url_raw( (string) wp_get_attachment_url( $image_id ) ),
			'alt'    => sanitize_text_field( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ),
			'width'  => absint( $metadata['width'] ?? 0 ),
			'height' => absint( $metadata['height'] ?? 0 ),
		];
	}
}
