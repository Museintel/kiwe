<?php
namespace DSA\Access;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Private adapter only. Woo owns orders, payment state, notes, stock and notifications. */
final class Order_Workspace_Service {
    private function recovery_paused(): bool {
        return class_exists( \DSA\Commerce\Store_Recovery_Gate_Service::class ) && \DSA\Commerce\Store_Recovery_Gate_Service::is_paused();
    }
	private function error( string $code, string $message, int $status = 409 ): \WP_Error {
		return new \WP_Error( 'kiwe_commerce_order_' . $code, $message, [ 'status' => $status ] );
	}
	public static function available(): bool {
		return function_exists( 'wc_get_order' ) && class_exists( '\WC_Order' ) && function_exists( 'wc_get_order_notes' );
	}
	public function query_filters( array $filters ) {
		$out = [];
		if ( in_array( $filters['kind'] ?? '', [ 'bacs', 'cod', 'cheque' ], true ) ) $out['payment_method'] = $filters['kind'];
		$dates = [];
		foreach ( [ 'from', 'to' ] as $key ) {
			$value = $filters[$key] ?? '';
			if ( '' === $value ) continue;
			if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) return $this->error( 'date', 'Choose valid order dates.', 400 );
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) return $this->error( 'date', 'Choose valid order dates.', 400 );
			$dates[$key] = $date;
		}
		if ( isset( $dates['from'], $dates['to'] ) && $dates['from'] > $dates['to'] ) return $this->error( 'date', 'The first order date must be before the last date.', 400 );
		if ( isset( $dates['from'], $dates['to'] ) ) $out['date_created'] = $dates['from']->getTimestamp() . '...' . ( $dates['to']->modify( '+1 day' )->getTimestamp() - 1 );
		elseif ( isset( $dates['from'] ) ) $out['date_created'] = '>=' . $dates['from']->getTimestamp();
		elseif ( isset( $dates['to'] ) ) $out['date_created'] = '<=' . ( $dates['to']->modify( '+1 day' )->getTimestamp() - 1 );
		return $out;
	}
	private function order( int $id ) {
		if ( ! self::available() ) return $this->error( 'unavailable', 'WooCommerce is not active.', 409 );
		if ( $id < 1 || ! current_user_can( 'edit_shop_orders' ) || ! current_user_can( 'edit_others_shop_orders' ) || ! current_user_can( 'edit_shop_order', $id ) ) return $this->error( 'forbidden', 'Your account cannot access this order.', 403 );
		$order = wc_get_order( $id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) return $this->error( 'missing', 'This order is unavailable.', 404 );
		$order->get_data_store()->read( $order );
		$order->read_meta_data( true );
		return $order;
	}
	private function date( $value ): string { return $value ? $value->date( 'c' ) : ''; }
	private function gateway( $order ): array {
		$id = (string) $order->get_payment_method( 'edit' );
		$classes = [ 'cod' => 'WC_Gateway_COD', 'bacs' => 'WC_Gateway_BACS', 'cheque' => 'WC_Gateway_Cheque' ];
		$woo = function_exists( 'WC' ) ? WC() : null;
		$gateway = is_object( $woo ) && is_callable( [ $woo, 'payment_gateways' ] ) ? ( $woo->payment_gateways()->payment_gateways()[$id] ?? null ) : null;
		return [ 'id' => $id, 'offline' => is_object( $gateway ) && isset( $classes[$id] ) && get_class( $gateway ) === $classes[$id], 'class' => is_object( $gateway ) ? get_class( $gateway ) : '', 'enabled' => is_object( $gateway ) && 'yes' === $gateway->enabled, 'refunds' => is_object( $gateway ) && is_callable( [ $gateway, 'supports' ] ) && $gateway->supports( 'refunds' ), 'settingsHash' => is_object( $gateway ) ? hash( 'sha256', wp_json_encode( $gateway->settings ?? [] ) ) : '' ];
	}
	private function snapshot( $order, array $notes, array $gateway, array $refunds = [] ) {
		$state = [ 'data' => $order->get_data(), 'notes' => $notes, 'gateway' => $gateway, 'refunds' => $refunds ];
		if ( count( $order->get_meta_data() ) > 500 ) return $this->error( 'size', 'This extended order needs its native order editor.' );
		$bytes = wp_json_encode( $state );
		if ( ! is_string( $bytes ) || strlen( $bytes ) > 2097152 ) return $this->error( 'size', 'This extended order needs its native order editor.' );
		return hash_hmac( 'sha256', $bytes, wp_salt( 'auth' ) );
	}
	public function review( int $id ) {
		$order = $this->order( $id );
		if ( is_wp_error( $order ) ) return $order;
		return $this->project( $order );
	}
	private function project( $order ) {
		$id = (int) $order->get_id();
		$gateway = $this->gateway( $order );
		$notes = [];
		foreach ( wc_get_order_notes( [ 'order_id' => $id, 'limit' => 21, 'orderby' => 'comment_ID', 'order' => 'DESC' ] ) as $note ) {
			$notes[] = [ 'id' => (int) $note->id, 'body' => sanitize_textarea_field( wp_strip_all_tags( (string) $note->content ) ), 'author' => sanitize_text_field( (string) $note->added_by ), 'date' => $this->date( $note->date_created ), 'customer' => (bool) $note->customer_note ];
		}
		$out = [ 'id' => $id, 'number' => (string) $order->get_order_number(), 'status' => (string) $order->get_status(), 'statusLabel' => sanitize_text_field( wc_get_order_status_name( $order->get_status() ) ), 'total' => (string) $order->get_total(), 'currency' => (string) $order->get_currency(), 'paymentMethod' => sanitize_text_field( $order->get_payment_method_title() ), 'paymentMethodId' => $gateway['id'], 'transactionId' => sanitize_text_field( $order->get_transaction_id( 'edit' ) ), 'paidAt' => $this->date( $order->get_date_paid( 'edit' ) ), 'created' => $this->date( $order->get_date_created( 'edit' ) ), 'modified' => $this->date( $order->get_date_modified( 'edit' ) ), 'customerNote' => sanitize_textarea_field( $order->get_customer_note() ), 'totalRefunded' => (string) $order->get_total_refunded(), 'billing' => [], 'shipping' => [], 'items' => [], 'charges' => [], 'shippingMethods' => [], 'notes' => array_slice( $notes, 0, 20 ), 'notesHaveMore' => count( $notes ) > 20 ];
		foreach ( [ 'billing', 'shipping' ] as $kind ) {
			$address = $order->get_address( $kind );
			foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', ...( 'billing' === $kind ? [ 'email', 'phone' ] : [] ) ] as $key ) $out[$kind][$key] = sanitize_text_field( (string) ( $address[$key] ?? '' ) );
		}
		$items = $order->get_items( [ 'line_item', 'shipping', 'fee', 'coupon', 'tax' ] );
		$out['itemsTruncated'] = count( $items ) > 100;
		foreach ( array_slice( $items, 0, 100, true ) as $item ) {
			$type = $item->get_type();
			if ( 'line_item' === $type ) {
				$out['items'][] = [ 'id' => (int) $item->get_id(), 'name' => sanitize_text_field( $item->get_name() ), 'productId' => (int) $item->get_product_id(), 'variationId' => (int) $item->get_variation_id(), 'quantity' => $item->get_quantity(), 'total' => (string) $item->get_total(), 'tax' => (string) $item->get_total_tax() ];
			} else {
				$amount = 'coupon' === $type ? $item->get_discount() : ( 'tax' === $type ? (float) $item->get_tax_total() + (float) $item->get_shipping_tax_total() : $item->get_total() );
				$out['charges'][] = [ 'id' => (int) $item->get_id(), 'type' => $type, 'name' => sanitize_text_field( $item->get_name() ), 'amount' => (string) $amount ];
				if ( 'shipping' === $type ) $out['shippingMethods'][] = sanitize_text_field( $item->get_name() );
			}
		}
		// Current Woo Admin returns its own core Order subclass in both CPT and HPOS.
		$bounded = ! $out['itemsTruncated'] && in_array( get_class( $order ), [ 'WC_Order', 'Automattic\\WooCommerce\\Admin\\Overrides\\Order' ], true );
		$noRefund = 0.0 === (float) $out['totalRefunded'] && ! $order->get_refunds();
		$unsettled = '' === $out['paidAt'] && '' === $out['transactionId'];
		$out['canComplete'] = $bounded && $noRefund && 'processing' === $out['status'];
		$out['canHold'] = $bounded && $noRefund && $unsettled && in_array( $out['status'], [ 'pending', 'failed' ], true );
		$out['canCancel'] = $bounded && $noRefund && $unsettled && in_array( $out['status'], [ 'pending', 'on-hold', 'failed' ], true );
		$out['canRecordPayment'] = $bounded && $noRefund && $unsettled && $gateway['offline'] && $gateway['enabled'] && (float) $out['total'] > 0 && in_array( $out['status'], [ 'pending', 'on-hold', 'failed', ...( 'cod' === $gateway['id'] ? [ 'processing' ] : [] ) ], true );
		$out['canAddNote'] = $bounded;
		$out['paymentOwner'] = $gateway['offline'] ? 'offline' : 'gateway';
		$out['paymentUrl'] = $order->needs_payment() ? esc_url_raw( $order->get_checkout_payment_url() ) : '';
		if ( $out['paymentUrl'] && wp_parse_url( $out['paymentUrl'], PHP_URL_HOST ) !== wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) $out['paymentUrl'] = '';
		$refundState = $this->refund_projection( $order, $bounded, $gateway );
		$expected = $this->snapshot( $order, $notes, $gateway, $refundState['_snapshot'] );
		unset( $refundState['_snapshot'] ); $out['refund'] = $refundState;
		if ( $refundState['blocked'] ) $out['canComplete'] = false;
		if ( $this->recovery_paused() ) {
			foreach ( [ 'canComplete','canHold','canCancel','canRecordPayment','canAddNote' ] as $key ) $out[$key] = false;
			foreach ( [ 'canCreate','canGateway','canResolve' ] as $key ) $out['refund'][$key] = false;
			$out['paymentUrl'] = '';
			$out['recovery'] = \DSA\Commerce\Store_Recovery_Gate_Service::projection();
		}
		if ( is_wp_error( $expected ) ) return $expected;
		$out['expected'] = $expected;
		return $out;
	}
	/** No payment capture, arbitrary status setter, financial edit or database rollback. */
	public function change( int $id, array $input ) {
		if ( $this->recovery_paused() ) return \DSA\Commerce\Store_Recovery_Gate_Service::error();
		$order = $this->order( $id );
		if ( is_wp_error( $order ) ) return $order;
		if ( array_diff( array_keys( $input ), [ 'operation', 'expected', 'confirmed', 'note', 'reference' ] ) || ! in_array( $input['operation'] ?? null, [ 'complete', 'hold', 'cancel', 'payment', 'note' ], true ) || ! is_string( $input['expected'] ?? null ) || true !== ( $input['confirmed'] ?? false ) ) return $this->error( 'confirmation', 'Review this order and confirm one action.', 400 );
		$operation = $input['operation'];
		$note = $input['note'] ?? '';
		$reference = $input['reference'] ?? '';
		if ( ! is_string( $note ) || strlen( $note ) > 2000 || ! is_string( $reference ) || strlen( $reference ) > 120 || sanitize_text_field( $reference ) !== $reference || ( 'note' !== $operation && '' !== $note ) || ( 'payment' !== $operation && '' !== $reference ) || ( 'note' === $operation && '' === trim( sanitize_textarea_field( $note ) ) ) ) return $this->error( 'input', 'Use a private note up to 2,000 bytes or a plain payment reference up to 120 bytes.', 400 );
		global $wpdb;
		$lock = $this->lock_name( $id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) return $this->error( 'busy', 'This order is being updated. Refresh its saved state.' );
		$paymentFilter = null;
		try {
			// External payment callbacks do not use this lock: reject changed native state before every action.
			$order = $this->order( $id );
			if ( is_wp_error( $order ) ) return $order;
			$before = $this->project( $order );
			if ( is_wp_error( $before ) ) return $before;
			if ( ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'The order, payment or notes changed. Refresh and review the saved state.' );
			$permission = [ 'complete' => 'canComplete', 'hold' => 'canHold', 'cancel' => 'canCancel', 'payment' => 'canRecordPayment', 'note' => 'canAddNote' ];
			if ( ! $before[$permission[$operation]] ) return $this->error( 'state', 'This action is unavailable for the saved order. Review its native payment and fulfilment state.' );
			$protected = $this->protected_values( $order );
			$noteId = 0;
			if ( 'note' === $operation ) {
				$noteId = (int) $order->add_order_note( sanitize_textarea_field( $note ), 0, true );
				$ok = $noteId > 0;
			} elseif ( 'payment' === $operation ) {
				// Core COD enters processing before collection. Add only this reviewed order to the native valid statuses.
				$paymentFilter = static function( $statuses, $candidate ) use ( $id ) { return $candidate && (int) $candidate->get_id() === $id ? array_values( array_unique( array_merge( $statuses, [ 'processing' ] ) ) ) : $statuses; };
				add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $paymentFilter, PHP_INT_MAX, 2 );
				$ok = $order->payment_complete( $reference );
			} else {
				$status = [ 'complete' => 'completed', 'hold' => 'on-hold', 'cancel' => 'cancelled' ][$operation];
				$ok = $order->update_status( $status, [ 'complete' => 'Fulfilment confirmed in Kiwe.', 'hold' => 'Placed on hold after review in Kiwe.', 'cancel' => 'Unpaid order cancellation confirmed in Kiwe.' ][$operation], true );
			}
			if ( ! $ok ) return $this->error( 'write', 'The outcome could not be confirmed. This action may have run. Refresh the saved order before doing anything else.' );
			$afterOrder = $this->order( $id );
			if ( is_wp_error( $afterOrder ) ) return $afterOrder;
			$after = $this->project( $afterOrder );
			if ( is_wp_error( $after ) ) return $after;
			if ( $protected !== $this->protected_values( $afterOrder ) ) return $this->error( 'readback', 'Native order details changed during this action. Refresh and review; do not repeat the action.' );
			if ( 'payment' === $operation && ( ! $after['paidAt'] || ! in_array( $after['status'], [ 'processing', 'completed' ], true ) || $after['transactionId'] !== $reference ) ) return $this->error( 'payment_readback', 'The payment outcome differs. Inspect the saved order and gateway before any further action.' );
			if ( in_array( $operation, [ 'complete', 'hold', 'cancel' ], true ) && $after['status'] !== $status ) return $this->error( 'status_readback', 'The saved status differs. Refresh the saved order before any further action.' );
			if ( 'note' === $operation && ! array_filter( $after['notes'], static fn( $saved ) => $saved['id'] === $noteId && false === $saved['customer'] && $saved['body'] === sanitize_textarea_field( wp_strip_all_tags( $note ) ) ) ) return $this->error( 'note_readback', 'The note could not be verified. Refresh saved notes before adding another.' );
			if ( $after['expected'] === $before['expected'] ) return $this->error( 'unchanged', 'The saved outcome was incomplete. Refresh the order before retrying.' );
			return $after;
		} catch ( \Throwable $error ) {
			return $this->error( 'write', 'The outcome is uncertain. The native action may have run. Refresh saved state; do not automatically retry.' );
		} finally {
			if ( $paymentFilter ) remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $paymentFilter, PHP_INT_MAX );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
	private function protected_values( $order, bool $includeRefunds = true ): string {
		$out = [ 'total' => $order->get_total( 'edit' ), 'currency' => $order->get_currency( 'edit' ), 'paymentMethod' => $order->get_payment_method( 'edit' ), 'customer' => $order->get_customer_id( 'edit' ), 'billing' => $order->get_address( 'billing' ), 'shipping' => $order->get_address( 'shipping' ), 'customerNote' => $order->get_customer_note( 'edit' ), 'refunded' => $order->get_total_refunded(), 'items' => [] ];
		if ( ! $includeRefunds ) unset( $out['refunded'] );
		foreach ( $order->get_items( [ 'line_item', 'shipping', 'fee', 'coupon', 'tax' ] ) as $item ) {
			$data = $item->get_data();
			// Woo legitimately adds/removes _reduced_stock during payment/cancellation.
			unset( $data['meta_data'] );
			$out['items'][] = $data;
		}
		return hash( 'sha256', wp_json_encode( $out ) );
	}

	/** Native refund amounts use Woo's configured precision; inputs never accept exponent/locale coercion. */
	private function refund_minor( $value, int $precision ): ?int {
		if ( ! is_string( $value ) || $precision < 0 || $precision > 6 || ! preg_match( '/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?$/D', $value, $m ) || strlen( $m[2] ?? '' ) > $precision ) return null;
		return (int) ( $m[1] . str_pad( $m[2] ?? '', $precision, '0' ) );
	}
	private function refund_decimal( int $minor, int $precision ): string {
		if ( 0 === $precision ) return (string) $minor;
		$value = str_pad( (string) $minor, $precision + 1, '0', STR_PAD_LEFT );
		return substr( $value, 0, -$precision ) . '.' . substr( $value, -$precision );
	}
	private function refund_origin(): string {
		return hash_hmac( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $GLOBALS['wpdb']->prefix . ':' . home_url( '/' ), wp_salt( 'auth' ) );
	}
	private function refund_guard( $order ) {
		$value = $order->get_meta( '_kiwe_refund_guard_v1', true, 'edit' );
		if ( '' === $value || null === $value ) return [];
		if ( ! is_array( $value ) || strlen( wp_json_encode( $value ) ) > 65536 || ! is_string( $value['signature'] ?? null ) ) return false;
		$signature = $value['signature']; unset( $value['signature'] );
		if ( ! hash_equals( hash_hmac( 'sha256', wp_json_encode( $value ), wp_salt( 'auth' ) ), $signature ) || ( $value['origin'] ?? '' ) !== $this->refund_origin() || ! in_array( $value['stage'] ?? '', [ 'pending', 'completed', 'verified_no_transfer' ], true ) ) return false;
		return $value;
	}
	private function refund_save_guard( $order, array $guard ): bool {
		$guard['origin'] = $this->refund_origin();
		$guard['signature'] = hash_hmac( 'sha256', wp_json_encode( $guard ), wp_salt( 'auth' ) );
		if ( strlen( wp_json_encode( $guard ) ) > 65536 ) return false;
		$order->update_meta_data( '_kiwe_refund_guard_v1', $guard );
		$order->save_meta_data();
		$order->read_meta_data( true );
		return $order->get_meta( '_kiwe_refund_guard_v1', true, 'edit' ) === $guard;
	}
	private function refund_projection( $order, bool $bounded, array $gateway ): array {
		$out = [ 'available' => false, 'canCreate' => false, 'canGateway' => false, 'canResolve' => false, 'blocked' => false, 'remaining' => '0', 'precision' => 2, 'lines' => [], 'history' => [], 'historyHasMore' => false, 'guard' => null, '_snapshot' => [] ];
		if ( ! function_exists( 'wc_create_refund' ) ) return $out;
		$precision = (int) wc_get_price_decimals(); $out['precision'] = $precision;
		$refunds = $order->get_refunds(); $data = [];
		foreach ( array_slice( $refunds, 0, 51 ) as $refund ) {
			$refund->get_data_store()->read( $refund ); $refund->read_meta_data( true );
			$data[] = $refund->get_data();
			if ( count( $out['history'] ) < 20 ) $out['history'][] = [ 'id' => (int) $refund->get_id(), 'amount' => (string) $refund->get_amount(), 'currency' => (string) $refund->get_currency(), 'reason' => sanitize_textarea_field( $refund->get_reason() ), 'date' => $this->date( $refund->get_date_created() ), 'by' => (int) $refund->get_refunded_by(), 'gateway' => (bool) $refund->get_refunded_payment(), 'intent' => sanitize_text_field( $refund->get_meta( '_kiwe_refund_intent_v1' ) ), 'reference' => sanitize_text_field( $refund->get_meta( '_kiwe_refund_reference_v1' ) ) ];
		}
		$out['historyHasMore'] = count( $refunds ) > 20;
		$out['_snapshot'] = $data;
		$guard = $this->refund_guard( $order );
		$linked = $guard && false !== $guard && 'completed' === $guard['stage'] ? array_filter( $refunds, static fn( $r ) => (int) $r->get_id() === (int) ( $guard['refundId'] ?? 0 ) && wc_format_decimal( $r->get_amount(), $precision ) === $guard['amount'] ) : [];
		$out['blocked'] = false === $guard || ( $guard && ( 'pending' === $guard['stage'] || ( 'completed' === $guard['stage'] && ! $linked ) ) );
		if ( false !== $guard && $guard ) $out['guard'] = array_intersect_key( $guard, array_flip( [ 'intent', 'stage', 'amount', 'mode', 'started', 'by', 'restock', 'refundId' ] ) );
		$out['canResolve'] = $bounded && count( $refunds ) <= 50 && $out['blocked'] && false !== $guard && current_user_can( 'manage_woocommerce' );
		$out['remaining'] = (string) $order->get_remaining_refund_amount();
		$money = $this->refund_minor( $out['remaining'], $precision );
		$out['available'] = $bounded && count( $refunds ) <= 50 && null !== $money;
		$out['canCreate'] = $out['available'] && ! $out['blocked'] && $money > 0 && $order->get_date_paid( 'edit' ) && in_array( $order->get_status(), [ 'processing', 'completed', 'on-hold', 'cancelled', 'failed' ], true );
		$out['canGateway'] = $out['canCreate'] && ! empty( $gateway['refunds'] ) && ! empty( $gateway['enabled'] );
		foreach ( array_slice( $order->get_items( [ 'line_item', 'shipping', 'fee' ] ), 0, 100, true ) as $item ) {
			$type = $item->get_type(); $id = (int) $item->get_id();
			$quantity = 'line_item' === $type ? (float) $item->get_quantity() + (float) $order->get_qty_refunded_for_item( $id ) : 0;
			$total = wc_format_decimal( (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $id, $type ), $precision );
			$taxes = [];
			foreach ( $item->get_taxes()['total'] ?? [] as $rate => $amount ) $taxes[(string) $rate] = wc_format_decimal( (float) $amount - (float) $order->get_tax_refunded_for_item( $id, (int) $rate, $type ), $precision );
			$supported = null !== $this->refund_minor( $total, $precision ) && count( $taxes ) <= 20 && ( 'line_item' !== $type || ( $quantity >= 0 && $quantity <= 1000000 && floor( $quantity ) === $quantity ) );
			foreach ( $taxes as $amount ) if ( null === $this->refund_minor( $amount, $precision ) ) $supported = false;
			$out['lines'][] = [ 'id' => $id, 'name' => sanitize_text_field( $item->get_name() ), 'type' => $type, 'quantity' => (int) $quantity, 'total' => $total, 'taxes' => $taxes, 'supported' => $supported ];
		}
		return $out;
	}
	private function refund_lines( array $input, array $state, bool $restock ) {
		if ( count( $input ) > 100 || ! array_is_list( $input ) ) return $this->error( 'refund_lines', 'Choose this order’s refundable items.', 400 );
		$native = array_column( $state['lines'], null, 'id' ); $lines = []; $sum = 0; $quantity = 0;
		foreach ( $input as $row ) {
			if ( ! is_array( $row ) || array_diff( array_keys( $row ), [ 'id', 'quantity', 'total', 'taxes' ] ) || ! is_int( $row['id'] ?? null ) || ! isset( $native[$row['id']] ) || isset( $lines[$row['id']] ) || ! is_int( $row['quantity'] ?? null ) || ! is_array( $row['taxes'] ?? null ) ) return $this->error( 'refund_lines', 'Review each refundable item once.', 400 );
			$item = $native[$row['id']]; $minor = $this->refund_minor( $row['total'] ?? null, $state['precision'] );
			if ( ! $item['supported'] || null === $minor || $minor > $this->refund_minor( $item['total'], $state['precision'] ) || $row['quantity'] < 0 || $row['quantity'] > $item['quantity'] || ( 'line_item' !== $item['type'] && 0 !== $row['quantity'] ) || array_diff( array_keys( $row['taxes'] ), array_keys( $item['taxes'] ) ) ) return $this->error( 'refund_lines', 'The quantity or amount exceeds this item’s refundable balance.', 400 );
			$tax = [];
			foreach ( $item['taxes'] as $rate => $maximum ) {
				$value = $row['taxes'][$rate] ?? '0'; $taxMinor = $this->refund_minor( $value, $state['precision'] );
				if ( null === $taxMinor || $taxMinor > $this->refund_minor( $maximum, $state['precision'] ) ) return $this->error( 'refund_tax', 'Use this item’s remaining native tax amounts.', 400 );
				$tax[$rate] = $this->refund_decimal( $taxMinor, $state['precision'] ); $minor += $taxMinor;
			}
			if ( 0 === $minor && 0 === $row['quantity'] ) continue;
			$sum += $minor; $quantity += $row['quantity'];
			$lines[$row['id']] = [ 'qty' => $row['quantity'], 'refund_total' => $this->refund_decimal( $this->refund_minor( $row['total'], $state['precision'] ), $state['precision'] ), 'refund_tax' => $tax ];
		}
		if ( $restock && ( ! $lines || $quantity < 1 ) ) return $this->error( 'refund_stock', 'Choose the returned item quantities before restocking.', 400 );
		return [ 'native' => $lines, 'sum' => $sum ];
	}
	private function refund_fingerprint( $order ): string {
		$out = [];
		foreach ( $order->get_refunds() as $refund ) $out[(int) $refund->get_id()] = $refund->get_data();
		ksort( $out ); return hash( 'sha256', wp_json_encode( $out ) );
	}
	/** One native attempt marker survives loss of a gateway response; it is not a payment queue. */
	public function refund( int $id, array $input ) {
		if ( $this->recovery_paused() ) return \DSA\Commerce\Store_Recovery_Gate_Service::error();
		$order = $this->order( $id ); if ( is_wp_error( $order ) ) return $order;
		if ( ! function_exists( 'wc_create_refund' ) ) return $this->error( 'refund_unavailable', 'Native refunds are unavailable.' );
		$operation = $input['operation'] ?? 'create';
		$allowed = 'resolve' === $operation ? [ 'operation', 'expected', 'confirmed', 'intent', 'resolution', 'reference', 'refundId', 'stockReviewed' ] : [ 'operation', 'expected', 'confirmed', 'intent', 'mode', 'amount', 'reason', 'reference', 'restock', 'lines' ];
		if ( ! in_array( $operation, [ 'create', 'resolve' ], true ) || array_diff( array_keys( $input ), $allowed ) || ! is_string( $input['expected'] ?? null ) || true !== ( $input['confirmed'] ?? false ) || ! is_string( $input['intent'] ?? null ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $input['intent'] ) || strlen( wp_json_encode( $input ) ) > 32768 ) return $this->error( 'refund_confirmation', 'Review and confirm one refund task.', 400 );
		$reference = $input['reference'] ?? '';
		if ( ! is_string( $reference ) || strlen( $reference ) > 120 || sanitize_text_field( $reference ) !== $reference ) return $this->error( 'refund_reference', 'Use a plain refund reference up to 120 bytes.', 400 );
		$input['reference'] = $reference;
		global $wpdb; $lock = $this->lock_name( $id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) return $this->error( 'busy', 'This order is being updated. Refresh its saved state.' );
		try {
			$order = $this->order( $id ); if ( is_wp_error( $order ) ) return $order;
			$before = $this->project( $order ); if ( is_wp_error( $before ) ) return $before;
			if ( ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'The order, refunds or gateway changed. Refresh and review the saved state.' );
			$state = $before['refund']; $guard = $this->refund_guard( $order );
			$recordElsewhere = false;
			if ( 'resolve' === $operation ) {
				if ( ! $state['canResolve'] || ! $guard || $guard['intent'] !== $input['intent'] || ! in_array( $input['resolution'] ?? '', [ 'not_sent', 'recorded', 'record_elsewhere' ], true ) || '' === trim( $reference ) || ( ! empty( $guard['restock'] ) && true !== ( $input['stockReviewed'] ?? false ) ) ) return $this->error( 'refund_resolution', 'Verify the gateway and inventory outcome before resolving this refund.', 400 );
				$resolution = $input['resolution'];
				if ( 'recorded' === $resolution ) {
					if ( ! is_int( $input['refundId'] ?? null ) || in_array( $input['refundId'], $guard['beforeIds'], true ) ) return $this->error( 'refund_resolution', 'Choose a new matching native refund.', 400 );
					$match = wc_get_order( $input['refundId'] );
					if ( ! $match || 'shop_order_refund' !== $match->get_type() || (int) $match->get_parent_id() !== $id || $match->get_currency() !== $order->get_currency() || wc_format_decimal( $match->get_amount(), $state['precision'] ) !== $guard['amount'] ) return $this->error( 'refund_resolution', 'The saved refund does not match this order and amount.', 400 );
					$guard['stage'] = 'completed'; $guard['refundId'] = (int) $match->get_id();
				} else {
					if ( $this->refund_fingerprint( $order ) !== $guard['beforeRefunds'] || $this->protected_values( $order, false ) !== $guard['protected'] ) return $this->error( 'refund_resolution', 'Native order or refund records changed. Review them before resolving this task.' );
					if ( 'not_sent' === $resolution ) $guard['stage'] = 'verified_no_transfer';
					else $recordElsewhere = true;
				}
				$guard['resolvedBy'] = get_current_user_id(); $guard['resolvedAt'] = gmdate( 'c' );
				if ( ! $recordElsewhere ) {
					if ( ! $order->add_order_note( 'Refund outcome reviewed in Kiwe: ' . ( 'recorded' === $resolution ? 'matched native refund #' . $guard['refundId'] : 'no transfer verified' ) . '. Reference: ' . $reference, 0, true ) || ! $this->refund_save_guard( $order, $guard ) ) return $this->error( 'refund_uncertain', 'The review could not be confirmed. Refresh saved state before doing anything else.' );
					return $this->review( $id );
				}
				$input = [ 'operation' => 'create', 'intent' => $guard['intent'], 'mode' => 'manual', 'amount' => $guard['amount'], 'reason' => $guard['reason'], 'reference' => $reference, 'restock' => false, 'lines' => $guard['lines'] ];
			}
			if ( ! $recordElsewhere && ( ! $state['canCreate'] || ( $guard && $guard['intent'] === $input['intent'] ) || array_filter( $order->get_refunds(), static fn( $r ) => $r->get_meta( '_kiwe_refund_intent_v1' ) === $input['intent'] ) ) ) return $this->error( 'refund_state', 'This refund is unavailable or already attempted. Review its saved outcome.' );
			if ( ! in_array( $input['mode'] ?? '', [ 'manual', 'gateway' ], true ) || ( 'gateway' === $input['mode'] && ! $state['canGateway'] ) || ! is_string( $input['reason'] ?? null ) || '' === trim( $input['reason'] ) || strlen( $input['reason'] ) > 1000 || sanitize_textarea_field( $input['reason'] ) !== $input['reason'] || ! is_bool( $input['restock'] ?? null ) || ! is_array( $input['lines'] ?? null ) || ( 'manual' === $input['mode'] && '' === trim( $input['reference'] ) ) ) return $this->error( 'refund_input', 'Choose the return method, reason and verified refund reference.', 400 );
			$minor = $this->refund_minor( $input['amount'] ?? null, $state['precision'] ); $maximum = $this->refund_minor( $state['remaining'], $state['precision'] );
			if ( null === $minor || $minor < 1 || null === $maximum || $minor > $maximum ) return $this->error( 'refund_amount', 'Use a positive amount within the remaining refund balance.', 400 );
			$lines = $this->refund_lines( $input['lines'], $state, $input['restock'] ); if ( is_wp_error( $lines ) ) return $lines;
			if ( $lines['native'] && $lines['sum'] !== $minor ) return $this->error( 'refund_sum', 'The refund amount must equal the selected item and tax amounts.', 400 );
			$amount = $this->refund_decimal( $minor, $state['precision'] );
			$guard = [ 'intent' => $input['intent'], 'stage' => 'pending', 'mode' => $input['mode'], 'amount' => $amount, 'reason' => $input['reason'], 'lines' => $input['lines'], 'restock' => $input['restock'], 'started' => gmdate( 'c' ), 'by' => get_current_user_id(), 'beforeIds' => array_map( static fn( $r ) => (int) $r->get_id(), $order->get_refunds() ), 'beforeRefunds' => $this->refund_fingerprint( $order ), 'protected' => $this->protected_values( $order, false ) ];
			if ( ! $this->refund_save_guard( $order, $guard ) ) return $this->error( 'refund_guard', 'The refund safeguard could not be saved. No refund was requested.' );
			$refund = wc_create_refund( [ 'order_id' => $id, 'amount' => $amount, 'reason' => $input['reason'], 'line_items' => $lines['native'], 'refund_payment' => 'gateway' === $input['mode'], 'restock_items' => $input['restock'] ] );
			if ( is_wp_error( $refund ) || ! $refund || ! $refund->get_id() ) return $this->error( 'refund_uncertain', 'The refund outcome is uncertain. Check the gateway and saved order. This refund cannot be sent again until reviewed.' );
			$refund->update_meta_data( '_kiwe_refund_intent_v1', $input['intent'] );
			if ( $input['reference'] ) $refund->update_meta_data( '_kiwe_refund_reference_v1', $input['reference'] );
			$refund->save_meta_data(); $refund->read_meta_data( true );
			$savedRefund = wc_get_order( (int) $refund->get_id() );
			if ( ! $savedRefund || 'shop_order_refund' !== $savedRefund->get_type() ) return $this->error( 'refund_uncertain', 'The native refund could not be read. Verify the saved order and gateway.' );
			$refund = $savedRefund; $refund->get_data_store()->read( $refund ); $refund->read_meta_data( true );
			$after = $this->order( $id ); if ( is_wp_error( $after ) ) return $after;
			$expectedIds = array_merge( $guard['beforeIds'], [ (int) $refund->get_id() ] ); sort( $expectedIds ); $actualIds = array_map( static fn( $r ) => (int) $r->get_id(), $after->get_refunds() ); sort( $actualIds );
			if ( $expectedIds !== $actualIds || ( 'gateway' === $input['mode'] && ! $refund->get_refunded_payment() ) ) return $this->error( 'refund_uncertain', 'Other refund activity changed this order. Review all native refunds before resolving it.' );
			if ( $this->protected_values( $after, false ) !== $guard['protected'] || wc_format_decimal( $refund->get_amount(), $state['precision'] ) !== $amount || (int) $refund->get_parent_id() !== $id || $refund->get_meta( '_kiwe_refund_intent_v1' ) !== $input['intent'] ) return $this->error( 'refund_uncertain', 'Native refund details changed. Review the saved order and gateway; do not repeat the refund.' );
			$guard['stage'] = 'completed'; $guard['refundId'] = (int) $refund->get_id();
			if ( ! $this->refund_save_guard( $after, $guard ) ) return $this->error( 'refund_uncertain', 'The native refund is recorded but final review could not be saved. Refresh and verify its outcome.' );
			return $this->review( $id );
		} catch ( \Throwable $error ) {
			return $this->error( 'refund_uncertain', 'The refund outcome is uncertain. Check the gateway and saved order before resolving it. Do not send another refund.' );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	private function lock_name( int $id ): string {
		return 'kiwe_order_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $GLOBALS['wpdb']->prefix . ':' . $id ), 0, 40 );
	}

}
