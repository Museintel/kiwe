<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Present Woo's existing controls. Locale, shipping and gateway requirements remain native. */
final class Checkout_Field_Service {
	public const DEFAULTS = [ 'phone'=>'required','company'=>'optional','address_2'=>'optional' ];
	public static function projection(): array {
		$out = [];
		foreach ( self::DEFAULTS as $key=>$default ) {
			$owner = '\\Automattic\\WooCommerce\\Blocks\\Utils\\CartCheckoutUtils';
			$getter = 'get_' . $key . '_field_visibility';
			$value = is_callable( [ $owner,$getter ] ) ? $owner::$getter() : get_option( 'woocommerce_checkout_' . $key . '_field',$default );
			$out[$key] = in_array( $value,[ 'required','optional','hidden' ],true ) ? $value : $default;
		}
		return $out;
	}
	public static function valid( $value ): bool {
		if ( ! is_array( $value ) || array_diff( array_keys( $value ),array_keys( self::DEFAULTS ) ) || array_diff( array_keys( self::DEFAULTS ),array_keys( $value ) ) ) return false;
		foreach ( $value as $v ) if ( ! is_string( $v ) || ! in_array( $v,[ 'required','optional','hidden' ],true ) ) return false;
		return true;
	}
}
