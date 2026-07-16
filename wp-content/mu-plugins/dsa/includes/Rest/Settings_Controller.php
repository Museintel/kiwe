<?php

namespace DSA\Rest;

use DSA\AI\Copilot_Service;
use DSA\Design\Seam_Token_Service;
use DSA\Design\Token_Schema;
use DSA\Element_Registry;
use DSA\Link_Hub\Review_Service;
use DSA\Modules\Module_Registry;
use DSA\Settings;
use DSA\Site\Site_Identity_Service;
use DSA\Trust\Trust_Service;
use DSA\Utilities\Origin_Checker;
use DSA\WP7\Native_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Controller {
	private $settings;
	private $registry;
	private $trust;
	private $modules;
	private $native;
	private $copilot;
	private $reviews;

	public function __construct( Settings $settings, Element_Registry $registry, Trust_Service $trust, Module_Registry $modules, Native_Service $native, Copilot_Service $copilot, Review_Service $reviews ) {
		$this->settings  = $settings;
		$this->registry  = $registry;
		$this->trust     = $trust;
		$this->modules   = $modules;
		$this->native    = $native;
		$this->copilot   = $copilot;
		$this->reviews   = $reviews;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			'dsa/v1',
			'/manifest',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'manifest' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'dsa/v1',
			'/registry',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'registry' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'dsa/v1',
			'/links',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_links' ],
				'permission_callback' => [ $this, 'can_manage_options' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/links/logo',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_logo' ],
				'permission_callback' => [ $this, 'can_manage_options' ],
			]
		);

		register_rest_route(
			'dsa/v1',
			'/copilot',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'copilot' ],
				'permission_callback' => [ $this, 'can_manage_options' ],
			]
		);

	}

	public function manifest( WP_REST_Request $request ): WP_REST_Response {
		$settings = $this->settings->all();
		$manifest = $this->settings->manifest();
		$dock     = isset( $settings['dock'] ) && is_array( $settings['dock'] ) ? $settings['dock'] : [];

		$manifest['modules'] = $this->modules->manifest_contract( $dock );
		$manifest['native']  = $this->native->manifest_fragment();
		$manifest['designTokens'] = Token_Schema::contract( $settings, $manifest );
		$seam_items  = Seam_Token_Service::universal_tokens();
		$kiwe_tokens = [
			'enabled'        => true,
			'source'         => 'kiwe.universal',
			'count'          => count( $seam_items ),
			'counts'         => Seam_Token_Service::counts( $seam_items ),
			'affectsSurface' => false,
			'bricksAdditive' => true,
		];
		$manifest['kiweTokens'] = $kiwe_tokens;
		$manifest['seamTokens'] = $kiwe_tokens;

		return new WP_REST_Response( $manifest, 200 );
	}

	public function registry( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->registry->to_array(), 200 );
	}

	public function can_manage_options( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return 'GET' === $request->get_method() ? true : Origin_Checker::mutation_allowed( $request );
	}

	public function copilot( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->copilot->report(), 200 );
	}

	public function update_links( WP_REST_Request $request ) {
		$current = $this->settings->get( 'link_hub', [] );
		$current = is_array( $current ) ? $current : [];
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : [];

		$next = $this->sanitize_link_hub_settings( $params, $current );
		$this->settings->update( [ 'link_hub' => $next ] );

		return new WP_REST_Response(
			[
				'ok'    => true,
				'links' => $this->links_response_data( $next ),
			],
			200
		);
	}

	public function update_logo( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = $files['logo'] ?? null;

		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'dsa_logo_missing', __( 'Choose a logo image.', 'dsa' ), [ 'status' => 400 ] );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > 4 * MB_IN_BYTES ) {
			return new WP_Error( 'dsa_logo_too_large', __( 'Logo images must be 4 MB or smaller.', 'dsa' ), [ 'status' => 400 ] );
		}

		$allowed_mimes = [
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
			'gif'          => 'image/gif',
		];

		if ( ! function_exists( 'wp_check_filetype_and_ext' ) || ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] ?? '', $allowed_mimes );

		if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], $allowed_mimes, true ) ) {
			return new WP_Error( 'dsa_logo_invalid_type', __( 'Upload a JPG, PNG, WebP, or GIF image.', 'dsa' ), [ 'status' => 400 ] );
		}

		$upload = wp_handle_upload(
			$file,
			[
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			]
		);

		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'dsa_logo_upload_failed', $upload['error'], [ 'status' => 400 ] );
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			],
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_option( Site_Identity_Service::OPTION_LOGO, (int) $attachment_id, false );

		return new WP_REST_Response(
			[
				'ok'   => true,
				'logo' => wp_get_attachment_image_url( $attachment_id, 'full' ),
			],
			200
		);
	}

	private function sanitize_link_hub_settings( array $input, array $current ): array {
		$socials = [];

		foreach ( array_keys( $this->social_link_labels() ) as $id ) {
			$socials[ $id ] = esc_url_raw( $input['socialLinks'][ $id ] ?? '' );
		}

		$review_source = sanitize_key( $input['reviewSource'] ?? 'manual' );
		$google_api_key = sanitize_text_field( $input['googleApiKey'] ?? '' );

		if ( '' === $google_api_key && ! empty( $current['google_api_key'] ) ) {
			$google_api_key = sanitize_text_field( $current['google_api_key'] );
		}

		return [
			'site_score'      => '' === trim( (string) ( $input['siteScore'] ?? '' ) ) ? '' : max( 0, min( 100, absint( $input['siteScore'] ) ) ),
			'shop_label'      => sanitize_text_field( $input['shopLabel'] ?? 'Shop' ),
			'shop_url'        => esc_url_raw( $input['shopUrl'] ?? '' ),
			'posts_title'     => sanitize_text_field( $input['postsTitle'] ?? '' ),
			'posts_category'  => absint( $input['postsCategory'] ?? 0 ),
			'ssl_provider'    => sanitize_text_field( $input['sslProvider'] ?? '' ),
			'payment_provider' => sanitize_text_field( $input['paymentProvider'] ?? '' ),
			'review_source'   => in_array( $review_source, [ 'manual', 'google' ], true ) ? $review_source : 'manual',
			'google_place_id' => sanitize_text_field( $input['googlePlaceId'] ?? '' ),
			'google_api_key'  => $google_api_key,
			'testimonials'    => sanitize_textarea_field( $input['testimonials'] ?? '' ),
			'social_links'    => $socials,
		];
	}

	private function links_response_data( array $config ): array {
		$dock = $this->settings->get( 'dock', [] );
		$commerce = $this->settings->get( 'commerce', [] );
		$commerce_available = $this->links_commerce_available();

		return [
			'logo'    => $this->site_logo_url(),
			'score'   => '' === trim( (string) ( $config['site_score'] ?? '' ) ) ? null : max( 0, min( 100, (int) $config['site_score'] ) ),
			'socials' => $this->social_links( $config ),
			'shop'    => [
				'label' => sanitize_text_field( $config['shop_label'] ?? __( 'Shop', 'dsa' ) ),
				'url'   => $this->shop_url( $config ),
			],
			'postsSection' => $this->posts_section_data( $config ),
			'posts'   => $this->latest_posts( $config ),
			'review'  => $this->reviews->review_data( $config, false ),
			'health'  => $this->trust->health_data( $config ),
			'paymentGateways' => $this->trust->payment_gateways( $config ),
			'commerceAvailable' => $commerce_available,
			'cartAvailable'     => $commerce_available && ! empty( $commerce['cart_surface_enabled'] ) && ! empty( $dock['enabled_items']['cart'] ),
			'canEdit' => true,
			'editor'  => $this->links_editor_data( $config ),
		];
	}

	private function links_editor_data( array $config ): array {
		$raw = isset( $config['social_links'] ) && is_array( $config['social_links'] ) ? $config['social_links'] : [];
		$socials = [];

		foreach ( $this->social_link_labels() as $id => $label ) {
			$socials[] = [
				'id'    => $id,
				'label' => $label,
				'url'   => esc_url_raw( $raw[ $id ] ?? '' ),
			];
		}

		return [
			'siteScore'       => '' === trim( (string) ( $config['site_score'] ?? '' ) ) ? '' : max( 0, min( 100, (int) $config['site_score'] ) ),
			'shopLabel'       => sanitize_text_field( $config['shop_label'] ?? __( 'Shop', 'dsa' ) ),
			'shopUrl'         => esc_url_raw( $config['shop_url'] ?? '' ),
			'postsTitle'      => sanitize_text_field( $config['posts_title'] ?? '' ),
			'postsCategory'   => (int) ( $config['posts_category'] ?? 0 ),
			'categories'      => $this->post_category_options(),
			'sslProvider'     => sanitize_text_field( $config['ssl_provider'] ?? '' ),
			'paymentProvider' => sanitize_text_field( $config['payment_provider'] ?? '' ),
			'reviewSource'    => 'google' === ( $config['review_source'] ?? 'manual' ) ? 'google' : 'manual',
			'googlePlaceId'   => sanitize_text_field( $config['google_place_id'] ?? '' ),
			'hasGoogleApiKey' => ! empty( $config['google_api_key'] ),
			'testimonials'    => sanitize_textarea_field( $config['testimonials'] ?? '' ),
			'socials'         => $socials,
			'adminUrl'        => admin_url( 'admin.php?page=dsa-settings' ),
		];
	}

	private function social_links( array $config ): array {
		$raw = isset( $config['social_links'] ) && is_array( $config['social_links'] ) ? $config['social_links'] : [];
		$out = [];

		foreach ( $this->social_link_labels() as $id => $label ) {
			$url = esc_url_raw( $raw[ $id ] ?? '' );

			if ( '' === $url ) {
				continue;
			}

			$out[] = [
				'id'    => $id,
				'label' => $label,
				'url'   => $url,
			];
		}

		return $out;
	}

	private function social_link_labels(): array {
		return [
			'facebook'  => 'Facebook',
			'instagram' => 'Instagram',
			'x'         => 'X',
			'youtube'   => 'YouTube',
			'pinterest' => 'Pinterest',
			'linkedin'  => 'LinkedIn',
		];
	}

	private function site_logo_url(): string {
		return Site_Identity_Service::logo_url();
	}

	private function shop_url( array $config ): string {
		if ( ! $this->links_commerce_available() ) {
			return '';
		}

		$url = esc_url_raw( $config['shop_url'] ?? '' );

		if ( '' !== $url ) {
			return $url;
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );

			if ( $shop ) {
				return esc_url_raw( $shop );
			}
		}

		return '';
	}

	private function links_commerce_available(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'wc_get_page_permalink' );
	}

	private function posts_section_data( array $config ): array {
		$category = $this->selected_post_category( $config );
		$title = sanitize_text_field( $config['posts_title'] ?? '' );

		if ( '' === $title && $category ) {
			$title = $category->name;
		}

		return [
			'title'      => $title ?: __( 'Latest Posts', 'dsa' ),
			'categoryId' => $category ? (int) $category->term_id : 0,
		];
	}

	private function latest_posts( array $config ): array {
		$args = [
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 8,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		];
		$category = $this->selected_post_category( $config );

		if ( $category ) {
			$args['cat'] = (int) $category->term_id;
		}

		$query = new \WP_Query( $args );
		$posts = [];

		foreach ( $query->posts as $post ) {
			$posts[] = [
				'title' => get_the_title( $post ),
				'url'   => get_permalink( $post ),
				'image' => get_the_post_thumbnail_url( $post, 'medium' ) ?: '',
			];
		}

		wp_reset_postdata();
		return $posts;
	}

	private function selected_post_category( array $config ) {
		$category_id = absint( $config['posts_category'] ?? 0 );

		if ( $category_id ) {
			$category = get_category( $category_id );

			if ( $category && ! is_wp_error( $category ) ) {
				return $category;
			}
		}

		$categories = get_categories(
			[
				'hide_empty' => true,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
				'number'     => 1,
			]
		);

		return ! empty( $categories ) ? $categories[0] : null;
	}

	private function post_category_options(): array {
		$categories = get_categories(
			[
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);
		$out = [
			[
				'id'    => 0,
				'label' => __( 'First available category', 'dsa' ),
			],
		];

		foreach ( $categories as $category ) {
			$out[] = [
				'id'    => (int) $category->term_id,
				'label' => $category->name,
			];
		}

		return $out;
	}

}
