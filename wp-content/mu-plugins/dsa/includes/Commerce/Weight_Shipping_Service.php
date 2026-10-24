<?php
namespace DSA\Commerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native flat-rate instances own both the base charge and optional weight increment. */
final class Weight_Shipping_Service {
	public function register(): void {
		if ( ! \DSA\Apps\Store_App_Service::enabled() ) return;
		add_filter( 'woocommerce_shipping_instance_form_fields_flat_rate', [ $this, 'fields' ] );
		add_filter( 'woocommerce_package_rates', [ $this, 'rates' ], 100, 2 );
	}

	public function fields( array $fields ): array {
		$fields['increment_weight'] = [
			'title' => sprintf( __( 'Weight per delivery charge (%s)', 'dsa' ), get_option( 'woocommerce_weight_unit', 'kg' ) ),
			'type' => 'number', 'default' => '',
			'description' => __( 'Optional. Charge the base rate for each started weight increment. Leave empty for normal flat-rate delivery. Products without a weight use 0.5 kg.', 'dsa' ),
			'custom_attributes' => [ 'step' => 'any', 'min' => '0.000001' ],
		];
		return $fields;
	}

	public function rates( array $rates, array $package ): array {
		$unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
		$weight = 0.0;
		foreach ( $package['contents'] ?? [] as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) continue;
			$value = (float) $product->get_weight();
			$weight += ( $value > 0 ? $value : (float) wc_get_weight( 0.5, $unit, 'kg' ) ) * max( 0, (float) ( $item['quantity'] ?? 0 ) );
		}
		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate || 'flat_rate' !== $rate->get_method_id() ) continue;
			$settings = get_option( 'woocommerce_flat_rate_' . $rate->get_instance_id() . '_settings', [] );
			$increment = is_array( $settings ) ? ( $settings['increment_weight'] ?? '' ) : '';
			// No stored rule means no change. Never adopt this legacy rule for unrelated sites.
			if ( ! is_numeric( $increment ) || ! is_finite( (float) $increment ) || (float) $increment <= 0 || $weight <= 0 ) continue;
			$steps = ceil( round( $weight / (float) $increment, 10 ) );
			$cost = (float) $rate->get_cost() * $steps;
			if ( ! is_finite( $cost ) || $cost < 0 ) continue;
			$rate->set_cost( $cost );
			$rate->set_label( sprintf( __( 'Weight-Based Shipping (%.2f %s)', 'dsa' ), $weight, $unit ) );
			// Woo has already calculated the base rate's taxes; keep those same tax IDs and rates.
			$taxes = $rate->get_taxes();
			if ( $taxes ) $rate->set_taxes( array_map( static fn( $tax ) => (float) $tax * $steps, $taxes ) );
		}
		return $rates;
	}
}
