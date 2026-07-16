<?php

namespace DSA\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Account_Controller {
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		foreach ( [
			'/account/orders'    => [ 'methods' => 'GET', 'callback' => 'orders' ],
			'/account/downloads' => [ 'methods' => 'GET', 'callback' => 'downloads' ],
			'/account/addresses' => [ 'methods' => 'GET', 'callback' => 'addresses' ],
			'/account/profile'   => [ 'methods' => 'POST', 'callback' => 'update_profile' ],
			'/account/email-change/verify' => [ 'methods' => 'POST', 'callback' => 'verify_email_change' ],
			'/account/address'   => [ 'methods' => 'POST', 'callback' => 'update_address' ],
			'/account/avatar'    => [ 'methods' => 'POST', 'callback' => 'update_avatar' ],
			'/account/password-reset' => [ 'methods' => 'POST', 'callback' => 'password_reset' ],
		] as $route => $args ) {
			register_rest_route(
				'dsa/v1',
				$route,
				[
					'methods'             => $args['methods'],
					'callback'            => [ $this, $args['callback'] ],
					'permission_callback' => [ $this, 'can_access' ],
				]
			);
		}
	}

	public function can_access( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return 'GET' === $request->get_method() ? true : Origin_Checker::mutation_allowed( $request );
	}

	public function update_profile( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$email   = sanitize_email( (string) $request->get_param( 'email' ) );
		$first   = sanitize_text_field( (string) $request->get_param( 'firstName' ) );
		$last    = sanitize_text_field( (string) $request->get_param( 'lastName' ) );
		$name    = sanitize_text_field( (string) $request->get_param( 'displayName' ) );

		if ( $email && ! is_email( $email ) ) {
			return new WP_Error( 'dsa_invalid_email', __( 'Enter a valid email address.', 'dsa' ), [ 'status' => 400 ] );
		}

		if ( $email && email_exists( $email ) && (int) email_exists( $email ) !== $user_id ) {
			return new WP_Error( 'dsa_email_exists', __( 'That email address is already used by another account.', 'dsa' ), [ 'status' => 409 ] );
		}

		update_user_meta( $user_id, 'first_name', $first );
		update_user_meta( $user_id, 'last_name', $last );

		$current_user = get_userdata( $user_id );
		$display_name = $name ?: trim( $first . ' ' . $last );

		if ( '' === $display_name && $current_user ) {
			$display_name = $current_user->display_name;
		}

		$update = [
			'ID'           => $user_id,
			'display_name' => $display_name,
		];

		$result = wp_update_user( $update );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = [ 'ok' => true, 'email' => $current_user ? $current_user->user_email : '' ];
		if ( '' !== $email && $current_user && strtolower( $email ) !== strtolower( (string) $current_user->user_email ) ) {
			if ( function_exists( 'pk_is_privileged' ) && pk_is_privileged( $user_id ) ) {
				$password = (string) $request->get_param( 'currentPassword' );
				if ( '' === $password || ! wp_check_password( $password, $current_user->user_pass, $user_id ) ) {
					return new WP_Error( 'dsa_email_step_up_required', __( 'Enter your current WordPress password before changing an administrator email.', 'dsa' ), [ 'status' => 403 ] );
				}
			}
			if ( ! function_exists( 'pk_begin_account_email_change' ) ) {
				return new WP_Error( 'dsa_email_verifier_unavailable', __( 'Email verification is temporarily unavailable.', 'dsa' ), [ 'status' => 503 ] );
			}
			$pending = pk_begin_account_email_change( $user_id, $email );
			if ( is_wp_error( $pending ) ) {
				return $pending;
			}
			$response['emailChange'] = [
				'pending'  => true,
				'token'    => $pending['token'],
				'accepted' => (bool) $pending['accepted'],
				'expires'  => (int) $pending['expires'],
			];
		}

		return new WP_REST_Response( $response, 200 );
	}

	public function verify_email_change( WP_REST_Request $request ) {
		if ( ! function_exists( 'pk_complete_account_email_change' ) ) {
			return new WP_Error( 'dsa_email_verifier_unavailable', __( 'Email verification is temporarily unavailable.', 'dsa' ), [ 'status' => 503 ] );
		}

		$result = pk_complete_account_email_change(
			get_current_user_id(),
			(string) $request->get_param( 'token' ),
			(string) $request->get_param( 'code' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( [ 'ok' => true, 'email' => $result['email'], 'trustRevoked' => true ], 200 );
	}

	public function update_avatar( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = $files['avatar'] ?? null;

		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'dsa_avatar_missing', __( 'Choose an avatar image.', 'dsa' ), [ 'status' => 400 ] );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > 2 * MB_IN_BYTES ) {
			return new WP_Error( 'dsa_avatar_too_large', __( 'Avatar images must be 2 MB or smaller.', 'dsa' ), [ 'status' => 400 ] );
		}

		$allowed_mimes = [
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
			'gif'          => 'image/gif',
		];

		if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] ?? '', $allowed_mimes );

		if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], $allowed_mimes, true ) ) {
			return new WP_Error( 'dsa_avatar_invalid_type', __( 'Upload a JPG, PNG, WebP, or GIF image.', 'dsa' ), [ 'status' => 400 ] );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_handle_upload(
			$file,
			[
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			]
		);

		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'dsa_avatar_upload_failed', $upload['error'], [ 'status' => 400 ] );
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
		update_user_meta( get_current_user_id(), 'kiwe_avatar_id', (int) $attachment_id );

		return new WP_REST_Response(
			[
				'ok'     => true,
				'avatar' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			],
			200
		);
	}

	public function password_reset(): WP_REST_Response {
		$user = wp_get_current_user();

		if ( ! $user || ! $user->ID ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => __( 'You must be logged in to reset your password.', 'dsa' ) ], 401 );
		}

		$rate_key = 'dsa_password_reset_' . md5( $user->ID . '|' . $this->remote_addr() );
		if ( get_transient( $rate_key ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => __( 'A password reset email was already sent. Try again in a few minutes.', 'dsa' ) ], 429 );
		}
		set_transient( $rate_key, 1, 5 * MINUTE_IN_SECONDS );

		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => $key->get_error_message() ], 400 );
		}

		$reset_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Password reset', 'dsa' ),
			wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
		);
		$message = sprintf(
			/* translators: %s: reset URL. */
			__( "Use this link to reset your password:\n\n%s", 'dsa' ),
			$reset_url
		);

		wp_mail( $user->user_email, $subject, $message );

		return new WP_REST_Response( [ 'ok' => true, 'message' => __( 'Password reset email sent.', 'dsa' ) ], 200 );
	}

	public function orders(): WP_REST_Response {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_REST_Response( [ 'orders' => [] ], 200 );
		}

		$orders = wc_get_orders(
			[
				'customer_id' => get_current_user_id(),
				'limit'       => 10,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'status'      => array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-checkout-draft', 'wc-auto-draft' ] ),
			]
		);

		$data = [];

		foreach ( $orders as $order ) {
			if ( in_array( $order->get_status(), [ 'checkout-draft', 'auto-draft', 'draft' ], true ) ) {
				continue;
			}

			$items = [];

			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				$image_id = $product && method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
				$items[] = [
					'name'     => wp_strip_all_tags( $item->get_name() ),
					'quantity' => (int) $item->get_quantity(),
					'total'    => $this->money_text( wc_price( (float) $item->get_total(), [ 'currency' => $order->get_currency() ] ) ),
					'image'    => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src() : '' ),
				];
			}

			$data[] = [
				'id'     => $order->get_id(),
				'number' => $order->get_order_number(),
				'date'   => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
				'status' => wc_get_order_status_name( $order->get_status() ),
				'total'  => $this->money_text( $order->get_formatted_order_total() ),
				'items'  => $items,
			];
		}

		return new WP_REST_Response( [ 'orders' => $data ], 200 );
	}

	public function downloads(): WP_REST_Response {
		if ( ! function_exists( 'wc_get_customer_available_downloads' ) ) {
			return new WP_REST_Response( [ 'downloads' => [] ], 200 );
		}

		$data = [];

		foreach ( wc_get_customer_available_downloads( get_current_user_id() ) as $download ) {
			$data[] = [
				'productName' => wp_strip_all_tags( $download['product_name'] ?? '' ),
				'downloadName' => wp_strip_all_tags( $download['download_name'] ?? '' ),
				'url'         => esc_url_raw( $download['download_url'] ?? '' ),
				'remaining'   => isset( $download['downloads_remaining'] ) ? (string) $download['downloads_remaining'] : '',
			];
		}

		return new WP_REST_Response( [ 'downloads' => $data ], 200 );
	}

	public function addresses(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'billing'  => $this->address_data( 'billing' ),
				'shipping' => $this->address_data( 'shipping' ),
			],
			200
		);
	}

	public function update_address( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );

		if ( ! in_array( $type, [ 'billing', 'shipping' ], true ) ) {
			return new WP_Error( 'dsa_invalid_address_type', __( 'Invalid address type.', 'dsa' ), [ 'status' => 400 ] );
		}

		$values = [];

		foreach ( $this->address_fields() as $field ) {
			$value = (string) $request->get_param( $field );
			$values[ $field ] = $this->sanitize_address_value( $field, $value );

			if ( 'email' === $field && '' !== $value && ! is_email( $values[ $field ] ) ) {
				return new WP_Error( 'dsa_invalid_address_email', __( 'Enter a valid email address.', 'dsa' ), [ 'status' => 400 ] );
			}
		}

		$stored = $this->persist_woocommerce_address( get_current_user_id(), $type, $values );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		if ( false === $stored ) {
			foreach ( $values as $field => $value ) {
				update_user_meta( get_current_user_id(), $type . '_' . $field, $value );
			}
		}

		return new WP_REST_Response( [ 'ok' => true, $type => $this->address_data( $type ) ], 200 );
	}

	private function address_data( string $type ): array {
		$data = [];
		$customer = null;

		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$customer = new \WC_Customer( get_current_user_id() );
			} catch ( \Throwable $e ) {
				$customer = null;
			}
		}

		foreach ( $this->address_fields() as $field ) {
			$getter = 'get_' . $type . '_' . $field;

			if ( $customer instanceof \WC_Customer && is_callable( [ $customer, $getter ] ) ) {
				$data[ $field ] = (string) $customer->{$getter}();
				continue;
			}

			$data[ $field ] = (string) get_user_meta( get_current_user_id(), $type . '_' . $field, true );
		}

		return $data;
	}

	private function address_fields(): array {
		return [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ];
	}

	private function sanitize_address_value( string $field, string $value ): string {
		return match ( $field ) {
			'email' => sanitize_email( $value ),
			'phone' => function_exists( 'wc_sanitize_phone_number' ) ? wc_sanitize_phone_number( $value ) : sanitize_text_field( $value ),
			default => sanitize_text_field( $value ),
		};
	}

	private function persist_woocommerce_address( int $user_id, string $type, array $values ): bool|WP_Error {
		if ( ! class_exists( 'WC_Customer' ) ) {
			return false;
		}

		try {
			$customer = new \WC_Customer( $user_id );
			$fallback = [];

			foreach ( $values as $field => $value ) {
				$setter = 'set_' . $type . '_' . $field;

				if ( is_callable( [ $customer, $setter ] ) ) {
					$customer->{$setter}( $value );
					continue;
				}

				$fallback[ $field ] = $value;
			}

			$customer->save();

			foreach ( $fallback as $field => $value ) {
				update_user_meta( $user_id, $type . '_' . $field, $value );
			}
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'dsa_address_save_failed',
				__( 'The address could not be saved. Check the address details and try again.', 'dsa' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	private function money_text( string $html ): string {
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, $charset );
	}

	private function remote_addr(): string {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	}
}
