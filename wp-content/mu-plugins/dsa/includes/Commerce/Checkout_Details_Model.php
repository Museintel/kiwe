<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** A bounded operational address, never a payment payload or marketing permission. */
final class Checkout_Details_Model {
	public const FIELDS = [ 'first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone' ];
	public static function normalize( array $fields ): array {
		$out = [ 'billing'=>[], 'shipping'=>[], 'differentShipping'=>false ];
		$remaining = 4096;
		$out['differentShipping'] = in_array( $fields['ship_to_different_address'] ?? false, [ true,1,'1','yes','on' ], true );
		foreach ( [ 'billing','shipping' ] as $group ) foreach ( self::FIELDS as $key ) {
			$name = $group . '_' . $key;
			if ( ! array_key_exists( $name,$fields ) || ! is_string( $fields[$name] ) || strlen( $fields[$name] ) > 600 ) continue;
			$value = trim( sanitize_text_field( $fields[$name] ) );
			if ( strlen( $value ) > $remaining ) continue;
			if ( 'email' === $key ) $value = is_email( $value ) ? strtolower( sanitize_email( $value ) ) : '';
			if ( 'phone' === $key ) { $value = preg_replace( '/[\s().-]+/','',$value ); if ( ! preg_match( '/^\+?[0-9]{7,15}$/D',$value ) ) $value = ''; }
			if ( 'country' === $key && ! preg_match( '/^[A-Z]{2}$/D',$value ) ) $value = '';
			$out[$group][$key] = $value;
			$remaining -= strlen( $value );
		}
		// Stale shipping fields must not masquerade as the shopper's chosen address.
		if ( ! $out['differentShipping'] ) $out['shipping'] = [];
		return $out;
	}
	public static function meaningful( array $details ): bool {
		return (bool) array_filter( [ ...($details['billing'] ?? []), ...($details['shipping'] ?? []) ],static fn( $v ) => is_string( $v ) && '' !== $v );
	}
	public static function envelope( array $fields,string $source,string $at ): array {
		return [ 'schema'=>'kiwe.checkout-details.v1','source'=>$source,'capturedAt'=>$at,...self::normalize( $fields ) ];
	}
	public static function decode( string $json ): ?array {
		if ( strlen( $json ) > 16384 ) return null;
		$value = json_decode( $json,true,8 );
		if ( ! is_array( $value ) || ( $value['schema'] ?? '' ) !== 'kiwe.checkout-details.v1' || ! in_array( $value['source'] ?? '',[ 'dsa','woocommerce','store-api','active-session' ],true ) || ! is_string( $value['capturedAt'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value['capturedAt'] ) ) return null;
		$fields = [ 'ship_to_different_address'=>true === ( $value['differentShipping'] ?? null ) ];
		foreach ( [ 'billing','shipping' ] as $group ) foreach ( (array) ( $value[$group] ?? [] ) as $key=>$v ) $fields[$group . '_' . $key] = $v;
		return self::envelope( $fields,$value['source'],$value['capturedAt'] );
	}
}
