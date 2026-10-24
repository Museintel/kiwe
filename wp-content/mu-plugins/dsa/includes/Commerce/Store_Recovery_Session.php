<?php

namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native Woo API, request-local data only: no copied cookie, token, storage, cache or hooks. */
final class Store_Recovery_Session extends \WC_Session_Handler {
	public function __construct() { $this->_customer_id = ''; }
	public function init() {}
	public function init_session_cookie() {}
	public function has_session() { return false; }
	public function get_session_cookie() { return false; }
	public function generate_customer_id() { return ''; }
	public function get_session( $customer_id, $default_value = false ) { return $default_value; }
	public function set_customer_session_cookie( $set ) {}
	public function set_session_expiration() {}
	public function save_data( $old_session_key = '' ) { $this->_dirty = false; }
	public function delete_session( $customer_id ) {}
	public function update_session_timestamp( $customer_id, $timestamp ) {}
	public function cleanup_sessions() {}
	public function destroy_session() { $this->_data = []; $this->_dirty = false; }
	public function forget_session() { $this->destroy_session(); }
}
