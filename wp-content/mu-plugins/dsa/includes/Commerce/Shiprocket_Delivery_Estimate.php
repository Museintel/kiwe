<?php
namespace DSA\Commerce;

use DSA\Apps\Shiprocket_App_Service;
use DSA\Apps\Asset_Locator;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Optional Bricks product-template control. Only provider delivery dates leave the server. */
final class Shiprocket_Delivery_Estimate {
	public function register(): void {
		add_shortcode( 'kiwe_shiprocket_delivery', [ $this, 'shortcode' ] );
		if ( ! Shiprocket_App_Service::realtime_configured() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route( 'dsa/v1', '/store/delivery-estimate', [
			'methods'=>'POST', 'callback'=>[ $this, 'estimate' ], 'permission_callback'=>[ Origin_Checker::class, 'mutation_allowed' ],
			'args'=>[
				'product'=>[ 'type'=>'integer', 'required'=>true, 'minimum'=>1 ],
				'variation'=>[ 'type'=>'integer', 'minimum'=>0, 'default'=>0 ],
				'pin'=>[ 'type'=>'string', 'required'=>true, 'pattern'=>'^[1-9][0-9]{5}$', 'minLength'=>6, 'maxLength'=>6 ],
			],
		] );
	}

	public function shortcode(): string {
		if ( ! Shiprocket_App_Service::realtime_configured() ) return '';
		$id = get_queried_object_id();
		$product = is_product() ? wc_get_product( $id ) : false;
		if ( ! $product || ! $product->needs_shipping() ) return '';
		$asset = 'assets/js/shiprocket-estimate.js';
		$version = Asset_Locator::version( $asset );
		$url = add_query_arg( 'kiwe-build', $version, Asset_Locator::url( $asset ) );
		wp_enqueue_script( 'kiwe-shiprocket-estimate', $url, [ 'dsa-surface' ], $version, true );
		return '<form data-kiwe-delivery-estimate="' . esc_attr( (string) $id ) . '"><label>'
			. esc_html__( 'Delivery PIN code', 'dsa' )
			. ' <input name="pin" inputmode="numeric" autocomplete="postal-code" maxlength="6" pattern="[1-9][0-9]{5}" required></label> '
			. '<button type="submit">' . esc_html__( 'Check delivery', 'dsa' ) . '</button><p role="status"></p></form>';
	}

	public function estimate( \WP_REST_Request $request ) {
		if ( ! Origin_Checker::transient_rate_limit( 'kiwe_shiprocket_estimate', 15 ) ) {
			return new \WP_Error( 'kiwe_estimate_rate_limit', __( 'Wait a minute before checking delivery again.', 'dsa' ), [ 'status'=>429 ] );
		}
		$parent = wc_get_product( $request['product'] );
		$product = $request['variation'] ? wc_get_product( $request['variation'] ) : $parent;
		if ( ! $parent || 'publish' !== $parent->get_status() || post_password_required( $parent->get_id() ) || ! $product
			|| 'publish' !== $product->get_status() || $product->is_type( 'variable' )
			|| ( $request['variation'] && ( ! $product->is_type( 'variation' ) || $product->get_parent_id() !== $parent->get_id() ) )
			|| ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->needs_shipping() ) {
			return new \WP_Error( 'kiwe_estimate_product', __( 'Choose an available product option to check delivery.', 'dsa' ), [ 'status'=>422 ] );
		}

		$settings = get_option( Shiprocket_App_Service::OPTION, [] );
		$method = WC()->shipping()->load_shipping_methods()[ Shiprocket_App_Service::METHOD ] ?? null;
		$unavailable = new \WP_Error( 'kiwe_estimate_unavailable', __( 'Delivery estimates are temporarily unavailable. Check delivery at checkout.', 'dsa' ), [ 'status'=>503 ] );
		if ( ! is_callable( [ $method, 'get_rates_from_server' ] ) || 'INR' !== get_woocommerce_currency() ) return $unavailable;
		$weight = wc_get_weight( (float) $product->get_weight(), 'kg' );
		if ( $weight <= 0 ) return $unavailable;
		$dimensions = $product->get_dimensions( false );
		$payload = [
			'delivery_postcode'=>$request['pin'], 'weight'=>$weight, 'cod'=>'0', 'currency'=>'INR',
			'merchant_id'=>$settings['integration_id'], 'declared_value'=>(float) $product->get_price(),
			'reference_id'=>wp_generate_uuid4(),
		];
		foreach ( [ 'length', 'width', 'height' ] as $dimension ) {
			$payload[ $dimension ] = wc_get_dimension( (float) ( $dimensions[ $dimension ] ?? 0 ), 'cm' );
		}
		$limit = static function ( array $args, string $url ): array {
			if ( str_starts_with( $url, SHIPROCKET_WC_RATE_URL . '?' ) ) $args['timeout'] = min( 6, $args['timeout'] );
			return $args;
		};
		add_filter( 'http_request_args', $limit, 10, 2 );
		try {
			$quote = $method->get_rates_from_server( $payload );
		} finally {
			remove_filter( 'http_request_args', $limit, 10 );
		}
		$couriers = $quote->data->available_courier_companies ?? null;
		if ( 200 !== (int) ( $quote->status ?? 0 ) || ! is_array( $couriers ) ) return $unavailable;

		$dates = [];
		foreach ( array_slice( $couriers, 0, 5 ) as $courier ) {
			if ( (int) ( $courier->courier_company_id ?? 0 ) > 0 && ! empty( $courier->etd ) && is_string( $courier->etd ) ) {
				$dates[] = sanitize_text_field( $courier->etd );
			}
		}
		$response = rest_ensure_response( [ 'dates'=>array_values( array_unique( array_filter( $dates ) ) ) ] );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
}
