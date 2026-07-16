<?php

namespace DSA\Commerce;

use DSA\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Checkout_Service {
	private const DRAFT_KEY = 'dsa_checkout_draft';
	private const ERROR_KEY = 'dsa_checkout_errors';

	private $settings;
	private $cart_payload;

	public function __construct( Settings $settings, ?Cart_Payload_Service $cart_payload = null ) {
		$this->settings = $settings;
		$this->cart_payload = $cart_payload;
	}

	public function register(): void {
		add_filter( 'woocommerce_checkout_get_value', [ $this, 'checkout_value' ], 5, 2 );
		add_filter( 'woocommerce_ship_to_different_address_checked', [ $this, 'ship_to_different_address_checked' ], 5 );
		add_filter( 'woocommerce_checkout_posted_data', [ $this, 'merge_draft_into_posted_data' ], 5 );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'capture_checkout_errors' ], 90, 2 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'clear_after_order' ], 90 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'clear_after_order' ], 90 );
	}

	public function enabled(): bool {
		$commerce = $this->settings->get( 'commerce', [] );
		return ! empty( $commerce['checkout_surface_enabled'] );
	}

	public function available(): bool {
		return $this->enabled() && $this->load_checkout();
	}

	public function contract( bool $consume_errors = false ): array {
		if ( ! $this->available() ) {
			return [
				'available'   => false,
				'enabled'     => $this->enabled(),
				'groups'      => [],
				'values'      => [],
				'errors'      => [],
				'notices'     => [],
				'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? esc_url_raw( wc_get_checkout_url() ) : '',
			];
		}

		$this->prepare_cart_totals();
		$groups = $this->field_groups();
		$errors = $this->stored_errors();

		if ( $consume_errors ) {
			$this->set_session_value( self::ERROR_KEY, [] );
		}

		$values = [];
		foreach ( $groups as $fields ) {
			foreach ( $fields as $field ) {
				$values[ $field['key'] ] = $field['value'];
			}
		}
		$values['ship_to_different_address'] = $this->ship_to_different_address() ? '1' : '0';
		$values['createaccount'] = $this->create_account() ? '1' : '0';

		return [
			'available'     => true,
			'hasDraft'      => ! empty( $this->draft() ),
			'enabled'       => true,
			'groups'        => $groups,
			'values'        => $values,
			'errors'        => $errors['fields'],
			'notices'       => $errors['notices'],
			'checkoutUrl'   => function_exists( 'wc_get_checkout_url' ) ? esc_url_raw( wc_get_checkout_url() ) : '',
			'cartTotal'     => WC()->cart ? $this->money_text( WC()->cart->get_total() ) : '',
			'discountSummary' => $this->cart_payload ? $this->cart_payload->discount_summary() : [],
			'needsShipping' => WC()->cart ? (bool) WC()->cart->needs_shipping() : false,
			'shipToDifferent' => $this->ship_to_different_address(),
			'canCreateAccount' => $this->can_create_account(),
			'accountRequired' => $this->account_required(),
			'createAccount' => $this->create_account(),
		];
	}

	public function save_draft( array $submitted, bool $validate = false ): array {
		if ( ! $this->available() ) {
			return [
				'ok'      => true,
				'valid'   => false,
				'errors'  => [],
				'notices' => [ __( 'Checkout is not available.', 'dsa' ) ],
			];
		}

		$definitions = $this->raw_checkout_fields();
		$draft       = $this->draft();
		if ( array_key_exists( 'ship_to_different_address', $submitted ) ) {
			$draft['ship_to_different_address'] = ! empty( $submitted['ship_to_different_address'] ) ? '1' : '0';
		} elseif ( $this->submitted_shipping_fields_have_values( $submitted ) ) {
			$draft['ship_to_different_address'] = '1';
		}
		if ( array_key_exists( 'createaccount', $submitted ) ) {
			$draft['createaccount'] = ! empty( $submitted['createaccount'] ) ? '1' : '0';
		}

		foreach ( $definitions as $key => $field ) {
			if ( ! array_key_exists( $key, $submitted ) ) {
				continue;
			}

			$draft[ $key ] = $this->sanitize_value( $submitted[ $key ], $field );
		}

		$this->set_session_value( self::DRAFT_KEY, $draft );
		$this->prime_customer_session( $draft );
		$this->persist_customer_address_drafts( $draft, $definitions );

		$errors = $validate ? $this->validate_draft( $draft, $definitions ) : [ 'fields' => [], 'notices' => [] ];
		if ( $validate ) {
			$this->set_session_value( self::ERROR_KEY, $errors );
			if ( empty( $errors['fields'] ) && empty( $errors['notices'] ) ) {
				$this->persist_customer_addresses( $draft );
			}
		}

		$contract = $this->contract( false );
		$contract['errors']  = $errors['fields'];
		$contract['notices'] = $errors['notices'];

		return [
			'ok'       => true,
			'valid'    => empty( $errors['fields'] ) && empty( $errors['notices'] ),
			'checkout' => $contract,
			'errors'   => $errors['fields'],
			'notices'  => $errors['notices'],
		];
	}

	public function checkout_value( $value, string $input ) {
		if ( ! $this->enabled() ) {
			return $value;
		}

		$draft = $this->draft();

		return array_key_exists( $input, $draft ) ? $draft[ $input ] : $value;
	}

	public function ship_to_different_address_checked( $checked ): bool {
		if ( ! $this->enabled() ) {
			return (bool) $checked;
		}

		$draft = $this->draft();
		return array_key_exists( 'ship_to_different_address', $draft ) ? '1' === (string) $draft['ship_to_different_address'] : (bool) $checked;
	}

	public function merge_draft_into_posted_data( array $data ): array {
		if ( ! $this->enabled() ) {
			return $data;
		}

		$draft = $this->draft();
		if ( empty( $draft ) ) {
			return $data;
		}

		foreach ( $this->raw_checkout_fields() as $key => $field ) {
			$submitted_value = $data[ $key ] ?? '';
			$draft_value     = $draft[ $key ] ?? '';
			$submitted_empty = is_array( $submitted_value ) ? empty( $submitted_value ) : '' === trim( (string) $submitted_value );
			$draft_present   = is_array( $draft_value ) ? ! empty( $draft_value ) : '' !== trim( (string) $draft_value );
			if ( $submitted_empty && ! empty( $field['required'] ) && $draft_present ) {
				$data[ $key ] = $draft[ $key ];
			}
		}

		return $data;
	}

	public function capture_checkout_errors( array $data, WP_Error $errors ): void {
		if ( ! $this->enabled() || ! $errors->has_errors() || ! $this->load_checkout() ) {
			return;
		}

		$captured = [ 'fields' => [], 'notices' => [] ];

		foreach ( $errors->get_error_codes() as $code ) {
			$error_data = $errors->get_error_data( $code );
			$field      = is_array( $error_data ) ? sanitize_key( $error_data['id'] ?? '' ) : '';
			$field      = $field ?: $this->field_from_error_code( (string) $code );

			foreach ( $errors->get_error_messages( $code ) as $message ) {
				$message = trim( wp_strip_all_tags( (string) $message ) );
				if ( '' === $message ) {
					continue;
				}

				if ( $field ) {
					$captured['fields'][ $field ] = $message;
				} else {
					$captured['notices'][] = $message;
				}
			}
		}

		$captured['notices'] = array_values( array_unique( $captured['notices'] ) );
		$this->set_session_value( self::ERROR_KEY, $captured );
	}

	public function clear_after_order(): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return;
		}

		$this->set_session_value( self::DRAFT_KEY, [] );
		$this->set_session_value( self::ERROR_KEY, [] );
	}

	private function field_groups(): array {
		$checkout = WC()->checkout();
		$groups   = $checkout ? $checkout->get_checkout_fields() : [];
		$out      = [];

		foreach ( [ 'billing', 'shipping', 'account', 'order' ] as $group ) {
			if ( empty( $groups[ $group ] ) || ! is_array( $groups[ $group ] ) ) {
				continue;
			}

			if ( 'shipping' === $group && WC()->cart && ! WC()->cart->needs_shipping() ) {
				continue;
			}
			if ( 'account' === $group && ! $this->create_account() ) {
				continue;
			}

			$fields = $groups[ $group ];
			uasort(
				$fields,
				static function ( array $a, array $b ): int {
					return (int) ( $a['priority'] ?? 100 ) <=> (int) ( $b['priority'] ?? 100 );
				}
			);

			foreach ( $fields as $key => $field ) {
				if ( ! is_array( $field ) || 'hidden' === ( $field['type'] ?? '' ) ) {
					continue;
				}

				$out[ $group ][] = $this->field_contract( (string) $key, $field, $group );
			}
		}

		return $out;
	}

	private function raw_checkout_fields(): array {
		$checkout = WC()->checkout();
		$groups   = $checkout ? $checkout->get_checkout_fields() : [];
		$fields   = [];

		foreach ( $groups as $group => $group_fields ) {
			if ( 'shipping' === $group && WC()->cart && ! WC()->cart->needs_shipping() ) {
				continue;
			}
			if ( 'account' === $group && ! $this->create_account() ) {
				continue;
			}

			foreach ( is_array( $group_fields ) ? $group_fields : [] as $key => $field ) {
				if ( is_array( $field ) ) {
					$fields[ sanitize_key( (string) $key ) ] = $field;
				}
			}
		}

		return $fields;
	}

	private function field_contract( string $key, array $field, string $group ): array {
		$key         = sanitize_key( $key );
		$type        = sanitize_key( $field['type'] ?? 'text' );
		$label       = sanitize_text_field( $field['label'] ?? $this->humanize_key( $key ) );
		$placeholder = sanitize_text_field( $field['placeholder'] ?? '' );
		$value       = $this->field_value( $key );
		$options     = $this->field_options( $key, $type, $field );

		if ( '' === $placeholder ) {
			$placeholder = $label;
		}

		if ( 'shipping' === $group && 0 !== stripos( $placeholder, 'shipping' ) ) {
			$placeholder = sprintf( __( 'Shipping %s', 'dsa' ), lcfirst( $placeholder ) );
		}

		if ( 'state' === $type ) {
			$type = $options ? 'select' : 'text';
		}

		return [
			'key'          => $key,
			'group'        => sanitize_key( $group ),
			'type'         => $this->supported_type( $type ),
			'label'        => $label,
			'placeholder'  => $placeholder,
			'value'        => is_scalar( $value ) ? (string) $value : '',
			'required'     => ! empty( $field['required'] ),
			'autocomplete' => sanitize_text_field( $field['autocomplete'] ?? '' ),
			'options'      => $options,
		];
	}

	private function field_options( string $key, string $type, array $field ): array {
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];

		if ( 'country' === $type && WC()->countries ) {
			$options = 0 === strpos( $key, 'shipping_' )
				? WC()->countries->get_shipping_countries()
				: WC()->countries->get_allowed_countries();
		}

		if ( 'state' === $type && WC()->countries ) {
			$prefix  = 0 === strpos( $key, 'shipping_' ) ? 'shipping_' : 'billing_';
			$country = (string) $this->field_value( $prefix . 'country' );
			$states  = $country ? WC()->countries->get_states( $country ) : [];
			$options = is_array( $states ) ? $states : [];
		}

		$out = [];
		foreach ( $options as $option_value => $option_label ) {
			$out[ (string) $option_value ] = sanitize_text_field( (string) $option_label );
		}

		return $out;
	}

	private function field_value( string $key ) {
		$draft = $this->draft();
		if ( array_key_exists( $key, $draft ) ) {
			return $draft[ $key ];
		}

		$checkout = WC()->checkout();
		return $checkout ? $checkout->get_value( $key ) : '';
	}

	private function sanitize_value( $value, array $field ) {
		$type = sanitize_key( $field['type'] ?? 'text' );

		if ( 'checkbox' === $type ) {
			return ! empty( $value ) ? '1' : '0';
		}

		if ( is_array( $value ) ) {
			return array_values( array_map( 'sanitize_text_field', wp_unslash( $value ) ) );
		}

		$value = wp_unslash( (string) $value );

		if ( 'textarea' === $type ) {
			return sanitize_textarea_field( $value );
		}

		if ( 'email' === $type ) {
			return sanitize_email( $value );
		}

		if ( 'tel' === $type && function_exists( 'wc_sanitize_phone_number' ) ) {
			return wc_sanitize_phone_number( $value );
		}

		return sanitize_text_field( $value );
	}

	private function validate_draft( array $draft, array $definitions ): array {
		$errors = [ 'fields' => [], 'notices' => [] ];

		foreach ( $definitions as $key => $field ) {
			$value       = $draft[ $key ] ?? '';
			$label       = sanitize_text_field( $field['label'] ?? $this->humanize_key( $key ) );
			$empty_value = is_array( $value ) ? empty( $value ) : '' === trim( (string) $value );

			if ( ! empty( $field['required'] ) && ( $empty_value || ( 'checkbox' === ( $field['type'] ?? '' ) && '1' !== (string) $value ) ) ) {
				$errors['fields'][ $key ] = sprintf( __( '%s is required.', 'dsa' ), $label );
				continue;
			}

			if ( $empty_value ) {
				continue;
			}

			$validators = isset( $field['validate'] ) && is_array( $field['validate'] ) ? $field['validate'] : [];
			$type       = sanitize_key( $field['type'] ?? 'text' );

			if ( ( 'email' === $type || in_array( 'email', $validators, true ) ) && ! is_email( (string) $value ) ) {
				$errors['fields'][ $key ] = __( 'Enter a valid email address.', 'dsa' );
			}

			if ( ( 'tel' === $type || in_array( 'phone', $validators, true ) ) && class_exists( 'WC_Validation' ) && ! \WC_Validation::is_phone( (string) $value ) ) {
				$errors['fields'][ $key ] = __( 'Enter a valid phone number.', 'dsa' );
			}

			if ( in_array( 'postcode', $validators, true ) && class_exists( 'WC_Validation' ) ) {
				$prefix  = 0 === strpos( $key, 'shipping_' ) ? 'shipping_' : 'billing_';
				$country = (string) ( $draft[ $prefix . 'country' ] ?? '' );
				if ( $country && ! \WC_Validation::is_postcode( (string) $value, $country ) ) {
					$errors['fields'][ $key ] = __( 'Enter a valid postcode.', 'dsa' );
				}
			}
		}

		return $errors;
	}

	private function prime_customer_session( array $draft ): void {
		if ( ! WC()->customer ) {
			return;
		}

		$properties = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ];

		foreach ( [ 'billing', 'shipping' ] as $group ) {
			foreach ( $properties as $property ) {
				$key    = $group . '_' . $property;
				$setter = 'set_' . $key;
				if ( array_key_exists( $key, $draft ) && method_exists( WC()->customer, $setter ) ) {
					try {
						WC()->customer->{$setter}( $draft[ $key ] );
					} catch ( \Exception $exception ) {
						// Keep the sanitized draft; final Woo validation explains invalid values.
					}
				}
			}
		}

		if ( class_exists( 'WC_Customer_Data_Store_Session' ) ) {
			$session_store = new \WC_Customer_Data_Store_Session();
			$session_store->save_to_session( WC()->customer );
		}
	}

	private function persist_customer_address_drafts( array $draft, array $definitions ): void {
		if ( ! is_user_logged_in() || ! class_exists( 'WC_Customer' ) ) {
			return;
		}

		foreach ( [ 'billing', 'shipping' ] as $group ) {
			if ( 'shipping' === $group && ! $this->should_persist_shipping_address( $draft ) ) {
				continue;
			}

			if ( ! $this->address_group_ready_for_persistence( $group, $draft, $definitions ) ) {
				continue;
			}

			if ( $this->address_group_has_validation_errors( $group, $draft, $definitions ) ) {
				continue;
			}

			$this->persist_customer_address_group( $group, $draft );
		}
	}

	private function address_group_ready_for_persistence( string $group, array $draft, array $definitions ): bool {
		$required = [];

		foreach ( $definitions as $key => $field ) {
			if ( 0 !== strpos( (string) $key, $group . '_' ) || empty( $field['required'] ) || 'hidden' === ( $field['type'] ?? '' ) ) {
				continue;
			}

			$required[] = (string) $key;
		}

		if ( empty( $required ) ) {
			$required = [ $group . '_address_1', $group . '_city', $group . '_country' ];
		}

		foreach ( $required as $key ) {
			$value = $draft[ $key ] ?? '';
			$value = is_array( $value ) ? implode( '', array_map( 'strval', $value ) ) : (string) $value;
			if ( '' === trim( $value ) ) {
				return false;
			}
		}

		return true;
	}

	private function address_group_has_validation_errors( string $group, array $draft, array $definitions ): bool {
		$group_definitions = [];

		foreach ( $definitions as $key => $field ) {
			if ( 0 === strpos( (string) $key, $group . '_' ) ) {
				$group_definitions[ $key ] = $field;
			}
		}

		$errors = $this->validate_draft( $draft, $group_definitions );
		return ! empty( $errors['fields'] ) || ! empty( $errors['notices'] );
	}

	private function persist_customer_address_group( string $group, array $draft ): void {
		$customer = null;

		try {
			$customer = new \WC_Customer( get_current_user_id() );
		} catch ( \Exception $exception ) {
			return;
		}

		foreach ( $this->customer_address_properties() as $property ) {
			$key = $group . '_' . $property;
			if ( ! array_key_exists( $key, $draft ) ) {
				continue;
			}

			$value  = wc_clean( $draft[ $key ] );
			$setter = 'set_' . $key;
			if ( method_exists( $customer, $setter ) ) {
				try {
					$customer->{$setter}( $value );
					continue;
				} catch ( \Exception $exception ) {
					// Fall through to user meta for custom or host-specific stores.
				}
			}

			update_user_meta( get_current_user_id(), $key, $value );
		}

		try {
			$customer->save();
		} catch ( \Exception $exception ) {
			// Draft/session data remains available; do not break checkout editing.
		}
	}

	private function submitted_shipping_fields_have_values( array $submitted ): bool {
		foreach ( $submitted as $key => $value ) {
			if ( 0 !== strpos( (string) $key, 'shipping_' ) ) {
				continue;
			}

			$value = is_array( $value ) ? implode( '', array_map( 'strval', $value ) ) : (string) $value;
			if ( '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private function persist_customer_addresses( array $draft ): void {
		if ( ! is_user_logged_in() || ! class_exists( 'WC_Customer' ) ) {
			return;
		}

		$customer   = new \WC_Customer( get_current_user_id() );
		$properties = $this->customer_address_properties();

		foreach ( [ 'billing', 'shipping' ] as $group ) {
			if ( 'shipping' === $group && ! $this->should_persist_shipping_address( $draft ) ) {
				continue;
			}

			foreach ( $properties as $property ) {
				$key = $group . '_' . $property;
				if ( ! array_key_exists( $key, $draft ) ) {
					continue;
				}

				$value  = wc_clean( $draft[ $key ] );
				$setter = 'set_' . $key;
				if ( method_exists( $customer, $setter ) ) {
					try {
						$customer->{$setter}( $value );
						continue;
					} catch ( \Exception $exception ) {
						// Fall through to user meta so custom address fields still persist.
					}
				}

				update_user_meta( get_current_user_id(), $key, $value );
			}
		}

		try {
			$customer->save();
		} catch ( \Exception $exception ) {
			// Session draft remains authoritative for the current checkout attempt.
		}
	}

	private function customer_address_properties(): array {
		return [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ];
	}

	private function should_persist_shipping_address( array $draft ): bool {
		if ( '1' === (string) ( $draft['ship_to_different_address'] ?? '0' ) ) {
			return true;
		}

		foreach ( $draft as $key => $value ) {
			if ( 0 !== strpos( (string) $key, 'shipping_' ) ) {
				continue;
			}

			$value = is_array( $value ) ? implode( '', array_map( 'strval', $value ) ) : (string) $value;
			if ( '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private function draft(): array {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return [];
		}

		if ( ! WC()->session && ! $this->load_checkout() ) {
			return [];
		}

		$draft = WC()->session->get( self::DRAFT_KEY, [] );
		return is_array( $draft ) ? $draft : [];
	}

	private function stored_errors(): array {
		$empty  = [ 'fields' => [], 'notices' => [] ];
		$stored = WC()->session ? WC()->session->get( self::ERROR_KEY, $empty ) : $empty;

		if ( ! is_array( $stored ) ) {
			return $empty;
		}

		return [
			'fields'  => is_array( $stored['fields'] ?? null ) ? $stored['fields'] : [],
			'notices' => is_array( $stored['notices'] ?? null ) ? $stored['notices'] : [],
		];
	}

	private function set_session_value( string $key, array $value ): void {
		if ( WC()->session ) {
			WC()->session->set( $key, $value );
		}
	}

	private function load_checkout(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return false;
		}

		if ( ( ! WC()->session || ! WC()->customer || ! WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		return (bool) ( WC()->session && WC()->customer && WC()->cart && WC()->checkout() );
	}

	private function prepare_cart_totals(): void {
		if ( ! WC()->cart || ! method_exists( WC()->cart, 'calculate_totals' ) || ! WC()->cart->get_cart_contents_count() ) {
			return;
		}

		$total = method_exists( WC()->cart, 'get_total' ) ? (float) WC()->cart->get_total( 'edit' ) : 0.0;
		$contents_total = method_exists( WC()->cart, 'get_cart_contents_total' ) ? (float) WC()->cart->get_cart_contents_total() : 0.0;
		if ( $total <= 0 && $contents_total > 0 ) {
			WC()->cart->calculate_totals();
		}
	}

	private function supported_type( string $type ): string {
		return in_array( $type, [ 'text', 'email', 'tel', 'number', 'password', 'textarea', 'select', 'country', 'checkbox', 'date' ], true ) ? $type : 'text';
	}

	private function ship_to_different_address(): bool {
		$draft = $this->draft();
		return '1' === (string) ( $draft['ship_to_different_address'] ?? '0' );
	}

	private function can_create_account(): bool {
		return ! is_user_logged_in() && ( $this->account_required() || 'yes' === get_option( 'woocommerce_enable_signup_from_checkout', 'no' ) );
	}

	private function account_required(): bool {
		return ! is_user_logged_in() && 'no' === get_option( 'woocommerce_enable_guest_checkout', 'yes' );
	}

	private function create_account(): bool {
		if ( $this->account_required() ) {
			return true;
		}

		$draft = $this->draft();
		return $this->can_create_account() && '1' === (string) ( $draft['createaccount'] ?? '0' );
	}

	private function field_from_error_code( string $code ): string {
		$code = sanitize_key( $code );
		$code = preg_replace( '/_(?:required|validation|invalid)$/', '', $code );

		return is_string( $code ) && preg_match( '/^(?:billing|shipping|account|order)_/', $code ) ? $code : '';
	}

	private function humanize_key( string $key ): string {
		$key = preg_replace( '/^(?:billing|shipping|account|order)_/', '', $key );
		return ucwords( str_replace( '_', ' ', (string) $key ) );
	}

	private function money_text( string $html ): string {
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, $charset );
	}
}
