<?php

namespace DSA\AI;

use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Controlled_Mutation_Service {
	private const MAX_BRICKS_JSON_BYTES = 900000;
	private const WOO_SETTING_OPTIONS = [
		'woocommerce_store_address',
		'woocommerce_store_address_2',
		'woocommerce_store_city',
		'woocommerce_store_postcode',
		'woocommerce_default_country',
		'woocommerce_currency',
		'woocommerce_currency_pos',
		'woocommerce_price_thousand_sep',
		'woocommerce_price_decimal_sep',
		'woocommerce_price_num_decimals',
		'woocommerce_shop_page_id',
		'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id',
		'woocommerce_myaccount_page_id',
		'woocommerce_enable_guest_checkout',
		'woocommerce_enable_checkout_login_reminder',
	];

	public function __construct( private ?Settings $settings = null ) {}

	public function supports( string $type ): bool {
		return in_array(
			$type,
			[
				'woocommerce.mutate',
				'woocommerce.product.upsert',
				'woocommerce.order.upsert',
				'woocommerce.settings.patch',
				'cart.run',
				'checkout.run',
				'auth.run',
				'bricks.raw-meta-write',
			],
			true
		);
	}

	public function execute( array $operation, string $type, string $execution_id ): array {
		return match ( $type ) {
			'woocommerce.mutate'         => $this->woocommerce_mutate( $operation, $execution_id ),
			'woocommerce.product.upsert' => $this->upsert_product( $operation, $execution_id ),
			'woocommerce.order.upsert'   => $this->upsert_order( $operation, $execution_id ),
			'woocommerce.settings.patch' => $this->patch_woocommerce_settings( $operation, $execution_id ),
			'cart.run'                   => $this->run_cart( $operation, $execution_id ),
			'checkout.run'               => $this->run_checkout( $operation, $execution_id ),
			'auth.run'                   => $this->run_auth( $operation, $execution_id ),
			'bricks.raw-meta-write'      => $this->write_raw_bricks_meta( $operation, $execution_id ),
			default                      => $this->failure( 'unsupported_controlled_mutation', 'Unsupported controlled mutation operation.' ),
		};
	}

	private function woocommerce_mutate( array $operation, string $execution_id ): array {
		$entity = sanitize_key( (string) ( $operation['entity'] ?? $operation['target'] ?? '' ) );
		$action = sanitize_key( (string) ( $operation['action'] ?? 'upsert' ) );
		if ( 'product' === $entity && 'upsert' === $action ) {
			return $this->upsert_product( array_merge( $operation, [ 'type' => 'woocommerce.product.upsert' ] ), $execution_id );
		}
		if ( 'order' === $entity && 'upsert' === $action ) {
			return $this->upsert_order( array_merge( $operation, [ 'type' => 'woocommerce.order.upsert' ] ), $execution_id );
		}
		if ( 'settings' === $entity && 'patch' === $action ) {
			return $this->patch_woocommerce_settings( array_merge( $operation, [ 'type' => 'woocommerce.settings.patch' ] ), $execution_id );
		}

		return $this->failure( 'unsupported_woocommerce_mutation', 'woocommerce.mutate supports product/upsert, order/upsert, and settings/patch only.' );
	}

	private function upsert_product( array $operation, string $execution_id ): array {
		if ( ! class_exists( '\WC_Product_Simple' ) || ! function_exists( 'wc_get_product' ) ) {
			return $this->failure( 'woocommerce_unavailable', 'WooCommerce product APIs are unavailable.' );
		}

		$product_id = absint( $operation['productId'] ?? 0 );
		$sku        = sanitize_text_field( (string) ( $operation['sku'] ?? '' ) );
		if ( ! $product_id && '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = absint( wc_get_product_id_by_sku( $sku ) );
		}

		if ( $product_id && empty( $operation['allowUpdate'] ) ) {
			return $this->failure( 'product_update_not_allowed', 'Updating an existing WooCommerce product requires allowUpdate true.' );
		}

		$product = $product_id ? wc_get_product( $product_id ) : new \WC_Product_Simple();
		if ( ! $product || ! is_object( $product ) ) {
			return $this->failure( 'product_not_found', 'WooCommerce product was not found.' );
		}

		$name = sanitize_text_field( (string) ( $operation['name'] ?? $operation['title'] ?? '' ) );
		if ( ! $product_id && '' === $name ) {
			return $this->failure( 'missing_product_name', 'Creating a WooCommerce product requires name/title.' );
		}

		if ( '' !== $name && method_exists( $product, 'set_name' ) ) {
			$product->set_name( $name );
		}
		if ( '' !== $sku && method_exists( $product, 'set_sku' ) ) {
			$product->set_sku( $sku );
		}
		if ( isset( $operation['regularPrice'] ) && method_exists( $product, 'set_regular_price' ) ) {
			$product->set_regular_price( $this->decimal( $operation['regularPrice'] ) );
		}
		if ( isset( $operation['salePrice'] ) && method_exists( $product, 'set_sale_price' ) ) {
			$product->set_sale_price( $this->decimal( $operation['salePrice'] ) );
		}
		if ( isset( $operation['description'] ) && method_exists( $product, 'set_description' ) ) {
			$product->set_description( wp_kses_post( (string) $operation['description'] ) );
		}
		if ( isset( $operation['shortDescription'] ) && method_exists( $product, 'set_short_description' ) ) {
			$product->set_short_description( wp_kses_post( (string) $operation['shortDescription'] ) );
		}
		if ( isset( $operation['manageStock'] ) && method_exists( $product, 'set_manage_stock' ) ) {
			$product->set_manage_stock( ! empty( $operation['manageStock'] ) );
		}
		if ( isset( $operation['stockQuantity'] ) && method_exists( $product, 'set_stock_quantity' ) ) {
			$product->set_stock_quantity( max( 0, absint( $operation['stockQuantity'] ) ) );
		}
		if ( isset( $operation['stockStatus'] ) && method_exists( $product, 'set_stock_status' ) ) {
			$stock_status = sanitize_key( (string) $operation['stockStatus'] );
			$product->set_stock_status( in_array( $stock_status, [ 'instock', 'outofstock', 'onbackorder' ], true ) ? $stock_status : 'instock' );
		}
		if ( method_exists( $product, 'set_status' ) ) {
			$product->set_status( $this->post_status( $operation ) );
		}
		if ( method_exists( $product, 'update_meta_data' ) ) {
			$product->update_meta_data( '_kiwe_ai_staging_execution', $execution_id );
		}

		$result = $product->save();
		if ( is_wp_error( $result ) ) {
			return $this->failure( 'product_save_failed', $result->get_error_message() );
		}

		$product_id = absint( method_exists( $product, 'get_id' ) ? $product->get_id() : $result );
		$this->set_product_terms( $product_id, $operation );

		return [
			'ok'        => true,
			'type'      => 'woocommerce.product.upsert',
			'productId' => $product_id,
			'status'    => get_post_status( $product_id ),
			'sku'       => method_exists( $product, 'get_sku' ) ? sanitize_text_field( (string) $product->get_sku() ) : '',
			'url'       => 'publish' === get_post_status( $product_id ) ? esc_url_raw( get_permalink( $product_id ) ) : '',
			'editUrl'   => esc_url_raw( get_edit_post_link( $product_id, 'raw' ) ?: '' ),
			'published' => 'publish' === get_post_status( $product_id ),
		];
	}

	private function upsert_order( array $operation, string $execution_id ): array {
		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_order' ) ) {
			return $this->failure( 'woocommerce_unavailable', 'WooCommerce order APIs are unavailable.' );
		}

		$order_id = absint( $operation['orderId'] ?? 0 );
		if ( $order_id && empty( $operation['allowUpdate'] ) ) {
			return $this->failure( 'order_update_not_allowed', 'Updating an existing WooCommerce order requires allowUpdate true.' );
		}
		if ( ! $order_id && empty( $operation['confirmCreateOrder'] ) ) {
			return $this->failure( 'order_create_not_confirmed', 'Creating a WooCommerce order requires confirmCreateOrder true.' );
		}

		$order = $order_id ? wc_get_order( $order_id ) : wc_create_order();
		if ( is_wp_error( $order ) ) {
			return $this->failure( 'order_create_failed', $order->get_error_message() );
		}
		if ( ! $order || ! is_object( $order ) ) {
			return $this->failure( 'order_not_found', 'WooCommerce order was not found.' );
		}

		if ( ! $order_id && isset( $operation['lineItems'] ) && is_array( $operation['lineItems'] ) ) {
			foreach ( array_slice( $operation['lineItems'], 0, 40 ) as $item ) {
				if ( ! is_array( $item ) || ! function_exists( 'wc_get_product' ) ) {
					continue;
				}
				$product = wc_get_product( absint( $item['productId'] ?? 0 ) );
				if ( $product ) {
					$order->add_product( $product, max( 1, min( 99, absint( $item['quantity'] ?? 1 ) ) ) );
				}
			}
		}

		if ( isset( $operation['customerId'] ) && method_exists( $order, 'set_customer_id' ) ) {
			$order->set_customer_id( absint( $operation['customerId'] ) );
		}
		foreach ( [ 'billing', 'shipping' ] as $address_type ) {
			if ( isset( $operation[ $address_type ] ) && is_array( $operation[ $address_type ] ) ) {
				$order->set_address( $this->sanitize_address( $operation[ $address_type ] ), $address_type );
			}
		}
		if ( isset( $operation['customerNote'] ) && method_exists( $order, 'set_customer_note' ) ) {
			$order->set_customer_note( sanitize_textarea_field( (string) $operation['customerNote'] ) );
		}
		$status = sanitize_key( (string) ( $operation['status'] ?? 'pending' ) );
		if ( 'completed' === $status && empty( $operation['confirmCompletedOrder'] ) ) {
			$status = 'processing';
		}
		if ( in_array( $status, [ 'pending', 'on-hold', 'processing', 'cancelled', 'refunded', 'failed', 'completed' ], true ) ) {
			$order->set_status( $status, 'Kiwe AI staging executor status set.', true );
		}
		$order->update_meta_data( '_kiwe_ai_staging_execution', $execution_id );
		$order->calculate_totals();
		$order->save();

		return [
			'ok'      => true,
			'type'    => 'woocommerce.order.upsert',
			'orderId' => absint( $order->get_id() ),
			'status'  => sanitize_key( (string) $order->get_status() ),
			'total'   => sanitize_text_field( (string) $order->get_total() ),
			'editUrl' => esc_url_raw( get_edit_post_link( absint( $order->get_id() ), 'raw' ) ?: '' ),
		];
	}

	private function patch_woocommerce_settings( array $operation, string $execution_id ): array {
		if ( ! class_exists( '\WooCommerce' ) && ! function_exists( 'WC' ) ) {
			return $this->failure( 'woocommerce_unavailable', 'WooCommerce is unavailable.' );
		}
		$patch = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : ( isset( $operation['patch'] ) && is_array( $operation['patch'] ) ? $operation['patch'] : [] );
		if ( [] === $patch ) {
			return $this->failure( 'missing_settings_patch', 'WooCommerce settings patch requires settings or patch.' );
		}

		$changed = [];
		foreach ( $patch as $option => $value ) {
			$option = sanitize_key( (string) $option );
			if ( ! in_array( $option, self::WOO_SETTING_OPTIONS, true ) ) {
				return $this->failure( 'woocommerce_setting_not_allowed', sprintf( 'WooCommerce setting %s is not in the controlled staging allow-list.', $option ) );
			}
			$previous = get_option( $option, null );
			$next     = $this->sanitize_option_value( $option, $value );
			update_option( $option, $next, false );
			$changed[] = [
				'option'       => $option,
				'previousHash' => hash( 'sha256', wp_json_encode( $previous ) ?: '' ),
				'nextHash'     => hash( 'sha256', wp_json_encode( $next ) ?: '' ),
			];
		}
		$this->append_log( 'dsa_ai_staging_woocommerce_settings_patches', [ 'executionId' => $execution_id, 'changed' => $changed ] );

		return [
			'ok'      => true,
			'type'    => 'woocommerce.settings.patch',
			'changed' => $changed,
		];
	}

	private function run_cart( array $operation, string $execution_id ): array {
		if ( ! $this->ensure_cart() ) {
			return $this->failure( 'cart_unavailable', 'WooCommerce cart/session APIs are unavailable.' );
		}

		$action = sanitize_key( (string) ( $operation['action'] ?? 'snapshot' ) );
		if ( 'clear' === $action ) {
			WC()->cart->empty_cart();
		} elseif ( 'add' === $action ) {
			$product_id   = absint( $operation['productId'] ?? 0 );
			$variation_id = absint( $operation['variationId'] ?? 0 );
			$quantity     = max( 1, min( 99, absint( $operation['quantity'] ?? 1 ) ) );
			$product      = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ?: $product_id ) : null;
			if ( ! $product ) {
				return $this->failure( 'cart_product_not_found', 'Cart add product was not found.' );
			}
			$key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
			if ( ! $key ) {
				return $this->failure( 'cart_add_failed', 'WooCommerce refused the cart add operation.' );
			}
		} elseif ( 'set_quantity' === $action ) {
			$item_key = sanitize_text_field( (string) ( $operation['cartItemKey'] ?? '' ) );
			if ( '' === $item_key || ! WC()->cart->set_quantity( $item_key, max( 0, min( 99, absint( $operation['quantity'] ?? 1 ) ) ), true ) ) {
				return $this->failure( 'cart_quantity_failed', 'Cart quantity update failed.' );
			}
		} elseif ( 'remove' === $action ) {
			$item_key = sanitize_text_field( (string) ( $operation['cartItemKey'] ?? '' ) );
			if ( '' === $item_key || ! WC()->cart->remove_cart_item( $item_key ) ) {
				return $this->failure( 'cart_remove_failed', 'Cart item removal failed.' );
			}
		} elseif ( 'snapshot' !== $action ) {
			return $this->failure( 'unsupported_cart_action', 'cart.run supports snapshot, clear, add, set_quantity, and remove.' );
		}

		WC()->cart->calculate_totals();
		if ( method_exists( WC()->cart, 'set_session' ) ) {
			WC()->cart->set_session();
		}

		return [
			'ok'        => true,
			'type'      => 'cart.run',
			'action'    => $action,
			'execution' => $execution_id,
			'cart'      => $this->cart_snapshot(),
		];
	}

	private function run_checkout( array $operation, string $execution_id ): array {
		if ( ! function_exists( 'WC' ) ) {
			return $this->failure( 'woocommerce_unavailable', 'WooCommerce checkout APIs are unavailable.' );
		}

		$action = sanitize_key( (string) ( $operation['action'] ?? 'validate_fields' ) );
		$fields = isset( $operation['fields'] ) && is_array( $operation['fields'] ) ? $operation['fields'] : [];
		$validation = $this->validate_checkout_fields( $fields );

		if ( 'validate_fields' === $action ) {
			return [
				'ok'         => [] === $validation,
				'type'       => 'checkout.run',
				'action'     => 'validate_fields',
				'errors'     => $validation,
				'cart'       => $this->ensure_cart() ? $this->cart_snapshot() : [],
				'createsOrder' => false,
			];
		}

		if ( 'create_pending_order' !== $action ) {
			return $this->failure( 'unsupported_checkout_action', 'checkout.run supports validate_fields and create_pending_order.' );
		}
		if ( ! empty( $validation ) ) {
			return $this->failure( 'checkout_validation_failed', 'Checkout fields did not pass validation.' );
		}
		if ( empty( $operation['confirmCreateOrder'] ) ) {
			return $this->failure( 'checkout_order_not_confirmed', 'Creating a pending checkout order requires confirmCreateOrder true.' );
		}

		$order_operation = [
			'confirmCreateOrder' => true,
			'status'             => 'pending',
			'billing'            => $fields,
			'shipping'           => isset( $operation['shipping'] ) && is_array( $operation['shipping'] ) ? $operation['shipping'] : $fields,
			'lineItems'          => isset( $operation['lineItems'] ) && is_array( $operation['lineItems'] ) ? $operation['lineItems'] : [],
		];
		if ( [] === $order_operation['lineItems'] && $this->ensure_cart() ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$order_operation['lineItems'][] = [
					'productId' => absint( $item['variation_id'] ?? 0 ) ?: absint( $item['product_id'] ?? 0 ),
					'quantity'  => absint( $item['quantity'] ?? 1 ),
				];
			}
		}
		$result = $this->upsert_order( $order_operation, $execution_id );
		$result['type'] = 'checkout.run';
		$result['action'] = 'create_pending_order';
		$result['createsOrder'] = true;

		return $result;
	}

	private function run_auth( array $operation, string $execution_id ): array {
		$action = sanitize_key( (string) ( $operation['action'] ?? 'probe' ) );
		if ( 'probe' === $action ) {
			return [
				'ok'          => true,
				'type'        => 'auth.run',
				'action'      => 'probe',
				'currentUser' => [
					'id'          => get_current_user_id(),
					'isLoggedIn'  => is_user_logged_in(),
					'canManage'   => current_user_can( 'manage_options' ),
					'canCommerce' => current_user_can( 'manage_woocommerce' ),
				],
			];
		}

		if ( 'create_test_user' === $action ) {
			if ( empty( $operation['confirmAuthRuntime'] ) ) {
				return $this->failure( 'auth_runtime_not_confirmed', 'Creating a test user requires confirmAuthRuntime true.' );
			}
			$email = sanitize_email( (string) ( $operation['email'] ?? '' ) );
			if ( ! is_email( $email ) ) {
				return $this->failure( 'invalid_email', 'A valid email is required for test user creation.' );
			}
			if ( email_exists( $email ) ) {
				return $this->failure( 'user_exists', 'A user with this email already exists.' );
			}
			$email_parts = explode( '@', $email );
			$username = sanitize_user( (string) ( $operation['username'] ?? ( $email_parts[0] ?? 'kiwe-test' ) ), true );
			if ( username_exists( $username ) ) {
				$username .= '_' . substr( hash( 'sha256', $email . microtime( true ) ), 0, 6 );
			}
			$role = sanitize_key( (string) ( $operation['role'] ?? 'customer' ) );
			if ( ! in_array( $role, [ 'subscriber', 'customer' ], true ) ) {
				$role = 'customer';
			}
			$user_id = wp_insert_user(
				[
					'user_login'   => $username,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'display_name' => sanitize_text_field( (string) ( $operation['displayName'] ?? $username ) ),
					'role'         => $role,
				]
			);
			if ( is_wp_error( $user_id ) ) {
				return $this->failure( 'user_create_failed', $user_id->get_error_message() );
			}
			update_user_meta( absint( $user_id ), '_kiwe_ai_staging_execution', $execution_id );

			return [
				'ok'       => true,
				'type'     => 'auth.run',
				'action'   => 'create_test_user',
				'userId'   => absint( $user_id ),
				'email'    => $email,
				'role'     => $role,
				'passwordReturned' => false,
			];
		}

		if ( 'delete_test_user' === $action ) {
			if ( empty( $operation['confirmAuthRuntime'] ) || empty( $operation['confirmDeleteTestUser'] ) ) {
				return $this->failure( 'auth_delete_not_confirmed', 'Deleting a test user requires confirmAuthRuntime and confirmDeleteTestUser true.' );
			}
			$user_id = absint( $operation['userId'] ?? 0 );
			if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
				return $this->failure( 'user_not_found', 'Test user was not found.' );
			}
			if ( '' === (string) get_user_meta( $user_id, '_kiwe_ai_staging_execution', true ) ) {
				return $this->failure( 'user_not_kiwe_test_user', 'Only Kiwe AI-created staging test users can be deleted by auth.run.' );
			}
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$deleted = wp_delete_user( $user_id );

			return [
				'ok'     => (bool) $deleted,
				'type'   => 'auth.run',
				'action' => 'delete_test_user',
				'userId' => $user_id,
			];
		}

		return $this->failure( 'unsupported_auth_action', 'auth.run supports probe, create_test_user, and delete_test_user.' );
	}

	private function write_raw_bricks_meta( array $operation, string $execution_id ): array {
		$post_id = absint( $operation['postId'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $this->failure( 'target_post_not_found', 'Raw Bricks write requires an existing target postId.' );
		}
		if ( empty( $operation['allowUpdate'] ) ) {
			return $this->failure( 'raw_bricks_update_not_allowed', 'Raw Bricks writes require allowUpdate true.' );
		}
		$meta_key = $this->allowed_bricks_meta_key( (string) ( $operation['metaKey'] ?? '' ) );
		if ( '' === $meta_key ) {
			return $this->failure( 'bricks_meta_key_not_allowed', 'Raw Bricks write requires an allowed Bricks meta key.' );
		}
		$value = $operation['value'] ?? $operation['bricksJson'] ?? null;
		if ( ! is_array( $value ) ) {
			return $this->failure( 'invalid_bricks_json', 'Raw Bricks meta value must be a JSON object/array decoded as an array.' );
		}
		if ( strlen( wp_json_encode( $value ) ?: '' ) > self::MAX_BRICKS_JSON_BYTES ) {
			return $this->failure( 'bricks_json_too_large', 'Raw Bricks JSON exceeds controlled size budget.' );
		}

		$previous = get_post_meta( $post_id, $meta_key, true );
		$next     = $this->sanitize_bricks_payload( $value, $meta_key );
		$backup_key = '_kiwe_ai_bricks_raw_backup_' . substr( hash( 'sha256', $execution_id . '|' . $meta_key ), 0, 12 );
		update_post_meta(
			$post_id,
			$backup_key,
			[
				'metaKey'      => $meta_key,
				'previous'     => $previous,
				'previousHash' => hash( 'sha256', wp_json_encode( $previous ) ?: '' ),
				'capturedAt'   => gmdate( 'c' ),
				'executionId'  => $execution_id,
			]
		);
		update_post_meta( $post_id, $meta_key, $next );
		update_post_meta( $post_id, '_kiwe_ai_staging_execution', $execution_id );
		$this->append_log(
			'dsa_ai_staging_bricks_raw_writes',
			[
				'executionId'  => $execution_id,
				'postId'       => $post_id,
				'metaKey'      => $meta_key,
				'backupMetaKey' => $backup_key,
				'previousHash' => hash( 'sha256', wp_json_encode( $previous ) ?: '' ),
				'nextHash'     => hash( 'sha256', wp_json_encode( $next ) ?: '' ),
			]
		);

		return [
			'ok'            => true,
			'type'          => 'bricks.raw-meta-write',
			'postId'        => $post_id,
			'metaKey'       => $meta_key,
			'backupMetaKey' => $backup_key,
			'previousHash'  => hash( 'sha256', wp_json_encode( $previous ) ?: '' ),
			'nextHash'      => hash( 'sha256', wp_json_encode( $next ) ?: '' ),
			'rawBricksMetaWritten' => true,
		];
	}

	private function set_product_terms( int $product_id, array $operation ): void {
		foreach ( [ 'categoryIds' => 'product_cat', 'tagIds' => 'product_tag' ] as $field => $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) && isset( $operation[ $field ] ) && is_array( $operation[ $field ] ) ) {
				wp_set_object_terms( $product_id, array_map( 'absint', $operation[ $field ] ), $taxonomy, false );
			}
		}
	}

	private function ensure_cart(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
			wc_load_cart();
		}
		if ( WC()->session && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}

		return (bool) WC()->cart;
	}

	private function cart_snapshot(): array {
		$items = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;
			$items[] = [
				'key'       => sanitize_text_field( (string) $key ),
				'productId' => absint( $item['product_id'] ?? 0 ),
				'variationId' => absint( $item['variation_id'] ?? 0 ),
				'name'      => $product && method_exists( $product, 'get_name' ) ? sanitize_text_field( (string) $product->get_name() ) : '',
				'quantity'  => absint( $item['quantity'] ?? 0 ),
				'lineTotal' => sanitize_text_field( (string) ( $item['line_total'] ?? '' ) ),
			];
		}

		return [
			'count'    => absint( WC()->cart->get_cart_contents_count() ),
			'totalRaw' => sanitize_text_field( (string) WC()->cart->get_total( 'edit' ) ),
			'hash'     => sanitize_text_field( (string) WC()->cart->get_cart_hash() ),
			'items'    => $items,
		];
	}

	private function validate_checkout_fields( array $fields ): array {
		$errors = [];
		foreach ( [ 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_address_1', 'billing_city', 'billing_postcode', 'billing_country' ] as $required ) {
			if ( '' === trim( (string) ( $fields[ $required ] ?? '' ) ) ) {
				$errors[] = $required . ' is required.';
			}
		}
		if ( isset( $fields['billing_email'] ) && ! is_email( (string) $fields['billing_email'] ) ) {
			$errors[] = 'billing_email must be valid.';
		}

		return $errors;
	}

	private function sanitize_address( array $address ): array {
		$out = [];
		foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ] as $field ) {
			$source_key = $field;
			$billing_key = 'billing_' . $field;
			if ( isset( $address[ $billing_key ] ) ) {
				$source_key = $billing_key;
			} elseif ( isset( $address[ 'shipping_' . $field ] ) ) {
				$source_key = 'shipping_' . $field;
			}
			if ( ! isset( $address[ $source_key ] ) ) {
				continue;
			}
			$value = (string) $address[ $source_key ];
			$out[ $field ] = 'email' === $field ? sanitize_email( $value ) : sanitize_text_field( $value );
		}

		return $out;
	}

	private function sanitize_bricks_payload( array $value, string $meta_key ): array {
		if ( class_exists( '\Bricks\Helpers' ) && is_callable( [ '\Bricks\Helpers', 'sanitize_bricks_data' ] ) && str_contains( $meta_key, 'content' ) ) {
			$sanitized = \Bricks\Helpers::sanitize_bricks_data( $value );
			return is_array( $sanitized ) ? $sanitized : [];
		}

		return $this->sanitize_nested_payload( $value );
	}

	private function allowed_bricks_meta_key( string $requested ): string {
		$allowed = [
			defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2',
			defined( 'BRICKS_DB_PAGE_HEADER' ) ? BRICKS_DB_PAGE_HEADER : '_bricks_page_header_2',
			defined( 'BRICKS_DB_PAGE_FOOTER' ) ? BRICKS_DB_PAGE_FOOTER : '_bricks_page_footer_2',
			defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings',
			defined( 'BRICKS_DB_EDITOR_MODE' ) ? BRICKS_DB_EDITOR_MODE : '_bricks_editor_mode',
		];
		$legacy = [ '_bricks_page_content', '_bricks_page_header', '_bricks_page_footer' ];
		$allowed = array_values( array_unique( array_merge( $allowed, $legacy ) ) );

		return in_array( $requested, $allowed, true ) ? $requested : '';
	}

	private function post_status( array $operation ): string {
		$status = sanitize_key( (string) ( $operation['status'] ?? 'draft' ) );
		if ( 'publish' === $status && empty( $operation['publishOnStaging'] ) ) {
			return 'draft';
		}

		return in_array( $status, [ 'draft', 'private', 'publish' ], true ) ? $status : 'draft';
	}

	private function decimal( mixed $value ): string {
		return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value ) : sanitize_text_field( (string) $value );
	}

	private function sanitize_option_value( string $option, mixed $value ): mixed {
		if ( str_ends_with( $option, '_page_id' ) ) {
			return absint( $value );
		}
		if ( 'woocommerce_price_num_decimals' === $option ) {
			return max( 0, min( 6, absint( $value ) ) );
		}
		if ( in_array( $option, [ 'woocommerce_enable_guest_checkout', 'woocommerce_enable_checkout_login_reminder' ], true ) ) {
			return ! empty( $value ) ? 'yes' : 'no';
		}

		return sanitize_text_field( (string) $value );
	}

	private function sanitize_nested_payload( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 8 ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $key => $nested ) {
				$clean_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
				if ( '' === (string) $clean_key || preg_match( '/script|code|php|password|secret|token|key|license|nonce/i', (string) $clean_key ) ) {
					continue;
				}
				$out[ $clean_key ] = $this->sanitize_nested_payload( $nested, $depth + 1 );
			}

			return $out;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			$string = substr( (string) $value, 0, 12000 );
			if ( preg_match( '/<\\s*script|javascript:|on[a-z]+\\s*=|data:text\\/html/i', $string ) ) {
				return '';
			}

			return sanitize_text_field( $string );
		}

		return null;
	}

	private function append_log( string $option, array $entry ): void {
		$history   = get_option( $option, [] );
		$history   = is_array( $history ) ? array_slice( $history, -49 ) : [];
		$entry['createdAt'] = gmdate( 'c' );
		$history[] = $entry;
		update_option( $option, $history, false );
	}

	private function failure( string $code, string $message ): array {
		return [
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
		];
	}
}
